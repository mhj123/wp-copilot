<?php
/**
 * Fetch cron (§6): per active KOL, fetch tweets since the last marker,
 * expand quoted tweets into the stored text, upsert idempotently, respect
 * the budget cap and rate limits, then chain the analyzer.
 *
 * GUARDRAIL (§12.2): this job only INGESTS. It creates no recommendations,
 * publishes nothing, and advances no statuses.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPW_Ingest {

    /** Cron entry point. */
    public static function run() {
        if (!wcpw_acquire_lock('fetch', 20 * MINUTE_IN_SECONDS)) {
            return;
        }
        $started = current_time('mysql', true);
        $reads_before = wcpw_reads_this_month();
        $counts = array('kols' => 0, 'fetched' => 0, 'inserted' => 0, 'skipped_rt' => 0);
        $errors = array();

        foreach (WCPW_KOLs::list_by_status('active') as $kol) {
            if (wcpw_budget_exhausted()) {
                $errors[] = 'Monthly X read cap reached — fetch stopped (remaining KOLs deferred).';
                break;
            }
            if (WCPW_Tweet_Source::backing_off()) {
                $errors[] = 'Rate-limit backoff active — fetch paused, will resume next run.';
                break;
            }
            $result = self::fetch_kol($kol->ID);
            if (is_wp_error($result)) {
                $errors[] = '@' . WCPW_KOLs::meta($kol->ID)['handle'] . ': ' . $result->get_error_message();
                continue;
            }
            $counts['kols']++;
            $counts['fetched']   += $result['fetched'];
            $counts['inserted']  += $result['inserted'];
            $counts['skipped_rt'] += $result['skipped_rt'];
        }

        wcpw_record_run('fetch', array(
            'started_at' => $started,
            'counts'     => $counts,
            'errors'     => $errors,
            'reads_used' => wcpw_reads_this_month() - $reads_before,
        ));
        wcpw_release_lock('fetch');

        // Chain analysis (§6): a single event; the analyzer self-reschedules
        // in chunks until the pending queue drains.
        if ($counts['inserted'] > 0 && !wp_next_scheduled('wcp_wiretap_analyze')) {
            wp_schedule_single_event(time() + 10, 'wcp_wiretap_analyze');
        }
    }

    /**
     * Fetch one KOL. Handles renamed handles (track by x_user_id, refresh
     * handle), and suspended/protected/deleted accounts (auto-pause + flag).
     *
     * @return array|WP_Error { fetched, inserted, skipped_rt }
     */
    public static function fetch_kol($kol_id) {
        $meta = WCPW_KOLs::meta($kol_id);

        // Resolve the user id on first fetch if the KOL was added by handle only.
        if (!$meta['x_user_id']) {
            $user = WCPW_Tweet_Source::resolve_user($meta['handle']);
            if (is_wp_error($user)) {
                if ($user->get_error_code() === 'user_not_found') {
                    WCPW_KOLs::set_status($kol_id, 'paused', 'Account not found (deleted or renamed)');
                }
                return $user;
            }
            update_post_meta($kol_id, '_wcpw_x_user_id', $user['id']);
            $meta['x_user_id'] = $user['id'];
            // Handle may have changed case/renamed — refresh from the API.
            if (!empty($user['username']) && strtolower($user['username']) !== strtolower($meta['handle'])) {
                update_post_meta($kol_id, '_wcpw_handle', $user['username']);
                wp_update_post(array('ID' => $kol_id, 'post_title' => '@' . $user['username']));
                $meta['handle'] = $user['username'];
            }
            if (!empty($user['protected'])) {
                WCPW_KOLs::set_status($kol_id, 'paused', 'Account is protected');
                return new WP_Error('protected', 'Account is protected — paused.');
            }
        }

        $start_time = '';
        if (!$meta['last_tweet_id']) {
            $lookback = (int) wcpw_get_setting('fetch_lookback_hours');
            $start_time = gmdate('Y-m-d\TH:i:s\Z', time() - $lookback * HOUR_IN_SECONDS);
        }

        $result = WCPW_Tweet_Source::fetch_user_tweets($meta['x_user_id'], $meta['last_tweet_id'], $start_time);
        if (is_wp_error($result)) {
            $status = $result->get_error_data();
            $code = is_array($status) && isset($status['status']) ? (int) $status['status'] : 0;
            if ($code === 403 || $result->get_error_code() === 'forbidden') {
                WCPW_KOLs::set_status($kol_id, 'paused', 'Account suspended or inaccessible');
            }
            return $result;
        }

        $include_rts = (bool) wcpw_get_setting('include_retweets');
        $inserted = 0;
        $skipped_rt = 0;
        $max_id = $meta['last_tweet_id'];

        foreach ($result['tweets'] as $tweet) {
            // Track the newest id even for skipped tweets so we never refetch them.
            if (!$max_id || strcmp(str_pad($tweet['id'], 25, '0', STR_PAD_LEFT), str_pad((string) $max_id, 25, '0', STR_PAD_LEFT)) > 0) {
                $max_id = $tweet['id'];
            }

            $ref_type = '';
            $quoted_text = '';
            if (!empty($tweet['referenced_tweets'])) {
                foreach ($tweet['referenced_tweets'] as $ref) {
                    $ref_type = $ref['type']; // retweeted | quoted | replied_to
                    if ($ref['type'] === 'quoted' && isset($result['quoted'][$ref['id']])) {
                        $quoted_text = $result['quoted'][$ref['id']];
                    }
                }
            }

            // Exclude pure RTs unless configured (§6) — they carry no new signal text.
            if ($ref_type === 'retweeted' && !$include_rts) {
                $skipped_rt++;
                continue;
            }

            $text = $tweet['text'];
            if ($quoted_text) {
                // Quoted-tweet text appended with a marker (§4.1) — single
                // tweets out of thread context routinely misclassify.
                $text .= "\n\n[QUOTED TWEET]: " . $quoted_text;
            }

            $is_new = WCPW_Tweet_Repo::upsert(array(
                'tweet_id'        => $tweet['id'],
                'kol_id'          => $kol_id,
                'author_handle'   => $meta['handle'],
                'text'            => $text,
                'created_at'      => isset($tweet['created_at']) ? gmdate('Y-m-d H:i:s', strtotime($tweet['created_at'])) : gmdate('Y-m-d H:i:s'),
                'conversation_id' => isset($tweet['conversation_id']) ? $tweet['conversation_id'] : '',
                'referenced_type' => $ref_type ?: 'original',
                'entities_json'   => isset($tweet['entities']) ? wp_json_encode($tweet['entities']) : '',
                'metrics_json'    => isset($tweet['public_metrics']) ? wp_json_encode($tweet['public_metrics']) : '',
            ));
            if ($is_new) {
                $inserted++;
            }
        }

        if ($max_id) {
            update_post_meta($kol_id, '_wcpw_last_tweet_id', $max_id);
        }
        update_post_meta($kol_id, '_wcpw_last_fetched_at', current_time('mysql', true));

        return array(
            'fetched'    => count($result['tweets']),
            'inserted'   => $inserted,
            'skipped_rt' => $skipped_rt,
        );
    }

    /** Import all members of an X List as active KOLs (F2), deduped on x_user_id. */
    public static function import_list($list_id) {
        $members = WCPW_Tweet_Source::get_list_members($list_id);
        if (is_wp_error($members)) {
            return $members;
        }
        $added = 0;
        foreach ($members as $user) {
            $result = WCPW_KOLs::create($user['username'], array(
                'x_user_id'   => $user['id'],
                'list_source' => (string) $list_id,
            ));
            if (!is_wp_error($result)) {
                $added++;
            }
        }
        return $added;
    }
}
