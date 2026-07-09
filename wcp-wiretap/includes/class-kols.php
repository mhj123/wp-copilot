<?php
/**
 * KOL management (F2) — CRUD over the wcp_kol CPT.
 *
 * Tracking status lives in post meta `_wcpw_tracking_status`:
 * suggested / active / paused / dismissed. Trust score 1–5 is set manually
 * by the user; trust ≥ trust_alert_min (default 4) = "originator tier".
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPW_KOLs {

    /**
     * Create (or return existing) KOL. Dedupe is on x_user_id when known,
     * else on handle. Returns post id or WP_Error.
     */
    public static function create($handle, array $args = array()) {
        $handle = ltrim(trim($handle), '@');
        if (!$handle) {
            return new WP_Error('bad_handle', 'Empty handle');
        }

        $x_user_id = isset($args['x_user_id']) ? (string) $args['x_user_id'] : '';
        $existing = $x_user_id ? self::get_by_x_user_id($x_user_id) : null;
        if (!$existing) {
            $existing = self::get_by_handle($handle);
        }
        if ($existing) {
            // Re-suggesting a dismissed KOL is suppressed (§F3); everything
            // else just returns the existing record.
            return $existing->ID;
        }

        $status = isset($args['tracking_status']) ? $args['tracking_status'] : 'active';
        if (!in_array($status, array('suggested', 'active', 'paused', 'dismissed'), true)) {
            $status = 'active';
        }

        $post_id = wp_insert_post(array(
            'post_type'   => 'wcp_kol',
            'post_status' => 'publish',
            'post_title'  => '@' . $handle,
        ), true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }

        update_post_meta($post_id, '_wcpw_handle', $handle);
        update_post_meta($post_id, '_wcpw_x_user_id', $x_user_id);
        update_post_meta($post_id, '_wcpw_trust_score', isset($args['trust_score']) ? max(1, min(5, (int) $args['trust_score'])) : 3);
        update_post_meta($post_id, '_wcpw_tracking_status', $status);
        foreach (array('list_source', 'discovery_source', 'discovery_reason', 'notes') as $key) {
            if (!empty($args[$key])) {
                update_post_meta($post_id, '_wcpw_' . $key, sanitize_text_field($args[$key]));
            }
        }
        return $post_id;
    }

    /** @return WP_Post|null */
    public static function get_by_handle($handle) {
        $posts = get_posts(array(
            'post_type'      => 'wcp_kol',
            'posts_per_page' => 1,
            'meta_key'       => '_wcpw_handle',
            'meta_value'     => ltrim(trim($handle), '@'),
        ));
        return $posts ? $posts[0] : null;
    }

    /** @return WP_Post|null */
    public static function get_by_x_user_id($x_user_id) {
        if (!$x_user_id) {
            return null;
        }
        $posts = get_posts(array(
            'post_type'      => 'wcp_kol',
            'posts_per_page' => 1,
            'meta_key'       => '_wcpw_x_user_id',
            'meta_value'     => (string) $x_user_id,
        ));
        return $posts ? $posts[0] : null;
    }

    /** @return WP_Post[] */
    public static function list_by_status($status) {
        return get_posts(array(
            'post_type'      => 'wcp_kol',
            'posts_per_page' => -1,
            'meta_key'       => '_wcpw_tracking_status',
            'meta_value'     => $status,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ));
    }

    /** All KOLs regardless of status. */
    public static function all() {
        return get_posts(array(
            'post_type'      => 'wcp_kol',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ));
    }

    public static function meta($post_id) {
        return array(
            'id'               => (int) $post_id,
            'handle'           => get_post_meta($post_id, '_wcpw_handle', true),
            'x_user_id'        => get_post_meta($post_id, '_wcpw_x_user_id', true),
            'trust_score'      => (int) (get_post_meta($post_id, '_wcpw_trust_score', true) ?: 3),
            'tracking_status'  => get_post_meta($post_id, '_wcpw_tracking_status', true) ?: 'active',
            'list_source'      => get_post_meta($post_id, '_wcpw_list_source', true),
            'last_fetched_at'  => get_post_meta($post_id, '_wcpw_last_fetched_at', true),
            'last_tweet_id'    => get_post_meta($post_id, '_wcpw_last_tweet_id', true),
            'discovery_source' => get_post_meta($post_id, '_wcpw_discovery_source', true),
            'discovery_reason' => get_post_meta($post_id, '_wcpw_discovery_reason', true),
            'pause_reason'     => get_post_meta($post_id, '_wcpw_pause_reason', true),
            'notes'            => get_post_meta($post_id, '_wcpw_notes', true),
        );
    }

    public static function trust($post_id) {
        return (int) (get_post_meta($post_id, '_wcpw_trust_score', true) ?: 3);
    }

    /** Trust weight for aggregation: trust/3 so the default trust (3) = 1.0. */
    public static function trust_weight($post_id) {
        return self::trust($post_id) / 3.0;
    }

    public static function set_status($post_id, $status, $reason = '') {
        if (!in_array($status, array('suggested', 'active', 'paused', 'dismissed'), true)) {
            return false;
        }
        update_post_meta($post_id, '_wcpw_tracking_status', $status);
        if ($status === 'paused' && $reason) {
            update_post_meta($post_id, '_wcpw_pause_reason', sanitize_text_field($reason));
        }
        if ($status === 'active') {
            delete_post_meta($post_id, '_wcpw_pause_reason');
        }
        return true;
    }

    /** Count of actively-polled KOLs (soft cap warning, F2). */
    public static function active_count() {
        return count(self::list_by_status('active'));
    }

    /** Tier-1 originators: active KOLs with trust ≥ trust_alert_min. */
    public static function tier1() {
        $min = (int) wcpw_get_setting('trust_alert_min');
        $out = array();
        foreach (self::list_by_status('active') as $kol) {
            if (self::trust($kol->ID) >= $min) {
                $out[] = $kol;
            }
        }
        return $out;
    }
}
