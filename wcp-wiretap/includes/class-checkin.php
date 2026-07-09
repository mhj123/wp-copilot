<?php
/**
 * Decision check-in (§F8): bounded context pack → one LLM call → advisory
 * memo stored as a timestamped note on the recommendation or trade plan.
 *
 * GUARDRAIL (§12.1): the memo is advisory text only — it changes NO statuses.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPW_Checkin {

    /**
     * Run a check-in on a recommendation or trade plan.
     *
     * @return array|WP_Error the memo
     */
    public static function run($object_id) {
        $post = get_post($object_id);
        if (!$post || !in_array($post->post_type, array('wcp_recommendation', 'wcp_trade_plan'), true)) {
            return new WP_Error('not_found', 'Object is not a recommendation or trade plan.');
        }

        if ($post->post_type === 'wcp_trade_plan') {
            $plan = WCPW_Trade_Plan::meta($object_id);
            $rec = $plan['source_rec_id'] ? WCPW_Recommendation_Repo::meta($plan['source_rec_id']) : null;
            $ticker = $plan['ticker'];
        } else {
            $rec = WCPW_Recommendation_Repo::meta($object_id);
            $plan = null;
            $ticker = $rec['ticker'];
        }

        // ---- Bounded context pack (§F8) ----
        $pack = "Asset: \${$ticker}\n";

        if ($rec) {
            $kol_handle = $rec['kol'] ? $rec['kol']['handle'] : '';
            $pack .= "Original call: {$rec['direction']} by @{$kol_handle} on " . substr($rec['created_at'], 0, 10)
                . " (confidence {$rec['confidence']})\n";
            $pack .= "Rationale: {$rec['rationale_excerpt']}\n";
            if ($rec['price_at_call']) {
                $pack .= "Price at call: \${$rec['price_at_call']}\n";
            }

            $tweet = WCPW_Tweet_Repo::get_by_tweet_id($rec['source_tweet_id']);
            if ($tweet) {
                $pack .= "Original tweet: " . mb_substr($tweet['text'], 0, 500) . "\n";
                foreach (WCPW_Tweet_Repo::thread_siblings($tweet['conversation_id'], $tweet['tweet_id'], 5) as $sib) {
                    $pack .= "Thread: " . mb_substr($sib['text'], 0, 300) . "\n";
                }
            }

            // Same KOL since the call: gone quiet, doubled down, or flipped?
            if ($rec['kol']) {
                $since_rows = WCPW_Tweet_Repo::signal_rows_since($rec['created_at'], $ticker);
                $same_kol = array();
                $other_kols = array();
                foreach ($since_rows as $row) {
                    $line = '[' . substr($row['created_at'], 0, 10) . '] @' . $row['author_handle'] . ': ' . mb_substr($row['text'], 0, 200);
                    if ((int) $row['kol_id'] === $rec['kol_id']) {
                        $same_kol[] = $line;
                    } else {
                        $other_kols[] = $line;
                    }
                }
                $pack .= "\nSame KOL's subsequent \${$ticker} tweets (" . count($same_kol) . "):\n";
                $pack .= $same_kol ? implode("\n", array_slice($same_kol, -8)) . "\n" : "(none — has gone quiet on this ticker)\n";
                $pack .= "\nOther tracked KOLs on \${$ticker} since the call (" . count($other_kols) . "):\n";
                $pack .= $other_kols ? implode("\n", array_slice($other_kols, -8)) . "\n" : "(none)\n";
            }
        }

        if ($plan) {
            $pack .= sprintf(
                "\nTrade plan: status %s; entry %s–%s; invalidation: %s; targets: %s; timeframe: %s\n",
                $plan['status'],
                $plan['entry_low'] !== null ? $plan['entry_low'] : '?',
                $plan['entry_high'] !== null ? $plan['entry_high'] : '?',
                $plan['invalidation'] ?: 'none stated',
                implode(', ', array_map('strval', $plan['targets'])) ?: 'none',
                $plan['timeframe'] ?: 'none'
            );
        }

        // Price series since the call.
        $anchor = $rec ? $rec['created_at'] : ($plan ? $plan['created_at'] : gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS));
        $series = WCPW_Price_Source::series_since($ticker, $anchor, 60);
        $price_now = WCPW_Price_Source::get_price($ticker);
        if ($price_now !== null) {
            $pack .= "\nCurrent price: \${$price_now}\n";
        }
        if ($series) {
            $points = array();
            foreach (array_slice($series, -20) as $obs) {
                $points[] = substr($obs['observed_at'], 5, 5) . ':$' . round((float) $obs['price'], 4);
            }
            $pack .= "Recent price observations: " . implode(', ', $points) . "\n";
        }

        // Current earliness snapshot.
        $earliness = WCPW_Earliness::compute($ticker);
        $pack .= "\nEarliness now: {$earliness['band']} — {$earliness['facts']}\n";

        // ---- One LLM call (§7.4) ----
        $system = 'You are reviewing whether an investment thesis from a tracked KOL still holds. '
            . 'Judge only from the provided context. Return ONLY valid JSON: '
            . '{"thesis_status":"intact|strengthened|weakened|invalidated",'
            . '"key_developments":["string"],"kol_stance_change":"string",'
            . '"suggested_next_look":"hold|revisit|tighten_invalidation|exit_watch","rationale":"string"}';

        $result = WCPW_LLM::call('checkin', $system, $pack, array(
            'tier'       => 'strong',
            'max_tokens' => 1000,
            'required'   => array('thesis_status', 'suggested_next_look', 'rationale'),
            'related_id' => $object_id,
        ));
        if (is_wp_error($result)) {
            return $result;
        }

        $memo = array(
            'at'                  => current_time('mysql', true),
            'thesis_status'       => (string) $result['data']['thesis_status'],
            'key_developments'    => array_map('sanitize_text_field', (array) (isset($result['data']['key_developments']) ? $result['data']['key_developments'] : array())),
            'kol_stance_change'   => sanitize_text_field((string) (isset($result['data']['kol_stance_change']) ? $result['data']['kol_stance_change'] : '')),
            'suggested_next_look' => (string) $result['data']['suggested_next_look'],
            'rationale'           => sanitize_textarea_field((string) $result['data']['rationale']),
            'earliness_band'      => $earliness['band'],
            'price_now'           => $price_now,
            'ai_log_id'           => (int) $result['log_id'],
        );

        // Timestamped note on the object. Advisory only — no status change.
        $checkins = json_decode((string) get_post_meta($object_id, '_wcpw_checkins', true), true);
        $checkins = is_array($checkins) ? $checkins : array();
        $checkins[] = $memo;
        update_post_meta($object_id, '_wcpw_checkins', wp_json_encode($checkins));

        return $memo;
    }
}
