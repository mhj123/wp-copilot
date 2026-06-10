<?php
/**
 * Delegation Settings
 *
 * Appends a "Delegation (Hermes Agent)" section onto the existing Work
 * Copilot settings screen (page slug 'work-copilot-settings', settings
 * group 'wcp_settings') so all configuration lives in one place.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPD_Settings {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_wcpd_test_telegram', array($this, 'handle_test_telegram'));
        add_action('admin_notices', array($this, 'maybe_show_test_notice'));
    }

    public function register_settings() {
        register_setting('wcp_settings', 'wcpd_enabled', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ));

        register_setting('wcp_settings', 'wcpd_telegram_bot_token', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ));

        register_setting('wcp_settings', 'wcpd_telegram_chat_id', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ));

        add_settings_section(
            'wcpd_section',
            __('Delegation (Hermes Agent)', 'wcp-delegation'),
            array($this, 'render_section'),
            'work-copilot-settings'
        );

        add_settings_field(
            'wcpd_enabled',
            __('Enable Delegation', 'wcp-delegation'),
            array($this, 'render_enabled_field'),
            'work-copilot-settings',
            'wcpd_section'
        );

        add_settings_field(
            'wcpd_telegram_bot_token',
            __('Telegram Bot Token', 'wcp-delegation'),
            array($this, 'render_bot_token_field'),
            'work-copilot-settings',
            'wcpd_section'
        );

        add_settings_field(
            'wcpd_telegram_chat_id',
            __('Telegram Chat ID', 'wcp-delegation'),
            array($this, 'render_chat_id_field'),
            'work-copilot-settings',
            'wcpd_section'
        );
    }

    public function render_section() {
        ?>
        <p>
            <?php _e('Delegate items to an external Hermes agent. The agent is notified via Telegram, reads the work packet over REST, and uploads artifacts back to the item for your review.', 'wcp-delegation'); ?>
        </p>
        <p class="description">
            <?php _e('Agent access: create a dedicated WordPress user with the <strong>Author</strong> role, then generate an Application Password on its profile (Users &rarr; Profile &rarr; Application Passwords). The agent authenticates with HTTP Basic auth over HTTPS. See <code>wcp-delegation/HERMES-INTEGRATION.md</code> for the full agent guide.', 'wcp-delegation'); ?>
        </p>
        <?php if (get_option('wcpd_telegram_bot_token') && get_option('wcpd_telegram_chat_id')) : ?>
        <p>
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wcpd_test_telegram'), 'wcpd_test_telegram')); ?>" class="button button-secondary">
                <?php _e('Send Test Telegram Message', 'wcp-delegation'); ?>
            </a>
        </p>
        <?php endif; ?>
        <?php
    }

    public function render_enabled_field() {
        $enabled = get_option('wcpd_enabled', '');
        ?>
        <label>
            <input type="checkbox" name="wcpd_enabled" value="1" <?php checked($enabled, '1'); ?>>
            <?php _e('Enable the Delegate action and agent REST endpoints', 'wcp-delegation'); ?>
        </label>
        <p class="description"><?php _e('When disabled, all delegation endpoints return 403 — a kill-switch for the agent surface.', 'wcp-delegation'); ?></p>
        <?php
    }

    public function render_bot_token_field() {
        $token = get_option('wcpd_telegram_bot_token', '');
        ?>
        <input type="password" name="wcpd_telegram_bot_token" value="<?php echo esc_attr($token); ?>" class="regular-text" autocomplete="off">
        <p class="description"><?php _e('From @BotFather on Telegram.', 'wcp-delegation'); ?></p>
        <?php
    }

    public function render_chat_id_field() {
        $chat_id = get_option('wcpd_telegram_chat_id', '');
        ?>
        <input type="text" name="wcpd_telegram_chat_id" value="<?php echo esc_attr($chat_id); ?>" class="regular-text">
        <p class="description"><?php _e('Chat or group ID the bot posts to (the chat your Hermes agent monitors).', 'wcp-delegation'); ?></p>
        <?php
    }

    public function handle_test_telegram() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wcp-delegation'));
        }
        check_admin_referer('wcpd_test_telegram');

        $result = WCPD_Delegation_Manager::instance()->send_test_message();
        $status = is_wp_error($result) ? 'error' : 'ok';
        $msg    = is_wp_error($result) ? $result->get_error_message() : '';

        wp_safe_redirect(add_query_arg(
            array(
                'page'              => 'work-copilot-settings',
                'wcpd_test'         => $status,
                'wcpd_test_message' => rawurlencode($msg),
            ),
            admin_url('admin.php')
        ));
        exit;
    }

    public function maybe_show_test_notice() {
        if (!isset($_GET['wcpd_test'])) {
            return;
        }
        if ($_GET['wcpd_test'] === 'ok') {
            echo '<div class="notice notice-success"><p><strong>' . esc_html__('Telegram test message sent.', 'wcp-delegation') . '</strong></p></div>';
        } else {
            $msg = isset($_GET['wcpd_test_message']) ? rawurldecode(wp_unslash($_GET['wcpd_test_message'])) : '';
            echo '<div class="notice notice-error"><p><strong>' . esc_html__('Telegram test failed:', 'wcp-delegation') . '</strong> ' . esc_html($msg) . '</p></div>';
        }
    }
}
