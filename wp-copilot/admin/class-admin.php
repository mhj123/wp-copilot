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
        // Add AI assistant meta box to posts AND pages
        add_meta_box(
            'wcp_ai_assistant',
            __('AI Assistant', 'work-copilot'),
            array($this, 'render_editor_ai_meta_box'),
            array('post', 'page'),
            'side',
            'default'
        );
    }

    /**
     * Render enhanced AI assistant meta box for editor
     */
    public function render_editor_ai_meta_box($post) {
        // Get saved prompts
        $saved_prompts = get_option('wcp_saved_prompts', array());
        if (empty($saved_prompts)) {
            $saved_prompts = array(
                array('label' => 'Expand', 'prompt' => 'Expand this with more detail and examples'),
                array('label' => 'Concise', 'prompt' => 'Make this more concise while keeping key points'),
                array('label' => 'Actions', 'prompt' => 'Add actionable next steps'),
            );
        }
        ?>
        <div class="wcp-editor-ai" data-post-id="<?php echo esc_attr($post->ID); ?>">
            <!-- Prompt chips -->
            <div class="wcp-editor-ai-chips">
                <?php foreach ($saved_prompts as $prompt): ?>
                    <button type="button" class="wcp-editor-chip" data-prompt="<?php echo esc_attr($prompt['prompt']); ?>">
                        <?php echo esc_html($prompt['label']); ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- Context selector -->
            <div class="wcp-editor-ai-context">
                <label><?php _e('Context:', 'work-copilot'); ?></label>
                <select id="wcp-editor-context-mode">
                    <option value="page"><?php _e('This Page', 'work-copilot'); ?></option>
                    <option value="corpus"><?php _e('Entire Corpus (RAG)', 'work-copilot'); ?></option>
                </select>
            </div>

            <!-- Prompt input -->
            <div class="wcp-editor-ai-input">
                <textarea
                    id="wcp-editor-ai-prompt"
                    placeholder="<?php _e('Describe how to modify your draft...', 'work-copilot'); ?>"
                    rows="3"
                ></textarea>
            </div>

            <!-- Actions -->
            <div class="wcp-editor-ai-actions">
                <button type="button" id="wcp-editor-ai-generate" class="button button-primary">
                    <?php _e('Generate', 'work-copilot'); ?>
                </button>
                <button type="button" id="wcp-editor-ai-save-prompt" class="button" title="<?php _e('Save prompt as chip', 'work-copilot'); ?>">
                    <span class="dashicons dashicons-star-empty"></span>
                </button>
            </div>

            <!-- Loading indicator -->
            <div class="wcp-editor-ai-loading" style="display: none;">
                <span class="spinner is-active"></span>
                <span><?php _e('Generating...', 'work-copilot'); ?></span>
            </div>

            <!-- Response area -->
            <div class="wcp-editor-ai-response" style="display: none;">
                <h4><?php _e('AI Response:', 'work-copilot'); ?></h4>
                <div class="wcp-editor-ai-response-content"></div>
                <div class="wcp-editor-ai-response-actions">
                    <button type="button" id="wcp-editor-ai-insert" class="button button-primary">
                        <?php _e('Insert into Content', 'work-copilot'); ?>
                    </button>
                    <button type="button" id="wcp-editor-ai-discard" class="button">
                        <?php _e('Discard', 'work-copilot'); ?>
                    </button>
                </div>
            </div>
        </div>

        <style>
            .wcp-editor-ai-chips {
                display: flex;
                flex-wrap: wrap;
                gap: 5px;
                margin-bottom: 10px;
            }
            .wcp-editor-chip {
                padding: 4px 10px;
                background: #e8f4fc;
                border: 1px solid #b8daff;
                border-radius: 12px;
                font-size: 11px;
                color: #0073aa;
                cursor: pointer;
            }
            .wcp-editor-chip:hover {
                background: #cce5ff;
            }
            .wcp-editor-ai-context {
                margin-bottom: 10px;
            }
            .wcp-editor-ai-context label {
                display: block;
                font-weight: 600;
                margin-bottom: 5px;
                font-size: 11px;
            }
            .wcp-editor-ai-context select {
                width: 100%;
            }
            .wcp-editor-ai-input textarea {
                width: 100%;
                margin-bottom: 10px;
            }
            .wcp-editor-ai-actions {
                display: flex;
                gap: 5px;
                margin-bottom: 10px;
            }
            .wcp-editor-ai-actions .dashicons {
                margin-top: 3px;
            }
            .wcp-editor-ai-loading {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 10px;
                background: #f7f7f7;
                border-radius: 4px;
                margin-bottom: 10px;
            }
            .wcp-editor-ai-response {
                background: #f0f6fc;
                border: 1px solid #b8daff;
                border-radius: 4px;
                padding: 10px;
                margin-top: 10px;
            }
            .wcp-editor-ai-response h4 {
                margin: 0 0 10px 0;
                font-size: 12px;
                color: #0073aa;
            }
            .wcp-editor-ai-response-content {
                background: #fff;
                border: 1px solid #ddd;
                padding: 10px;
                border-radius: 4px;
                margin-bottom: 10px;
                max-height: 200px;
                overflow-y: auto;
                white-space: pre-wrap;
                font-size: 12px;
            }
            .wcp-editor-ai-response-actions {
                display: flex;
                gap: 5px;
            }
        </style>
        <?php
    }


}
