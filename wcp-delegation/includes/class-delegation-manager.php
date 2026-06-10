<?php
/**
 * Delegation Manager
 *
 * Hands items off to an external Hermes agent and tracks the round trip:
 * create → Telegram notify → agent fetches packet → (clarification Q&A) →
 * agent posts status + artifacts → human reviews on the item row.
 *
 * AI guardrail: the agent's writes are confined to delegation meta, the
 * delegation index, and media attachments parented to the item. The agent
 * can never modify post_title/post_content of items or pages — results are
 * review-only.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPD_Delegation_Manager {

    const META_KEY       = '_wcpd_delegations';
    const INDEX_OPTION   = 'wcpd_delegation_index';
    const MAX_FILES      = 5;
    const MAX_FILE_BYTES = 10485760; // 10 MB
    const MAX_REPORT_CHARS = 20000;

    private static $valid_statuses = array('pending', 'in_progress', 'needs_input', 'completed', 'failed');

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // No hooks — invoked by the REST layer and the theme template.
    }

    public function is_enabled() {
        return get_option('wcpd_enabled') === '1';
    }

    /**
     * Create a delegation from a user submission.
     *
     * @param int    $item_id     Item (post) being delegated
     * @param string $instruction Brief for the agent
     * @param array  $files       Normalized-or-raw file params (optional input attachments)
     * @return array|WP_Error { delegation, telegram_sent, telegram_error, skipped_files }
     */
    public function create_delegation($item_id, $instruction, $files = array()) {
        $item = get_post($item_id);
        if (!$item || $item->post_type !== 'post') {
            return new WP_Error('not_found', 'Item not found', array('status' => 404));
        }

        $instruction = sanitize_textarea_field($instruction);
        if (empty($instruction)) {
            return new WP_Error('missing_instruction', 'Instruction is required', array('status' => 400));
        }

        $attachment_ids = array();
        $skipped        = array();
        if (!empty($files)) {
            $upload = $this->handle_uploaded_files($files, $item_id);
            if (is_wp_error($upload)) {
                return $upload;
            }
            $attachment_ids = $upload['ids'];
            $skipped        = $upload['skipped'];
        }

        $delegation_id = 'dlg_' . wp_generate_uuid4();
        $now           = current_time('mysql');

        $delegation = array(
            'id'             => $delegation_id,
            'instruction'    => $instruction,
            'status'         => 'pending',
            'created_at'     => $now,
            'updated_at'     => $now,
            'page_id'        => $this->resolve_page_id($item_id),
            'attachment_ids' => $attachment_ids,
            'artifact_ids'   => array(),
            'report'         => '',
            'status_message' => '',
            'clarifications' => array(),
            'user_id'        => get_current_user_id(),
        );

        $delegations   = $this->get_delegations_for_item($item_id);
        $delegations[] = $delegation;
        $this->save_delegations($item_id, $delegations);
        $this->update_index($delegation_id, $item_id, 'pending', $now);

        if (class_exists('WCP_AI_Logger')) {
            WCP_AI_Logger::instance()->log_action('delegation_created', array(
                'prompt'          => $instruction,
                'context_post_id' => $item_id,
                'output'          => array('delegation_id' => $delegation_id),
            ));
        }

        $message = "New delegation\n"
            . "Item: {$item->post_title}\n"
            . 'Instruction: ' . mb_substr($instruction, 0, 500) . "\n"
            . "ID: {$delegation_id}\n"
            . 'Packet: ' . $this->packet_url($delegation_id);
        $sent = $this->notify_telegram($message);

        return array(
            'delegation'     => $delegation,
            'telegram_sent'  => !is_wp_error($sent),
            'telegram_error' => is_wp_error($sent) ? $sent->get_error_message() : null,
            'skipped_files'  => $skipped,
        );
    }

    /**
     * Look up a delegation by ID. Returned record includes item_id.
     */
    public function get_delegation($delegation_id) {
        $index = get_option(self::INDEX_OPTION, array());
        if (empty($index[$delegation_id]['item_id'])) {
            return null;
        }
        $item_id = (int) $index[$delegation_id]['item_id'];

        foreach ($this->get_delegations_for_item($item_id) as $delegation) {
            if ($delegation['id'] === $delegation_id) {
                $delegation['item_id'] = $item_id;
                return $delegation;
            }
        }
        return null;
    }

    public function get_delegations_for_item($item_id) {
        $raw = get_post_meta($item_id, self::META_KEY, true);
        $decoded = $raw ? json_decode($raw, true) : array();
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * Summaries from the index — no item meta loads. Used by the agent's polling fallback.
     */
    public function list_delegations($status = '') {
        $index   = get_option(self::INDEX_OPTION, array());
        $results = array();

        foreach ($index as $delegation_id => $entry) {
            if ($status && (!isset($entry['status']) || $entry['status'] !== $status)) {
                continue;
            }
            $results[] = array(
                'id'         => $delegation_id,
                'item_id'    => (int) $entry['item_id'],
                'item_title' => get_the_title((int) $entry['item_id']),
                'status'     => $entry['status'] ?? '',
                'created_at' => $entry['created_at'] ?? '',
                'packet_url' => $this->packet_url($delegation_id),
            );
        }

        return $results;
    }

    /**
     * Agent-facing status update. needs_input requires a question, which is
     * appended to the clarification thread for the user to answer.
     */
    public function update_status($delegation_id, $status, $message = '', $report = '', $question = '') {
        if (!in_array($status, self::$valid_statuses, true)) {
            return new WP_Error('invalid_status', 'Invalid status. Allowed: ' . implode(', ', self::$valid_statuses), array('status' => 400));
        }

        $question = sanitize_textarea_field($question);
        if ($status === 'needs_input' && empty($question)) {
            return new WP_Error('missing_question', 'A question is required when status is needs_input', array('status' => 400));
        }

        $updated = $this->mutate_delegation($delegation_id, function (&$delegation) use ($status, $message, $report, $question) {
            $delegation['status']         = $status;
            $delegation['status_message'] = sanitize_textarea_field($message);
            if ($report !== '') {
                $delegation['report'] = mb_substr(sanitize_textarea_field($report), 0, self::MAX_REPORT_CHARS);
            }
            if ($question !== '') {
                $delegation['clarifications'][] = array(
                    'id'          => uniqid('q_'),
                    'question'    => $question,
                    'asked_at'    => current_time('mysql'),
                    'answer'      => '',
                    'answered_at' => null,
                );
            }
        });

        if (is_wp_error($updated)) {
            return $updated;
        }

        if (class_exists('WCP_AI_Logger')) {
            WCP_AI_Logger::instance()->log_action('delegation_status_changed', array(
                'prompt'          => $question !== '' ? $question : $message,
                'context_post_id' => $updated['item_id'],
                'output'          => array('delegation_id' => $delegation_id, 'status' => $status),
            ));
        }

        return $updated;
    }

    /**
     * User answers an agent question: store the answer, flip status back to
     * pending, and re-notify the agent so it resumes with full context.
     */
    public function answer_question($delegation_id, $question_id, $answer) {
        $answer = sanitize_textarea_field($answer);
        if (empty($answer)) {
            return new WP_Error('missing_answer', 'Answer is required', array('status' => 400));
        }

        $found = false;
        $updated = $this->mutate_delegation($delegation_id, function (&$delegation) use ($question_id, $answer, &$found) {
            foreach ($delegation['clarifications'] as &$q) {
                if ($q['id'] === $question_id) {
                    $q['answer']      = $answer;
                    $q['answered_at'] = current_time('mysql');
                    $found = true;
                    break;
                }
            }
            unset($q);
            if ($found) {
                $delegation['status'] = 'pending';
            }
        });

        if (is_wp_error($updated)) {
            return $updated;
        }
        if (!$found) {
            return new WP_Error('question_not_found', 'Question not found on this delegation', array('status' => 404));
        }

        $message = "Question answered — resume delegation\n"
            . "ID: {$delegation_id}\n"
            . 'Answer: ' . mb_substr($answer, 0, 500) . "\n"
            . 'Packet: ' . $this->packet_url($delegation_id);
        $this->notify_telegram($message);

        return $updated;
    }

    /**
     * Agent-facing artifact upload. Files become media attachments parented
     * to the item — review-only, nothing is applied to content.
     */
    public function add_artifacts($delegation_id, $files) {
        $delegation = $this->get_delegation($delegation_id);
        if (!$delegation) {
            return new WP_Error('not_found', 'Delegation not found', array('status' => 404));
        }
        if (empty($files)) {
            return new WP_Error('missing_files', 'No files provided', array('status' => 400));
        }

        $upload = $this->handle_uploaded_files($files, $delegation['item_id']);
        if (is_wp_error($upload)) {
            return $upload;
        }

        $this->mutate_delegation($delegation_id, function (&$d) use ($upload) {
            $d['artifact_ids'] = array_merge($d['artifact_ids'], $upload['ids']);
        });

        return array(
            'artifacts' => $this->describe_attachments($upload['ids']),
            'skipped'   => $upload['skipped'],
        );
    }

    /**
     * Full work packet for the agent: brief, item data, context pack,
     * attachments, and absolute endpoint URLs.
     */
    public function build_packet($delegation_id) {
        $delegation = $this->get_delegation($delegation_id);
        if (!$delegation) {
            return new WP_Error('not_found', 'Delegation not found', array('status' => 404));
        }

        $item_id = $delegation['item_id'];
        $item    = get_post($item_id);
        if (!$item) {
            return new WP_Error('not_found', 'Item no longer exists', array('status' => 404));
        }

        $contexts = wp_get_post_terms($item_id, 'wcp_context', array('fields' => 'names'));
        $tags     = wp_get_post_terms($item_id, 'post_tag', array('fields' => 'names'));
        $types    = wp_get_post_terms($item_id, 'item_type', array('fields' => 'names'));
        $prios    = wp_get_post_terms($item_id, 'priority', array('fields' => 'names'));

        $page_id = (int) $delegation['page_id'];

        // Note: {user} variables in mission text resolve to the agent's WP
        // user on agent-initiated fetches — acceptable for context purposes.
        $mission = WCP_Mission_Loader::instance()->get_mission_context($page_id);

        $formatted_context = '';
        if ($page_id) {
            $context_builder = WCP_Context_Builder::instance();
            $context_data    = $context_builder->build_hierarchical_context($page_id, array(
                'include_items' => true,
                'item_limit'    => 10,
            ));
            $formatted_context = $context_builder->format_for_prompt($context_data, array(
                'max_chars_per_item'      => 500,
                'max_chars_page_summary'  => 8000,
            ));
        }

        $base = rest_url('wcp-delegation/v1/delegations/' . $delegation_id);

        return array(
            'delegation' => array(
                'id'             => $delegation['id'],
                'status'         => $delegation['status'],
                'instruction'    => $delegation['instruction'],
                'created_at'     => $delegation['created_at'],
                'updated_at'     => $delegation['updated_at'],
                'status_message' => $delegation['status_message'],
                'report'         => $delegation['report'],
                'clarifications' => $delegation['clarifications'],
            ),
            'item' => array(
                'id'        => $item_id,
                'title'     => $item->post_title,
                'content'   => wp_strip_all_tags($item->post_content),
                'item_type' => !is_wp_error($types) && !empty($types) ? $types[0] : '',
                'priority'  => !is_wp_error($prios) && !empty($prios) ? $prios[0] : '',
                'due_date'  => get_post_meta($item_id, '_wcp_due_date', true) ?: '',
                'subtasks'  => json_decode(get_post_meta($item_id, '_wcp_subtasks', true) ?: '[]', true),
                'tags'      => !is_wp_error($tags) ? $tags : array(),
                'contexts'  => !is_wp_error($contexts) ? $contexts : array(),
            ),
            'context_pack' => array(
                'global_mission'    => $mission['global'] ?? '',
                'page_mission'      => $mission['page'] ?? '',
                'page_id'           => $page_id,
                'formatted_context' => $formatted_context,
            ),
            'attachments' => $this->describe_attachments($delegation['attachment_ids']),
            'artifacts'   => $this->describe_attachments($delegation['artifact_ids']),
            'endpoints'   => array(
                'self'      => $base,
                'status'    => $base . '/status',
                'artifacts' => $base . '/artifacts',
            ),
        );
    }

    /**
     * Settings-page helper: verify the bot token/chat ID work.
     */
    public function send_test_message() {
        return $this->notify_telegram('Work Copilot Delegation: test message — your Telegram settings work.');
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function packet_url($delegation_id) {
        return rest_url('wcp-delegation/v1/delegations/' . $delegation_id);
    }

    /**
     * Read-modify-write a single delegation, then sync the index. Re-reads
     * meta just before writing to minimize races with concurrent updates.
     *
     * @param callable $mutator function (&$delegation) — modifies in place
     * @return array|WP_Error Updated delegation (with item_id)
     */
    private function mutate_delegation($delegation_id, $mutator) {
        $index = get_option(self::INDEX_OPTION, array());
        if (empty($index[$delegation_id]['item_id'])) {
            return new WP_Error('not_found', 'Delegation not found', array('status' => 404));
        }
        $item_id = (int) $index[$delegation_id]['item_id'];

        $delegations = $this->get_delegations_for_item($item_id);
        $updated     = null;

        foreach ($delegations as &$delegation) {
            if ($delegation['id'] === $delegation_id) {
                $mutator($delegation);
                $delegation['updated_at'] = current_time('mysql');
                $updated = $delegation;
                break;
            }
        }
        unset($delegation);

        if (null === $updated) {
            return new WP_Error('not_found', 'Delegation not found on item', array('status' => 404));
        }

        $this->save_delegations($item_id, $delegations);
        $this->update_index($delegation_id, $item_id, $updated['status'], $updated['created_at']);

        $updated['item_id'] = $item_id;
        return $updated;
    }

    private function save_delegations($item_id, $delegations) {
        update_post_meta($item_id, self::META_KEY, wp_json_encode(array_values($delegations)));
    }

    private function update_index($delegation_id, $item_id, $status, $created_at) {
        $index = get_option(self::INDEX_OPTION, array());
        $index[$delegation_id] = array(
            'item_id'    => (int) $item_id,
            'status'     => $status,
            'created_at' => $created_at,
        );
        update_option(self::INDEX_OPTION, $index, false);
    }

    /**
     * Resolve the page giving this item its context (for the context pack).
     * Follows the core pattern: wcp_context term → term meta wcp_ref_id.
     * Heading refs resolve to their parent page.
     */
    private function resolve_page_id($item_id) {
        $terms = wp_get_post_terms($item_id, 'wcp_context');
        if (empty($terms) || is_wp_error($terms)) {
            return 0;
        }

        $ref_id = get_term_meta($terms[0]->term_id, 'wcp_ref_id', true);
        if (!$ref_id) {
            return 0;
        }

        $ref = get_post((int) $ref_id);
        if ($ref && $ref->post_type === 'wcp_heading') {
            $parent_id = (int) get_post_meta($ref->ID, '_wcp_parent_id', true);
            return $parent_id ?: 0;
        }

        return (int) $ref_id;
    }

    /**
     * Sideload uploaded files as media attachments parented to the item.
     * Fail-soft per file: bad files are skipped and reported back.
     *
     * @return array|WP_Error { ids: int[], skipped: array{name,error}[] }
     */
    private function handle_uploaded_files($files, $parent_id) {
        $normalized = $this->normalize_file_params($files);

        if (count($normalized) > self::MAX_FILES) {
            return new WP_Error('too_many_files', 'Maximum ' . self::MAX_FILES . ' files per request', array('status' => 400));
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $ids     = array();
        $skipped = array();

        foreach ($normalized as $file) {
            if (!empty($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
                $skipped[] = array('name' => $file['name'], 'error' => 'Upload error code ' . $file['error']);
                continue;
            }
            if ($file['size'] > self::MAX_FILE_BYTES) {
                $skipped[] = array('name' => $file['name'], 'error' => 'File exceeds 10MB limit');
                continue;
            }

            $attachment_id = media_handle_sideload(array(
                'name'     => sanitize_file_name($file['name']),
                'type'     => $file['type'],
                'tmp_name' => $file['tmp_name'],
                'error'    => $file['error'],
                'size'     => $file['size'],
            ), $parent_id);

            if (is_wp_error($attachment_id)) {
                $skipped[] = array('name' => $file['name'], 'error' => $attachment_id->get_error_message());
                continue;
            }

            $ids[] = (int) $attachment_id;
        }

        if (empty($ids) && !empty($skipped)) {
            return new WP_Error(
                'upload_failed',
                'No files could be uploaded: ' . wp_json_encode($skipped),
                array('status' => 400)
            );
        }

        return array('ids' => $ids, 'skipped' => $skipped);
    }

    /**
     * Accepts both shapes from WP_REST_Request::get_file_params():
     * a single file entry, or the PHP files[] multi-upload arrays.
     */
    private function normalize_file_params($files) {
        $normalized = array();

        foreach ($files as $entry) {
            if (!is_array($entry) || !isset($entry['name'])) {
                continue;
            }
            if (is_array($entry['name'])) {
                // files[] shape: each field is a parallel array
                foreach ($entry['name'] as $i => $name) {
                    $normalized[] = array(
                        'name'     => $name,
                        'type'     => $entry['type'][$i] ?? '',
                        'tmp_name' => $entry['tmp_name'][$i] ?? '',
                        'error'    => $entry['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                        'size'     => $entry['size'][$i] ?? 0,
                    );
                }
            } else {
                $normalized[] = $entry;
            }
        }

        return $normalized;
    }

    private function describe_attachments($ids) {
        $described = array();
        foreach ((array) $ids as $id) {
            $url = wp_get_attachment_url($id);
            if (!$url) {
                continue;
            }
            $described[] = array(
                'id'       => (int) $id,
                'filename' => basename(get_attached_file($id) ?: $url),
                'url'      => $url,
                'mime'     => get_post_mime_type($id) ?: '',
            );
        }
        return $described;
    }

    /**
     * Send a plain-text message via the Telegram Bot API.
     * No parse_mode — avoids Markdown-escaping pitfalls.
     *
     * @return true|WP_Error
     */
    private function notify_telegram($text) {
        $token   = get_option('wcpd_telegram_bot_token', '');
        $chat_id = get_option('wcpd_telegram_chat_id', '');

        if (empty($token) || empty($chat_id)) {
            return new WP_Error('telegram_not_configured', 'Telegram bot token or chat ID not set');
        }

        $response = wp_remote_post('https://api.telegram.org/bot' . $token . '/sendMessage', array(
            'timeout' => 15,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array(
                'chat_id'                  => $chat_id,
                'text'                     => $text,
                'disable_web_page_preview' => true,
            )),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $desc = $body['description'] ?? ('HTTP ' . $code);
            return new WP_Error('telegram_error', 'Telegram API error: ' . $desc);
        }

        return true;
    }
}
