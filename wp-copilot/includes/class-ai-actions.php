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

        // Build context with character limits
        $context_builder = WCP_Context_Builder::instance();
        $context_data = $context_builder->build_hierarchical_context($page_id, array(
            'include_items' => true,
            'item_limit' => 20,
            'use_rag' => $use_rag,
            'query' => $prompt,
            'rag_limit' => 10,
            'limits' => array(
                'max_chars_per_item' => 50000,
                'max_chars_page_summary' => 8000
            )
        ));

        // Build system prompt (4 layers)
        $prompt_builder = WCP_Prompt_Builder::instance();
        $system_prompt = $prompt_builder->build_system_prompt('coaching', $page_id);

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

        // Build context with character limits
        $context_builder = WCP_Context_Builder::instance();
        $context_data = $context_builder->build_hierarchical_context($page_id, array(
            'include_items' => true,
            'item_limit' => 20,
            'use_rag' => $use_rag,
            'query' => $prompt,
            'rag_limit' => 10,
            'limits' => array(
                'max_chars_per_item' => 50000,
                'max_chars_page_summary' => 8000
            )
        ));

        // Build system prompt (4 layers)
        $prompt_builder = WCP_Prompt_Builder::instance();
        $system_prompt = $prompt_builder->build_system_prompt('generate-single', $page_id);

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

        // Build context based on mode with character limits
        $context_builder = WCP_Context_Builder::instance();
        $context_data = $context_builder->build_context_by_mode($page_id, $context_mode, array(
            'selected_pages' => $selected_pages,
            'query' => $prompt,
            'include_items' => true,
            'item_limit' => 20,
            'limits' => array(
                'max_chars_per_item' => 50000,
                'max_chars_page_summary' => 8000
            )
        ));

        // Build system prompt (4 layers)
        $prompt_builder = WCP_Prompt_Builder::instance();
        $system_prompt = $prompt_builder->build_system_prompt('chat', $page_id);

        // Build user message with context (limits are already in context_data)
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
     * Site-level: a readable taxonomy outline of the whole corpus (every
     * Page → Heading, walked from the wcp_context taxonomy tree).
     * AI guardrail: read-only — answers in chat, writes nothing.
     */
    public function taxonomy_outline($prompt, $conversation_id = null) {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_Error('auth_error', 'User not authenticated');
        }

        $terms   = WCP_Taxonomy_Sync::get_all_contexts();
        $outline = is_wp_error($terms) ? '' : $this->format_taxonomy_outline_text($terms);
        if (empty($outline)) {
            return new WP_Error('no_structure', 'No page/heading structure found to outline');
        }

        $prompt_builder = WCP_Prompt_Builder::instance();
        $system_prompt  = $prompt_builder->build_system_prompt('taxonomy_outline', 0);
        $user_message   = trim($prompt) . "\n\nSite structure (Page → Heading):\n" . $outline;

        $conversation_history = array();
        if ($conversation_id) {
            $cm = WCP_Conversations_Manager::instance();
            foreach ($cm->get_messages($conversation_id, 10) as $msg) {
                $conversation_history[] = array('role' => $msg['role'], 'content' => $msg['content']);
            }
        }

        $ai_client = WCP_AI_Client::instance();
        $response  = $ai_client->request_with_conversation($system_prompt, $user_message, $conversation_history, 2048);
        if (is_wp_error($response)) {
            return $response;
        }

        if ($conversation_id) {
            $cm = WCP_Conversations_Manager::instance();
            $cm->add_message($conversation_id, 'user', $prompt);
            $cm->add_message($conversation_id, 'assistant', $response['content']);
        }

        WCP_AI_Logger::instance()->log_action(array(
            'action_type'     => 'taxonomy_outline',
            'user_id'         => $user_id,
            'model'           => $response['model'],
            'prompt'          => $prompt,
            'input_context'   => json_encode(array('term_count' => count($terms))),
            'output_snapshot' => $response['content'],
            'context_post_id' => 0,
            'accepted_items'  => array(),
            'dismissed_items' => array(),
        ));

        return array(
            'outcome'  => 'chat',
            'message'  => $response['content'],
            'metadata' => array('model' => $response['model'], 'tokens' => $response['usage'] ?? null),
        );
    }

    /**
     * Recursively render a wcp_context term tree (as returned by
     * WCP_Taxonomy_Sync::get_all_contexts()) into an indented text outline.
     * Mirrors build_structure_snapshot()'s "readable text from taxonomy/post
     * data" style below.
     */
    private function format_taxonomy_outline_text($terms, $parent_id = 0, $depth = 0) {
        $lines = array();
        foreach ($terms as $term) {
            if ((int) $term->parent !== (int) $parent_id) {
                continue;
            }
            $ref_type = get_term_meta($term->term_id, 'wcp_ref_type', true);
            $label    = $ref_type === 'wcp_heading' ? 'heading' : 'page';
            $lines[]  = str_repeat('  ', $depth) . "- {$term->name} ({$label})";
            $child_outline = $this->format_taxonomy_outline_text($terms, $term->term_id, $depth + 1);
            if ($child_outline !== '') {
                $lines[] = $child_outline;
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Site-level: the 5 most important things to work on, weighed against
     * the global mission and recent site-wide activity.
     * AI guardrail: read-only — answers in chat, writes nothing.
     */
    public function mission_priorities($prompt, $conversation_id = null) {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_Error('auth_error', 'User not authenticated');
        }

        $mission = WCP_Mission_Loader::instance()->get_global_mission();
        if (empty($mission)) {
            return new WP_Error('no_mission', 'No mission is set — add one before using this action');
        }

        $items      = WCP_Context_Builder::instance()->get_recent_items_sitewide(20);
        $items_text = '';
        foreach ($items as $it) {
            $excerpt = wp_strip_all_tags($it['content'] ?? '');
            $excerpt = $excerpt ? ' — ' . mb_substr($excerpt, 0, 200) : '';
            $items_text .= "- {$it['title']}{$excerpt}\n";
        }

        $prompt_builder = WCP_Prompt_Builder::instance();
        $system_prompt  = $prompt_builder->build_system_prompt('mission_priorities', 0);
        $user_message   = trim($prompt) . "\n\nMission:\n{$mission}"
            . "\n\nRecently created items:\n" . ($items_text ?: '(none)');

        $conversation_history = array();
        if ($conversation_id) {
            $cm = WCP_Conversations_Manager::instance();
            foreach ($cm->get_messages($conversation_id, 10) as $msg) {
                $conversation_history[] = array('role' => $msg['role'], 'content' => $msg['content']);
            }
        }

        $ai_client = WCP_AI_Client::instance();
        $response  = $ai_client->request_with_conversation($system_prompt, $user_message, $conversation_history, 2048);
        if (is_wp_error($response)) {
            return $response;
        }

        if ($conversation_id) {
            $cm = WCP_Conversations_Manager::instance();
            $cm->add_message($conversation_id, 'user', $prompt);
            $cm->add_message($conversation_id, 'assistant', $response['content']);
        }

        WCP_AI_Logger::instance()->log_action(array(
            'action_type'     => 'mission_priorities',
            'user_id'         => $user_id,
            'model'           => $response['model'],
            'prompt'          => $prompt,
            'input_context'   => json_encode(array('item_count' => count($items))),
            'output_snapshot' => $response['content'],
            'context_post_id' => 0,
            'accepted_items'  => array(),
            'dismissed_items' => array(),
        ));

        return array(
            'outcome'  => 'chat',
            'message'  => $response['content'],
            'metadata' => array('model' => $response['model'], 'tokens' => $response['usage'] ?? null),
        );
    }

    /**
     * Site-level: a chat-delivered weekly activity summary. Reuses the same
     * query + prompt approach as WCP_Rest_API::generate_activity_summary()
     * (which powers the dashboard's "This week" card) but returns a chat
     * reply saved to the conversation, rather than that endpoint's bespoke
     * {summary, post_count, generated_at} shape / 6-hour transient cache.
     * AI guardrail: read-only — answers in chat, writes nothing.
     */
    public function weekly_summary($prompt, $conversation_id = null) {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_Error('auth_error', 'User not authenticated');
        }

        $posts = get_posts(array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'date_query'     => array(array('after' => '7 days ago', 'inclusive' => true)),
        ));

        if (empty($posts)) {
            $message = 'No items were created in the last 7 days.';
            if ($conversation_id) {
                $cm = WCP_Conversations_Manager::instance();
                $cm->add_message($conversation_id, 'user', $prompt);
                $cm->add_message($conversation_id, 'assistant', $message);
            }
            return array('outcome' => 'chat', 'message' => $message, 'metadata' => array());
        }

        $items_text = '';
        foreach ($posts as $p) {
            $excerpt  = wp_strip_all_tags($p->post_content);
            $excerpt  = $excerpt ? ' — ' . mb_substr($excerpt, 0, 200) : '';
            $contexts = wp_get_post_terms($p->ID, 'wcp_context', array('fields' => 'names'));
            $ctx      = !empty($contexts) && !is_wp_error($contexts) ? ' [' . implode(', ', $contexts) . ']' : '';
            $items_text .= "- {$p->post_title}{$ctx}{$excerpt}\n";
        }

        $mission      = WCP_Mission_Loader::instance()->get_global_mission();
        $mission_line = $mission ? "\n\nMission:\n{$mission}" : '';

        $count = count($posts);
        $prompt_builder = WCP_Prompt_Builder::instance();
        $system_prompt  = $prompt_builder->build_system_prompt('weekly_summary', 0);
        $user_message   = trim($prompt) . "\n\nItems created in the last 7 days ({$count} total):\n\n{$items_text}{$mission_line}";

        $conversation_history = array();
        if ($conversation_id) {
            $cm = WCP_Conversations_Manager::instance();
            foreach ($cm->get_messages($conversation_id, 10) as $msg) {
                $conversation_history[] = array('role' => $msg['role'], 'content' => $msg['content']);
            }
        }

        $ai_client = WCP_AI_Client::instance();
        $response  = $ai_client->request_with_conversation($system_prompt, $user_message, $conversation_history, 1024);
        if (is_wp_error($response)) {
            return $response;
        }

        if ($conversation_id) {
            $cm = WCP_Conversations_Manager::instance();
            $cm->add_message($conversation_id, 'user', $prompt);
            $cm->add_message($conversation_id, 'assistant', $response['content']);
        }

        WCP_AI_Logger::instance()->log_action(array(
            'action_type'     => 'weekly_summary',
            'user_id'         => $user_id,
            'model'           => $response['model'],
            'prompt'          => $prompt,
            'input_context'   => json_encode(array('post_count' => $count)),
            'output_snapshot' => $response['content'],
            'context_post_id' => 0,
            'accepted_items'  => array(),
            'dismissed_items' => array(),
        ));

        return array(
            'outcome'  => 'chat',
            'message'  => $response['content'],
            'metadata' => array('model' => $response['model'], 'tokens' => $response['usage'] ?? null),
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

        // Validate draft content
        if (empty($draft_content)) {
            return new WP_Error('empty_draft', 'Draft content is empty. Please add some content first.');
        }

        $draft_length = strlen($draft_content);
        if ($draft_length > 20000) {
            return new WP_Error('draft_too_large', 'Draft content is too large (' . number_format($draft_length) . ' chars). Maximum is 15,000 characters. Please reduce content size or work in sections.');
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

        // Build context based on mode with character limits
        $context_builder = WCP_Context_Builder::instance();
        $context_data = $context_builder->build_context_by_mode($page_id, $context_mode, array(
            'selected_pages' => $selected_pages,
            'query' => $prompt,
            'include_items' => true,
            'item_limit' => 10,
            'limits' => array(
                'max_chars_per_item' => 50000,
                'max_chars_page_summary' => 8000
            )
        ));

        // Build system prompt (4 layers)
        $prompt_builder = WCP_Prompt_Builder::instance();
        $system_prompt = $prompt_builder->build_system_prompt('expand_draft', $page_id);

        // Build user message with draft content
        $user_message = "User Instruction:\n{$prompt}\n\n";
        $user_message .= "Current Draft:\n{$draft_content}\n\n";

        // Add context with character limits applied
        $formatted_context = $context_builder->format_for_prompt($context_data, array(
            'max_chars_per_item' => 50000,
            'max_chars_page_summary' => 8000
        ));
        if (!empty($formatted_context)) {
            $user_message .= $formatted_context;
        }

        // Call AI with longer timeout for expand operations
        try {
            $ai_client = WCP_AI_Client::instance();
            $response = $ai_client->request_with_conversation(
                $system_prompt,
                $user_message,
                array(), // No conversation history for expand
                4096,    // max_tokens for response
                60       // 60-second timeout for expand operations
            );

            if (is_wp_error($response)) {
                // Log specific error
                error_log('WCP Expand Draft Error: ' . $response->get_error_message());

                // Return user-friendly error
                if (strpos($response->get_error_message(), 'timeout') !== false) {
                    return new WP_Error('timeout', 'AI request timed out. Try reducing the content size or try again.');
                } elseif (strpos($response->get_error_message(), 'token') !== false) {
                    return new WP_Error('token_limit', 'Content is too large for AI processing. Please reduce content size and try again.');
                } else {
                    return $response; // Return original error
                }
            }
        } catch (Exception $e) {
            error_log('WCP Expand Draft Exception: ' . $e->getMessage());
            return new WP_Error('api_error', 'AI API error: ' . $e->getMessage());
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
     * @param int $item_count Optional number of items to generate (0 = let AI decide)
     * @return array|WP_Error Response with proposals requiring approval
     */
    public function generate_items($prompt, $page_id, $context_mode = 'page', $selected_pages = array(), $conversation_id = null, $item_count = 0) {
        set_time_limit(120);

        // Get current user
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_Error('auth_error', 'User not authenticated');
        }

        // Build context based on mode with character limits
        // Page summary limit is raised so the full page content (e.g. a list of items to process) is visible to the AI
        $context_builder = WCP_Context_Builder::instance();
        $context_data = $context_builder->build_context_by_mode($page_id, $context_mode, array(
            'selected_pages' => $selected_pages,
            'query' => $prompt,
            'include_items' => true,
            'item_limit' => 20,
            'limits' => array(
                'max_chars_per_item' => 50000,
                'max_chars_page_summary' => 8000
            )
        ));

        // Build system prompt (4 layers) - use generate-multiple
        $prompt_builder = WCP_Prompt_Builder::instance();
        $system_prompt = $prompt_builder->build_system_prompt('generate-multiple', $page_id, $item_count);

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

        // Call AI with extended timeout — generating multiple items can take >30s
        $ai_client = WCP_AI_Client::instance();
        $response = $ai_client->request_with_conversation(
            $system_prompt,
            $user_message,
            $conversation_history,
            4096,
            90
        );

        if (is_wp_error($response)) {
            return $response;
        }

        // Save user message to conversation
        if ($conversation_id) {
            $conversations_manager = WCP_Conversations_Manager::instance();
            $conversations_manager->add_message($conversation_id, 'user', $prompt);
        }

        // Parse JSON from response (expecting array of items)
        $parsed_items = $this->parse_json_response($response['content']);

        if (is_wp_error($parsed_items)) {
            // Save error to conversation
            if ($conversation_id) {
                $error_msg = 'Failed to parse AI response: ' . $parsed_items->get_error_message();
                $conversations_manager->add_message($conversation_id, 'system', $error_msg);
            }
            return $parsed_items;
        }

        // Normalize to array (in case AI returned single item)
        if (isset($parsed_items['title'])) {
            $parsed_items = array($parsed_items);
        }

        // Validate we have items
        if (empty($parsed_items) || !is_array($parsed_items)) {
            $error = new WP_Error('invalid_response', 'AI did not return any items');
            if ($conversation_id) {
                $conversations_manager->add_message($conversation_id, 'system', $error->get_error_message());
            }
            return $error;
        }

        // Create proposals for each item
        $proposals = array();
        $batch_id = wp_generate_uuid4(); // Group proposals together

        foreach ($parsed_items as $index => $item) {
            // Validate item structure
            if (!isset($item['title']) || !isset($item['content'])) {
                continue; // Skip invalid items
            }

            $proposal_id = wp_generate_uuid4();
            $proposal = array(
                'proposal_id' => $proposal_id,
                'batch_id' => $batch_id,
                'index' => $index,
                'action_type' => 'generate-multiple',
                'item' => $item,
                'conversation_id' => $conversation_id,
                'page_id' => $page_id,
                'created_at' => current_time('mysql')
            );

            // Store proposal in transient (expires in 1 hour)
            set_transient('wcp_proposal_' . $proposal_id, $proposal, HOUR_IN_SECONDS);

            $proposals[] = $proposal;
        }

        // Store batch info
        set_transient('wcp_batch_' . $batch_id, array(
            'proposal_ids' => array_column($proposals, 'proposal_id'),
            'page_id' => $page_id,
            'conversation_id' => $conversation_id
        ), HOUR_IN_SECONDS);

        // Save assistant response to conversation
        if ($conversation_id) {
            $item_count_msg = count($proposals);
            $conversations_manager->add_message(
                $conversation_id,
                'assistant',
                "Generated {$item_count_msg} item(s) for your review",
                array('batch_id' => $batch_id)
            );
        }

        // Log AI action
        $logger = WCP_AI_Logger::instance();
        $action_id = $logger->log_action(array(
            'action_type' => 'generate_items',
            'user_id' => $user_id,
            'model' => $response['model'],
            'prompt' => $prompt,
            'input_context' => json_encode(array('context_mode' => $context_mode, 'page_id' => $page_id, 'item_count' => $item_count)),
            'output_snapshot' => json_encode($parsed_items),
            'context_post_id' => $page_id,
            'accepted_items' => array(),
            'dismissed_items' => array()
        ));

        return array(
            'outcome' => 'create_items',
            'proposals' => $proposals,
            'batch_id' => $batch_id,
            'action_id' => $action_id,
            'metadata' => array(
                'model' => $response['model'],
                'tokens' => $response['usage'] ?? null
            )
        );
    }

    /**
     * Generate heading proposals for a page.
     * AI guardrail: headings are proposals only — never written directly.
     */
    public function generate_headings($prompt, $page_id, $context_mode = 'page', $selected_pages = array(), $conversation_id = null, $item_count = 0) {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_Error('auth_error', 'User not authenticated');
        }

        $context_builder = WCP_Context_Builder::instance();
        $context_data = $context_builder->build_context_by_mode($page_id, $context_mode, array(
            'selected_pages' => $selected_pages,
            'query' => $prompt,
            'include_items' => true,
            'item_limit' => 20,
            'limits' => array(
                'max_chars_per_item' => 50000,
                'max_chars_page_summary' => 8000
            )
        ));

        $prompt_builder = WCP_Prompt_Builder::instance();
        $system_prompt = $prompt_builder->build_system_prompt('generate-headings', $page_id, $item_count);
        $user_message = $prompt_builder->build_user_message($prompt, $context_data);

        $conversation_history = array();
        if ($conversation_id) {
            $conversations_manager = WCP_Conversations_Manager::instance();
            $messages = $conversations_manager->get_messages($conversation_id, 10);
            foreach ($messages as $msg) {
                $conversation_history[] = array('role' => $msg['role'], 'content' => $msg['content']);
            }
        }

        $ai_client = WCP_AI_Client::instance();
        $response = $ai_client->request_with_conversation($system_prompt, $user_message, $conversation_history, 2048, 90);

        if (is_wp_error($response)) {
            return $response;
        }

        if ($conversation_id) {
            $conversations_manager = WCP_Conversations_Manager::instance();
            $conversations_manager->add_message($conversation_id, 'user', $prompt);
        }

        $parsed = $this->parse_json_response($response['content']);
        if (is_wp_error($parsed)) {
            return $parsed;
        }

        // Normalise single object to array
        if (isset($parsed['title'])) {
            $parsed = array($parsed);
        }

        if (empty($parsed) || !is_array($parsed)) {
            return new WP_Error('invalid_response', 'AI did not return any headings');
        }

        $proposals = array();
        $batch_id = wp_generate_uuid4();

        foreach ($parsed as $index => $heading) {
            if (empty($heading['title'])) {
                continue;
            }

            $proposal_id = wp_generate_uuid4();
            $proposal = array(
                'proposal_id' => $proposal_id,
                'batch_id'    => $batch_id,
                'index'       => $index,
                'action_type' => 'generate_headings',
                'item'        => array('title' => sanitize_text_field($heading['title'])),
                'conversation_id' => $conversation_id,
                'page_id'     => $page_id,
                'created_at'  => current_time('mysql'),
            );

            set_transient('wcp_proposal_' . $proposal_id, $proposal, HOUR_IN_SECONDS);
            $proposals[] = $proposal;
        }

        set_transient('wcp_batch_' . $batch_id, array(
            'proposal_ids'    => array_column($proposals, 'proposal_id'),
            'page_id'         => $page_id,
            'conversation_id' => $conversation_id,
        ), HOUR_IN_SECONDS);

        if ($conversation_id) {
            $conversations_manager = WCP_Conversations_Manager::instance();
            $count = count($proposals);
            $conversations_manager->add_message($conversation_id, 'assistant',
                "Generated {$count} heading(s) for your review",
                array('batch_id' => $batch_id)
            );
        }

        $logger = WCP_AI_Logger::instance();
        $logger->log_action(array(
            'action_type'    => 'generate_headings',
            'user_id'        => $user_id,
            'model'          => $response['model'],
            'prompt'         => $prompt,
            'input_context'  => json_encode(array('context_mode' => $context_mode, 'page_id' => $page_id)),
            'output_snapshot' => json_encode($parsed),
            'context_post_id' => $page_id,
            'accepted_items' => array(),
            'dismissed_items' => array(),
        ));

        return array(
            'outcome'   => 'create_items',
            'proposals' => $proposals,
            'batch_id'  => $batch_id,
            'metadata'  => array('model' => $response['model'], 'tokens' => $response['usage'] ?? null),
        );
    }

    /**
     * Propose edits to the title/description of one or more existing items.
     * The model is shown candidate items with their IDs (context prompts
     * elsewhere don't expose IDs, so this builds its own listing) and must
     * reference one of those IDs for each edit — anything else is discarded
     * rather than guessed at.
     * AI guardrail: proposals only; execute_proposal() applies the edit only
     * once the user accepts it.
     */
    public function edit_items($prompt, $page_id, $context_mode = 'page', $selected_pages = array(), $conversation_id = null) {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_Error('auth_error', 'User not authenticated');
        }

        $context_builder = WCP_Context_Builder::instance();
        $context_data = $context_builder->build_context_by_mode($page_id, $context_mode, array(
            'selected_pages' => $selected_pages,
            'query' => $prompt,
            'include_items' => true,
            'item_limit' => 40,
        ));

        $candidate_items = $context_data['items'] ?? array();
        if (empty($candidate_items)) {
            return new WP_Error('no_items', 'No items found in this context to edit');
        }

        $by_id = array();
        $items_listing = '';
        foreach ($candidate_items as $it) {
            $by_id[(int) $it['id']] = $it;
            $content = wp_strip_all_tags($it['content'] ?? '');
            if (strlen($content) > 500) {
                $content = substr($content, 0, 500) . '…';
            }
            $items_listing .= "ID {$it['id']}: \"{$it['title']}\" — " . ($content !== '' ? $content : '(no description)') . "\n";
        }

        $sys = "You are helping the user edit the title and/or description of one or more existing items. "
             . "Below is a list of candidate items, each with its ID, current title, and current description. "
             . "Decide which item(s) the user's instruction refers to and propose a new title and description for "
             . "each. Only include items the instruction clearly refers to — do not touch items it doesn't mention. "
             . "Return ONLY a JSON array: [{\"id\": <item id, must match one from the list>, \"title\": \"...\", "
             . "\"content\": \"...\"}, ...]. Use the item's existing title/description for whichever of the two "
             . "the instruction doesn't ask you to change. Content may be an empty string if the item should end up "
             . "with no description.";
        $usr = "User instruction: {$prompt}\n\nCandidate items:\n{$items_listing}";

        $conversation_history = array();
        if ($conversation_id) {
            $conversations_manager = WCP_Conversations_Manager::instance();
            $messages = $conversations_manager->get_messages($conversation_id, 10);
            foreach ($messages as $msg) {
                $conversation_history[] = array('role' => $msg['role'], 'content' => $msg['content']);
            }
        }

        $ai_client = WCP_AI_Client::instance();
        $response = $ai_client->request_with_conversation($sys, $usr, $conversation_history, 2048, 90);

        if (is_wp_error($response)) {
            return $response;
        }

        if ($conversation_id) {
            $conversations_manager = WCP_Conversations_Manager::instance();
            $conversations_manager->add_message($conversation_id, 'user', $prompt);
        }

        $parsed = $this->parse_json_response($response['content']);
        if (is_wp_error($parsed)) {
            return $parsed;
        }
        if (isset($parsed['id'])) {
            $parsed = array($parsed);
        }
        if (empty($parsed) || !is_array($parsed)) {
            return new WP_Error('invalid_response', 'AI did not propose any edits');
        }

        $proposals = array();
        $batch_id  = wp_generate_uuid4();

        foreach ($parsed as $index => $edit) {
            $item_id = isset($edit['id']) ? (int) $edit['id'] : 0;
            if (!$item_id || !isset($by_id[$item_id])) {
                continue; // AI referenced something outside the candidate set — skip, don't guess
            }
            $original = $by_id[$item_id];

            $proposal_id = wp_generate_uuid4();
            $proposal = array(
                'proposal_id'     => $proposal_id,
                'batch_id'        => $batch_id,
                'index'           => $index,
                'action_type'     => 'edit_item',
                'item_id'         => $item_id,
                'original'        => array('title' => $original['title'], 'content' => $original['content']),
                'item'            => array(
                    'title'   => isset($edit['title']) ? $edit['title'] : $original['title'],
                    'content' => isset($edit['content']) ? $edit['content'] : $original['content'],
                ),
                'conversation_id' => $conversation_id,
                'page_id'         => $page_id,
                'created_at'      => current_time('mysql'),
            );

            set_transient('wcp_proposal_' . $proposal_id, $proposal, HOUR_IN_SECONDS);
            $proposals[] = $proposal;
        }

        if (empty($proposals)) {
            return new WP_Error('no_matching_items', 'Could not match the AI’s response to any item in this context');
        }

        set_transient('wcp_batch_' . $batch_id, array(
            'proposal_ids'    => array_column($proposals, 'proposal_id'),
            'page_id'         => $page_id,
            'conversation_id' => $conversation_id,
        ), HOUR_IN_SECONDS);

        if ($conversation_id) {
            $conversations_manager = WCP_Conversations_Manager::instance();
            $count = count($proposals);
            $conversations_manager->add_message($conversation_id, 'assistant',
                "Proposed edits to {$count} item(s) for your review",
                array('batch_id' => $batch_id)
            );
        }

        $logger = WCP_AI_Logger::instance();
        $logger->log_action(array(
            'action_type'     => 'edit_items',
            'user_id'         => $user_id,
            'model'           => $response['model'],
            'prompt'          => $prompt,
            'input_context'   => json_encode(array('context_mode' => $context_mode, 'page_id' => $page_id)),
            'output_snapshot' => json_encode($parsed),
            'context_post_id' => $page_id,
            'accepted_items'  => array(),
            'dismissed_items' => array(),
        ));

        return array(
            'outcome'   => 'edit_items',
            'proposals' => $proposals,
            'batch_id'  => $batch_id,
            'metadata'  => array('model' => $response['model'], 'tokens' => $response['usage'] ?? null),
        );
    }

    /**
     * Structure-aware generation: the model sees the current page's existing
     * headings (with ids) and items, and proposes new headings and/or items
     * placed under new headings, existing headings, or page level. Combines the
     * old generate_items + generate_headings into one action.
     * AI guardrail: everything is a proposal — nothing is written here.
     */
    public function generate_structure($prompt, $page_id, $context_mode = 'page', $selected_pages = array(), $conversation_id = null) {
        set_time_limit(120);
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_Error('auth_error', 'User not authenticated');
        }

        $page_term_id = $this->page_context_term_id($page_id);
        list($snapshot, $valid_heading_terms) = $this->build_structure_snapshot($page_id, $page_term_id);

        // Build item content section so the AI can reason about what already exists
        $context_builder = WCP_Context_Builder::instance();
        $context_data    = $context_builder->build_hierarchical_context( $page_id, array(
            'include_items' => true,
            'item_limit'    => 25,
        ) );
        // Ancestor page titles/hierarchy are already covered by the snapshot above,
        // but ancestor CONTENT isn't — and neither is the current page's own content,
        // which the snapshot never carried. Keep just the current page's content.
        $context_data['pages'] = array_values(array_filter($context_data['pages'], function($p) use ($page_id) {
            return isset($p['id']) && (int) $p['id'] === (int) $page_id;
        }));
        $items_context = $context_builder->format_for_prompt( $context_data );

        $prompt_builder = WCP_Prompt_Builder::instance();
        $system_prompt  = $prompt_builder->build_system_prompt('generate-structure', $page_id);
        $user_message   = trim($prompt)
            . "\n\nCURRENT PAGE STRUCTURE (use these ids for existing headings):\n" . $snapshot
            . ( $items_context ? "\n\n" . $items_context : '' );

        $conversation_history = array();
        if ($conversation_id) {
            $cm = WCP_Conversations_Manager::instance();
            foreach ($cm->get_messages($conversation_id, 10) as $msg) {
                $conversation_history[] = array('role' => $msg['role'], 'content' => $msg['content']);
            }
        }

        $ai_client = WCP_AI_Client::instance();
        $response  = $ai_client->request_with_conversation($system_prompt, $user_message, $conversation_history, 4096, 90);
        if (is_wp_error($response)) {
            return $response;
        }

        if ($conversation_id) {
            WCP_Conversations_Manager::instance()->add_message($conversation_id, 'user', $prompt);
        }

        $parsed = $this->parse_json_response($response['content']);
        if (is_wp_error($parsed)) {
            if ($conversation_id) {
                WCP_Conversations_Manager::instance()->add_message($conversation_id, 'system', 'Failed to parse AI response: ' . $parsed->get_error_message());
            }
            return $parsed;
        }

        $headings_in = (isset($parsed['headings']) && is_array($parsed['headings'])) ? $parsed['headings'] : array();
        $items_in    = (isset($parsed['items']) && is_array($parsed['items']))       ? $parsed['items']    : array();
        if (empty($headings_in) && empty($items_in)) {
            return new WP_Error('invalid_response', 'AI did not return any headings or items');
        }

        $batch_id         = wp_generate_uuid4();
        $valid_item_types = array('task', 'info', 'learning', 'spec');

        // New headings → proposals + ref map for the plan tree.
        $headings   = array();
        $ref_titles = array();
        foreach ($headings_in as $h) {
            $title = isset($h['title']) ? sanitize_text_field($h['title']) : '';
            $ref   = isset($h['ref'])   ? sanitize_text_field($h['ref'])   : '';
            if ($title === '' || $ref === '') {
                continue;
            }
            $pid  = wp_generate_uuid4();
            set_transient('wcp_proposal_' . $pid, array(
                'proposal_id' => $pid, 'batch_id' => $batch_id, 'action_type' => 'structure_heading',
                'ref' => $ref, 'title' => $title, 'page_id' => $page_id, 'created_at' => current_time('mysql'),
            ), HOUR_IN_SECONDS);
            $ref_titles[$ref] = $title;
            $headings[] = array('proposal_id' => $pid, 'ref' => $ref, 'title' => $title, 'items' => array());
        }

        // Items → proposals with validated targets, grouped for the plan tree.
        $page_items      = array();
        $existing_groups = array();
        foreach ($items_in as $it) {
            $title = isset($it['title']) ? sanitize_text_field($it['title']) : '';
            if ($title === '') {
                continue;
            }
            $content = isset($it['content']) ? wp_kses_post($it['content']) : '';
            $type    = isset($it['item_type']) ? sanitize_key($it['item_type']) : '';
            if (!in_array($type, $valid_item_types, true)) {
                $type = 'task';
            }

            $target   = (isset($it['target']) && is_array($it['target'])) ? $it['target'] : array('type' => 'page');
            $ttype    = isset($target['type']) ? $target['type'] : 'page';
            $resolved = array('type' => 'page');
            if ($ttype === 'new' && !empty($target['ref']) && isset($ref_titles[$target['ref']])) {
                $resolved = array('type' => 'new', 'ref' => sanitize_text_field($target['ref']));
            } elseif ($ttype === 'existing' && !empty($target['id']) && in_array((int) $target['id'], $valid_heading_terms, true)) {
                $resolved = array('type' => 'existing', 'id' => (int) $target['id']);
            }

            $pid = wp_generate_uuid4();
            set_transient('wcp_proposal_' . $pid, array(
                'proposal_id' => $pid, 'batch_id' => $batch_id, 'action_type' => 'structure_item',
                'item' => array('title' => $title, 'content' => $content, 'item_type' => $type),
                'target' => $resolved, 'page_id' => $page_id, 'created_at' => current_time('mysql'),
            ), HOUR_IN_SECONDS);

            $entry = array('proposal_id' => $pid, 'title' => $title, 'content' => $content, 'item_type' => $type);
            if ($resolved['type'] === 'new') {
                foreach ($headings as &$hh) {
                    if ($hh['ref'] === $resolved['ref']) { $hh['items'][] = $entry; break; }
                }
                unset($hh);
            } elseif ($resolved['type'] === 'existing') {
                $tid = $resolved['id'];
                if (!isset($existing_groups[$tid])) {
                    $term = get_term($tid, 'wcp_context');
                    $existing_groups[$tid] = array('term_id' => $tid, 'title' => ($term && !is_wp_error($term)) ? $term->name : 'Heading', 'items' => array());
                }
                $existing_groups[$tid]['items'][] = $entry;
            } else {
                $page_items[] = $entry;
            }
        }

        set_transient('wcp_batch_' . $batch_id, array('page_id' => $page_id, 'conversation_id' => $conversation_id), HOUR_IN_SECONDS);

        if ($conversation_id) {
            WCP_Conversations_Manager::instance()->add_message($conversation_id, 'assistant', 'Proposed a structure update for your review', array('batch_id' => $batch_id));
        }

        $logger = WCP_AI_Logger::instance();
        $logger->log_action(array(
            'action_type' => 'generate_structure', 'user_id' => $user_id, 'model' => $response['model'],
            'prompt' => $prompt, 'input_context' => json_encode(array('page_id' => $page_id)),
            'output_snapshot' => $response['content'], 'context_post_id' => $page_id,
            'accepted_items' => array(), 'dismissed_items' => array(),
        ));

        return array(
            'outcome'  => 'create_structure',
            'batch_id' => $batch_id,
            'plan'     => array(
                'new_headings'    => $headings,
                'existing_groups' => array_values($existing_groups),
                'page_items'      => $page_items,
            ),
            'metadata' => array('model' => $response['model']),
        );
    }

    private function page_context_term_id($page_id) {
        $terms = get_terms(array('taxonomy' => 'wcp_context', 'hide_empty' => false, 'number' => 1,
            'meta_query' => array(array('key' => 'wcp_ref_type', 'value' => 'page'), array('key' => 'wcp_ref_id', 'value' => $page_id))));
        return (!is_wp_error($terms) && !empty($terms)) ? (int) $terms[0]->term_id : 0;
    }

    private function heading_context_term_id($heading_id) {
        $terms = get_terms(array('taxonomy' => 'wcp_context', 'hide_empty' => false, 'number' => 1,
            'meta_query' => array(array('key' => 'wcp_ref_type', 'value' => 'wcp_heading'), array('key' => 'wcp_ref_id', 'value' => $heading_id, 'type' => 'NUMERIC'))));
        return (!is_wp_error($terms) && !empty($terms)) ? (int) $terms[0]->term_id : 0;
    }

    private function item_titles_for_term($term_id, $limit = 30) {
        if (!$term_id) {
            return array();
        }
        $ids = get_posts(array('post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => $limit, 'fields' => 'ids',
            'tax_query' => array(array('taxonomy' => 'wcp_context', 'field' => 'term_id', 'terms' => $term_id, 'include_children' => false))));
        return array_map('get_the_title', $ids);
    }

    private function build_structure_snapshot($page_id, $page_term_id) {
        $lines = array();
        $valid = array();
        $page  = get_post($page_id);
        $lines[] = 'PAGE: ' . ($page ? $page->post_title : '');

        $pl = $this->item_titles_for_term($page_term_id);
        $lines[] = 'PAGE-LEVEL ITEMS:' . (empty($pl) ? ' (none)' : '');
        foreach ($pl as $t) {
            $lines[] = '  - ' . $t;
        }

        $headings = get_posts(array('post_type' => 'wcp_heading', 'post_status' => 'publish', 'posts_per_page' => -1,
            'orderby' => 'menu_order', 'order' => 'ASC',
            'meta_query' => array(array('key' => '_wcp_parent_type', 'value' => 'page'), array('key' => '_wcp_parent_id', 'value' => $page_id))));
        foreach ($headings as $h) {
            $tid = $this->heading_context_term_id($h->ID);
            if (!$tid) {
                continue;
            }
            $valid[] = $tid;
            $lines[] = 'HEADING [id:' . $tid . ']: ' . $h->post_title;
            foreach ($this->item_titles_for_term($tid) as $t) {
                $lines[] = '  - ' . $t;
            }
        }

        return array(implode("\n", $lines), $valid);
    }

    /**
     * Generate sub-page proposals under the current page.
     * AI guardrail: pages are proposals only — never written directly.
     */
    public function generate_pages($prompt, $page_id, $context_mode = 'page', $selected_pages = array(), $conversation_id = null, $item_count = 0) {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_Error('auth_error', 'User not authenticated');
        }

        $context_builder = WCP_Context_Builder::instance();
        $context_data = $context_builder->build_context_by_mode($page_id, $context_mode, array(
            'selected_pages' => $selected_pages,
            'query' => $prompt,
            'include_items' => false,
            'item_limit' => 0,
            'limits' => array(
                'max_chars_per_item' => 50000,
                'max_chars_page_summary' => 8000
            )
        ));

        $prompt_builder = WCP_Prompt_Builder::instance();
        $system_prompt = $prompt_builder->build_system_prompt('generate-pages', $page_id, $item_count);
        $user_message = $prompt_builder->build_user_message($prompt, $context_data);

        $conversation_history = array();
        if ($conversation_id) {
            $conversations_manager = WCP_Conversations_Manager::instance();
            $messages = $conversations_manager->get_messages($conversation_id, 10);
            foreach ($messages as $msg) {
                $conversation_history[] = array('role' => $msg['role'], 'content' => $msg['content']);
            }
        }

        $ai_client = WCP_AI_Client::instance();
        $response = $ai_client->request_with_conversation($system_prompt, $user_message, $conversation_history, 2048, 90);

        if (is_wp_error($response)) {
            return $response;
        }

        if ($conversation_id) {
            $conversations_manager = WCP_Conversations_Manager::instance();
            $conversations_manager->add_message($conversation_id, 'user', $prompt);
        }

        $parsed = $this->parse_json_response($response['content']);
        if (is_wp_error($parsed)) {
            return $parsed;
        }

        if (isset($parsed['title'])) {
            $parsed = array($parsed);
        }

        if (empty($parsed) || !is_array($parsed)) {
            return new WP_Error('invalid_response', 'AI did not return any pages');
        }

        $proposals = array();
        $batch_id = wp_generate_uuid4();

        foreach ($parsed as $index => $page) {
            if (empty($page['title'])) {
                continue;
            }

            $proposal_id = wp_generate_uuid4();
            $proposal = array(
                'proposal_id'    => $proposal_id,
                'batch_id'       => $batch_id,
                'index'          => $index,
                'action_type'    => 'generate_pages',
                'item'           => array(
                    'title'   => sanitize_text_field($page['title']),
                    'content' => isset($page['content']) ? $page['content'] : '',
                ),
                'conversation_id' => $conversation_id,
                'page_id'        => $page_id,
                'created_at'     => current_time('mysql'),
            );

            set_transient('wcp_proposal_' . $proposal_id, $proposal, HOUR_IN_SECONDS);
            $proposals[] = $proposal;
        }

        set_transient('wcp_batch_' . $batch_id, array(
            'proposal_ids'    => array_column($proposals, 'proposal_id'),
            'page_id'         => $page_id,
            'conversation_id' => $conversation_id,
        ), HOUR_IN_SECONDS);

        if ($conversation_id) {
            $conversations_manager = WCP_Conversations_Manager::instance();
            $count = count($proposals);
            $conversations_manager->add_message($conversation_id, 'assistant',
                "Proposed {$count} sub-page(s) for your review",
                array('batch_id' => $batch_id)
            );
        }

        $logger = WCP_AI_Logger::instance();
        $logger->log_action(array(
            'action_type'     => 'generate_pages',
            'user_id'         => $user_id,
            'model'           => $response['model'],
            'prompt'          => $prompt,
            'input_context'   => json_encode(array('context_mode' => $context_mode, 'page_id' => $page_id)),
            'output_snapshot' => json_encode($parsed),
            'context_post_id' => $page_id,
            'accepted_items'  => array(),
            'dismissed_items' => array(),
        ));

        return array(
            'outcome'   => 'create_items',
            'proposals' => $proposals,
            'batch_id'  => $batch_id,
            'metadata'  => array('model' => $response['model'], 'tokens' => $response['usage'] ?? null),
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
            return new WP_Error('proposal_not_found', 'Proposal not found or expired. ID: ' . $proposal_id);
        }

        $created_posts = array();
        $debug_info = array();
        $page_id = $proposal['page_id'] ?? 0;

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

        // Check if this is a memory proposal
        if (isset($proposal['action_type']) && $proposal['action_type'] === 'extract_memories') {
            $memory_data = $proposal['memory'];
            $conversation_id = $proposal['conversation_id'] ?? null;

            $memory_manager = WCP_Memory_Manager::instance();
            $post_id = $memory_manager->save_memory($memory_data, $conversation_id);

            if (is_wp_error($post_id)) {
                return $post_id;
            }

            $created_posts[] = $post_id;

            // Delete proposal transient
            delete_transient('wcp_proposal_' . $proposal_id);

            return array(
                'created_posts' => $created_posts,
                'message' => 'Memory saved successfully',
                'debug' => array('memory_id' => $post_id)
            );
        }

        // Handle heading proposals
        if (isset($proposal['action_type']) && $proposal['action_type'] === 'generate_headings') {
            $heading_title = $proposal['item']['title'] ?? '';
            if (empty($heading_title)) {
                return new WP_Error('invalid_proposal', 'Heading proposal is missing a title');
            }

            $heading_id = wp_insert_post(array(
                'post_type'    => 'wcp_heading',
                'post_title'   => sanitize_text_field($heading_title),
                'post_content' => '',
                'post_status'  => 'publish',
                'post_author'  => $user_id,
            ));

            if (is_wp_error($heading_id)) {
                return $heading_id;
            }

            WCP_Post_Types::mark_creator($heading_id, 'copilot');

            // Link heading to its parent page
            update_post_meta($heading_id, '_wcp_parent_type', 'page');
            update_post_meta($heading_id, '_wcp_parent_id', $page_id);

            // Taxonomy sync fires automatically via save_post hook

            delete_transient('wcp_proposal_' . $proposal_id);

            return array(
                'created_posts' => array($heading_id),
                'message'       => 'Heading created successfully',
                'debug'         => array('heading_id' => $heading_id),
            );
        }

        // Handle page proposals — create as child of the context page
        if (isset($proposal['action_type']) && $proposal['action_type'] === 'generate_pages') {
            $page_title = $proposal['item']['title'] ?? '';
            if (empty($page_title)) {
                return new WP_Error('invalid_proposal', 'Page proposal is missing a title');
            }

            $new_page_id = wp_insert_post(array(
                'post_type'    => 'page',
                'post_title'   => sanitize_text_field($page_title),
                'post_content' => wp_kses_post($proposal['item']['content'] ?? ''),
                'post_status'  => 'publish',
                'post_author'  => $user_id,
                'post_parent'  => $page_id,
            ));

            if (is_wp_error($new_page_id)) {
                return $new_page_id;
            }

            WCP_Post_Types::mark_creator($new_page_id, 'copilot');

            // Apply parent page template if one exists (AI guardrail: template manager owns this)
            $template_manager = WCP_Page_Template_Manager::instance();
            $template = $template_manager->get_template($page_id);
            if ($template) {
                $template_manager->apply_template($new_page_id, $template);
            }

            delete_transient('wcp_proposal_' . $proposal_id);

            return array(
                'created_posts' => array($new_page_id),
                'message'       => 'Page created successfully',
                'debug'         => array('page_id' => $new_page_id, 'parent_id' => $page_id),
            );
        }

        // Handle item edit proposals — updates an existing post rather than
        // creating one. AI guardrail: only reached once the user accepts.
        if (isset($proposal['action_type']) && $proposal['action_type'] === 'edit_item') {
            $item_id = (int) ($proposal['item_id'] ?? 0);
            $new     = isset($proposal['item']) ? $proposal['item'] : array();

            if (!$item_id || get_post_type($item_id) !== 'post') {
                return new WP_Error('invalid_proposal', 'Edit proposal refers to a missing item');
            }

            $update = array(
                'ID'           => $item_id,
                'post_title'   => sanitize_text_field($new['title'] ?? get_the_title($item_id)),
                'post_content' => wp_kses_post($new['content'] ?? ''),
            );

            $updated = wp_update_post($update, true);
            if (is_wp_error($updated)) {
                return $updated;
            }

            delete_transient('wcp_proposal_' . $proposal_id);

            return array(
                'created_posts' => array(),
                'updated_posts' => array($item_id),
                'message'       => 'Item updated successfully',
                'debug'         => array('item_id' => $item_id),
            );
        }

        // Handle both single and multiple item proposals
        $item = isset($proposal['item']) ? $proposal['item'] : null;

        // Debug: log what we found
        $debug_info['proposal_id'] = $proposal_id;
        $debug_info['has_item'] = !empty($item);
        $debug_info['item_keys'] = $item ? array_keys($item) : array();
        $debug_info['has_title'] = !empty($item['title']);
        $debug_info['has_content'] = !empty($item['content']);

        if (!empty($item['title']) && !empty($item['content'])) {
            $post_id = wp_insert_post(array(
                'post_type' => 'post',
                'post_title' => sanitize_text_field($item['title']),
                'post_content' => wp_kses_post($item['content']),
                'post_status' => 'publish',
                'post_author' => $user_id,
            ));

            if (!is_wp_error($post_id)) {
                WCP_Post_Types::mark_creator($post_id, 'copilot');

                // Add to context if term exists
                if ($context_term_id) {
                    wp_set_post_terms($post_id, array($context_term_id), 'wcp_context');
                }

                // Set item type if provided
                if (!empty($item['item_type'])) {
                    wp_set_post_terms($post_id, array($item['item_type']), 'item_type');
                }

                $created_posts[] = $post_id;
                $debug_info['post_created'] = $post_id;
            } else {
                $debug_info['insert_error'] = $post_id->get_error_message();
            }
        } else {
            $debug_info['skip_reason'] = 'Missing title or content';
        }

        // Delete proposal transient
        delete_transient('wcp_proposal_' . $proposal_id);

        // Update AI action log with accepted items
        global $wpdb;
        $table = $wpdb->prefix . 'wcp_ai_actions';

        // Find recent action for this proposal (within last hour)
        $action_type_search = isset($proposal['action_type']) && $proposal['action_type'] === 'generate-single'
            ? 'generate_single_item'
            : 'generate_items';

        $action = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table
            WHERE context_post_id = %d
            AND action_type = %s
            AND timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ORDER BY timestamp DESC LIMIT 1",
            $page_id,
            $action_type_search
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
            'message' => sprintf('Created %d item(s)', count($created_posts)),
            'debug' => $debug_info
        );
    }

    /**
     * Parse JSON from AI response (handles markdown code blocks)
     *
     * @param string $response AI response text
     * @return array|WP_Error Parsed JSON or error
     */
    private function parse_json_response($response) {
        $original = $response;

        // Remove markdown code blocks if present
        $response = preg_replace('/```json\s*/i', '', $response);
        $response = preg_replace('/```\s*/', '', $response);
        $response = trim($response);

        // Try to find JSON array first (for generate-multiple)
        $json_start_array = strpos($response, '[');
        $json_start_object = strpos($response, '{');

        if ($json_start_array === false && $json_start_object === false) {
            return new WP_Error('no_json', 'No JSON found in response. Raw: ' . substr($original, 0, 200));
        }

        // Determine if we're looking for array or object
        $is_array = ($json_start_array !== false && ($json_start_object === false || $json_start_array < $json_start_object));

        if ($is_array) {
            // Extract array - find matching closing bracket
            $json_text = substr($response, $json_start_array);
            // Find the last ] in the response
            $last_bracket = strrpos($json_text, ']');
            if ($last_bracket !== false) {
                $json_text = substr($json_text, 0, $last_bracket + 1);
            }
        } else {
            // Extract object - find matching closing brace
            $json_text = substr($response, $json_start_object);
            $last_brace = strrpos($json_text, '}');
            if ($last_brace !== false) {
                $json_text = substr($json_text, 0, $last_brace + 1);
            }
        }

        // Clean up common issues
        $json_text = preg_replace('/,\s*([}\]])/', '$1', $json_text); // Remove trailing commas

        // Decode JSON
        $parsed = json_decode($json_text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Try one more time with more aggressive cleaning
            $json_text = preg_replace('/[\x00-\x1F\x7F]/u', '', $json_text); // Remove control characters
            $parsed = json_decode($json_text, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new WP_Error(
                    'json_parse_error',
                    'Failed to parse JSON: ' . json_last_error_msg() . '. Extract attempted: ' . substr($json_text, 0, 100)
                );
            }
        }

        return $parsed;
    }

    /**
     * Extract memories from conversation
     *
     * @param string $conversation_id Conversation ID
     * @return array|WP_Error Result with memory proposals or error
     */
    public function extract_memories_action($conversation_id) {
        $memory_manager = WCP_Memory_Manager::instance();
        return $memory_manager->extract_memories($conversation_id);
    }

    /**
     * Summarize page content for compact context representation
     *
     * @param int $page_id Page ID to summarize
     * @return array|WP_Error Result with summary or error
     */
    public function summarize_page($page_id) {
        // Get current user
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_Error('auth_error', 'User not authenticated');
        }

        // Get page
        $page = get_post($page_id);
        if (!$page || $page->post_type !== 'page') {
            return new WP_Error('invalid_page', 'Invalid page ID');
        }

        // Get page headings for structural context (with error handling)
        $heading_titles = array();
        try {
            $headings_query = new WP_Query(array(
                'post_type' => 'wcp_heading',
                'post_status' => 'publish',
                'posts_per_page' => 20, // Limit for performance
                'meta_query' => array(
                    'relation' => 'AND',
                    array('key' => '_wcp_parent_id', 'value' => $page_id, 'compare' => '='),
                    array('key' => '_wcp_parent_type', 'value' => 'page', 'compare' => '=')
                ),
                'orderby' => 'menu_order',
                'order' => 'ASC',
                'fields' => 'ids'
            ));

            if (!is_wp_error($headings_query) && !empty($headings_query->posts)) {
                foreach ($headings_query->posts as $heading_id) {
                    $heading = get_post($heading_id);
                    if ($heading) {
                        $heading_titles[] = $heading->post_title;
                    }
                }
            }
        } catch (Exception $e) {
            error_log('WCP: Error getting headings for summarization: ' . $e->getMessage());
        }

        // Build prompt
        $prompt_builder = WCP_Prompt_Builder::instance();
        $system_prompt = "You are a summarization assistant. Create a concise summary (500-1000 characters) that captures the key themes, objectives, and purpose of the page content. Focus on what's important for understanding the context, not every detail.";

        $user_message = "Page Title: {$page->post_title}\n\n";
        if (!empty($heading_titles)) {
            $user_message .= "Page Structure (Headings):\n- " . implode("\n- ", $heading_titles) . "\n\n";
        }
        $user_message .= "Page Content:\n{$page->post_content}\n\n";
        $user_message .= "Please provide a 500-1000 character summary of this page.";

        // Call AI
        $ai_client = WCP_AI_Client::instance();
        $response = $ai_client->request_with_conversation(
            $system_prompt,
            $user_message,
            array(), // No conversation history
            1000     // Max tokens for summary
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $summary = trim($response['content']);

        // Enforce max length
        if (strlen($summary) > 1000) {
            $summary = substr($summary, 0, 997) . '...';
        }

        // Save summary to page meta
        update_post_meta($page_id, '_wcp_page_compact_summary', $summary);
        update_post_meta($page_id, '_wcp_summary_generated_at', current_time('mysql'));

        // Log AI action
        $logger = WCP_AI_Logger::instance();
        $logger->log_action(array(
            'action_type' => 'summarize_page',
            'user_id' => $user_id,
            'model' => $response['model'],
            'prompt' => 'Summarize page: ' . $page->post_title,
            'input_context' => json_encode(array(
                'page_title' => $page->post_title,
                'content_length' => strlen($page->post_content),
                'heading_count' => count($heading_titles)
            )),
            'output_snapshot' => $summary,
            'context_post_id' => $page_id,
            'accepted_items' => array(),
            'dismissed_items' => array()
        ));

        return array(
            'success' => true,
            'summary' => $summary,
            'generated_at' => current_time('mysql'),
            'metadata' => array(
                'model' => $response['model'],
                'tokens' => $response['usage'] ?? null,
                'original_length' => strlen($page->post_content),
                'summary_length' => strlen($summary)
            )
        );
    }

    /**
     * Plan a goal: understand the user's intent and propose action items.
     * AI guardrail: nothing is written to the database here — caller handles creation.
     *
     * @param string $goal_description What the user wants to achieve
     * @param int    $page_id          Page providing context (mission, existing work)
     * @return array|WP_Error { understanding, action_items, action_id }
     */
    public function plan_goal( $goal_description, $page_id ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return new WP_Error( 'auth_error', 'User not authenticated' );
        }

        $context_builder = WCP_Context_Builder::instance();
        $item_char_limit = 50000;
        $context_data    = $context_builder->build_hierarchical_context( $page_id, array(
            'include_items' => true,
            'item_limit'    => 25,
            'limits'        => array(
                'max_chars_per_item'      => $item_char_limit,
                'max_chars_page_summary'  => 8000,
            ),
        ) );

        // Detect which items were truncated so the UI can warn the user
        $truncated_items = array();
        foreach ( $context_data['items'] as $item ) {
            $len = strlen( wp_strip_all_tags( $item['content'] ) );
            if ( $len > $item_char_limit ) {
                $truncated_items[] = array(
                    'title'      => $item['title'],
                    'actual_len' => $len,
                    'limit'      => $item_char_limit,
                );
            }
        }

        $prompt_builder = WCP_Prompt_Builder::instance();
        $system_prompt  = $prompt_builder->build_system_prompt( 'plan-goal', $page_id );
        $user_message   = $prompt_builder->build_user_message( $goal_description, $context_data );

        $ai_client = WCP_AI_Client::instance();
        $response  = $ai_client->request_with_conversation( $system_prompt, $user_message, array(), 2048, 60 );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $parsed = $this->parse_json_response( $response['content'] );
        if ( is_wp_error( $parsed ) ) {
            return $parsed;
        }

        if ( empty( $parsed['understanding'] ) || empty( $parsed['action_items'] ) ) {
            return new WP_Error( 'invalid_response', 'AI did not return a valid goal plan' );
        }

        $logger    = WCP_AI_Logger::instance();
        $action_id = $logger->log_action( 'plan_goal', array(
            'model'          => $response['model'] ?? get_option( 'wcp_ai_model', 'claude-sonnet-4-6' ),
            'prompt'         => $goal_description,
            'input_context'  => $context_data,
            'output'         => $parsed,
            'context_post_id' => $page_id,
        ) );

        return array(
            'success'         => true,
            'understanding'   => sanitize_textarea_field( $parsed['understanding'] ),
            'action_items'    => array_values( array_filter( array_map( function( $item ) {
                $title = sanitize_text_field( $item['title'] ?? '' );
                if ( empty( $title ) ) return null;
                return array(
                    'title'   => $title,
                    'content' => sanitize_textarea_field( $item['content'] ?? '' ),
                );
            }, (array) $parsed['action_items'] ) ) ),
            'action_id'       => $action_id,
            'truncated_items' => $truncated_items,
        );
    }

    /**
     * Route an 'auto' action by detecting intent from the prompt via keyword matching.
     * Returns an array: ['action' => string, 'item_count' => int]
     */
    public function auto_route( $prompt ) {
        $lower = mb_strtolower( $prompt );

        // Extract explicit item count from prompt ("generate 5 items", "create 3 tasks")
        $item_count = 0;
        if ( preg_match( '/\b(\d+)\s+(?:items?|tasks?|headings?|pages?|sub-?pages?)\b/i', $prompt, $m ) ) {
            $item_count = (int) $m[1];
        }

        if ( preg_match( '/\b(rewrite|re-write)\b.*\b(page|content)\b|\b(rewrite|re-write)\s+this\b/i', $lower ) ) {
            return array( 'action' => 'rewrite_content', 'item_count' => 0 );
        }
        if ( preg_match( '/\b(append|add to|extend|add content)\b.*\b(page|content)\b/i', $lower ) ) {
            return array( 'action' => 'append_content', 'item_count' => 0 );
        }
        if ( preg_match( '/\b(generate|create|suggest|add|produce)\b.{0,30}\b(headings?|sections?)\b/i', $lower ) ) {
            return array( 'action' => 'generate_headings', 'item_count' => $item_count );
        }
        if ( preg_match( '/\b(generate|create|suggest|add|produce)\b.{0,30}\b(sub-?pages?|child pages?)\b/i', $lower ) ) {
            return array( 'action' => 'generate_pages', 'item_count' => $item_count );
        }
        if ( preg_match( '/\b(generate|create|suggest|add|produce)\b.{0,30}\b(items?|tasks?|notes?|actions?)\b/i', $lower ) ) {
            return array( 'action' => 'generate_items', 'item_count' => $item_count );
        }

        return array( 'action' => 'chat', 'item_count' => 0 );
    }

    /**
     * Rewrite the page's post_content based on the user's instruction.
     * Returns a content proposal stored in a transient (human-in-the-loop).
     */
    public function rewrite_page_content( $prompt, $page_id, $context_mode = 'page', $selected_pages = array() ) {
        $page = get_post( $page_id );
        if ( ! $page ) return new WP_Error( 'not_found', 'Page not found' );

        $context_builder = WCP_Context_Builder::instance();
        $context_data = $context_builder->build_context_by_mode( $page_id, $context_mode, array(
            'selected_pages' => $selected_pages,
            'query' => $prompt,
            'include_items' => true,
            'item_limit'    => 25,
        ) );

        $prompt_builder = WCP_Prompt_Builder::instance();
        $system_prompt  = $prompt_builder->build_system_prompt( 'rewrite_content', $page_id );

        $existing = $page->post_content ? "\n\nCurrent page content:\n" . wp_strip_all_tags( $page->post_content ) : '';
        $user_message = $prompt_builder->build_user_message( $prompt . $existing, $context_data );

        $ai_client = WCP_AI_Client::instance();
        $response  = $ai_client->request_with_conversation( $system_prompt, $user_message, array(), 4096, 90 );
        if ( is_wp_error( $response ) ) return $response;

        $proposal_id = wp_generate_uuid4();
        set_transient( 'wcp_content_proposal_' . $proposal_id, array(
            'mode'    => 'rewrite',
            'page_id' => $page_id,
            'content' => $response['content'],
        ), HOUR_IN_SECONDS );

        $logger = WCP_AI_Logger::instance();
        $action_id = $logger->log_action( 'rewrite_content', $prompt, array( 'page_id' => $page_id ), $response['content'] );

        return array(
            'outcome'     => 'content_proposal',
            'mode'        => 'rewrite',
            'proposal_id' => $proposal_id,
            'content'     => $response['content'],
            'action_id'   => $action_id,
        );
    }

    /**
     * Generate content to append to the page's post_content.
     * Returns a content proposal stored in a transient (human-in-the-loop).
     */
    public function append_page_content( $prompt, $page_id, $context_mode = 'page', $selected_pages = array() ) {
        $page = get_post( $page_id );
        if ( ! $page ) return new WP_Error( 'not_found', 'Page not found' );

        $context_builder = WCP_Context_Builder::instance();
        $context_data = $context_builder->build_context_by_mode( $page_id, $context_mode, array(
            'selected_pages' => $selected_pages,
            'query' => $prompt,
            'include_items' => true,
            'item_limit'    => 25,
        ) );

        $prompt_builder = WCP_Prompt_Builder::instance();
        $system_prompt  = $prompt_builder->build_system_prompt( 'append_content', $page_id );

        $existing = $page->post_content ? "\n\nExisting page content:\n" . wp_strip_all_tags( $page->post_content ) : '';
        $user_message = $prompt_builder->build_user_message( $prompt . $existing, $context_data );

        $ai_client = WCP_AI_Client::instance();
        $response  = $ai_client->request_with_conversation( $system_prompt, $user_message, array(), 4096, 90 );
        if ( is_wp_error( $response ) ) return $response;

        $proposal_id = wp_generate_uuid4();
        set_transient( 'wcp_content_proposal_' . $proposal_id, array(
            'mode'    => 'append',
            'page_id' => $page_id,
            'content' => $response['content'],
        ), HOUR_IN_SECONDS );

        $logger = WCP_AI_Logger::instance();
        $action_id = $logger->log_action( 'append_content', $prompt, array( 'page_id' => $page_id ), $response['content'] );

        return array(
            'outcome'     => 'content_proposal',
            'mode'        => 'append',
            'proposal_id' => $proposal_id,
            'content'     => $response['content'],
            'action_id'   => $action_id,
        );
    }

    /**
     * "Fetch posts": interpret the prompt, run the query, and answer — all in one step.
     */
    public function fetch_posts_auto( $prompt, $page_id, $conversation_id = null ) {
        $interpret = $this->fetch_posts_interpret( $prompt, $page_id, $conversation_id );
        if ( is_wp_error( $interpret ) ) return $interpret;
        return $this->fetch_posts_execute( $interpret['fetch_id'], $conversation_id );
    }

    /**
     * Interpret the natural language prompt into structured query params via a lightweight
     * AI call. Stores params in a transient keyed by fetch_id.
     */
    public function fetch_posts_interpret( $prompt, $page_id, $conversation_id = null ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) return new WP_Error( 'auth_error', 'User not authenticated' );

        $prompt_builder = WCP_Prompt_Builder::instance();
        $system_prompt  = $prompt_builder->build_system_prompt( 'fetch_interpret', $page_id );

        $ai_client = WCP_AI_Client::instance();
        $response  = $ai_client->request_with_conversation( $system_prompt, "User request: {$prompt}", array(), 256 );
        if ( is_wp_error( $response ) ) return $response;

        $params = $this->parse_json_response( $response['content'] );
        if ( is_wp_error( $params ) ) return $params;

        // Ensure limit has a sensible default
        if ( empty( $params['limit'] ) || (int) $params['limit'] < 1 ) {
            $params['limit'] = 10;
        }
        $params['limit'] = min( (int) $params['limit'], 100 );

        $fetch_id = wp_generate_uuid4();
        set_transient( 'wcp_fetch_' . $fetch_id, array(
            'params'  => $params,
            'prompt'  => $prompt,
            'page_id' => $page_id,
        ), 10 * MINUTE_IN_SECONDS );

        return array(
            'outcome'  => 'fetch_confirmation',
            'fetch_id' => $fetch_id,
            'params'   => $params,
        );
    }

    /**
     * Phase 2 of "Fetch posts": run the confirmed query deterministically, then answer
     * the original prompt using the fetched posts as context.
     */
    public function fetch_posts_execute( $fetch_id, $conversation_id = null ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) return new WP_Error( 'auth_error', 'User not authenticated' );

        $stored = get_transient( 'wcp_fetch_' . $fetch_id );
        if ( ! $stored ) return new WP_Error( 'expired', 'Fetch session expired — please try again' );

        delete_transient( 'wcp_fetch_' . $fetch_id );
        $params  = $stored['params'];
        $prompt  = $stored['prompt'];
        $page_id = $stored['page_id'];

        // Build WP_Query args from extracted params
        $args = array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => (int) $params['limit'],
            'orderby'        => 'date',
            'order'          => 'DESC',
        );
        if ( ! empty( $params['date_from'] ) || ! empty( $params['date_to'] ) ) {
            $date_query = array( 'inclusive' => true );
            if ( ! empty( $params['date_from'] ) ) $date_query['after']  = $params['date_from'];
            if ( ! empty( $params['date_to'] ) )   $date_query['before'] = $params['date_to'];
            $args['date_query'] = array( $date_query );
        }
        if ( ! empty( $params['task_status'] ) ) {
            $args['tax_query'][] = array( 'taxonomy' => 'task_status', 'field' => 'slug', 'terms' => $params['task_status'] );
        }
        if ( ! empty( $params['item_type'] ) ) {
            $args['tax_query'][] = array( 'taxonomy' => 'item_type', 'field' => 'slug', 'terms' => $params['item_type'] );
        }
        if ( ! empty( $params['parent_page_id'] ) ) {
            $context_builder = WCP_Context_Builder::instance();
            // reuse helper: get context term for the page, then query items in that tree
            // We'll use the existing build_context_by_mode select path instead
            $args['tax_query'][] = array(
                'taxonomy' => 'wcp_context',
                'field'    => 'slug',
                'terms'    => get_terms( array(
                    'taxonomy' => 'wcp_context', 'hide_empty' => false,
                    'meta_query' => array( array( 'key' => 'wcp_ref_id', 'value' => (int) $params['parent_page_id'] ) ),
                    'fields' => 'slugs',
                ) ),
            );
        }

        $posts = get_posts( $args );

        // Build a context_data array the existing format_for_prompt understands
        $context_data = array(
            'pages'     => array(),
            'items'     => array(),
            'rag_items' => array(),
            'memories'  => array(),
            'limits'    => array( 'max_chars_per_item' => 2000, 'max_chars_page_summary' => 0 ),
        );
        foreach ( $posts as $p ) {
            $context_data['items'][] = array(
                'id'      => $p->ID,
                'title'   => $p->post_title,
                'content' => wp_strip_all_tags( $p->post_content ),
                'date'    => $p->post_date,
            );
        }

        $prompt_builder = WCP_Prompt_Builder::instance();
        $system_prompt  = $prompt_builder->build_system_prompt( 'chat', $page_id );
        $user_message   = $prompt_builder->build_user_message( $prompt, $context_data );

        $conversation_history = array();
        if ( $conversation_id ) {
            $mgr = WCP_Conversations_Manager::instance();
            foreach ( $mgr->get_messages( $conversation_id, 10 ) as $msg ) {
                $conversation_history[] = array( 'role' => $msg['role'], 'content' => $msg['content'] );
            }
        }

        $ai_client = WCP_AI_Client::instance();
        $response  = $ai_client->request_with_conversation( $system_prompt, $user_message, $conversation_history, 4096 );
        if ( is_wp_error( $response ) ) return $response;

        if ( $conversation_id ) {
            $mgr = WCP_Conversations_Manager::instance();
            $mgr->add_message( $conversation_id, 'user', $prompt );
            $mgr->add_message( $conversation_id, 'assistant', $response['content'] );
        }

        return array(
            'outcome'      => 'chat',
            'message'      => $response['content'],
            'posts_fetched' => count( $posts ),
        );
    }

    /**
     * "Fetch structure": build the full page/heading taxonomy tree as context and answer
     * the user's structural question. No AI interpretation needed — deterministic.
     */
    public function fetch_structure_chat( $prompt, $conversation_id = null ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) return new WP_Error( 'auth_error', 'User not authenticated' );

        $contexts = WCP_Taxonomy_Sync::get_all_contexts();
        $tree_text = $this->format_context_tree( $contexts );

        $system_prompt = "You are a helpful assistant with access to the full page, subpage and heading structure of this knowledge base. Answer the user's question using the structure provided.";
        $user_message  = "User request: {$prompt}\n\n## Full Structure:\n\n{$tree_text}";

        $conversation_history = array();
        if ( $conversation_id ) {
            $mgr = WCP_Conversations_Manager::instance();
            foreach ( $mgr->get_messages( $conversation_id, 10 ) as $msg ) {
                $conversation_history[] = array( 'role' => $msg['role'], 'content' => $msg['content'] );
            }
        }

        $ai_client = WCP_AI_Client::instance();
        $response  = $ai_client->request_with_conversation( $system_prompt, $user_message, $conversation_history, 4096 );
        if ( is_wp_error( $response ) ) return $response;

        if ( $conversation_id ) {
            $mgr = WCP_Conversations_Manager::instance();
            $mgr->add_message( $conversation_id, 'user', $prompt );
            $mgr->add_message( $conversation_id, 'assistant', $response['content'] );
        }

        return array( 'outcome' => 'chat', 'message' => $response['content'] );
    }

    /**
     * Recursively format the wcp_context taxonomy tree into an indented text outline.
     */
    private function format_context_tree( $terms, $parent_id = 0, $depth = 0 ) {
        $output = '';
        $indent = str_repeat( '  ', $depth );
        foreach ( $terms as $term ) {
            if ( (int) $term->parent !== $parent_id ) continue;
            $ref_type = get_term_meta( $term->term_id, 'wcp_ref_type', true );
            $label    = $ref_type === 'wcp_heading' ? "— {$term->name}" : $term->name;
            $output  .= "{$indent}{$label}\n";
            $output  .= $this->format_context_tree( $terms, $term->term_id, $depth + 1 );
        }
        return $output;
    }

    /**
     * Onboard action: gather context, summarise, greet, optionally suggest AI mission.
     * AI guardrail: nothing is written here — any mission suggestion is a proposal.
     *
     * @param int    $page_id         Current page ID
     * @param string $conversation_id Conversation ID (optional)
     * @return array|WP_Error
     */
    public function onboard( $page_id, $conversation_id = null ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return new WP_Error( 'auth_error', 'User not authenticated' );
        }

        $page = get_post( $page_id );
        if ( ! $page || $page->post_type !== 'page' ) {
            return new WP_Error( 'invalid_page', 'Invalid page ID' );
        }

        // Load missions
        $mission_loader  = WCP_Mission_Loader::instance();
        $global_mission  = $mission_loader->get_global_mission();
        $page_mission    = $mission_loader->get_page_objectives( $page_id );
        $has_page_mission = ! empty( trim( $page_mission ) );

        // Build a compact structure snapshot (same helper used by generate_structure)
        $page_term_id = $this->page_context_term_id( $page_id );
        list( $structure_snapshot, ) = $this->build_structure_snapshot( $page_id, $page_term_id );

        // Count items directly under this page
        $item_count = count( get_posts( array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'tax_query'      => array( array(
                'taxonomy'         => 'wcp_context',
                'field'            => 'term_id',
                'terms'            => $page_term_id,
                'include_children' => true,
            ) ),
        ) ) );

        // System prompt
        $system_prompt  = "You are an AI assistant embedded in a personal work management system. ";
        $system_prompt .= "When asked to onboard onto a page, you:\n";
        $system_prompt .= "1. Briefly summarise what you understand about the global mission and the page's purpose.\n";
        $system_prompt .= "2. Note the page's current structure (headings, item count).\n";
        $system_prompt .= "3. Close with an open, helpful question.\n\n";
        $system_prompt .= "Keep the greeting to 100–200 words. Be direct and practical — not effusive.\n\n";
        $system_prompt .= "IMPORTANT rules for the JSON output:\n";
        $system_prompt .= "- Do NOT mention the mission proposal inside `greeting`. The UI will display it separately.\n";
        $system_prompt .= "- If no page AI mission is set (you will be told), you MUST provide a concise 2–4 sentence mission in `suggested_mission`. Never leave it null or empty in that case.\n";
        $system_prompt .= "- If a page AI mission already exists, set `suggested_mission` to null.\n\n";
        $system_prompt .= "Return ONLY valid JSON with no markdown fences: { \"greeting\": \"<greeting text>\", \"suggested_mission\": \"<proposed mission text or null>\" }";

        // User message
        $user_message  = "Page: {$page->post_title}\n\n";
        $user_message .= "Global mission:\n" . ( $global_mission ?: '(none set)' ) . "\n\n";
        $mission_status = $has_page_mission ? "SET:\n{$page_mission}" : "NOT SET — you must propose one in `suggested_mission`";
        $user_message .= "Page AI mission: {$mission_status}\n\n";
        $user_message .= "Current structure:\n{$structure_snapshot}\n\n";
        $user_message .= "Total items on this page: {$item_count}\n\n";
        $user_message .= "Please onboard onto this page.";

        $ai_client = WCP_AI_Client::instance();
        $response  = $ai_client->request_with_conversation( $system_prompt, $user_message, array(), 800, 30 );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $parsed = $this->parse_json_response( $response['content'] );
        if ( is_wp_error( $parsed ) ) {
            // Regex fallback: AI may embed actual newlines in string values, breaking json_decode.
            // Capture the raw escaped content and re-decode it as a standalone JSON string.
            if ( preg_match( '/"greeting"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $response['content'], $m ) ) {
                // Re-encode the captured fragment as a JSON string so json_decode handles \n etc.
                $raw = preg_replace( '/\r\n|\r|\n/', '\\n', $m[1] ); // normalise real newlines
                $greeting_text = json_decode( '"' . $raw . '"' );
                if ( $greeting_text === null ) {
                    $greeting_text = $m[1]; // last resort: keep as-is
                }
                $mission_text = null;
                if ( preg_match( '/"suggested_mission"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $response['content'], $mm ) ) {
                    $raw_m = preg_replace( '/\r\n|\r|\n/', '\\n', $mm[1] );
                    $mission_text = json_decode( '"' . $raw_m . '"' ) ?: $mm[1];
                }
                $parsed = array(
                    'greeting'          => $greeting_text,
                    'suggested_mission' => $mission_text,
                );
            } else {
                $parsed = array( 'greeting' => $response['content'], 'suggested_mission' => null );
            }
        }

        $greeting          = isset( $parsed['greeting'] ) ? $parsed['greeting'] : $response['content'];
        $suggested_mission = ( ! $has_page_mission && ! empty( $parsed['suggested_mission'] ) )
            ? sanitize_textarea_field( $parsed['suggested_mission'] )
            : null;

        // Log action
        $logger = WCP_AI_Logger::instance();
        $logger->log_action( array(
            'action_type'     => 'onboard',
            'user_id'         => $user_id,
            'model'           => $response['model'],
            'prompt'          => 'onboard',
            'input_context'   => json_encode( array( 'page_id' => $page_id, 'has_page_mission' => $has_page_mission ) ),
            'output_snapshot' => $greeting,
            'context_post_id' => $page_id,
            'accepted_items'  => array(),
            'dismissed_items' => array(),
        ) );

        if ( $conversation_id ) {
            $mgr = WCP_Conversations_Manager::instance();
            $mgr->add_message( $conversation_id, 'user', 'onboard' );
            $mgr->add_message( $conversation_id, 'assistant', $greeting );
        }

        return array(
            'outcome'           => 'onboard',
            'message'           => $greeting,
            'suggested_mission' => $suggested_mission,
            'has_page_mission'  => $has_page_mission,
            'metadata'          => array( 'model' => $response['model'] ),
        );
    }

    /**
     * Get version for debugging
     */
    public static function get_version() {
        return '1.2.0';
    }
}
