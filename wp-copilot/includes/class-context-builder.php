<?php
/**
 * Context Builder
 *
 * Builds hierarchical context for AI prompts by walking up page tree
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_Context_Builder {

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
     * Build hierarchical context for a page
     *
     * @param int $page_id The page ID to build context for
     * @param array $options Context building options
     *   - include_items: bool - Include recent items
     *   - item_limit: int - Max items to include
     *   - use_rag: bool - Use semantic search
     *   - query: string - RAG search query
     *   - rag_limit: int - Max RAG items
     *   - limits: array - Character limits (max_chars_per_item, max_chars_page_summary)
     * @return array Context data structure
     */
    public function build_hierarchical_context($page_id, $options = array()) {
        $defaults = array(
            'include_items' => false,
            'item_limit' => 20,
            'use_rag' => false,
            'query' => '',
            'rag_limit' => 10,
            'limits' => array(
                'max_chars_per_item' => 50000,
                'max_chars_page_summary' => 8000
            )
        );

        $options = wp_parse_args($options, $defaults);

        // Ensure limits is an array with defaults
        if (!isset($options['limits']) || !is_array($options['limits'])) {
            $options['limits'] = $defaults['limits'];
        } else {
            $options['limits'] = array_merge($defaults['limits'], $options['limits']);
        }

        $context = array(
            'pages' => array(),
            'items' => array(),
            'rag_items' => array(),
            'limits' => $options['limits'] // Store limits in context data
        );

        // Walk up page hierarchy
        $context['pages'] = $this->collect_parent_contexts($page_id);

        // Reverse so root is first, current page is last
        $context['pages'] = array_reverse($context['pages']);

        // Get items for current page (if requested)
        if ($options['include_items']) {
            $context['items'] = $this->get_page_items($page_id, $options['item_limit']);
        }

        // Include RAG items (if enabled and query provided)
        if ($options['use_rag'] && !empty($options['query'])) {
            $context['rag_items'] = $this->include_rag_items($options['query'], array(
                'limit' => $options['rag_limit'],
                'exclude_page' => $page_id
            ));
        }

        return $context;
    }

    /**
     * Truncate content to max characters, preserving word boundaries
     *
     * @param string $content Content to truncate
     * @param int $max_chars Maximum characters
     * @return string Truncated content
     */
    private function truncate_content($content, $max_chars) {
        if (strlen($content) <= $max_chars) {
            return $content;
        }

        // Truncate to max_chars - 3 (for ellipsis)
        $truncated = substr($content, 0, $max_chars - 3);

        // Find last space to preserve word boundary
        $last_space = strrpos($truncated, ' ');
        if ($last_space !== false && $last_space > $max_chars * 0.8) {
            $truncated = substr($truncated, 0, $last_space);
        }

        return $truncated . '...';
    }

    /**
     * Build context based on mode selection
     *
     * @param int $page_id The current page ID (for 'page' mode)
     * @param string $context_mode Mode: 'page', 'corpus', or 'select'
     * @param array $options Options including:
     *   - selected_pages: array of page IDs (for 'select' mode)
     *   - query: string for RAG search (for 'corpus' mode)
     *   - include_items: bool - Include items from pages
     *   - item_limit: int - Max items per page
     *   - limits: array - Character limits (max_chars_per_item, max_chars_page_summary)
     * @return array Context data structure
     */
    public function build_context_by_mode($page_id, $context_mode = 'page', $options = array()) {
        $defaults = array(
            'selected_pages' => array(),
            'query' => '',
            'include_items' => true,
            'item_limit' => 20,
            'limits' => array(
                'max_chars_per_item' => 50000,
                'max_chars_page_summary' => 8000
            )
        );

        $options = wp_parse_args($options, $defaults);

        // Ensure limits is an array with defaults
        if (!isset($options['limits']) || !is_array($options['limits'])) {
            $options['limits'] = $defaults['limits'];
        } else {
            $options['limits'] = array_merge($defaults['limits'], $options['limits']);
        }

        $context = array(
            'pages' => array(),
            'items' => array(),
            'rag_items' => array(),
            'memories' => array(),
            'limits' => $options['limits'] // Store limits in context data
        );

        // Fetch relevant memories if RAG is enabled and query provided
        if (get_option('wcp_embeddings_enabled', false) && !empty($options['query'])) {
            $memory_manager = WCP_Memory_Manager::instance();
            $context['memories'] = $memory_manager->get_relevant_memories($options['query'], 5);
        }

        switch ($context_mode) {
            case 'corpus':
                // RAG mode: semantic search across all content
                if (!empty($options['query'])) {
                    $context['rag_items'] = $this->include_rag_items($options['query'], array(
                        'limit' => 20
                    ));
                }
                // Also include current page context if available
                if ($page_id) {
                    $page = get_post($page_id);
                    if ($page && $page->post_type === 'page') {
                        $context['pages'][] = array(
                            'id' => $page->ID,
                            'title' => $page->post_title,
                            'content' => $page->post_content,
                            'level' => 0
                        );
                    }
                }
                break;

            case 'select':
                // User-selected pages mode with hierarchical support
                $selected_pages = isset($options['selected_pages']) ? $options['selected_pages'] : array();

                foreach ($selected_pages as $selection) {
                    // Handle both old format (int) and new format (array with options)
                    if (is_int($selection)) {
                        $page_id_to_process = $selection;
                        $include_children = false;
                        $include_items = true;
                    } else {
                        $page_id_to_process = $selection['page_id'];
                        $include_children = isset($selection['include_children']) ? $selection['include_children'] : false;
                        $include_items = isset($selection['include_items']) ? $selection['include_items'] : true;
                    }

                    // Get parent hierarchy for context
                    $parent_contexts = $this->collect_parent_contexts($page_id_to_process);
                    $context['pages'] = array_merge($context['pages'], $parent_contexts);

                    // If include_children, get all descendants
                    if ($include_children) {
                        $descendants = $this->get_descendant_pages($page_id_to_process);
                        foreach ($descendants as $desc_page) {
                            $context['pages'][] = array(
                                'id' => $desc_page->ID,
                                'title' => $desc_page->post_title,
                                'content' => $desc_page->post_content,
                                'level' => 0
                            );
                        }
                    }

                    // If include_items, get items for this page
                    if ($include_items) {
                        $page_items = $this->get_page_items($page_id_to_process, $options['item_limit']);
                        $context['items'] = array_merge($context['items'], $page_items);
                    }
                }

                // Deduplicate pages by ID
                $context['pages'] = $this->deduplicate_pages($context['pages']);
                break;

            case 'page':
            default:
                // Default: current page + parent hierarchy
                $context['pages'] = $this->collect_parent_contexts($page_id);
                // Reverse so root is first, current page is last
                $context['pages'] = array_reverse($context['pages']);

                // Get items for current page
                if ($options['include_items'] && $page_id) {
                    $context['items'] = $this->get_page_items($page_id, $options['item_limit']);
                }
                break;
        }

        return $context;
    }

    /**
     * Walk up page hierarchy and collect contexts
     *
     * @param int $page_id Starting page ID
     * @param int $max_depth Maximum depth to traverse
     * @return array Array of page context data
     */
    private function collect_parent_contexts($page_id, $max_depth = 10) {
        $pages = array();
        $current_id = $page_id;
        $depth = 0;

        while ($current_id && $depth < $max_depth) {
            $page = get_post($current_id);

            if (!$page || $page->post_type !== 'page') {
                break;
            }

            $pages[] = array(
                'id' => $page->ID,
                'title' => $page->post_title,
                'content' => $page->post_content,
                'level' => $depth
            );

            $current_id = $page->post_parent;
            $depth++;
        }

        return $pages;
    }

    /**
     * Get page heading outline
     *
     * @param int $page_id The page ID
     * @return array Array of heading data
     */
    private function get_page_heading_outline($page_id) {
        // Check cache first to avoid repeated queries
        $cache_key = 'wcp_headings_' . $page_id;
        $cached = wp_cache_get($cache_key, 'wcp_context');
        if ($cached !== false) {
            return $cached;
        }

        try {
            // Query headings that belong to this page with timeout protection
            $headings = get_posts(array(
                'post_type' => 'wcp_heading',
                'post_status' => 'publish',
                'posts_per_page' => 50, // Limit to prevent excessive results
                'meta_query' => array(
                    'relation' => 'AND',
                    array('key' => '_wcp_parent_id', 'value' => $page_id, 'compare' => '='),
                    array('key' => '_wcp_parent_type', 'value' => 'page', 'compare' => '=')
                ),
                'orderby' => 'menu_order',
                'order' => 'ASC',
                'fields' => 'ids', // Only get IDs first for faster query
                'suppress_filters' => true
            ));

            if (empty($headings) || is_wp_error($headings)) {
                wp_cache_set($cache_key, array(), 'wcp_context', 300); // Cache empty result for 5 min
                return array();
            }

            // Now get full post objects only for found IDs
            $heading_data = array();
            foreach ($headings as $heading_id) {
                $heading = get_post($heading_id);
                if ($heading) {
                    $heading_data[] = array(
                        'id' => $heading->ID,
                        'title' => $heading->post_title,
                        'menu_order' => $heading->menu_order
                    );
                }
            }

            // Cache for 5 minutes
            wp_cache_set($cache_key, $heading_data, 'wcp_context', 300);

            return $heading_data;
        } catch (Exception $e) {
            // Silently fail and return empty array
            error_log('WCP: Error getting heading outline for page ' . $page_id . ': ' . $e->getMessage());
            return array();
        }
    }

    /**
     * Format heading outline as markdown
     *
     * @param array $headings Array of heading data
     * @return string Formatted outline
     */
    private function format_heading_outline($headings) {
        if (empty($headings)) {
            return '';
        }

        $outline = "## Page Structure:\n\n";
        foreach ($headings as $heading) {
            $outline .= "- {$heading['title']}\n";
        }
        $outline .= "\n";

        return $outline;
    }

    /**
     * Get recent items for a page
     *
     * @param int $page_id The page ID
     * @param int $limit Maximum items to return
     * @return array Array of item data
     */
    private function get_page_items($page_id, $limit = 20) {
        // Find context term for this page
        $terms = get_terms(array(
            'taxonomy' => 'wcp_context',
            'hide_empty' => false,
            'meta_query' => array(
                array('key' => 'wcp_ref_type', 'value' => 'page'),
                array('key' => 'wcp_ref_id', 'value' => $page_id),
            ),
        ));

        if (empty($terms) || is_wp_error($terms)) {
            return array();
        }

        $context_term = $terms[0];

        // Get term and all descendants
        $term_ids = array($context_term->term_id);
        $children = get_term_children($context_term->term_id, 'wcp_context');
        if (!is_wp_error($children)) {
            $term_ids = array_merge($term_ids, $children);
        }

        // Get posts in this context
        $posts = get_posts(array(
            'post_type' => 'post',
            'posts_per_page' => $limit,
            'tax_query' => array(
                array(
                    'taxonomy' => 'wcp_context',
                    'field' => 'term_id',
                    'terms' => $term_ids,
                ),
            ),
            'orderby' => 'date',
            'order' => 'DESC',
        ));

        $items = array();
        foreach ($posts as $post) {
            $items[] = array(
                'id' => $post->ID,
                'title' => $post->post_title,
                'content' => $post->post_content,
                'date' => $post->post_date,
            );
        }

        return $items;
    }

    /**
     * Include semantically similar items via RAG
     *
     * @param string $query The search query
     * @param array $options RAG options
     * @return array Array of similar items with similarity scores
     */
    private function include_rag_items($query, $options = array()) {
        $defaults = array(
            'limit' => 10,
            'exclude_page' => null
        );

        $options = wp_parse_args($options, $defaults);

        // Check if embeddings are enabled
        if (!get_option('wcp_embeddings_enabled', false)) {
            return array();
        }

        $embeddings_client = WCP_Embeddings_Client::instance();

        if (!$embeddings_client->is_configured()) {
            return array();
        }

        // Perform semantic search
        $similar_posts = $embeddings_client->find_similar_posts(
            $query,
            $options['limit'],
            'post', // Only search ItemPosts
            array() // No excluded IDs for now
        );

        if (is_wp_error($similar_posts)) {
            return array();
        }

        $rag_items = array();
        foreach ($similar_posts as $similar) {
            $post = get_post($similar['post_id']);
            if ($post) {
                $rag_items[] = array(
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'content' => $post->post_content,
                    'similarity' => $similar['similarity'],
                );
            }
        }

        return $rag_items;
    }

    /**
     * Format context data for AI prompt
     *
     * @param array $context_data Context data from build_hierarchical_context() or build_context_by_mode()
     * @param array $limits Optional character limits (max_chars_per_item, max_chars_page_summary)
     * @return string Formatted context string
     */
    public function format_for_prompt($context_data, $limits = array()) {
        // Set default limits
        $defaults = array(
            'max_chars_per_item' => 50000,
            'max_chars_page_summary' => 8000
        );

        // Check if limits are stored in context data (preferred method)
        if (isset($context_data['limits']) && is_array($context_data['limits'])) {
            $limits = array_merge($defaults, $context_data['limits'], $limits);
        } else {
            $limits = array_merge($defaults, $limits);
        }

        $prompt = '';

        // Add page hierarchy
        if (!empty($context_data['pages'])) {
            $prompt .= "## Page Context:\n\n";

            foreach ($context_data['pages'] as $page) {
                $level = isset($page['level']) ? $page['level'] : 0;
                $indent = str_repeat('  ', $level);

                $prompt .= "{$indent}Page: {$page['title']}\n";

                if (!empty($page['content'])) {
                    $content = wp_strip_all_tags($page['content']);
                    $original_length = strlen($content);
                    $truncated = false;
                    if ($original_length > $limits['max_chars_page_summary']) {
                        $content = $this->truncate_content($content, $limits['max_chars_page_summary']);
                        $truncated = true;
                    }
                    $prompt .= "{$indent}Content: {$content}";
                    if ($truncated) {
                        $prompt .= " [truncated from {$original_length} chars]";
                    }
                    $prompt .= "\n";
                }

                // Add heading outline for this page if it's depth 0 (current page)
                if ($level === 0 && isset($page['id']) && is_numeric($page['id'])) {
                    try {
                        $headings = $this->get_page_heading_outline($page['id']);
                        if (!empty($headings) && is_array($headings)) {
                            $outline = $this->format_heading_outline($headings);
                            if (!empty($outline)) {
                                $prompt .= "\n" . $outline;
                            }
                        }
                    } catch (Exception $e) {
                        // Silently skip headings if there's an error
                        error_log('WCP: Error formatting heading outline: ' . $e->getMessage());
                    }
                }

                $prompt .= "\n";
            }
        }

        // Add recent items
        if (!empty($context_data['items'])) {
            $prompt .= "## Recent Items:\n\n";

            foreach ($context_data['items'] as $item) {
                $content = wp_strip_all_tags($item['content']);
                $original_length = strlen($content);
                $truncated = false;

                // Apply character limit to items
                if ($original_length > $limits['max_chars_per_item']) {
                    $content = $this->truncate_content($content, $limits['max_chars_per_item']);
                    $truncated = true;
                }

                $date_prefix = !empty($item['date']) ? '[' . substr($item['date'], 0, 10) . '] ' : '';
                $prompt .= "- {$date_prefix}{$item['title']}: {$content}";
                if ($truncated) {
                    $prompt .= " [truncated from {$original_length} chars]";
                }
                $prompt .= "\n";
            }

            $prompt .= "\n";
        }

        // Add semantically similar items (RAG)
        if (!empty($context_data['rag_items'])) {
            $prompt .= "## Semantically Related Items:\n\n";

            foreach ($context_data['rag_items'] as $item) {
                $similarity_pct = round($item['similarity'] * 100);
                $content = wp_strip_all_tags($item['content']);
                $original_length = strlen($content);
                $truncated = false;

                // Apply character limit to RAG items
                if ($original_length > $limits['max_chars_per_item']) {
                    $content = $this->truncate_content($content, $limits['max_chars_per_item']);
                    $truncated = true;
                }

                $prompt .= "- [{$similarity_pct}% match] {$item['title']}: {$content}";
                if ($truncated) {
                    $prompt .= " [truncated from {$original_length} chars]";
                }
                $prompt .= "\n";
            }

            $prompt .= "\n";
        }

        // Add relevant memories if available
        if (!empty($context_data['memories'])) {
            $prompt .= "## Relevant Memories:\n\n";

            foreach ($context_data['memories'] as $memory) {
                $type = get_post_meta($memory->ID, '_wcp_memory_type', true);
                $content_preview = wp_trim_words($memory->post_content, 30, '...');
                $prompt .= "- **{$memory->post_title}** ({$type})\n";
                $prompt .= "  {$content_preview}\n\n";
            }

            $prompt .= "\n";
        }

        return $prompt;
    }

    /**
     * Get all descendant pages recursively
     *
     * @param int $page_id Parent page ID
     * @param array $descendants Accumulator for recursive calls
     * @return array Array of descendant page objects
     */
    private function get_descendant_pages($page_id, &$descendants = array()) {
        $children = get_pages(array(
            'child_of' => $page_id,
            'parent' => $page_id,
            'post_status' => 'publish'
        ));

        foreach ($children as $child) {
            $descendants[] = $child;
            $this->get_descendant_pages($child->ID, $descendants);
        }

        return $descendants;
    }

    /**
     * Deduplicate pages by ID
     *
     * @param array $pages Array of page data
     * @return array Deduplicated array
     */
    private function deduplicate_pages($pages) {
        $seen = array();
        $unique = array();

        foreach ($pages as $page) {
            if (!in_array($page['id'], $seen)) {
                $seen[] = $page['id'];
                $unique[] = $page;
            }
        }

        return $unique;
    }
}
