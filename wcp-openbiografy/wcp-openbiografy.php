<?php
/**
 * Plugin Name: Work Copilot OpenBiografy
 * Description: Document-native biography engine. Ingests URLs and documents about a person, extracts atomic facts as proposals, reconciles them into a life timeline, and drafts narrative chapters — all human-in-the-loop. Companion to Work Copilot.
 * Version: 0.1.0
 * License: GPL v2 or later
 * Text Domain: wcp-openbiografy
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WCPO_VERSION', '0.1.0');
define('WCPO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCPO_PLUGIN_URL', plugin_dir_url(__FILE__));

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

/** All tunables live in one option array. */
function wcpo_default_settings() {
    return array(
        // Batch size for the user-triggered "process next N" loops.
        'batch_size'             => 5,
        // Models must be in WCP_AI_Client's allowlist (haiku/sonnet/opus).
        'model'                  => 'claude-sonnet-4-6',          // extraction / reconciliation
        'model_draft'            => 'claude-sonnet-4-6',          // narrative drafting
        // Bounded context pack (PRD: AI calls receive bounded context).
        'max_context_chars'      => 60000,
        // Fetched-text snapshot cap stored on the source post.
        'max_snapshot_chars'     => 200000,
        'fetch_timeout'          => 30,
        'max_pdf_mb'             => 20,
        // Visual flag threshold only — low-confidence facts are NEVER dropped.
        'min_confidence_display' => 0.6,
        // Facts per reconciliation call (chunked by date proximity).
        'consolidate_chunk'      => 60,
    );
}

/** Get one setting with default fallback. */
function wcpo_get_setting($key, $fallback = null) {
    $settings = get_option('wcpo_settings', array());
    $defaults = wcpo_default_settings();
    if (array_key_exists($key, $settings)) {
        return $settings[$key];
    }
    if (array_key_exists($key, $defaults)) {
        return $defaults[$key];
    }
    return $fallback;
}

/**
 * Work Copilot core provides the AI client, the API key and the audit log
 * table. OpenBiografy extends it rather than duplicating that stack.
 */
function wcpo_copilot_active() {
    return class_exists('WCP_AI_Client') && class_exists('WCP_AI_Logger');
}

// ---------------------------------------------------------------------------
// Includes
// ---------------------------------------------------------------------------

require_once WCPO_PLUGIN_DIR . 'includes/class-edtf.php';
require_once WCPO_PLUGIN_DIR . 'includes/class-llm.php';
require_once WCPO_PLUGIN_DIR . 'includes/class-person-repo.php';
require_once WCPO_PLUGIN_DIR . 'includes/class-source-repo.php';
require_once WCPO_PLUGIN_DIR . 'includes/class-fact-repo.php';
require_once WCPO_PLUGIN_DIR . 'includes/class-event-repo.php';
require_once WCPO_PLUGIN_DIR . 'includes/class-chapter-repo.php';
require_once WCPO_PLUGIN_DIR . 'includes/class-fetcher.php';
require_once WCPO_PLUGIN_DIR . 'includes/class-extractor.php';
require_once WCPO_PLUGIN_DIR . 'includes/class-reconciler.php';
require_once WCPO_PLUGIN_DIR . 'includes/class-chapter-ai.php';
require_once WCPO_PLUGIN_DIR . 'includes/class-exporter.php';
require_once WCPO_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once WCPO_PLUGIN_DIR . 'includes/class-frontend.php';

if (is_admin()) {
    require_once WCPO_PLUGIN_DIR . 'admin/class-dashboard.php';
    require_once WCPO_PLUGIN_DIR . 'admin/class-settings.php';
}

// ---------------------------------------------------------------------------
// Registration: CPTs, statuses, taxonomy
// ---------------------------------------------------------------------------

