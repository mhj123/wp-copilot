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

/**
 * Reverse-proxy gate shim.
 *
 * The site sits behind a Caddy HTTP Basic Auth gate (default user "michael").
 * Browsers cache those gate credentials and resend them on every request,
 * including admin pages and REST calls. WordPress then sees Basic Auth in
 * $_SERVER and (a) warns that Application Passwords are unavailable, hiding
 * the create form, and (b) may try the gate credentials as an Application
 * Password on REST requests, pre-empting cookie/nonce auth.
 *
 * Strip the gate's credentials before WordPress reads them, so it never sees
 * them. Real Application Password clients (the Hermes agent) authenticate as
 * a DIFFERENT user, so they are left untouched — which is exactly why the
 * agent must not reuse the gate's username. Override the username to match
 * your Caddyfile via define('WCPD_GATE_AUTH_USER', '...') in wp-config.php.
 *
 * Runs at plugin load (before determine_current_user) so it covers the same
 * request's auth, including REST.
 */
$wcpd_gate_user = defined('WCPD_GATE_AUTH_USER') ? WCPD_GATE_AUTH_USER : 'michael';
if (isset($_SERVER['PHP_AUTH_USER']) && $_SERVER['PHP_AUTH_USER'] === $wcpd_gate_user) {
    unset(
        $_SERVER['PHP_AUTH_USER'],
        $_SERVER['PHP_AUTH_PW'],
        $_SERVER['HTTP_AUTHORIZATION'],
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    );
}
unset($wcpd_gate_user);

define('WCPD_VERSION', '0.1.1');
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
