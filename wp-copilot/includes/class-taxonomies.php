<?php
/**
 * Register custom taxonomies
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_Taxonomies {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'register_taxonomies'));
        add_action('init', array($this, 'populate_default_terms'), 20);
        add_action('init', array($this, 'populate_task_status_terms'), 21);
        add_action('init', array($this, 'populate_spec_terms'), 22);
    }

    public function register_taxonomies() {
        // Structural taxonomy (mirrors Pages + Headings)
        register_taxonomy('wcp_context', array('post'), array(
            'labels' => array(
                'name' => __('Contexts', 'work-copilot'),
                'singular_name' => __('Context', 'work-copilot'),
                'search_items' => __('Search Contexts', 'work-copilot'),
                'all_items' => __('All Contexts', 'work-copilot'),
                'parent_item' => __('Parent Context', 'work-copilot'),
                'parent_item_colon' => __('Parent Context:', 'work-copilot'),
                'edit_item' => __('Edit Context', 'work-copilot'),
                'update_item' => __('Update Context', 'work-copilot'),
                'add_new_item' => __('Add New Context', 'work-copilot'),
                'new_item_name' => __('New Context Name', 'work-copilot'),
                'menu_name' => __('Contexts', 'work-copilot'),
            ),
            'hierarchical' => true,
            'show_ui' => false, // Managed automatically via sync
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'context'),
            'show_in_rest' => true,
            'public' => true,
            'has_archive' => true,
        ));

        // Item type taxonomy
        register_taxonomy('item_type', array('post'), array(
            'labels' => array(
                'name' => __('Item Types', 'work-copilot'),
                'singular_name' => __('Item Type', 'work-copilot'),
                'search_items' => __('Search Item Types', 'work-copilot'),
                'all_items' => __('All Item Types', 'work-copilot'),
                'edit_item' => __('Edit Item Type', 'work-copilot'),
                'update_item' => __('Update Item Type', 'work-copilot'),
                'add_new_item' => __('Add New Item Type', 'work-copilot'),
                'new_item_name' => __('New Item Type Name', 'work-copilot'),
                'menu_name' => __('Item Types', 'work-copilot'),
            ),
            'hierarchical' => false,
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'type'),
            'show_in_rest' => true,
            'public' => true,
            'has_archive' => true,
        ));

        // Priority taxonomy
        register_taxonomy('priority', array('post'), array(
            'labels' => array(
                'name' => __('Priorities', 'work-copilot'),
                'singular_name' => __('Priority', 'work-copilot'),
                'search_items' => __('Search Priorities', 'work-copilot'),
                'all_items' => __('All Priorities', 'work-copilot'),
                'edit_item' => __('Edit Priority', 'work-copilot'),
                'update_item' => __('Update Priority', 'work-copilot'),
                'add_new_item' => __('Add New Priority', 'work-copilot'),
                'new_item_name' => __('New Priority Name', 'work-copilot'),
                'menu_name' => __('Priorities', 'work-copilot'),
            ),
            'hierarchical' => false,
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'priority'),
            'show_in_rest' => true,
            'public' => true,
            'has_archive' => true,
        ));

        // Task status taxonomy (visible only when item_type = task)
        register_taxonomy('task_status', array('post'), array(
            'labels' => array(
                'name'          => __('Task Status', 'work-copilot'),
                'singular_name' => __('Task Status', 'work-copilot'),
                'menu_name'     => __('Task Status', 'work-copilot'),
            ),
            'hierarchical'     => false,
            'show_ui'          => true,
            'show_admin_column' => true,
            'query_var'        => true,
            'rewrite'          => array('slug' => 'task-status'),
            'show_in_rest'     => true,
            'public'           => true,
        ));

        // Spec status taxonomy (visible only when item_type = spec)
        register_taxonomy('spec_status', array('post'), array(
            'labels' => array(
                'name'          => __('Spec Status', 'work-copilot'),
                'singular_name' => __('Spec Status', 'work-copilot'),
                'menu_name'     => __('Spec Status', 'work-copilot'),
            ),
            'hierarchical'     => false,
            'show_ui'          => true,
            'show_admin_column' => true,
            'query_var'        => true,
            'rewrite'          => array('slug' => 'spec-status'),
            'show_in_rest'     => true,
            'public'           => true,
        ));

        // Pinned taxonomy
        register_taxonomy('pinned', array('post'), array(
            'labels' => array(
                'name' => __('Pinned Status', 'work-copilot'),
                'singular_name' => __('Pinned', 'work-copilot'),
                'search_items' => __('Search Pinned Status', 'work-copilot'),
                'all_items' => __('All Pinned Status', 'work-copilot'),
                'edit_item' => __('Edit Pinned Status', 'work-copilot'),
                'update_item' => __('Update Pinned Status', 'work-copilot'),
                'add_new_item' => __('Add New Pinned Status', 'work-copilot'),
                'new_item_name' => __('New Pinned Status Name', 'work-copilot'),
                'menu_name' => __('Pinned', 'work-copilot'),
            ),
            'hierarchical' => false,
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'pinned'),
            'show_in_rest' => true,
            'public' => true,
            'has_archive' => true,
        ));
    }

    public function populate_default_terms() {
        // Only run once
        if (get_option('wcp_default_terms_created')) {
            return;
        }

        // Item types
        $item_types = array('task', 'info', 'learning', 'spec');
        foreach ($item_types as $type) {
            if (!term_exists($type, 'item_type')) {
                wp_insert_term($type, 'item_type', array(
                    'slug' => $type,
                ));
            }
        }

        // Priorities
        $priorities = array('critical', 'high', 'medium', 'low');
        foreach ($priorities as $priority) {
            if (!term_exists($priority, 'priority')) {
                wp_insert_term($priority, 'priority', array(
                    'slug' => $priority,
                ));
            }
        }

        // Pinned status
        $pinned_statuses = array('yes', 'no');
        foreach ($pinned_statuses as $status) {
            if (!term_exists($status, 'pinned')) {
                wp_insert_term($status, 'pinned', array(
                    'slug' => $status,
                ));
            }
        }

        update_option('wcp_default_terms_created', true);
    }

    public function populate_task_status_terms() {
        if (get_option('wcp_task_status_terms_created')) {
            return;
        }

        $task_statuses = array('to-do' => 'To Do', 'in-progress' => 'In Progress', 'done' => 'Done');
        foreach ($task_statuses as $slug => $label) {
            if (!term_exists($slug, 'task_status')) {
                wp_insert_term($label, 'task_status', array('slug' => $slug));
            }
        }

        update_option('wcp_task_status_terms_created', true);
    }

    /**
     * Populate spec-related terms: the 'spec' item type (for existing installs
     * where populate_default_terms has already run) and the spec_status terms.
     */
    public function populate_spec_terms() {
        if (get_option('wcp_spec_terms_created')) {
            return;
        }

        if (!term_exists('spec', 'item_type')) {
            wp_insert_term('spec', 'item_type', array('slug' => 'spec'));
        }

        $spec_statuses = array('draft' => 'Draft', 'review' => 'Review', 'final' => 'Final');
        foreach ($spec_statuses as $slug => $label) {
            if (!term_exists($slug, 'spec_status')) {
                wp_insert_term($label, 'spec_status', array('slug' => $slug));
            }
        }

        update_option('wcp_spec_terms_created', true);
    }
}
