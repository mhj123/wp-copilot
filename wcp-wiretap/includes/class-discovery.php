<?php
/**
 * KOL discovery (§F3) — three mechanisms, cheapest first:
 *  1. corpus_scan()       free, automatic (nightly)
 *  2. graph_triangulate() on-demand, budgeted (cost-confirmed in UI)
 *  3. earliest_callers()  on-demand, budgeted archive search + free corpus fallback
 *
 * Suggestions land as wcp_kol posts with tracking_status=suggested; promoting
 * to active (the only path that spends ongoing polling budget) or dismissing
 * is a human action via REST.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPW_Discovery {

    /**
     * F3.1 — Corpus signals: accounts your tracked KOLs repeatedly retweet,
     * quote, or reply to. Thresholds: ≥ discovery_min_interactions
     * interactions from ≥ 2 distinct tracked KOLs in 30d.
     *
     * @return int suggestions created
     */
    public static function corpus_scan() {
        $since = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
        $rows = WCPW_Tweet_Repo::rows_since($since);

        // Build the set of handles we already track/know, lowercased.
        $known = array();
        foreach (WCPW_KOLs::all() as $kol) {
            $known[strtolower(WCPW_KOLs::meta($kol->ID)['handle'])] = true;
        }

        // handle => { interactions, kols:set }
        $candidates = array();
        foreach ($rows as $row) {
            $entities = json_decode((string) $row['entities_json'], true);
            $mentioned = array();
            if (!empty($entities['mentions'])) {
                foreach ($entities['mentions'] as $mention) {
                    if (!empty($mention['username'])) {
                        $mentioned[strtolower($mention['username'])] = true;
                    }
                }
            }
            // Interaction = quote/reply/retweet referencing another account
            // (the referenced author appears in mentions for RT/QT/replies).
            if (!in_array($row['referenced_type'], array('quoted', 'replied_to', 'retweeted'), true)) {
                continue;
            }
            foreach (array_keys($mentioned) as $handle) {
                if (isset($known[$handle])) {
                    continue;
                }
                if (!isset($candidates[$handle])) {
                    $candidates[$handle] = array('interactions' => 0, 'kols' => array());
                }
                $candidates[$handle]['interactions']++;
                $candidates[$handle]['kols'][(int) $row['kol_id']] = true;
            }
        }

        $min_interactions = (int) wcpw_get_setting('discovery_min_interactions');
        $created = 0;
        foreach ($candidates as $handle => $agg) {
            if ($agg['interactions'] < $min_interactions || count($agg['kols']) < 2) {
                continue;
            }
            $kol_names = array();
            foreach (array_keys($agg['kols']) as $kol_id) {
                $kol_names[] = '@' . WCPW_KOLs::meta($kol_id)['handle'];
            }
            $result = WCPW_KOLs::create($handle, array(
                'tracking_status'  => 'suggested',
                'discovery_source' => 'corpus',
                'discovery_reason' => sprintf(
                    '%d interactions in 30d from %s',
                    $agg['interactions'], implode(', ', array_slice($kol_names, 0, 4))
                ),
            ));
            if (!is_wp_error($result) && get_post_meta($result, '_wcpw_tracking_status', true) === 'suggested') {
                $created++;
            }
        }
        return $created;
    }

    /**
     * F3.2 — Graph triangulation: fetch one page of a tier-1 KOL's following
     * list (capped), cache it, then score candidates by how many of your
     * tier-1 KOLs' cached followings contain them, plus an LLM topical-fit
     * pass over bios.
     *
     * Cost note: up to 1000 user reads — the UI shows the estimate and
     * requires confirmation before calling this.
     *
     * @return array|WP_Error { fetched, suggested, candidates: [] }
     */
    public static function graph_triangulate($kol_id) {
        $meta = WCPW_KOLs::meta($kol_id);
        if (!$meta['x_user_id']) {
            return new WP_Error('no_user_id', 'KOL has no resolved X user id yet — run a fetch first.');
        }

        $following = WCPW_Tweet_Source::get_following($meta['x_user_id'], 1000);
        if (is_wp_error($following)) {
            return $following;
        }

        // Cache ids + bios on the KOL for cross-triangulation.
        $cache = array();
        foreach ($following as $user) {
            $cache[$user['id']] = array(
                'username'  => $user['username'],
                'name'      => isset($user['name']) ? $user['name'] : '',
                'bio'       => isset($user['description']) ? $user['description'] : '',
                'followers' => isset($user['public_metrics']['followers_count']) ? (int) $user['public_metrics']['followers_count'] : 0,
            );
        }
        update_post_meta($kol_id, '_wcpw_following_cache', wp_json_encode($cache));
        update_post_meta($kol_id, '_wcpw_following_fetched_at', current_time('mysql', true));

        // Triangulate across ALL tier-1 KOLs with cached followings.
        $tier1 = WCPW_KOLs::tier1();
        $tier1_total = 0;
        $counts = array();      // x_user_id => triangulation count
        $profiles = array();    // x_user_id => profile
        foreach ($tier1 as $kol) {
            $cached = json_decode((string) get_post_meta($kol->ID, '_wcpw_following_cache', true), true);
            if (!is_array($cached) || empty($cached)) {
                continue;
            }
            $tier1_total++;
            foreach ($cached as $uid => $profile) {
                $counts[$uid] = isset($counts[$uid]) ? $counts[$uid] + 1 : 1;
                $profiles[$uid] = $profile;
            }
        }

        $known = array();
        foreach (WCPW_KOLs::all() as $kol) {
            $known[strtolower(WCPW_KOLs::meta($kol->ID)['handle'])] = true;
        }

        arsort($counts);
        $suggested = 0;
        $candidates = array();
        foreach ($counts as $uid => $count) {
            if ($count < 2 || count($candidates) >= 15) {
                continue;
            }
            $profile = $profiles[$uid];
            if (isset($known[strtolower($profile['username'])])) {
                continue;
            }

            // LLM topical fit over bio (§7.5) — small fast model.
            $fit = self::topical_fit($profile);
            $candidates[] = array_merge($profile, array(
                'triangulation' => $count,
                'tier1_total'   => $tier1_total,
                'fit'           => $fit['fit'],
                'fit_reason'    => $fit['reason'],
            ));

            if ($fit['fit'] >= 0.5) {
                $result = WCPW_KOLs::create($profile['username'], array(
                    'x_user_id'        => (string) $uid,
                    'tracking_status'  => 'suggested',
                    'discovery_source' => 'graph',
                    'discovery_reason' => sprintf(
                        'Followed by %d of your %d tier-1 KOLs; %s',
                        $count, $tier1_total, $fit['reason'] ?: 'topical fit ' . $fit['fit']
                    ),
                ));
                if (!is_wp_error($result)) {
                    $suggested++;
                }
            }
        }

        return array(
            'fetched'    => count($following),
            'suggested'  => $suggested,
            'candidates' => $candidates,
        );
    }

    /** §7.5 discovery topical fit. */
    private static function topical_fit(array $profile) {
        $default = array('fit' => 0.0, 'focus_areas' => array(), 'reason' => '');
        if (empty($profile['bio'])) {
            return $default;
        }
        $result = WCPW_LLM::call(
            'discovery_fit',
            'Score how well this X account fits an investment-focused KOL watchlist (crypto and equities '
            . 'trading/analysis). Return ONLY JSON: {"fit":0.0,"focus_areas":["string"],"reason":"string"} '
            . 'where fit is 0..1.',
            "Handle: @{$profile['username']}\nName: {$profile['name']}\nFollowers: {$profile['followers']}\nBio: {$profile['bio']}",
            array('tier' => 'fast', 'max_tokens' => 300, 'required' => array('fit'))
        );
        if (is_wp_error($result)) {
            return $default;
        }
        return array(
            'fit'         => max(0, min(1, (float) $result['data']['fit'])),
            'focus_areas' => isset($result['data']['focus_areas']) ? (array) $result['data']['focus_areas'] : array(),
            'reason'      => isset($result['data']['reason']) ? (string) $result['data']['reason'] : '',
        );
    }

    /**
     * F3.3 — Earliest callers of a ticker via capped full-archive search.
     * "Early" is ranked relative to the price move, not just chronology:
     * each author gets price-at-first-mention vs price-now.
     *
     * Cost: up to earliest_search_max_results reads — UI shows the estimate
     * and requires confirmation. On 403 (API tier), falls back to corpus.
     *
     * @return array { source: archive|corpus, results: [], backfilled: bool }
     */
    public static function earliest_callers($ticker, $start_date, $end_date) {
        $ticker = strtoupper(ltrim(trim($ticker), '$'));
        $cap = (int) wcpw_get_setting('earliest_search_max_results');

        $tweets = WCPW_Tweet_Source::search_all(
            '$' . $ticker . ' -is:retweet',
            $start_date ? gmdate('Y-m-d\T00:00:00\Z', strtotime($start_date)) : '',
            $end_date ? gmdate('Y-m-d\T23:59:59\Z', strtotime($end_date)) : '',
            $cap
        );

        if (is_wp_error($tweets)) {
            // Free fallback: earliest caller within the ingested corpus (§F3).
            $row = WCPW_Tweet_Repo::earliest_mention($ticker);
            $results = array();
            if ($row) {
                $results[] = array(
                    'handle'        => $row['author_handle'],
                    'first_mention' => $row['created_at'],
                    'text'          => mb_substr($row['text'], 0, 200),
                    'price_then'    => WCPW_Price_Source::get_historical_price($ticker, substr($row['created_at'], 0, 10)),
                    'price_now'     => WCPW_Price_Source::get_price($ticker),
                    'followers'     => null,
                );
            }
            return array(
                'source'   => 'corpus',
                'error'    => $tweets->get_error_message(),
                'results'  => $results,
                'backfilled' => false,
            );
        }

        // Rank authors by earliest mention.
        $by_author = array();
        foreach ($tweets as $tweet) {
            $author = isset($tweet['author']['username']) ? $tweet['author']['username'] : '';
            if (!$author) {
                continue;
            }
            $ts = isset($tweet['created_at']) ? strtotime($tweet['created_at']) : 0;
            if (!isset($by_author[$author]) || $ts < $by_author[$author]['ts']) {
                $by_author[$author] = array(
                    'ts'        => $ts,
                    'text'      => $tweet['text'],
                    'followers' => isset($tweet['author']['public_metrics']['followers_count'])
                        ? (int) $tweet['author']['public_metrics']['followers_count'] : null,
                );
            }
        }
        uasort($by_author, function ($a, $b) {
            return $a['ts'] <=> $b['ts'];
        });

        $price_now = WCPW_Price_Source::get_price($ticker);
        $results = array();
        $backfilled = false;
        foreach (array_slice($by_author, 0, 20, true) as $handle => $data) {
            $date = gmdate('Y-m-d', $data['ts']);
            $results[] = array(
                'handle'        => $handle,
                'first_mention' => gmdate('Y-m-d H:i', $data['ts']),
                'text'          => mb_substr($data['text'], 0, 200),
                'price_then'    => WCPW_Price_Source::get_historical_price($ticker, $date),
                'price_now'     => $price_now,
                'followers'     => $data['followers'],
            );
        }

        // Backfill the earliness first-call anchor (§5 corpus-blindness repair).
        if ($results) {
            $earliest = reset($results);
            WCPW_Ticker_Registry::maybe_set_first_call(
                $ticker,
                gmdate('Y-m-d H:i:s', strtotime($earliest['first_mention'])),
                $earliest['price_then']
            );
            $backfilled = true;
        }

        return array('source' => 'archive', 'results' => $results, 'backfilled' => $backfilled);
    }
}
