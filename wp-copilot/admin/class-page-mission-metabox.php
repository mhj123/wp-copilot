<?php
/**
 * Page Mission Metabox
 *
 * Allows defining AI agent mission at the page level
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_Page_Mission_Metabox {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('add_meta_boxes', array($this, 'add_mission_metabox'));
        add_action('save_post_page', array($this, 'save_mission_meta'));
    }

    public function add_mission_metabox() {
        add_meta_box(
            'wcp_page_mission',
            __('AI Mission for This Page', 'work-copilot'),
            array($this, 'render_mission_metabox'),
            'page',
            'normal',
            'default'
        );
    }

    public function render_mission_metabox($post) {
        wp_nonce_field('wcp_page_mission_save', 'wcp_page_mission_nonce');

        $page_mission = get_post_meta($post->ID, '_wcp_ai_page_mission', true);
        $inherit_from_parent = get_post_meta($post->ID, '_wcp_ai_mission_inherit_parent', true);
        $page_summary = get_post_meta($post->ID, '_wcp_page_compact_summary', true);
        $summary_generated_at = get_post_meta($post->ID, '_wcp_summary_generated_at', true);

        // Get parent page mission if exists
        $parent_mission = '';
        if ($post->post_parent) {
            $parent_mission = get_post_meta($post->post_parent, '_wcp_ai_page_mission', true);
        }

        // Calculate summary age
        $summary_age_days = null;
        if ($summary_generated_at) {
            $generated_time = strtotime($summary_generated_at);
            $summary_age_days = floor((time() - $generated_time) / DAY_IN_SECONDS);
        }

        ?>
        <div class="wcp-page-mission-wrapper">
            <p class="description">
                <?php _e('Define how the AI agent should behave when working on this page and its sub-pages. This mission overrides the global mission for this context.', 'work-copilot'); ?>
            </p>

            <textarea
                name="wcp_ai_page_mission"
                id="wcp-ai-page-mission"
                rows="6"
                class="large-text code"
                placeholder="<?php _e('Example: This project involves migrating fulfillment operations. Help the user track progress, identify blockers, and align with overall ecommerce strategy.', 'work-copilot'); ?>"
            ><?php echo esc_textarea($page_mission); ?></textarea>

            <p class="description" style="margin-top: 10px;">
                <strong><?php _e('How missions work:', 'work-copilot'); ?></strong><br>
                <?php _e('1. If this field has content, it will be used', 'work-copilot'); ?><br>
                <?php _e('2. If this is empty and "Inherit from parent" is checked, the parent page mission will be used', 'work-copilot'); ?><br>
                <?php _e('3. If no parent mission exists, the global mission from Settings will be used', 'work-copilot'); ?>
            </p>

            <?php if ($post->post_parent && $parent_mission): ?>
                <p style="margin-top: 15px;">
                    <label>
                        <input
                            type="checkbox"
                            name="wcp_ai_mission_inherit_parent"
                            value="1"
                            <?php checked($inherit_from_parent, '1'); ?>
                        >
                        <?php printf(
                            __('Inherit mission from parent page (%s)', 'work-copilot'),
                            esc_html(get_the_title($post->post_parent))
                        ); ?>
                    </label>
                </p>

                <div id="wcp-parent-mission-preview" style="margin-top: 10px; padding: 12px; background: #f9f9f9; border-left: 3px solid #ccc; display: <?php echo $inherit_from_parent ? 'block' : 'none'; ?>;">
                    <strong><?php _e('Parent Mission:', 'work-copilot'); ?></strong>
                    <p style="margin: 8px 0 0 0; font-style: italic; color: #666;"><?php echo esc_html($parent_mission); ?></p>
                </div>
            <?php endif; ?>

            <p class="description" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
                <?php _e('Leave blank to use the global mission from', 'work-copilot'); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=work-copilot-settings')); ?>" target="_blank">
                    <?php _e('Settings', 'work-copilot'); ?>
                </a>
            </p>

            <!-- Page Summary Section -->
            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">
                <h4 style="margin: 0 0 10px 0;"><?php _e('Page Summary for AI Context', 'work-copilot'); ?></h4>
                <p class="description">
                    <?php _e('A compact summary of this page is used in AI context to reduce token usage. Refresh when page content changes significantly.', 'work-copilot'); ?>
                </p>

                <?php if ($page_summary): ?>
                    <div id="wcp-page-summary-display" style="margin-top: 10px; padding: 12px; background: #f9f9f9; border-left: 3px solid #2271b1;">
                        <div style="margin-bottom: 8px;">
                            <strong><?php _e('Current Summary:', 'work-copilot'); ?></strong>
                            <?php if ($summary_age_days !== null): ?>
                                <span style="color: <?php echo $summary_age_days > 7 ? '#d63638' : '#666'; ?>; font-size: 12px; margin-left: 8px;">
                                    (<?php printf(__('Generated %d days ago', 'work-copilot'), $summary_age_days); ?>)
                                </span>
                            <?php endif; ?>
                        </div>
                        <p id="wcp-summary-text" style="margin: 0; font-style: italic; color: #666;"><?php echo esc_html($page_summary); ?></p>
                    </div>
                <?php else: ?>
                    <div id="wcp-page-summary-display" style="margin-top: 10px; padding: 12px; background: #fff3cd; border-left: 3px solid #ffc107;">
                        <p style="margin: 0; color: #856404;">
                            <?php _e('No summary generated yet. Click "Refresh Summary" to generate one.', 'work-copilot'); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <p style="margin-top: 10px;">
                    <button type="button" id="wcp-refresh-summary" class="button" data-page-id="<?php echo esc_attr($post->ID); ?>">
                        <?php _e('Refresh Summary', 'work-copilot'); ?>
                    </button>
                    <span id="wcp-summary-status" style="margin-left: 10px; color: #666;"></span>
                </p>
            </div>
        </div>

        <style>
            .wcp-page-mission-wrapper {
                margin: 0;
            }
            #wcp-ai-page-mission {
                font-family: Consolas, Monaco, monospace;
                font-size: 13px;
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // Show/hide parent mission preview when checkbox changes
            $('input[name="wcp_ai_mission_inherit_parent"]').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#wcp-parent-mission-preview').slideDown();
                    $('#wcp-ai-page-mission').prop('disabled', true).css('opacity', '0.5');
                } else {
                    $('#wcp-parent-mission-preview').slideUp();
                    $('#wcp-ai-page-mission').prop('disabled', false).css('opacity', '1');
                }
            });

            // Initialize state
            if ($('input[name="wcp_ai_mission_inherit_parent"]').is(':checked')) {
                $('#wcp-ai-page-mission').prop('disabled', true).css('opacity', '0.5');
            }

            // Refresh summary button
            $('#wcp-refresh-summary').on('click', function() {
                var $button = $(this);
                var $status = $('#wcp-summary-status');
                var pageId = $button.data('page-id');

                $button.prop('disabled', true).text('<?php _e('Generating...', 'work-copilot'); ?>');
                $status.text('<?php _e('Please wait...', 'work-copilot'); ?>').css('color', '#2271b1');

                $.ajax({
                    url: '<?php echo esc_url(rest_url('work-copilot/v1/page/refresh-summary')); ?>',
                    method: 'POST',
                    data: { page_id: pageId },
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce('wp_rest'); ?>');
                    },
                    success: function(response) {
                        if (response.success && response.summary) {
                            $('#wcp-summary-text').text(response.summary);
                            $('#wcp-page-summary-display').removeClass('notice-warning').css({
                                'background': '#f9f9f9',
                                'border-left-color': '#2271b1'
                            });
                            $status.text('<?php _e('Summary updated successfully!', 'work-copilot'); ?>').css('color', '#2ecc71');

                            // Update age display
                            var ageSpan = $('#wcp-page-summary-display').find('span[style*="color"]');
                            if (ageSpan.length) {
                                ageSpan.text('<?php _e('(Generated just now)', 'work-copilot'); ?>').css('color', '#666');
                            } else {
                                $('#wcp-page-summary-display strong').after(' <span style="color: #666; font-size: 12px; margin-left: 8px;"><?php _e('(Generated just now)', 'work-copilot'); ?></span>');
                            }
                        } else {
                            $status.text('<?php _e('Error: ', 'work-copilot'); ?>' + (response.message || '<?php _e('Unknown error', 'work-copilot'); ?>')).css('color', '#d63638');
                        }
                    },
                    error: function(xhr, status, error) {
                        $status.text('<?php _e('Request failed: ', 'work-copilot'); ?>' + error).css('color', '#d63638');
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('<?php _e('Refresh Summary', 'work-copilot'); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    public function save_mission_meta($post_id) {
        // Verify nonce
        if (!isset($_POST['wcp_page_mission_nonce']) ||
            !wp_verify_nonce($_POST['wcp_page_mission_nonce'], 'wcp_page_mission_save')) {
            return;
        }

        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Check permissions
        if (!current_user_can('edit_page', $post_id)) {
            return;
        }

        // Save page mission
        if (isset($_POST['wcp_ai_page_mission'])) {
            $mission = wp_kses_post($_POST['wcp_ai_page_mission']);
            update_post_meta($post_id, '_wcp_ai_page_mission', $mission);
        } else {
            delete_post_meta($post_id, '_wcp_ai_page_mission');
        }

        // Save inherit checkbox
        if (isset($_POST['wcp_ai_mission_inherit_parent']) && $_POST['wcp_ai_mission_inherit_parent'] === '1') {
            update_post_meta($post_id, '_wcp_ai_mission_inherit_parent', '1');
        } else {
            delete_post_meta($post_id, '_wcp_ai_mission_inherit_parent');
        }
    }
}
