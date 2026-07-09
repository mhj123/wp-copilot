<?php
/**
 * Wiretap settings (§10). Single option array `wcpw_settings`.
 *
 * GUARDRAIL (§12.8): credentials live in options, never in code, and are
 * rendered as password fields; they never appear in logs or snapshots.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPW_Settings {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_post_wcpw_save_settings', array($this, 'save'));
        add_action('admin_post_wcpw_import_tickers', array($this, 'import_tickers'));
    }

    public function menu() {
        add_submenu_page('wcp-wiretap', __('Settings', 'wcp-wiretap'), __('Settings', 'wcp-wiretap'), 'manage_options', 'wcp-wiretap-settings', array($this, 'render'));
    }

    public function save() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'wcp-wiretap'), '', array('response' => 403));
        }
        check_admin_referer('wcpw_settings');

        $current = get_option('wcpw_settings', array());
        $defaults = wcpw_default_settings();
        $in = wp_unslash($_POST);

        $text_keys = array('x_bearer_token', 'anthropic_api_key', 'telegram_bot_token', 'telegram_chat_id', 'model_fast', 'model_strong');
        foreach ($text_keys as $key) {
            if (isset($in[$key])) {
                $current[$key] = sanitize_text_field($in[$key]);
            }
        }

        $float_keys = array('per_read_price_usd', 'monthly_read_cap_usd', 'alert_confidence_threshold', 'review_confidence_floor', 'emerging_velocity_mult');
        foreach ($float_keys as $key) {
            if (isset($in[$key])) {
                $current[$key] = (float) $in[$key];
            }
        }

        $int_keys = array('max_kols', 'tweet_retention_days', 'new_call_window', 'trust_alert_min', 'fetch_lookback_hours',
            'analyze_chunk_size', 'emerging_min_kols', 'emerging_max_age', 'discovery_min_interactions',
            'earliest_search_max_results', 'plan_ttl_days', 'price_cache_minutes', 'digest_hour');
        foreach ($int_keys as $key) {
            if (isset($in[$key])) {
                $current[$key] = (int) $in[$key];
            }
        }

        foreach (array('notify_new_calls', 'notify_plan_triggers', 'notify_emerging', 'notify_digest', 'include_retweets') as $key) {
            $current[$key] = empty($in[$key]) ? 0 : 1;
        }

        if (isset($in['lexicon'])) {
            $terms = array_filter(array_map('trim', explode("\n", sanitize_textarea_field($in['lexicon']))));
            $current['lexicon'] = array_values($terms) ?: $defaults['lexicon'];
        }

        // Earliness thresholds (§5) — nested, numeric only.
        $earliness = isset($current['earliness']) ? (array) $current['earliness'] : $defaults['earliness'];
        foreach (array_keys($defaults['earliness']) as $key) {
            if (isset($in['earliness_' . $key]) && is_numeric($in['earliness_' . $key])) {
                $earliness[$key] = $key === 'min_mentions' ? (int) $in['earliness_' . $key] : (float) $in['earliness_' . $key];
            }
        }
        $current['earliness'] = $earliness;

        update_option('wcpw_settings', $current);
        wp_safe_redirect(add_query_arg(array('page' => 'wcp-wiretap-settings', 'saved' => 1), admin_url('admin.php')));
        exit;
    }

    public function import_tickers() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'wcp-wiretap'), '', array('response' => 403));
        }
        check_admin_referer('wcpw_import_tickers');
        $count = 0;
        if (!empty($_FILES['ticker_json']['tmp_name'])) {
            $result = WCPW_Ticker_Registry::import_json(file_get_contents($_FILES['ticker_json']['tmp_name']));
            $count = is_wp_error($result) ? 0 : (int) $result;
        }
        wp_safe_redirect(add_query_arg(array('page' => 'wcp-wiretap-settings', 'tickers_added' => $count), admin_url('admin.php')));
        exit;
    }

    public function render() {
        $s = function ($key) { return wcpw_get_setting($key); };
        $earliness = (array) $s('earliness');
        ?>
        <div class="wrap">
            <h1><?php _e('Wiretap — Settings', 'wcp-wiretap'); ?></h1>
            <?php if (isset($_GET['saved'])) : ?>
                <div class="notice notice-success"><p><?php _e('Settings saved.', 'wcp-wiretap'); ?></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['tickers_added'])) : ?>
                <div class="notice notice-success"><p><?php printf(esc_html__('%d tickers imported.', 'wcp-wiretap'), (int) $_GET['tickers_added']); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wcpw_save_settings">
                <?php wp_nonce_field('wcpw_settings'); ?>

                <h2><?php _e('Credentials', 'wcp-wiretap'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr><th><?php _e('X API bearer token', 'wcp-wiretap'); ?></th>
                        <td><input type="password" name="x_bearer_token" value="<?php echo esc_attr($s('x_bearer_token')); ?>" class="regular-text" autocomplete="off">
                            <button class="button" data-wcpw="test-x" type="button"><?php _e('Test', 'wcp-wiretap'); ?></button></td></tr>
                    <tr><th><?php _e('Per-read price (USD)', 'wcp-wiretap'); ?></th>
                        <td><input type="text" name="per_read_price_usd" value="<?php echo esc_attr($s('per_read_price_usd')); ?>" size="8">
                            <span class="description"><?php _e('Verify current X API pay-per-use rates and keep this in sync (§9).', 'wcp-wiretap'); ?></span></td></tr>
                    <tr><th><?php _e('Monthly read cap (USD)', 'wcp-wiretap'); ?></th>
                        <td><input type="text" name="monthly_read_cap_usd" value="<?php echo esc_attr($s('monthly_read_cap_usd')); ?>" size="8"></td></tr>
                    <tr><th><?php _e('Anthropic API key', 'wcp-wiretap'); ?></th>
                        <td><input type="password" name="anthropic_api_key" value="<?php echo esc_attr($s('anthropic_api_key')); ?>" class="regular-text" autocomplete="off">
                            <button class="button" data-wcpw="test-anthropic" type="button"><?php _e('Test', 'wcp-wiretap'); ?></button>
                            <p class="description"><?php _e('Leave blank to inherit the Work Copilot core key.', 'wcp-wiretap'); ?></p></td></tr>
                    <tr><th><?php _e('Fast model (classification / extraction / fit)', 'wcp-wiretap'); ?></th>
                        <td><input type="text" name="model_fast" value="<?php echo esc_attr($s('model_fast')); ?>" class="regular-text"></td></tr>
                    <tr><th><?php _e('Strong model (digest / check-in)', 'wcp-wiretap'); ?></th>
                        <td><input type="text" name="model_strong" value="<?php echo esc_attr($s('model_strong')); ?>" class="regular-text"></td></tr>
                    <tr><th><?php _e('Telegram bot token', 'wcp-wiretap'); ?></th>
                        <td><input type="password" name="telegram_bot_token" value="<?php echo esc_attr($s('telegram_bot_token')); ?>" class="regular-text" autocomplete="off"></td></tr>
                    <tr><th><?php _e('Telegram chat ID', 'wcp-wiretap'); ?></th>
                        <td><input type="text" name="telegram_chat_id" value="<?php echo esc_attr($s('telegram_chat_id')); ?>" size="20">
                            <button class="button" data-wcpw="test-telegram" type="button"><?php _e('Test', 'wcp-wiretap'); ?></button>
                            <p class="description"><?php _e('Notify-only — Wiretap never sends action buttons over Telegram.', 'wcp-wiretap'); ?></p></td></tr>
                </table>

                <h2><?php _e('Notifications', 'wcp-wiretap'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr><th><?php _e('Push to Telegram', 'wcp-wiretap'); ?></th><td>
                        <label><input type="checkbox" name="notify_new_calls" <?php checked($s('notify_new_calls')); ?>> <?php _e('New calls (above thresholds)', 'wcp-wiretap'); ?></label><br>
                        <label><input type="checkbox" name="notify_plan_triggers" <?php checked($s('notify_plan_triggers')); ?>> <?php _e('Trade-plan triggers / invalidations', 'wcp-wiretap'); ?></label><br>
                        <label><input type="checkbox" name="notify_emerging" <?php checked($s('notify_emerging')); ?>> <?php _e('Emerging themes/tickers', 'wcp-wiretap'); ?></label><br>
                        <label><input type="checkbox" name="notify_digest" <?php checked($s('notify_digest')); ?>> <?php _e('Morning digest link', 'wcp-wiretap'); ?></label>
                    </td></tr>
                </table>

                <h2><?php _e('Ingestion & analysis', 'wcp-wiretap'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr><th><?php _e('Include retweets', 'wcp-wiretap'); ?></th>
                        <td><label><input type="checkbox" name="include_retweets" <?php checked($s('include_retweets')); ?>> <?php _e('Ingest pure retweets too', 'wcp-wiretap'); ?></label></td></tr>
                    <tr><th><?php _e('First-fetch lookback (hours)', 'wcp-wiretap'); ?></th>
                        <td><input type="number" name="fetch_lookback_hours" value="<?php echo esc_attr($s('fetch_lookback_hours')); ?>" min="1" max="168"></td></tr>
                    <tr><th><?php _e('Max KOLs (soft cap)', 'wcp-wiretap'); ?></th>
                        <td><input type="number" name="max_kols" value="<?php echo esc_attr($s('max_kols')); ?>" min="1"></td></tr>
                    <tr><th><?php _e('Tweet retention (days)', 'wcp-wiretap'); ?></th>
                        <td><input type="number" name="tweet_retention_days" value="<?php echo esc_attr($s('tweet_retention_days')); ?>" min="7">
                            <p class="description"><?php _e('Pruned nightly, except tweets referenced by recommendations or plans. X API ToS constrains long-term storage/redistribution — digests quote sparingly and link out.', 'wcp-wiretap'); ?></p></td></tr>
                    <tr><th><?php _e('Analyze chunk size', 'wcp-wiretap'); ?></th>
                        <td><input type="number" name="analyze_chunk_size" value="<?php echo esc_attr($s('analyze_chunk_size')); ?>" min="1" max="50"></td></tr>
                    <tr><th><?php _e('New-call window (days)', 'wcp-wiretap'); ?></th>
                        <td><input type="number" name="new_call_window" value="<?php echo esc_attr($s('new_call_window')); ?>" min="1"></td></tr>
                    <tr><th><?php _e('Alert confidence threshold', 'wcp-wiretap'); ?></th>
                        <td><input type="text" name="alert_confidence_threshold" value="<?php echo esc_attr($s('alert_confidence_threshold')); ?>" size="6"></td></tr>
                    <tr><th><?php _e('Review confidence floor', 'wcp-wiretap'); ?></th>
                        <td><input type="text" name="review_confidence_floor" value="<?php echo esc_attr($s('review_confidence_floor')); ?>" size="6">
                            <p class="description"><?php _e('Signals below this are flagged for attention, never silently dropped.', 'wcp-wiretap'); ?></p></td></tr>
                    <tr><th><?php _e('Trust gate for alerts (1–5)', 'wcp-wiretap'); ?></th>
                        <td><input type="number" name="trust_alert_min" value="<?php echo esc_attr($s('trust_alert_min')); ?>" min="1" max="5"></td></tr>
                    <tr><th><?php _e('Lexicon (one term per line)', 'wcp-wiretap'); ?></th>
                        <td><textarea name="lexicon" rows="6" class="large-text code"><?php echo esc_textarea(implode("\n", (array) $s('lexicon'))); ?></textarea></td></tr>
                </table>

                <h2><?php _e('Earliness thresholds (§5)', 'wcp-wiretap'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    $labels = array(
                        'min_mentions' => __('Min mentions for a band (else "insufficient data")', 'wcp-wiretap'),
                        'd_low' => __('Diffusion: low (too_early / quiet_mover ceiling)', 'wcp-wiretap'),
                        'd_mid' => __('Diffusion: mid (on_time ceiling / crowded floor)', 'wcp-wiretap'),
                        'd_high' => __('Diffusion: high (late floor)', 'wcp-wiretap'),
                        'v_hot' => __('Velocity: accelerating (on_time floor)', 'wcp-wiretap'),
                        'v_cold' => __('Velocity: fading (late trigger)', 'wcp-wiretap'),
                        'x_flat_lo' => __('Price: flat range low', 'wcp-wiretap'),
                        'x_flat_hi' => __('Price: flat range high', 'wcp-wiretap'),
                        'x_moved' => __('Price: moved threshold', 'wcp-wiretap'),
                        'x_extended' => __('Price: extended (late trigger)', 'wcp-wiretap'),
                        'o_early' => __('Originator share: shift earlier at ≥', 'wcp-wiretap'),
                        'o_late' => __('Originator share: shift later at ≤', 'wcp-wiretap'),
                    );
                    foreach ($labels as $key => $label) : ?>
                        <tr><th><?php echo esc_html($label); ?></th>
                            <td><input type="text" name="earliness_<?php echo esc_attr($key); ?>" value="<?php echo esc_attr(isset($earliness[$key]) ? $earliness[$key] : ''); ?>" size="8"></td></tr>
                    <?php endforeach; ?>
                </table>

                <h2><?php _e('Emerging, discovery, plans, prices, digest', 'wcp-wiretap'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr><th><?php _e('Emerging: min distinct KOLs (7d)', 'wcp-wiretap'); ?></th>
                        <td><input type="number" name="emerging_min_kols" value="<?php echo esc_attr($s('emerging_min_kols')); ?>" min="1"></td></tr>
                    <tr><th><?php _e('Emerging: velocity multiple', 'wcp-wiretap'); ?></th>
                        <td><input type="text" name="emerging_velocity_mult" value="<?php echo esc_attr($s('emerging_velocity_mult')); ?>" size="6"></td></tr>
                    <tr><th><?php _e('Emerging: max age (days, else "resurgent")', 'wcp-wiretap'); ?></th>
                        <td><input type="number" name="emerging_max_age" value="<?php echo esc_attr($s('emerging_max_age')); ?>" min="1"></td></tr>
                    <tr><th><?php _e('Discovery: min interactions (30d)', 'wcp-wiretap'); ?></th>
                        <td><input type="number" name="discovery_min_interactions" value="<?php echo esc_attr($s('discovery_min_interactions')); ?>" min="1"></td></tr>
                    <tr><th><?php _e('Earliest-caller search cap (reads)', 'wcp-wiretap'); ?></th>
                        <td><input type="number" name="earliest_search_max_results" value="<?php echo esc_attr($s('earliest_search_max_results')); ?>" min="10" max="2000"></td></tr>
                    <tr><th><?php _e('Trade plan TTL (days)', 'wcp-wiretap'); ?></th>
                        <td><input type="number" name="plan_ttl_days" value="<?php echo esc_attr($s('plan_ttl_days')); ?>" min="1"></td></tr>
                    <tr><th><?php _e('Price cache (minutes)', 'wcp-wiretap'); ?></th>
                        <td><input type="number" name="price_cache_minutes" value="<?php echo esc_attr($s('price_cache_minutes')); ?>" min="5">
                            <p class="description"><?php _e('CoinGecko free tier for crypto; Stooq for equities (EOD/delayed).', 'wcp-wiretap'); ?></p></td></tr>
                    <tr><th><?php _e('Digest hour (site time)', 'wcp-wiretap'); ?></th>
                        <td><input type="number" name="digest_hour" value="<?php echo esc_attr($s('digest_hour')); ?>" min="0" max="23"></td></tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr>
            <h2><?php _e('Ticker registry import', 'wcp-wiretap'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="wcpw_import_tickers">
                <?php wp_nonce_field('wcpw_import_tickers'); ?>
                <p>
                    <input type="file" name="ticker_json" accept=".json,application/json" required>
                    <button class="button"><?php _e('Import JSON', 'wcp-wiretap'); ?></button>
                    <span class="description"><?php _e('Array of {symbol, asset_class, name, coingecko_id|stooq_symbol, aliases}. Existing symbols untouched.', 'wcp-wiretap'); ?></span>
                </p>
            </form>
        </div>
        <script>
        (function() {
            const rest = <?php echo wp_json_encode(rest_url('wcp-wiretap/v1/')); ?>;
            const nonce = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;
            document.addEventListener('click', async (e) => {
                const el = e.target.closest('[data-wcpw^="test-"]');
                if (!el) return;
                e.preventDefault();
                el.disabled = true;
                try {
                    const res = await fetch(rest + 'test-connections', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                        body: JSON.stringify({ which: el.dataset.wcpw.replace('test-', '') })
                    });
                    const body = await res.json();
                    alert(body.success ? 'Connection OK ✓' : ('Failed: ' + (body.message || 'unknown error')));
                } catch (err) { alert('Failed: ' + err.message); }
                el.disabled = false;
            });
        })();
        </script>
        <?php
    }
}
