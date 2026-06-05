<?php
/**
 * Embeddings Client - Handles OpenAI embeddings generation for RAG
 *
 * Uses OpenAI's text-embedding-3-small model to create vector embeddings
 * of posts for semantic search and retrieval-augmented generation.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_Embeddings_Client {

    private static $instance = null;
    private $api_key;
    private $api_url = 'https://api.openai.com/v1/embeddings';
    private $model = 'text-embedding-3-small';
    private $dimensions = 1536;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->api_key = get_option('wcp_openai_api_key', '');
    }

    /**
     * Check if embeddings are configured
     */
    public function is_configured() {
        return !empty($this->api_key);
    }

    /**
     * Generate embedding for a single text
     */
    public function generate_embedding($text) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', 'OpenAI API key not configured');
        }

        if (empty($text)) {
            return new WP_Error('empty_text', 'Cannot generate embedding for empty text');
        }

        $response = wp_remote_post($this->api_url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ),
            'body' => wp_json_encode(array(
                'model' => $this->model,
                'input' => $text,
                'dimensions' => $this->dimensions,
            )),
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

        // Extract embedding vector
        if (isset($data['data'][0]['embedding'])) {
            return $data['data'][0]['embedding'];
        }

        return new WP_Error('parse_error', 'Could not parse embedding response');
    }

    /**
     * Generate embeddings for multiple texts (batch)
     * Note: OpenAI allows up to 2048 texts per request, but we'll batch smaller for reliability
     */
    public function generate_embeddings_batch($texts, $batch_size = 100) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', 'OpenAI API key not configured');
        }

        $all_embeddings = array();
        $batches = array_chunk($texts, $batch_size);

        foreach ($batches as $batch) {
            $response = wp_remote_post($this->api_url, array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->api_key,
                ),
                'body' => wp_json_encode(array(
                    'model' => $this->model,
                    'input' => $batch,
                    'dimensions' => $this->dimensions,
                )),
                'timeout' => 60,
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

            // Extract embeddings
            if (isset($data['data']) && is_array($data['data'])) {
                foreach ($data['data'] as $item) {
                    if (isset($item['embedding'])) {
                        $all_embeddings[] = $item['embedding'];
                    }
                }
            }
        }

        return $all_embeddings;
    }

    /**
     * Generate embedding for a WordPress post
     * Combines title and content intelligently
     */
    public function generate_post_embedding($post_id) {
        $post = get_post($post_id);

        if (!$post) {
            return new WP_Error('invalid_post', 'Post not found');
        }

        // Build text for embedding
        $text_parts = array();

        // Add title with emphasis
        if (!empty($post->post_title)) {
            $text_parts[] = "Title: " . $post->post_title;
        }

        // Add content
        if (!empty($post->post_content)) {
            // Strip HTML tags and shortcodes
            $content = wp_strip_all_tags(strip_shortcodes($post->post_content));
            $text_parts[] = "Content: " . $content;
        }

        // Add contexts (pages/headings) for semantic richness
        $contexts = wp_get_post_terms($post_id, 'wcp_context', array('fields' => 'names'));
        if (!empty($contexts) && !is_wp_error($contexts)) {
            $text_parts[] = "Context: " . implode(', ', $contexts);
        }

        // Add item type
        $item_types = wp_get_post_terms($post_id, 'item_type', array('fields' => 'names'));
        if (!empty($item_types) && !is_wp_error($item_types)) {
            $text_parts[] = "Type: " . implode(', ', $item_types);
        }

        $embedding_text = implode("\n\n", $text_parts);

        // Generate embedding
        $embedding = $this->generate_embedding($embedding_text);

        if (is_wp_error($embedding)) {
            return $embedding;
        }

        return array(
            'text' => $embedding_text,
            'vector' => $embedding,
        );
    }

    /**
     * Save embedding to database
     */
    public function save_embedding($post_id, $embedding_text, $embedding_vector) {
        global $wpdb;

        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('invalid_post', 'Post not found');
        }

        $table_name = $wpdb->prefix . 'wcp_embeddings';

        // Store vector as JSON
        $vector_json = wp_json_encode($embedding_vector);

        $data = array(
            'post_id' => $post_id,
            'post_type' => $post->post_type,
            'embedding_text' => $embedding_text,
            'embedding_vector' => $vector_json,
            'model' => $this->model,
            'dimensions' => $this->dimensions,
        );

        // Check if embedding exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE post_id = %d",
            $post_id
        ));

        if ($existing) {
            // Update
            $wpdb->update(
                $table_name,
                $data,
                array('post_id' => $post_id),
                array('%d', '%s', '%s', '%s', '%s', '%d'),
                array('%d')
            );
        } else {
            // Insert
            $wpdb->insert(
                $table_name,
                $data,
                array('%d', '%s', '%s', '%s', '%s', '%d')
            );
        }

        return true;
    }

    /**
     * Get embedding for a post
     */
    public function get_embedding($post_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wcp_embeddings';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE post_id = %d",
            $post_id
        ));

        if (!$row) {
            return null;
        }

        return array(
            'id' => $row->id,
            'post_id' => $row->post_id,
            'post_type' => $row->post_type,
            'text' => $row->embedding_text,
            'vector' => json_decode($row->embedding_vector, true),
            'model' => $row->model,
            'dimensions' => $row->dimensions,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        );
    }

    /**
     * Delete embedding for a post
     */
    public function delete_embedding($post_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wcp_embeddings';

        $wpdb->delete(
            $table_name,
            array('post_id' => $post_id),
            array('%d')
        );

        return true;
    }

    /**
     * Get all embeddings (for batch similarity search)
     *
     * @param string|null $post_type Optional post type filter
     * @param string $fields 'vectors_only' omits embedding_text to reduce memory during similarity search;
     *                       'full' returns all columns (default, preserves backward compatibility)
     */
    public function get_all_embeddings($post_type = null, $fields = 'full') {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wcp_embeddings';

        // Omit the heavy embedding_text longtext column when only vectors are needed
        $select = ($fields === 'vectors_only')
            ? 'post_id, post_type, embedding_vector'
            : '*';

        if ($post_type) {
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT $select FROM $table_name WHERE post_type = %s ORDER BY updated_at DESC",
                $post_type
            ));
        } else {
            $results = $wpdb->get_results(
                "SELECT $select FROM $table_name ORDER BY updated_at DESC"
            );
        }

        if (!$results) {
            return array();
        }

        $embeddings = array();
        foreach ($results as $row) {
            $embedding = array(
                'post_id' => $row->post_id,
                'post_type' => $row->post_type,
                'vector' => json_decode($row->embedding_vector, true),
            );
            if ($fields !== 'vectors_only') {
                $embedding['id'] = $row->id;
                $embedding['text'] = $row->embedding_text;
                $embedding['model'] = $row->model;
                $embedding['dimensions'] = $row->dimensions;
                $embedding['created_at'] = $row->created_at;
                $embedding['updated_at'] = $row->updated_at;
            }
            $embeddings[] = $embedding;
        }

        return $embeddings;
    }

    /**
     * Calculate cosine similarity between two vectors
     */
    public function cosine_similarity($vector_a, $vector_b) {
        if (count($vector_a) !== count($vector_b)) {
            return 0;
        }

        $dot_product = 0;
        $magnitude_a = 0;
        $magnitude_b = 0;

        for ($i = 0; $i < count($vector_a); $i++) {
            $dot_product += $vector_a[$i] * $vector_b[$i];
            $magnitude_a += $vector_a[$i] * $vector_a[$i];
            $magnitude_b += $vector_b[$i] * $vector_b[$i];
        }

        $magnitude_a = sqrt($magnitude_a);
        $magnitude_b = sqrt($magnitude_b);

        if ($magnitude_a == 0 || $magnitude_b == 0) {
            return 0;
        }

        return $dot_product / ($magnitude_a * $magnitude_b);
    }

    /**
     * Fetch a page of embeddings (vectors only) for chunked similarity search.
     * Using LIMIT/OFFSET keeps peak memory proportional to chunk size, not corpus size.
     */
    private function get_embeddings_chunk( $post_type, $chunk_size, $offset ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcp_embeddings';

        if ( $post_type ) {
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT post_id, post_type, embedding_vector
                 FROM $table
                 WHERE post_type = %s
                 ORDER BY id ASC
                 LIMIT %d OFFSET %d",
                $post_type, $chunk_size, $offset
            ) );
        } else {
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT post_id, post_type, embedding_vector
                 FROM $table
                 ORDER BY id ASC
                 LIMIT %d OFFSET %d",
                $chunk_size, $offset
            ) );
        }

        if ( ! $results ) {
            return array();
        }

        $chunk = array();
        foreach ( $results as $row ) {
            $chunk[] = array(
                'post_id'    => (int) $row->post_id,
                'post_type'  => $row->post_type,
                'vector'     => json_decode( $row->embedding_vector, true ),
            );
        }
        return $chunk;
    }

    /**
     * Find similar posts using cosine similarity.
     * Processes embeddings in chunks of 200 to keep peak memory bounded regardless
     * of corpus size — avoids loading all vectors into PHP memory simultaneously.
     */
    public function find_similar_posts( $query_text, $limit = 10, $post_type = null, $exclude_post_ids = array() ) {
        $query_embedding = $this->generate_embedding( $query_text );
        if ( is_wp_error( $query_embedding ) ) {
            return $query_embedding;
        }

        $chunk_size  = 200;
        $prune_at    = $limit * 5; // keep candidate list small between chunks
        $offset      = 0;
        $candidates  = array();

        while ( true ) {
            $chunk = $this->get_embeddings_chunk( $post_type, $chunk_size, $offset );
            if ( empty( $chunk ) ) {
                break;
            }

            foreach ( $chunk as $embedding ) {
                if ( in_array( $embedding['post_id'], $exclude_post_ids ) ) {
                    continue;
                }
                $candidates[] = array(
                    'post_id'    => $embedding['post_id'],
                    'similarity' => $this->cosine_similarity( $query_embedding, $embedding['vector'] ),
                );
            }

            unset( $chunk ); // free chunk memory before next iteration

            // Prune early so the candidate list never grows unboundedly
            if ( count( $candidates ) > $prune_at ) {
                usort( $candidates, function( $a, $b ) {
                    return $b['similarity'] <=> $a['similarity'];
                } );
                $candidates = array_slice( $candidates, 0, $prune_at );
            }

            $offset += $chunk_size;
        }

        if ( empty( $candidates ) ) {
            return array();
        }

        usort( $candidates, function( $a, $b ) {
            return $b['similarity'] <=> $a['similarity'];
        } );

        // Verify existence only for the final top-N (avoids N+1 on the full corpus)
        $results = array();
        foreach ( $candidates as $candidate ) {
            if ( count( $results ) >= $limit ) {
                break;
            }
            if ( get_post( $candidate['post_id'] ) ) {
                $results[] = array(
                    'post_id'    => $candidate['post_id'],
                    'similarity' => $candidate['similarity'],
                    'text'       => '',
                );
            }
        }

        return $results;
    }

    /**
     * Test connection to OpenAI
     */
    public function test_connection() {
        $result = $this->generate_embedding('test');

        if (is_wp_error($result)) {
            return $result;
        }

        return array(
            'success' => true,
            'message' => 'OpenAI Embeddings API connection successful',
            'model' => $this->model,
            'dimensions' => $this->dimensions,
        );
    }
}
