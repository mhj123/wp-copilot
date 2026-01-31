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
    private $api_key;
    private $api_url = 'https://api.anthropic.com/v1/messages';
    private $model;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->api_key = get_option('wcp_anthropic_api_key', '');
        $this->model = get_option('wcp_ai_model', 'claude-sonnet-4-20250514');
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
    private function request($messages, $max_tokens = 1024, $system = null) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', 'AI API key not configured');
        }

        $body = array(
            'model' => $this->model,
            'max_tokens' => $max_tokens,
            'messages' => $messages,
        );

        if ($system) {
            $body['system'] = $system;
        }

        $response = wp_remote_post($this->api_url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $this->api_key,
                'anthropic-version' => '2023-06-01',
            ),
            'body' => wp_json_encode($body),
            'timeout' => 30,
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

        return $data;
    }

    /**
     * Suggest tags for an ItemPost
     */
    public function suggest_tags($title, $content) {
        $prompt = "Analyze this note and suggest:\n";
        $prompt .= "1. Item type (task, info, or learning)\n";
        $prompt .= "2. Priority (high, medium, or low)\n";
        $prompt .= "3. Relevant tags (2-5 tags)\n\n";
        $prompt .= "Title: {$title}\n\n";
        $prompt .= "Content: {$content}\n\n";
        $prompt .= "Respond ONLY with valid JSON in this format:\n";
        $prompt .= '{"item_type": "task|info|learning", "priority": "high|medium|low", "tags": ["tag1", "tag2"]}';

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
     * Page-scoped chat
     */
    public function page_chat($page_context, $prompt) {
        $system_prompt = "You are a helpful assistant analyzing a user's work page and its associated notes. ";
        $system_prompt .= "Provide clear, actionable insights based on the context provided.";

        $user_message = "Page: {$page_context['page']['title']}\n\n";

        if (!empty($page_context['page']['content'])) {
            $user_message .= "Page Description:\n{$page_context['page']['content']}\n\n";
        }

        if (!empty($page_context['recent_items'])) {
            $user_message .= "Recent Items:\n";
            foreach ($page_context['recent_items'] as $item) {
                $user_message .= "- {$item['title']}: {$item['content']}\n";
            }
            $user_message .= "\n";
        }

        $user_message .= "User Question: {$prompt}";

        $response = $this->request(array(
            array(
                'role' => 'user',
                'content' => $user_message,
            ),
        ), 2048, $system_prompt);

        if (is_wp_error($response)) {
            return $response;
        }

        return array(
            'message' => isset($response['content'][0]['text']) ? $response['content'][0]['text'] : '',
            'model' => $this->model,
        );
    }

    /**
     * Coaching prompts - generates candidate ItemPosts
     */
    public function coaching($context, $prompt_type) {
        $system_prompts = array(
            'coach' => 'You are an executive coach. Review the user\'s work and learnings, then provide coaching insights as actionable notes.',
            'business' => 'You are a business owner advisor. Reframe the user\'s work from a business ownership perspective.',
            'pm' => 'You are a product management coach. Reframe the user\'s work from a product manager\'s perspective.',
            'generate_plan' => 'You are a planning assistant. Generate a structured plan based on the user\'s context.',
        );

        $system = isset($system_prompts[$prompt_type]) ? $system_prompts[$prompt_type] : $system_prompts['coach'];

        $user_message = "Context: {$context['page']['title']}\n\n";

        if (!empty($context['recent_items'])) {
            $user_message .= "Current Items:\n";
            foreach ($context['recent_items'] as $item) {
                $user_message .= "- {$item['title']}\n";
            }
            $user_message .= "\n";
        }

        if (!empty($context['learnings'])) {
            $user_message .= "Key Learnings:\n";
            foreach ($context['learnings'] as $learning) {
                $user_message .= "- {$learning['title']}: {$learning['content']}\n";
            }
            $user_message .= "\n";
        }

        $user_message .= "Generate 2-5 specific, actionable insights or recommendations as separate notes.\n\n";
        $user_message .= "Respond ONLY with valid JSON in this format:\n";
        $user_message .= '[{"title": "Note title", "content": "Note content", "item_type": "learning|info|task"}]';

        $response = $this->request(array(
            array(
                'role' => 'user',
                'content' => $user_message,
            ),
        ), 2048, $system);

        if (is_wp_error($response)) {
            return $response;
        }

        $text = isset($response['content'][0]['text']) ? $response['content'][0]['text'] : '';

        // Strip markdown code blocks if present
        $text = preg_replace('/```json\s*/i', '', $text);
        $text = preg_replace('/```\s*$/i', '', $text);
        $text = trim($text);

        // Extract JSON array
        $json_start = strpos($text, '[');
        $json_end = strrpos($text, ']');

        if ($json_start !== false && $json_end !== false) {
            $json_str = substr($text, $json_start, $json_end - $json_start + 1);
            $candidates = json_decode($json_str, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($candidates)) {
                return array(
                    'candidates' => $candidates,
                    'model' => $this->model,
                    'raw_response' => $text,
                );
            }
        }

        // More detailed error for debugging
        $error_msg = 'Could not parse coaching response';
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
    public function request_with_conversation($system_prompt, $user_message, $conversation_history = array(), $max_tokens = 4096) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', 'AI API key not configured');
        }

        // Build messages array
        $messages = array();

        // Include conversation history (limit to last 10 turns to avoid token limits)
        // Filter to only include 'user' and 'assistant' roles (Claude API doesn't accept 'system' in messages)
        $history_limit = 10;
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
        $response = $this->request($messages, $max_tokens, $system_prompt);

        if (is_wp_error($response)) {
            return $response;
        }

        // Extract content from response
        $content = isset($response['content'][0]['text']) ? $response['content'][0]['text'] : '';

        return array(
            'content' => $content,
            'model' => $this->model,
            'usage' => isset($response['usage']) ? $response['usage'] : null
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
            'model' => $this->model,
        );
    }
}
