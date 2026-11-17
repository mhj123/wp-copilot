<?php
/**
 * Story Map
 *
 * A board view of a Page or a Heading: columns run left to right, cards sit
 * underneath, and horizontal "release lines" cut across every column so you
 * can see what comes next in each one at a glance.
 *
 *   Page board:    columns = the page's Headings (plus one for items filed
 *                  directly on the page); cards = the items under each.
 *   Heading board: columns = the heading's top-level items; cards = their
 *                  sub-items.
 *
 * What is stored, and where:
 *
 *   - Card order is the item's ordinary menu_order. It is deliberately NOT a
 *     separate order: reordering on the map reorders the page view, and vice
 *     versa.
 *   - Which column a card is in is the item's ordinary filing (its wcp_context
 *     term on a page board, its post_parent on a heading board). Dragging a
 *     card to another column re-files it.
 *   - Release lines, and which release each card belongs to, are the only
 *     thing the map owns. They live in one post meta row on the board's Page
 *     or Heading, so the same item can sit in different releases on different
 *     boards.
 *
 * Nothing here involves AI: every write is a direct result of the user
 * dragging something.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_Story_Map {

    const META_KEY     = '_wcp_story_map';
    const MAX_RELEASES = 30;

    /** Column key for items filed directly on the page, outside any heading. */
    const PAGE_COLUMN = 'page';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        $route = '/story-map/(?P<scope>page|heading)/(?P<id>\d+)';

        register_rest_route('work-copilot/v1', $route, array(
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'rest_get'),
                'permission_callback' => array($this, 'check_permission'),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'rest_save'),
                'permission_callback' => array($this, 'check_permission'),
            ),
        ));
    }

    /** Same coarse gate as the rest of the plugin's API; object checks follow per request. */
    public function check_permission() {
        return current_user_can('edit_published_posts');
    }

    // -------------------------------------------------------------------------
    // REST handlers
    // -------------------------------------------------------------------------

    public function rest_get($request) {
        $board = $this->resolve_board($request->get_param('scope'), (int) $request->get_param('id'));
        if (is_wp_error($board)) {
            return $board;
        }

        return rest_ensure_response($this->build_response($board));
    }

    /**
     * Save the whole layout in one call: release lines, the order of every
     * column, and which release each card is in. The client sends the full
     * state after each drag, so a save is idempotent and a dropped request
     * is repaired by the next one.
     *
     * Body: {
     *   releases:    [{ id, name }, ...]            top to bottom
     *   columns:     [{ key, items: [id, ...] }]    each column's full order
     *   assignments: { "<item id>": "<release id>" }
     * }
     */
    public function rest_save($request) {
        $board = $this->resolve_board($request->get_param('scope'), (int) $request->get_param('id'));
        if (is_wp_error($board)) {
            return $board;
        }

        $columns_in = $request->get_param('columns');
        if (!is_array($columns_in)) {
            return new WP_Error('invalid_payload', 'columns is required', array('status' => 400));
        }

        $columns = $this->get_columns($board);

        // Everything a save may touch: the cards currently on this board, and
        // the board's own columns. An id from anywhere else is ignored, so a
        // crafted request cannot re-file or reorder an unrelated item.
        $known_columns = array();
        $current_keys  = array(); // item id => [column key, ...] as stored now
        foreach ($columns as $column) {
            $known_columns[$column['key']] = $column;
            foreach ($column['items'] as $item) {
                $current_keys[$item['id']][] = $column['key'];
            }
        }

        $desired_keys = array(); // item id => [column key, ...] as posted
        $order        = array(); // column key => [item id, ...]
        foreach ($columns_in as $column_in) {
            $key = isset($column_in['key']) ? (string) $column_in['key'] : '';
            if (!isset($known_columns[$key])) {
                continue;
            }
            $ids = isset($column_in['items']) ? array_map('intval', (array) $column_in['items']) : array();
            foreach (array_unique($ids) as $item_id) {
                if (!isset($current_keys[$item_id]) || !current_user_can('edit_post', $item_id)) {
                    continue;
                }
                $desired_keys[$item_id][] = $key;
                $order[$key][]            = $item_id;
            }
        }

        // 1. Re-file cards that changed column.
        foreach ($desired_keys as $item_id => $keys) {
            $removed = array_diff($current_keys[$item_id], $keys);
            $added   = array_diff($keys, $current_keys[$item_id]);
            if ($removed || $added) {
                $this->refile_item($board, $item_id, $known_columns, $removed, $added);
            }
        }

        // 2. Order. Written straight to the column rather than through
        // wp_update_post(), the same as the page view's drag-reorder: a
        // reorder is not a content edit and should not fire save_post
        // (which would re-embed every card that moved).
        global $wpdb;
        foreach ($order as $ids) {
            foreach ($ids as $index => $item_id) {
                $wpdb->update(
                    $wpdb->posts,
                    array('menu_order' => ($index + 1) * 10),
                    array('ID' => $item_id),
                    array('%d'),
                    array('%d')
                );
                clean_post_cache($item_id);
            }
        }

        // 3. Release lines and assignments.
        $releases    = $this->sanitize_releases($request->get_param('releases'));
        $release_ids = wp_list_pluck($releases, 'id');
        $stored      = $this->get_layout($board['id']);
        $posted      = (array) $request->get_param('assignments');

        // Keep what is stored for cards this save did not mention (cards
        // hidden from the board, such as completed tasks), then overlay what
        // was posted. An item posted without a release is unscheduled.
        $assignments = array();
        foreach ($stored['assignments'] as $item_id => $release_id) {
            if (!isset($desired_keys[$item_id])) {
                $assignments[(int) $item_id] = $release_id;
            }
        }
        foreach (array_keys($desired_keys) as $item_id) {
            if (isset($posted[$item_id]) && is_string($posted[$item_id])) {
                $assignments[$item_id] = $posted[$item_id];
            }
        }
        // Drop assignments pointing at a release line that no longer exists.
        foreach ($assignments as $item_id => $release_id) {
            if (!in_array($release_id, $release_ids, true)) {
                unset($assignments[$item_id]);
            }
        }

        update_post_meta($board['id'], self::META_KEY, array(
            'releases'    => $releases,
            'assignments' => $assignments,
        ));

        return rest_ensure_response(array('success' => true));
    }

    // -------------------------------------------------------------------------
    // Board
    // -------------------------------------------------------------------------

    /**
     * Validate the requested board and the caller's right to it.
     *
     * @return array{scope: string, id: int, post: WP_Post}|WP_Error
     */
    private function resolve_board($scope, $id) {
        $post_type = $scope === 'heading' ? 'wcp_heading' : 'page';
        $post      = get_post($id);

        if (!$post || $post->post_type !== $post_type || $post->post_status === 'trash') {
            return new WP_Error('not_found', 'Story map not found', array('status' => 404));
        }

        $auth = WCP_REST_Auth::require_object($id, 'edit_post');
        if (is_wp_error($auth)) {
            return $auth;
        }

        return array('scope' => $scope === 'heading' ? 'heading' : 'page', 'id' => $id, 'post' => $post);
    }

    private function build_response($board) {
        $columns = $this->get_columns($board);
        $layout  = $this->get_layout($board['id']);

        // Only send assignments for cards actually on the board.
        $on_board = array();
        foreach ($columns as $column) {
            foreach ($column['items'] as $item) {
                $on_board[$item['id']] = true;
            }
        }
        $assignments = array_intersect_key($layout['assignments'], $on_board);

        // Internal detail (term ids) stays server-side.
        foreach ($columns as &$column) {
            unset($column['term_id']);
        }
        unset($column);

        return array(
            'scope'       => $board['scope'],
            'id'          => $board['id'],
            'title'       => $board['post']->post_title,
            'columns'     => $columns,
            'releases'    => $layout['releases'],
            // Cast so an empty map is sent as {} rather than [].
            'assignments' => (object) $assignments,
        );
    }

    /**
     * The board's columns with their cards, in display order.
     *
     * @return array<int, array{key: string, title: string, items: array, term_id?: int, post_id?: int}>
     */
    private function get_columns($board) {
        return $board['scope'] === 'heading'
            ? $this->get_heading_columns($board['id'])
            : $this->get_page_columns($board['id']);
    }

    /**
     * Page board: one column per Heading, preceded by a column for items
     * filed directly on the page when there are any.
     */
    private function get_page_columns($page_id) {
        $sync      = WCP_Taxonomy_Sync::instance();
        $page_term = $sync->get_term_for_ref('page', $page_id);
        $columns   = array();

        $headings = get_posts(array(
            'post_type'      => 'wcp_heading',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'menu_order title',
            'order'          => 'ASC',
            'meta_query'     => array(
                array('key' => '_wcp_parent_type', 'value' => 'page'),
                array('key' => '_wcp_parent_id', 'value' => $page_id),
            ),
        ));

        $heading_term_ids = array();
        foreach ($headings as $heading) {
            $term = $sync->get_term_for_ref('wcp_heading', $heading->ID);
            if (!$term) {
                continue; // not synced to the taxonomy yet — nothing can be filed under it
            }
            $heading_term_ids[] = (int) $term->term_id;
            $columns[]          = array(
                'key'     => 'h' . $heading->ID,
                'title'   => $heading->post_title,
                'term_id' => (int) $term->term_id,
                'items'   => $this->format_items($this->query_items(array(
                    'post_parent' => 0,
                    'tax_query'   => array(
                        array('taxonomy' => 'wcp_context', 'field' => 'term_id', 'terms' => (int) $term->term_id, 'include_children' => false),
                        $this->not_done_clause(),
                    ),
                )), true),
            );
        }

        if ($page_term) {
            $tax_query = array(
                array('taxonomy' => 'wcp_context', 'field' => 'term_id', 'terms' => (int) $page_term->term_id, 'include_children' => false),
                $this->not_done_clause(),
            );
            if ($heading_term_ids) {
                // Filed under a heading as well: it already has a column.
                $tax_query[] = array('taxonomy' => 'wcp_context', 'field' => 'term_id', 'terms' => $heading_term_ids, 'operator' => 'NOT IN', 'include_children' => false);
            }

            // Always offered, even when empty, so a card can be dragged out
            // of every heading and back onto the page itself.
            array_unshift($columns, array(
                'key'     => self::PAGE_COLUMN,
                'title'   => __('No heading', 'work-copilot'),
                'term_id' => (int) $page_term->term_id,
                'items'   => $this->format_items($this->query_items(array('post_parent' => 0, 'tax_query' => $tax_query)), true),
            ));
        }

        return $columns;
    }

    /**
     * Heading board: one column per top-level item under the heading; its
     * sub-items are the cards.
     */
    private function get_heading_columns($heading_id) {
        $term = WCP_Taxonomy_Sync::instance()->get_term_for_ref('wcp_heading', $heading_id);
        if (!$term) {
            return array();
        }

        $parents = $this->query_items(array(
            'post_parent' => 0,
            'tax_query'   => array(
                array('taxonomy' => 'wcp_context', 'field' => 'term_id', 'terms' => (int) $term->term_id, 'include_children' => false),
                $this->not_done_clause(),
            ),
        ));

        $columns = array();
        foreach ($parents as $parent) {
            $columns[] = array(
                'key'     => 'i' . $parent->ID,
                'title'   => $parent->post_title,
                'post_id' => (int) $parent->ID,
                'url'     => get_permalink($parent),
                'items'   => $this->format_items($this->query_items(array(
                    'post_parent' => (int) $parent->ID,
                    'tax_query'   => array($this->not_done_clause()),
                )), false),
            );
        }

        return $columns;
    }

    private function query_items($args) {
        return get_posts(array_merge(array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => array('menu_order' => 'ASC', 'date' => 'ASC'),
        ), $args));
    }

    /**
     * Completed tasks are hidden here as they are everywhere in the
     * workspace. NOT IN also keeps every item with no task status at all.
     */
    private function not_done_clause() {
        return array(
            'taxonomy' => 'task_status',
            'field'    => 'slug',
            'terms'    => array('done'),
            'operator' => 'NOT IN',
        );
    }

    /**
     * @param WP_Post[] $posts
     * @param bool      $with_children Include each item's open sub-items (shown inside its card).
     */
    private function format_items($posts, $with_children) {
        $items = array();

        foreach ($posts as $post) {
            $item = array(
                'id'       => (int) $post->ID,
                'title'    => $post->post_title,
                'type'     => $this->first_term_slug($post->ID, 'item_type'),
                'priority' => $this->first_term_slug($post->ID, 'priority'),
                'status'   => $this->first_term_slug($post->ID, 'task_status'),
                'pinned'   => $this->first_term_slug($post->ID, 'pinned') === 'yes',
                'due'      => (string) get_post_meta($post->ID, '_wcp_due_date', true),
                'url'      => get_permalink($post),
            );

            if ($with_children) {
                $item['children'] = array();
                $children         = $this->query_items(array(
                    'post_parent' => (int) $post->ID,
                    'tax_query'   => array($this->not_done_clause()),
                ));
                foreach ($children as $child) {
                    $item['children'][] = array(
                        'id'    => (int) $child->ID,
                        'title' => $child->post_title,
                        'type'  => $this->first_term_slug($child->ID, 'item_type'),
                    );
                }
            }

            $items[] = $item;
        }

        return $items;
    }

    private function first_term_slug($post_id, $taxonomy) {
        $terms = get_the_terms($post_id, $taxonomy);

        return (is_array($terms) && !empty($terms)) ? reset($terms)->slug : '';
    }

    // -------------------------------------------------------------------------
    // Moving a card between columns
    // -------------------------------------------------------------------------

    /**
     * Re-file an item after it was dragged to a different column.
     *
     * Page board: swap the column's context term. Only this board's own terms
     * are touched — an item also filed on other pages keeps those.
     * Heading board: the column is the parent item, so change post_parent.
     *
     * @param array    $board
     * @param int      $item_id
     * @param array    $known_columns key => column
     * @param string[] $removed       column keys the item left
     * @param string[] $added         column keys the item joined
     */
    private function refile_item($board, $item_id, $known_columns, $removed, $added) {
        if ($board['scope'] === 'heading') {
            $target = reset($added);
            if ($target === false || empty($known_columns[$target]['post_id'])) {
                return;
            }
            $parent_id = (int) $known_columns[$target]['post_id'];
            if ($parent_id === (int) $item_id) {
                return;
            }

            global $wpdb;
            $wpdb->update($wpdb->posts, array('post_parent' => $parent_id), array('ID' => $item_id), array('%d'), array('%d'));
            clean_post_cache($item_id);
            return;
        }

        $term_ids = wp_get_post_terms($item_id, 'wcp_context', array('fields' => 'ids'));
        if (is_wp_error($term_ids)) {
            return;
        }
        $term_ids = array_map('intval', $term_ids);

        foreach ($removed as $key) {
            $term_ids = array_diff($term_ids, array((int) $known_columns[$key]['term_id']));
        }
        foreach ($added as $key) {
            $term_ids[] = (int) $known_columns[$key]['term_id'];
        }

        wp_set_post_terms($item_id, array_values(array_unique($term_ids)), 'wcp_context');
    }

    // -------------------------------------------------------------------------
    // Stored layout
    // -------------------------------------------------------------------------

    /**
     * @return array{releases: array<int, array{id: string, name: string}>, assignments: array<int, string>}
     */
    private function get_layout($board_id) {
        $stored = get_post_meta($board_id, self::META_KEY, true);

        $releases    = $this->sanitize_releases(is_array($stored) && isset($stored['releases']) ? $stored['releases'] : array());
        $assignments = array();

        if (is_array($stored) && isset($stored['assignments']) && is_array($stored['assignments'])) {
            foreach ($stored['assignments'] as $item_id => $release_id) {
                if (is_string($release_id)) {
                    $assignments[(int) $item_id] = $release_id;
                }
            }
        }

        return array('releases' => $releases, 'assignments' => $assignments);
    }

    /**
     * Release ids are generated in the browser, so accept only a strict shape.
     */
    private function sanitize_releases($releases) {
        $clean = array();
        $seen  = array();

        foreach ((array) $releases as $release) {
            if (!is_array($release) || empty($release['id']) || !is_string($release['id'])) {
                continue;
            }
            $id = $release['id'];
            if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id) || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $name = isset($release['name']) ? sanitize_text_field((string) $release['name']) : '';
            if ($name === '') {
                $name = __('Release', 'work-copilot');
            }

            $clean[] = array('id' => $id, 'name' => mb_substr($name, 0, 80));

            if (count($clean) >= self::MAX_RELEASES) {
                break;
            }
        }

        return $clean;
    }
}
