<?php
/**
 * Uninstall handler — runs once, only when the plugin is deleted (not
 * deactivated) via the Plugins screen or `wp plugin uninstall`.
 *
 * Removes the plugin's own infrastructure: its four custom tables and its
 * settings/options. Deliberately does NOT touch user content — Pages,
 * Items (native Posts), or Heading posts (the wcp_heading CPT) are left
 * exactly as they are. Deleting a plugin should not delete someone's notes;
 * if Work Copilot is reinstalled later, that content and structure is
 * exactly where it was. Removing the CPT/taxonomy *registration* here would
 * be pointless anyway — the classes that register them aren't loaded during
 * uninstall, so there's nothing to unregister.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$tables = array(
    $wpdb->prefix . 'wcp_ai_actions',
    $wpdb->prefix . 'wcp_embeddings',
    $wpdb->prefix . 'wcp_ai_conversations',
    $wpdb->prefix . 'wcp_ai_messages',
);

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS `$table`");
}

$options = array(
    'wcp_ai_enabled',
    'wcp_ai_global_instructions',
    'wcp_ai_global_mission',
    'wcp_ai_model',
    'wcp_anthropic_api_key',
    'wcp_db_version',
    'wcp_default_terms_created',
    'wcp_embeddings_enabled',
    'wcp_openai_api_key',
    'wcp_raindrop_api_key',
    'wcp_raindrop_import_frequency',
    'wcp_raindrop_last_import',
    'wcp_raindrop_selected_collections',
    'wcp_saved_prompts',
    'wcp_spec_terms_created',
    'wcp_task_status_terms_created',
);

foreach ($options as $option) {
    delete_option($option);
}

wp_clear_scheduled_hook('wcp_raindrop_import');
wp_clear_scheduled_hook('wcp_scheduled_page_check');
wp_clear_scheduled_hook('wcp_ai_actions_retention'); // AI audit log purge, see class-ai-logger.php
