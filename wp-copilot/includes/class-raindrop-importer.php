<?php
/**
 * Raindrop.io Import Pipeline
 *
 * Pulls bookmarks from Raindrop.io via their REST API and creates WP posts.
 * Runs on WP-Cron. Each Raindrop collection maps to a child page under a
 * "Bookmarks" parent page. Tags become WP post tags. No AI summarisation
 * at import time — store faithful source material, synthesise at query time.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_Raindrop_Importer {

    private static $instance = null;
    private $api_base = 'https://api.raindrop.io/rest/v1';

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /** Static wrapper for WP-Cron hook (cron callbacks must be globally callable). */
    public static function run_static() {
        self::instance()->run();
    }

    /**
     * Entry point — called by WP-Cron hook and "Import Now" button.
     *
     * @param int $limit Max items to import this run (0 = unlimited)
     * @return array Stats array with counts of imported/skipped/errors
     */
    public function run($limit = 0) {
        // Remove PHP time limit — large imports can take several minutes
        set_time_limit(0);

        $api_key = get_option('wcp_raindrop_api_key', '');
        if (empty($api_key)) {
            return new WP_Error('no_api_key', 'Raindrop API key not configured');
        }

        $last_import = get_option('wcp_raindrop_last_import', 0);
        $stats = array('imported' => 0, 'skipped' => 0, 'errors' => 0);
        $limit = intval($limit);

        // Ensure the Bookmarks parent page exists
        $bookmarks_page_id = $this->ensure_bookmarks_page();
        if (is_wp_error($bookmarks_page_id)) {
            return $bookmarks_page_id;
        }

        // Fetch all root-level collections
        $collections_response = $this->fetch_api('/collections', $api_key);
        if (is_wp_error($collections_response)) {
            return $collections_response;
        }

        $collections = $collections_response['items'] ?? array();
        error_log('WCP Raindrop: fetched ' . count($collections) . ' collections. Response keys: ' . implode(', ', array_keys($collections_response)));

        $selected_ids = array_map('intval', get_option('wcp_raindrop_selected_collections', array()));
        error_log('WCP Raindrop: selected_ids=' . json_encode($selected_ids) . ' last_import=' . $last_import);

        foreach ($collections as $collection) {
            error_log('WCP Raindrop: collection id=' . $collection['_id'] . ' title=' . $collection['title']);
            // Skip collections not in the allowlist (empty allowlist = import all)
            if (!empty($selected_ids) && !in_array((int) $collection['_id'], $selected_ids, true)) {
                error_log('WCP Raindrop: skipping collection ' . $collection['title'] . ' (not in allowlist)');
                continue;
            }
            $collection_page_id = $this->ensure_collection_page($collection, $bookmarks_page_id);
            if (is_wp_error($collection_page_id)) {
                $stats['errors']++;
                continue;
            }

            // Fetch raindrops for this collection, newest first, since last import
            $page = 0;
            $done = false;

            while (!$done) {
                $endpoint = '/raindrops/' . intval($collection['_id']) . '?sort=-created&perpage=50&page=' . $page;
                $response = $this->fetch_api($endpoint, $api_key);

                if (is_wp_error($response)) {
                    $stats['errors']++;
                    break;
                }

                $raindrops = $response['items'] ?? array();
                error_log('WCP Raindrop: collection ' . $collection['_id'] . ' page ' . $page . ' returned ' . count($raindrops) . ' items');
                if (empty($raindrops)) {
                    break;
                }

                foreach ($raindrops as $raindrop) {
                    // Stop paginating once we reach items older than last import
                    $created = strtotime($raindrop['created'] ?? '');
                    if ($last_import > 0 && $created <= $last_import) {
                        error_log('WCP Raindrop: stopping — item created=' . $created . ' <= last_import=' . $last_import);
                        $done = true;
                        break;
                    }

                    $result = $this->import_raindrop($raindrop, $collection_page_id);
                    if ($result === false) {
                        $stats['skipped']++;
                    } elseif (is_wp_error($result)) {
                        $stats['errors']++;
                    } else {
                        $stats['imported']++;
                    }

                    if ($limit > 0 && $stats['imported'] >= $limit) {
                        $done = true;
                        break;
                    }
                }

                // Stop if we got fewer than a full page
                if (count($raindrops) < 50) {
                    break;
                }

                $page++;
            }
        }

        // Only advance the cursor on unlimited runs — limited runs are previews and
        // should not move the watermark, so the full backlog is still imported later.
        if ($limit === 0) {
            update_option('wcp_raindrop_last_import', time());
        }

        return $stats;
    }

    /**
     * Find or create the "Bookmarks" parent page.
     * If a page titled "Bookmarks" already exists (manually created), it is adopted
     * by setting the _wcp_is_bookmarks_page meta so future runs find it directly.
     */
    public function ensure_bookmarks_page() {
        // Check for already-flagged page first
        $pages = get_posts(array(
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_key'       => '_wcp_is_bookmarks_page',
            'meta_value'     => '1',
            'fields'         => 'ids',
        ));

        if (!empty($pages)) {
            return $pages[0];
        }

        // Fall back to finding an existing page titled "Bookmarks" (manually created)
        $existing = get_posts(array(
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'title'          => 'Bookmarks',
            'fields'         => 'ids',
        ));

        if (!empty($existing)) {
            update_post_meta($existing[0], '_wcp_is_bookmarks_page', '1');
            return $existing[0];
        }

        // Create it if it doesn't exist at all
        $page_id = wp_insert_post(array(
            'post_type'    => 'page',
            'post_title'   => 'Bookmarks',
            'post_content' => 'Imported bookmarks from Raindrop.io, organised by collection.',
            'post_status'  => 'publish',
            'post_author'  => get_current_user_id() ?: 1,
        ));

        if (is_wp_error($page_id)) {
            return $page_id;
        }

        update_post_meta($page_id, '_wcp_is_bookmarks_page', '1');

        return $page_id;
    }

    /**
     * Find or create a child page for a Raindrop collection.
     *
     * @param array $collection Raindrop collection object
     * @param int   $parent_page_id Bookmarks parent page ID
     * @return int|WP_Error Page ID
     */
    private function ensure_collection_page($collection, $parent_page_id) {
        $collection_id = $collection['_id'];

        $pages = get_posts(array(
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_key'       => '_wcp_raindrop_collection_id',
            'meta_value'     => $collection_id,
            'fields'         => 'ids',
        ));

        if (!empty($pages)) {
            return $pages[0];
        }

        $page_id = wp_insert_post(array(
            'post_type'    => 'page',
            'post_title'   => sanitize_text_field($collection['title']),
            'post_content' => '',
            'post_status'  => 'publish',
            'post_author'  => get_current_user_id() ?: 1,
            'post_parent'  => $parent_page_id,
        ));

        if (is_wp_error($page_id)) {
            return $page_id;
        }

        update_post_meta($page_id, '_wcp_raindrop_collection_id', $collection_id);

        // Taxonomy sync fires automatically via save_post hook in class-taxonomy-sync.php

        return $page_id;
    }

    /**
     * Import a single Raindrop as a WP post.
     *
     * @param array $raindrop Raindrop API item object
     * @param int   $page_id  Collection page ID (for wcp_context)
     * @return int|false|WP_Error Post ID, false if skipped (duplicate), or WP_Error
     */
    private function import_raindrop($raindrop, $page_id) {
        $raindrop_id = $raindrop['_id'];

        // Deduplicate: skip if already imported
        $existing = get_posts(array(
            'post_type'      => 'post',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'meta_key'       => '_wcp_raindrop_id',
            'meta_value'     => $raindrop_id,
            'fields'         => 'ids',
        ));

        if (!empty($existing)) {
            return false;
        }

        // Build post content: note first (user's own words), then excerpt
        $content_parts = array();
        if (!empty($raindrop['note'])) {
            $content_parts[] = wp_kses_post($raindrop['note']);
        }
        if (!empty($raindrop['excerpt'])) {
            $content_parts[] = wp_kses_post($raindrop['excerpt']);
        }
        if (!empty($raindrop['link'])) {
            $content_parts[] = esc_url($raindrop['link']);
        }
        $post_content = implode("\n\n", $content_parts);

        $post_id = wp_insert_post(array(
            'post_type'    => 'post',
            'post_title'   => sanitize_text_field($raindrop['title'] ?? $raindrop['link'] ?? 'Untitled'),
            'post_content' => $post_content,
            'post_status'  => 'publish',
            'post_author'  => get_current_user_id() ?: 1,
            'post_date'    => get_date_from_gmt(date('Y-m-d H:i:s', strtotime($raindrop['created'] ?? 'now'))),
        ));

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // Store Raindrop metadata
        update_post_meta($post_id, '_wcp_raindrop_id', $raindrop_id);
        update_post_meta($post_id, '_wcp_source_url', esc_url_raw($raindrop['link'] ?? ''));

        // Assign item_type = info (default for imported bookmarks)
        wp_set_post_terms($post_id, array('info'), 'item_type');

        // Assign wcp_context term matching the collection page
        $context_terms = get_terms(array(
            'taxonomy'   => 'wcp_context',
            'hide_empty' => false,
            'meta_query' => array(
                array('key' => 'wcp_ref_type', 'value' => 'page'),
                array('key' => 'wcp_ref_id',   'value' => $page_id),
            ),
        ));

        if (!empty($context_terms) && !is_wp_error($context_terms)) {
            wp_set_post_terms($post_id, array($context_terms[0]->term_id), 'wcp_context');
        }

        // Assign tags from Raindrop
        if (!empty($raindrop['tags']) && is_array($raindrop['tags'])) {
            wp_set_post_terms($post_id, array_map('sanitize_text_field', $raindrop['tags']), 'post_tag');
        }

        return $post_id;
    }

    /**
     * Make an authenticated GET request to the Raindrop API.
     *
     * @param string $endpoint Path relative to API base (e.g. '/collections')
     * @param string $api_key  Bearer token
     * @return array|WP_Error Decoded JSON body or error
     */
    private function fetch_api($endpoint, $api_key) {
        $response = wp_remote_get($this->api_base . $endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $message = $body['errorMessage'] ?? 'Raindrop API error (HTTP ' . $code . ')';
            return new WP_Error('raindrop_api_error', $message, array('status' => $code));
        }

        return $body;
    }
}
