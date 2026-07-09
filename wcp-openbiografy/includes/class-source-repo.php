<?php
/**
 * Source repository. A source is one URL or one uploaded document. Its
 * post_content holds the fetched plain-text snapshot; its pipeline state
 * lives in _wcpo_fetch_status (new → fetched → extracted, with *_failed
 * branches). Sources are not review objects — facts are — so they stay in
 * the normal publish status.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPO_Source_Repo {

    const FETCH_STATUSES = array('new', 'fetched', 'fetch_failed', 'extracted', 'extract_failed', 'skipped');
    const CITE_FIELDS    = array('cite_title', 'cite_author', 'cite_date', 'cite_publisher');

    /** Create a URL source; duplicate URLs for the same person are skipped. */
    public static function create_from_url($person_id, $url) {
        $url = esc_url_raw(trim($url));
        if (!wp_http_validate_url($url)) {
            return new WP_Error('bad_url', sprintf(__('Not a valid URL: %s', 'wcp-openbiografy'), $url));
        }
        if (self::find_by_url($person_id, $url)) {
            return new WP_Error('duplicate', sprintf(__('Already added: %s', 'wcp-openbiografy'), $url));
        }

        $host = wp_parse_url($url, PHP_URL_HOST);
        $post_id = wp_insert_post(array(
            'post_type'   => 'wcpo_source',
            'post_status' => 'publish',
            'post_title'  => $host . wp_parse_url($url, PHP_URL_PATH),
        ), true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        update_post_meta($post_id, '_wcpo_person_id', (int) $person_id);
        update_post_meta($post_id, '_wcpo_source_type', 'url');
        update_post_meta($post_id, '_wcpo_url', $url);
        update_post_meta($post_id, '_wcpo_fetch_status', 'new');
        return $post_id;
    }

    /** Create a document source from a media-library attachment. */
    public static function create_from_attachment($person_id, $attachment_id) {
        $attachment = get_post($attachment_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            return new WP_Error('bad_attachment', __('Attachment not found.', 'wcp-openbiografy'));
        }
        $existing = get_posts(array(
            'post_type'      => 'wcpo_source',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array('key' => '_wcpo_person_id', 'value' => (int) $person_id),
                array('key' => '_wcpo_attachment_id', 'value' => (int) $attachment_id),
            ),
        ));
        if ($existing) {
            return new WP_Error('duplicate', __('This document has already been added.', 'wcp-openbiografy'));
        }

        $post_id = wp_insert_post(array(
            'post_type'   => 'wcpo_source',
            'post_status' => 'publish',
            'post_title'  => $attachment->post_title ?: basename((string) get_attached_file($attachment_id)),
        ), true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        update_post_meta($post_id, '_wcpo_person_id', (int) $person_id);
        update_post_meta($post_id, '_wcpo_source_type', 'document');
        update_post_meta($post_id, '_wcpo_attachment_id', (int) $attachment_id);
        update_post_meta($post_id, '_wcpo_fetch_status', 'new');
        return $post_id;
    }

    public static function find_by_url($person_id, $url) {
        $posts = get_posts(array(
            'post_type'      => 'wcpo_source',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array('key' => '_wcpo_person_id', 'value' => (int) $person_id),
                array('key' => '_wcpo_url', 'value' => $url),
            ),
        ));
        return $posts ? (int) $posts[0] : 0;
    }

    public static function meta($source_id) {
        $post = get_post($source_id);
        if (!$post || $post->post_type !== 'wcpo_source') {
            return null;
        }
        $attachment_id = (int) get_post_meta($source_id, '_wcpo_attachment_id', true);
        $meta = array(
            'id'              => (int) $source_id,
            'title'           => $post->post_title,
            'person_id'       => (int) get_post_meta($source_id, '_wcpo_person_id', true),
            'source_type'     => get_post_meta($source_id, '_wcpo_source_type', true),
            'url'             => get_post_meta($source_id, '_wcpo_url', true),
            'attachment_id'   => $attachment_id,
            'attachment_url'  => $attachment_id ? wp_get_attachment_url($attachment_id) : '',
            'mime'            => $attachment_id ? get_post_mime_type($attachment_id) : '',
            'fetch_status'    => get_post_meta($source_id, '_wcpo_fetch_status', true),
            'fetch_error'     => get_post_meta($source_id, '_wcpo_fetch_error', true),
            'http_status'     => get_post_meta($source_id, '_wcpo_http_status', true),
            'fetched_at'      => get_post_meta($source_id, '_wcpo_fetched_at', true),
            'extracted_at'    => get_post_meta($source_id, '_wcpo_extracted_at', true),
            'facts_extracted' => (int) get_post_meta($source_id, '_wcpo_facts_extracted_count', true),
            'doc_kind'        => get_post_meta($source_id, '_wcpo_doc_kind', true),
            'source_tier'     => get_post_meta($source_id, '_wcpo_source_tier', true),
            'tier_confidence' => get_post_meta($source_id, '_wcpo_tier_confidence', true),
            'snapshot_chars'  => strlen($post->post_content),
            'ai_action_id'    => get_post_meta($source_id, '_wcpo_ai_action_id', true),
        );
        foreach (self::CITE_FIELDS as $field) {
            $meta[$field] = get_post_meta($source_id, '_wcpo_' . $field, true);
        }
        return $meta;
    }

    /** Short human citation line for footnotes / prompts. */
    public static function citation_line($source_id) {
        $s = self::meta($source_id);
        if (!$s) {
            return '';
        }
        $bits = array_filter(array(
            $s['cite_author'],
            $s['cite_title'] ?: $s['title'],
            $s['cite_publisher'],
            $s['cite_date'],
        ));
        return implode(', ', $bits);
    }

    public static function set_fetch_status($source_id, $status, $error = '') {
        if (!in_array($status, self::FETCH_STATUSES, true)) {
            return;
        }
        update_post_meta($source_id, '_wcpo_fetch_status', $status);
        if ($error !== '') {
            update_post_meta($source_id, '_wcpo_fetch_error', sanitize_text_field($error));
        } else {
            delete_post_meta($source_id, '_wcpo_fetch_error');
        }
    }

    /** Save the fetched text snapshot, bounded by max_snapshot_chars. */
    public static function save_snapshot($source_id, $text) {
        $max = (int) wcpo_get_setting('max_snapshot_chars');
        wp_update_post(array(
            'ID'           => (int) $source_id,
            'post_content' => mb_substr((string) $text, 0, $max),
        ));
        update_post_meta($source_id, '_wcpo_fetched_at', current_time('mysql', true));
    }

    /** Fill citation fields — human edits always win over AI prefill. */
    public static function save_citation($source_id, array $fields, $overwrite = false) {
        foreach (self::CITE_FIELDS as $field) {
            if (!array_key_exists($field, $fields) || $fields[$field] === null) {
                continue;
            }
            $current = get_post_meta($source_id, '_wcpo_' . $field, true);
            if ($current !== '' && !$overwrite) {
                continue; // AI prefill never clobbers an existing (possibly human) value
            }
            update_post_meta($source_id, '_wcpo_' . $field, sanitize_text_field((string) $fields[$field]));
        }
        if (!empty($fields['cite_title']) && ($overwrite || strpos(get_post($source_id)->post_title, '/') !== false)) {
            // Upgrade the placeholder host/path title to the real citation title.
            wp_update_post(array('ID' => (int) $source_id, 'post_title' => sanitize_text_field($fields['cite_title'])));
        }
    }

    public static function save_classification($source_id, $doc_kind, $source_tier, $tier_confidence) {
        if (in_array($doc_kind, wcpo_doc_kinds(), true)) {
            update_post_meta($source_id, '_wcpo_doc_kind', $doc_kind);
        }
        if (in_array($source_tier, wcpo_source_tiers(), true)) {
            update_post_meta($source_id, '_wcpo_source_tier', $source_tier);
        }
        update_post_meta($source_id, '_wcpo_tier_confidence', (float) $tier_confidence);
    }

    /** Next single source in a pipeline state — the "process next N" unit. */
    public static function next_with_status($person_id, $status) {
        $posts = get_posts(array(
            'post_type'      => 'wcpo_source',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_query'     => array(
                array('key' => '_wcpo_person_id', 'value' => (int) $person_id),
                array('key' => '_wcpo_fetch_status', 'value' => $status),
            ),
        ));
        return $posts ? $posts[0] : null;
    }

    public static function count_with_status($person_id, $status) {
        $posts = get_posts(array(
            'post_type'      => 'wcpo_source',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array('key' => '_wcpo_person_id', 'value' => (int) $person_id),
                array('key' => '_wcpo_fetch_status', 'value' => $status),
            ),
        ));
        return count($posts);
    }

    public static function counts($person_id) {
        $counts = array();
        foreach (self::FETCH_STATUSES as $status) {
            $counts[$status] = self::count_with_status($person_id, $status);
        }
        $counts['total'] = array_sum($counts);
        return $counts;
    }

    public static function list_for_person($person_id, $limit = 500) {
        return get_posts(array(
            'post_type'      => 'wcpo_source',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'meta_key'       => '_wcpo_person_id',
            'meta_value'     => (int) $person_id,
        ));
    }

    /** Reset a failed source so the pipeline can retry it. */
    public static function retry($source_id) {
        $status = get_post_meta($source_id, '_wcpo_fetch_status', true);
        if ($status === 'fetch_failed') {
            self::set_fetch_status($source_id, 'new');
        } elseif ($status === 'extract_failed') {
            self::set_fetch_status($source_id, 'fetched');
        }
        return get_post_meta($source_id, '_wcpo_fetch_status', true);
    }

    /** Trash a source and its still-proposed facts (accepted facts are kept). */
    public static function delete($source_id) {
        $proposed = get_posts(array(
            'post_type'      => 'wcpo_fact',
            'post_status'    => 'wcpo_proposed',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => '_wcpo_source_id',
            'meta_value'     => (int) $source_id,
        ));
        foreach ($proposed as $fact_id) {
            wp_trash_post($fact_id);
        }
        wp_trash_post($source_id);
        return count($proposed);
    }
}
