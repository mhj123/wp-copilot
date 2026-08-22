<?php
/**
 * Strict-JSON LLM gateway — the single choke point for every AI call.
 *
 * Text and PDF calls are delegated to Work Copilot core's WCP_AI_Client
 * (shared key, shared model allowlist, shared Anthropic request path).
 *
 * GUARDRAIL: this class returns parsed proposals to callers and writes
 * nothing except the mandatory audit row in Work Copilot's wp_wcp_ai_actions
 * table (prompt + input snapshot + output snapshot). Persistence of proposals
 * (always in wcpo_proposed) is the caller's job; acceptance is always a human's.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPO_LLM {

    const API_URL = 'https://api.anthropic.com/v1/messages';

    /**
     * Make a strict-JSON call.
     *
     * @param string $kind extract_facts|consolidate|assign_chapters|draft_chapter
     * @param string $system System prompt (must demand JSON-only output unless raw_text).
     * @param string $user   Bounded context pack.
     * @param array  $opts {
     *   @type string $tier            'fast'|'draft' — picks the configured model. Default 'fast'.
     *   @type int    $max_tokens      Default 4096.
     *   @type int    $timeout         Default 120.
     *   @type array  $required        Top-level keys that must exist in the decoded object.
     *   @type bool   $raw_text        Skip JSON parsing (narrative prose). Default false.
     *   @type int    $context_post_id Related post id for the audit row.
     *   @type int    $attachment_id   PDF attachment — switches to the document-block path.
     * }
     * @return array|WP_Error { data, action_id, model }
     */
    public static function call($kind, $system, $user, array $opts = array()) {
        if (!wcpo_copilot_active()) {
            return new WP_Error('copilot_missing', __('Work Copilot is not active — AI features are unavailable.', 'wcp-openbiografy'));
        }

        $tier            = isset($opts['tier']) ? $opts['tier'] : 'fast';
        $model           = ($tier === 'draft') ? wcpo_get_setting('model_draft') : wcpo_get_setting('model');
        $max_tokens      = isset($opts['max_tokens']) ? (int) $opts['max_tokens'] : 4096;
        $timeout         = isset($opts['timeout']) ? (int) $opts['timeout'] : 120;
        $required        = isset($opts['required']) ? (array) $opts['required'] : array();
        $raw_text        = !empty($opts['raw_text']);
        $context_post_id = isset($opts['context_post_id']) ? (int) $opts['context_post_id'] : 0;
        $attachment_id   = isset($opts['attachment_id']) ? (int) $opts['attachment_id'] : 0;

        if ($attachment_id) {
            $file = get_attached_file($attachment_id);
            if (!$file || !file_exists($file)) {
                return new WP_Error('file_missing', __('Attached file not found.', 'wcp-openbiografy'));
            }
            if (get_post_mime_type($attachment_id) !== 'application/pdf') {
                return new WP_Error('bad_type', __('Only PDF documents can be sent to the model directly.', 'wcp-openbiografy'));
            }
            $max_bytes = (float) wcpo_get_setting('max_pdf_mb') * 1024 * 1024;
            if ($max_bytes > 0 && filesize($file) > $max_bytes) {
                return new WP_Error('too_large', sprintf(__('PDF exceeds the %s MB limit.', 'wcp-openbiografy'), wcpo_get_setting('max_pdf_mb')));
            }

            $client = WCP_AI_Client::instance();
            if (!$client->is_configured()) {
                return new WP_Error('not_configured', __('Anthropic API key not configured in Work Copilot settings.', 'wcp-openbiografy'));
            }
            $client->set_overrides($model);
            $result = $client->request_with_document($system, $user, $attachment_id, $max_tokens, $timeout);
            $client->set_overrides(null); // don't leak the override into other plugins' calls
            // The audit row records the attachment reference, never the base64 payload.
            $input_snapshot = array(
                'user'          => $user,
                'attachment_id' => $attachment_id,
                'filename'      => basename((string) get_attached_file($attachment_id)),
            );
        } else {
            $client = WCP_AI_Client::instance();
            if (!$client->is_configured()) {
                return new WP_Error('not_configured', __('Anthropic API key not configured in Work Copilot settings.', 'wcp-openbiografy'));
            }
            $client->set_overrides($model);
            $result = $client->request_with_conversation($system, $user, array(), $max_tokens, $timeout);
            $client->set_overrides(null); // don't leak the override into other plugins' calls
            $input_snapshot = array('user' => $user);
        }

        $logger = WCP_AI_Logger::instance();

        if (is_wp_error($result)) {
            $logger->log_action('wcpo_' . $kind, array(
                'model'           => $model,
                'prompt'          => $system,
                'input_context'   => $input_snapshot,
                'output'          => array('error' => $result->get_error_message()),
                'context_post_id' => $context_post_id,
            ));
            return $result;
        }

        $text = isset($result['content']) ? (string) $result['content'] : '';

        if ($raw_text) {
            $action_id = $logger->log_action('wcpo_' . $kind, array(
                'model'           => $model,
                'prompt'          => $system,
                'input_context'   => $input_snapshot,
                'output'          => array('text' => $text, 'usage' => isset($result['usage']) ? $result['usage'] : null),
                'context_post_id' => $context_post_id,
            ));
            return array('data' => $text, 'action_id' => $action_id, 'model' => $model);
        }

        $data = self::extract_json($text);
        if (is_wp_error($data)) {
            $logger->log_action('wcpo_' . $kind, array(
                'model'           => $model,
                'prompt'          => $system,
                'input_context'   => $input_snapshot,
                'output'          => array('parse_error' => $data->get_error_message(), 'raw' => mb_substr($text, 0, 2000)),
                'context_post_id' => $context_post_id,
            ));
            return $data;
        }

        // Schema validation BEFORE anything is persisted.
        foreach ($required as $key) {
            if (!array_key_exists($key, $data)) {
                $err = new WP_Error('schema_error', "LLM output missing required key: {$key}");
                $logger->log_action('wcpo_' . $kind, array(
                    'model'           => $model,
                    'prompt'          => $system,
                    'input_context'   => $input_snapshot,
                    'output'          => array('schema_error' => $key, 'raw' => $data),
                    'context_post_id' => $context_post_id,
                ));
                return $err;
            }
        }

        $action_id = $logger->log_action('wcpo_' . $kind, array(
            'model'           => $model,
            'prompt'          => $system,
            'input_context'   => $input_snapshot,
            'output'          => array('data' => $data, 'usage' => isset($result['usage']) ? $result['usage'] : null),
            'context_post_id' => $context_post_id,
        ));

        return array('data' => $data, 'action_id' => $action_id, 'model' => $model);
    }

    /**
     * Pull the first JSON object out of a model reply, tolerating markdown
     * fences and prose wrappers.
     */
    private static function extract_json($text) {
        $text = preg_replace('/```(?:json)?\s*/i', '', (string) $text);
        $text = trim(str_replace('```', '', $text));

        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return new WP_Error('parse_error', 'No JSON object found in LLM reply');
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return new WP_Error('parse_error', 'Invalid JSON: ' . json_last_error_msg());
        }
        return $decoded;
    }
}
