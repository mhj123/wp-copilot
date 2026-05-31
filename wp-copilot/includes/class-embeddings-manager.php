<?php
/**
 * Embeddings Manager - Handles automatic embedding generation
 *
 * Manages when and how embeddings are generated for posts.
 * Hooks into WordPress post save events.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_Embeddings_Manager {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Hook into post save events
        add_action('save_post', array($this, 'maybe_generate_embedding'), 20, 3);
        add_action('delete_post', array($this, 'delete_embedding'), 10, 1);

        // Add admin notices for embedding status
        add_action('admin_notices', array($this, 'embedding_notices'));
    }

    /**
     * Maybe generate embedding when post is saved
     */
    public function maybe_generate_embedding($post_id, $post, $update) {
        // Skip autosaves and revisions
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        // Only process posts and pages
        if (!in_array($post->post_type, array('post', 'page', 'wcp_heading'))) {
            return;
        }

        // Skip if post is not published
        if ($post->post_status !== 'publish') {
            return;
        }

        // Check if embeddings are enabled
        if (!get_option('wcp_embeddings_enabled', false)) {
            return;
        }

        // Check if we should skip this update (to avoid rate limits on rapid edits)
        $last_embedded = get_post_meta($post_id, '_wcp_last_embedded', true);
        if ($last_embedded && (time() - intval($last_embedded)) < 60) {
            // Don't re-embed within 60 seconds
            return;
        }

        // Generate embedding in background (non-blocking)
        $this->generate_embedding_async($post_id);
    }

    /**
     * Generate embedding asynchronously (or immediately if async not available)
     */
    private function generate_embedding_async($post_id) {
        // For now, generate immediately
        // TODO: Could use wp_schedule_single_event for true async
        $this->generate_embedding($post_id);
    }

    /**
     * Generate and save embedding for a post
     */
    public function generate_embedding($post_id) {
        $embeddings_client = WCP_Embeddings_Client::instance();

        if (!$embeddings_client->is_configured()) {
            return new WP_Error('not_configured', 'OpenAI API key not configured');
        }

        // Generate embedding
        $result = $embeddings_client->generate_post_embedding($post_id);

        if (is_wp_error($result)) {
            // Log error
            update_post_meta($post_id, '_wcp_embedding_error', $result->get_error_message());
            return $result;
        }

        // Save embedding
        $saved = $embeddings_client->save_embedding($post_id, $result['text'], $result['vector']);

        if ($saved) {
            // Update last embedded timestamp
            update_post_meta($post_id, '_wcp_last_embedded', time());
            delete_post_meta($post_id, '_wcp_embedding_error');

            return true;
        }

        return new WP_Error('save_failed', 'Failed to save embedding');
    }

    /**
     * Delete embedding when post is deleted
     */
    public function delete_embedding($post_id) {
        $embeddings_client = WCP_Embeddings_Client::instance();
        $embeddings_client->delete_embedding($post_id);

        delete_post_meta($post_id, '_wcp_last_embedded');
        delete_post_meta($post_id, '_wcp_embedding_error');
    }

    /**
     * Batch process existing posts to generate embeddings
     */
    public function batch_generate_embeddings($post_type = 'post', $limit = 50, $offset = 0) {
        $args = array(
            'post_type' => $post_type,
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'offset' => $offset,
            'orderby' => 'date',
            'order' => 'DESC',
        );

        $posts = get_posts($args);

        $results = array(
            'total' => count($posts),
            'success' => 0,
            'errors' => 0,
            'skipped' => 0,
        );

        $embeddings_client = WCP_Embeddings_Client::instance();

        foreach ($posts as $post) {
            // Check if embedding already exists and is recent
            $existing = $embeddings_client->get_embedding($post->ID);
            if ($existing) {
                // Check if post was modified after last embedding
                $post_modified = strtotime($post->post_modified);
                $embedding_updated = strtotime($existing['updated_at']);

                if ($post_modified <= $embedding_updated) {
                    $results['skipped']++;
                    continue;
                }
            }

            // Generate embedding
            $result = $this->generate_embedding($post->ID);

            if (is_wp_error($result)) {
                $results['errors']++;
            } else {
                $results['success']++;
            }

            // Small delay to avoid rate limits
            usleep(100000); // 100ms delay
        }

        return $results;
    }

    /**
     * Get embedding statistics
     */
    public function get_stats() {
        global $wpdb;

        $embeddings_table = $wpdb->prefix . 'wcp_embeddings';

        $total_embeddings = $wpdb->get_var("SELECT COUNT(*) FROM $embeddings_table");

        $by_post_type = $wpdb->get_results(
            "SELECT post_type, COUNT(*) as count FROM $embeddings_table GROUP BY post_type",
            ARRAY_A
        );

        $total_posts = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post', 'page', 'wcp_heading')"
        );

        $posts_without_embeddings = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_type
            FROM {$wpdb->posts} p
            LEFT JOIN $embeddings_table e ON p.ID = e.post_id
            WHERE p.post_status = 'publish'
            AND p.post_type IN ('post', 'page', 'wcp_heading')
            AND e.id IS NULL
            LIMIT 100",
            ARRAY_A
        );

        return array(
            'total_embeddings' => intval($total_embeddings),
            'total_posts' => intval($total_posts),
            'coverage_percentage' => $total_posts > 0 ? round(($total_embeddings / $total_posts) * 100, 1) : 0,
            'by_post_type' => $by_post_type,
            'posts_without_embeddings' => $posts_without_embeddings,
        );
    }

    /**
     * Admin notices for embedding status
     */
    public function embedding_notices() {
        // Only show on relevant admin pages
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, array('edit-post', 'post', 'edit-page', 'page'))) {
            return;
        }

        // Check if embeddings are enabled but not configured
        if (get_option('wcp_embeddings_enabled', false) && !WCP_Embeddings_Client::instance()->is_configured()) {
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>Work Copilot:</strong> Embeddings are enabled but OpenAI API key is not configured. ';
            echo '<a href="' . admin_url('admin.php?page=wcp-settings') . '">Configure now</a>';
            echo '</p></div>';
        }
    }
}
