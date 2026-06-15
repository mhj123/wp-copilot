<?php
/**
 * Plugin Name: Work Copilot
 * Plugin URI: https://wordpress.org/plugins/work-copilot
 * Description: Personal knowledge and work management system with AI-assisted sensemaking
 * Version: 1.1.0
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

// Debug: Log that plugin is loading
file_put_contents(
    __DIR__ . '/plugin-load-log.txt',
    date('Y-m-d H:i:s') . " - Plugin loading v1.2.1\n",
    FILE_APPEND
);

define('WCP_VERSION', '1.2.2');
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
        require_once WCP_PLUGIN_DIR . 'includes/class-embeddings-client.php';
        require_once WCP_PLUGIN_DIR . 'includes/class-embeddings-manager.php';
        require_once WCP_PLUGIN_DIR . 'includes/class-conversations-manager.php';
        require_once WCP_PLUGIN_DIR . 'includes/class-context-builder.php';
        require_once WCP_PLUGIN_DIR . 'includes/class-mission-loader.php';
        require_once WCP_PLUGIN_DIR . 'includes/class-memory-manager.php';
        require_once WCP_PLUGIN_DIR . 'includes/class-prompt-builder.php';
        require_once WCP_PLUGIN_DIR . 'includes/class-ai-actions.php';
        require_once WCP_PLUGIN_DIR . 'includes/class-raindrop-importer.php';
        require_once WCP_PLUGIN_DIR . 'includes/class-csv-exporter.php';
        require_once WCP_PLUGIN_DIR . 'admin/class-admin.php';
        require_once WCP_PLUGIN_DIR . 'admin/class-settings.php';
        require_once WCP_PLUGIN_DIR . 'admin/class-page-mission-metabox.php';
        require_once WCP_PLUGIN_DIR . 'admin/class-page-notes-metabox.php';
        require_once WCP_PLUGIN_DIR . 'admin/class-page-template-metabox.php';
        require_once WCP_PLUGIN_DIR . 'includes/class-page-template-manager.php';
        require_once WCP_PLUGIN_DIR . 'includes/class-page-scheduler.php';
        require_once WCP_PLUGIN_DIR . 'includes/class-calendar-importer.php';
        require_once WCP_PLUGIN_DIR . 'public/class-public.php';
    }

    private function init_hooks() {
        register_activation_hook(WCP_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(WCP_PLUGIN_FILE, array($this, 'deactivate'));

        add_action('init', array($this, 'init'), 0);
        add_action('plugins_loaded', array($this, 'load_textdomain'));

        // Raindrop cron hook
        add_action('wcp_raindrop_import', array('WCP_Raindrop_Importer', 'run_static'));

        // Page scheduler: ensure the 15-min check event is always registered
        add_action('init', function() {
            WCP_Page_Scheduler::instance()->ensure_cron_scheduled();
        }, 5);

        // Self-healing: reschedule if the event is missing or overdue by more than 1 hour
        add_action('init', function() {
            $freq = get_option('wcp_raindrop_import_frequency', 'daily');
            if ($freq === 'disabled' || empty(get_option('wcp_raindrop_api_key', ''))) {
                return;
            }
            $next = wp_next_scheduled('wcp_raindrop_import');
            $overdue = $next && $next < (time() - HOUR_IN_SECONDS);
            if (!$next || $overdue) {
                wp_clear_scheduled_hook('wcp_raindrop_import');
                wp_schedule_event(time(), $freq, 'wcp_raindrop_import');
            }
        });
    }

    public function init() {
        WCP_Post_Types::instance();
        WCP_Taxonomies::instance();
        WCP_Taxonomy_Sync::instance();
        WCP_REST_API::instance();
        WCP_Embeddings_Manager::instance();
        WCP_Page_Template_Manager::instance();
        WCP_Page_Scheduler::instance();

        if (is_admin()) {
            WCP_Admin::instance();
            WCP_Settings::instance();
            WCP_Page_Mission_Metabox::instance();
            WCP_Page_Notes_Metabox::instance();
            WCP_Page_Template_Metabox::instance();
        } else {
            WCP_Public::instance();
        }
    }

    public function activate() {
        require_once WCP_PLUGIN_DIR . 'includes/class-post-types.php';
        require_once WCP_PLUGIN_DIR . 'includes/class-taxonomies.php';
        require_once WCP_PLUGIN_DIR . 'includes/class-memory-manager.php';

        WCP_Post_Types::instance();
        WCP_Taxonomies::instance();

        flush_rewrite_rules();

        $this->create_tables();

        // Ensure Memories page exists
        WCP_Memory_Manager::instance()->ensure_memories_page();

        // Schedule Raindrop import cron
        wcp_schedule_raindrop_import();
    }

    public function deactivate() {
        flush_rewrite_rules();
        wp_clear_scheduled_hook('wcp_raindrop_import');
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

        // Embeddings table for RAG/vector search
        $embeddings_table = $wpdb->prefix . 'wcp_embeddings';

        $sql_embeddings = "CREATE TABLE IF NOT EXISTS $embeddings_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            post_type varchar(20) NOT NULL,
            embedding_text longtext NOT NULL,
            embedding_vector longtext NOT NULL,
            model varchar(100) DEFAULT 'text-embedding-3-small',
            dimensions int(11) DEFAULT 1536,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY post_id (post_id),
            KEY post_type (post_type),
            KEY updated_at (updated_at)
        ) $charset_collate;";

        dbDelta($sql_embeddings);

        // Conversations table for persistent AI chat per page
        $conversations_table = $wpdb->prefix . 'wcp_ai_conversations';

        $sql_conversations = "CREATE TABLE IF NOT EXISTS $conversations_table (
            conversation_id varchar(64) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            context_post_id bigint(20) unsigned NOT NULL,
            conversation_title varchar(255) DEFAULT NULL,
            started_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            last_activity_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
            status varchar(20) DEFAULT 'active',
            metadata longtext DEFAULT NULL,
            PRIMARY KEY  (conversation_id),
            KEY context_post_id_status (context_post_id, status),
            KEY user_id_activity (user_id, last_activity_at)
        ) $charset_collate;";

        dbDelta($sql_conversations);

        // Messages table for conversation history
        $messages_table = $wpdb->prefix . 'wcp_ai_messages';

        $sql_messages = "CREATE TABLE IF NOT EXISTS $messages_table (
            message_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            conversation_id varchar(64) NOT NULL,
            role varchar(20) NOT NULL,
            content longtext NOT NULL,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            metadata longtext DEFAULT NULL,
            PRIMARY KEY  (message_id),
            KEY conversation_id_timestamp (conversation_id, timestamp),
            KEY timestamp (timestamp)
        ) $charset_collate;";

        dbDelta($sql_messages);

        // Set default global AI instructions if not exists
        if (!get_option('wcp_ai_global_instructions')) {
            $default_instructions = "You are a work copilot helping a professional manage their knowledge and work. ";
            $default_instructions .= "Be clear, actionable, and concise. ";
            $default_instructions .= "When generating items, provide specific and practical suggestions. ";
            $default_instructions .= "Remember that all your suggestions require user approval before being saved.";

            add_option('wcp_ai_global_instructions', $default_instructions);
        }

        // Update database version
        update_option('wcp_db_version', '2.0.0');
    }

    public function load_textdomain() {
        load_plugin_textdomain('work-copilot', false, dirname(plugin_basename(WCP_PLUGIN_FILE)) . '/languages');
    }
}

function WCP() {
    return Work_Copilot::instance();
}

/**
 * Schedule (or reschedule) the Raindrop import cron event.
 * Called on plugin activation and whenever the frequency setting changes.
 */
function wcp_schedule_raindrop_import() {
    wp_clear_scheduled_hook('wcp_raindrop_import');
    $freq = get_option('wcp_raindrop_import_frequency', 'daily');
    if ($freq !== 'disabled' && !empty(get_option('wcp_raindrop_api_key', ''))) {
        wp_schedule_event(time(), $freq, 'wcp_raindrop_import');
    }
}

WCP();
