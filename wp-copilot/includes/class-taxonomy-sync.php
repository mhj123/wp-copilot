<?php
/**
 * Sync Pages and Headings to wcp_context taxonomy
 *
 * CRITICAL: This ensures the hierarchical taxonomy mirrors the Page/Heading structure
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_Taxonomy_Sync {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Sync on save
        add_action('save_post_page', array($this, 'sync_page_to_taxonomy'), 10, 3);
        add_action('save_post_wcp_heading', array($this, 'sync_heading_to_taxonomy'), 10, 3);

        // Sync on delete
        add_action('before_delete_post', array($this, 'delete_context_term'));

        // Sync on status change (trash/untrash)
        add_action('trashed_post', array($this, 'handle_post_trashed'));
        add_action('untrashed_post', array($this, 'handle_post_untrashed'));
    }

    /**
     * Sync a Page to wcp_context taxonomy
     */
    public function sync_page_to_taxonomy($post_id, $post, $update) {
        // Skip revisions and autosaves
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        // Only sync published pages
        if ($post->post_status !== 'publish') {
            return;
        }

        $this->sync_post_to_context($post_id, 'page', $post->post_title, $post->post_parent);
    }

    /**
     * Sync a Heading to wcp_context taxonomy
     */
    public function sync_heading_to_taxonomy($post_id, $post, $update) {
        // Skip revisions and autosaves
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        // Only sync published headings
        if ($post->post_status !== 'publish') {
            return;
        }

        // Get heading parent
        $parent_type = get_post_meta($post_id, '_wcp_parent_type', true);
        $parent_id = get_post_meta($post_id, '_wcp_parent_id', true);

        // Find parent term
        $parent_term_id = 0;
        if ($parent_type && $parent_id) {
            $parent_term = $this->get_context_term_by_ref($parent_id, $parent_type);
            if ($parent_term) {
                $parent_term_id = $parent_term->term_id;
            }
        }

        $this->sync_post_to_context($post_id, 'wcp_heading', $post->post_title, $parent_term_id, true);
    }

    /**
     * Core sync logic
     *
     * @param int $post_id Post ID
     * @param string $ref_type Reference type (page|wcp_heading)
     * @param string $title Term name
     * @param int $parent_id Parent term ID (or parent post ID for pages)
     * @param bool $parent_is_term Whether $parent_id is a term ID (true) or post ID (false)
     */
    private function sync_post_to_context($post_id, $ref_type, $title, $parent_id = 0, $parent_is_term = false) {
        // Find existing term
        $existing_term = $this->get_context_term_by_ref($post_id, $ref_type);

        // Resolve parent term ID
        $parent_term_id = 0;
        if ($parent_id && !$parent_is_term && $ref_type === 'page') {
            // For pages, parent_id is a post ID, need to find its term
            $parent_term = $this->get_context_term_by_ref($parent_id, 'page');
            if ($parent_term) {
                $parent_term_id = $parent_term->term_id;
            }
        } elseif ($parent_id && $parent_is_term) {
            $parent_term_id = $parent_id;
        }

        $slug = sanitize_title($title . '-' . $post_id);

        if ($existing_term) {
            // Update existing term
            wp_update_term($existing_term->term_id, 'wcp_context', array(
                'name' => $title,
                'slug' => $slug,
                'parent' => $parent_term_id,
            ));

            $term_id = $existing_term->term_id;
        } else {
            // Create new term
            $result = wp_insert_term($title, 'wcp_context', array(
                'slug' => $slug,
                'parent' => $parent_term_id,
            ));

            if (is_wp_error($result)) {
                error_log('WCP: Failed to create context term for ' . $ref_type . ' ' . $post_id . ': ' . $result->get_error_message());
                return;
            }

            $term_id = $result['term_id'];

            // Store reference metadata
            update_term_meta($term_id, 'wcp_ref_type', $ref_type);
            update_term_meta($term_id, 'wcp_ref_id', $post_id);
        }

        // Update cached path
        $path = $this->get_term_path($term_id);
        update_term_meta($term_id, 'wcp_cached_path', $path);
    }

    /**
     * Public accessor for looking up the wcp_context term that mirrors a
     * given Page or Heading post. Callers that need the term (e.g. to fetch
     * an entity's items or duplicate it) should use this rather than
     * re-implementing the ref_type/ref_id meta_query lookup.
     *
     * @param string $ref_type 'page' or 'wcp_heading'
     * @param int    $ref_id   Post ID
     * @return WP_Term|null
     */
    public function get_term_for_ref($ref_type, $ref_id) {
        return $this->get_context_term_by_ref($ref_id, $ref_type);
    }

    /**
     * Get context term by reference
     */
    private function get_context_term_by_ref($ref_id, $ref_type) {
        $terms = get_terms(array(
            'taxonomy' => 'wcp_context',
            'hide_empty' => false,
            'meta_query' => array(
                array(
                    'key' => 'wcp_ref_type',
                    'value' => $ref_type,
                ),
                array(
                    'key' => 'wcp_ref_id',
                    'value' => $ref_id,
                ),
            ),
        ));

        return (!is_wp_error($terms) && !empty($terms)) ? $terms[0] : null;
    }

    /**
     * Delete context term when post is deleted
     */
    public function delete_context_term($post_id) {
        $post = get_post($post_id);

        if (!$post || !in_array($post->post_type, array('page', 'wcp_heading'))) {
            return;
        }

        $ref_type = $post->post_type === 'page' ? 'page' : 'wcp_heading';
        $term = $this->get_context_term_by_ref($post_id, $ref_type);

        if ($term) {
            wp_delete_term($term->term_id, 'wcp_context');
        }
    }

    /**
     * Handle post trashed (hide term temporarily)
     */
    public function handle_post_trashed($post_id) {
        $post = get_post($post_id);

        if (!$post || !in_array($post->post_type, array('page', 'wcp_heading'))) {
            return;
        }

        $ref_type = $post->post_type === 'page' ? 'page' : 'wcp_heading';
        $term = $this->get_context_term_by_ref($post_id, $ref_type);

        if ($term) {
            update_term_meta($term->term_id, 'wcp_is_trashed', true);
        }
    }

    /**
     * Handle post untrashed (restore term)
     */
    public function handle_post_untrashed($post_id) {
        $post = get_post($post_id);

        if (!$post || !in_array($post->post_type, array('page', 'wcp_heading'))) {
            return;
        }

        $ref_type = $post->post_type === 'page' ? 'page' : 'wcp_heading';
        $term = $this->get_context_term_by_ref($post_id, $ref_type);

        if ($term) {
            delete_term_meta($term->term_id, 'wcp_is_trashed');
        }
    }

    /**
     * Get full hierarchical path for a term
     */
    private function get_term_path($term_id) {
        $term = get_term($term_id, 'wcp_context');

        if (!$term || is_wp_error($term)) {
            return '';
        }

        $path = array($term->name);

        while ($term->parent) {
            $term = get_term($term->parent, 'wcp_context');
            if (!$term || is_wp_error($term)) {
                break;
            }
            array_unshift($path, $term->name);
        }

        return implode(' > ', $path);
    }

    /**
     * Sync all published pages and headings to wcp_context taxonomy.
     * Used for bulk repair when pages were created before the plugin was active.
     *
     * @return array ['pages' => int, 'headings' => int]
     */
    public function sync_all_to_taxonomy() {
        $counts = array('pages' => 0, 'headings' => 0);

        // Pages first (headings depend on their parent page terms existing)
        $pages = get_posts(array(
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ));

        foreach ($pages as $post) {
            $this->sync_page_to_taxonomy($post->ID, $post, true);
            $counts['pages']++;
        }

        // Headings second
        $headings = get_posts(array(
            'post_type'      => 'wcp_heading',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ));

        foreach ($headings as $post) {
            $this->sync_heading_to_taxonomy($post->ID, $post, true);
            $counts['headings']++;
        }

        return $counts;
    }

    /**
     * Utility: Get all context terms for display
     */
    public static function get_all_contexts($include_trashed = false) {
        $args = array(
            'taxonomy' => 'wcp_context',
            'hide_empty' => false,
            'orderby' => 'name',
        );

        if (!$include_trashed) {
            $args['meta_query'] = array(
                array(
                    'key' => 'wcp_is_trashed',
                    'compare' => 'NOT EXISTS',
                ),
            );
        }

        return get_terms($args);
    }
}
