<?php
/**
 * Raw tweets table (§4.1) — idempotent upsert, thread siblings, retention pruning.
 *
 * One deliberate addition over the PRD column list: `signals_json` stores the
 * per-tweet classification output so the nightly rollup (§4.4) and the
 * earliness diffusion inputs (§5) have queryable per-tweet signals; tweets
 * are table rows, not posts, so taxonomies can't carry this.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPW_Tweet_Repo {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'wcp_wiretap_tweets';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $table = self::table();
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tweet_id VARCHAR(32) NOT NULL,
            kol_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            author_handle VARCHAR(64) NOT NULL DEFAULT '',
            text TEXT,
            created_at DATETIME DEFAULT NULL,
            conversation_id VARCHAR(32) DEFAULT '',
            referenced_type VARCHAR(16) DEFAULT NULL,
            entities_json LONGTEXT,
            metrics_json LONGTEXT,
            signals_json LONGTEXT,
            analysis_status VARCHAR(16) NOT NULL DEFAULT 'pending',
            fetched_at DATETIME DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY tweet_id (tweet_id),
            KEY kol_id (kol_id),
            KEY created_at (created_at),
            KEY analysis_status (analysis_status),
            KEY conversation_id (conversation_id)
        ) {$charset};");
    }

    /**
     * Idempotent insert keyed on tweet_id (re-running a fetch produces zero
     * duplicates — M1 acceptance). Returns true if a new row was inserted.
     */
    public static function upsert(array $row) {
        global $wpdb;
        $table = self::table();
        $result = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table}
             (tweet_id, kol_id, author_handle, text, created_at, conversation_id, referenced_type, entities_json, metrics_json, analysis_status, fetched_at)
             VALUES (%s, %d, %s, %s, %s, %s, %s, %s, %s, 'pending', %s)",
            $row['tweet_id'],
            (int) $row['kol_id'],
            $row['author_handle'],
            $row['text'],
            $row['created_at'],
            isset($row['conversation_id']) ? $row['conversation_id'] : '',
            isset($row['referenced_type']) ? $row['referenced_type'] : null,
            isset($row['entities_json']) ? $row['entities_json'] : '',
            isset($row['metrics_json']) ? $row['metrics_json'] : '',
            current_time('mysql', true)
        ));
        return $result === 1;
    }

    public static function get_by_tweet_id($tweet_id) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE tweet_id = %s", $tweet_id), ARRAY_A);
    }

    /** Batch of pending tweets for the chunked analyzer. */
    public static function pending($limit = 10) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE analysis_status = 'pending' ORDER BY id ASC LIMIT %d", $limit
        ), ARRAY_A);
    }

    public static function pending_count() {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE analysis_status = 'pending'");
    }

    /**
     * Thread siblings for context packs (§F1): other stored tweets sharing the
     * conversation_id, oldest first.
     */
    public static function thread_siblings($conversation_id, $exclude_tweet_id = '', $limit = 10) {
        global $wpdb;
        if (!$conversation_id) {
            return array();
        }
        $table = self::table();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE conversation_id = %s AND tweet_id != %s ORDER BY created_at ASC LIMIT %d",
            $conversation_id, $exclude_tweet_id, $limit
        ), ARRAY_A);
    }

    public static function set_status($id, $status, $signals_json = null) {
        global $wpdb;
        $data = array('analysis_status' => $status);
        if ($signals_json !== null) {
            $data['signals_json'] = is_string($signals_json) ? $signals_json : wp_json_encode($signals_json);
        }
        $wpdb->update(self::table(), $data, array('id' => (int) $id));
    }

    /**
     * Analyzed tweets in a window whose signals mention a ticker symbol.
     * Used by earliness (diffusion/originator inputs) and digest pre-aggregation.
     */
    public static function signal_rows_since($since_gmt, $ticker = '') {
        global $wpdb;
        $table = self::table();
        if ($ticker) {
            // signals_json stores canonical symbols as "ticker":"SOL"
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE analysis_status = 'analyzed' AND created_at >= %s
                   AND signals_json LIKE %s",
                $since_gmt, '%' . $wpdb->esc_like('"ticker":"' . strtoupper($ticker) . '"') . '%'
            ), ARRAY_A);
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE analysis_status = 'analyzed' AND created_at >= %s
               AND signals_json IS NOT NULL AND signals_json != '' AND signals_json != '[]'",
            $since_gmt
        ), ARRAY_A);
    }

    /** All tweets in a window (corpus discovery scan reads entities/references). */
    public static function rows_since($since_gmt) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE created_at >= %s", $since_gmt
        ), ARRAY_A);
    }

    /** Earliest stored mention of a ticker (free fallback for F3.3). */
    public static function earliest_mention($ticker) {
        global $wpdb;
        $table = self::table();
        $like = '%' . $wpdb->esc_like('"ticker":"' . strtoupper($ticker) . '"') . '%';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE signals_json LIKE %s ORDER BY created_at ASC LIMIT 1", $like
        ), ARRAY_A);
    }

    /**
     * Retention pruning (§4.1): delete rows older than the cutoff EXCEPT any
     * tweet referenced by a recommendation or trade plan.
     */
    public static function prune() {
        global $wpdb;
        $days = (int) wcpw_get_setting('tweet_retention_days');
        if ($days <= 0) {
            return 0;
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);

        // Collect protected tweet ids: rec source tweets + reinforcing tweets.
        $protected = array();
        $meta_rows = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wcpw_source_tweet_id' AND meta_value != ''"
        );
        foreach ($meta_rows as $v) {
            $protected[$v] = true;
        }
        $reinforce_rows = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wcpw_reinforcing_tweet_ids' AND meta_value != ''"
        );
        foreach ($reinforce_rows as $json) {
            foreach ((array) json_decode($json, true) as $tid) {
                $protected[$tid] = true;
            }
        }

        $table = self::table();
        if (empty($protected)) {
            return (int) $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $cutoff));
        }
        $placeholders = implode(',', array_fill(0, count($protected), '%s'));
        $params = array_merge(array($cutoff), array_keys($protected));
        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s AND tweet_id NOT IN ({$placeholders})", $params
        ));
    }
}
