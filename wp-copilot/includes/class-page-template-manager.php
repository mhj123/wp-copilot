<?php
/**
 * Page Template Manager
 *
 * Applies a parent page's structural template to newly created child pages.
 * Handles both WP-admin-created pages (via save_post_page hook) and
 * AI-generated pages (called directly from class-ai-actions.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCP_Page_Template_Manager {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Apply template to WP-admin-created pages when they are saved
        add_action( 'save_post_page', array( $this, 'maybe_apply_on_save' ), 20, 3 );
    }

    /**
     * Retrieve and decode the template stored on a parent page, or null if none.
     *
     * @param int $parent_page_id
     * @return array|null { content_blocks: [], headings: [{ title, placeholder, items: [{title}] }] }
     */
    public function get_template( $parent_page_id ) {
        $raw = get_post_meta( $parent_page_id, '_wcp_page_template', true );
        if ( empty( $raw ) ) {
            return null;
        }
        $template = json_decode( $raw, true );
        if ( ! is_array( $template ) ) {
            return null;
        }
        // Must have at least one block or heading to be worth applying
        $has_content  = ! empty( $template['content_blocks'] ) && is_array( $template['content_blocks'] );
        $has_headings = ! empty( $template['headings'] ) && is_array( $template['headings'] );
        if ( ! $has_content && ! $has_headings ) {
            return null;
        }
        return $template;
    }

    /**
     * Apply a template to a child page.
     * Sets post_content from content_blocks and creates wcp_heading posts.
     * Marks the page with _wcp_template_applied to prevent re-application.
     *
     * @param int   $child_page_id
     * @param array $template
     */
    public function apply_template( $child_page_id, $template ) {
        // Guard: only apply once
        if ( get_post_meta( $child_page_id, '_wcp_template_applied', true ) ) {
            return;
        }

        // Build post_content HTML from content_blocks
        if ( ! empty( $template['content_blocks'] ) ) {
            $html = '';
            foreach ( $template['content_blocks'] as $block ) {
                $title = sanitize_text_field( $block['title'] ?? '' );
                if ( empty( $title ) ) continue;

                $tag   = in_array( $block['level'] ?? '', array( 'h2', 'h3', 'h4' ), true ) ? $block['level'] : 'h2';
                $html .= "<{$tag}>" . esc_html( $title ) . "</{$tag}>\n";

                if ( ! empty( $block['placeholder'] ) ) {
                    $html .= '<p>' . esc_html( sanitize_textarea_field( $block['placeholder'] ) ) . "</p>\n";
                }
            }

            if ( $html ) {
                // Unhook our own save listener to avoid recursion
                remove_action( 'save_post_page', array( $this, 'maybe_apply_on_save' ), 20 );

                wp_update_post( array(
                    'ID'           => $child_page_id,
                    'post_content' => $html,
                ) );

                add_action( 'save_post_page', array( $this, 'maybe_apply_on_save' ), 20, 3 );
            }
        }

        // Create wcp_heading posts and their checklist items for each section heading
        if ( ! empty( $template['headings'] ) ) {
            foreach ( $template['headings'] as $index => $heading ) {
                $title = sanitize_text_field( $heading['title'] ?? '' );
                if ( empty( $title ) ) continue;

                $heading_id = wp_insert_post( array(
                    'post_type'    => 'wcp_heading',
                    'post_title'   => $title,
                    'post_content' => sanitize_textarea_field( $heading['placeholder'] ?? '' ),
                    'post_status'  => 'publish',
                    'post_author'  => get_current_user_id() ?: 1,
                    'menu_order'   => isset( $heading['menu_order'] ) ? (int) $heading['menu_order'] : ( $index * 10 ),
                ) );

                if ( is_wp_error( $heading_id ) ) {
                    continue;
                }

                update_post_meta( $heading_id, '_wcp_parent_type', 'page' );
                update_post_meta( $heading_id, '_wcp_parent_id', $child_page_id );
                // The save_post hook fires before parent meta exists, so sync
                // again now that the heading can be placed under the child page.
                if ( class_exists( 'WCP_Taxonomy_Sync' ) ) {
                    WCP_Taxonomy_Sync::instance()->sync_heading_to_taxonomy( $heading_id, get_post( $heading_id ), true );
                }

                // Create checklist items under this heading if the template defines any
                if ( ! empty( $heading['items'] ) && is_array( $heading['items'] ) ) {
                    $this->create_heading_items( $heading_id, $heading['items'] );
                }
            }
        }

        // Mark as applied so subsequent saves don't re-apply
        update_post_meta( $child_page_id, '_wcp_template_applied', '1' );
    }

    /**
     * Create ItemPosts (tasks) under a newly-created heading.
     * Resolves the heading's wcp_context term (set by taxonomy sync on insert)
     * and assigns each item to it.
     *
     * @param int   $heading_id
     * @param array $items       Array of { title } from the template definition.
     */
    private function create_heading_items( $heading_id, $items ) {
        // Taxonomy sync runs synchronously on wp_insert_post, so the term exists now
        $terms = get_terms( array(
            'taxonomy'   => 'wcp_context',
            'hide_empty' => false,
            'meta_query' => array(
                array( 'key' => 'wcp_ref_type', 'value' => 'wcp_heading' ),
                array( 'key' => 'wcp_ref_id',   'value' => $heading_id, 'type' => 'NUMERIC' ),
            ),
        ) );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return;
        }

        $term_id = $terms[0]->term_id;
        $author  = get_current_user_id() ?: 1;

        foreach ( $items as $item ) {
            $item_title = sanitize_text_field( $item['title'] ?? '' );
            if ( empty( $item_title ) ) continue;

            $item_id = wp_insert_post( array(
                'post_type'   => 'post',
                'post_title'  => $item_title,
                'post_status' => 'publish',
                'post_author' => $author,
            ) );

            if ( ! is_wp_error( $item_id ) ) {
                wp_set_post_terms( $item_id, array( $term_id ), 'wcp_context' );
                wp_set_post_terms( $item_id, array( 'task' ), 'item_type' );
            }
        }
    }

    /**
     * Hook: fires on save_post_page.
     * Applies the parent template to new WP-admin-created child pages
     * that have no existing content.
     *
     * @param int     $post_id
     * @param WP_Post $post
     * @param bool    $update  True if this is an update, false if new.
     */
    public function maybe_apply_on_save( $post_id, $post, $update ) {
        // Skip autosaves and revisions
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        // Must have a parent page
        if ( empty( $post->post_parent ) ) {
            return;
        }

        // Template already applied — don't touch again
        if ( get_post_meta( $post_id, '_wcp_template_applied', true ) ) {
            return;
        }

        // Only apply to pages with no body content (admin-created pages with
        // existing content should not be overwritten)
        if ( ! empty( $post->post_content ) ) {
            return;
        }

        $template = $this->get_template( $post->post_parent );
        if ( $template ) {
            $this->apply_template( $post_id, $template );
        }
    }
}
