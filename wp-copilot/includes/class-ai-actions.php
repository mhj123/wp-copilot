<?php
/**
 * AI Actions
 *
 * Handles all AI action types with context building and proposal management
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_AI_Actions {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Constructor
    }

    /**
     * Execute coaching dialogue action
     *
     * @param string $prompt User's question/prompt
     * @param int $page_id Current page ID for context
     * @param bool $use_rag Whether to include RAG items
     * @param string $conversation_id Conversation ID
     * @return array|WP_Error Response with message and metadata
     */
    public function coaching_dialogue($prompt, $page_id, $use_rag, $conversation_id) {
        // Get current user
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_Error('auth_error', 'User not authenticated');
        }

        // Build context
        $context_builder = WCP_Context_Builder::instance();
        $context_data = $context_builder->build_hierarchical_context($page_id, array(
            'include_items' => true,
            'item_limit' => 20,
            'use_rag' => $use_rag,
            'query' => $prompt,
            'rag_limit' => 10
        ));

        // Build system prompt (2 layers)
        $prompt_builder = WCP_Prompt_Builder::instance();
        $system_prompt = $prompt_builder->build_system_prompt('coaching');

        // Build user message with context
        $user_message = $prompt_builder->build_user_message($prompt, $context_data);

        // Get conversation history
        $conversations_manager = WCP_Conversations_Manager::instance();
        $messages = $conversations_manager->get_messages($conversation_id, 10);

        // Format conversation history for AI
        $conversation_history = array();
        foreach ($messages as $msg) {
            $conversation_history[] = array(
                'role' => $msg['role'],
                'content' => $msg['content']
            );
        }

        // Call AI with conversation history
        $ai_client = WCP_AI_Client::instance();
        $response = $ai_client->request_with_conversation(
            $system_prompt,
            $user_message,
            $conversation_history,
            4096
        );

        if (is_wp_error($response)) {
            return $response;
        }

        // Save user message to conversation
        $conversations_manager->add_message($conversation_id, 'user', $prompt);

        // Save assistant response to conversation
        $conversations_manager->add_message($conversation_id, 'assistant', $response['content']);

        // Log AI action
        $logger = WCP_AI_Logger::instance();
        $logger->log_action(array(
            'action_type' => 'coaching_dialogue',
            'user_id' => $user_id,
            'model' => $response['model'],
            'prompt' => $prompt,
            'input_context' => json_encode($context_data),
            'output_snapshot' => $response['content'],
            'context_post_id' => $page_id,
            'accepted_items' => array(),
            'dismissed_items' => array()
        ));

        return array(
            'outcome' => 'chat',
            'message' => $response['content'],
            'metadata' => array(
                'model' => $response['model'],
                'tokens' => $response['usage'] ?? null
            )
        );
    }

    /**
     * Execute generate single item action
     *
     * @param string $prompt User's request for item generation
     * @param int $page_id Current page ID for context
     * @param bool $use_rag Whether to include RAG items
     * @param string $conversation_id Conversation ID
     * @return array|WP_Error Response with proposals requiring approval
     */
    public function generate_single_item($prompt, $page_id, $use_rag, $conversation_id) {
        // Get current user
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_Error('auth_error', 'User not authenticated');
        }

        // Build context
        $context_builder = WCP_Context_Builder::instance();
        $context_data = $context_builder->build_hierarchical_context($page_id, array(
            'include_items' => true,
            'item_limit' => 20,
            'use_rag' => $use_rag,
            'query' => $prompt,
            'rag_limit' => 10
        ));

        // Build system prompt (2 layers)
        $prompt_builder = WCP_Prompt_Builder::instance();
        $system_prompt = $prompt_builder->build_system_prompt('generate-single');

        // Build user message with context
        $user_message = $prompt_builder->build_user_message($prompt, $context_data);

        // Get conversation history
        $conversations_manager = WCP_Conversations_Manager::instance();
        $messages = $conversations_manager->get_messages($conversation_id, 10);

        // Format conversation history for AI
        $conversation_history = array();
        foreach ($messages as $msg) {
            $conversation_history[] = array(
                'role' => $msg['role'],
                'content' => $msg['content']
            );
        }

        // Call AI with conversation history
        $ai_client = WCP_AI_Client::instance();
        $response = $ai_client->request_with_conversation(
            $system_prompt,
            $user_message,
            $conversation_history,
            4096
        );

        if (is_wp_error($response)) {
            return $response;
        }

        // Save user message to conversation
        $conversations_manager->add_message($conversation_id, 'user', $prompt);

        // Parse JSON from response
        $parsed_item = $this->parse_json_response($response['content']);

        if (is_wp_error($parsed_item)) {
            // Save error to conversation
            $error_msg = 'Failed to parse AI response: ' . $parsed_item->get_error_message();
            $conversations_manager->add_message($conversation_id, 'system', $error_msg);

            return $parsed_item;
        }

        // Validate item structure
        if (!isset($parsed_item['title']) || !isset($parsed_item['content'])) {
            $error = new WP_Error('invalid_item', 'AI response missing required fields (title, content)');
            $conversations_manager->add_message($conversation_id, 'system', $error->get_error_message());
            return $error;
        }

        // Create proposal
        $proposal_id = wp_generate_uuid4();
        $proposal = array(
            'proposal_id' => $proposal_id,
            'action_type' => 'generate-single',
            'item' => $parsed_item,
            'conversation_id' => $conversation_id,
            'page_id' => $page_id,
            'created_at' => current_time('mysql')
        );

        // Store proposal in transient (expires in 1 hour)
        set_transient('wcp_proposal_' . $proposal_id, $proposal, HOUR_IN_SECONDS);

        // Save assistant response to conversation (with proposal reference)
        $conversations_manager->add_message(
            $conversation_id,
            'assistant',
            'Generated item proposal (requires approval)',
            array('proposal_id' => $proposal_id)
        );

        // Log AI action (pending acceptance)
        $logger = WCP_AI_Logger::instance();
        $action_id = $logger->log_action(array(
            'action_type' => 'generate_single_item',
            'user_id' => $user_id,
            'model' => $response['model'],
            'prompt' => $prompt,
            'input_context' => json_encode($context_data),
            'output_snapshot' => json_encode($parsed_item),
            'context_post_id' => $page_id,
            'accepted_items' => array(),
            'dismissed_items' => array()
        ));

        return array(
            'outcome' => 'create_items',
            'proposals' => array($proposal),
            'action_id' => $action_id,
            'metadata' => array(
                'model' => $response['model'],
                'tokens' => $response['usage'] ?? null
            )
        );
    }

    /**
     * Chat/Q&A action for frontend widget
     *
     * @param string $prompt User's question
     * @param int $page_id Current page ID
     * @param string $context_mode Context mode: 'page', 'corpus', or 'select'
     * @param array $selected_pages Array of page IDs (for 'select' mode)
     * @param string $conversation_id Conversation ID
     * @return array|WP_Error Response with message
     */
    public function chat_qa($prompt, $page_id, $context_mode = 'page', $selected_pages = array(), $conversation_id = null) {
        // Get current user
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_Error('auth_error', 'User not authenticated');
        }

        // Build context based on mode
        $context_builder = WCP_Context_Builder::instance();
        $context_data = $context_builder->build_context_by_mode($page_id, $context_mode, array(
            'selected_pages' => $selected_pages,
            'query' => $prompt,
            'include_items' => true,
            'item_limit' => 20
        ));

        // Build system prompt (2 layers)
        $prompt_builder = WCP_Prompt_Builder::instance();
        $system_prompt = $prompt_builder->build_system_prompt('chat');

        // Build user message with context
        $user_message = $prompt_builder->build_user_message($prompt, $context_data);

        // Get conversation history if provided
        $conversation_history = array();
        if ($conversation_id) {
            $conversations_manager = WCP_Conversations_Manager::instance();
            $messages = $conversations_manager->get_messages($conversation_id, 10);

            foreach ($messages as $msg) {
                $conversation_history[] = array(
                    'role' => $msg['role'],
                    'content' => $msg['content']
                );
            }
        }

        // Call AI
        $ai_client = WCP_AI_Client::instance();
        $response = $ai_client->request_with_conversation(
            $system_prompt,
            $user_message,
            $conversation_history,
            4096
        );

        if (is_wp_error($response)) {
            return $response;
        }

        // Save messages to conversation if provided
        if ($conversation_id) {
            $conversations_manager = WCP_Conversations_Manager::instance();
            $conversations_manager->add_message($conversation_id, 'user', $prompt);
            $conversations_manager->add_message($conversation_id, 'assistant', $response['content']);
        }

        // Log AI action
        $logger = WCP_AI_Logger::instance();
        $logger->log_action(array(
            'action_type' => 'chat_qa',
            'user_id' => $user_id,
            'model' => $response['model'],
            'prompt' => $prompt,
            'input_context' => json_encode(array('context_mode' => $context_mode, 'page_id' => $page_id)),
            'output_snapshot' => $response['content'],
            'context_post_id' => $page_id,
            'accepted_items' => array(),
            'dismissed_items' => array()
        ));

        return array(
            'outcome' => 'chat',
            'message' => $response['content'],
            'metadata' => array(
                'model' => $response['model'],
                'tokens' => $response['usage'] ?? null
            )
        );
    }

    /**
     * Expand/modify draft content for editor sidebar
     *
     * @param string $prompt User's instruction for how to modify the draft
     * @param string $draft_content Current draft content from editor
     * @param int $post_id Post ID being edited
     * @param string $context_mode Context mode: 'page', 'corpus', or 'select'
     * @param array $selected_pages Array of page IDs (for 'select' mode)
     * @return array|WP_Error Response with expanded content
     */
    public function expand_draft($prompt, $draft_content, $post_id, $context_mode = 'page', $selected_pages = array()) {
        // Get current user
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_Error('auth_error', 'User not authenticated');
        }

        // Get the post to determine page context
        $post = get_post($post_id);
        $page_id = null;

        if ($post) {
            if ($post->post_type === 'page') {
                $page_id = $post_id;
            } else {
                // For posts, try to get the page context from taxonomy
                $terms = wp_get_post_terms($post_id, 'wcp_context');
                if (!empty($terms) && !is_wp_error($terms)) {
                    // Get the page ID from term meta
                    $ref_id = get_term_meta($terms[0]->term_id, 'wcp_ref_id', true);
                    if ($ref_id) {
                        $page_id = intval($ref_id);
                    }
                }
            }
        }

        // Build context based on mode
        $context_builder = WCP_Context_Builder::instance();
        $context_data = $context_builder->build_context_by_mode($page_id, $context_mode, array(
            'selected_pages' => $selected_pages,
            'query' => $prompt,
            'include_items' => true,
            'item_limit' => 10
        ));

        // Build system prompt (2 layers)
        $prompt_builder = WCP_Prompt_Builder::instance();
        $system_prompt = $prompt_builder->build_system_prompt('expand_draft');

        // Build user message with draft content
        $user_message = "User Instruction:\n{$prompt}\n\n";
        $user_message .= "Current Draft:\n{$draft_content}\n\n";

        // Add context
        $formatted_context = $context_builder->format_for_prompt($context_data);
        if (!empty($formatted_context)) {
            $user_message .= $formatted_context;
        }

        // Call AI (no conversation history for draft expansion)
        $ai_client = WCP_AI_Client::instance();
        $response = $ai_client->request_with_conversation(
            $system_prompt,
            $user_message,
            array(),
            4096
        );

        if (is_wp_error($response)) {
            return $response;
        }

        // Log AI action
        $logger = WCP_AI_Logger::instance();
        $logger->log_action(array(
            'action_type' => 'expand_draft',
            'user_id' => $user_id,
            'model' => $response['model'],
            'prompt' => $prompt,
            'input_context' => json_encode(array(
                'draft_length' => strlen($draft_content),
                'context_mode' => $context_mode,
                'post_id' => $post_id
            )),
            'output_snapshot' => $response['content'],
            'context_post_id' => $post_id,
            'accepted_items' => array(),
            'dismissed_items' => array()
        ));

        return array(
            'outcome' => 'content',
            'content' => $response['content'],
            'metadata' => array(
                'model' => $response['model'],
                'tokens' => $response['usage'] ?? null
            )
        );
    }

    /**
     * Generate items with context mode support
     *
     * @param string $prompt User's request for item generation
     * @param int $page_id Current page ID for context
     * @param string $context_mode Context mode: 'page', 'corpus', or 'select'
     * @param array $selected_pages Array of page IDs (for 'select' mode)
     * @param string $conversation_id Conversation ID
     * @return array|WP_Error Response with proposals requiring approval
     */
    public function generate_items($prompt, $page_id, $context_mode = 'page', $selected_pages = array(), $conversation_id = null) {
        // Get current user
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_Error('auth_error', 'User not authenticated');
        }

        // Build context based on mode
        $context_builder = WCP_Context_Builder::instance();
        $context_data = $context_builder->build_context_by_mode($page_id, $context_mode, array(
            'selected_pages' => $selected_pages,
            'query' => $prompt,
            'include_items' => true,
            'item_limit' => 20
        ));

        // Build system prompt (2 layers)
        $prompt_builder = WCP_Prompt_Builder::instance();
        $system_prompt = $prompt_builder->build_system_prompt('generate-single');

        // Build user message with context
        $user_message = $prompt_builder->build_user_message($prompt, $context_data);

        // Get conversation history if provided
        $conversation_history = array();
        if ($conversation_id) {
            $conversations_manager = WCP_Conversations_Manager::instance();
            $messages = $conversations_manager->get_messages($conversation_id, 10);

            foreach ($messages as $msg) {
                $conversation_history[] = array(
                    'role' => $msg['role'],
                    'content' => $msg['content']
                );
            }
        }

        // Call AI
        $ai_client = WCP_AI_Client::instance();
        $response = $ai_client->request_with_conversation(
            $system_prompt,
            $user_message,
            $conversation_history,
            4096
        );

        if (is_wp_error($response)) {
            return $response;
        }

        // Save user message to conversation
        if ($conversation_id) {
            $conversations_manager = WCP_Conversations_Manager::instance();
            $conversations_manager->add_message($conversation_id, 'user', $prompt);
        }

        // Parse JSON from response
        $parsed_item = $this->parse_json_response($response['content']);

        if (is_wp_error($parsed_item)) {
            // Save error to conversation
            if ($conversation_id) {
                $error_msg = 'Failed to parse AI response: ' . $parsed_item->get_error_message();
                $conversations_manager->add_message($conversation_id, 'system', $error_msg);
            }
            return $parsed_item;
        }

        // Validate item structure
        if (!isset($parsed_item['title']) || !isset($parsed_item['content'])) {
            $error = new WP_Error('invalid_item', 'AI response missing required fields (title, content)');
            if ($conversation_id) {
                $conversations_manager->add_message($conversation_id, 'system', $error->get_error_message());
            }
            return $error;
        }

        // Create proposal
        $proposal_id = wp_generate_uuid4();
        $proposal = array(
            'proposal_id' => $proposal_id,
            'action_type' => 'generate-single',
            'item' => $parsed_item,
            'conversation_id' => $conversation_id,
            'page_id' => $page_id,
            'created_at' => current_time('mysql')
        );

        // Store proposal in transient (expires in 1 hour)
        set_transient('wcp_proposal_' . $proposal_id, $proposal, HOUR_IN_SECONDS);

        // Save assistant response to conversation (with proposal reference)
        if ($conversation_id) {
            $conversations_manager->add_message(
                $conversation_id,
                'assistant',
                'Generated item proposal (requires approval)',
                array('proposal_id' => $proposal_id)
            );
        }

        // Log AI action
        $logger = WCP_AI_Logger::instance();
        $action_id = $logger->log_action(array(
            'action_type' => 'generate_items',
            'user_id' => $user_id,
            'model' => $response['model'],
            'prompt' => $prompt,
            'input_context' => json_encode(array('context_mode' => $context_mode, 'page_id' => $page_id)),
            'output_snapshot' => json_encode($parsed_item),
            'context_post_id' => $page_id,
            'accepted_items' => array(),
            'dismissed_items' => array()
        ));

        return array(
            'outcome' => 'create_items',
            'proposals' => array($proposal),
            'action_id' => $action_id,
            'metadata' => array(
                'model' => $response['model'],
                'tokens' => $response['usage'] ?? null
            )
        );
    }

    /**
     * Execute proposal (accept items and create posts)
     *
     * @param string $proposal_id Proposal ID
     * @param array $accepted_items Array of item indices to accept
     * @return array|WP_Error Created post IDs or error
     */
    public function execute_proposal($proposal_id, $accepted_items = array()) {
        // Get current user
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_Error('auth_error', 'User not authenticated');
        }

        // Get proposal from transient
        $proposal = get_transient('wcp_proposal_' . $proposal_id);

        if (!$proposal) {
            return new WP_Error('proposal_not_found', 'Proposal not found or expired');
        }

        $created_posts = array();
        $page_id = $proposal['page_id'];

        // Get or create context term for this page
        $terms = get_terms(array(
            'taxonomy' => 'wcp_context',
            'hide_empty' => false,
            'meta_query' => array(
                array('key' => 'wcp_ref_type', 'value' => 'page'),
                array('key' => 'wcp_ref_id', 'value' => $page_id),
            ),
        ));

        $context_term_id = null;
        if (!empty($terms) && !is_wp_error($terms)) {
            $context_term_id = $terms[0]->term_id;
        }

        if ($proposal['action_type'] === 'generate-single') {
            // Single item
            $item = $proposal['item'];

            $post_id = wp_insert_post(array(
                'post_type' => 'post',
                'post_title' => sanitize_text_field($item['title']),
                'post_content' => wp_kses_post($item['content']),
                'post_status' => 'publish',
                'post_author' => $user_id,
            ));

            if (!is_wp_error($post_id)) {
                // Add to context if term exists
                if ($context_term_id) {
                    wp_set_post_terms($post_id, array($context_term_id), 'wcp_context');
                }

                // Set item type if provided
                if (!empty($item['item_type'])) {
                    wp_set_post_terms($post_id, array($item['item_type']), 'wcp_item_type');
                }

                $created_posts[] = $post_id;
            }
        }

        // Delete proposal transient
        delete_transient('wcp_proposal_' . $proposal_id);

        // Update AI action log with accepted items
        global $wpdb;
        $table = $wpdb->prefix . 'wcp_ai_actions';

        // Find recent action for this proposal (within last hour)
        $action = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table
            WHERE context_post_id = %d
            AND action_type = %s
            AND timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ORDER BY timestamp DESC LIMIT 1",
            $page_id,
            $proposal['action_type'] === 'generate-single' ? 'generate_single_item' : 'generate_multiple_items'
        ));

        if ($action) {
            $wpdb->update(
                $table,
                array('accepted_items' => json_encode($created_posts)),
                array('id' => $action->id),
                array('%s'),
                array('%d')
            );
        }

        return array(
            'created_posts' => $created_posts,
            'message' => sprintf('Created %d item(s)', count($created_posts))
        );
    }

    /**
     * Parse JSON from AI response (handles markdown code blocks)
     *
     * @param string $response AI response text
     * @return array|WP_Error Parsed JSON or error
     */
    private function parse_json_response($response) {
        // Remove markdown code blocks if present
        $response = preg_replace('/```json\s*/', '', $response);
        $response = preg_replace('/```\s*$/', '', $response);
        $response = trim($response);

        // Try to find JSON in response
        $json_start = strpos($response, '{');
        $json_start_array = strpos($response, '[');

        if ($json_start === false && $json_start_array === false) {
            return new WP_Error('no_json', 'No JSON found in response');
        }

        // Use whichever comes first
        if ($json_start !== false && ($json_start_array === false || $json_start < $json_start_array)) {
            $json_text = substr($response, $json_start);
        } else {
            $json_text = substr($response, $json_start_array);
        }

        // Decode JSON
        $parsed = json_decode($json_text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_parse_error', 'Failed to parse JSON: ' . json_last_error_msg());
        }

        return $parsed;
    }
}
