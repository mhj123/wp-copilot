<?php
/**
 * Page Template Metabox
 *
 * Lets a parent page define a structural template that is automatically
 * applied to every new direct child page on creation.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCP_Page_Template_Metabox {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'add_metabox' ) );
        add_action( 'save_post_page', array( $this, 'save_meta' ) );
    }

    public function add_metabox() {
        add_meta_box(
            'wcp_page_template',
            __( 'Child Page Template', 'work-copilot' ),
            array( $this, 'render' ),
            'page',
            'normal',
            'low'
        );
    }

    public function render( $post ) {
        wp_nonce_field( 'wcp_page_template_save', 'wcp_page_template_nonce' );

        $raw      = get_post_meta( $post->ID, '_wcp_page_template', true );
        $template = $raw ? json_decode( $raw, true ) : array();

        $content_blocks = $template['content_blocks'] ?? array();
        $headings       = $template['headings'] ?? array();
        ?>
        <div id="wcp-template-wrap">

            <p class="description">
                <?php _e( 'Define the default structure for new child pages created under this page. Headings and placeholder text are applied once at creation and can be edited freely per page.', 'work-copilot' ); ?>
            </p>

            <!-- Content Headings (post_content) -->
            <h4 style="margin-bottom:4px;"><?php _e( 'Content Headings', 'work-copilot' ); ?></h4>
            <p class="description" style="margin-bottom:8px;"><?php _e( 'These become HTML headings inside the page body (H2–H4), with optional guide text beneath each.', 'work-copilot' ); ?></p>

            <div id="wcp-content-blocks">
                <?php foreach ( $content_blocks as $i => $block ) : ?>
                    <?php $this->render_content_block_row( $i, $block ); ?>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button" id="wcp-add-content-block" style="margin-top:6px;">
                + <?php _e( 'Add content heading', 'work-copilot' ); ?>
            </button>

            <hr style="margin:16px 0;">

            <!-- Section Headings (wcp_heading posts) -->
            <h4 style="margin-bottom:4px;"><?php _e( 'Section Headings', 'work-copilot' ); ?></h4>
            <p class="description" style="margin-bottom:8px;"><?php _e( 'These become on-page section headings (WPCopilot Headings) that group items. Optional placeholder text goes in the heading description.', 'work-copilot' ); ?></p>

            <div id="wcp-headings">
                <?php foreach ( $headings as $i => $heading ) : ?>
                    <?php $this->render_heading_row( $i, $heading ); ?>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button" id="wcp-add-heading" style="margin-top:6px;">
                + <?php _e( 'Add section heading', 'work-copilot' ); ?>
            </button>

        </div>

        <!-- Hidden templates for JS row cloning -->
        <script type="text/html" id="wcp-content-block-tpl">
            <?php $this->render_content_block_row( '__CB_IDX__', array() ); ?>
        </script>
        <script type="text/html" id="wcp-heading-tpl">
            <?php $this->render_heading_row( '__H_IDX__', array() ); ?>
        </script>

        <script>
        (function() {
            var cbIdx = <?php echo count( $content_blocks ); ?>;
            var hIdx  = <?php echo count( $headings ); ?>;

            function bindRemove( container ) {
                container.addEventListener( 'click', function( e ) {
                    if ( e.target.classList.contains( 'wcp-remove-row' ) ) {
                        e.target.closest( '.wcp-template-row' ).remove();
                    }
                } );
            }

            var cbWrap = document.getElementById( 'wcp-content-blocks' );
            var hWrap  = document.getElementById( 'wcp-headings' );

            bindRemove( cbWrap );
            bindRemove( hWrap );

            document.getElementById( 'wcp-add-content-block' ).addEventListener( 'click', function() {
                var tpl = document.getElementById( 'wcp-content-block-tpl' ).innerHTML;
                var div = document.createElement( 'div' );
                div.innerHTML = tpl.replace( /__CB_IDX__/g, cbIdx++ );
                cbWrap.appendChild( div.firstElementChild );
            } );

            document.getElementById( 'wcp-add-heading' ).addEventListener( 'click', function() {
                var tpl = document.getElementById( 'wcp-heading-tpl' ).innerHTML;
                var div = document.createElement( 'div' );
                div.innerHTML = tpl.replace( /__H_IDX__/g, hIdx++ );
                hWrap.appendChild( div.firstElementChild );
            } );
        })();
        </script>
        <?php
    }

    private function render_content_block_row( $i, $block ) {
        $level       = esc_attr( $block['level'] ?? 'h2' );
        $title       = esc_attr( $block['title'] ?? '' );
        $placeholder = esc_textarea( $block['placeholder'] ?? '' );
        ?>
        <div class="wcp-template-row" style="display:flex;align-items:flex-start;gap:8px;margin-bottom:8px;padding:8px;background:#f9f9f9;border:1px solid #ddd;border-radius:3px;">
            <select name="content_blocks[<?php echo $i; ?>][level]" style="width:70px;flex-shrink:0;">
                <option value="h2" <?php selected( $level, 'h2' ); ?>>H2</option>
                <option value="h3" <?php selected( $level, 'h3' ); ?>>H3</option>
                <option value="h4" <?php selected( $level, 'h4' ); ?>>H4</option>
            </select>
            <div style="flex:1;">
                <input type="text" name="content_blocks[<?php echo $i; ?>][title]" value="<?php echo $title; ?>" placeholder="<?php esc_attr_e( 'Heading title', 'work-copilot' ); ?>" class="regular-text" style="width:100%;margin-bottom:4px;">
                <textarea name="content_blocks[<?php echo $i; ?>][placeholder]" rows="2" placeholder="<?php esc_attr_e( 'Optional guide text shown beneath this heading (editable per page)', 'work-copilot' ); ?>" style="width:100%;"><?php echo $placeholder; ?></textarea>
            </div>
            <button type="button" class="button wcp-remove-row" style="flex-shrink:0;">&times;</button>
        </div>
        <?php
    }

    private function render_heading_row( $i, $heading ) {
        $title       = esc_attr( $heading['title'] ?? '' );
        $placeholder = esc_textarea( $heading['placeholder'] ?? '' );
        ?>
        <div class="wcp-template-row" style="display:flex;align-items:flex-start;gap:8px;margin-bottom:8px;padding:8px;background:#f9f9f9;border:1px solid #ddd;border-radius:3px;">
            <div style="flex:1;">
                <input type="text" name="headings[<?php echo $i; ?>][title]" value="<?php echo $title; ?>" placeholder="<?php esc_attr_e( 'Section heading title', 'work-copilot' ); ?>" class="regular-text" style="width:100%;margin-bottom:4px;">
                <textarea name="headings[<?php echo $i; ?>][placeholder]" rows="2" placeholder="<?php esc_attr_e( 'Optional description/placeholder for this section (editable per page)', 'work-copilot' ); ?>" style="width:100%;"><?php echo $placeholder; ?></textarea>
            </div>
            <button type="button" class="button wcp-remove-row" style="flex-shrink:0;">&times;</button>
        </div>
        <?php
    }

    public function save_meta( $post_id ) {
        if ( ! isset( $_POST['wcp_page_template_nonce'] ) ||
             ! wp_verify_nonce( $_POST['wcp_page_template_nonce'], 'wcp_page_template_save' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_page', $post_id ) ) {
            return;
        }

        $content_blocks = array();
        if ( ! empty( $_POST['content_blocks'] ) && is_array( $_POST['content_blocks'] ) ) {
            foreach ( $_POST['content_blocks'] as $block ) {
                $title = sanitize_text_field( $block['title'] ?? '' );
                if ( empty( $title ) ) continue;
                $content_blocks[] = array(
                    'level'       => in_array( $block['level'] ?? '', array( 'h2', 'h3', 'h4' ), true ) ? $block['level'] : 'h2',
                    'title'       => $title,
                    'placeholder' => sanitize_textarea_field( $block['placeholder'] ?? '' ),
                );
            }
        }

        $headings = array();
        if ( ! empty( $_POST['headings'] ) && is_array( $_POST['headings'] ) ) {
            foreach ( $_POST['headings'] as $heading ) {
                $title = sanitize_text_field( $heading['title'] ?? '' );
                if ( empty( $title ) ) continue;
                $headings[] = array(
                    'title'       => $title,
                    'placeholder' => sanitize_textarea_field( $heading['placeholder'] ?? '' ),
                );
            }
        }

        if ( empty( $content_blocks ) && empty( $headings ) ) {
            delete_post_meta( $post_id, '_wcp_page_template' );
        } else {
            update_post_meta( $post_id, '_wcp_page_template', wp_json_encode( array(
                'content_blocks' => $content_blocks,
                'headings'       => $headings,
            ) ) );
        }
    }
}
