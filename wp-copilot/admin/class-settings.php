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
        add_action('wp_ajax_wcp_raindrop_import_now', array($this, 'ajax_raindrop_import_now'));
        add_action('wp_ajax_wcp_raindrop_refresh_collections', array($this, 'ajax_raindrop_refresh_collections'));
        add_action('wp_ajax_wcp_raindrop_reset_cursor', array($this, 'ajax_raindrop_reset_cursor'));
        add_action('update_option_wcp_raindrop_import_frequency', array($this, 'reschedule_raindrop_cron'), 10, 0);
        // Clear collection cache when API key changes
        add_action('update_option_wcp_raindrop_api_key', function() {
            delete_transient('wcp_raindrop_collections_cache');
        });
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

        register_setting('wcp_settings', 'wcp_ai_global_instructions', array(
            'type' => 'string',
            'sanitize_callback' => 'wp_kses_post',
            'default' => '',
        ));

        register_setting('wcp_settings', 'wcp_ai_global_mission', array(
            'type' => 'string',
            'sanitize_callback' => 'wp_kses_post',
            'default' => '',
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

        add_settings_field(
            'wcp_ai_global_instructions',
            __('Global AI Instructions', 'work-copilot'),
            array($this, 'render_global_instructions_field'),
            'work-copilot-settings',
            'wcp_ai_section'
        );

        add_settings_field(
            'wcp_ai_global_mission',
            __('Global Agent Mission', 'work-copilot'),
            array($this, 'render_global_mission_field'),
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

        // Raindrop settings
        register_setting('wcp_settings', 'wcp_raindrop_api_key', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ));

        register_setting('wcp_settings', 'wcp_raindrop_import_frequency', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'daily',
        ));

        register_setting('wcp_settings', 'wcp_raindrop_selected_collections', array(
            'type'              => 'array',
            'sanitize_callback' => array($this, 'sanitize_collection_ids'),
            'default'           => array(),
        ));

        add_settings_section(
            'wcp_raindrop_section',
            __('Raindrop.io Import', 'work-copilot'),
            array($this, 'render_raindrop_section'),
            'work-copilot-settings'
        );

        add_settings_field(
            'wcp_raindrop_api_key',
            __('Raindrop API Token', 'work-copilot'),
            array($this, 'render_raindrop_api_key_field'),
            'work-copilot-settings',
            'wcp_raindrop_section'
        );

        add_settings_field(
            'wcp_raindrop_import_frequency',
            __('Import Frequency', 'work-copilot'),
            array($this, 'render_raindrop_frequency_field'),
            'work-copilot-settings',
            'wcp_raindrop_section'
        );

        add_settings_field(
            'wcp_raindrop_selected_collections',
            __('Collections to Import', 'work-copilot'),
            array($this, 'render_raindrop_collections_field'),
            'work-copilot-settings',
            'wcp_raindrop_section'
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

                    <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
                        <h2><?php _e('Tools', 'work-copilot'); ?></h2>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><?php _e('Sync Context Taxonomy', 'work-copilot'); ?></th>
                                <td>
                                    <button type="button" id="wcp-sync-taxonomy" class="button button-secondary">
                                        <?php _e('Sync All Pages &amp; Headings', 'work-copilot'); ?>
                                    </button>
                                    <span id="wcp-sync-taxonomy-status" style="margin-left: 10px;"></span>
                                    <p class="description">
                                        <?php _e('Creates missing <code>wcp_context</code> taxonomy terms for any pages or headings that were added before the plugin was active, or whose sync failed. Safe to run at any time.', 'work-copilot'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
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
        $model = get_option('wcp_ai_model', 'claude-sonnet-4-6');
        $models = array(
            'claude-sonnet-4-6' => 'Claude Sonnet 4.6 (Recommended)',
            'claude-opus-4-7' => 'Claude Opus 4.7 (Most Capable)',
            'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5 (Fast)',
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

    public function render_global_instructions_field() {
        $default_instructions = "You are a work copilot helping a professional manage their knowledge and work. ";
        $default_instructions .= "Be clear, actionable, and concise. ";
        $default_instructions .= "When generating items, provide specific and practical suggestions. ";
        $default_instructions .= "Remember that all your suggestions require user approval before being saved.";

        $instructions = get_option('wcp_ai_global_instructions', $default_instructions);

        ?>
        <p class="description" style="margin-bottom: 10px;">
            <?php _e('Define global instructions that will be included in all AI requests. These form Layer 1 of the 3-layer prompt system (Global → Page Context → Action).', 'work-copilot'); ?>
        </p>
        <?php

        wp_editor(
            $instructions,
            'wcp_ai_global_instructions',
            array(
                'textarea_name' => 'wcp_ai_global_instructions',
                'textarea_rows' => 10,
                'media_buttons' => false,
                'teeny' => false,
                'quicktags' => true,
                'tinymce' => array(
                    'toolbar1' => 'bold,italic,underline,bullist,numlist,link,unlink',
                    'toolbar2' => '',
                ),
            )
        );

        ?>
        <p class="description" style="margin-top: 10px;">
            <?php _e('These instructions set the overall tone and behavior for all AI interactions in Work Copilot.', 'work-copilot'); ?>
        </p>
        <?php
    }

    public function render_global_mission_field() {
        $default_mission = "You are a helpful work copilot assisting {user} with {role}. ";
        $default_mission .= "Your mission is to help them achieve their goals by providing thoughtful advice, ";
        $default_mission .= "organizing knowledge, and identifying next steps.";

        $mission = get_option('wcp_ai_global_mission', $default_mission);
        $current_user = wp_get_current_user();
        $user_name = $current_user->display_name;

        ?>
        <p class="description" style="margin-bottom: 10px;">
            <?php _e('Define the personality and mission of your AI agent. This sets the agent\'s character and goals across all interactions.', 'work-copilot'); ?>
        </p>

        <textarea name="wcp_ai_global_mission" rows="6" class="large-text code"><?php echo esc_textarea($mission); ?></textarea>

        <p class="description" style="margin-top: 10px;">
            <strong><?php _e('Available variables:', 'work-copilot'); ?></strong><br>
            <code>{user}</code> - <?php printf(__('Current user name (e.g., %s)', 'work-copilot'), esc_html($user_name)); ?><br>
            <code>{role}</code> - <?php _e('User role from "Role" page if it exists', 'work-copilot'); ?>
        </p>

        <p class="description">
            <strong><?php _e('Advanced:', 'work-copilot'); ?></strong>
            <?php _e('You can also create a', 'work-copilot'); ?> <code>/wp-copilot/soul.md</code> <?php _e('file to override this setting. Useful for version control.', 'work-copilot'); ?>
            <?php
            $soul_file = WP_PLUGIN_DIR . '/wp-copilot/soul.md';
            if (file_exists($soul_file)) {
                echo '<br><span style="color: #46b450;">✓ ' . __('soul.md file detected - currently in use', 'work-copilot') . '</span>';
            } else {
                echo '<br><span style="color: #999;">○ ' . __('soul.md file not found', 'work-copilot') . '</span>';
            }
            ?>
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

    public function render_raindrop_section() {
        $last_import = get_option('wcp_raindrop_last_import', 0);
        echo '<p>' . __('Import bookmarks from Raindrop.io as WP posts. Each collection becomes a child page under a "Bookmarks" parent page. Tags are imported as WP post tags.', 'work-copilot') . '</p>';
        if ($last_import) {
            echo '<p>' . sprintf(__('Last import cursor: %s', 'work-copilot'), esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_import)));
            echo ' &mdash; <a href="#" id="wcp-raindrop-reset-cursor" style="color:#c00;">' . __('Reset cursor (re-import everything)', 'work-copilot') . '</a></p>';
            echo '<script>
            document.getElementById("wcp-raindrop-reset-cursor").addEventListener("click", function(e) {
                e.preventDefault();
                if (!confirm("' . esc_js(__('This will re-import all items on the next run. Continue?', 'work-copilot')) . '")) return;
                fetch(ajaxurl, {
                    method: "POST",
                    headers: {"Content-Type": "application/x-www-form-urlencoded"},
                    body: "action=wcp_raindrop_reset_cursor&_wpnonce=' . wp_create_nonce('wcp_raindrop_reset_cursor') . '"
                }).then(function() { location.reload(); });
            });
            </script>';
        }
    }

    public function render_raindrop_api_key_field() {
        $api_key = get_option('wcp_raindrop_api_key', '');
        ?>
        <input type="password" name="wcp_raindrop_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" placeholder="your-test-token">
        <p class="description">
            <?php _e('Create a test token at', 'work-copilot'); ?> <a href="https://app.raindrop.io/settings/integrations" target="_blank">app.raindrop.io/settings/integrations</a>
        </p>
        <?php
    }

    public function render_raindrop_frequency_field() {
        $frequency = get_option('wcp_raindrop_import_frequency', 'daily');
        $next      = wp_next_scheduled('wcp_raindrop_import');
        ?>
        <select name="wcp_raindrop_import_frequency">
            <option value="twicedaily" <?php selected($frequency, 'twicedaily'); ?>><?php _e('Twice Daily', 'work-copilot'); ?></option>
            <option value="daily"      <?php selected($frequency, 'daily'); ?>><?php _e('Once Daily', 'work-copilot'); ?></option>
            <option value="disabled"   <?php selected($frequency, 'disabled'); ?>><?php _e('Disabled (manual only)', 'work-copilot'); ?></option>
        </select>
        <?php if ($next) : ?>
            <p class="description"><?php printf(__('Next scheduled run: %s', 'work-copilot'), esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next))); ?></p>
        <?php endif; ?>

        <?php if (get_option('wcp_raindrop_api_key')) : ?>
        <p style="margin-top: 10px;">
            <button type="button" id="wcp-raindrop-import-now" class="button button-secondary">
                <?php _e('Import Now', 'work-copilot'); ?>
            </button>
            <input type="number" id="wcp-raindrop-import-limit" min="0" placeholder="<?php esc_attr_e('limit (optional)', 'work-copilot'); ?>" style="width:140px; margin-left:8px;">
            <span id="wcp-raindrop-import-status" style="margin-left: 10px;"></span>
        </p>
        <script>
        document.getElementById('wcp-raindrop-import-now').addEventListener('click', function() {
            var btn    = this;
            var status = document.getElementById('wcp-raindrop-import-status');
            var limit  = document.getElementById('wcp-raindrop-import-limit').value || 0;
            btn.disabled = true;
            status.textContent = '<?php echo esc_js(__('Importing...', 'work-copilot')); ?>';
            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=wcp_raindrop_import_now&limit=' + encodeURIComponent(limit) + '&_wpnonce=<?php echo wp_create_nonce('wcp_raindrop_import_now'); ?>'
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                btn.disabled = false;
                if (data.success) {
                    status.textContent = data.data.message;
                } else {
                    status.textContent = '<?php echo esc_js(__('Error: ', 'work-copilot')); ?>' + (data.data || '<?php echo esc_js(__('Unknown error', 'work-copilot')); ?>');
                }
            })
            .catch(function() {
                btn.disabled = false;
                status.textContent = '<?php echo esc_js(__('Request failed', 'work-copilot')); ?>';
            });
        });
        </script>
        <?php endif; ?>
        <?php
    }

    public function ajax_raindrop_import_now() {
        check_ajax_referer('wcp_raindrop_import_now');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $limit  = intval($_POST['limit'] ?? 0);
        $result = WCP_Raindrop_Importer::instance()->run($limit);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        $message = sprintf(
            __('Done. Imported: %d, Skipped (duplicates): %d, Errors: %d', 'work-copilot'),
            $result['imported'],
            $result['skipped'],
            $result['errors']
        );
        wp_send_json_success(array('message' => $message, 'stats' => $result));
    }

    public function ajax_raindrop_reset_cursor() {
        check_ajax_referer('wcp_raindrop_reset_cursor');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        update_option('wcp_raindrop_last_import', 0);
        wp_send_json_success();
    }

    public function ajax_raindrop_refresh_collections() {
        check_ajax_referer('wcp_raindrop_refresh_collections');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        delete_transient('wcp_raindrop_collections_cache');
        wp_send_json_success();
    }

    public function reschedule_raindrop_cron() {
        wcp_schedule_raindrop_import();
    }

    public function sanitize_collection_ids($value) {
        if (!is_array($value)) {
            return array();
        }
        return array_map('intval', $value);
    }

    public function render_raindrop_collections_field() {
        $api_key = get_option('wcp_raindrop_api_key', '');
        if (empty($api_key)) {
            echo '<p class="description">' . __('Save your API token first to load collections.', 'work-copilot') . '</p>';
            return;
        }

        // Cache collections for 24h to avoid hitting the API on every settings page load
        $collections = get_transient('wcp_raindrop_collections_cache');
        if ($collections === false) {
            $response = wp_remote_get('https://api.raindrop.io/rest/v1/collections', array(
                'headers' => array('Authorization' => 'Bearer ' . $api_key),
                'timeout' => 15,
            ));
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $collections = $body['items'] ?? array();
                set_transient('wcp_raindrop_collections_cache', $collections, DAY_IN_SECONDS);
            } else {
                echo '<p class="description" style="color:#c00;">' . __('Could not fetch collections — check your API token.', 'work-copilot') . '</p>';
                return;
            }
        }

        if (empty($collections)) {
            echo '<p class="description">' . __('No collections found in your Raindrop account.', 'work-copilot') . '</p>';
            return;
        }

        $selected = get_option('wcp_raindrop_selected_collections', array());
        ?>
        <p class="description" style="margin-bottom:8px;">
            <?php _e('Check the collections you want to import. Leave all unchecked to import everything.', 'work-copilot'); ?>
            <br><a href="#" id="wcp-rd-select-all" style="margin-right:8px;"><?php _e('Select all', 'work-copilot'); ?></a>
            <a href="#" id="wcp-rd-deselect-all"><?php _e('Deselect all', 'work-copilot'); ?></a>
        </p>
        <ul id="wcp-raindrop-collections" style="margin:0; column-count:2; column-gap:20px; max-width:500px;">
            <?php foreach ($collections as $collection) : ?>
            <li style="list-style:none; margin-bottom:4px;">
                <label>
                    <input type="checkbox"
                           name="wcp_raindrop_selected_collections[]"
                           value="<?php echo esc_attr($collection['_id']); ?>"
                           <?php checked(in_array((int) $collection['_id'], array_map('intval', $selected))); ?>>
                    <?php echo esc_html($collection['title']); ?>
                    <span style="color:#999; font-size:12px;">(<?php echo intval($collection['count']); ?>)</span>
                </label>
            </li>
            <?php endforeach; ?>
        </ul>
        <p style="margin-top:8px;">
            <a href="#" id="wcp-rd-refresh-collections" class="button button-small"><?php _e('↻ Refresh list', 'work-copilot'); ?></a>
        </p>
        <script>
        (function() {
            var list = document.getElementById('wcp-raindrop-collections');
            document.getElementById('wcp-rd-select-all').addEventListener('click', function(e) {
                e.preventDefault();
                list.querySelectorAll('input[type=checkbox]').forEach(function(cb) { cb.checked = true; });
            });
            document.getElementById('wcp-rd-deselect-all').addEventListener('click', function(e) {
                e.preventDefault();
                list.querySelectorAll('input[type=checkbox]').forEach(function(cb) { cb.checked = false; });
            });
            document.getElementById('wcp-rd-refresh-collections').addEventListener('click', function(e) {
                e.preventDefault();
                var btn = this;
                btn.textContent = '<?php echo esc_js(__('Refreshing...', 'work-copilot')); ?>';
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=wcp_raindrop_refresh_collections&_wpnonce=<?php echo wp_create_nonce('wcp_raindrop_refresh_collections'); ?>'
                }).then(function() { location.reload(); });
            });
        })();
        </script>
        <?php
    }
}