function wcpo_register_types() {
    // The person is the only public-facing type: it gets a real page at
    // /people/{slug}/ rendered by the plugin template. Native UI stays on so
    // the portrait (featured image) and bio summary use the normal editor.
    register_post_type('wcpo_person', array(
        'labels' => array(
            'name'          => __('People', 'wcp-openbiografy'),
            'singular_name' => __('Person', 'wcp-openbiografy'),
        ),
        'public'       => true,
        'show_ui'      => true,
        'show_in_menu' => false, // reached via the OpenBiografy dashboard
        'show_in_rest' => false,
        'has_archive'  => false,
        'rewrite'      => array('slug' => 'people'),
        'supports'     => array('title', 'editor', 'thumbnail'),
        'menu_icon'    => 'dashicons-book-alt',
    ));

    // Sources / facts / events / chapters are managed exclusively through the
    // OpenBiografy dashboard, never native list tables.
    $private_args = array(
        'public'          => false,
        'show_ui'         => false,
        'show_in_rest'    => false,
        'capability_type' => 'post',
    );

    // Revisions deliberately OFF for sources: post_content holds the fetched
    // text snapshot (up to max_snapshot_chars) and revisions would bloat wp_posts.
    register_post_type('wcpo_source', array_merge($private_args, array(
        'labels'   => array('name' => __('Bio Sources', 'wcp-openbiografy'), 'singular_name' => __('Bio Source', 'wcp-openbiografy')),
        'supports' => array('title', 'editor', 'custom-fields'),
    )));
    register_post_type('wcpo_fact', array_merge($private_args, array(
        'labels'   => array('name' => __('Bio Facts', 'wcp-openbiografy'), 'singular_name' => __('Bio Fact', 'wcp-openbiografy')),
        'supports' => array('title', 'editor', 'custom-fields'),
    )));
    register_post_type('wcpo_event', array_merge($private_args, array(
        'labels'   => array('name' => __('Timeline Events', 'wcp-openbiografy'), 'singular_name' => __('Timeline Event', 'wcp-openbiografy')),
        'supports' => array('title', 'editor', 'custom-fields'),
    )));
    register_post_type('wcpo_chapter', array_merge($private_args, array(
        'labels'   => array('name' => __('Chapters', 'wcp-openbiografy'), 'singular_name' => __('Chapter', 'wcp-openbiografy')),
        'supports' => array('title', 'editor', 'custom-fields', 'page-attributes'),
    )));

    // Review state for facts and events is a custom post status — the single
    // source of truth (no duplicate review meta). GUARDRAIL: AI-created facts
    // and events are ALWAYS born wcpo_proposed; only a human REST decision
    // moves them to accepted/dismissed.
    $statuses = array(
        'wcpo_proposed'  => __('Proposed', 'wcp-openbiografy'),
        'wcpo_accepted'  => __('Accepted', 'wcp-openbiografy'),
        'wcpo_dismissed' => __('Dismissed', 'wcp-openbiografy'),
    );
    foreach ($statuses as $status => $label) {
        register_post_status($status, array(
            'label'                     => $label,
            'public'                    => false,
            'internal'                  => true,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => false,
            'show_in_admin_status_list' => false,
        ));
    }

    // Coarse event/fact kinds (PRD §3): deliberately ~12 terms, extensible later.
    register_taxonomy('wcpo_kind', array('wcpo_fact', 'wcpo_event'), array(
        'public'       => false,
        'show_ui'      => false,
        'hierarchical' => false,
        'show_in_rest' => false,
    ));
}
add_action('init', 'wcpo_register_types');

/** The seeded coarse kinds. Extraction output is validated against this list. */
function wcpo_kinds() {
    return array(
        'birth', 'death', 'education', 'move', 'employment', 'publication',
        'relationship', 'marriage', 'award', 'conflict', 'health', 'other',
    );
}

/** Allowed source classification vocabularies (PRD §9). */
function wcpo_doc_kinds() {
    return array(
        'letter', 'article', 'interview', 'official_record', 'book_excerpt',
        'obituary', 'speech', 'diary_entry', 'photograph', 'memoir', 'other', 'unknown',
    );
}

function wcpo_source_tiers() {
    return array(
        'definite_primary', 'probable_primary', 'contemporary_secondary',
        'later_secondary', 'tertiary', 'unknown',
    );
}

// ---------------------------------------------------------------------------
// Activation / deactivation
// ---------------------------------------------------------------------------

function wcpo_activate() {
    wcpo_register_types();
    foreach (wcpo_kinds() as $kind) {
        if (!term_exists($kind, 'wcpo_kind')) {
            wp_insert_term($kind, 'wcpo_kind');
        }
    }
    flush_rewrite_rules();
    update_option('wcpo_activated_at', current_time('mysql'), false);
}
register_activation_hook(__FILE__, 'wcpo_activate');

function wcpo_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'wcpo_deactivate');

// ---------------------------------------------------------------------------
// Work Copilot dependency notice — the plugin degrades gracefully (non-AI
// features keep working; AI endpoints return WP_Error('copilot_missing')).
// ---------------------------------------------------------------------------

function wcpo_copilot_notice() {
    if (!current_user_can('manage_options') || wcpo_copilot_active()) {
        return;
    }
    if (!isset($_GET['page']) || strpos((string) $_GET['page'], 'wcpo-') !== 0) {
        return;
    }
    echo '<div class="notice notice-warning"><p><strong>OpenBiografy:</strong> '
        . esc_html__('The Work Copilot plugin is not active. AI features (extraction, reconciliation, drafting) are disabled until it is installed and its Anthropic API key is configured.', 'wcp-openbiografy')
        . '</p></div>';
}
add_action('admin_notices', 'wcpo_copilot_notice');

// ---------------------------------------------------------------------------
// Boot
// ---------------------------------------------------------------------------

function wcpo_boot() {
    WCPO_REST_API::instance();
    WCPO_Frontend::instance();
    if (is_admin()) {
        WCPO_Dashboard::instance();
        WCPO_Settings::instance();
    }
}
add_action('plugins_loaded', 'wcpo_boot');
