<?php
/**
 * Nightly rollup → daily aggregates table (§4.4), plus emerging/resurgent
 * detection (§F5). Makes all velocity queries O(days), not O(tweets).
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPW_Themes {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'wcp_wiretap_daily_stats';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $table = self::table();
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            stat_date DATE NOT NULL,
            object_type VARCHAR(10) NOT NULL DEFAULT 'ticker',
            object_key VARCHAR(64) NOT NULL DEFAULT '',
            mention_count INT NOT NULL DEFAULT 0,
            distinct_kols INT NOT NULL DEFAULT 0,
            trust_weighted_mentions FLOAT NOT NULL DEFAULT 0,
            new_calls INT NOT NULL DEFAULT 0,
            reinforcements INT NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY stat_key (stat_date,object_type,object_key)
        ) {$charset};");
    }

    /**
     * Nightly cron (§6): daily_stats aggregation for the last 2 days
     * (yesterday finalized, today-so-far refreshed on re-runs), emerging
     * detection, corpus discovery scan, tweet pruning.
     */
    public static function run_rollup() {
        if (!wcpw_acquire_lock('rollup', 15 * MINUTE_IN_SECONDS)) {
            return;
        }
        $started = current_time('mysql', true);
        $errors = array();

        $days_written = 0;
        foreach (array(gmdate('Y-m-d'), gmdate('Y-m-d', strtotime('-1 day'))) as $day) {
            self::rollup_day($day);
            $days_written++;
        }

        $emerging = self::detect_emerging();
        update_option('wcpw_emerging', $emerging, false);

        // Optional Telegram on newly-crossed thresholds (off by default, §F5).
        if (wcpw_get_setting('notify_emerging') && !empty($emerging['new_this_run'])) {
            foreach ($emerging['new_this_run'] as $entry) {
                WCPW_Telegram::notify(sprintf(
                    '📈 Emerging %s: %s — %d mentions/7d (%.1fx prior), %d distinct KOLs',
                    $entry['object_type'], $entry['object_key'], $entry['mentions_7d'], $entry['velocity'], $entry['distinct_kols']
                ));
            }
        }

        // Corpus discovery scan (F3.1) and retention pruning (§4.1) ride the
        // nightly job per §6.
        $suggested = WCPW_Discovery::corpus_scan();
        if (is_wp_error($suggested)) {
            $errors[] = 'discovery: ' . $suggested->get_error_message();
            $suggested = 0;
        }
        $pruned = WCPW_Tweet_Repo::prune();

        wcpw_record_run('rollup', array(
            'started_at' => $started,
            'counts'     => array(
                'days_rolled'    => $days_written,
                'emerging'       => count($emerging['entries']),
                'kols_suggested' => $suggested,
                'tweets_pruned'  => $pruned,
            ),
            'errors'     => $errors,
        ));
        wcpw_release_lock('rollup');
    }

    /**
     * Aggregate one UTC day from per-tweet signals into daily_stats.
     */
    public static function rollup_day($day_ymd) {
        global $wpdb;
        $start = $day_ymd . ' 00:00:00';
        $end   = $day_ymd . ' 23:59:59';

        $table = WCPW_Tweet_Repo::table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT kol_id, signals_json, created_at FROM {$table}
             WHERE analysis_status = 'analyzed' AND created_at BETWEEN %s AND %s
               AND signals_json IS NOT NULL AND signals_json != ''",
            $start, $end
        ), ARRAY_A);

        // buckets[type][key] = { mentions, kols:set, weighted }
        $buckets = array('ticker' => array(), 'theme' => array());
        foreach ($rows as $row) {
            $payload = json_decode($row['signals_json'], true);
            if (!is_array($payload)) {
                continue;
            }
            $weight = WCPW_KOLs::trust_weight((int) $row['kol_id']);
            foreach ((array) (isset($payload['signals']) ? $payload['signals'] : array()) as $signal) {
                if (empty($signal['ticker'])) {
                    continue;
                }
                self::bucket($buckets['ticker'], strtoupper($signal['ticker']), (int) $row['kol_id'], $weight);
            }
            foreach ((array) (isset($payload['themes']) ? $payload['themes'] : array()) as $theme) {
                $key = strtolower(trim((string) $theme));
                if ($key !== '') {
                    self::bucket($buckets['theme'], $key, (int) $row['kol_id'], $weight);
                }
            }
        }

        // New calls / reinforcements per ticker for the day, from recs.
        $new_calls = array();
        $reinforcements = array();
        foreach (WCPW_Recommendation_Repo::created_since($start) as $rec) {
            if ($rec->post_date_gmt > $end) {
                continue;
            }
            $ticker = get_post_meta($rec->ID, '_wcpw_ticker', true);
            $new_calls[$ticker] = isset($new_calls[$ticker]) ? $new_calls[$ticker] + 1 : 1;
        }
        // Reinforcements dated by last_reinforced_at falling on this day.
        $meta_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm2.meta_value AS ticker FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = pm.post_id AND pm2.meta_key = '_wcpw_ticker'
             WHERE pm.meta_key = '_wcpw_last_reinforced_at' AND pm.meta_value BETWEEN %s AND %s",
            $start, $end
        ), ARRAY_A);
        foreach ($meta_rows as $m) {
            $reinforcements[$m['ticker']] = isset($reinforcements[$m['ticker']]) ? $reinforcements[$m['ticker']] + 1 : 1;
        }

        $stats_table = self::table();
        foreach ($buckets as $type => $keys) {
            foreach ($keys as $key => $agg) {
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$stats_table}
                     (stat_date, object_type, object_key, mention_count, distinct_kols, trust_weighted_mentions, new_calls, reinforcements)
                     VALUES (%s, %s, %s, %d, %d, %f, %d, %d)
                     ON DUPLICATE KEY UPDATE
                       mention_count = VALUES(mention_count),
                       distinct_kols = VALUES(distinct_kols),
                       trust_weighted_mentions = VALUES(trust_weighted_mentions),
                       new_calls = VALUES(new_calls),
                       reinforcements = VALUES(reinforcements)",
                    $day_ymd, $type, $key,
                    $agg['mentions'], count($agg['kols']), $agg['weighted'],
                    ($type === 'ticker' && isset($new_calls[$key])) ? $new_calls[$key] : 0,
                    ($type === 'ticker' && isset($reinforcements[$key])) ? $reinforcements[$key] : 0
                ));
            }
        }
    }

    private static function bucket(array &$bucket, $key, $kol_id, $weight) {
        if (!isset($bucket[$key])) {
            $bucket[$key] = array('mentions' => 0, 'kols' => array(), 'weighted' => 0.0);
        }
        $bucket[$key]['mentions']++;
        $bucket[$key]['kols'][$kol_id] = true;
        $bucket[$key]['weighted'] += $weight;
    }

    /**
     * Trust-weighted mention sum for a window: [now - from_days, now - to_days].
     * Used by earliness velocity and the emerging rule.
     */
    public static function weighted_mentions($object_type, $object_key, $from_days, $to_days = 0) {
        global $wpdb;
        $table = self::table();
        $from = gmdate('Y-m-d', time() - $from_days * DAY_IN_SECONDS);
        $to   = gmdate('Y-m-d', time() - $to_days * DAY_IN_SECONDS);
        return (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trust_weighted_mentions),0) FROM {$table}
             WHERE object_type = %s AND object_key = %s AND stat_date > %s AND stat_date <= %s",
            $object_type, strtolower($object_type) === 'ticker' ? strtoupper($object_key) : $object_key, $from, $to
        ));
    }

    /**
     * Emerging detection (§F5). Default rule, all thresholds configurable:
     *  - ≥ emerging_min_kols distinct KOLs in trailing 7d, AND
     *  - trailing-7d mentions ≥ velocity_mult × prior 7d, AND
     *  - first-ever mention within emerging_max_age days (else "resurgent").
     *
     * @return array { entries: [], new_this_run: [], computed_at }
     */
    public static function detect_emerging() {
        global $wpdb;
        $table = self::table();
        $min_kols = (int) wcpw_get_setting('emerging_min_kols');
        $mult     = (float) wcpw_get_setting('emerging_velocity_mult');
        $max_age  = (int) wcpw_get_setting('emerging_max_age');

        $d7  = gmdate('Y-m-d', time() - 7 * DAY_IN_SECONDS);
        $d14 = gmdate('Y-m-d', time() - 14 * DAY_IN_SECONDS);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT object_type, object_key,
                    SUM(CASE WHEN stat_date > %s THEN mention_count ELSE 0 END) AS m7,
                    SUM(CASE WHEN stat_date > %s AND stat_date <= %s THEN mention_count ELSE 0 END) AS m7prior,
                    SUM(CASE WHEN stat_date > %s THEN trust_weighted_mentions ELSE 0 END) AS w7,
                    MAX(CASE WHEN stat_date > %s THEN distinct_kols ELSE 0 END) AS max_daily_kols
             FROM {$table}
             WHERE stat_date > %s
             GROUP BY object_type, object_key",
            $d7, $d14, $d7, $d7, $d7, $d14
        ), ARRAY_A);

        $previous = get_option('wcpw_emerging', array());
        $previous_keys = array();
        if (!empty($previous['entries'])) {
            foreach ($previous['entries'] as $e) {
                $previous_keys[$e['object_type'] . ':' . $e['object_key']] = true;
            }
        }

        $entries = array();
        $new_this_run = array();
        foreach ($rows as $row) {
            $m7 = (int) $row['m7'];
            $m7prior = (int) $row['m7prior'];
            if ($m7 < 1) {
                continue;
            }

            // Distinct KOLs across the trailing 7d — computed from the corpus
            // (per-day distincts can't be unioned).
            $distinct = self::distinct_kols_7d($row['object_type'], $row['object_key']);
            if ($distinct < $min_kols) {
                continue;
            }
            if ($m7 < $mult * max(1, $m7prior)) {
                continue;
            }

            $first = $wpdb->get_var($wpdb->prepare(
                "SELECT MIN(stat_date) FROM {$table} WHERE object_type = %s AND object_key = %s",
                $row['object_type'], $row['object_key']
            ));
            $age_days = $first ? (time() - strtotime($first)) / DAY_IN_SECONDS : 0;
            $label = ($age_days <= $max_age) ? 'emerging' : 'resurgent';

            $velocity = $m7 / max(1, $m7prior);
            $entry = array(
                'object_type'  => $row['object_type'],
                'object_key'   => $row['object_key'],
                'label'        => $label,
                'mentions_7d'  => $m7,
                'velocity'     => round($velocity, 2),
                'distinct_kols' => $distinct,
                // Rank: velocity × distinct-KOL count × trust weighting (§F5)
                'score'        => round($velocity * $distinct * max(0.1, (float) $row['w7']), 2),
                'sparkline'    => self::sparkline($row['object_type'], $row['object_key'], 14),
                'first_seen'   => $first,
            );
            if ($row['object_type'] === 'ticker' && $label === 'emerging') {
                $entry['earliness'] = WCPW_Earliness::compute($row['object_key']);
            }
            $entries[] = $entry;

            $k = $row['object_type'] . ':' . $row['object_key'];
            if ($label === 'emerging' && !isset($previous_keys[$k])) {
                $new_this_run[] = $entry;
            }
        }

        usort($entries, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return array(
            'entries'      => array_slice($entries, 0, 30),
            'new_this_run' => $new_this_run,
            'computed_at'  => gmdate('c'),
        );
    }

    private static function distinct_kols_7d($object_type, $object_key) {
        $since = gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS);
        if ($object_type === 'ticker') {
            $rows = WCPW_Tweet_Repo::signal_rows_since($since, $object_key);
        } else {
            // Themes: scan all signal rows for the theme key.
            $rows = array();
            foreach (WCPW_Tweet_Repo::signal_rows_since($since) as $row) {
                $payload = json_decode($row['signals_json'], true);
                if (!empty($payload['themes']) && in_array($object_key, array_map('strtolower', (array) $payload['themes']), true)) {
                    $rows[] = $row;
                }
            }
        }
        $kols = array();
        foreach ($rows as $row) {
            $kols[(int) $row['kol_id']] = true;
        }
        return count($kols);
    }

    /** Daily mention counts for the last N days (dashboard sparkline). */
    public static function sparkline($object_type, $object_key, $days = 14) {
        global $wpdb;
        $table = self::table();
        $since = gmdate('Y-m-d', time() - $days * DAY_IN_SECONDS);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT stat_date, mention_count FROM {$table}
             WHERE object_type = %s AND object_key = %s AND stat_date > %s
             ORDER BY stat_date ASC",
            $object_type, $object_key, $since
        ), ARRAY_A);
        $by_date = array();
        foreach ($rows as $r) {
            $by_date[$r['stat_date']] = (int) $r['mention_count'];
        }
        $out = array();
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', time() - $i * DAY_IN_SECONDS);
            $out[] = isset($by_date[$day]) ? $by_date[$day] : 0;
        }
        return $out;
    }
}
