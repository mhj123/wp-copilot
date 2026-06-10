<?php
/**
 * Plugin Name: Work Copilot Graph
 * Description: Context graph for Work Copilot — entities are your existing pages, posts and headings; edges are labelled subject–predicate–object triples stored in one custom table. Adds a Connections panel to every entity. Add-on for Work Copilot.
 * Version: 0.1.0
 * License: GPL v2 or later
 * Text Domain: wcp-graph
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WCPG_VERSION', '0.1.0');
define('WCPG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCPG_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WCPG_PLUGIN_DIR . 'includes/class-graph-repository.php';
require_once WCPG_PLUGIN_DIR . 'includes/class-predicates.php';
require_once WCPG_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once WCPG_PLUGIN_DIR . 'includes/class-connections-panel.php';

register_activation_hook(__FILE__, array('WCPG_Graph_Repository', 'install'));

function wcpg_boot() {
    WCPG_Graph_Repository::instance();
    WCPG_Predicates::instance();
    WCPG_REST_API::instance();
    WCPG_Connections_Panel::instance();
}
add_action('plugins_loaded', 'wcpg_boot', 20);

/**
 * dbDelta runs only on activation; if the plugin files are updated in place
 * (git pull) the activation hook never fires, so re-run install on version bump.
 */
function wcpg_maybe_upgrade() {
    if (get_option('wcpg_db_version') !== WCPG_VERSION) {
        WCPG_Graph_Repository::install();
    }
}
add_action('admin_init', 'wcpg_maybe_upgrade');

/**
 * Theme integration point: render the Connections panel for a post.
 * Guard call sites with function_exists() so the theme works without the plugin.
 */
function wcpg_connections_panel($post_id) {
    WCPG_Connections_Panel::instance()->render((int) $post_id);
}
