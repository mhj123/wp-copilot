<?php
/**
 * Trade plans (§F7): conditional, watched, approved.
 *
 * Lifecycle: wcp_proposed → (human approves, may edit levels) → wcp_armed →
 * (price watcher fires) → wcp_triggered → human closes → wcp_closed;
 * or wcp_expired / wcp_cancelled / wcp_invalidated.
 *
 * GUARDRAIL (§12.3): NO order routing, NO exchange/wallet/brokerage API,
 * anywhere in this plugin, ever. "Triggered" means NOTIFY, never execute.
 * GUARDRAIL (§12.2): only a human (via REST) can move proposed → armed.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPW_Trade_Plan {

    const STATUSES = array('wcp_proposed', 'wcp_armed', 'wcp_triggered', 'wcp_invalidated', 'wcp_closed', 'wcp_expired', 'wcp_cancelled');

    /**
     * Propose a plan from a recommendation via LLM extraction (§7.3).
     * Created in wcp_proposed — always.
     *
     * @return int|WP_Error plan post id
     */
    public static function propose_from_rec($rec_id) {
        $rec = WCPW_Recommendation_Repo::meta($rec_id);
        if (!$rec) {
            return new WP_Error('not_found', 'Recommendation not found');
        }

        $tweet = WCPW_Tweet_Repo::get_by_tweet_id($rec['source_tweet_id']);
        $pack = "Recommendation: \${$rec['ticker']} {$rec['direction']} by @" . ($rec['kol'] ? $rec['kol']['handle'] : '?') . "\n";
        $pack .= "Rationale: {$rec['rationale_excerpt']}\n";
        if ($tweet) {
            $pack .= "Source tweet:\n{$tweet['text']}\n";
            foreach (WCPW_Tweet_Repo::thread_siblings($tweet['conversation_id'], $tweet['tweet_id'], 5) as $sib) {
                $pack .= "Thread: " . mb_substr($sib['text'], 0, 300) . "\n";
            }
        }
        $price = WCPW_Price_Source::get_price($rec['ticker']);
        if ($price !== null) {
            $pack .= "Current price: \${$price}\n";
        }

        $system = 'Extract a conditional trade plan from the KOL\'s stated view. Convert verbal level '
            . 'descriptions into numeric zones (e.g. "bottom in the 40s" for an asset near $60 means '
            . 'entry zone low 40, high 49). Only extract levels the KOL actually implied — if none, '
            . 'set entry type "unspecified". Return ONLY valid JSON: '
            . '{"has_plan":true,"entry":{"type":"zone|level|market|unspecified","low":0.0,"high":0.0},'
            . '"invalidation":"string|null","targets":[],"timeframe":"string|null","thesis":"string","confidence":0.0}';

        $result = WCPW_LLM::call('plan_extraction', $system, $pack, array(
            'tier'       => 'fast',
            'max_tokens' => 800,
            'required'   => array('has_plan', 'entry', 'thesis'),
            'related_id' => $rec_id,
        ));
        if (is_wp_error($result)) {
            return $result;
        }
        $data = $result['data'];

        $entry = (array) $data['entry'];
        $entry_type = isset($entry['type']) && in_array($entry['type'], array('zone', 'level', 'market', 'unspecified'), true)
            ? $entry['type'] : 'unspecified';
        $entry_low  = isset($entry['low']) && is_numeric($entry['low']) ? (float) $entry['low'] : null;
        $entry_high = isset($entry['high']) && is_numeric($entry['high']) ? (float) $entry['high'] : null;
        if ($entry_type === 'level' && $entry_low !== null && $entry_high === null) {
            $entry_high = $entry_low;
        }

        $ttl_days = (int) wcpw_get_setting('plan_ttl_days');
        $title = sprintf('Plan: $%s %s — %s', $rec['ticker'], $rec['direction'], gmdate('Y-m-d'));

        // GUARDRAIL (§12.1): plans are ALWAYS created wcp_proposed.
        $plan_id = wp_insert_post(array(
            'post_type'    => 'wcp_trade_plan',
            'post_status'  => 'wcp_proposed',
            'post_title'   => $title,
            'post_content' => isset($data['thesis']) ? (string) $data['thesis'] : '',
        ), true);
        if (is_wp_error($plan_id)) {
            return $plan_id;
        }

        $meta = array(
            '_wcpw_source_rec_id'     => (int) $rec_id,
            '_wcpw_ticker'            => $rec['ticker'],
            '_wcpw_asset_class'       => $rec['asset_class'],
            '_wcpw_direction'         => $rec['direction'],
            '_wcpw_entry_type'        => $entry_type,
            '_wcpw_entry_low'         => $entry_low,
            '_wcpw_entry_high'        => $entry_high,
            '_wcpw_invalidation'      => isset($data['invalidation']) && $data['invalidation'] !== null ? sanitize_text_field((string) $data['invalidation']) : '',
            '_wcpw_targets_json'      => wp_json_encode(isset($data['targets']) ? (array) $data['targets'] : array()),
            '_wcpw_timeframe'         => isset($data['timeframe']) && $data['timeframe'] !== null ? sanitize_text_field((string) $data['timeframe']) : '',
            '_wcpw_thesis'            => isset($data['thesis']) ? (string) $data['thesis'] : '',
            '_wcpw_expires_at'        => gmdate('Y-m-d H:i:s', time() + $ttl_days * DAY_IN_SECONDS),
            '_wcpw_price_at_creation' => $price,
            '_wcpw_ai_log_id'         => (int) $result['log_id'],
            // If the KOL gave no levels, flag for the human to set them (§F7).
            '_wcpw_needs_levels'      => ($entry_type === 'unspecified') ? 1 : 0,
        );
        foreach ($meta as $key => $value) {
            update_post_meta($plan_id, $key, $value);
        }
        wp_set_object_terms($plan_id, $rec['ticker'], 'wcp_ticker');
        wp_set_object_terms($plan_id, $rec['asset_class'], 'wcp_asset_class');
        WCPW_AI_Log::set_related((int) $result['log_id'], $plan_id);

        return $plan_id;
    }

    public static function meta($plan_id) {
        $post = get_post($plan_id);
        if (!$post || $post->post_type !== 'wcp_trade_plan') {
            return null;
        }
        return array(
            'id'               => (int) $plan_id,
            'title'            => $post->post_title,
            'status'           => $post->post_status,
            'created_at'       => $post->post_date_gmt,
            'source_rec_id'    => (int) get_post_meta($plan_id, '_wcpw_source_rec_id', true),
            'ticker'           => get_post_meta($plan_id, '_wcpw_ticker', true),
            'asset_class'      => get_post_meta($plan_id, '_wcpw_asset_class', true),
            'direction'        => get_post_meta($plan_id, '_wcpw_direction', true),
            'entry_type'       => get_post_meta($plan_id, '_wcpw_entry_type', true),
            'entry_low'        => self::float_or_null(get_post_meta($plan_id, '_wcpw_entry_low', true)),
            'entry_high'       => self::float_or_null(get_post_meta($plan_id, '_wcpw_entry_high', true)),
            'invalidation'     => get_post_meta($plan_id, '_wcpw_invalidation', true),
            'targets'          => json_decode((string) get_post_meta($plan_id, '_wcpw_targets_json', true), true) ?: array(),
            'timeframe'        => get_post_meta($plan_id, '_wcpw_timeframe', true),
            'thesis'           => get_post_meta($plan_id, '_wcpw_thesis', true),
            'expires_at'       => get_post_meta($plan_id, '_wcpw_expires_at', true),
            'price_at_creation' => self::float_or_null(get_post_meta($plan_id, '_wcpw_price_at_creation', true)),
            'triggered_at'     => get_post_meta($plan_id, '_wcpw_triggered_at', true),
            'needs_levels'     => (bool) get_post_meta($plan_id, '_wcpw_needs_levels', true),
            'checkins'         => json_decode((string) get_post_meta($plan_id, '_wcpw_checkins', true), true) ?: array(),
        );
    }

    /**
     * Human transitions (REST only): arm (with optional edited levels),
     * cancel, close. Only a human can move proposed → armed (§F7).
     */
    public static function arm($plan_id, array $levels = array()) {
        $plan = self::meta($plan_id);
        if (!$plan || $plan['status'] !== 'wcp_proposed') {
            return new WP_Error('bad_state', 'Only proposed plans can be armed.');
        }

        $before = array('entry_low' => $plan['entry_low'], 'entry_high' => $plan['entry_high'], 'invalidation' => $plan['invalidation']);
        $after = array();
        foreach (array('entry_low', 'entry_high') as $field) {
            if (isset($levels[$field]) && is_numeric($levels[$field])) {
                update_post_meta($plan_id, '_wcpw_' . $field, (float) $levels[$field]);
                $after[$field] = (float) $levels[$field];
            }
        }
        if (isset($levels['invalidation'])) {
            update_post_meta($plan_id, '_wcpw_invalidation', sanitize_text_field($levels['invalidation']));
            $after['invalidation'] = sanitize_text_field($levels['invalidation']);
        }
        if ($after) {
            WCPW_AI_Log::log_edit($plan_id, $before, array_merge($before, $after));
            update_post_meta($plan_id, '_wcpw_entry_type', 'zone');
            delete_post_meta($plan_id, '_wcpw_needs_levels');
            $plan = self::meta($plan_id);
        }

        if ($plan['entry_low'] === null || $plan['entry_high'] === null) {
            return new WP_Error('needs_levels', 'Set entry levels before arming — the KOL gave none.');
        }

        wp_update_post(array('ID' => (int) $plan_id, 'post_status' => 'wcp_armed'));
        $log_id = (int) get_post_meta($plan_id, '_wcpw_ai_log_id', true);
        if ($log_id) {
            WCPW_AI_Log::set_decision($log_id, 'armed');
        }
        return true;
    }

    public static function transition($plan_id, $to) {
        $allowed = array(
            'wcp_cancelled' => array('wcp_proposed', 'wcp_armed'),
            'wcp_closed'    => array('wcp_triggered', 'wcp_armed', 'wcp_invalidated'),
        );
        if (!isset($allowed[$to])) {
            return new WP_Error('bad_transition', 'Unsupported transition.');
        }
        $post = get_post($plan_id);
        if (!$post || !in_array($post->post_status, $allowed[$to], true)) {
            return new WP_Error('bad_state', 'Plan is not in a state that allows this transition.');
        }
        wp_update_post(array('ID' => (int) $plan_id, 'post_status' => $to));
        return true;
    }

    public static function list_by_status($status, $limit = 100) {
        return get_posts(array(
            'post_type'      => 'wcp_trade_plan',
            'post_status'    => $status,
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ));
    }

    /**
     * Price watcher cron (§F7): every 15 min, ARMED plans only.
     * Entry-zone hit → wcp_triggered + alert. Numeric invalidation breached →
     * wcp_invalidated + alert. Past TTL → wcp_expired.
     *
     * GUARDRAIL (§12.3): triggering NOTIFIES. There is no execution path.
     */
    public static function run_price_watch() {
        if (!wcpw_acquire_lock('price_watch', 10 * MINUTE_IN_SECONDS)) {
            return;
        }
        $started = current_time('mysql', true);
        $counts = array('watched' => 0, 'triggered' => 0, 'invalidated' => 0, 'expired' => 0);
        $errors = array();
        $now_gmt = gmdate('Y-m-d H:i:s');

        foreach (self::list_by_status('wcp_armed') as $post) {
            $plan = self::meta($post->ID);
            $counts['watched']++;

            // TTL expiry first — stale plans shouldn't fire.
            if ($plan['expires_at'] && $plan['expires_at'] < $now_gmt) {
                wp_update_post(array('ID' => $post->ID, 'post_status' => 'wcp_expired'));
                $counts['expired']++;
                continue;
            }

            $price = WCPW_Price_Source::get_price($plan['ticker']);
            if ($price === null) {
                $errors[] = 'No price for $' . $plan['ticker'];
                continue;
            }

            // Invalidation while armed (numeric invalidations only; prose
            // invalidations are for the human's eyes).
            $inv = self::parse_numeric($plan['invalidation']);
            if ($inv !== null && self::invalidation_breached($plan['direction'], $price, $inv)) {
                wp_update_post(array('ID' => $post->ID, 'post_status' => 'wcp_invalidated'));
                $counts['invalidated']++;
                if (wcpw_get_setting('notify_plan_triggers')) {
                    WCPW_Telegram::notify(sprintf(
                        "⛔ Plan invalidated: $%s at $%s breached invalidation %s.\nPlan: %s",
                        $plan['ticker'], $price, $plan['invalidation'],
                        admin_url('admin.php?page=wcp-wiretap-plans&plan=' . $post->ID)
                    ));
                }
                continue;
            }

            // Entry zone hit → triggered (notify, never execute).
            if ($plan['entry_low'] !== null && $plan['entry_high'] !== null
                && $price >= $plan['entry_low'] && $price <= $plan['entry_high']) {
                wp_update_post(array('ID' => $post->ID, 'post_status' => 'wcp_triggered'));
                update_post_meta($post->ID, '_wcpw_triggered_at', $now_gmt);
                $counts['triggered']++;
                if (wcpw_get_setting('notify_plan_triggers')) {
                    WCPW_Telegram::notify(sprintf(
                        "🎯 $%s $%s — inside your %s–%s %s zone. Plan: %s",
                        $plan['ticker'], $price, $plan['entry_low'], $plan['entry_high'],
                        $plan['direction'] === 'short' ? 'sell' : 'buy',
                        admin_url('admin.php?page=wcp-wiretap-plans&plan=' . $post->ID)
                    ));
                }
            }
        }

        wcpw_record_run('price_watch', array(
            'started_at' => $started,
            'counts'     => $counts,
            'errors'     => $errors,
        ));
        wcpw_release_lock('price_watch');
    }

    private static function invalidation_breached($direction, $price, $level) {
        if (in_array($direction, array('short'), true)) {
            return $price >= $level;
        }
        // long / accumulate / watch default: invalidation is below.
        return $price <= $level;
    }

    private static function parse_numeric($text) {
        if ($text === '' || $text === null) {
            return null;
        }
        if (is_numeric($text)) {
            return (float) $text;
        }
        // "close below $38", "under 38.50" → 38.50
        if (preg_match('/\$?\s*([0-9]+(?:[\.,][0-9]+)?)/', (string) $text, $m)) {
            return (float) str_replace(',', '', $m[1]);
        }
        return null;
    }

    private static function float_or_null($value) {
        return ($value === '' || $value === null || $value === false) ? null : (float) $value;
    }
}
