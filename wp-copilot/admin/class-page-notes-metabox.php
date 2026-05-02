<?php
/**
 * Page Notes Metabox
 *
 * Adds a rich text editor field to each page for general notes,
 * displayed on the front-end below the page title.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_Page_Notes_Metabox {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('add_meta_boxes', array($this, 'add_metabox'));
        add_action('save_post_page', array($this, 'save_meta'));
    }

    public function add_metabox() {
        add_meta_box(
            'wcp_page_notes',
            __('Page Notes', 'work-copilot'),
            array($this, 'render_metabox'),
            'page',
            'normal',
            'default'
        );
    }

    public function render_metabox($post) {
        wp_nonce_field('wcp_page_notes_save', 'wcp_page_notes_nonce');

        $notes = get_post_meta($post->ID, '_wcp_page_notes', true);

        wp_editor(
            $notes,
            'wcp_page_notes_editor',
            array(
                'textarea_name' => 'wcp_page_notes',
                'media_buttons' => false,
                'textarea_rows' => 10,
                'teeny'         => false,
            )
        );
    }

    public function save_meta($post_id) {
        if (!isset($_POST['wcp_page_notes_nonce']) ||
            !wp_verify_nonce($_POST['wcp_page_notes_nonce'], 'wcp_page_notes_save')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_page', $post_id)) {
            return;
        }

        if (isset($_POST['wcp_page_notes'])) {
            update_post_meta($post_id, '_wcp_page_notes', wp_kses_post($_POST['wcp_page_notes']));
        } else {
            delete_post_meta($post_id, '_wcp_page_notes');
        }
    }
}
