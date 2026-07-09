<?php
/**
 * Two-stage analysis (§F1): cheap pre-filter, then thread-aware LLM
 * classification (§7.1) in self-rescheduling chunks (§6 — a 50-call loop in
 * one cron tick would time out). Newness is computed in code after the LLM
 * call. New calls create wcp_pending recommendations; repeats reinforce.
 *
 * GUARDRAIL (§12.1): every recommendation this class creates is wcp_pending.
 * GUARDRAIL (§12.2): cron proposes only — acceptance lives behind REST + human.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPW_Analyzer {

    /** Cron entry point — processes one chunk, reschedules itself if more remain. */
    public static function run_chunk() {
        if (!wcpw_acquire_lock('analyze', 5 * MINUTE_IN_SECONDS)) {
            return;
        }
        $started = current_time('mysql', true);
        $chunk = max(1, (int) wcpw_get_setting('analyze_chunk_size'));
        $counts = array('analyzed' => 0, 'skipped' => 0, 'recs_created' => 0, 'reinforced' => 0, 'errors' => 0);
        $errors = array();
        $tokens = 0;

        foreach (WCPW_Tweet_Repo::pending($chunk) as $row) {
            $result = self::analyze_tweet($row);
            if (is_wp_error($result)) {
                $counts['errors']++;
                $errors[] = 'tweet ' . $row['tweet_id'] . ': ' . $result->get_error_message();
                WCPW_Tweet_Repo::set_status($row['id'], 'error');
                continue;
            }
            $counts['analyzed'] += $result['analyzed'] ? 1 : 0;
            $counts['skipped'] += $result['skipped'] ? 1 : 0;
            $counts['recs_created'] += $result['recs_created'];
            $counts['reinforced'] += $result['reinforced'];
            $tokens += $result['tokens'];
        }

        wcpw_record_run('analyze', array(
            'started_at'  => $started,
            'counts'      => $counts,
            'errors'      => $errors,
            'tokens_used' => $tokens,
        ));
        wcpw_release_lock('analyze');

        // Self-reschedule until the queue drains (§6).
        if (WCPW_Tweet_Repo::pending_count() > 0 && !wp_next_scheduled('wcp_wiretap_analyze')) {
            wp_schedule_single_event(time() + 30, 'wcp_wiretap_analyze');
        }
    }

    /**
     * Analyze a single stored tweet row.
     *
     * @return array|WP_Error { analyzed, skipped, recs_created, reinforced, tokens }
     */
    public static function analyze_tweet(array $row) {
        $out = array('analyzed' => false, 'skipped' => false, 'recs_created' => 0, 'reinforced' => 0, 'tokens' => 0);

        // Stage 1: cheap pre-filter — zero signals → skipped, no LLM (§F1).
        $scan = WCPW_Prefilter::scan($row['text'], $row['entities_json']);
        if (!$scan['has_signal']) {
            WCPW_Tweet_Repo::set_status($row['id'], 'skipped');
            $out['skipped'] = true;
            return $out;
        }

        // Stage 2: LLM classification over a bounded, thread-aware context pack.
        $kol = WCPW_KOLs::meta($row['kol_id']);
        $trust_min = (int) wcpw_get_setting('trust_alert_min');
        $tier = $kol['trust_score'] >= $trust_min ? 'tier-1 originator' : 'standard';

        $pack = "Author: @{$kol['handle']} (trust {$kol['trust_score']}/5, {$tier})\n";
        $pack .= "Tweet ({$row['created_at']} UTC):\n{$row['text']}\n";

        $siblings = WCPW_Tweet_Repo::thread_siblings($row['conversation_id'], $row['tweet_id'], 8);
        if ($siblings) {
            $pack .= "\nThread context (same conversation, oldest first):\n";
            foreach ($siblings as $sib) {
                $pack .= '- @' . $sib['author_handle'] . ': ' . mb_substr($sib['text'], 0, 500) . "\n";
            }
        }
        if ($scan['candidates']) {
            $known = array();
            $unknown = array();
            foreach ($scan['candidates'] as $sym) {
                if (WCPW_Ticker_Registry::get($sym)) {
                    $meta = WCPW_Ticker_Registry::meta($sym);
                    $known[] = '$' . $sym . ' (' . ($meta['display_name'] ?: $sym) . ', ' . $meta['asset_class'] . ')';
                } else {
                    $unknown[] = '$' . $sym;
                }
            }
            if ($known) {
                $pack .= "\nRegistry-matched tickers: " . implode(', ', $known) . "\n";
            }
            if ($unknown) {
                $pack .= "Unrecognised cashtags (resolve from context or mark ticker_resolved=false): " . implode(', ', $unknown) . "\n";
            }
        }
        if ($scan['lexicon_hits']) {
            $pack .= "Lexicon hits: " . implode(', ', $scan['lexicon_hits']) . "\n";
        }

        $system = 'You classify investment-related tweets from tracked accounts. '
            . 'For each asset genuinely discussed, decide if this is an actionable RECOMMENDATION '
            . '(a call to take or hold a position now/soon), a passing MENTION, or HINDSIGHT '
            . '(reflecting on a past call). Use the thread and quoted context — single tweets '
            . 'out of context routinely misclassify. Do not invent tickers not present in the text. '
            . "Return ONLY valid JSON:\n"
            . '{"tweet_id":"string","signals":[{"ticker":"$SOL","ticker_resolved":true,'
            . '"asset_class":"crypto|equity","classification":"recommendation|mention|hindsight",'
            . '"direction":"long|short|accumulate|exit|watch","rationale_excerpt":"string",'
            . '"confidence":0.0}],"themes":["string"],"notes":"string"}';

        $result = WCPW_LLM::call('classification', $system, $pack, array(
            'tier'       => 'fast',
            'max_tokens' => 1024,
            'required'   => array('signals'),
        ));
        if (is_wp_error($result)) {
            return $result;
        }
        $out['tokens'] = 0; // token totals are read from the AI log by the run recorder
        $data = $result['data'];
        $log_id = $result['log_id'];

        // Normalize + persist per-tweet signals for the rollup/earliness (§4.4, §5).
        $signals = array();
        foreach ((array) $data['signals'] as $signal) {
            if (empty($signal['ticker'])) {
                continue;
            }
            $symbol = strtoupper(ltrim(trim($signal['ticker']), '$'));
            $signals[] = array(
                'ticker'          => $symbol,
                'ticker_resolved' => !empty($signal['ticker_resolved']),
                'asset_class'     => (isset($signal['asset_class']) && $signal['asset_class'] === 'equity') ? 'equity' : 'crypto',
                'classification'  => isset($signal['classification']) ? $signal['classification'] : 'mention',
                'direction'       => isset($signal['direction']) ? $signal['direction'] : 'watch',
                'rationale'       => isset($signal['rationale_excerpt']) ? mb_substr((string) $signal['rationale_excerpt'], 0, 500) : '',
                'confidence'      => isset($signal['confidence']) ? max(0, min(1, (float) $signal['confidence'])) : 0,
            );
        }
        $themes = array_slice(array_map('sanitize_text_field', (array) (isset($data['themes']) ? $data['themes'] : array())), 0, 5);

        WCPW_Tweet_Repo::set_status($row['id'], 'analyzed', array('signals' => $signals, 'themes' => $themes));
        $out['analyzed'] = true;

        // Stage 3 (in code, §F1): newness → create rec or reinforce.
        foreach ($signals as $signal) {
            if ($signal['classification'] !== 'recommendation') {
                continue;
            }
            $handled = self::handle_recommendation($row, $kol, $signal, $themes, $log_id);
            if ($handled === 'created') {
                $out['recs_created']++;
            } elseif ($handled === 'reinforced') {
                $out['reinforced']++;
            }
        }

        return $out;
    }

    /**
     * Newness decision + rec creation / reinforcement + alerting.
     * @return string 'created'|'reinforced'|'skipped'
     */
    private static function handle_recommendation(array $row, array $kol, array $signal, array $themes, $log_id) {
        $symbol = $signal['ticker'];

        // Unknown ticker: create the registry entry unverified; the rec is
        // flagged ticker_unverified for human confirmation (§4.3).
        $unverified = false;
        if (!WCPW_Ticker_Registry::get($symbol)) {
            WCPW_Ticker_Registry::add($symbol, $signal['asset_class'], array(), false);
            $unverified = true;
        } elseif (!$signal['ticker_resolved']) {
            $unverified = true;
        }

        $newness = WCPW_Recommendation_Repo::assess_newness($kol['id'], $symbol, $signal['direction']);

        if (!$newness['is_new']) {
            // Reinforcement is itself signal (§F1) — it feeds the digest and
            // earliness, but never duplicates the pending item.
            WCPW_Recommendation_Repo::reinforce($newness['prior_rec_id'], $row['tweet_id']);
            return 'reinforced';
        }

        // price-at-call captured immediately — it cannot be backfilled (§F1).
        $price_at_call = WCPW_Price_Source::get_price($symbol);
        $earliness = WCPW_Earliness::compute($symbol);

        $floor = (float) wcpw_get_setting('review_confidence_floor');
        $rec_id = WCPW_Recommendation_Repo::create(array(
            'ticker'            => $symbol,
            'asset_class'       => $signal['asset_class'],
            'direction'         => $signal['direction'],
            'confidence'        => $signal['confidence'],
            'source_tweet_id'   => $row['tweet_id'],
            'kol_id'            => $kol['id'],
            'rationale_excerpt' => $signal['rationale'],
            'newness_reason'    => $newness['reason'],
            'price_at_call'     => $price_at_call,
            'earliness_at_call' => $earliness,
            'ai_log_id'         => $log_id,
            'ticker_unverified' => $unverified,
            'low_confidence'    => $signal['confidence'] < $floor,
            'themes'            => $themes,
        ));
        if (is_wp_error($rec_id)) {
            return 'skipped';
        }

        self::maybe_alert($rec_id, $kol, $signal, $earliness);
        return 'created';
    }

    /**
     * Alerting (§F1): new calls above the confidence threshold from KOLs at
     * or above the trust gate → Telegram push with a deep link. Muted tickers
     * are suppressed (alerts only — ingestion continues).
     */
    private static function maybe_alert($rec_id, array $kol, array $signal, array $earliness) {
        if ($signal['confidence'] < (float) wcpw_get_setting('alert_confidence_threshold')) {
            return;
        }
        if ($kol['trust_score'] < (int) wcpw_get_setting('trust_alert_min')) {
            return;
        }
        // Per-ticker mute: relief valve for noisy days; suppresses alerts, not ingestion.
        $muted = get_option('wcpw_muted_tickers', array());
        if (isset($muted[$signal['ticker']]) && (int) $muted[$signal['ticker']] > time()) {
            return;
        }

        update_post_meta($rec_id, '_wcpw_alerted', current_time('mysql', true));

        if (wcpw_get_setting('notify_new_calls')) {
            $link = admin_url('admin.php?page=wcp-wiretap&rec=' . $rec_id);
            WCPW_Telegram::notify(sprintf(
                "🚨 New call: $%s %s — @%s (trust %d/5, conf %.0f%%)\nEarliness: %s\n%s\n%s",
                $signal['ticker'],
                strtoupper($signal['direction']),
                $kol['handle'],
                $kol['trust_score'],
                $signal['confidence'] * 100,
                WCPW_Earliness::band_label($earliness['band']),
                mb_substr($signal['rationale'], 0, 200),
                $link
            ));
        }
    }
}
