<?php
/**
 * Admin Interface
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_Admin {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('add_meta_boxes', array($this, 'add_ai_meta_boxes'));
    }

    public function add_admin_menu() {
        add_menu_page(
            __('Work Copilot', 'work-copilot'),
            __('Work Copilot', 'work-copilot'),
            'edit_posts',
            'work-copilot',
            array($this, 'render_dashboard'),
            'dashicons-networking',
            3
        );

        add_submenu_page(
            'work-copilot',
            __('Dashboard', 'work-copilot'),
            __('Dashboard', 'work-copilot'),
            'edit_posts',
            'work-copilot',
            array($this, 'render_dashboard')
        );

        add_submenu_page(
            'work-copilot',
            __('AI Audit Log', 'work-copilot'),
            __('AI Audit Log', 'work-copilot'),
            'edit_posts',
            'work-copilot-ai-log',
            array($this, 'render_ai_log')
        );
    }

    public function enqueue_scripts($hook) {
        // Enqueue on Work Copilot pages
        if (strpos($hook, 'work-copilot') !== false || $hook === 'post.php' || $hook === 'post-new.php') {
            wp_enqueue_style(
                'work-copilot-admin',
                WCP_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                WCP_VERSION
            );

            wp_enqueue_script(
                'work-copilot-admin',
                WCP_PLUGIN_URL . 'assets/js/admin.js',
                array('jquery'),
                WCP_VERSION,
                true
            );

            wp_localize_script('work-copilot-admin', 'wcpData', array(
                'restUrl' => rest_url('work-copilot/v1'),
                'nonce' => wp_create_nonce('wp_rest'),
            ));
        }
    }

    public function render_dashboard() {
        ?>
        <div class="wrap wcp-dashboard">
            <h1><?php _e('Work Copilot Dashboard', 'work-copilot'); ?></h1>

            <div class="wcp-grid">
                <div class="wcp-col-8">
                    <div class="wcp-card">
                        <h2><?php _e('Quick Create ItemPost', 'work-copilot'); ?></h2>
                        <form id="wcp-quick-create">
                            <input type="text" id="wcp-quick-title" placeholder="<?php _e('Title', 'work-copilot'); ?>" style="width: 100%; margin-bottom: 10px; padding: 8px;">
                            <textarea id="wcp-quick-content" placeholder="<?php _e('Content', 'work-copilot'); ?>" style="width: 100%; height: 120px; margin-bottom: 10px; padding: 8px;"></textarea>

                            <div style="margin-bottom: 10px;">
                                <label><?php _e('Contexts:', 'work-copilot'); ?></label>
                                <div id="wcp-context-selector"></div>
                            </div>

                            <div style="margin-bottom: 10px;">
                                <label><?php _e('Item Type:', 'work-copilot'); ?></label>
                                <select id="wcp-item-type" style="width: 100%;">
                                    <option value="">-</option>
                                    <option value="task"><?php _e('Task', 'work-copilot'); ?></option>
                                    <option value="info"><?php _e('Info', 'work-copilot'); ?></option>
                                    <option value="learning"><?php _e('Learning', 'work-copilot'); ?></option>
                                </select>
                            </div>

                            <div style="margin-bottom: 10px;">
                                <label><?php _e('Priority:', 'work-copilot'); ?></label>
                                <select id="wcp-priority" style="width: 100%;">
                                    <option value="">-</option>
                                    <option value="high"><?php _e('High', 'work-copilot'); ?></option>
                                    <option value="medium"><?php _e('Medium', 'work-copilot'); ?></option>
                                    <option value="low"><?php _e('Low', 'work-copilot'); ?></option>
                                </select>
                            </div>

                            <button type="submit" class="button button-primary"><?php _e('Create Item', 'work-copilot'); ?></button>
                            <button type="button" id="wcp-ai-suggest" class="button"><?php _e('AI Suggest Tags', 'work-copilot'); ?></button>
                        </form>
                    </div>

                    <div class="wcp-card" style="margin-top: 20px;">
                        <h2><?php _e('Recent ItemPosts', 'work-copilot'); ?></h2>
                        <div id="wcp-recent-items"></div>
                    </div>
                </div>

                <div class="wcp-col-4">
                    <div class="wcp-card">
                        <h2><?php _e('Context Tree', 'work-copilot'); ?></h2>
                        <div id="wcp-context-tree"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_ai_log() {
        $logger = WCP_AI_Logger::instance();
        $actions = $logger->get_recent_actions(100);
        ?>
        <div class="wrap">
            <h1><?php _e('AI Audit Log', 'work-copilot'); ?></h1>
            <p><?php _e('All AI interactions are logged for transparency and auditability.', 'work-copilot'); ?></p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Timestamp', 'work-copilot'); ?></th>
                        <th><?php _e('Action Type', 'work-copilot'); ?></th>
                        <th><?php _e('Model', 'work-copilot'); ?></th>
                        <th><?php _e('Context', 'work-copilot'); ?></th>
                        <th><?php _e('Accepted', 'work-copilot'); ?></th>
                        <th><?php _e('Dismissed', 'work-copilot'); ?></th>
                        <th><?php _e('Action', 'work-copilot'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($actions)): ?>
                        <tr>
                            <td colspan="7"><?php _e('No AI actions logged yet.', 'work-copilot'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($actions as $action): ?>
                            <tr>
                                <td><?php echo esc_html($action['timestamp']); ?></td>
                                <td><?php echo esc_html($action['action_type']); ?></td>
                                <td><?php echo esc_html($action['model']); ?></td>
                                <td>
                                    <?php if ($action['context_post_id']): ?>
                                        <a href="<?php echo esc_url(get_edit_post_link($action['context_post_id'])); ?>">
                                            <?php echo esc_html(get_the_title($action['context_post_id'])); ?>
                                        </a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo !empty($action['accepted_items']) ? count($action['accepted_items']) : 0; ?></td>
                                <td><?php echo !empty($action['dismissed_items']) ? count($action['dismissed_items']) : 0; ?></td>
                                <td>
                                    <button class="button button-small wcp-view-action-details" data-action-id="<?php echo esc_attr($action['action_id']); ?>">
                                        <?php _e('View Details', 'work-copilot'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function add_ai_meta_boxes() {
        // Add AI assistant meta box to posts
        add_meta_box(
            'wcp_ai_assistant',
            __('AI Assistant', 'work-copilot'),
            array($this, 'render_ai_assistant_meta_box'),
            'post',
            'side',
            'default'
        );

        // Add AI assistant to pages
        add_meta_box(
            'wcp_page_ai',
            __('AI Chat & Coaching', 'work-copilot'),
            array($this, 'render_page_ai_meta_box'),
            'page',
            'side',
            'default'
        );
    }

    public function render_ai_assistant_meta_box($post) {
        ?>
        <div class="wcp-ai-assistant">
            <p><?php _e('Get AI suggestions for this note:', 'work-copilot'); ?></p>
            <button type="button" class="button button-primary wcp-ai-suggest-tags" data-post-id="<?php echo esc_attr($post->ID); ?>">
                <?php _e('Suggest Tags', 'work-copilot'); ?>
            </button>
            <div id="wcp-ai-suggestions" style="margin-top: 10px;"></div>
        </div>
        <?php
    }

    public function render_page_ai_meta_box($post) {
        ?>
        <div class="wcp-page-ai">
            <h4><?php _e('Quick Prompts', 'work-copilot'); ?></h4>
            <button type="button" class="button wcp-ai-chat" data-post-id="<?php echo esc_attr($post->ID); ?>" data-prompt="Summarise this page and its items">
                <?php _e('Summarise', 'work-copilot'); ?>
            </button>
            <button type="button" class="button wcp-ai-chat" data-post-id="<?php echo esc_attr($post->ID); ?>" data-prompt="What are the most important items here?">
                <?php _e('Important Items', 'work-copilot'); ?>
            </button>

            <h4 style="margin-top: 15px;"><?php _e('Coaching', 'work-copilot'); ?></h4>
            <button type="button" class="button wcp-ai-coaching" data-post-id="<?php echo esc_attr($post->ID); ?>" data-type="coach">
                <?php _e('Coach me', 'work-copilot'); ?>
            </button>
            <button type="button" class="button wcp-ai-coaching" data-post-id="<?php echo esc_attr($post->ID); ?>" data-type="business">
                <?php _e('Reframe as Business Owner', 'work-copilot'); ?>
            </button>
            <button type="button" class="button wcp-ai-coaching" data-post-id="<?php echo esc_attr($post->ID); ?>" data-type="pm">
                <?php _e('Reframe as PM', 'work-copilot'); ?>
            </button>

            <div id="wcp-ai-response" style="margin-top: 15px;"></div>
        </div>
        <?php
    }
}
