<?php
/**
 * AI log — every LLM call with prompt + input/output snapshots, plus human
 * edit diffs on recommendations/plans (§4.6).
 *
 * GUARDRAIL (§12.5): every LLM call is logged here via WCPW_LLM; human edits
 * are logged with before/after diffs. Nothing AI-related bypasses this table.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPW_AI_Log {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'wcp_wiretap_ai_log';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $table = self::table();
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            kind VARCHAR(32) NOT NULL DEFAULT '',
            prompt LONGTEXT,
            input_snapshot LONGTEXT,
            output_snapshot LONGTEXT,
            model VARCHAR(64) DEFAULT '',
            tokens_in INT DEFAULT 0,
            tokens_out INT DEFAULT 0,
            created_at DATETIME DEFAULT NULL,
            decision VARCHAR(255) DEFAULT '',
            related_object_id BIGINT UNSIGNED DEFAULT 0,
            PRIMARY KEY  (id),
            KEY kind (kind),
            KEY related_object_id (related_object_id),
            KEY created_at (created_at)
        ) {$charset};");
    }

    /**
     * Insert a log row. Returns the log id.
     *
     * @param string $kind classification|digest|plan_extraction|checkin|discovery_fit|ticker_resolution|human_edit
     */
    public static function insert($kind, $prompt, $input, $output, $model = '', $tokens_in = 0, $tokens_out = 0, $related_object_id = 0) {
        global $wpdb;
        $wpdb->insert(self::table(), array(
            'kind'              => $kind,
            'prompt'            => (string) $prompt,
            'input_snapshot'    => is_string($input) ? $input : wp_json_encode($input),
            'output_snapshot'   => is_string($output) ? $output : wp_json_encode($output),
            'model'             => $model,
            'tokens_in'         => (int) $tokens_in,
            'tokens_out'        => (int) $tokens_out,
            'created_at'        => current_time('mysql', true),
            'related_object_id' => (int) $related_object_id,
        ));
        return (int) $wpdb->insert_id;
    }

    /** Record the human decision (accepted / dismissed / edited / …) on a log row. */
    public static function set_decision($log_id, $decision) {
        global $wpdb;
        $wpdb->update(self::table(), array('decision' => (string) $decision), array('id' => (int) $log_id));
    }

    /** Attach the created object (rec/plan id) after the fact. */
    public static function set_related($log_id, $object_id) {
        global $wpdb;
        $wpdb->update(self::table(), array('related_object_id' => (int) $object_id), array('id' => (int) $log_id));
    }

    /**
     * Log a human edit as a before/after diff (§F1: edits store a diff).
     */
    public static function log_edit($object_id, array $before, array $after) {
        $diff = array();
        foreach ($after as $key => $value) {
            $old = isset($before[$key]) ? $before[$key] : null;
            if ($old !== $value) {
                $diff[$key] = array('before' => $old, 'after' => $value);
            }
        }
        if (empty($diff)) {
            return 0;
        }
        return self::insert('human_edit', '', $before, $diff, '', 0, 0, $object_id);
    }

    public static function recent($limit = 100, $kind = '') {
        global $wpdb;
        $table = self::table();
        if ($kind) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE kind = %s ORDER BY id DESC LIMIT %d", $kind, $limit
            ), ARRAY_A);
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit
        ), ARRAY_A);
    }

    /** Total tokens used this calendar month (for the budget meter). */
    public static function tokens_this_month() {
        global $wpdb;
        $table = self::table();
        $start = gmdate('Y-m-01 00:00:00');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(SUM(tokens_in),0) AS tin, COALESCE(SUM(tokens_out),0) AS tout
             FROM {$table} WHERE created_at >= %s", $start
        ), ARRAY_A);
        return array('in' => (int) $row['tin'], 'out' => (int) $row['tout']);
    }
}
