<?php
/**
 * Plugin Name: Work Copilot Delegation
 * Description: Delegate items to an external Hermes agent — Telegram notification, REST work packets, artifact uploads, and a clarification loop. Add-on for Work Copilot.
 * Version: 0.1.0
 * License: GPL v2 or later
 * Text Domain: wcp-delegation
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WCPD_VERSION', '0.1.0');
define('WCPD_PLUGIN_DIR', plugin_dir_path(__FILE__));

/**
 * Boot after all plugins load so we can detect the Work Copilot core classes.
 * Delegation is an add-on: without core context builders there is no packet to build.
 */
function wcpd_boot() {
    if (!class_exists('WCP_Context_Builder') || !class_exists('WCP_Mission_Loader')) {
        add_action('admin_notices', 'wcpd_missing_core_notice');
        return;
    }

    require_once WCPD_PLUGIN_DIR . 'includes/class-delegation-manager.php';
    require_once WCPD_PLUGIN_DIR . 'includes/class-rest-api.php';
    require_once WCPD_PLUGIN_DIR . 'admin/class-settings.php';

    WCPD_Delegation_Manager::instance();
    WCPD_REST_API::instance();

    if (is_admin()) {
        WCPD_Settings::instance();
    }
}
add_action('plugins_loaded', 'wcpd_boot', 20);

function wcpd_missing_core_notice() {
    echo '<div class="notice notice-warning"><p>';
    esc_html_e('Work Copilot Delegation requires the Work Copilot plugin to be active. Delegation features are disabled.', 'wcp-delegation');
    echo '</p></div>';
}
