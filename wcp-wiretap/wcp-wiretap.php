<?php
/**
 * Plugin Name: Work Copilot Wiretap
 * Description: Tracks trusted investment KOLs on X, detects new actionable calls, scores earliness, proposes conditional trade plans, and surfaces KOL discovery — all human-in-the-loop. Companion to Work Copilot.
 * Version: 2.0.0
 * License: GPL v2 or later
 * Text Domain: wcp-wiretap
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WCPW_VERSION', '2.0.0');
define('WCPW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCPW_PLUGIN_URL', plugin_dir_url(__FILE__));

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

/**
 * All tunables live in one option array; every key here is the documented
 * default from the PRD (§9, §10, §5, §F1, §F3, §F5, §F7).
 */
function wcpw_default_settings() {
    return array(
        // X API (§9)
        'x_bearer_token'         => '',
        'per_read_price_usd'     => 0.005,
        'monthly_read_cap_usd'   => 150,
        // Anthropic (§7) — empty key inherits Work Copilot core's key
        'anthropic_api_key'      => '',
        'model_fast'             => 'claude-haiku-4-5-20251001', // classification / extraction / fit
        'model_strong'           => 'claude-sonnet-4-6',         // digest / check-in
        // Telegram (notify-only, §12.4)
        'telegram_bot_token'     => '',
        'telegram_chat_id'       => '',
        'notify_new_calls'       => 1,
        'notify_plan_triggers'   => 1,
        'notify_emerging'        => 0,
        'notify_digest'          => 0,
        // Ingestion
        'include_retweets'       => 0,
        'fetch_lookback_hours'   => 24,
        'max_kols'               => 30,
        'tweet_retention_days'   => 90,
        'analyze_chunk_size'     => 10,
        // Signal thresholds (§F1)
        'new_call_window'        => 30,   // days
        'alert_confidence_threshold' => 0.7,
        'review_confidence_floor'    => 0.4,
        'trust_alert_min'        => 4,
        // Lexicon for the non-LLM pre-filter (editable in settings)
        'lexicon'                => array(
            'long', 'short', 'buy', 'sell', 'accumulate', 'accumulating', 'entry', 'exit',
            'target', 'bottom', 'top', 'breakout', 'support', 'resistance', 'bullish',
            'bearish', 'position', 'sized', 'bid', 'bidding', 'ape', 'aped', 'sold',
            'bought', 'longing', 'shorting', 'dca', 'take profit', 'stop loss', 'invalidation',
        ),
        // Earliness thresholds (§5) — keep names aligned with the band table
        'earliness'              => array(
            'min_mentions'   => 5,
            'd_low'          => 0.10,
            'd_mid'          => 0.40,
            'd_high'         => 0.70,
            'v_hot'          => 1.5,
            'v_cold'         => 0.7,
            'x_flat_lo'      => 0.85,
            'x_flat_hi'      => 1.15,
            'x_moved'        => 1.5,
            'x_extended'     => 3.0,
            'o_early'        => 0.5,
            'o_late'         => 0.2,
        ),
        // Emerging (§F5)
        'emerging_min_kols'      => 3,
        'emerging_velocity_mult' => 2,
        'emerging_max_age'       => 45,   // days
        // Discovery (§F3)
        'discovery_min_interactions' => 3,
        'earliest_search_max_results' => 500,
        // Trade plans (§F7)
        'plan_ttl_days'          => 30,
        // Prices
        'price_cache_minutes'    => 15,
        // Digest (§F4)
        'digest_hour'            => 7,
    );
}

/** Get one setting with default fallback. */
function wcpw_get_setting($key, $fallback = null) {
    $settings = get_option('wcpw_settings', array());
    $defaults = wcpw_default_settings();
    if (array_key_exists($key, $settings)) {
        return $settings[$key];
    }
    if (array_key_exists($key, $defaults)) {
        return $defaults[$key];
    }
    return $fallback;
}

