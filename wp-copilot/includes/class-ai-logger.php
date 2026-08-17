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
     * Log an AI action
     *
     * @param string $action_type Type of AI action (tagging|chat|coaching|generation)
     * @param array $data {
     *     @type string $model AI model used
     *     @type string $prompt Prompt sent to AI
     *     @type array $input_context Context data sent to AI
     *     @type array $output AI response
     *     @type int $context_post_id Related post ID (if applicable)
     * }
     * @return string Action ID
     */
    public function log_action($action_type, $data = array()) {
        global $wpdb;

        $action_id = $this->generate_action_id();

        $wpdb->insert(
            $wpdb->prefix . 'wcp_ai_actions',
            array(
                'action_id' => $action_id,
                'action_type' => $action_type,
                'user_id' => get_current_user_id(),
                'model' => isset($data['model']) ? $data['model'] : null,
                'prompt' => isset($data['prompt']) ? $data['prompt'] : null,
                'input_context' => isset($data['input_context']) ? wp_json_encode($data['input_context']) : null,
                'output_snapshot' => isset($data['output']) ? wp_json_encode($data['output']) : null,
                'context_post_id' => isset($data['context_post_id']) ? intval($data['context_post_id']) : null,
            ),
            array('%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d')
        );

        return $action_id;
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
