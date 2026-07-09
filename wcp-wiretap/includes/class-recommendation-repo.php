<?php
/**
 * Recommendation repository (§4.2, §F1).
 *
 * Review state = custom post status (wcp_pending / wcp_accepted /
 * wcp_dismissed) — the single source of truth; there is no duplicate
 * review_status meta. Prior calls are queried live, never snapshotted.
 *
 * Newness is computed HERE, in code, never by the LLM (§F1).
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPW_Recommendation_Repo {

    const STATUSES = array('wcp_pending', 'wcp_accepted', 'wcp_dismissed');

    /**
     * Deterministic newness (§F1). A classified recommendation is a NEW call iff:
     *  - first recommendation ever by this KOL on this ticker; or
     *  - direction differs from this KOL's most recent call on the ticker; or
     *  - the most recent same-direction call is older than new_call_window days.
     * Otherwise it's a reinforcement of the most recent call.
     *
     * @return array { is_new: bool, reason: string, prior_rec_id: int }
     */
    public static function assess_newness($kol_id, $ticker, $direction) {
        $prior = self::latest_by_kol_ticker($kol_id, $ticker);
        if (!$prior) {
            return array('is_new' => true, 'reason' => 'first_call_by_kol', 'prior_rec_id' => 0);
        }
        $prior_direction = get_post_meta($prior->ID, '_wcpw_direction', true);
        if ($prior_direction !== $direction) {
            return array('is_new' => true, 'reason' => 'direction_change', 'prior_rec_id' => $prior->ID);
        }
        $window_days = (int) wcpw_get_setting('new_call_window');
        $age_days = (time() - get_post_time('U', true, $prior)) / DAY_IN_SECONDS;
        if ($age_days > $window_days) {
            return array('is_new' => true, 'reason' => 'window_elapsed', 'prior_rec_id' => $prior->ID);
        }
        return array('is_new' => false, 'reason' => 'repeat_within_window', 'prior_rec_id' => $prior->ID);
    }

    /**
     * Most recent rec by a KOL on a ticker, any review status.
     *
     * GUARDRAIL (§12.6): dismissed recommendations are retained and included
     * here — a dismissed call suppresses re-surfacing of repeats within the
     * window (they reinforce the dismissed rec instead of creating new noise).
     */
    public static function latest_by_kol_ticker($kol_id, $ticker) {
        $posts = get_posts(array(
            'post_type'      => 'wcp_recommendation',
            'post_status'    => self::STATUSES,
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => array(
                array('key' => '_wcpw_kol_id', 'value' => (int) $kol_id),
                array('key' => '_wcpw_ticker', 'value' => strtoupper($ticker)),
            ),
        ));
        return $posts ? $posts[0] : null;
    }

    /** All prior calls by a KOL on a ticker — queried live for display (§F1). */
    public static function prior_calls($kol_id, $ticker, $limit = 10) {
        return get_posts(array(
            'post_type'      => 'wcp_recommendation',
            'post_status'    => self::STATUSES,
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => array(
                array('key' => '_wcpw_kol_id', 'value' => (int) $kol_id),
                array('key' => '_wcpw_ticker', 'value' => strtoupper($ticker)),
            ),
        ));
    }

    /** Earliest tracked recommendation on a ticker (earliness anchor, §5). */
    public static function earliest_on_ticker($ticker) {
        $posts = get_posts(array(
            'post_type'      => 'wcp_recommendation',
            'post_status'    => self::STATUSES,
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'ASC',
            'meta_key'       => '_wcpw_ticker',
            'meta_value'     => strtoupper($ticker),
        ));
        return $posts ? $posts[0] : null;
    }

    /**
     * Create a pending recommendation (§F1).
     *
     * GUARDRAIL (§12.1): recs are ALWAYS created in wcp_pending. There is no
     * code path that creates an accepted recommendation.
     */
    public static function create(array $data) {
        $ticker = strtoupper($data['ticker']);
        $kol = WCPW_KOLs::meta($data['kol_id']);

        $title = sprintf('$%s — %s — @%s — %s', $ticker, $data['direction'], $kol['handle'], gmdate('Y-m-d'));

        $post_id = wp_insert_post(array(
            'post_type'    => 'wcp_recommendation',
            'post_status'  => 'wcp_pending',
            'post_title'   => $title,
            'post_content' => isset($data['rationale_excerpt']) ? $data['rationale_excerpt'] : '',
        ), true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }

        $meta = array(
            '_wcpw_ticker'                => $ticker,
            '_wcpw_asset_class'           => isset($data['asset_class']) ? $data['asset_class'] : 'crypto',
            '_wcpw_direction'             => $data['direction'],
            '_wcpw_confidence'            => (float) $data['confidence'],
            '_wcpw_source_tweet_id'       => $data['source_tweet_id'],
            '_wcpw_kol_id'                => (int) $data['kol_id'],
            '_wcpw_rationale_excerpt'     => isset($data['rationale_excerpt']) ? $data['rationale_excerpt'] : '',
            '_wcpw_is_new_call'           => 1,
            '_wcpw_newness_reason'        => isset($data['newness_reason']) ? $data['newness_reason'] : '',
            '_wcpw_reinforced_count'      => 0,
            '_wcpw_reinforcing_tweet_ids' => wp_json_encode(array()),
            '_wcpw_ai_log_id'             => isset($data['ai_log_id']) ? (int) $data['ai_log_id'] : 0,
        );
        // price-at-call is captured immediately because it cannot be backfilled (§F1).
        if (isset($data['price_at_call']) && $data['price_at_call'] !== null) {
            $meta['_wcpw_price_at_call'] = (float) $data['price_at_call'];
        }
        if (isset($data['earliness_at_call'])) {
            $meta['_wcpw_earliness_at_call'] = wp_json_encode($data['earliness_at_call']);
        }
        if (!empty($data['ticker_unverified'])) {
            $meta['_wcpw_ticker_unverified'] = 1;
        }
        if (!empty($data['low_confidence'])) {
            // Low-confidence signals are flagged for human attention, never
            // silently dropped (§7.1).
            $meta['_wcpw_low_confidence'] = 1;
        }
        foreach ($meta as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }

        wp_set_object_terms($post_id, $ticker, 'wcp_ticker');
        wp_set_object_terms($post_id, $meta['_wcpw_asset_class'], 'wcp_asset_class');
        if (!empty($data['themes'])) {
            wp_set_object_terms($post_id, array_map('sanitize_text_field', (array) $data['themes']), 'wcp_theme');
        }

        if (!empty($data['ai_log_id'])) {
            WCPW_AI_Log::set_related((int) $data['ai_log_id'], $post_id);
        }

        // Anchor the earliness first-call reference if this is the earliest call.
        WCPW_Ticker_Registry::maybe_set_first_call(
            $ticker,
            gmdate('Y-m-d H:i:s'),
            isset($data['price_at_call']) ? $data['price_at_call'] : null
        );

        return $post_id;
    }

    /**
     * Reinforcement (§F1): increment count, append the tweet reference, bump
     * last_reinforced_at. No duplicate pending item is created.
     */
    public static function reinforce($rec_id, $tweet_id) {
        $count = (int) get_post_meta($rec_id, '_wcpw_reinforced_count', true);
        update_post_meta($rec_id, '_wcpw_reinforced_count', $count + 1);
        update_post_meta($rec_id, '_wcpw_last_reinforced_at', current_time('mysql', true));

        $ids = json_decode((string) get_post_meta($rec_id, '_wcpw_reinforcing_tweet_ids', true), true);
        $ids = is_array($ids) ? $ids : array();
        if (!in_array($tweet_id, $ids, true)) {
            $ids[] = $tweet_id;
            update_post_meta($rec_id, '_wcpw_reinforcing_tweet_ids', wp_json_encode($ids));
        }
        return $count + 1;
    }

    /** Full meta bundle for rendering / context packs. */
    public static function meta($rec_id) {
        $post = get_post($rec_id);
        if (!$post || $post->post_type !== 'wcp_recommendation') {
            return null;
        }
        $kol_id = (int) get_post_meta($rec_id, '_wcpw_kol_id', true);
        return array(
            'id'                => (int) $rec_id,
            'title'             => $post->post_title,
            'status'            => $post->post_status,
            'created_at'        => $post->post_date_gmt,
            'ticker'            => get_post_meta($rec_id, '_wcpw_ticker', true),
            'asset_class'       => get_post_meta($rec_id, '_wcpw_asset_class', true),
            'direction'         => get_post_meta($rec_id, '_wcpw_direction', true),
            'confidence'        => (float) get_post_meta($rec_id, '_wcpw_confidence', true),
            'source_tweet_id'   => get_post_meta($rec_id, '_wcpw_source_tweet_id', true),
            'kol_id'            => $kol_id,
            'kol'               => $kol_id ? WCPW_KOLs::meta($kol_id) : null,
            'rationale_excerpt' => get_post_meta($rec_id, '_wcpw_rationale_excerpt', true),
            'is_new_call'       => (bool) get_post_meta($rec_id, '_wcpw_is_new_call', true),
            'newness_reason'    => get_post_meta($rec_id, '_wcpw_newness_reason', true),
            'reinforced_count'  => (int) get_post_meta($rec_id, '_wcpw_reinforced_count', true),
            'last_reinforced_at' => get_post_meta($rec_id, '_wcpw_last_reinforced_at', true),
            'price_at_call'     => get_post_meta($rec_id, '_wcpw_price_at_call', true),
            'earliness_at_call' => json_decode((string) get_post_meta($rec_id, '_wcpw_earliness_at_call', true), true),
            'ai_log_id'         => (int) get_post_meta($rec_id, '_wcpw_ai_log_id', true),
            'low_confidence'    => (bool) get_post_meta($rec_id, '_wcpw_low_confidence', true),
            'ticker_unverified' => (bool) get_post_meta($rec_id, '_wcpw_ticker_unverified', true),
            'dismiss_reason'    => get_post_meta($rec_id, '_wcpw_dismiss_reason', true),
            'checkins'          => json_decode((string) get_post_meta($rec_id, '_wcpw_checkins', true), true) ?: array(),
        );
    }

    /** Pending inbox, newest first. */
    public static function pending($limit = 50) {
        return get_posts(array(
            'post_type'      => 'wcp_recommendation',
            'post_status'    => 'wcp_pending',
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ));
    }

    /**
     * Human decision. Accept / dismiss are the ONLY status transitions and
     * they exist ONLY here, reached from REST handlers behind a human click.
     *
     * @param string $decision 'accept'|'dismiss'
     */
    public static function decide($rec_id, $decision, $dismiss_reason = '') {
        $new_status = $decision === 'accept' ? 'wcp_accepted' : 'wcp_dismissed';
        wp_update_post(array('ID' => (int) $rec_id, 'post_status' => $new_status));
        if ($decision === 'dismiss' && $dismiss_reason) {
            // Dismissal reason tags — future fuel for auto-suggested trust scores (§14).
            update_post_meta($rec_id, '_wcpw_dismiss_reason', sanitize_text_field($dismiss_reason));
        }
        $log_id = (int) get_post_meta($rec_id, '_wcpw_ai_log_id', true);
        if ($log_id) {
            WCPW_AI_Log::set_decision($log_id, $decision . ($dismiss_reason ? ':' . $dismiss_reason : ''));
        }
        return true;
    }

    /**
     * Human edit with before/after diff logged (§F1).
     * Editable fields: direction, confidence, ticker, rationale_excerpt.
     */
    public static function edit($rec_id, array $changes) {
        $editable = array('direction', 'confidence', 'ticker', 'rationale_excerpt');
        $before = array();
        $after = array();
        foreach ($editable as $field) {
            if (!array_key_exists($field, $changes)) {
                continue;
            }
            $meta_key = '_wcpw_' . $field;
            $before[$field] = get_post_meta($rec_id, $meta_key, true);
            $value = $field === 'confidence' ? (float) $changes[$field] : sanitize_text_field($changes[$field]);
            if ($field === 'ticker') {
                $value = strtoupper($value);
                wp_set_object_terms($rec_id, $value, 'wcp_ticker');
                // Editing the ticker is the human-confirmation path for
                // LLM-resolved unknowns (§4.3).
                if (get_post_meta($rec_id, '_wcpw_ticker_unverified', true)) {
                    WCPW_Ticker_Registry::verify($value);
                    delete_post_meta($rec_id, '_wcpw_ticker_unverified');
                }
            }
            update_post_meta($rec_id, $meta_key, $value);
            $after[$field] = $value;
        }
        if ($after) {
            WCPW_AI_Log::log_edit($rec_id, $before, $after);
        }
        return $after;
    }

    /** New calls (and reinforcements) in a window — digest pre-aggregation. */
    public static function created_since($since_gmt) {
        return get_posts(array(
            'post_type'      => 'wcp_recommendation',
            'post_status'    => self::STATUSES,
            'posts_per_page' => 100,
            'date_query'     => array(array('after' => $since_gmt, 'column' => 'post_date_gmt')),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ));
    }
}
