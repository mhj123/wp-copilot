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

        // Get parent page mission if exists
        $parent_mission = '';
        if ($post->post_parent) {
            $parent_mission = get_post_meta($post->post_parent, '_wcp_ai_page_mission', true);
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
