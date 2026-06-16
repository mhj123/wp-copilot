<?php
/**
 * Register custom post types
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_Post_Types {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'register_post_types'));
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_heading_parent'), 10, 2);
    }

    /**
     * Record which agent created a post, for colour-coding and admin filtering.
     * Source is one of 'copilot' (in-app AI) or 'hermes' (delegation agent);
     * manually-created content carries no marker.
     */
    public static function mark_creator($post_id, $source) {
        $source = in_array($source, array('copilot', 'hermes'), true) ? $source : '';
        if ($source && $post_id && !is_wp_error($post_id)) {
            update_post_meta($post_id, '_wcp_created_by', $source);
        }
    }

    public function register_post_types() {
        // Heading post type
        $labels = array(
            'name'                  => _x('Headings', 'Post type general name', 'work-copilot'),
            'singular_name'         => _x('Heading', 'Post type singular name', 'work-copilot'),
            'menu_name'             => _x('Headings', 'Admin Menu text', 'work-copilot'),
            'name_admin_bar'        => _x('Heading', 'Add New on Toolbar', 'work-copilot'),
            'add_new'               => __('Add New', 'work-copilot'),
            'add_new_item'          => __('Add New Heading', 'work-copilot'),
            'new_item'              => __('New Heading', 'work-copilot'),
            'edit_item'             => __('Edit Heading', 'work-copilot'),
            'view_item'             => __('View Heading', 'work-copilot'),
            'all_items'             => __('All Headings', 'work-copilot'),
            'search_items'          => __('Search Headings', 'work-copilot'),
            'parent_item_colon'     => __('Parent Heading:', 'work-copilot'),
            'not_found'             => __('No headings found.', 'work-copilot'),
            'not_found_in_trash'    => __('No headings found in Trash.', 'work-copilot'),
        );

        $args = array(
            'labels'                => $labels,
            'description'           => __('Structural sub-contexts under Pages', 'work-copilot'),
            'public'                => true,
            'publicly_queryable'    => true,
            'show_ui'               => true,
            'show_in_menu'          => 'edit.php?post_type=page',
            'query_var'             => true,
            'rewrite'               => array('slug' => 'heading'),
            'capability_type'       => 'page',
            'has_archive'           => false,
            'hierarchical'          => true,
            'menu_position'         => null,
            'menu_icon'             => 'dashicons-editor-insertmore',
            'supports'              => array('title', 'editor', 'page-attributes'),
            'show_in_rest'          => true,
        );

        register_post_type('wcp_heading', $args);
    }

    public function add_meta_boxes() {
        add_meta_box(
            'wcp_heading_parent',
            __('Heading Parent', 'work-copilot'),
            array($this, 'render_heading_parent_meta_box'),
            'wcp_heading',
            'side',
            'high'
        );

        // Add context selector to posts
        add_meta_box(
            'wcp_post_contexts',
            __('Contexts (Pages & Headings)', 'work-copilot'),
            array($this, 'render_post_contexts_meta_box'),
            'post',
            'side',
            'default'
        );
    }

    public function render_heading_parent_meta_box($post) {
        wp_nonce_field('wcp_heading_parent_nonce', 'wcp_heading_parent_nonce');

        $current_parent_type = get_post_meta($post->ID, '_wcp_parent_type', true);
        $current_parent_id = get_post_meta($post->ID, '_wcp_parent_id', true);

        echo '<p class="description">' . __('Headings must belong to exactly one Page or Heading.', 'work-copilot') . '</p>';

        echo '<label for="wcp_parent_type">' . __('Parent Type:', 'work-copilot') . '</label>';
        echo '<select name="wcp_parent_type" id="wcp_parent_type" style="width: 100%; margin-bottom: 10px;">';
        echo '<option value="">' . __('Select Type', 'work-copilot') . '</option>';
        echo '<option value="page"' . selected($current_parent_type, 'page', false) . '>' . __('Page', 'work-copilot') . '</option>';
        echo '<option value="wcp_heading"' . selected($current_parent_type, 'wcp_heading', false) . '>' . __('Heading', 'work-copilot') . '</option>';
        echo '</select>';

        echo '<label for="wcp_parent_id">' . __('Parent:', 'work-copilot') . '</label>';
        echo '<div id="wcp_parent_selector">';
        $this->render_parent_selector($current_parent_type, $current_parent_id);
        echo '</div>';
    }

    private function render_parent_selector($parent_type, $current_parent_id) {
        if ($parent_type === 'page') {
            wp_dropdown_pages(array(
                'name' => 'wcp_parent_id',
                'selected' => $current_parent_id,
                'show_option_none' => __('Select Page', 'work-copilot'),
                'option_none_value' => '',
            ));
        } elseif ($parent_type === 'wcp_heading') {
            $headings = get_posts(array(
                'post_type' => 'wcp_heading',
                'posts_per_page' => -1,
                'orderby' => 'title',
                'order' => 'ASC',
            ));

            echo '<select name="wcp_parent_id" style="width: 100%;">';
            echo '<option value="">' . __('Select Heading', 'work-copilot') . '</option>';
            foreach ($headings as $heading) {
                echo '<option value="' . esc_attr($heading->ID) . '"' . selected($current_parent_id, $heading->ID, false) . '>' . esc_html($heading->post_title) . '</option>';
            }
            echo '</select>';
        } else {
            echo '<p class="description">' . __('Select a parent type first.', 'work-copilot') . '</p>';
        }
    }

    public function render_post_contexts_meta_box($post) {
        wp_nonce_field('wcp_post_contexts_nonce', 'wcp_post_contexts_nonce');

        echo '<p class="description">' . __('Select Pages and/or Headings this note relates to.', 'work-copilot') . '</p>';

        // Get current contexts from taxonomy
        $current_contexts = wp_get_post_terms($post->ID, 'wcp_context', array('fields' => 'ids'));

        // Get all pages
        $pages = get_posts(array(
            'post_type' => 'page',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ));

        // Get all headings
        $headings = get_posts(array(
            'post_type' => 'wcp_heading',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ));

        echo '<div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 8px;">';

        if (!empty($pages)) {
            echo '<strong>' . __('Pages:', 'work-copilot') . '</strong><br>';
            foreach ($pages as $page) {
                $term = $this->get_context_term_for_post($page->ID, 'page');
                $checked = $term && in_array($term->term_id, $current_contexts) ? 'checked' : '';
                echo '<label style="display: block; margin: 5px 0;">';
                echo '<input type="checkbox" name="wcp_contexts[]" value="' . esc_attr($term ? $term->term_id : '') . '" ' . $checked . '> ';
                echo esc_html($page->post_title);
                echo '</label>';
            }
        }

        if (!empty($headings)) {
            echo '<br><strong>' . __('Headings:', 'work-copilot') . '</strong><br>';
            foreach ($headings as $heading) {
                $term = $this->get_context_term_for_post($heading->ID, 'wcp_heading');
                $checked = $term && in_array($term->term_id, $current_contexts) ? 'checked' : '';
                echo '<label style="display: block; margin: 5px 0;">';
                echo '<input type="checkbox" name="wcp_contexts[]" value="' . esc_attr($term ? $term->term_id : '') . '" ' . $checked . '> ';
                echo esc_html($heading->post_title);
                echo '</label>';
            }
        }

        echo '</div>';
    }

    private function get_context_term_for_post($post_id, $post_type) {
        $terms = get_terms(array(
            'taxonomy' => 'wcp_context',
            'hide_empty' => false,
            'meta_query' => array(
                array(
                    'key' => 'wcp_ref_type',
                    'value' => $post_type,
                ),
                array(
                    'key' => 'wcp_ref_id',
                    'value' => $post_id,
                ),
            ),
        ));

        return !empty($terms) ? $terms[0] : null;
    }

    public function save_heading_parent($post_id, $post) {
        // Check if this is a heading
        if ($post->post_type !== 'wcp_heading') {
            // Handle post contexts
            if ($post->post_type === 'post' && isset($_POST['wcp_post_contexts_nonce']) && wp_verify_nonce($_POST['wcp_post_contexts_nonce'], 'wcp_post_contexts_nonce')) {
                if (!current_user_can('edit_post', $post_id)) {
                    return;
                }

                $contexts = isset($_POST['wcp_contexts']) ? array_map('intval', $_POST['wcp_contexts']) : array();
                wp_set_post_terms($post_id, $contexts, 'wcp_context');
            }
            return;
        }

        // Verify nonce
        if (!isset($_POST['wcp_heading_parent_nonce']) || !wp_verify_nonce($_POST['wcp_heading_parent_nonce'], 'wcp_heading_parent_nonce')) {
            return;
        }

        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Don't save on autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $parent_type = isset($_POST['wcp_parent_type']) ? sanitize_text_field($_POST['wcp_parent_type']) : '';
        $parent_id = isset($_POST['wcp_parent_id']) ? intval($_POST['wcp_parent_id']) : 0;

        update_post_meta($post_id, '_wcp_parent_type', $parent_type);
        update_post_meta($post_id, '_wcp_parent_id', $parent_id);
    }
}
