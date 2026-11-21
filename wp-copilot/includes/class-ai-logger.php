<?php
/**
 * AI Action Logger
 *
 * Logs all AI interactions for auditability
 * AI NEVER writes directly to database - all outputs are proposals
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_AI_Logger {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Log an AI action.
     *
     * Two call shapes are accepted, because both exist across the codebase
     * and a mismatch here used to fail silently — the row was written with
     * no type, model, prompt or context, which defeats the audit log:
     *
     *     log_action('chat', array('model' => ..., 'output' => ...));
     *     log_action(array('action_type' => 'chat', 'model' => ..., 'output_snapshot' => ...));
     *
     * @param string|array $action_type Action type, or the whole record as one array.
     * @param array        $data {
     *     @type string       $action_type     Only in the single-array form.
     *     @type string       $model           AI model used.
     *     @type string       $prompt          Prompt sent to AI.
     *     @type array|string $input_context   Context sent to AI (array, or already-encoded JSON).
     *     @type array|string $output          AI response. Alias: output_snapshot.
     *     @type int          $context_post_id Related post ID (if applicable).
     *     @type int          $user_id         Defaults to the current user.
     * }
     * @return string Action ID
     */
    public function log_action($action_type, $data = array()) {
        global $wpdb;

        if (is_array($action_type)) {
            $data        = $action_type;
            $action_type = isset($data['action_type']) ? $data['action_type'] : '';
        }
        if (!is_array($data)) {
            $data = array();
        }

        $action_type = is_scalar($action_type) ? (string) $action_type : '';
        if ($action_type === '') {
            $action_type = 'unknown';
        }

        $output = null;
        if (array_key_exists('output', $data)) {
            $output = $data['output'];
        } elseif (array_key_exists('output_snapshot', $data)) {
            $output = $data['output_snapshot'];
        }

        $user_id = !empty($data['user_id']) ? (int) $data['user_id'] : get_current_user_id();

        $action_id = $this->generate_action_id();

        $row = array(
            'action_id'       => $action_id,
            'action_type'     => substr($action_type, 0, 50),
            'user_id'         => $user_id,
            'model'           => isset($data['model']) && is_scalar($data['model']) ? substr((string) $data['model'], 0, 100) : null,
            'prompt'          => isset($data['prompt']) && is_scalar($data['prompt']) ? (string) $data['prompt'] : null,
            'input_context'   => isset($data['input_context']) ? $this->encode_snapshot($data['input_context']) : null,
            'output_snapshot' => $output !== null ? $this->encode_snapshot($output) : null,
        );
        $formats = array('%s', '%s', '%d', '%s', '%s', '%s', '%s');

        // Only bind context_post_id when there is one: a null bound as %d
        // would be stored as 0 rather than NULL.
        if (!empty($data['context_post_id'])) {
            $row['context_post_id'] = (int) $data['context_post_id'];
            $formats[]              = '%d';
        }

        $inserted = $wpdb->insert($wpdb->prefix . 'wcp_ai_actions', $row, $formats);

        if ($inserted === false && defined('WP_DEBUG') && WP_DEBUG) {
            // The audit trail is a stated guarantee; don't lose rows quietly.
            error_log('WP Copilot: failed to write AI audit log row (' . $action_type . '): ' . $wpdb->last_error);
        }

        return $action_id;
    }

    /**
     * Encode a context/output snapshot for storage. Every reader
     * json_decode()s these columns, so everything is stored as JSON — with
     * one exception: several callers pass a structure they have already
     * JSON-encoded, and encoding that again would store a quoted, escaped
     * string that decodes back to text instead of structure.
     *
     * @param mixed $value
     * @return string
     */
    private function encode_snapshot($value) {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $value; // already-encoded object/array
            }
        }

        $encoded = wp_json_encode($value);

        return $encoded === false ? '' : $encoded;
    }

    /**
     * Log acceptance/dismissal decisions
     *
     * @param string $action_id Action ID
     * @param array $accepted_items Accepted item IDs
     * @param array $dismissed_items Dismissed item IDs
     */
    public function log_decisions($action_id, $accepted_items = array(), $dismissed_items = array()) {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'wcp_ai_actions',
            array(
                'accepted_items' => wp_json_encode($accepted_items),
                'dismissed_items' => wp_json_encode($dismissed_items),
            ),
            array('action_id' => $action_id),
            array('%s', '%s'),
            array('%s')
        );
    }

    /**
     * Get AI action by ID
     */
    public function get_action($action_id) {
        global $wpdb;

        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wcp_ai_actions WHERE action_id = %s",
                $action_id
            ),
            ARRAY_A
        );

        if ($result) {
            // Decode JSON fields
            $result['input_context'] = json_decode($result['input_context'], true);
            $result['output_snapshot'] = json_decode($result['output_snapshot'], true);
            $result['accepted_items'] = json_decode($result['accepted_items'], true);
            $result['dismissed_items'] = json_decode($result['dismissed_items'], true);
        }

        return $result;
    }

    /**
     * Get all AI actions for a context
     */
    public function get_actions_for_context($post_id, $limit = 50) {
        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wcp_ai_actions WHERE context_post_id = %d ORDER BY timestamp DESC LIMIT %d",
                $post_id,
                $limit
            ),
            ARRAY_A
        );

        foreach ($results as &$result) {
            $result['input_context'] = json_decode($result['input_context'], true);
            $result['output_snapshot'] = json_decode($result['output_snapshot'], true);
            $result['accepted_items'] = json_decode($result['accepted_items'], true);
            $result['dismissed_items'] = json_decode($result['dismissed_items'], true);
        }

        return $results;
    }

    /**
     * Get recent AI actions
     */
    public function get_recent_actions($limit = 50) {
        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wcp_ai_actions ORDER BY timestamp DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        foreach ($results as &$result) {
            $result['input_context'] = json_decode($result['input_context'], true);
            $result['output_snapshot'] = json_decode($result['output_snapshot'], true);
            $result['accepted_items'] = json_decode($result['accepted_items'], true);
            $result['dismissed_items'] = json_decode($result['dismissed_items'], true);
        }

        return $results;
    }

    /**
     * Generate unique action ID
     */
    private function generate_action_id() {
        return 'wcp_ai_' . wp_generate_uuid4();
    }

    /**
     * Delete audit log rows older than the retention window. readme.txt
     * names this log as a privacy/auditability feature, which cuts both
     * ways — it stores full prompts and AI responses (potentially
     * containing note content) indefinitely otherwise, growing unbounded.
     * Called daily via the wcp_ai_actions_retention cron event.
     *
     * @return int Number of rows deleted
     */
    public function purge_old_actions() {
        global $wpdb;

        // Filterable — a site owner who wants a longer/shorter audit trail
        // can adjust this without a code change.
        $days = (int) apply_filters('wcp_ai_log_retention_days', 90);
        if ($days <= 0) {
            return 0; // 0 or negative disables purging, not "delete everything"
        }

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}wcp_ai_actions WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));

        return $deleted === false ? 0 : (int) $deleted;
    }
}
