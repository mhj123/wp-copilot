<?php
/**
 * Timeline event repository. An event is the reconciled unit shown on the
 * public timeline: one real-world happening backed by one or more accepted
 * facts (the PRD's Claim → TimelineEvent aggregation). Conflicting sources
 * are preserved as a contested flag + note, never flattened.
 *
 * Same proposal lifecycle as facts: AI creates wcpo_proposed, a human
 * accepts or dismisses via REST. Accepting stamps _wcpo_event_id on the
 * member facts; dismissing frees them for the next consolidation run.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPO_Event_Repo {

    /**
     * Create a proposed event.
     *
     * GUARDRAIL: events are ALWAYS created in wcpo_proposed; member facts are
     * only stamped when a human accepts the event.
     */
    public static function create(array $data) {
        if (empty($data['title']) || empty($data['person_id']) || empty($data['fact_ids'])) {
            return new WP_Error('bad_event', __('An event requires a title, a person and member facts.', 'wcp-openbiografy'));
        }

        $edtf = isset($data['date_edtf']) ? trim((string) $data['date_edtf']) : '';
        if (!WCPO_EDTF::is_valid($edtf)) {
            $edtf = '';
        }
        $range = WCPO_EDTF::to_sort_range($edtf);

        $post_id = wp_insert_post(array(
            'post_type'    => 'wcpo_event',
            'post_status'  => 'wcpo_proposed',
            'post_title'   => sanitize_text_field($data['title']),
            'post_content' => isset($data['description']) ? sanitize_textarea_field((string) $data['description']) : '',
        ), true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }

        $meta = array(
            '_wcpo_person_id'      => (int) $data['person_id'],
            '_wcpo_fact_ids'       => wp_json_encode(array_values(array_map('intval', (array) $data['fact_ids']))),
            '_wcpo_date_edtf'      => $edtf,
            '_wcpo_date_sort_start' => $range['start'],
            '_wcpo_date_sort_end'  => $range['end'],
            '_wcpo_place'          => isset($data['place']) ? sanitize_text_field((string) $data['place']) : '',
            '_wcpo_confidence'     => isset($data['confidence']) ? max(0, min(1, (float) $data['confidence'])) : 0.5,
            '_wcpo_importance'     => isset($data['importance']) ? max(0, min(1, (float) $data['importance'])) : 0.5,
            '_wcpo_contested'      => empty($data['contested']) ? 0 : 1,
            '_wcpo_contested_note' => isset($data['contested_note']) ? sanitize_text_field((string) $data['contested_note']) : '',
            '_wcpo_chapter_id'     => 0,
            '_wcpo_ai_action_id'   => isset($data['ai_action_id']) ? sanitize_text_field((string) $data['ai_action_id']) : '',
        );
        foreach ($meta as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }

        $kind = isset($data['kind']) && in_array($data['kind'], wcpo_kinds(), true) ? $data['kind'] : 'other';
        wp_set_object_terms($post_id, $kind, 'wcpo_kind');

        return $post_id;
    }

    public static function meta($event_id) {
        $post = get_post($event_id);
        if (!$post || $post->post_type !== 'wcpo_event') {
            return null;
        }
        $kinds = wp_get_object_terms($event_id, 'wcpo_kind', array('fields' => 'names'));
        $fact_ids = json_decode((string) get_post_meta($event_id, '_wcpo_fact_ids', true), true);
        return array(
            'id'             => (int) $event_id,
            'title'          => $post->post_title,
            'description'    => $post->post_content,
            'status'         => $post->post_status,
            'kind'           => $kinds && !is_wp_error($kinds) ? $kinds[0] : 'other',
            'person_id'      => (int) get_post_meta($event_id, '_wcpo_person_id', true),
            'fact_ids'       => is_array($fact_ids) ? array_map('intval', $fact_ids) : array(),
            'date_edtf'      => get_post_meta($event_id, '_wcpo_date_edtf', true),
            'date_display'   => WCPO_EDTF::format(get_post_meta($event_id, '_wcpo_date_edtf', true)),
            'sort_start'     => get_post_meta($event_id, '_wcpo_date_sort_start', true),
            'place'          => get_post_meta($event_id, '_wcpo_place', true),
            'confidence'     => (float) get_post_meta($event_id, '_wcpo_confidence', true),
            'importance'     => (float) get_post_meta($event_id, '_wcpo_importance', true),
            'contested'      => (bool) get_post_meta($event_id, '_wcpo_contested', true),
            'contested_note' => get_post_meta($event_id, '_wcpo_contested_note', true),
            'chapter_id'     => (int) get_post_meta($event_id, '_wcpo_chapter_id', true),
            'ai_action_id'   => get_post_meta($event_id, '_wcpo_ai_action_id', true),
        );
    }

    /** Source ids backing an event, via its member facts (for footnotes). */
    public static function source_ids($event_id) {
        $meta = self::meta($event_id);
        if (!$meta) {
            return array();
        }
        $source_ids = array();
        foreach ($meta['fact_ids'] as $fact_id) {
            $sid = (int) get_post_meta($fact_id, '_wcpo_source_id', true);
            if ($sid) {
                $source_ids[] = $sid;
            }
        }
        return array_values(array_unique($source_ids));
    }

    /**
     * Human decision — the ONLY status transition. Accept stamps member
     * facts; dismiss frees them for re-consolidation.
     */
    public static function decide($event_id, $decision, $reason = '') {
        $meta = self::meta($event_id);
        if (!$meta) {
            return new WP_Error('not_found', __('Event not found.', 'wcp-openbiografy'));
        }
        $new_status = $decision === 'accept' ? 'wcpo_accepted' : 'wcpo_dismissed';
        wp_update_post(array('ID' => (int) $event_id, 'post_status' => $new_status));

        if ($decision === 'accept') {
            foreach ($meta['fact_ids'] as $fact_id) {
                update_post_meta($fact_id, '_wcpo_event_id', (int) $event_id);
            }
        } elseif ($reason) {
            update_post_meta($event_id, '_wcpo_dismiss_reason', sanitize_text_field($reason));
        }

        if (wcpo_copilot_active()) {
            $action_id = $meta['ai_action_id'];
            if ($action_id) {
                $logger = WCP_AI_Logger::instance();
                $row = $logger->get_action($action_id);
                $accepted_items  = $row && is_array($row['accepted_items']) ? $row['accepted_items'] : array();
                $dismissed_items = $row && is_array($row['dismissed_items']) ? $row['dismissed_items'] : array();
                if ($decision === 'accept') {
                    $accepted_items[] = (int) $event_id;
                } else {
                    $dismissed_items[] = (int) $event_id;
                }
                $logger->log_decisions($action_id, array_values(array_unique($accepted_items)), array_values(array_unique($dismissed_items)));
            }
        }
        return true;
    }

    /** Human edit with diff logging. Editable: title, description, date_edtf, place, kind, contested, contested_note, importance. */
    public static function edit($event_id, array $changes) {
        $before = array();
        $after  = array();
        $post   = get_post($event_id);
        if (!$post || $post->post_type !== 'wcpo_event') {
            return new WP_Error('not_found', __('Event not found.', 'wcp-openbiografy'));
        }

        $post_fields = array();
        if (array_key_exists('title', $changes) && trim((string) $changes['title']) !== '') {
            $before['title'] = $post->post_title;
            $post_fields['post_title'] = sanitize_text_field($changes['title']);
            $after['title'] = $post_fields['post_title'];
        }
        if (array_key_exists('description', $changes)) {
            $before['description'] = $post->post_content;
            $post_fields['post_content'] = sanitize_textarea_field($changes['description']);
            $after['description'] = $post_fields['post_content'];
        }
        if ($post_fields) {
            $post_fields['ID'] = (int) $event_id;
            wp_update_post($post_fields);
        }

        if (array_key_exists('date_edtf', $changes)) {
            $edtf = trim((string) $changes['date_edtf']);
            if (!WCPO_EDTF::is_valid($edtf)) {
                return new WP_Error('bad_edtf', sprintf(__('Invalid EDTF date: %s', 'wcp-openbiografy'), $edtf));
            }
            $before['date_edtf'] = get_post_meta($event_id, '_wcpo_date_edtf', true);
            $range = WCPO_EDTF::to_sort_range($edtf);
            update_post_meta($event_id, '_wcpo_date_edtf', $edtf);
            update_post_meta($event_id, '_wcpo_date_sort_start', $range['start']);
            update_post_meta($event_id, '_wcpo_date_sort_end', $range['end']);
            $after['date_edtf'] = $edtf;
        }

        if (array_key_exists('place', $changes)) {
            $before['place'] = get_post_meta($event_id, '_wcpo_place', true);
            $value = sanitize_text_field($changes['place']);
            update_post_meta($event_id, '_wcpo_place', $value);
            $after['place'] = $value;
        }

        if (array_key_exists('contested', $changes)) {
            $before['contested'] = get_post_meta($event_id, '_wcpo_contested', true);
            update_post_meta($event_id, '_wcpo_contested', empty($changes['contested']) ? 0 : 1);
            $after['contested'] = empty($changes['contested']) ? 0 : 1;
        }
        if (array_key_exists('contested_note', $changes)) {
            $before['contested_note'] = get_post_meta($event_id, '_wcpo_contested_note', true);
            $value = sanitize_text_field($changes['contested_note']);
            update_post_meta($event_id, '_wcpo_contested_note', $value);
            $after['contested_note'] = $value;
        }
        if (array_key_exists('importance', $changes)) {
            $before['importance'] = get_post_meta($event_id, '_wcpo_importance', true);
            $value = max(0, min(1, (float) $changes['importance']));
            update_post_meta($event_id, '_wcpo_importance', $value);
            $after['importance'] = $value;
        }
        if (array_key_exists('kind', $changes) && in_array($changes['kind'], wcpo_kinds(), true)) {
            $kinds = wp_get_object_terms($event_id, 'wcpo_kind', array('fields' => 'names'));
            $before['kind'] = $kinds && !is_wp_error($kinds) ? $kinds[0] : '';
            wp_set_object_terms($event_id, $changes['kind'], 'wcpo_kind');
            $after['kind'] = $changes['kind'];
        }

        if ($after && wcpo_copilot_active()) {
            WCP_AI_Logger::instance()->log_action('wcpo_human_edit', array(
                'input_context'   => array('before' => $before),
                'output'          => array('after' => $after),
                'context_post_id' => (int) $event_id,
            ));
        }
        return $after;
    }

    public static function proposed($person_id) {
        return get_posts(array(
            'post_type'      => 'wcpo_event',
            'post_status'    => 'wcpo_proposed',
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_key'       => '_wcpo_person_id',
            'meta_value'     => (int) $person_id,
        ));
    }

    /** Accepted events in chronological order (dated first, undated last). */
    public static function accepted($person_id) {
        $posts = get_posts(array(
            'post_type'      => 'wcpo_event',
            'post_status'    => 'wcpo_accepted',
            'posts_per_page' => -1,
            'meta_key'       => '_wcpo_person_id',
            'meta_value'     => (int) $person_id,
        ));
        usort($posts, function ($a, $b) {
            $sa = get_post_meta($a->ID, '_wcpo_date_sort_start', true);
            $sb = get_post_meta($b->ID, '_wcpo_date_sort_start', true);
            if ($sa === $sb) {
                return $a->ID <=> $b->ID;
            }
            if ($sa === '') {
                return 1; // undated last
            }
            if ($sb === '') {
                return -1;
            }
            return strcmp($sa, $sb);
        });
        return $posts;
    }

    /** Accepted events assigned to a chapter, chronological. */
    public static function for_chapter($chapter_id) {
        $chapter = get_post($chapter_id);
        if (!$chapter) {
            return array();
        }
        $person_id = (int) get_post_meta($chapter_id, '_wcpo_person_id', true);
        return array_values(array_filter(self::accepted($person_id), function ($post) use ($chapter_id) {
            return (int) get_post_meta($post->ID, '_wcpo_chapter_id', true) === (int) $chapter_id;
        }));
    }

    /** Accepted events not yet assigned to any chapter. */
    public static function unassigned($person_id) {
        return array_values(array_filter(self::accepted($person_id), function ($post) {
            return !(int) get_post_meta($post->ID, '_wcpo_chapter_id', true);
        }));
    }

    /** Fact ids referenced by still-proposed events — excluded from re-consolidation. */
    public static function fact_ids_in_proposed($person_id) {
        $ids = array();
        foreach (self::proposed($person_id) as $post) {
            $fact_ids = json_decode((string) get_post_meta($post->ID, '_wcpo_fact_ids', true), true);
            if (is_array($fact_ids)) {
                $ids = array_merge($ids, array_map('intval', $fact_ids));
            }
        }
        return array_values(array_unique($ids));
    }

    public static function counts($person_id) {
        $counts = array();
        foreach (array('wcpo_proposed', 'wcpo_accepted', 'wcpo_dismissed') as $status) {
            $posts = get_posts(array(
                'post_type'      => 'wcpo_event',
                'post_status'    => $status,
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_key'       => '_wcpo_person_id',
                'meta_value'     => (int) $person_id,
            ));
            $counts[str_replace('wcpo_', '', $status)] = count($posts);
        }
        $contested = get_posts(array(
            'post_type'      => 'wcpo_event',
            'post_status'    => 'wcpo_accepted',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array('key' => '_wcpo_person_id', 'value' => (int) $person_id),
                array('key' => '_wcpo_contested', 'value' => 1),
            ),
        ));
        $counts['contested'] = count($contested);
        return $counts;
    }
}
