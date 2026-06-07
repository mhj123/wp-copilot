<?php
/**
 * REST API Endpoints
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_REST_API {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        $namespace = 'work-copilot/v1';

        // Version check endpoint (for debugging)
        register_rest_route($namespace, '/version', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_version'),
            'permission_callback' => '__return_true',
        ));

        // Get context tree
        register_rest_route($namespace, '/contexts/tree', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_context_tree'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Get items for context
        register_rest_route($namespace, '/contexts/(?P<id>\d+)/items', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_context_items'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Update item (title, item_type, priority)
        register_rest_route($namespace, '/items/(?P<id>\d+)/update', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'update_item'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Delete item (trash)
        register_rest_route($namespace, '/items/(?P<id>\d+)/delete', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'delete_item'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Quick create item
        register_rest_route($namespace, '/items/create', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_item'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Reorder items (drag-and-drop)
        register_rest_route($namespace, '/items/reorder', array(
            'methods' => 'POST',
            'callback' => array($this, 'reorder_items'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // AI: Suggest tags
        register_rest_route($namespace, '/ai/suggest-tags', array(
            'methods' => 'POST',
            'callback' => array($this, 'ai_suggest_tags'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // AI: Page chat
        register_rest_route($namespace, '/ai/page-chat', array(
            'methods' => 'POST',
            'callback' => array($this, 'ai_page_chat'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // AI: Coaching prompt
        register_rest_route($namespace, '/ai/coaching', array(
            'methods' => 'POST',
            'callback' => array($this, 'ai_coaching'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // NEW: Conversation-based AI endpoints
        // Initialize conversation for a page
        register_rest_route($namespace, '/ai/conversations/init', array(
            'methods' => 'POST',
            'callback' => array($this, 'init_conversation'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Execute AI action with conversation
        register_rest_route($namespace, '/ai/actions/execute', array(
            'methods' => 'POST',
            'callback' => array($this, 'execute_action'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Decide on proposals (accept/dismiss) - MUST be before the generic pattern below
        register_rest_route($namespace, '/ai/proposals/decide', array(
            'methods' => 'POST',
            'callback' => array($this, 'decide_proposals'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // AI: Accept/dismiss (LEGACY - generic pattern, must be AFTER specific routes)
        register_rest_route($namespace, '/ai/(?P<action_id>[a-zA-Z0-9_-]+)/decide', array(
            'methods' => 'POST',
            'callback' => array($this, 'ai_decide'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Embeddings: Semantic search
        register_rest_route($namespace, '/search/semantic', array(
            'methods' => 'POST',
            'callback' => array($this, 'semantic_search'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Embeddings: Batch generate
        register_rest_route($namespace, '/embeddings/batch', array(
            'methods' => 'POST',
            'callback' => array($this, 'batch_generate_embeddings'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Embeddings: Stats
        register_rest_route($namespace, '/embeddings/stats', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_embedding_stats'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Embeddings: Generate for single post
        register_rest_route($namespace, '/embeddings/generate/(?P<post_id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'generate_single_embedding'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Mission: Get active mission for page
        register_rest_route($namespace, '/mission/active', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_active_mission'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Memories: Extract from conversation
        register_rest_route($namespace, '/ai/memories/extract', array(
            'methods' => 'POST',
            'callback' => array($this, 'extract_memories'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // NEW: Editor expand draft
        register_rest_route($namespace, '/ai/editor/expand', array(
            'methods' => 'POST',
            'callback' => array($this, 'editor_expand_draft'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // NEW: Prompt chips (get/save/delete)
        register_rest_route($namespace, '/prompts', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'get_prompts'),
                'permission_callback' => array($this, 'check_permission'),
            ),
            array(
                'methods' => 'POST',
                'callback' => array($this, 'save_prompt'),
                'permission_callback' => array($this, 'check_permission'),
            ),
        ));

        register_rest_route($namespace, '/prompts/(?P<index>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_prompt'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // NEW: Pages list for selector
        register_rest_route($namespace, '/pages/list', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_pages_list'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Reorder headings on a page
        register_rest_route($namespace, '/headings/reorder', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'reorder_headings'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Delete heading
        register_rest_route($namespace, '/headings/(?P<heading_id>\d+)/delete', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'delete_heading'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // NEW: Create heading
        register_rest_route($namespace, '/headings/create', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_heading'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Create a child page manually (button or external trigger)
        register_rest_route($namespace, '/pages/create', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_subpage'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Goals: AI planning step (returns understanding + proposed action items)
        register_rest_route($namespace, '/ai/goals/plan', array(
            'methods' => 'POST',
            'callback' => array($this, 'ai_plan_goal'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Goals: Create goal heading + accepted action items
        register_rest_route($namespace, '/goals/create', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_goal'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // NEW: Refresh page summary
        register_rest_route($namespace, '/page/refresh-summary', array(
            'methods' => 'POST',
            'callback' => array($this, 'refresh_page_summary'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Content proposal accept
        register_rest_route($namespace, '/pages/(?P<page_id>\d+)/content/accept', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'accept_content_proposal'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Page notes
        register_rest_route($namespace, '/pages/(?P<page_id>\d+)/notes', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'save_page_notes'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Subtasks: add, toggle, delete
        register_rest_route($namespace, '/items/(?P<item_id>\d+)/subtasks', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'add_subtask'),
            'permission_callback' => array($this, 'check_permission'),
        ));
        register_rest_route($namespace, '/items/(?P<item_id>\d+)/subtasks/(?P<subtask_id>[a-zA-Z0-9_-]+)/toggle', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'toggle_subtask'),
            'permission_callback' => array($this, 'check_permission'),
        ));
        register_rest_route($namespace, '/items/(?P<item_id>\d+)/subtasks/(?P<subtask_id>[a-zA-Z0-9_-]+)', array(
            'methods'             => 'DELETE',
            'callback'            => array($this, 'delete_subtask'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Dynamic listings: add / remove / refresh
        register_rest_route($namespace, '/pages/(?P<page_id>\d+)/dynamic-listings/(?P<listing_id>[a-zA-Z0-9_-]+)/items', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_dynamic_listing_items'),
            'permission_callback' => array($this, 'check_permission'),
        ));
        register_rest_route($namespace, '/pages/(?P<page_id>\d+)/dynamic-listings', array(
            'methods' => 'POST',
            'callback' => array($this, 'add_dynamic_listing'),
            'permission_callback' => array($this, 'check_permission'),
        ));
        register_rest_route($namespace, '/pages/(?P<page_id>\d+)/dynamic-listings/(?P<listing_id>[a-zA-Z0-9_-]+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_dynamic_listing'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Tools: Bulk sync all pages/headings to wcp_context taxonomy
        register_rest_route($namespace, '/taxonomy/sync-all', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'taxonomy_sync_all'),
            'permission_callback' => function() { return current_user_can('manage_options'); },
        ));
    }

    public function check_permission() {
        return current_user_can('edit_posts');
    }

    /**
     * Get plugin version (for debugging)
     */
    public function get_version() {
        return rest_ensure_response(array(
            'version' => '1.2.1',
            'timestamp' => current_time('mysql'),
            'php_version' => PHP_VERSION,
        ));
    }

    /**
     * Get hierarchical context tree
     */
    public function get_context_tree($request) {
        $contexts = WCP_Taxonomy_Sync::get_all_contexts();

        $tree = $this->build_tree($contexts);

        return rest_ensure_response(array(
            'success' => true,
            'tree' => $tree,
        ));
    }

    private function build_tree($terms, $parent_id = 0) {
        $branch = array();

        foreach ($terms as $term) {
            if ($term->parent == $parent_id) {
                $ref_type = get_term_meta($term->term_id, 'wcp_ref_type', true);
                $ref_id = get_term_meta($term->term_id, 'wcp_ref_id', true);

                $children = $this->build_tree($terms, $term->term_id);

                $branch[] = array(
                    'term_id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'ref_type' => $ref_type,
                    'ref_id' => $ref_id,
                    'count' => $term->count,
                    'children' => $children,
                );
            }
        }

        return $branch;
    }

    /**
     * Get items for a context (including descendants)
     */
    public function get_context_items($request) {
        $context_id = $request->get_param('id');
        $filters = array(
            'item_type' => $request->get_param('item_type'),
            'priority' => $request->get_param('priority'),
            'pinned' => $request->get_param('pinned'),
            'tag' => $request->get_param('tag'),
        );

        // Get all descendant term IDs
        $term_ids = $this->get_term_and_descendants($context_id);

        $args = array(
            'post_type' => 'post',
            'posts_per_page' => 100,
            'tax_query' => array(
                array(
                    'taxonomy' => 'wcp_context',
                    'field' => 'term_id',
                    'terms' => $term_ids,
                ),
            ),
        );

        // Apply filters
        if (!empty($filters['item_type'])) {
            $args['tax_query'][] = array(
                'taxonomy' => 'item_type',
                'field' => 'slug',
                'terms' => $filters['item_type'],
            );
        }

        if (!empty($filters['priority'])) {
            $args['tax_query'][] = array(
                'taxonomy' => 'priority',
                'field' => 'slug',
                'terms' => $filters['priority'],
            );
        }

        if (!empty($filters['pinned'])) {
            $args['tax_query'][] = array(
                'taxonomy' => 'pinned',
                'field' => 'slug',
                'terms' => $filters['pinned'],
            );
        }

        if (!empty($filters['tag'])) {
            $args['tax_query'][] = array(
                'taxonomy' => 'post_tag',
                'field' => 'slug',
                'terms' => $filters['tag'],
            );
        }

        $query = new WP_Query($args);

        $items = array();
        foreach ($query->posts as $post) {
            $items[] = $this->format_item($post);
        }

        return rest_ensure_response(array(
            'success' => true,
            'items' => $items,
            'total' => $query->found_posts,
        ));
    }

    private function get_term_and_descendants($term_id) {
        $term_ids = array($term_id);

        $children = get_term_children($term_id, 'wcp_context');
        if (!is_wp_error($children)) {
            $term_ids = array_merge($term_ids, $children);
        }

        return $term_ids;
    }

    private function format_item($post) {
        return array(
            'id' => $post->ID,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt,
            'date' => $post->post_date,
            'modified' => $post->post_modified,
            'contexts' => wp_get_post_terms($post->ID, 'wcp_context', array('fields' => 'names')),
            'item_type' => wp_get_post_terms($post->ID, 'item_type', array('fields' => 'names')),
            'priority' => wp_get_post_terms($post->ID, 'priority', array('fields' => 'names')),
            'pinned' => wp_get_post_terms($post->ID, 'pinned', array('fields' => 'names')),
            'tags' => wp_get_post_terms($post->ID, 'post_tag', array('fields' => 'names')),
            'edit_url' => get_edit_post_link($post->ID, 'raw'),
            'view_url' => get_permalink($post->ID),
        );
    }

    /**
     * Quick create item
     */
    public function create_item($request) {
        $title = $request->get_param('title');
        $content = (string) $request->get_param('content');
        $contexts = $request->get_param('contexts');
        $item_type = $request->get_param('item_type');
        $priority = $request->get_param('priority');
        $pinned = $request->get_param('pinned');
        $tags = $request->get_param('tags');

        $post_id = wp_insert_post(array(
            'post_type'    => 'post',
            'post_title'   => $title,
            'post_content' => isset($content) && $content !== null ? $content : '',
            'post_status'  => 'publish',
        ));

        if (is_wp_error($post_id)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => $post_id->get_error_message(),
            ));
        }

        // Set taxonomies — accept single ID or array
        if (!empty($contexts)) {
            $contexts = is_array($contexts) ? $contexts : array($contexts);
            wp_set_post_terms($post_id, array_map('intval', $contexts), 'wcp_context');
        }

        if (!empty($item_type)) {
            wp_set_post_terms($post_id, $item_type, 'item_type');
            if ($item_type === 'task') {
                wp_set_post_terms($post_id, array('to-do'), 'task_status');
            }
        }

        if (!empty($priority)) {
            wp_set_post_terms($post_id, $priority, 'priority');
        }

        if (!empty($pinned)) {
            wp_set_post_terms($post_id, $pinned, 'pinned');
        }

        if (!empty($tags)) {
            wp_set_post_terms($post_id, $tags, 'post_tag');
        }

        // Immediately embed the new item (bypasses save_post throttle)
        if (get_option('wcp_ai_enabled', false)) {
            WCP_Embeddings_Manager::instance()->generate_embedding($post_id);
        }

        return rest_ensure_response(array(
            'success' => true,
            'post_id' => $post_id,
            'edit_url' => get_edit_post_link($post_id, 'raw'),
        ));
    }

    /**
     * Reorder items via drag-and-drop.
     * Accepts one or two lists (source + destination) with their full ordered item IDs.
     * Updates menu_order for each item and reassigns wcp_context if the item moved lists.
     */
    public function reorder_items($request) {
        global $wpdb;

        $lists = $request->get_param('lists');

        if (!is_array($lists) || empty($lists)) {
            return new WP_Error('invalid_payload', 'lists parameter required', array('status' => 400));
        }

        foreach ($lists as $list) {
            $context_id = intval($list['context_id']);
            $item_ids   = array_map('intval', (array) $list['item_ids']);

            foreach ($item_ids as $index => $post_id) {
                $post = get_post($post_id);
                if (!$post || $post->post_type !== 'post') {
                    continue;
                }

                if (!current_user_can('edit_post', $post_id)) {
                    continue;
                }

                // Update menu_order directly to avoid triggering save_post hooks
                // (which would queue unnecessary embedding regeneration on every reorder).
                $wpdb->update(
                    $wpdb->posts,
                    array('menu_order' => $index * 10),
                    array('ID' => $post_id),
                    array('%d'),
                    array('%d')
                );
                clean_post_cache($post_id);

                // If this item doesn't already belong to this context, add it.
                // Preserves any other existing context assignments (multi-page items).
                $current_context_ids = wp_get_post_terms($post_id, 'wcp_context', array('fields' => 'ids'));
                if (!is_wp_error($current_context_ids) && !in_array($context_id, $current_context_ids)) {
                    wp_set_post_terms($post_id, array_merge($current_context_ids, array($context_id)), 'wcp_context');
                }
            }
        }

        return rest_ensure_response(array('success' => true));
    }

    /**
     * AI: Suggest tags based on content
     * CRITICAL: Returns proposal only, does not save
     */
    public function ai_suggest_tags($request) {
        $content = $request->get_param('content');
        $title = $request->get_param('title');

        // Check if AI is enabled
        if (!get_option('wcp_ai_enabled', false)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'AI features are not enabled. Please enable them in Settings.',
            ));
        }

        $ai_client = WCP_AI_Client::instance();

        if (!$ai_client->is_configured()) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'AI is not configured. Please add your Anthropic API key in Settings.',
            ));
        }

        // Call AI
        $result = $ai_client->suggest_tags($title, $content);

        if (is_wp_error($result)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => $result->get_error_message(),
            ));
        }

        $suggestions = array(
            'contexts' => array(), // Term IDs - would need context analysis
            'item_type' => isset($result['item_type']) ? $result['item_type'] : '',
            'priority' => isset($result['priority']) ? $result['priority'] : '',
            'tags' => isset($result['tags']) ? $result['tags'] : array(),
        );

        // Log AI action
        $logger = WCP_AI_Logger::instance();
        $action_id = $logger->log_action('tagging', array(
            'model' => get_option('wcp_ai_model', 'claude-sonnet-4-6'),
            'prompt' => 'Suggest tags for: ' . $title,
            'input_context' => array(
                'title' => $title,
                'content' => $content,
            ),
            'output' => $suggestions,
        ));

        return rest_ensure_response(array(
            'success' => true,
            'action_id' => $action_id,
            'suggestions' => $suggestions,
        ));
    }

    /**
     * AI: Page-scoped chat
     * CRITICAL: Returns proposal only
     */
    public function ai_page_chat($request) {
        $page_id = $request->get_param('page_id');
        $prompt = $request->get_param('prompt');

        // Check if AI is enabled
        if (!get_option('wcp_ai_enabled', false)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'AI features are not enabled.',
            ));
        }

        $ai_client = WCP_AI_Client::instance();

        if (!$ai_client->is_configured()) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'AI is not configured.',
            ));
        }

        // Build context pack (with semantic search if available)
        $context = $this->build_page_context($page_id, $prompt);

        // Call AI
        $result = $ai_client->page_chat($context, $prompt);

        if (is_wp_error($result)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => $result->get_error_message(),
            ));
        }

        $response = array(
            'message' => $result['message'],
            'suggested_items' => array(),
        );

        // Log AI action
        $logger = WCP_AI_Logger::instance();
        $action_id = $logger->log_action('chat', array(
            'model' => $result['model'],
            'prompt' => $prompt,
            'input_context' => $context,
            'output' => $response,
            'context_post_id' => $page_id,
        ));

        return rest_ensure_response(array(
            'success' => true,
            'action_id' => $action_id,
            'response' => $response,
        ));
    }

    /**
     * AI: Coaching prompts
     * CRITICAL: Returns candidate ItemPosts
     */
    public function ai_coaching($request) {
        $context_id = $request->get_param('context_id');
        $prompt_type = $request->get_param('prompt_type');

        // Check if AI is enabled
        if (!get_option('wcp_ai_enabled', false)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'AI features are not enabled.',
            ));
        }

        $ai_client = WCP_AI_Client::instance();

        if (!$ai_client->is_configured()) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'AI is not configured.',
            ));
        }

        $context = $this->build_page_context($context_id);

        // Call AI
        $result = $ai_client->coaching($context, $prompt_type);

        if (is_wp_error($result)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => $result->get_error_message(),
            ));
        }

        $candidate_items = isset($result['candidates']) ? $result['candidates'] : array();

        // Log AI action
        $logger = WCP_AI_Logger::instance();
        $action_id = $logger->log_action('coaching', array(
            'model' => $result['model'],
            'prompt' => 'Coaching prompt: ' . $prompt_type,
            'input_context' => $context,
            'output' => $candidate_items,
            'context_post_id' => $context_id,
        ));

        return rest_ensure_response(array(
            'success' => true,
            'action_id' => $action_id,
            'candidate_items' => $candidate_items,
        ));
    }

    /**
     * AI: Accept or dismiss candidates (OLD ENDPOINT - LEGACY)
     * CRITICAL: This is the ONLY way AI content enters the database
     */
    public function ai_decide($request) {
        // Debug: Log that OLD endpoint is being called
        file_put_contents(
            WCP_PLUGIN_DIR . 'debug-log.txt',
            date('Y-m-d H:i:s') . " - OLD ai_decide endpoint called!\n",
            FILE_APPEND
        );

        $action_id = $request->get_param('action_id');
        $accepted = $request->get_param('accepted');
        $dismissed = $request->get_param('dismissed');

        $accepted_post_ids = array();

        // Create posts from accepted candidates
        if (!empty($accepted)) {
            foreach ($accepted as $candidate) {
                $post_id = wp_insert_post(array(
                    'post_type' => 'post',
                    'post_title' => $candidate['title'],
                    'post_content' => $candidate['content'],
                    'post_status' => 'publish',
                ));

                if (!is_wp_error($post_id)) {
                    // Mark as AI-generated
                    update_post_meta($post_id, '_wcp_ai_generated', true);
                    update_post_meta($post_id, '_wcp_ai_action_id', $action_id);

                    // Apply taxonomies
                    if (!empty($candidate['contexts'])) {
                        wp_set_post_terms($post_id, $candidate['contexts'], 'wcp_context');
                    }

                    if (!empty($candidate['item_type'])) {
                        wp_set_post_terms($post_id, $candidate['item_type'], 'item_type');
                    }

                    $accepted_post_ids[] = $post_id;
                }
            }
        }

        // Log decisions
        $logger = WCP_AI_Logger::instance();
        $logger->log_decisions($action_id, $accepted_post_ids, $dismissed);

        return rest_ensure_response(array(
            'success' => true,
            'created_posts' => $accepted_post_ids,
        ));
    }

    /**
     * Build context pack for AI
     * Enhanced with semantic search when available
     */
    private function build_page_context($post_id, $query = null) {
        $post = get_post($post_id);

        if (!$post) {
            return array();
        }

        $context = array(
            'page' => array(
                'title' => $post->post_title,
                'content' => $post->post_content,
            ),
            'headings' => array(),
            'recent_items' => array(),
            'pinned_items' => array(),
            'learnings' => array(),
        );

        // Get context term
        $ref_type = $post->post_type === 'page' ? 'page' : 'wcp_heading';
        $terms = get_terms(array(
            'taxonomy' => 'wcp_context',
            'hide_empty' => false,
            'meta_query' => array(
                array('key' => 'wcp_ref_type', 'value' => $ref_type),
                array('key' => 'wcp_ref_id', 'value' => $post_id),
            ),
        ));

        if (!empty($terms)) {
            $term_id = $terms[0]->term_id;

            // Use semantic search if embeddings are enabled and query is provided
            $use_semantic_search = false;
            if ($query && get_option('wcp_embeddings_enabled', false)) {
                $embeddings_client = WCP_Embeddings_Client::instance();
                if ($embeddings_client->is_configured()) {
                    $use_semantic_search = true;
                }
            }

            if ($use_semantic_search) {
                // Get all posts in this context
                $args = array(
                    'post_type' => 'post',
                    'post_status' => 'publish',
                    'posts_per_page' => -1,
                    'fields' => 'ids',
                    'tax_query' => array(
                        array(
                            'taxonomy' => 'wcp_context',
                            'field' => 'term_id',
                            'terms' => $term_id,
                        ),
                    ),
                );
                $context_post_ids = get_posts($args);

                if (!empty($context_post_ids)) {
                    // Use semantic search to find most relevant items
                    $similar_posts = $embeddings_client->find_similar_posts(
                        $query,
                        15, // Get top 15 most relevant
                        'post',
                        array() // Don't exclude any
                    );

                    if (!is_wp_error($similar_posts)) {
                        // Filter to only posts in this context
                        foreach ($similar_posts as $similar) {
                            if (in_array($similar['post_id'], $context_post_ids)) {
                                $item = get_post($similar['post_id']);
                                if ($item) {
                                    $context['recent_items'][] = array(
                                        'title' => $item->post_title,
                                        'content' => $item->post_content,
                                        'similarity' => $similar['similarity'],
                                    );
                                }
                            }
                        }
                    }
                }
            }

            // Fallback to recent items if semantic search not used or returned nothing
            if (empty($context['recent_items'])) {
                $args = array(
                    'post_type' => 'post',
                    'posts_per_page' => 20,
                    'orderby' => 'date',
                    'order' => 'DESC',
                    'tax_query' => array(
                        array(
                            'taxonomy' => 'wcp_context',
                            'field' => 'term_id',
                            'terms' => $term_id,
                        ),
                    ),
                );

                $items = get_posts($args);
                foreach ($items as $item) {
                    $context['recent_items'][] = array(
                        'title' => $item->post_title,
                        'content' => $item->post_content,
                    );
                }
            }
        }

        return $context;
    }

    /**
     * Semantic search endpoint
     */
    public function semantic_search($request) {
        $query = $request->get_param('query');
        $limit = $request->get_param('limit') ?: 10;
        $post_type = $request->get_param('post_type');
        $exclude_ids = $request->get_param('exclude_ids') ?: array();

        if (empty($query)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'Query parameter is required',
            ));
        }

        // Check if embeddings are enabled
        if (!get_option('wcp_embeddings_enabled', false)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'Semantic search is not enabled',
            ));
        }

        $embeddings_client = WCP_Embeddings_Client::instance();

        if (!$embeddings_client->is_configured()) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'OpenAI API key not configured',
            ));
        }

        // Find similar posts
        $results = $embeddings_client->find_similar_posts($query, $limit, $post_type, $exclude_ids);

        if (is_wp_error($results)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => $results->get_error_message(),
            ));
        }

        // Format results with post details
        $formatted_results = array();
        foreach ($results as $result) {
            $post = get_post($result['post_id']);
            if ($post) {
                $formatted_results[] = array(
                    'post_id' => $post->ID,
                    'title' => $post->post_title,
                    'content' => wp_trim_words($post->post_content, 50),
                    'excerpt' => $post->post_excerpt,
                    'post_type' => $post->post_type,
                    'similarity' => round($result['similarity'], 4),
                    'edit_url' => get_edit_post_link($post->ID, 'raw'),
                    'view_url' => get_permalink($post->ID),
                    'contexts' => wp_get_post_terms($post->ID, 'wcp_context', array('fields' => 'names')),
                );
            }
        }

        return rest_ensure_response(array(
            'success' => true,
            'query' => $query,
            'results' => $formatted_results,
            'count' => count($formatted_results),
        ));
    }

    /**
     * Batch generate embeddings
     */
    public function batch_generate_embeddings($request) {
        // Increase PHP execution time for batch processing
        // Each embedding API call takes ~1-2 seconds, batch of 50 could take 100+ seconds
        set_time_limit(120);

        $post_type = $request->get_param('post_type') ?: 'post';
        $limit = $request->get_param('limit') ?: 50;
        $offset = $request->get_param('offset') ?: 0;

        $manager = WCP_Embeddings_Manager::instance();
        $results = $manager->batch_generate_embeddings($post_type, $limit, $offset);

        return rest_ensure_response(array(
            'success' => true,
            'results' => $results,
            'post_type' => $post_type,
            'processed' => $results['total'],
        ));
    }

    /**
     * Get embedding statistics
     */
    public function get_embedding_stats($request) {
        $manager = WCP_Embeddings_Manager::instance();
        $stats = $manager->get_stats();

        return rest_ensure_response(array(
            'success' => true,
            'stats' => $stats,
        ));
    }

    /**
     * Generate embedding for a single post
     */
    public function generate_single_embedding($request) {
        $post_id = $request->get_param('post_id');

        if (!$post_id) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'Post ID is required',
            ));
        }

        $manager = WCP_Embeddings_Manager::instance();
        $result = $manager->generate_embedding($post_id);

        if (is_wp_error($result)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => $result->get_error_message(),
            ));
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Embedding generated successfully',
            'post_id' => $post_id,
        ));
    }

    /**
     * NEW: Initialize or get conversation for a page
     */
    public function init_conversation($request) {
        $page_id = $request->get_param('page_id');
        $user_id = get_current_user_id();

        if (!$page_id) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'Page ID is required',
            ));
        }

        if (!$user_id) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'User not authenticated',
            ));
        }

        // Get or create conversation
        $conversations_manager = WCP_Conversations_Manager::instance();
        $conversation_id = $conversations_manager->get_or_create_conversation($page_id, $user_id);

        if (is_wp_error($conversation_id)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => $conversation_id->get_error_message(),
            ));
        }

        // Get conversation messages
        $messages = $conversations_manager->get_messages($conversation_id, 50);

        // Get conversation details
        $conversation = $conversations_manager->get_conversation($conversation_id);

        return rest_ensure_response(array(
            'success' => true,
            'conversation_id' => $conversation_id,
            'conversation_title' => $conversation->conversation_title ?? null,
            'messages' => $messages,
            'message_count' => count($messages),
        ));
    }

    /**
     * NEW: Execute AI action with conversation
     */
    public function execute_action($request) {
        $action_type = $request->get_param('action_type');
        $prompt = $request->get_param('prompt');
        $page_id = $request->get_param('page_id');
        $conversation_id = $request->get_param('conversation_id');
        $context_mode = $request->get_param('context_mode') ?? 'page';
        $selected_pages = $request->get_param('selected_pages') ?? array();

        // Validate required params
        if (!$action_type || !$prompt || !$page_id) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'Missing required parameters (action_type, prompt, page_id)',
            ));
        }

        // Check if AI is enabled
        if (!get_option('wcp_ai_enabled', false)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'AI features are not enabled',
            ));
        }

        $ai_client = WCP_AI_Client::instance();
        if (!$ai_client->is_configured()) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'AI is not configured',
            ));
        }

        // Execute action
        $ai_actions = WCP_AI_Actions::instance();
        $result = null;

        // Auto-route: detect intent from the prompt, then fall through to the resolved type
        if ( $action_type === 'auto' ) {
            $routed      = $ai_actions->auto_route( $prompt );
            $action_type = $routed['action'];
            if ( ! $request->get_param('item_count') && $routed['item_count'] > 0 ) {
                $request->set_param( 'item_count', $routed['item_count'] );
            }
        }

        switch ($action_type) {
            case 'chat':
            case 'chat_qa':
                $result = $ai_actions->chat_qa($prompt, $page_id, $context_mode, $selected_pages, $conversation_id);
                break;

            case 'generate':
            case 'generate-single':
            case 'generate_items':
                $item_count = intval($request->get_param('item_count') ?? 0);
                $result = $ai_actions->generate_items($prompt, $page_id, $context_mode, $selected_pages, $conversation_id, $item_count);
                break;

            case 'generate_headings':
                $item_count = intval($request->get_param('item_count') ?? 0);
                $result = $ai_actions->generate_headings($prompt, $page_id, $context_mode, $selected_pages, $conversation_id, $item_count);
                break;

            case 'generate_pages':
                $item_count = intval($request->get_param('item_count') ?? 0);
                $result = $ai_actions->generate_pages($prompt, $page_id, $context_mode, $selected_pages, $conversation_id, $item_count);
                break;

            case 'rewrite_content':
                $result = $ai_actions->rewrite_page_content($prompt, $page_id, $context_mode, $selected_pages);
                break;

            case 'append_content':
                $result = $ai_actions->append_page_content($prompt, $page_id, $context_mode, $selected_pages);
                break;

            case 'fetch_posts':
                $result = $ai_actions->fetch_posts_auto($prompt, $page_id, $conversation_id);
                break;

            case 'fetch_structure':
                $result = $ai_actions->fetch_structure_chat($prompt, $conversation_id);
                break;

            // Legacy support
            case 'coaching':
            case 'coaching_dialogue':
                $use_rag = ($context_mode === 'corpus');
                $result = $ai_actions->coaching_dialogue($prompt, $page_id, $use_rag, $conversation_id);
                break;

            case 'generate_single_item':
                $use_rag = ($context_mode === 'corpus');
                $result = $ai_actions->generate_single_item($prompt, $page_id, $use_rag, $conversation_id);
                break;

            default:
                return rest_ensure_response(array(
                    'success' => false,
                    'message' => 'Unknown action type: ' . $action_type,
                ));
        }

        if (is_wp_error($result)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => $result->get_error_message(),
            ));
        }

        return rest_ensure_response(array(
            'success' => true,
            'result' => $result,
        ));
    }

    /**
     * NEW: Decide on proposals (accept/dismiss items)
     * Supports both single proposals and batch decisions
     */
    public function decide_proposals($request) {
        // Debug: Write to file to confirm this code is running
        file_put_contents(
            WCP_PLUGIN_DIR . 'debug-log.txt',
            date('Y-m-d H:i:s') . " - decide_proposals v1.2.1 called\n",
            FILE_APPEND
        );

        $proposal_id = $request->get_param('proposal_id');
        $batch_id = $request->get_param('batch_id');
        $decision = $request->get_param('decision'); // 'accept' or 'dismiss'

        // Handle selected_proposal_ids - may come as array or need to be parsed
        $selected_proposal_ids = $request->get_param('selected_proposal_ids');
        if (empty($selected_proposal_ids)) {
            $selected_proposal_ids = array();
        } elseif (is_string($selected_proposal_ids)) {
            // Try JSON decode if it's a string
            $decoded = json_decode($selected_proposal_ids, true);
            $selected_proposal_ids = is_array($decoded) ? $decoded : array($selected_proposal_ids);
        } elseif (!is_array($selected_proposal_ids)) {
            $selected_proposal_ids = array();
        }

        // Debug: Always include received params in response
        $received_params = array(
            'api_version' => '1.2.1',
            'proposal_id' => $proposal_id,
            'batch_id' => $batch_id,
            'decision' => $decision,
            'selected_proposal_ids' => $selected_proposal_ids,
            'has_batch_id' => !empty($batch_id),
        );

        // Handle batch decisions (multiple proposals)
        if ($batch_id) {
            return $this->handle_batch_decision($batch_id, $decision, $selected_proposal_ids, $received_params);
        }

        // Handle single proposal (legacy support)
        if (!$proposal_id || !$decision) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'Missing required parameters (proposal_id or batch_id, decision)',
                'debug' => $received_params,
            ));
        }

        if ($decision === 'accept') {
            // Execute proposal
            $ai_actions = WCP_AI_Actions::instance();
            $result = $ai_actions->execute_proposal($proposal_id, array());

            if (is_wp_error($result)) {
                return rest_ensure_response(array(
                    'success' => false,
                    'message' => $result->get_error_message(),
                    'debug' => $received_params,
                ));
            }

            return rest_ensure_response(array(
                'success' => true,
                'decision' => 'accepted',
                'created_posts' => $result['created_posts'],
                'message' => $result['message'],
                'debug' => array_merge($received_params, array('result_debug' => $result['debug'] ?? null)),
            ));
        } else if ($decision === 'dismiss') {
            // Just delete the transient
            delete_transient('wcp_proposal_' . $proposal_id);

            return rest_ensure_response(array(
                'success' => true,
                'decision' => 'dismissed',
                'message' => 'Proposal dismissed',
                'debug' => $received_params,
            ));
        } else {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'Invalid decision. Must be "accept" or "dismiss"',
                'debug' => $received_params,
            ));
        }
    }

    /**
     * Handle batch decision for multiple proposals
     */
    private function handle_batch_decision($batch_id, $decision, $selected_proposal_ids, $received_params = array()) {
        // Get batch info
        $batch = get_transient('wcp_batch_' . $batch_id);

        if (!$batch) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'Batch not found or expired',
                'debug' => array_merge($received_params, array('batch_id_searched' => 'wcp_batch_' . $batch_id)),
            ));
        }

        $all_proposal_ids = $batch['proposal_ids'] ?? array();
        $created_posts = array();
        $ai_actions = WCP_AI_Actions::instance();

        if ($decision === 'dismiss') {
            // Dismiss all proposals in batch
            foreach ($all_proposal_ids as $pid) {
                delete_transient('wcp_proposal_' . $pid);
            }
            delete_transient('wcp_batch_' . $batch_id);

            return rest_ensure_response(array(
                'success' => true,
                'decision' => 'dismissed',
                'message' => 'All proposals dismissed',
                'debug' => $received_params,
            ));
        }

        if ($decision === 'accept') {
            // Ensure selected_proposal_ids is an array
            if (!is_array($selected_proposal_ids)) {
                $selected_proposal_ids = array();
            }

            $errors = array();
            $proposal_debug = array();

            // Accept selected proposals, dismiss unselected
            foreach ($all_proposal_ids as $pid) {
                if (in_array($pid, $selected_proposal_ids, false)) {
                    // Accept this proposal
                    $result = $ai_actions->execute_proposal($pid, array());
                    if (is_wp_error($result)) {
                        $errors[] = array(
                            'proposal_id' => $pid,
                            'error' => $result->get_error_message()
                        );
                    } else {
                        if (!empty($result['created_posts'])) {
                            $created_posts = array_merge($created_posts, $result['created_posts']);
                        }
                        // Collect debug info from each proposal
                        if (isset($result['debug'])) {
                            $proposal_debug[] = $result['debug'];
                        }
                    }
                } else {
                    // Dismiss unselected
                    delete_transient('wcp_proposal_' . $pid);
                }
            }

            // Clean up batch
            delete_transient('wcp_batch_' . $batch_id);

            $count = count($created_posts);
            $response = array(
                'success' => true,
                'decision' => 'accepted',
                'created_posts' => $created_posts,
                'message' => $count . ' item' . ($count !== 1 ? 's' : '') . ' created',
            );

            // Always include debug info for troubleshooting
            $response['debug'] = array(
                'api_version' => '1.2.1',
                'received_params' => $received_params,
                'batch_proposal_count' => count($all_proposal_ids),
                'selected_count' => count($selected_proposal_ids),
                'selected_ids' => $selected_proposal_ids,
                'all_ids' => $all_proposal_ids,
                'errors' => $errors,
                'proposal_results' => $proposal_debug,
            );

            return rest_ensure_response($response);
        }

        return rest_ensure_response(array(
            'success' => false,
            'message' => 'Invalid decision. Must be "accept" or "dismiss"',
        ));
    }

    /**
     * NEW: Expand draft content from editor
     */
    public function editor_expand_draft($request) {
        $prompt = $request->get_param('prompt');
        $draft_content = $request->get_param('draft_content');
        $post_id = $request->get_param('post_id');
        $context_mode = $request->get_param('context_mode') ?? 'page';
        $selected_pages = $request->get_param('selected_pages') ?? array();

        // Validate required params
        if (!$prompt || !$post_id) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'Missing required parameters (prompt, post_id)',
            ));
        }

        // Add size validation and truncation
        $max_draft_chars = 15000; // ~3,750 tokens (conservative limit)

        if (strlen($draft_content) > $max_draft_chars) {
            // Truncate and notify user
            $original_length = strlen($draft_content);
            $draft_content = mb_substr($draft_content, 0, $max_draft_chars);

            // Add warning to draft content
            $draft_content .= "\n\n[...CONTENT TRUNCATED FOR PROCESSING - Original: " . number_format($original_length) . " chars, Truncated to: " . number_format($max_draft_chars) . " chars...]";

            error_log("WCP: Draft content truncated from {$original_length} to {$max_draft_chars} chars for post {$post_id}");
        }

        // Check if AI is enabled
        if (!get_option('wcp_ai_enabled', false)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'AI features are not enabled',
            ));
        }

        $ai_client = WCP_AI_Client::instance();
        if (!$ai_client->is_configured()) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'AI is not configured',
            ));
        }

        // Execute expand draft action
        $ai_actions = WCP_AI_Actions::instance();
        $result = $ai_actions->expand_draft($prompt, $draft_content, $post_id, $context_mode, $selected_pages);

        if (is_wp_error($result)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => $result->get_error_message(),
            ));
        }

        return rest_ensure_response(array(
            'success' => true,
            'result' => $result,
        ));
    }

    /**
     * NEW: Get saved prompt chips
     */
    public function get_prompts($request) {
        $prompts = get_option('wcp_saved_prompts', array());

        // Add default prompts if none saved
        if (empty($prompts)) {
            $prompts = array(
                array('label' => 'Expand', 'prompt' => 'Expand this with more detail and examples'),
                array('label' => 'Concise', 'prompt' => 'Make this more concise while keeping key points'),
                array('label' => 'Actions', 'prompt' => 'Add actionable next steps'),
            );
        }

        return rest_ensure_response(array(
            'success' => true,
            'prompts' => $prompts,
        ));
    }

    /**
     * NEW: Save a new prompt chip
     */
    public function save_prompt($request) {
        $label = sanitize_text_field($request->get_param('label'));
        $prompt = sanitize_textarea_field($request->get_param('prompt'));

        if (empty($label) || empty($prompt)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'Label and prompt are required',
            ));
        }

        $prompts = get_option('wcp_saved_prompts', array());

        // Limit to 20 prompts
        if (count($prompts) >= 20) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'Maximum 20 saved prompts allowed',
            ));
        }

        // Add new prompt
        $prompts[] = array(
            'label' => $label,
            'prompt' => $prompt,
            'created_at' => current_time('mysql'),
        );

        update_option('wcp_saved_prompts', $prompts);

        return rest_ensure_response(array(
            'success' => true,
            'prompts' => $prompts,
            'message' => 'Prompt saved',
        ));
    }

    /**
     * NEW: Delete a prompt chip
     */
    public function delete_prompt($request) {
        $index = intval($request->get_param('index'));

        $prompts = get_option('wcp_saved_prompts', array());

        if (!isset($prompts[$index])) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'Prompt not found',
            ));
        }

        // Remove prompt at index
        array_splice($prompts, $index, 1);

        update_option('wcp_saved_prompts', $prompts);

        return rest_ensure_response(array(
            'success' => true,
            'prompts' => $prompts,
            'message' => 'Prompt deleted',
        ));
    }

    /**
     * NEW: Get pages list for context selector
     */
    public function get_pages_list($request) {
        $search = $request->get_param('search');

        $args = array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'posts_per_page' => -1, // Get all pages for hierarchical display
            'orderby' => 'title',
            'order' => 'ASC',
        );

        if (!empty($search)) {
            $args['s'] = $search;
        }

        $pages = get_posts($args);

        // Build hierarchical tree
        $hierarchical = $this->build_page_tree($pages);

        return rest_ensure_response(array(
            'success' => true,
            'pages' => $hierarchical,
        ));
    }

    /**
     * Build hierarchical page tree
     *
     * @param array $pages Flat list of pages
     * @param int $parent_id Parent ID to filter by (0 = root level)
     * @return array Hierarchical tree structure
     */
    private function build_page_tree($pages, $parent_id = 0) {
        $tree = array();

        foreach ($pages as $page) {
            if ($page->post_parent == $parent_id) {
                $node = array(
                    'id' => $page->ID,
                    'title' => $page->post_title,
                    'parent_id' => $page->post_parent,
                    'children' => $this->build_page_tree($pages, $page->ID)
                );
                $tree[] = $node;
            }
        }

        return $tree;
    }

    /**
     * NEW: Create a Heading under a Page or another Heading
     */
    public function create_heading($request) {
        $title = sanitize_text_field($request->get_param('title'));
        $content = wp_kses_post($request->get_param('content'));
        $parent_id = intval($request->get_param('parent_id'));
        $parent_type = sanitize_text_field($request->get_param('parent_type'));

        if (empty($title) || empty($parent_id)) {
            return new WP_Error('invalid_params', 'Title and parent_id are required', array('status' => 400));
        }

        // Validate parent_type
        if (!in_array($parent_type, array('page', 'wcp_heading'))) {
            return new WP_Error('invalid_parent_type', 'Parent type must be page or wcp_heading', array('status' => 400));
        }

        // Create heading
        $heading_id = wp_insert_post(array(
            'post_type' => 'wcp_heading',
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => 'publish',
        ));

        if (is_wp_error($heading_id)) {
            return $heading_id;
        }

        // Set parent meta before syncing — the save_post hook fired during wp_insert_post
        // before this meta existed, so we re-run the sync explicitly now.
        update_post_meta($heading_id, '_wcp_parent_type', $parent_type);
        update_post_meta($heading_id, '_wcp_parent_id', $parent_id);
        WCP_Taxonomy_Sync::instance()->sync_heading_to_taxonomy($heading_id, get_post($heading_id), true);

        $term = null;
        if (function_exists('wcp_theme_get_heading_context_term')) {
            $term = wcp_theme_get_heading_context_term($heading_id);
        }

        return rest_ensure_response(array(
            'success' => true,
            'heading_id' => $heading_id,
            'term_id' => $term ? $term->term_id : null,
            'heading' => get_post($heading_id),
        ));
    }

    /**
     * Get active mission for a page
     *
     * GET /work-copilot/v1/mission/active?page_id=123
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response with mission context
     */
    public function get_active_mission($request) {
        $page_id = $request->get_param('page_id');

        $mission_loader = WCP_Mission_Loader::instance();
        $mission_context = $mission_loader->get_mission_context($page_id);

        return rest_ensure_response(array(
            'success' => true,
            'global_mission' => $mission_context['global'],
            'page_objectives' => $mission_context['page'],
            'source' => $mission_context['source'],
            'mission_text' => !empty($mission_context['page']) ? $mission_context['page'] : $mission_context['global']
        ));
    }

    /**
     * Extract memories from conversation
     *
     * POST /work-copilot/v1/ai/memories/extract
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response with memory proposals
     */
    public function extract_memories($request) {
        $conversation_id = $request->get_param('conversation_id');

        if (empty($conversation_id)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'Missing conversation_id parameter'
            ));
        }

        $ai_actions = WCP_AI_Actions::instance();
        $result = $ai_actions->extract_memories_action($conversation_id);

        if (is_wp_error($result)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => $result->get_error_message()
            ));
        }

        return rest_ensure_response(array(
            'success' => true,
            'outcome' => $result['outcome'],
            'proposals' => isset($result['proposals']) ? $result['proposals'] : array(),
            'batch_id' => isset($result['batch_id']) ? $result['batch_id'] : null,
            'message' => isset($result['message']) ? $result['message'] : null
        ));
    }

    /**
     * Refresh page summary
     *
     * POST /work-copilot/v1/page/refresh-summary
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response with summary
     */
    public function refresh_page_summary($request) {
        $page_id = intval($request->get_param('page_id'));

        if (!$page_id) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'Missing page_id parameter'
            ));
        }

        // Check permission - user must be able to edit this page
        if (!current_user_can('edit_post', $page_id)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'Permission denied'
            ));
        }

        // Check if AI is enabled
        if (!get_option('wcp_ai_enabled', false)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'AI features are not enabled'
            ));
        }

        $ai_client = WCP_AI_Client::instance();
        if (!$ai_client->is_configured()) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'AI is not configured'
            ));
        }

        // Generate summary
        $ai_actions = WCP_AI_Actions::instance();
        $result = $ai_actions->summarize_page($page_id);

        if (is_wp_error($result)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => $result->get_error_message()
            ));
        }

        return rest_ensure_response($result);
    }

    public function update_item($request) {
        $item_id = intval($request->get_param('id'));
        $post = get_post($item_id);

        if (!$post || $post->post_type !== 'post') {
            return new WP_Error('not_found', 'Item not found', array('status' => 404));
        }

        if (!current_user_can('edit_post', $item_id)) {
            return new WP_Error('forbidden', 'Permission denied', array('status' => 403));
        }

        $updated = array('ID' => $item_id);

        $title = $request->get_param('title');
        if ($title !== null) {
            $updated['post_title'] = sanitize_text_field($title);
        }

        if (count($updated) > 1) {
            wp_update_post($updated);
        }

        $item_type = $request->get_param('item_type');
        if ($item_type !== null) {
            $terms = $item_type ? array(sanitize_key($item_type)) : array();
            wp_set_post_terms($item_id, $terms, 'item_type');
        }

        $priority = $request->get_param('priority');
        if ($priority !== null) {
            $terms = $priority ? array(sanitize_key($priority)) : array();
            wp_set_post_terms($item_id, $terms, 'priority');
        }

        $task_status = $request->get_param('task_status');
        if ($task_status !== null) {
            $terms = $task_status ? array(sanitize_key($task_status)) : array();
            wp_set_post_terms($item_id, $terms, 'task_status');
        }

        $due_date = $request->get_param('due_date');
        if ($due_date !== null) {
            // Accept Y-m-d or empty string to clear
            $safe = preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date) ? $due_date : '';
            update_post_meta($item_id, '_wcp_due_date', $safe);
        }

        // Re-embed immediately, bypassing the 60-second save_post throttle
        if (get_option('wcp_ai_enabled', false)) {
            WCP_Embeddings_Manager::instance()->generate_embedding($item_id);
        }

        return rest_ensure_response(array('success' => true));
    }

    public function delete_item($request) {
        $item_id = intval($request->get_param('id'));
        $post = get_post($item_id);

        if (!$post || $post->post_type !== 'post') {
            return new WP_Error('not_found', 'Item not found', array('status' => 404));
        }

        if (!current_user_can('delete_post', $item_id)) {
            return new WP_Error('forbidden', 'Permission denied', array('status' => 403));
        }

        // Delete embedding before trashing — wp_trash_post fires trashed_post not delete_post,
        // so the delete_post hook in WCP_Embeddings_Manager would never fire.
        if (get_option('wcp_ai_enabled', false)) {
            WCP_Embeddings_Manager::instance()->delete_embedding($item_id);
        }

        wp_trash_post($item_id);

        return rest_ensure_response(array('success' => true));
    }

    /**
     * Create a child page under a parent, applying the parent's template.
     * Used by the manual "Create subpage" button.
     *
     * POST /work-copilot/v1/pages/create
     * Body: { parent_id, title }
     */
    public function create_subpage( $request ) {
        $parent_id = intval( $request->get_param( 'parent_id' ) );
        $title     = sanitize_text_field( $request->get_param( 'title' ) );

        if ( ! $parent_id || ! $title ) {
            return new WP_Error( 'invalid_params', 'parent_id and title are required', array( 'status' => 400 ) );
        }

        $parent = get_post( $parent_id );
        if ( ! $parent || $parent->post_type !== 'page' ) {
            return new WP_Error( 'invalid_parent', 'Parent must be a published page', array( 'status' => 400 ) );
        }

        $result = WCP_Page_Scheduler::create_child_page( $parent_id, $title );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( array(
            'success'  => true,
            'page_id'  => $result['page_id'],
            'page_url' => $result['page_url'],
        ) );
    }

    public function taxonomy_sync_all($request) {
        $taxonomy_sync = WCP_Taxonomy_Sync::instance();
        $counts = $taxonomy_sync->sync_all_to_taxonomy();

        return rest_ensure_response(array(
            'success'  => true,
            'message'  => sprintf(
                'Synced %d pages and %d headings.',
                $counts['pages'],
                $counts['headings']
            ),
            'counts'   => $counts,
        ));
    }

    /**
     * AI planning step for a new goal.
     * Returns the AI's understanding of the goal + proposed action items.
     * Nothing is written to the database at this stage.
     *
     * POST /work-copilot/v1/ai/goals/plan
     * Body: { goal_description, page_id }
     */
    public function ai_plan_goal( $request ) {
        $goal_description = sanitize_textarea_field( $request->get_param( 'goal_description' ) );
        $page_id          = intval( $request->get_param( 'page_id' ) );

        if ( empty( $goal_description ) || empty( $page_id ) ) {
            return new WP_Error( 'invalid_params', 'goal_description and page_id are required', array( 'status' => 400 ) );
        }

        $ai_actions = WCP_AI_Actions::instance();
        $result     = $ai_actions->plan_goal( $goal_description, $page_id );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }

    /**
     * Create a goal heading and its accepted action items in one step.
     * Called after the user has reviewed and confirmed the AI's plan.
     *
     * POST /work-copilot/v1/goals/create
     * Body: { title, description, page_id, parent_id, parent_type, action_items, action_id }
     *   action_items: [{ title, content }, ...]  — only the items the user accepted
     */
    public function create_goal( $request ) {
        $title       = sanitize_text_field( $request->get_param( 'title' ) );
        $description = wp_kses_post( $request->get_param( 'description' ) );
        $page_id     = intval( $request->get_param( 'page_id' ) );
        $parent_id   = intval( $request->get_param( 'parent_id' ) ?: $page_id );
        $parent_type = sanitize_text_field( $request->get_param( 'parent_type' ) ?: 'page' );
        $action_items = $request->get_param( 'action_items' ) ?: array();
        $action_id    = sanitize_text_field( $request->get_param( 'action_id' ) ?: '' );

        if ( empty( $title ) || empty( $page_id ) ) {
            return new WP_Error( 'invalid_params', 'title and page_id are required', array( 'status' => 400 ) );
        }

        if ( ! in_array( $parent_type, array( 'page', 'wcp_heading' ), true ) ) {
            return new WP_Error( 'invalid_parent_type', 'parent_type must be page or wcp_heading', array( 'status' => 400 ) );
        }

        // Create the goal heading
        $heading_id = wp_insert_post( array(
            'post_type'    => 'wcp_heading',
            'post_title'   => $title,
            'post_content' => $description,
            'post_status'  => 'publish',
            'post_author'  => get_current_user_id(),
        ) );

        if ( is_wp_error( $heading_id ) ) {
            return $heading_id;
        }

        update_post_meta( $heading_id, '_wcp_parent_type', $parent_type );
        update_post_meta( $heading_id, '_wcp_parent_id', $parent_id );
        // AI guardrail: flag this as a goal subtype so the UI can render it differently
        update_post_meta( $heading_id, '_wcp_is_goal', '1' );

        // Re-run taxonomy sync now that parent meta is in place (save_post fired
        // during wp_insert_post before the meta above was written).
        WCP_Taxonomy_Sync::instance()->sync_heading_to_taxonomy( $heading_id, get_post( $heading_id ), true );

        // Resolve context term for the new heading to assign items to it
        $heading_term = null;
        $terms = get_terms( array(
            'taxonomy'   => 'wcp_context',
            'hide_empty' => false,
            'meta_query' => array(
                array( 'key' => 'wcp_ref_type', 'value' => 'wcp_heading' ),
                array( 'key' => 'wcp_ref_id',   'value' => $heading_id,  'type' => 'NUMERIC' ),
            ),
        ) );
        if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
            $heading_term = $terms[0];
        }

        // Create the accepted action items under the goal heading
        $created_items = array();
        if ( ! empty( $action_items ) && is_array( $action_items ) && $heading_term ) {
            foreach ( $action_items as $item_data ) {
                $item_title   = sanitize_text_field( $item_data['title'] ?? '' );
                $item_content = sanitize_textarea_field( $item_data['content'] ?? '' );

                if ( empty( $item_title ) ) continue;

                $item_id = wp_insert_post( array(
                    'post_type'    => 'post',
                    'post_title'   => $item_title,
                    'post_content' => $item_content,
                    'post_status'  => 'publish',
                    'post_author'  => get_current_user_id(),
                ) );

                if ( ! is_wp_error( $item_id ) ) {
                    wp_set_post_terms( $item_id, array( $heading_term->term_id ), 'wcp_context' );
                    wp_set_post_terms( $item_id, array( 'task' ), 'item_type' );
                    // AI guardrail audit trail
                    update_post_meta( $item_id, '_wcp_ai_generated', '1' );
                    if ( $action_id ) {
                        update_post_meta( $item_id, '_wcp_ai_action_id', $action_id );
                    }
                    $created_items[] = $item_id;
                }
            }
        }

        // Log acceptance decision against the planning action
        if ( $action_id ) {
            $logger = WCP_AI_Logger::instance();
            $logger->log_decisions( $action_id, $created_items, array() );
        }

        return rest_ensure_response( array(
            'success'       => true,
            'heading_id'    => $heading_id,
            'created_items' => $created_items,
        ) );
    }

    public function reorder_headings( $request ) {
        $heading_ids = array_map('intval', (array) $request->get_param('heading_ids'));
        foreach ( $heading_ids as $order => $id ) {
            wp_update_post( array( 'ID' => $id, 'menu_order' => $order ) );
        }
        return rest_ensure_response( array('success' => true) );
    }

    public function delete_heading( $request ) {
        $heading_id = (int) $request->get_param('heading_id');
        $post = get_post($heading_id);

        if ( ! $post || $post->post_type !== 'wcp_heading' ) {
            return new WP_Error('not_found', 'Heading not found', array('status' => 404));
        }

        wp_delete_post($heading_id, true); // true = force delete, skip trash
        return rest_ensure_response(array('success' => true));
    }

    public function accept_content_proposal( $request ) {
        $page_id     = (int) $request->get_param('page_id');
        $proposal_id = sanitize_text_field( $request->get_param('proposal_id') );

        $proposal = get_transient( 'wcp_content_proposal_' . $proposal_id );
        if ( ! $proposal ) {
            return new WP_Error('expired', 'Proposal not found or expired', array('status' => 404));
        }
        if ( (int) $proposal['page_id'] !== $page_id ) {
            return new WP_Error('mismatch', 'Proposal does not belong to this page', array('status' => 403));
        }

        $new_content = wp_kses_post( $proposal['content'] );

        if ( $proposal['mode'] === 'append' ) {
            $page        = get_post($page_id);
            $new_content = $page->post_content . "\n\n" . $new_content;
        }

        wp_update_post( array( 'ID' => $page_id, 'post_content' => $new_content ) );
        delete_transient( 'wcp_content_proposal_' . $proposal_id );

        return rest_ensure_response( array('success' => true) );
    }

    public function save_page_notes( $request ) {
        $page_id = (int) $request->get_param('page_id');
        if ( ! get_post($page_id) ) {
            return new WP_Error('not_found', 'Page not found', array('status' => 404));
        }
        $notes = wp_kses_post( $request->get_param('notes') ?: '' );
        update_post_meta($page_id, '_wcp_page_notes', $notes);
        return rest_ensure_response(array('success' => true, 'notes' => $notes));
    }

    // ------------------------------------------------------------------
    // Subtask helpers
    // ------------------------------------------------------------------

    private function get_subtasks( $item_id ) {
        return json_decode( get_post_meta($item_id, '_wcp_subtasks', true) ?: '[]', true );
    }

    private function save_subtasks( $item_id, $subtasks ) {
        update_post_meta( $item_id, '_wcp_subtasks', wp_json_encode( array_values($subtasks) ) );
    }

    public function add_subtask( $request ) {
        $item_id = (int) $request->get_param('item_id');
        $title   = sanitize_text_field( $request->get_param('title') );

        if ( ! get_post($item_id) ) {
            return new WP_Error('not_found', 'Item not found', array('status' => 404));
        }
        if ( empty($title) ) {
            return new WP_Error('missing_title', 'Title is required', array('status' => 400));
        }

        $subtasks   = $this->get_subtasks($item_id);
        $subtask    = array('id' => uniqid('st_'), 'title' => $title, 'done' => false);
        $subtasks[] = $subtask;
        $this->save_subtasks($item_id, $subtasks);

        return rest_ensure_response(array('success' => true, 'subtask' => $subtask));
    }

    public function toggle_subtask( $request ) {
        $item_id    = (int) $request->get_param('item_id');
        $subtask_id = $request->get_param('subtask_id');
        $subtasks   = $this->get_subtasks($item_id);

        foreach ( $subtasks as &$st ) {
            if ( $st['id'] === $subtask_id ) {
                $st['done'] = ! $st['done'];
                $this->save_subtasks($item_id, $subtasks);
                return rest_ensure_response(array('success' => true, 'done' => $st['done']));
            }
        }

        return new WP_Error('not_found', 'Subtask not found', array('status' => 404));
    }

    public function delete_subtask( $request ) {
        $item_id    = (int) $request->get_param('item_id');
        $subtask_id = $request->get_param('subtask_id');
        $subtasks   = array_filter(
            $this->get_subtasks($item_id),
            function($st) use ($subtask_id) { return $st['id'] !== $subtask_id; }
        );
        $this->save_subtasks($item_id, $subtasks);

        return rest_ensure_response(array('success' => true));
    }

    /**
     * Re-run a dynamic listing query and return rendered item rows as HTML.
     */
    public function get_dynamic_listing_items( $request ) {
        $page_id    = (int) $request->get_param('page_id');
        $listing_id = $request->get_param('listing_id');

        $listings = json_decode( get_post_meta($page_id, '_wcp_dynamic_listings', true) ?: '[]', true );
        $listing  = null;
        foreach ( $listings as $l ) {
            if ( $l['id'] === $listing_id ) { $listing = $l; break; }
        }

        if ( ! $listing ) {
            return new WP_Error('not_found', 'Listing not found', array('status' => 404));
        }

        $items = wcp_theme_query_dynamic_listing( $listing );

        ob_start();
        foreach ( $items as $item ) {
            $item_types    = wp_get_post_terms($item->ID, 'item_type',   array('fields' => 'names'));
            $priorities    = wp_get_post_terms($item->ID, 'priority',    array('fields' => 'names'));
            $task_statuses = wp_get_post_terms($item->ID, 'task_status', array('fields' => 'slugs'));
            $item_tags     = wp_get_post_terms($item->ID, 'post_tag',    array('fields' => 'names'));
            $item_contexts = wp_get_post_terms($item->ID, 'wcp_context', array('fields' => 'names'));
            include locate_template('template-parts/item-row.php');
        }
        $html = ob_get_clean();

        return rest_ensure_response(array(
            'success' => true,
            'html'    => $html,
            'count'   => count($items),
        ));
    }

    /**
     * Add a dynamic listing query to a page's meta.
     */
    public function add_dynamic_listing( $request ) {
        $page_id = (int) $request->get_param('page_id');
        if ( ! get_post( $page_id ) ) {
            return new WP_Error('not_found', 'Page not found', array('status' => 404));
        }

        $title          = sanitize_text_field( $request->get_param('title') );
        $item_type      = sanitize_key( $request->get_param('item_type') ?: '' );
        $task_status    = sanitize_key( $request->get_param('task_status') ?: '' );
        $parent_page_id = (int) ( $request->get_param('parent_page_id') ?: 0 );

        if ( empty( $title ) ) {
            return new WP_Error('missing_title', 'Title is required', array('status' => 400));
        }

        $listings = json_decode( get_post_meta($page_id, '_wcp_dynamic_listings', true) ?: '[]', true );

        $listing = array(
            'id'             => uniqid('dl_'),
            'title'          => $title,
            'item_type'      => $item_type,
            'task_status'    => $task_status,
            'parent_page_id' => $parent_page_id,
        );

        $listings[] = $listing;
        update_post_meta( $page_id, '_wcp_dynamic_listings', wp_json_encode($listings) );

        return rest_ensure_response( array('success' => true, 'listing' => $listing) );
    }

    /**
     * Remove a dynamic listing from a page's meta.
     */
    public function delete_dynamic_listing( $request ) {
        $page_id    = (int) $request->get_param('page_id');
        $listing_id = $request->get_param('listing_id');

        $listings = json_decode( get_post_meta($page_id, '_wcp_dynamic_listings', true) ?: '[]', true );
        $listings = array_values( array_filter($listings, function($l) use ($listing_id) {
            return $l['id'] !== $listing_id;
        }) );

        update_post_meta( $page_id, '_wcp_dynamic_listings', wp_json_encode($listings) );

        return rest_ensure_response( array('success' => true) );
    }
}
