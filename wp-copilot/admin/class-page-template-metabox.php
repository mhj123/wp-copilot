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

        $sraw     = get_post_meta( $post->ID, '_wcp_page_schedule', true );
        $schedule = $sraw ? json_decode( $sraw, true ) : array();
        $next_run = (int) get_post_meta( $post->ID, '_wcp_schedule_next_run', true );
        ?>
        <div id="wcp-template-wrap">

            <p class="description">
                <?php _e( 'Define the default structure for new child pages created under this page. Everything is applied once at creation and can be edited freely per page.', 'work-copilot' ); ?>
            </p>

            <!-- Content Headings (post_content) -->
            <h4 style="margin-bottom:4px;"><?php _e( 'Content Headings', 'work-copilot' ); ?></h4>
            <p class="description" style="margin-bottom:8px;"><?php _e( 'HTML headings inside the page body (H2–H4), with optional guide text beneath each.', 'work-copilot' ); ?></p>

            <div id="wcp-content-blocks">
                <?php foreach ( $content_blocks as $i => $block ) : ?>
                    <?php $this->render_content_block_row( $i, $block ); ?>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button" id="wcp-add-content-block" style="margin-top:6px;">
                + <?php _e( 'Add content heading', 'work-copilot' ); ?>
            </button>

            <hr style="margin:16px 0;">

            <!-- Section Headings (wcp_heading posts) with optional checklist items -->
            <h4 style="margin-bottom:4px;"><?php _e( 'Section Headings', 'work-copilot' ); ?></h4>
            <p class="description" style="margin-bottom:8px;"><?php _e( 'On-page section headings that group items. Each heading can have optional checklist items pre-created beneath it.', 'work-copilot' ); ?></p>

            <div id="wcp-headings">
                <?php foreach ( $headings as $i => $heading ) : ?>
                    <?php $this->render_heading_row( $i, $heading ); ?>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button" id="wcp-add-heading" style="margin-top:6px;">
                + <?php _e( 'Add section heading', 'work-copilot' ); ?>
            </button>

            <hr style="margin:16px 0;">

            <!-- Scheduled Page Creation -->
            <h4 style="margin-bottom:4px;"><?php _e( 'Scheduled Page Creation', 'work-copilot' ); ?></h4>
            <p class="description" style="margin-bottom:12px;"><?php _e( 'Automatically create a new child page on a recurring schedule, with the template above applied. Requires WP-Cron (tip: add a crontab entry on your server for reliable timing).', 'work-copilot' ); ?></p>

            <table class="form-table" style="margin:0;">
                <tr>
                    <th style="width:160px;padding:6px 0;"><label for="wcp_sched_enabled"><?php _e( 'Enable schedule', 'work-copilot' ); ?></label></th>
                    <td style="padding:6px 0;">
                        <input type="checkbox" id="wcp_sched_enabled" name="wcp_schedule[enabled]" value="1" <?php checked( ! empty( $schedule['enabled'] ) ); ?>>
                    </td>
                </tr>
                <tr>
                    <th style="padding:6px 0;"><label for="wcp_sched_frequency"><?php _e( 'Frequency', 'work-copilot' ); ?></label></th>
                    <td style="padding:6px 0;">
                        <select id="wcp_sched_frequency" name="wcp_schedule[frequency]">
                            <?php
                            $freq = $schedule['frequency'] ?? 'weekly';
                            foreach ( array( 'weekly' => __( 'Weekly', 'work-copilot' ), 'fortnightly' => __( 'Fortnightly', 'work-copilot' ), 'monthly' => __( 'Monthly (every 4 weeks)', 'work-copilot' ) ) as $val => $label ) :
                            ?>
                                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $freq, $val ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th style="padding:6px 0;"><label for="wcp_sched_day"><?php _e( 'Day of week', 'work-copilot' ); ?></label></th>
                    <td style="padding:6px 0;">
                        <select id="wcp_sched_day" name="wcp_schedule[day_of_week]">
                            <?php
                            $day = intval( $schedule['day_of_week'] ?? 1 );
                            $days = array( 0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday' );
                            foreach ( $days as $num => $name ) :
                            ?>
                                <option value="<?php echo $num; ?>" <?php selected( $day, $num ); ?>><?php echo esc_html( $name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th style="padding:6px 0;"><?php _e( 'Time of day', 'work-copilot' ); ?></th>
                    <td style="padding:6px 0;">
                        <select name="wcp_schedule[hour]" style="width:70px;">
                            <?php $h = intval( $schedule['hour'] ?? 9 ); for ( $i = 0; $i < 24; $i++ ) : ?>
                                <option value="<?php echo $i; ?>" <?php selected( $h, $i ); ?>><?php printf( '%02d', $i ); ?></option>
                            <?php endfor; ?>
                        </select>
                        :
                        <select name="wcp_schedule[minute]" style="width:70px;">
                            <?php $m = intval( $schedule['minute'] ?? 0 ); foreach ( array( 0, 15, 30, 45 ) as $min ) : ?>
                                <option value="<?php echo $min; ?>" <?php selected( $m, $min ); ?>><?php printf( '%02d', $min ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="description">&nbsp;<?php echo esc_html( wp_timezone_string() ); ?></span>
                    </td>
                </tr>
                <tr>
                    <th style="padding:6px 0;"><label for="wcp_sched_title"><?php _e( 'Title pattern', 'work-copilot' ); ?></label></th>
                    <td style="padding:6px 0;">
                        <input type="text" id="wcp_sched_title" name="wcp_schedule[title_pattern]"
                            value="<?php echo esc_attr( $schedule['title_pattern'] ?? '' ); ?>"
                            class="regular-text"
                            placeholder="<?php esc_attr_e( 'e.g. Weekly Check-in – {date}', 'work-copilot' ); ?>">
                        <p class="description"><?php _e( '<code>{date}</code> is replaced with the current date when the page is created.', 'work-copilot' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th style="padding:6px 0;"><label for="wcp_sched_email"><?php _e( 'Notify email', 'work-copilot' ); ?></label></th>
                    <td style="padding:6px 0;">
                        <input type="email" id="wcp_sched_email" name="wcp_schedule[notify_email]"
                            value="<?php echo esc_attr( $schedule['notify_email'] ?? get_option( 'admin_email' ) ); ?>"
                            class="regular-text">
                        <p class="description"><?php _e( 'An email is sent each time a page is automatically created.', 'work-copilot' ); ?></p>
                    </td>
                </tr>
                <?php if ( $next_run > 0 ) : ?>
                <tr>
                    <th style="padding:6px 0;"><?php _e( 'Next creation', 'work-copilot' ); ?></th>
                    <td style="padding:6px 0;">
                        <span class="description"><?php echo esc_html( wp_date( 'D j M Y \a\t H:i', $next_run ) ); ?></span>
                    </td>
                </tr>
                <?php endif; ?>
            </table>

        </div>

        <!-- Hidden template for JS row cloning (content block) -->
        <script type="text/html" id="wcp-content-block-tpl">
            <?php $this->render_content_block_row( '__CB_IDX__', array() ); ?>
        </script>
        <!-- Hidden template for JS row cloning (heading, no pre-existing items) -->
        <script type="text/html" id="wcp-heading-tpl">
            <?php $this->render_heading_row( '__H_IDX__', array() ); ?>
        </script>

        <style>
        .wcp-heading-items-wrap { margin-top:8px; padding-top:8px; border-top:1px dashed #ccc; }
        .wcp-heading-items-wrap .wcp-item-row { display:flex; gap:6px; margin-bottom:4px; align-items:center; }
        .wcp-heading-items-wrap .wcp-item-row input { flex:1; }
        .wcp-heading-items-label { font-size:11px; color:#666; margin:0 0 4px; }
        </style>

        <script>
        (function() {
            var cbIdx = <?php echo count( $content_blocks ); ?>;
            var hIdx  = <?php echo count( $headings ); ?>;

            var cbWrap = document.getElementById( 'wcp-content-blocks' );
            var hWrap  = document.getElementById( 'wcp-headings' );

            // ---- Content block: remove row ----
            cbWrap.addEventListener( 'click', function( e ) {
                if ( e.target.classList.contains( 'wcp-remove-row' ) ) {
                    e.target.closest( '.wcp-template-row' ).remove();
                }
            } );

            // ---- Heading: remove heading row, add/remove item rows ----
            hWrap.addEventListener( 'click', function( e ) {
                // Remove heading row
                if ( e.target.classList.contains( 'wcp-remove-row' ) ) {
                    e.target.closest( '.wcp-heading-row' ).remove();
                    return;
                }
                // Remove item row
                if ( e.target.classList.contains( 'wcp-remove-item' ) ) {
                    e.target.closest( '.wcp-item-row' ).remove();
                    return;
                }
                // Add item row to this heading
                if ( e.target.classList.contains( 'wcp-add-heading-item' ) ) {
                    var headingRow    = e.target.closest( '.wcp-heading-row' );
                    var hI            = headingRow.dataset.hIdx;
                    var itemsWrap     = headingRow.querySelector( '.wcp-heading-items' );
                    var itemIdx       = parseInt( itemsWrap.dataset.itemIdx, 10 );

                    var div = document.createElement( 'div' );
                    div.innerHTML = '<div class="wcp-item-row">' +
                        '<input type="text" name="headings[' + hI + '][items][' + itemIdx + '][title]"' +
                        ' placeholder="<?php echo esc_js( __( 'Checklist item title', 'work-copilot' ) ); ?>">' +
                        '<button type="button" class="button wcp-remove-item">&times;</button>' +
                        '</div>';

                    itemsWrap.appendChild( div.firstElementChild );
                    itemsWrap.dataset.itemIdx = itemIdx + 1;
                    return;
                }
            } );

            // ---- Add new content block row ----
            document.getElementById( 'wcp-add-content-block' ).addEventListener( 'click', function() {
                var tpl = document.getElementById( 'wcp-content-block-tpl' ).innerHTML;
                var div = document.createElement( 'div' );
                div.innerHTML = tpl.replace( /__CB_IDX__/g, cbIdx++ );
                cbWrap.appendChild( div.firstElementChild );
            } );

            // ---- Add new heading row ----
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
                <textarea name="content_blocks[<?php echo $i; ?>][placeholder]" rows="2" placeholder="<?php esc_attr_e( 'Optional guide text shown beneath this heading', 'work-copilot' ); ?>" style="width:100%;"><?php echo $placeholder; ?></textarea>
            </div>
            <button type="button" class="button wcp-remove-row" style="flex-shrink:0;">&times;</button>
        </div>
        <?php
    }

    /**
     * Render a section heading row, including any pre-saved checklist items.
     *
     * @param int|string $i       Heading index (or '__H_IDX__' for the JS template).
     * @param array      $heading Heading data including optional 'items' array.
     */
    private function render_heading_row( $i, $heading ) {
        $title       = esc_attr( $heading['title'] ?? '' );
        $placeholder = esc_textarea( $heading['placeholder'] ?? '' );
        $items       = $heading['items'] ?? array();
        $item_count  = count( $items );
        ?>
        <div class="wcp-template-row wcp-heading-row" data-h-idx="<?php echo $i; ?>" style="margin-bottom:8px;padding:8px;background:#f9f9f9;border:1px solid #ddd;border-radius:3px;">
            <div style="display:flex;gap:8px;align-items:flex-start;">
                <div style="flex:1;">
                    <input type="text" name="headings[<?php echo $i; ?>][title]" value="<?php echo $title; ?>" placeholder="<?php esc_attr_e( 'Section heading title', 'work-copilot' ); ?>" class="regular-text" style="width:100%;margin-bottom:4px;">
                    <textarea name="headings[<?php echo $i; ?>][placeholder]" rows="2" placeholder="<?php esc_attr_e( 'Optional description for this section', 'work-copilot' ); ?>" style="width:100%;"><?php echo $placeholder; ?></textarea>
                </div>
                <button type="button" class="button wcp-remove-row" style="flex-shrink:0;">&times;</button>
            </div>

            <div class="wcp-heading-items-wrap">
                <p class="wcp-heading-items-label"><?php _e( 'Checklist items (created as tasks under this heading):', 'work-copilot' ); ?></p>
                <div class="wcp-heading-items" data-item-idx="<?php echo $item_count; ?>">
                    <?php foreach ( $items as $j => $item ) : ?>
                        <div class="wcp-item-row">
                            <input type="text"
                                name="headings[<?php echo $i; ?>][items][<?php echo $j; ?>][title]"
                                value="<?php echo esc_attr( $item['title'] ?? '' ); ?>"
                                placeholder="<?php esc_attr_e( 'Checklist item title', 'work-copilot' ); ?>">
                            <button type="button" class="button wcp-remove-item">&times;</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="button button-small wcp-add-heading-item" style="margin-top:4px;">
                    + <?php _e( 'Add item', 'work-copilot' ); ?>
                </button>
            </div>
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

                $items = array();
                if ( ! empty( $heading['items'] ) && is_array( $heading['items'] ) ) {
                    foreach ( $heading['items'] as $item ) {
                        $item_title = sanitize_text_field( $item['title'] ?? '' );
                        if ( empty( $item_title ) ) continue;
                        $items[] = array( 'title' => $item_title );
                    }
                }

                $headings[] = array(
                    'title'       => $title,
                    'placeholder' => sanitize_textarea_field( $heading['placeholder'] ?? '' ),
                    'items'       => $items,
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

        // Save schedule settings
        $raw_sched = $_POST['wcp_schedule'] ?? array();
        if ( ! empty( $raw_sched ) && is_array( $raw_sched ) ) {
            $valid_freq = array( 'weekly', 'fortnightly', 'monthly' );
            $schedule   = array(
                'enabled'       => ! empty( $raw_sched['enabled'] ),
                'frequency'     => in_array( $raw_sched['frequency'] ?? '', $valid_freq, true ) ? $raw_sched['frequency'] : 'weekly',
                'day_of_week'   => intval( $raw_sched['day_of_week'] ?? 1 ),
                'hour'          => min( 23, max( 0, intval( $raw_sched['hour'] ?? 9 ) ) ),
                'minute'        => in_array( intval( $raw_sched['minute'] ?? 0 ), array( 0, 15, 30, 45 ), true ) ? intval( $raw_sched['minute'] ) : 0,
                'title_pattern' => sanitize_text_field( $raw_sched['title_pattern'] ?? '' ),
                'notify_email'  => sanitize_email( $raw_sched['notify_email'] ?? get_option( 'admin_email' ) ),
            );
            update_post_meta( $post_id, '_wcp_page_schedule', wp_json_encode( $schedule ) );
        } else {
            delete_post_meta( $post_id, '_wcp_page_schedule' );
        }

        // Recalculate next_run for this page
        WCP_Page_Scheduler::instance()->sync_schedule( $post_id );
    }
}
