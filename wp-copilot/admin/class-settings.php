<?php
/**
 * Settings Page for AI Configuration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_Settings {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function add_settings_page() {
        add_submenu_page(
            'work-copilot',
            __('Settings', 'work-copilot'),
            __('Settings', 'work-copilot'),
            'manage_options',
            'work-copilot-settings',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        // AI Settings
        register_setting('wcp_settings', 'wcp_anthropic_api_key', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ));

        register_setting('wcp_settings', 'wcp_ai_model', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'claude-3-5-sonnet-20241022',
        ));

        register_setting('wcp_settings', 'wcp_ai_enabled', array(
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
        ));

        // Embeddings/RAG Settings
        register_setting('wcp_settings', 'wcp_openai_api_key', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ));

        register_setting('wcp_settings', 'wcp_embeddings_enabled', array(
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
        ));

        add_settings_section(
            'wcp_ai_section',
            __('AI Configuration', 'work-copilot'),
            array($this, 'render_ai_section'),
            'work-copilot-settings'
        );

        add_settings_field(
            'wcp_ai_enabled',
            __('Enable AI Features', 'work-copilot'),
            array($this, 'render_ai_enabled_field'),
            'work-copilot-settings',
            'wcp_ai_section'
        );

        add_settings_field(
            'wcp_anthropic_api_key',
            __('Anthropic API Key', 'work-copilot'),
            array($this, 'render_api_key_field'),
            'work-copilot-settings',
            'wcp_ai_section'
        );

        add_settings_field(
            'wcp_ai_model',
            __('Claude Model', 'work-copilot'),
            array($this, 'render_model_field'),
            'work-copilot-settings',
            'wcp_ai_section'
        );

        // Embeddings section
        add_settings_section(
            'wcp_embeddings_section',
            __('Semantic Search & RAG Configuration', 'work-copilot'),
            array($this, 'render_embeddings_section'),
            'work-copilot-settings'
        );

        add_settings_field(
            'wcp_embeddings_enabled',
            __('Enable Semantic Search', 'work-copilot'),
            array($this, 'render_embeddings_enabled_field'),
            'work-copilot-settings',
            'wcp_embeddings_section'
        );

        add_settings_field(
            'wcp_openai_api_key',
            __('OpenAI API Key', 'work-copilot'),
            array($this, 'render_openai_api_key_field'),
            'work-copilot-settings',
            'wcp_embeddings_section'
        );
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle test connection
        if (isset($_POST['wcp_test_connection']) && check_admin_referer('wcp_test_connection')) {
            $ai_client = WCP_AI_Client::instance();
            $result = $ai_client->test_connection();

            if (is_wp_error($result)) {
                echo '<div class="notice notice-error"><p><strong>Connection Failed:</strong> ' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p><strong>Connection Successful!</strong> ' . esc_html($result['message']) . '</p></div>';
            }
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <div class="wcp-settings-container">
                <div class="wcp-settings-main">
                    <form action="options.php" method="post">
                        <?php
                        settings_fields('wcp_settings');
                        do_settings_sections('work-copilot-settings');
                        submit_button(__('Save Settings', 'work-copilot'));
                        ?>
                    </form>

                    <?php if (get_option('wcp_anthropic_api_key')) : ?>
                    <form method="post" style="margin-top: 20px;">
                        <?php wp_nonce_field('wcp_test_connection'); ?>
                        <input type="submit" name="wcp_test_connection" class="button button-secondary" value="<?php esc_attr_e('Test Connection', 'work-copilot'); ?>">
                    </form>
                    <?php endif; ?>
                </div>

                <div class="wcp-settings-sidebar">
                    <div class="wcp-settings-box">
                        <h3><?php _e('Getting Started', 'work-copilot'); ?></h3>
                        <ol>
                            <li><?php _e('Get an API key from', 'work-copilot'); ?> <a href="https://console.anthropic.com/" target="_blank">Anthropic Console</a></li>
                            <li><?php _e('Enter your API key in the field', 'work-copilot'); ?></li>
                            <li><?php _e('Enable AI features', 'work-copilot'); ?></li>
                            <li><?php _e('Click "Test Connection" to verify', 'work-copilot'); ?></li>
                            <li><?php _e('Start using AI features in your posts and pages!', 'work-copilot'); ?></li>
                        </ol>
                    </div>

                    <div class="wcp-settings-box">
                        <h3><?php _e('Available AI Features', 'work-copilot'); ?></h3>
                        <ul>
                            <li><strong><?php _e('AI-Assisted Tagging', 'work-copilot'); ?></strong><br>
                                <?php _e('Suggests item types, priorities, and tags', 'work-copilot'); ?></li>
                            <li><strong><?php _e('Page-Scoped Chat', 'work-copilot'); ?></strong><br>
                                <?php _e('Ask questions about your page content', 'work-copilot'); ?></li>
                            <li><strong><?php _e('Coaching Prompts', 'work-copilot'); ?></strong><br>
                                <?php _e('Generate insights and recommendations', 'work-copilot'); ?></li>
                        </ul>
                    </div>

                    <div class="wcp-settings-box wcp-settings-warning">
                        <h3><?php _e('Privacy & Control', 'work-copilot'); ?></h3>
                        <p><?php _e('All AI features follow these principles:', 'work-copilot'); ?></p>
                        <ul>
                            <li><?php _e('AI never writes to database automatically', 'work-copilot'); ?></li>
                            <li><?php _e('You must explicitly accept all AI suggestions', 'work-copilot'); ?></li>
                            <li><?php _e('All AI actions are logged for transparency', 'work-copilot'); ?></li>
                            <li><?php _e('Your content is sent to Anthropic\'s API', 'work-copilot'); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <style>
        .wcp-settings-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-top: 20px;
        }

        .wcp-settings-main {
            background: #fff;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .wcp-settings-sidebar {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .wcp-settings-box {
            background: #fff;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .wcp-settings-box h3 {
            margin-top: 0;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }

        .wcp-settings-box ul,
        .wcp-settings-box ol {
            margin: 10px 0;
            padding-left: 20px;
        }

        .wcp-settings-box li {
            margin: 8px 0;
        }

        .wcp-settings-warning {
            border-left: 4px solid #f39c12;
        }

        @media (max-width: 1024px) {
            .wcp-settings-container {
                grid-template-columns: 1fr;
            }
        }
        </style>
        <?php
    }

    public function render_ai_section() {
        echo '<p>' . __('Configure AI features powered by Claude (Anthropic).', 'work-copilot') . '</p>';
    }

    public function render_ai_enabled_field() {
        $enabled = get_option('wcp_ai_enabled', false);
        ?>
        <label>
            <input type="checkbox" name="wcp_ai_enabled" value="1" <?php checked($enabled, true); ?>>
            <?php _e('Enable AI-powered features (tagging, chat, coaching)', 'work-copilot'); ?>
        </label>
        <p class="description">
            <?php _e('You must have a valid API key to use AI features.', 'work-copilot'); ?>
        </p>
        <?php
    }

    public function render_api_key_field() {
        $api_key = get_option('wcp_anthropic_api_key', '');
        ?>
        <input type="password" name="wcp_anthropic_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" placeholder="sk-ant-...">
        <p class="description">
            <?php _e('Get your API key from', 'work-copilot'); ?> <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a>
        </p>
        <?php
    }

    public function render_model_field() {
        $model = get_option('wcp_ai_model', 'claude-3-5-sonnet-20241022');
        $models = array(
            'claude-3-5-sonnet-20241022' => 'Claude 3.5 Sonnet (Recommended)',
            'claude-3-opus-20240229' => 'Claude 3 Opus (Most Capable)',
            'claude-3-sonnet-20240229' => 'Claude 3 Sonnet',
            'claude-3-haiku-20240307' => 'Claude 3 Haiku (Fastest)',
        );
        ?>
        <select name="wcp_ai_model" class="regular-text">
            <?php foreach ($models as $value => $label) : ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($model, $value); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php _e('Choose the Claude model to use. Sonnet offers the best balance of quality and speed.', 'work-copilot'); ?>
        </p>
        <?php
    }

    public function render_embeddings_section() {
        ?>
        <p><?php _e('Configure semantic search and RAG (Retrieval-Augmented Generation) using OpenAI embeddings.', 'work-copilot'); ?></p>
        <p><?php _e('This enables intelligent search across your notes based on meaning, not just keywords.', 'work-copilot'); ?></p>
        <?php

        // Show stats if embeddings are enabled
        if (get_option('wcp_embeddings_enabled', false)) {
            $manager = WCP_Embeddings_Manager::instance();
            $stats = $manager->get_stats();
            ?>
            <div class="notice notice-info inline">
                <p>
                    <strong><?php _e('Embedding Stats:', 'work-copilot'); ?></strong><br>
                    <?php printf(__('%d of %d posts have embeddings (%s%% coverage)', 'work-copilot'),
                        $stats['total_embeddings'],
                        $stats['total_posts'],
                        $stats['coverage_percentage']
                    ); ?>
                </p>
                <?php if (!empty($stats['posts_without_embeddings'])): ?>
                <p>
                    <a href="#" id="wcp-batch-generate" class="button">
                        <?php _e('Generate Missing Embeddings', 'work-copilot'); ?>
                    </a>
                    <span id="wcp-batch-status"></span>
                </p>
                <?php endif; ?>
            </div>
            <?php
        }
    }

    public function render_embeddings_enabled_field() {
        $enabled = get_option('wcp_embeddings_enabled', false);
        ?>
        <label>
            <input type="checkbox" name="wcp_embeddings_enabled" value="1" <?php checked($enabled, true); ?>>
            <?php _e('Enable semantic search and RAG features', 'work-copilot'); ?>
        </label>
        <p class="description">
            <?php _e('Automatically generates embeddings for posts when they are saved. Requires OpenAI API key.', 'work-copilot'); ?>
        </p>
        <?php
    }

    public function render_openai_api_key_field() {
        $api_key = get_option('wcp_openai_api_key', '');
        ?>
        <input type="password" name="wcp_openai_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" placeholder="sk-...">
        <p class="description">
            <?php _e('Get your API key from', 'work-copilot'); ?> <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a><br>
            <?php _e('Used for generating embeddings (text-embedding-3-small model). Very affordable (~$0.02 per 1M tokens).', 'work-copilot'); ?>
        </p>
        <?php
    }
}