/** Anthropic key: own setting, else inherit Work Copilot core's. */
function wcpw_anthropic_key() {
    $key = wcpw_get_setting('anthropic_api_key');
    if (!$key) {
        $key = get_option('wcp_anthropic_api_key', '');
    }
    return $key;
}

// ---------------------------------------------------------------------------
// Budget metering (§9) — month-keyed X read counter
// ---------------------------------------------------------------------------

function wcpw_reads_option_key() {
    return 'wcpw_reads_' . gmdate('Ym');
}

function wcpw_add_reads($n) {
    $key = wcpw_reads_option_key();
    $current = (int) get_option($key, 0);
    update_option($key, $current + max(0, (int) $n), false);
}

function wcpw_reads_this_month() {
    return (int) get_option(wcpw_reads_option_key(), 0);
}

function wcpw_spend_this_month_usd() {
    return wcpw_reads_this_month() * (float) wcpw_get_setting('per_read_price_usd');
}

/** True when the monthly X budget cap is exhausted — fetch must stop. */
function wcpw_budget_exhausted() {
    return wcpw_spend_this_month_usd() >= (float) wcpw_get_setting('monthly_read_cap_usd');
}

// ---------------------------------------------------------------------------
// Run rows (§6) — capped option ring buffer, shown on the Runs & Budget tab
// ---------------------------------------------------------------------------

function wcpw_record_run($job, array $data) {
    $runs = get_option('wcpw_runs', array());
    $runs[] = array_merge(array(
        'job'         => $job,
        'started_at'  => '',
        'finished_at' => current_time('mysql', true),
        'counts'      => array(),
        'errors'      => array(),
        'reads_used'  => 0,
        'tokens_used' => 0,
    ), $data);
    $runs = array_slice($runs, -100);
    update_option('wcpw_runs', $runs, false);
}

/**
 * Transient job lock — prevents overlapping cron runs (§6).
 * Returns false if another run holds the lock.
 */
function wcpw_acquire_lock($job, $ttl = 600) {
    $key = 'wcpw_lock_' . $job;
    if (get_transient($key)) {
        return false;
    }
    set_transient($key, time(), $ttl);
    return true;
}

function wcpw_release_lock($job) {
    delete_transient('wcpw_lock_' . $job);
}

// ---------------------------------------------------------------------------
// Includes
// ---------------------------------------------------------------------------

require_once WCPW_PLUGIN_DIR . 'includes/class-ai-log.php';
require_once WCPW_PLUGIN_DIR . 'includes/class-llm.php';
require_once WCPW_PLUGIN_DIR . 'includes/class-tweet-repo.php';
require_once WCPW_PLUGIN_DIR . 'includes/class-ticker-registry.php';
require_once WCPW_PLUGIN_DIR . 'includes/class-kols.php';
require_once WCPW_PLUGIN_DIR . 'includes/class-tweet-source.php';
require_once WCPW_PLUGIN_DIR . 'includes/class-prefilter.php';
require_once WCPW_PLUGIN_DIR . 'includes/class-ingest.php';
require_once WCPW_PLUGIN_DIR . 'includes/class-recommendation-repo.php';
require_once WCPW_PLUGIN_DIR . 'includes/class-earliness.php';
require_once WCPW_PLUGIN_DIR . 'includes/class-price-source.php';
require_once WCPW_PLUGIN_DIR . 'includes/class-analyzer.php';
require_once WCPW_PLUGIN_DIR . 'includes/class-trade-plan.php';
require_once WCPW_PLUGIN_DIR . 'includes/class-digest.php';
require_once WCPW_PLUGIN_DIR . 'includes/class-themes.php';
require_once WCPW_PLUGIN_DIR . 'includes/class-discovery.php';
require_once WCPW_PLUGIN_DIR . 'includes/class-checkin.php';
require_once WCPW_PLUGIN_DIR . 'includes/class-telegram.php';
require_once WCPW_PLUGIN_DIR . 'includes/class-rest-api.php';

