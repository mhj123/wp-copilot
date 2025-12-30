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

        // Quick create item
        register_rest_route($namespace, '/items/create', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_item'),
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

        // AI: Accept/dismiss
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
    }

    public function check_permission() {
        return current_user_can('edit_posts');
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
        $content = $request->get_param('content');
        $contexts = $request->get_param('contexts');
        $item_type = $request->get_param('item_type');
        $priority = $request->get_param('priority');
        $pinned = $request->get_param('pinned');
        $tags = $request->get_param('tags');

        $post_id = wp_insert_post(array(
            'post_type' => 'post',
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => 'publish',
        ));

        if (is_wp_error($post_id)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => $post_id->get_error_message(),
            ));
        }

        // Set taxonomies
        if (!empty($contexts)) {
            wp_set_post_terms($post_id, $contexts, 'wcp_context');
        }

        if (!empty($item_type)) {
            wp_set_post_terms($post_id, $item_type, 'item_type');
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

        return rest_ensure_response(array(
            'success' => true,
            'post_id' => $post_id,
            'edit_url' => get_edit_post_link($post_id, 'raw'),
        ));
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
            'model' => get_option('wcp_ai_model', 'claude-3-5-sonnet-20241022'),
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
     * AI: Accept or dismiss candidates
     * CRITICAL: This is the ONLY way AI content enters the database
     */
    public function ai_decide($request) {
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
}
