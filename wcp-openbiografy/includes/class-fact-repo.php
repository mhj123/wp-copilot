<?php
/**
 * Fact repository. A fact is one atomic, source-linked claim (the PRD's
 * "Claim"): the claim sentence in post_content, provenance in _wcpo_source_id
 * + _wcpo_quote, fuzzy date as EDTF + lexical sort keys.
 *
 * Review state = custom post status (wcpo_proposed / wcpo_accepted /
 * wcpo_dismissed) — the single source of truth.
 *
 * GUARDRAIL: a fact cannot exist without a source link — create() rejects
 * data without one, which makes "citation coverage" 100% by construction.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPO_Fact_Repo {

    const STATUSES = array('wcpo_proposed', 'wcpo_accepted', 'wcpo_dismissed');

    /**
     * Create a proposed fact.
     *
     * GUARDRAIL: facts are ALWAYS created in wcpo_proposed. There is no code
     * path that creates an accepted fact.
     */
    public static function create(array $data) {
        if (empty($data['claim']) || empty($data['source_id']) || empty($data['person_id'])) {
            return new WP_Error('bad_fact', __('A fact requires a claim, a source and a person.', 'wcp-openbiografy'));
        }

        $claim = sanitize_textarea_field($data['claim']);
        $edtf  = isset($data['date_edtf']) ? trim((string) $data['date_edtf']) : '';
        if (!WCPO_EDTF::is_valid($edtf)) {
            $edtf = ''; // unparseable model date → keep the fact, file it as undated
        }
        $range = WCPO_EDTF::to_sort_range($edtf);

        $post_id = wp_insert_post(array(
            'post_type'    => 'wcpo_fact',
            'post_status'  => 'wcpo_proposed',
            'post_title'   => wp_trim_words($claim, 12, '…'),
            'post_content' => $claim,
        ), true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }

        $meta = array(
            '_wcpo_person_id'       => (int) $data['person_id'],
            '_wcpo_source_id'       => (int) $data['source_id'],
            '_wcpo_date_edtf'       => $edtf,
            '_wcpo_date_sort_start' => $range['start'],
            '_wcpo_date_sort_end'   => $range['end'],
            '_wcpo_place'           => isset($data['place']) ? sanitize_text_field((string) $data['place']) : '',
            '_wcpo_quote'           => isset($data['quote']) ? sanitize_textarea_field((string) $data['quote']) : '',
            '_wcpo_locator'         => isset($data['locator']) ? sanitize_text_field((string) $data['locator']) : '',
            '_wcpo_confidence'      => isset($data['confidence']) ? max(0, min(1, (float) $data['confidence'])) : 0.5,
            '_wcpo_event_id'        => 0,
            '_wcpo_ai_action_id'    => isset($data['ai_action_id']) ? sanitize_text_field((string) $data['ai_action_id']) : '',
        );
        foreach ($meta as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }

        $kind = isset($data['kind']) && in_array($data['kind'], wcpo_kinds(), true) ? $data['kind'] : 'other';
        wp_set_object_terms($post_id, $kind, 'wcpo_kind');

        return $post_id;
    }

    public static function meta($fact_id) {
        $post = get_post($fact_id);
        if (!$post || $post->post_type !== 'wcpo_fact') {
            return null;
        }
        $kinds = wp_get_object_terms($fact_id, 'wcpo_kind', array('fields' => 'names'));
        return array(
            'id'           => (int) $fact_id,
            'claim'        => $post->post_content,
            'status'       => $post->post_status,
            'kind'         => $kinds && !is_wp_error($kinds) ? $kinds[0] : 'other',
            'person_id'    => (int) get_post_meta($fact_id, '_wcpo_person_id', true),
            'source_id'    => (int) get_post_meta($fact_id, '_wcpo_source_id', true),
            'date_edtf'    => get_post_meta($fact_id, '_wcpo_date_edtf', true),
            'date_display' => WCPO_EDTF::format(get_post_meta($fact_id, '_wcpo_date_edtf', true)),
            'sort_start'   => get_post_meta($fact_id, '_wcpo_date_sort_start', true),
            'place'        => get_post_meta($fact_id, '_wcpo_place', true),
            'quote'        => get_post_meta($fact_id, '_wcpo_quote', true),
            'locator'      => get_post_meta($fact_id, '_wcpo_locator', true),
            'confidence'   => (float) get_post_meta($fact_id, '_wcpo_confidence', true),
            'event_id'     => (int) get_post_meta($fact_id, '_wcpo_event_id', true),
            'ai_action_id' => get_post_meta($fact_id, '_wcpo_ai_action_id', true),
        );
    }

    /**
     * Human decision — the ONLY status transition, reached from REST handlers
     * behind a human click. Decisions are recorded on the originating AI
     * action row.
     *
     * @param string $decision 'accept'|'dismiss'
     */
    public static function decide($fact_id, $decision, $reason = '') {
        $new_status = $decision === 'accept' ? 'wcpo_accepted' : 'wcpo_dismissed';
        wp_update_post(array('ID' => (int) $fact_id, 'post_status' => $new_status));
        if ($decision === 'dismiss' && $reason) {
            update_post_meta($fact_id, '_wcpo_dismiss_reason', sanitize_text_field($reason));
        }
        self::record_decision($fact_id, $decision === 'accept');
        return true;
    }

    /** Append the decision to the extraction's audit row. */
    private static function record_decision($fact_id, $accepted) {
        if (!wcpo_copilot_active()) {
            return;
        }
        $action_id = get_post_meta($fact_id, '_wcpo_ai_action_id', true);
        if (!$action_id) {
            return;
        }
        $logger = WCP_AI_Logger::instance();
        $row = $logger->get_action($action_id);
        $accepted_items  = $row && is_array($row['accepted_items']) ? $row['accepted_items'] : array();
        $dismissed_items = $row && is_array($row['dismissed_items']) ? $row['dismissed_items'] : array();
        if ($accepted) {
            $accepted_items[] = (int) $fact_id;
        } else {
            $dismissed_items[] = (int) $fact_id;
        }
        $logger->log_decisions($action_id, array_values(array_unique($accepted_items)), array_values(array_unique($dismissed_items)));
    }

    /**
     * Human edit with before/after diff logged. Editable: claim, date_edtf,
     * place, kind, quote, locator, confidence.
     */
    public static function edit($fact_id, array $changes) {
        $before = array();
        $after  = array();

        if (array_key_exists('claim', $changes) && trim((string) $changes['claim']) !== '') {
            $before['claim'] = get_post($fact_id)->post_content;
            $claim = sanitize_textarea_field($changes['claim']);
            wp_update_post(array(
                'ID'           => (int) $fact_id,
                'post_content' => $claim,
                'post_title'   => wp_trim_words($claim, 12, '…'),
            ));
            $after['claim'] = $claim;
        }

        if (array_key_exists('date_edtf', $changes)) {
            $edtf = trim((string) $changes['date_edtf']);
            if (!WCPO_EDTF::is_valid($edtf)) {
                return new WP_Error('bad_edtf', sprintf(__('Invalid EDTF date: %s', 'wcp-openbiografy'), $edtf));
            }
            $before['date_edtf'] = get_post_meta($fact_id, '_wcpo_date_edtf', true);
            $range = WCPO_EDTF::to_sort_range($edtf);
            update_post_meta($fact_id, '_wcpo_date_edtf', $edtf);
            update_post_meta($fact_id, '_wcpo_date_sort_start', $range['start']);
            update_post_meta($fact_id, '_wcpo_date_sort_end', $range['end']);
            $after['date_edtf'] = $edtf;
        }

        foreach (array('place', 'quote', 'locator') as $field) {
            if (!array_key_exists($field, $changes)) {
                continue;
            }
            $before[$field] = get_post_meta($fact_id, '_wcpo_' . $field, true);
            $value = $field === 'quote' ? sanitize_textarea_field($changes[$field]) : sanitize_text_field($changes[$field]);
            update_post_meta($fact_id, '_wcpo_' . $field, $value);
            $after[$field] = $value;
        }

        if (array_key_exists('confidence', $changes)) {
            $before['confidence'] = get_post_meta($fact_id, '_wcpo_confidence', true);
            $value = max(0, min(1, (float) $changes['confidence']));
            update_post_meta($fact_id, '_wcpo_confidence', $value);
            $after['confidence'] = $value;
        }

        if (array_key_exists('kind', $changes) && in_array($changes['kind'], wcpo_kinds(), true)) {
            $kinds = wp_get_object_terms($fact_id, 'wcpo_kind', array('fields' => 'names'));
            $before['kind'] = $kinds && !is_wp_error($kinds) ? $kinds[0] : '';
            wp_set_object_terms($fact_id, $changes['kind'], 'wcpo_kind');
            $after['kind'] = $changes['kind'];
        }

        if ($after && wcpo_copilot_active()) {
            WCP_AI_Logger::instance()->log_action('wcpo_human_edit', array(
                'input_context'   => array('before' => $before),
                'output'          => array('after' => $after),
                'context_post_id' => (int) $fact_id,
            ));
        }
        return $after;
    }

    /** Proposed facts for a person, grouped by source id (review screen). */
    public static function proposed_by_source($person_id) {
        $posts = get_posts(array(
            'post_type'      => 'wcpo_fact',
            'post_status'    => 'wcpo_proposed',
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_key'       => '_wcpo_person_id',
            'meta_value'     => (int) $person_id,
        ));
        $groups = array();
        foreach ($posts as $post) {
            $source_id = (int) get_post_meta($post->ID, '_wcpo_source_id', true);
            $groups[$source_id][] = $post;
        }
        return $groups;
    }

    /** Bulk accept every remaining proposed fact for one source. */
    public static function accept_all_for_source($source_id) {
        $posts = get_posts(array(
            'post_type'      => 'wcpo_fact',
            'post_status'    => 'wcpo_proposed',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => '_wcpo_source_id',
            'meta_value'     => (int) $source_id,
        ));
        foreach ($posts as $fact_id) {
            self::decide($fact_id, 'accept');
        }
        return count($posts);
    }

    /**
     * Accepted facts not yet part of an accepted event AND not referenced by
     * a still-proposed event ($exclude_ids) — the reconciliation input.
     */
    public static function accepted_unconsolidated($person_id, array $exclude_ids = array()) {
        $posts = get_posts(array(
            'post_type'      => 'wcpo_fact',
            'post_status'    => 'wcpo_accepted',
            'posts_per_page' => -1,
            'meta_query'     => array(
                array('key' => '_wcpo_person_id', 'value' => (int) $person_id),
                array('key' => '_wcpo_event_id', 'value' => 0),
            ),
            'meta_key'       => '_wcpo_date_sort_start',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
        ));
        if ($exclude_ids) {
            $posts = array_values(array_filter($posts, function ($post) use ($exclude_ids) {
                return !in_array((int) $post->ID, $exclude_ids, true);
            }));
        }
        return $posts;
    }

    /** Accepted, dated, unconsolidated facts — shown lighter on the frontend timeline. */
    public static function accepted_dated($person_id) {
        return get_posts(array(
            'post_type'      => 'wcpo_fact',
            'post_status'    => 'wcpo_accepted',
            'posts_per_page' => -1,
            'meta_query'     => array(
                array('key' => '_wcpo_person_id', 'value' => (int) $person_id),
                array('key' => '_wcpo_event_id', 'value' => 0),
                array('key' => '_wcpo_date_sort_start', 'value' => '', 'compare' => '!='),
            ),
            'meta_key'       => '_wcpo_date_sort_start',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
        ));
    }

    public static function counts($person_id) {
        $counts = array();
        foreach (self::STATUSES as $status) {
            $posts = get_posts(array(
                'post_type'      => 'wcpo_fact',
                'post_status'    => $status,
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_key'       => '_wcpo_person_id',
                'meta_value'     => (int) $person_id,
            ));
            $counts[str_replace('wcpo_', '', $status)] = count($posts);
        }
        $counts['unconsolidated'] = count(self::accepted_unconsolidated($person_id));
        return $counts;
    }

    /** Proposed facts below the display threshold — warnings panel. */
    public static function low_confidence_proposed($person_id) {
        $threshold = (float) wcpo_get_setting('min_confidence_display');
        $posts = get_posts(array(
            'post_type'      => 'wcpo_fact',
            'post_status'    => 'wcpo_proposed',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array('key' => '_wcpo_person_id', 'value' => (int) $person_id),
                array('key' => '_wcpo_confidence', 'value' => $threshold, 'compare' => '<', 'type' => 'DECIMAL(4,3)'),
            ),
        ));
        return count($posts);
    }
}
