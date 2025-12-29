<?php
/**
 * Plugin Name: Work Copilot
 * Plugin URI: https://wordpress.org/plugins/work-copilot
 * Description: Personal knowledge and work management system with AI-assisted sensemaking
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yoursite.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: work-copilot
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WCP_VERSION', '1.0.0');
define('WCP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WCP_PLUGIN_FILE', __FILE__);

class Work_Copilot {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }

    private function includes() {
        require_once WCP_PLUGIN_DIR . 'includes/class-post-types.php';
        require_once WCP_PLUGIN_DIR . 'includes/class-taxonomies.php';
        require_once WCP_PLUGIN_DIR . 'includes/class-taxonomy-sync.php';
        require_once WCP_PLUGIN_DIR . 'includes/class-rest-api.php';
        require_once WCP_PLUGIN_DIR . 'includes/class-ai-logger.php';
        require_once WCP_PLUGIN_DIR . 'includes/class-ai-client.php';
        require_once WCP_PLUGIN_DIR . 'admin/class-admin.php';
        require_once WCP_PLUGIN_DIR . 'admin/class-settings.php';
        require_once WCP_PLUGIN_DIR . 'public/class-public.php';
    }

    private function init_hooks() {
        register_activation_hook(WCP_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(WCP_PLUGIN_FILE, array($this, 'deactivate'));

        add_action('init', array($this, 'init'), 0);
        add_action('plugins_loaded', array($this, 'load_textdomain'));
    }

    public function init() {
        WCP_Post_Types::instance();
        WCP_Taxonomies::instance();
        WCP_Taxonomy_Sync::instance();
        WCP_REST_API::instance();

        if (is_admin()) {
            WCP_Admin::instance();
            WCP_Settings::instance();
        } else {
            WCP_Public::instance();
        }
    }

    public function activate() {
        require_once WCP_PLUGIN_DIR . 'includes/class-post-types.php';
        require_once WCP_PLUGIN_DIR . 'includes/class-taxonomies.php';

        WCP_Post_Types::instance();
        WCP_Taxonomies::instance();

        flush_rewrite_rules();

        $this->create_tables();
    }

    public function deactivate() {
        flush_rewrite_rules();
    }

    private function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // AI action log table
        $table_name = $wpdb->prefix . 'wcp_ai_actions';

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            action_id varchar(64) NOT NULL,
            action_type varchar(50) NOT NULL,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            model varchar(100) DEFAULT NULL,
            prompt longtext DEFAULT NULL,
            input_context longtext DEFAULT NULL,
            output_snapshot longtext DEFAULT NULL,
            accepted_items longtext DEFAULT NULL,
            dismissed_items longtext DEFAULT NULL,
            context_post_id bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY action_id (action_id),
            KEY action_type (action_type),
            KEY user_id (user_id),
            KEY context_post_id (context_post_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function load_textdomain() {
        load_plugin_textdomain('work-copilot', false, dirname(plugin_basename(WCP_PLUGIN_FILE)) . '/languages');
    }
}

function WCP() {
    return Work_Copilot::instance();
}

WCP();
