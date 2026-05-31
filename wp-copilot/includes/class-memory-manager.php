<?php
/**
 * Memory Manager
 *
 * Handles persistent memory system for cross-session learning
 * - Extracts memories from conversations
 * - Stores memories as native Posts
 * - Retrieves relevant memories using RAG
 *
 * @package WorkCopilot
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_Memory_Manager {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get the Memories page
     *
     * @return WP_Post|null Memories page object or null if not found
     */
    public function get_memories_page() {
        $pages = get_pages(array(
            'meta_key' => '_wcp_is_memories_page',
            'meta_value' => '1',
            'number' => 1
        ));

        return !empty($pages) ? $pages[0] : null;
    }

    /**
     * Get or find context term for Memories page
     *
     * @return WP_Term|null Context term for memories or null
     */
    public function get_memories_context_term() {
        $memories_page = $this->get_memories_page();
        if (!$memories_page) {
            return null;
        }

        $terms = get_terms(array(
            'taxonomy' => 'wcp_context',
            'meta_key' => 'wcp_ref_id',
            'meta_value' => $memories_page->ID,
            'hide_empty' => false
        ));

        return !empty($terms) ? $terms[0] : null;
    }

    /**
     * Extract memories from conversation
     *
     * Uses AI to analyze conversation and identify key learnings
     *
     * @param string $conversation_id Conversation ID
     * @return array|WP_Error Array with proposals or error
     */
    public function extract_memories($conversation_id) {
        $conv_manager = WCP_Conversations_Manager::instance();
        $messages = $conv_manager->get_messages($conversation_id, 20);

        if (empty($messages)) {
            return new WP_Error('no_messages', 'No messages found in conversation');
        }

        // Build conversation context
        $conversation_text = '';
        foreach ($messages as $msg) {
            $role = $msg['role'] === 'user' ? 'User' : 'Assistant';
            $conversation_text .= "{$role}: {$msg['content']}\n\n";
        }

        // Build prompt for memory extraction
        $system_prompt = "You are a memory extraction assistant. Your job is to analyze conversations and identify important learnings that should be remembered for future interactions.";

        $user_prompt = "Analyze this conversation and identify 1-3 important learnings that should be remembered. Focus on:\n\n";
        $user_prompt .= "- User facts (name, role, background, goals, projects)\n";
        $user_prompt .= "- User preferences (communication style, focus areas, work patterns)\n";
        $user_prompt .= "- Project context (deadlines, tech stack, current priorities, challenges)\n\n";
        $user_prompt .= "Format your response as a JSON array with NO text before or after:\n";
        $user_prompt .= '[{"title": "Short title", "content": "Detailed description", "type": "user_fact|preference|project_context", "confidence": 0-100}]\n\n';
        $user_prompt .= "Only extract genuinely important and non-obvious information. Skip pleasantries and generic statements.\n\n";
        $user_prompt .= "Conversation:\n\n{$conversation_text}";

        // Call AI
        $ai_client = WCP_AI_Client::instance();
        $response = $ai_client->request(
            $system_prompt,
            $user_prompt,
            2048
        );

        if (is_wp_error($response)) {
            return $response;
        }

        // Parse JSON response
        $ai_actions = WCP_AI_Actions::instance();
        $memories = $ai_actions->parse_json_response($response['content']);

        if (is_wp_error($memories)) {
            return $memories;
        }

        if (empty($memories)) {
            return array(
                'outcome' => 'no_memories',
                'message' => 'No significant memories identified in this conversation'
            );
        }

        // Create proposals
        $proposals = array();
        $batch_id = wp_generate_uuid4();

        foreach ($memories as $memory) {
            $proposal_id = wp_generate_uuid4();

            $proposal = array(
                'proposal_id' => $proposal_id,
                'batch_id' => $batch_id,
                'action_type' => 'extract_memories',
                'memory' => $memory,
                'conversation_id' => $conversation_id,
                'created_at' => current_time('mysql')
            );

            set_transient('wcp_proposal_' . $proposal_id, $proposal, HOUR_IN_SECONDS);
            $proposals[] = $proposal;
        }

        return array(
            'outcome' => 'create_memories',
            'proposals' => $proposals,
            'batch_id' => $batch_id
        );
    }

    /**
     * Save accepted memory as Post
     *
     * @param array $memory_data Memory data array
     * @param string $conversation_id Optional conversation ID
     * @return int|WP_Error Post ID or error
     */
    public function save_memory($memory_data, $conversation_id = null) {
        $memories_page = $this->get_memories_page();
        $context_term = $this->get_memories_context_term();

        if (!$memories_page) {
            return new WP_Error('memories_not_setup', 'Memories page not found. The page may not have been created during activation.');
        }

        if (!$context_term) {
            return new WP_Error('memories_term_not_found', 'Memories context term not found.');
        }

        // Create memory post
        $post_id = wp_insert_post(array(
            'post_type' => 'post',
            'post_title' => sanitize_text_field($memory_data['title']),
            'post_content' => wp_kses_post($memory_data['content']),
            'post_status' => 'publish',
            'post_author' => get_current_user_id()
        ));

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // Set context taxonomy
        wp_set_post_terms($post_id, array($context_term->term_id), 'wcp_context');

        // Set metadata
        update_post_meta($post_id, '_wcp_memory_type', sanitize_text_field($memory_data['type']));
        update_post_meta($post_id, '_wcp_memory_source', 'ai_generated');
        update_post_meta($post_id, '_wcp_memory_confidence', intval($memory_data['confidence']));

        if ($conversation_id) {
            update_post_meta($post_id, '_wcp_memory_conversation_id', sanitize_text_field($conversation_id));
        }

        // Trigger embedding generation if enabled
        if (get_option('wcp_embeddings_enabled', false)) {
            $embeddings_manager = WCP_Embeddings_Manager::instance();
            $embeddings_manager->generate_embedding($post_id);
        }

        return $post_id;
    }

    /**
     * Get relevant memories using RAG semantic search
     *
     * @param string $query Query text for semantic search
     * @param int $limit Maximum number of memories to return
     * @return array Array of memory posts
     */
    public function get_relevant_memories($query, $limit = 5) {
        if (!get_option('wcp_embeddings_enabled', false)) {
            return array(); // RAG not enabled
        }

        $memories_term = $this->get_memories_context_term();
        if (!$memories_term) {
            return array();
        }

        // Get all memory post IDs
        $memory_posts = get_posts(array(
            'post_type' => 'post',
            'tax_query' => array(
                array(
                    'taxonomy' => 'wcp_context',
                    'terms' => $memories_term->term_id
                )
            ),
            'fields' => 'ids',
            'posts_per_page' => -1
        ));

        if (empty($memory_posts)) {
            return array();
        }

        // Semantic search within memories
        $embeddings = WCP_Embeddings_Client::instance();
        $results = $embeddings->find_similar_posts($query, $limit, 'post', array(), $memory_posts);

        return $results;
    }

    /**
     * Ensure Memories page exists
     *
     * Called during plugin activation
     */
    public function ensure_memories_page() {
        $existing = $this->get_memories_page();
        if ($existing) {
            return $existing->ID;
        }

        // Create Memories page
        $page_id = wp_insert_post(array(
            'post_type' => 'page',
            'post_title' => 'Memories',
            'post_content' => 'This page stores AI-generated memories about your work, preferences, and context. Memories are automatically extracted from conversations and help the AI provide better assistance over time.',
            'post_status' => 'publish',
            'post_author' => get_current_user_id()
        ));

        if (!is_wp_error($page_id)) {
            // Mark as special system page
            update_post_meta($page_id, '_wcp_is_memories_page', '1');
        }

        return $page_id;
    }
}