if (is_admin()) {
    require_once WCPW_PLUGIN_DIR . 'admin/class-dashboard.php';
    require_once WCPW_PLUGIN_DIR . 'admin/class-settings.php';
}

// ---------------------------------------------------------------------------
// Registration: CPTs, statuses, taxonomies
// ---------------------------------------------------------------------------

function wcpw_register_types() {
    $cpt_args = array(
        'public'          => false,
        'show_ui'         => false,       // managed via the Wiretap dashboard, not native list tables
        'show_in_rest'    => false,
        'supports'        => array('title', 'editor', 'custom-fields'),
        'capability_type' => 'post',
    );

    register_post_type('wcp_kol', array_merge($cpt_args, array(
        'labels' => array('name' => __('KOLs', 'wcp-wiretap'), 'singular_name' => __('KOL', 'wcp-wiretap')),
    )));
    register_post_type('wcp_recommendation', array_merge($cpt_args, array(
        'labels' => array('name' => __('Recommendations', 'wcp-wiretap'), 'singular_name' => __('Recommendation', 'wcp-wiretap')),
    )));
    register_post_type('wcp_trade_plan', array_merge($cpt_args, array(
        'labels' => array('name' => __('Trade Plans', 'wcp-wiretap'), 'singular_name' => __('Trade Plan', 'wcp-wiretap')),
    )));

    // Review / lifecycle states are custom post statuses — the single source
    // of truth (no duplicate meta, §4.2).
    $statuses = array(
        // Recommendations
        'wcp_pending'     => __('Pending review', 'wcp-wiretap'),
        'wcp_accepted'    => __('Accepted', 'wcp-wiretap'),
        'wcp_dismissed'   => __('Dismissed', 'wcp-wiretap'),
        // Trade plans
        'wcp_proposed'    => __('Proposed', 'wcp-wiretap'),
        'wcp_armed'       => __('Armed', 'wcp-wiretap'),
        'wcp_triggered'   => __('Triggered', 'wcp-wiretap'),
        'wcp_invalidated' => __('Invalidated', 'wcp-wiretap'),
        'wcp_closed'      => __('Closed', 'wcp-wiretap'),
        'wcp_expired'     => __('Expired', 'wcp-wiretap'),
        'wcp_cancelled'   => __('Cancelled', 'wcp-wiretap'),
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

    $tax_args = array(
        'public'       => false,
        'show_ui'      => false,
        'hierarchical' => false,
        'show_in_rest' => false,
    );
    register_taxonomy('wcp_ticker', array('wcp_recommendation', 'wcp_trade_plan'), $tax_args);
    register_taxonomy('wcp_theme', array('wcp_recommendation'), $tax_args);
    register_taxonomy('wcp_asset_class', array('wcp_recommendation', 'wcp_trade_plan'), $tax_args);
}
add_action('init', 'wcpw_register_types');

// ---------------------------------------------------------------------------
// Cron (§6)
// ---------------------------------------------------------------------------

function wcpw_cron_schedules($schedules) {
    $schedules['wcpw_15min'] = array(
        'interval' => 15 * MINUTE_IN_SECONDS,
        'display'  => __('Every 15 minutes (Wiretap)', 'wcp-wiretap'),
    );
    return $schedules;
}
add_filter('cron_schedules', 'wcpw_cron_schedules');

function wcpw_schedule_events() {
    if (!wp_next_scheduled('wcp_wiretap_fetch')) {
        wp_schedule_event(time() + MINUTE_IN_SECONDS, 'twicedaily', 'wcp_wiretap_fetch');
    }
    if (!wp_next_scheduled('wcp_wiretap_price_watch')) {
        wp_schedule_event(time() + MINUTE_IN_SECONDS, 'wcpw_15min', 'wcp_wiretap_price_watch');
    }
    if (!wp_next_scheduled('wcp_wiretap_rollup')) {
        // Nightly at ~02:00 site time
        $next = strtotime('tomorrow 02:00', current_time('timestamp'));
        wp_schedule_event($next - (get_option('gmt_offset') * HOUR_IN_SECONDS), 'daily', 'wcp_wiretap_rollup');
    }
    if (!wp_next_scheduled('wcp_wiretap_digest')) {
        $hour = (int) wcpw_get_setting('digest_hour');
        $next = strtotime(sprintf('tomorrow %02d:00', $hour), current_time('timestamp'));
        wp_schedule_event($next - (get_option('gmt_offset') * HOUR_IN_SECONDS), 'daily', 'wcp_wiretap_digest');
    }
}

function wcpw_clear_events() {
    foreach (array('wcp_wiretap_fetch', 'wcp_wiretap_analyze', 'wcp_wiretap_rollup', 'wcp_wiretap_digest', 'wcp_wiretap_price_watch') as $hook) {
        wp_clear_scheduled_hook($hook);
    }
}

// GUARDRAIL (§12.2): cron handlers below only ingest and propose. No handler
// accepts, arms, publishes, or otherwise advances anything past a proposal
// state — those transitions exist solely in REST handlers behind a human.
add_action('wcp_wiretap_fetch',       array('WCPW_Ingest', 'run'));
add_action('wcp_wiretap_analyze',     array('WCPW_Analyzer', 'run_chunk'));
add_action('wcp_wiretap_rollup',      array('WCPW_Themes', 'run_rollup'));
add_action('wcp_wiretap_digest',      array('WCPW_Digest', 'run_scheduled'));
add_action('wcp_wiretap_price_watch', array('WCPW_Trade_Plan', 'run_price_watch'));

// ---------------------------------------------------------------------------
// Activation / deactivation
// ---------------------------------------------------------------------------

function wcpw_activate() {
    wcpw_register_types();
    WCPW_Tweet_Repo::install();
    WCPW_Themes::install();
    WCPW_Price_Source::install();
    WCPW_AI_Log::install();
    WCPW_Ticker_Registry::seed();
    wcpw_schedule_events();
    flush_rewrite_rules();
    update_option('wcpw_activated_at', current_time('mysql'), false);
}
register_activation_hook(__FILE__, 'wcpw_activate');

function wcpw_deactivate() {
    wcpw_clear_events();
}
register_deactivation_hook(__FILE__, 'wcpw_deactivate');

// ---------------------------------------------------------------------------
// System-cron notice (§6): WP-Cron is traffic-triggered and unreliable on a
// low-traffic admin site. Require DISABLE_WP_CRON + a real crontab.
// ---------------------------------------------------------------------------

function wcpw_cron_notice() {
    if (!current_user_can('manage_options')) {
        return;
    }
    $screen = get_current_screen();
    if (!$screen || strpos((string) $screen->id, 'wiretap') === false) {
        return;
    }
    if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
        return;
    }
    echo '<div class="notice notice-warning"><p><strong>Wiretap:</strong> '
        . esc_html__('WP-Cron is traffic-triggered and will not run punctually on a quiet site. Add to wp-config.php: ', 'wcp-wiretap')
        . '<code>define(\'DISABLE_WP_CRON\', true);</code> '
        . esc_html__('and install the system crontab line from the plugin README (every 5 minutes).', 'wcp-wiretap')
        . '</p></div>';
}
add_action('admin_notices', 'wcpw_cron_notice');

// ---------------------------------------------------------------------------
// Boot
// ---------------------------------------------------------------------------

function wcpw_boot() {
    WCPW_REST_API::instance();
    if (is_admin()) {
        WCPW_Dashboard::instance();
        WCPW_Settings::instance();
    }
}
add_action('plugins_loaded', 'wcpw_boot');
