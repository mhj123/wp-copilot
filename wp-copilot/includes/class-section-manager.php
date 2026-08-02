<?php
/**
 * Section Manager — a "Section" is a Heading plus every Item that belongs to
 * it (including nested sub-headings and item sub-items). This class centralises
 * building a Section from a source, starting with manual duplication; a future
 * page-template "apply" pass and a future recurring-schedule pass are expected
 * to reuse the same item-creation core rather than re-implementing it.
 *
 * AI guardrail: none of this is AI-driven — duplication is a direct,
 * human-triggered action, so nothing here writes to the AI audit log.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_Section_Manager {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Duplicate a Heading and everything under it (its own items, their
     * sub-items, and any nested sub-headings with their items), resetting
     * per-cycle task state on the copy: task_status -> to-do, pinned -> no,
     * due date dropped, subtask 'done' flags cleared. spec_status and all
     * other fields (priority, tags, item_type, content) are copied as-is.
     *
     * @param int $heading_id Source wcp_heading post ID
     * @return int|WP_Error New top-level heading's post ID
     */
    public function duplicate_section($heading_id) {
        $heading_id = (int) $heading_id;
        $source = get_post($heading_id);

        if (!$source || $source->post_type !== 'wcp_heading') {
            return new WP_Error('not_found', 'Heading not found', array('status' => 404));
        }

        $parent_type = get_post_meta($source->ID, '_wcp_parent_type', true);
        $parent_id   = get_post_meta($source->ID, '_wcp_parent_id', true);

        $new_id = $this->duplicate_heading($source, $parent_type, $parent_id, true);
        if (is_wp_error($new_id)) {
            return $new_id;
        }

        $this->reposition_after($source->ID, $new_id, $parent_type, $parent_id);

        return $new_id;
    }

    /**
     * Create one duplicated heading (with its own items) and recurse into
     * any direct child headings underneath it.
     *
     * @param WP_Post $source       Source heading post
     * @param string  $parent_type  'page' or 'wcp_heading' — new heading's parent
     * @param int     $parent_id    Post ID of that parent
     * @param bool    $is_top_level Only the top-level duplicate gets the date suffix
     * @return int|WP_Error
     */
    private function duplicate_heading($source, $parent_type, $parent_id, $is_top_level) {
        $title = $source->post_title;
        if ($is_top_level) {
            $title .= date_i18n(' – j M Y', current_time('timestamp'));
        }

        $new_heading_id = wp_insert_post(array(
            'post_type'    => 'wcp_heading',
            'post_title'   => $title,
            'post_content' => $source->post_content,
            'post_status'  => 'publish',
        ));

        if (is_wp_error($new_heading_id)) {
            return $new_heading_id;
        }

        // Parent meta must be set before syncing — the save_post hook fired
        // during wp_insert_post above ran before this meta existed. Same
        // pattern used by create_heading() in class-rest-api.php.
        update_post_meta($new_heading_id, '_wcp_parent_type', $parent_type);
        update_post_meta($new_heading_id, '_wcp_parent_id', $parent_id);

        $is_goal = get_post_meta($source->ID, '_wcp_is_goal', true);
        if ($is_goal) {
            update_post_meta($new_heading_id, '_wcp_is_goal', $is_goal);
        }

        WCP_Taxonomy_Sync::instance()->sync_heading_to_taxonomy($new_heading_id, get_post($new_heading_id), true);

        $source_term = WCP_Taxonomy_Sync::instance()->get_term_for_ref('wcp_heading', $source->ID);
        $new_term    = WCP_Taxonomy_Sync::instance()->get_term_for_ref('wcp_heading', $new_heading_id);

        if ($source_term && $new_term) {
            $this->duplicate_items_in_term($source_term->term_id, $new_term->term_id);
        }

        // Recurse into direct child headings, reparenting them under the new heading.
        $child_headings = get_posts(array(
            'post_type'      => 'wcp_heading',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
            'meta_query'     => array(
                array('key' => '_wcp_parent_type', 'value' => 'wcp_heading'),
                array('key' => '_wcp_parent_id', 'value' => $source->ID),
            ),
        ));

        foreach ($child_headings as $child) {
            $this->duplicate_heading($child, 'wcp_heading', $new_heading_id, false);
        }

        return $new_heading_id;
    }

    /**
     * Duplicate every top-level item (post_parent = 0) directly in a
     * wcp_context term — not descendant terms, so nested heading levels are
     * handled by the caller's own recursion rather than being flattened here.
     */
    private function duplicate_items_in_term($source_term_id, $new_term_id) {
        $items = get_posts(array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'post_parent'    => 0,
            'posts_per_page' => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
            'tax_query'      => array(array(
                'taxonomy'         => 'wcp_context',
                'field'            => 'term_id',
                'terms'            => $source_term_id,
                'include_children' => false,
            )),
        ));

        $menu_order = 0;
        foreach ($items as $item) {
            $this->duplicate_item($item, $new_term_id, $menu_order, 0);
            $menu_order += 10;
        }
    }

    /**
     * Duplicate one item, resetting per-cycle task state, then recurse into
     * its own child items (real post_parent sub-items, distinct from the
     * lightweight _wcp_subtasks JSON list which is copied as plain data).
     *
     * @param WP_Post $source       Source item post
     * @param int     $new_term_id  wcp_context term of the new section
     * @param int     $menu_order   Position among its new siblings
     * @param int     $post_parent  0 for a top-level item, or the new parent item's ID
     * @return int|WP_Error
     */
    private function duplicate_item($source, $new_term_id, $menu_order, $post_parent) {
        $insert_args = array(
            'post_type'    => 'post',
            'post_title'   => $source->post_title,
            'post_content' => $source->post_content,
            'post_status'  => 'publish',
            'menu_order'   => $menu_order,
            'post_author'  => get_current_user_id(),
        );
        if ($post_parent) {
            $insert_args['post_parent'] = $post_parent;
        }

        $new_id = wp_insert_post($insert_args);
        if (is_wp_error($new_id)) {
            return $new_id;
        }

        wp_set_post_terms($new_id, array($new_term_id), 'wcp_context');

        $item_types = wp_get_post_terms($source->ID, 'item_type', array('fields' => 'slugs'));
        $item_type  = !empty($item_types) ? $item_types[0] : '';
        if ($item_type) {
            wp_set_post_terms($new_id, array($item_type), 'item_type');
        }

        $priorities = wp_get_post_terms($source->ID, 'priority', array('fields' => 'slugs'));
        if (!empty($priorities)) {
            wp_set_post_terms($new_id, array($priorities[0]), 'priority');
        }

        $tags = wp_get_post_terms($source->ID, 'post_tag', array('fields' => 'names'));
        if (!empty($tags)) {
            wp_set_post_terms($new_id, $tags, 'post_tag');
        }

        // Reset per-cycle state: always unpin; reset task_status for tasks,
        // otherwise carry spec_status across unchanged (out of scope to reset it).
        wp_set_post_terms($new_id, array('no'), 'pinned');
        if ($item_type === 'task') {
            wp_set_post_terms($new_id, array('to-do'), 'task_status');
        } else {
            $spec_statuses = wp_get_post_terms($source->ID, 'spec_status', array('fields' => 'slugs'));
            if (!empty($spec_statuses)) {
                wp_set_post_terms($new_id, array($spec_statuses[0]), 'spec_status');
            }
        }

        // Subtasks: copy titles, reset each 'done' flag — same "fresh checklist" logic one level down.
        $subtasks = json_decode(get_post_meta($source->ID, '_wcp_subtasks', true) ?: '[]', true);
        if (!empty($subtasks) && is_array($subtasks)) {
            foreach ($subtasks as &$subtask) {
                $subtask['done'] = false;
            }
            unset($subtask);
            update_post_meta($new_id, '_wcp_subtasks', wp_json_encode($subtasks));
        }

        $source_url = get_post_meta($source->ID, '_wcp_source_url', true);
        if ($source_url) {
            update_post_meta($new_id, '_wcp_source_url', $source_url);
        }

        // Intentionally not copied:
        // - _wcp_due_date (dropped — a carried-over due date would be stale)
        // - _wcp_created_by / _wcp_ai_generated / _wcp_ai_action_id / _wcp_source_conversation_id
        //   (audit-trail fields belong to the source item, not the copy)

        if (get_option('wcp_ai_enabled', false)) {
            WCP_Embeddings_Manager::instance()->generate_embedding($new_id);
        }

        $children = get_posts(array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'post_parent'    => $source->ID,
            'posts_per_page' => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        ));

        $child_order = 0;
        foreach ($children as $child) {
            $this->duplicate_item($child, $new_term_id, $child_order, $new_id);
            $child_order += 10;
        }

        return $new_id;
    }

    /**
     * Renumber a heading's siblings sequentially so the newly duplicated
     * heading sits directly after its source, matching the plain-integer
     * menu_order convention reorder_headings() already uses.
     */
    private function reposition_after($source_id, $new_heading_id, $parent_type, $parent_id) {
        $siblings = get_posts(array(
            'post_type'      => 'wcp_heading',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
            'fields'         => 'ids',
            'post__not_in'   => array($new_heading_id),
            'meta_query'     => array(
                array('key' => '_wcp_parent_type', 'value' => $parent_type),
                array('key' => '_wcp_parent_id', 'value' => $parent_id),
            ),
        ));

        $ordered = array();
        foreach ($siblings as $id) {
            $ordered[] = $id;
            if ((int) $id === (int) $source_id) {
                $ordered[] = $new_heading_id;
            }
        }
        if (!in_array($new_heading_id, $ordered, true)) {
            $ordered[] = $new_heading_id;
        }

        foreach ($ordered as $order => $id) {
            wp_update_post(array('ID' => $id, 'menu_order' => $order));
        }
    }
}
