<?php
/**
 * AI Client - Handles communication with Claude API
 *
 * CRITICAL: This class makes AI calls but NEVER writes to database
 * All outputs are proposals that require user acceptance
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_AI_Client {

    private static $instance = null;
    /**
     * Fallback default when nothing is configured. The single place a model
     * ID is spelled out as a default — everything else asks default_model().
     */
    const DEFAULT_MODEL = 'claude-sonnet-5-5';

    /** Extra max_tokens allowed when the model is thinking, so reasoning doesn't eat the reply. */
    const THINKING_HEADROOM_TOKENS = 8192;

    private $api_key;
    private $api_url = 'https://api.anthropic.com/v1/messages';
    private $model;
    private $_override_model   = null;
    private $_thinking_budget  = 0;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->api_key = get_option('wcp_anthropic_api_key', '');
        $this->model   = self::default_model();
    }

    /**
     * The one registry of selectable models. The settings screen, the widget
     * picker and every allowlist check read from here, so a new model is a
     * one-line change (or no code change at all, via the filter).
     *
     * Per model:
     *  - label / short_label: settings screen and widget picker text.
     *  - thinking_off: the `thinking` value that switches reasoning off for
     *    this model, or null if the model cannot switch it off. The value is
     *    model-specific and the API rejects the wrong one with a 400:
     *    Sonnet 5.5 takes "between_tools", Haiku 5.5 takes "disabled", and
     *    Opus 5.5 always thinks.
     *  - refusal_fallback: whether the API's server-side refusal fallback is
     *    available for this model.
     *
     * A model added through the filter without `thinking_off` is sent no
     * `thinking` field at all and runs on its own default.
     *
     * @return array<string, array{label: string, short_label: string, thinking_off: ?array, refusal_fallback: bool}>
     */
    public static function models() {
        $models = array(
            'claude-sonnet-5-5' => array(
                'label'            => 'Claude Sonnet 5.5 (Recommended)',
                'short_label'      => 'Sonnet 5.5 — Balanced',
                'thinking_off'     => array('type' => 'between_tools'),
                'refusal_fallback' => true,
            ),
            'claude-opus-5-5' => array(
                'label'            => 'Claude Opus 5.5 (Most Capable)',
                'short_label'      => 'Opus 5.5 — Powerful',
                'thinking_off'     => null,
                'refusal_fallback' => true,
            ),
            'claude-haiku-5-5' => array(
                'label'            => 'Claude Haiku 5.5 (Fast)',
                'short_label'      => 'Haiku 5.5 — Fast',
                'thinking_off'     => array('type' => 'disabled'),
                'refusal_fallback' => false,
            ),
        );

        $models = apply_filters('wcp_ai_models', $models);

        return is_array($models) && !empty($models) ? $models : array();
    }

    /**
     * Whether $model is one this install will send requests to.
     */
    public static function is_allowed_model($model) {
        return is_string($model) && $model !== '' && array_key_exists($model, self::models());
    }

    /**
     * Map a model ID to one in the registry. IDs saved by earlier versions
     * of the plugin (and still sitting in the wcp_ai_model option, or in a
     * browser tab opened before an update) are moved to the same tier's
     * current model rather than being sent to the API as they are.
     *
     * @param string|null $model
     * @return string|null A registry model ID, or null if $model is unknown.
     */
    public static function resolve_model($model) {
        if (!is_string($model) || $model === '') {
            return null;
        }
        if (self::is_allowed_model($model)) {
            return $model;
        }

        foreach (array('sonnet', 'opus', 'haiku') as $tier) {
            if (strpos($model, 'claude-' . $tier . '-') !== 0) {
                continue;
            }
            foreach (array_keys(self::models()) as $candidate) {
                if (strpos($candidate, 'claude-' . $tier . '-') === 0) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * The site's default model: the saved setting if it is (or maps to) a
     * registry model, otherwise the first-choice fallback.
     */
    public static function default_model() {
        $resolved = self::resolve_model(get_option('wcp_ai_model', ''));
        if ($resolved) {
            return $resolved;
        }

        $fallback = apply_filters('wcp_ai_default_model', self::DEFAULT_MODEL);
        if (self::is_allowed_model($fallback)) {
            return $fallback;
        }

        $ids = array_keys(self::models());

        return $ids ? $ids[0] : self::DEFAULT_MODEL;
    }

    /**
     * Override model and reasoning for a single request cycle.
     * Call once before dispatching an action; call again with no arguments
     * to reset.
     *
     * @param string|null $model           Model ID, or null to use the site default.
     *                                     Unknown IDs fall back to the site default.
     * @param int         $thinking_budget > 0 asks the model to reason before answering.
     *                                     Current models no longer take a token budget —
     *                                     depth is set with effort — so this is on/off.
     */
    public function set_overrides( $model = null, $thinking_budget = 0 ) {
        $this->_override_model  = self::resolve_model( $model );
        $this->_thinking_budget = max( 0, (int) $thinking_budget );
    }

    /**
     * The model the next request will be sent to.
     */
    public function effective_model() {
        return $this->_override_model ?: $this->model;
    }

    /**
     * Check if AI is configured
     */
    public function is_configured() {
        return !empty($this->api_key);
    }

    /**
     * Make a request to Claude API
     */
    private function request($messages, $max_tokens = 1024, $system = null, $timeout = 30) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', 'AI API key not configured');
        }

        $effective_model = $this->effective_model();
        $registry        = self::models();
        $model_config    = isset($registry[$effective_model]) ? $registry[$effective_model] : array();
        $wants_thinking  = $this->_thinking_budget > 0;
        $thinking_off    = isset($model_config['thinking_off']) ? $model_config['thinking_off'] : null;

        // Reasoning. Current models reason by default and no longer accept a
        // token budget or sampling parameters (both are rejected with a 400).
        //  - Asked to think: adaptive thinking at high effort.
        //  - Not asked: switch it off where the model allows, so short
        //    structured calls stay fast and their max_tokens stays accurate.
        //  - Model can't switch it off: leave its default and budget for it.
        $thinking_active = true;
        $thinking_param  = null;
        if ($wants_thinking) {
            $thinking_param = array('type' => 'adaptive');
        } elseif (is_array($thinking_off)) {
            $thinking_param  = $thinking_off;
            $thinking_active = false;
        }

        if ($thinking_active) {
            // Reasoning tokens count against max_tokens; without headroom a
            // small cap would be spent before the reply starts.
            $max_tokens += self::THINKING_HEADROOM_TOKENS;
            $timeout     = max($timeout, 120);
        }

        $body = array(
            'model'      => $effective_model,
            'max_tokens' => $max_tokens,
            'messages'   => $messages,
        );

        if ($system) {
            $body['system'] = $system;
        }

        if ($thinking_param) {
            $body['thinking'] = $thinking_param;
        }
        if ($wants_thinking) {
            $body['output_config'] = array('effort' => 'high');
        }

        $headers = array(
            'Content-Type'      => 'application/json',
            'x-api-key'         => $this->api_key,
            'anthropic-version' => '2023-06-01',
        );

        // Refusal fallback: if the model's safety classifiers decline a
        // request, the API re-runs it on a fallback model inside the same
        // call instead of returning nothing. Opt-in on the API side; turn
        // off here with add_filter('wcp_ai_refusal_fallback', '__return_false').
        if (!empty($model_config['refusal_fallback']) && apply_filters('wcp_ai_refusal_fallback', true, $effective_model)) {
            $headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
            $body['fallbacks']         = 'default';
        }

        $response = wp_remote_post($this->api_url, array(
            'headers' => $headers,
            'body'    => wp_json_encode($body),
            'timeout' => $timeout,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        if ($response_code !== 200) {
            $error_message = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown API error';
            return new WP_Error('api_error', $error_message, array('status' => $response_code));
        }

        // A refusal is an HTTP 200 with no usable text — surface it as an
        // error rather than letting callers treat it as an empty reply.
        if (isset($data['stop_reason']) && $data['stop_reason'] === 'refusal') {
            return new WP_Error(
                'ai_refused',
                __('The AI model declined this request. Try rephrasing it, or pick a different model.', 'work-copilot'),
                array('status' => 422)
            );
        }

        // Responses can carry thinking (and fallback) blocks before the text.
        // Keep only text blocks so every caller can safely read
        // content[0]['text'], and join them in case the reply was split.
        $text = '';
        if (isset($data['content']) && is_array($data['content'])) {
            foreach ($data['content'] as $block) {
                if (isset($block['type'], $block['text']) && $block['type'] === 'text') {
                    $text .= $block['text'];
                }
            }
        }
        $data['content'] = array(array('type' => 'text', 'text' => $text));

        // The model that actually answered (it differs from the one asked
        // for when a refusal fallback served the turn) — this is what the
        // audit log should record.
        if (empty($data['model'])) {
            $data['model'] = $effective_model;
        }

        return $data;
    }

    /**
     * Suggest tags for an ItemPost
     */
    public function suggest_tags($title, $content) {
        $prompt = "Analyze this note and suggest:\n";
        $prompt .= "1. Item type (task, note, or learning)\n";
        $prompt .= "2. Priority (high, medium, or low)\n";
        $prompt .= "3. Relevant tags (2-5 tags)\n\n";
        $prompt .= "Title: {$title}\n\n";
        $prompt .= "Content: {$content}\n\n";
        $prompt .= "Respond ONLY with valid JSON in this format:\n";
        $prompt .= '{"item_type": "task|note|learning", "priority": "high|medium|low", "tags": ["tag1", "tag2"]}';

        $response = $this->request(array(
            array(
                'role' => 'user',
                'content' => $prompt,
            ),
        ), 512);

        if (is_wp_error($response)) {
            return $response;
        }

        // Extract text from response
        $text = isset($response['content'][0]['text']) ? $response['content'][0]['text'] : '';

        // Strip markdown code blocks if present
        $text = preg_replace('/```json\s*/i', '', $text);
        $text = preg_replace('/```\s*$/i', '', $text);
        $text = trim($text);

        // Try to parse JSON from response
        $json_start = strpos($text, '{');
        $json_end = strrpos($text, '}');

        if ($json_start !== false && $json_end !== false) {
            $json_str = substr($text, $json_start, $json_end - $json_start + 1);
            $suggestions = json_decode($json_str, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($suggestions)) {
                return array(
                    'item_type' => isset($suggestions['item_type']) ? $suggestions['item_type'] : '',
                    'priority' => isset($suggestions['priority']) ? $suggestions['priority'] : '',
                    'tags' => isset($suggestions['tags']) ? $suggestions['tags'] : array(),
                    'raw_response' => $text,
                );
            }
        }

        // More detailed error for debugging
        $error_msg = 'Could not parse AI response';
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_msg .= ': ' . json_last_error_msg();
        }
        $error_msg .= '. Response: ' . substr($text, 0, 200);

        return new WP_Error('parse_error', $error_msg);
    }

    /**
     * Make a request with conversation history
     *
     * @param string $system_prompt System prompt (3 layers combined)
     * @param string $user_message User message with context
     * @param array $conversation_history Array of previous messages (role => content)
     * @param int $max_tokens Maximum tokens for response
     * @return array|WP_Error Response with content, model, and usage
     */
    public function request_with_conversation($system_prompt, $user_message, $conversation_history = array(), $max_tokens = 4096, $timeout = 30) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', 'AI API key not configured');
        }

        // Build messages array
        $messages = array();

        // Include conversation history, capped to bound token usage. Must match
        // the DB fetch limit used by callers, hence the shared constant.
        // Filter to only include 'user' and 'assistant' roles (Claude API doesn't accept 'system' in messages)
        $history_limit = WCP_Conversations_Manager::AI_HISTORY_LIMIT;
        $recent_history = array_slice($conversation_history, -$history_limit);

        foreach ($recent_history as $msg) {
            // Only include user and assistant messages - Claude API doesn't accept 'system' as a message role
            if (in_array($msg['role'], array('user', 'assistant'))) {
                $messages[] = array(
                    'role' => $msg['role'],
                    'content' => $msg['content']
                );
            }
        }

        // Add current user message
        $messages[] = array(
            'role' => 'user',
            'content' => $user_message
        );

        // Make request with system prompt
        $response = $this->request($messages, $max_tokens, $system_prompt, $timeout);

        if (is_wp_error($response)) {
            return $response;
        }

        // Extract content from response
        $content = isset($response['content'][0]['text']) ? $response['content'][0]['text'] : '';

        return array(
            'content' => $content,
            'model' => $response['model'],
            'usage' => isset($response['usage']) ? $response['usage'] : null,
            'stop_reason' => isset($response['stop_reason']) ? $response['stop_reason'] : null,
        );
    }

    /**
     * Make a request to Claude with an attached PDF document block.
     *
     * GUARDRAIL: callers must not log the request body; it contains base64 PDF
     * bytes. Audit logs should record only attachment id/filename metadata.
     *
     * @param string $system_prompt System prompt
     * @param string $user_message User instruction text
     * @param int $attachment_id WordPress attachment ID for an application/pdf file
     * @param int $max_tokens Maximum tokens for response
     * @param int $timeout Request timeout in seconds
     * @return array|WP_Error Response with content, model, usage, and stop_reason
     */
    public function request_with_document($system_prompt, $user_message, $attachment_id, $max_tokens = 2048, $timeout = 90) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', 'AI API key not configured');
        }

        $attachment_id = (int) $attachment_id;
        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file) || !is_readable($file)) {
            return new WP_Error('file_missing', 'Attached PDF file not found');
        }
        if (get_post_mime_type($attachment_id) !== 'application/pdf') {
            return new WP_Error('bad_type', 'Only PDF documents can be sent to the model directly');
        }

        $bytes = file_get_contents($file);
        if (!is_string($bytes) || $bytes === '') {
            return new WP_Error('empty_document', 'Attached PDF file is empty or unreadable');
        }

        $messages = array(array(
            'role'    => 'user',
            'content' => array(
                array(
                    'type'   => 'document',
                    'source' => array(
                        'type'       => 'base64',
                        'media_type' => 'application/pdf',
                        'data'       => base64_encode($bytes),
                    ),
                ),
                array('type' => 'text', 'text' => $user_message),
            ),
        ));

        $response = $this->request($messages, $max_tokens, $system_prompt, $timeout);

        if (is_wp_error($response)) {
            return $response;
        }

        $content = isset($response['content'][0]['text']) ? $response['content'][0]['text'] : '';

        return array(
            'content' => $content,
            'model' => $response['model'],
            'usage' => isset($response['usage']) ? $response['usage'] : null,
            'stop_reason' => isset($response['stop_reason']) ? $response['stop_reason'] : null,
        );
    }

    /**
     * Test API connection
     */
    public function test_connection() {
        $response = $this->request(array(
            array(
                'role' => 'user',
                'content' => 'Hello! Please respond with "Connection successful"',
            ),
        ), 128);

        if (is_wp_error($response)) {
            return $response;
        }

        return array(
            'success' => true,
            'message' => isset($response['content'][0]['text']) ? $response['content'][0]['text'] : '',
            'model' => $response['model'],
        );
    }
}
