<?php
/**
 * Chapter repository. A chapter is a derived narrative section (the PRD's
 * NarrativeSection / life phase): human-created structure, AI-drafted prose.
 *
 * The pending AI draft lives in _wcpo_draft_proposal until a human accepts
 * it into post_content (with [eNNN] citation markers validated) or dismisses
 * it. Native draft/publish status controls frontend visibility.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPO_Chapter_Repo {

    public static function create($person_id, $title, $period_edtf = '') {
        $period_edtf = trim((string) $period_edtf);
        if (!WCPO_EDTF::is_valid($period_edtf)) {
            return new WP_Error('bad_edtf', sprintf(__('Invalid EDTF period: %s', 'wcp-openbiografy'), $period_edtf));
        }
        $existing = self::list_for_person($person_id);

        $post_id = wp_insert_post(array(
            'post_type'   => 'wcpo_chapter',
            'post_status' => 'draft',
            'post_title'  => sanitize_text_field($title),
            'menu_order'  => count($existing) + 1,
        ), true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        $range = WCPO_EDTF::to_sort_range($period_edtf);
        update_post_meta($post_id, '_wcpo_person_id', (int) $person_id);
        update_post_meta($post_id, '_wcpo_period_edtf', $period_edtf);
        update_post_meta($post_id, '_wcpo_period_sort_start', $range['start']);
        update_post_meta($post_id, '_wcpo_period_sort_end', $range['end']);
        return $post_id;
    }

    public static function update($chapter_id, array $fields) {
        $post_fields = array('ID' => (int) $chapter_id);
        if (!empty($fields['title'])) {
            $post_fields['post_title'] = sanitize_text_field($fields['title']);
        }
        if (isset($fields['publish'])) {
            $post_fields['post_status'] = $fields['publish'] ? 'publish' : 'draft';
        }
        wp_update_post($post_fields);

        if (array_key_exists('period_edtf', $fields)) {
            $period = trim((string) $fields['period_edtf']);
            if (!WCPO_EDTF::is_valid($period)) {
                return new WP_Error('bad_edtf', sprintf(__('Invalid EDTF period: %s', 'wcp-openbiografy'), $period));
            }
            $range = WCPO_EDTF::to_sort_range($period);
            update_post_meta($chapter_id, '_wcpo_period_edtf', $period);
            update_post_meta($chapter_id, '_wcpo_period_sort_start', $range['start']);
            update_post_meta($chapter_id, '_wcpo_period_sort_end', $range['end']);
        }
        return true;
    }

    public static function reorder($person_id, array $ordered_ids) {
        $order = 1;
        foreach ($ordered_ids as $chapter_id) {
            $post = get_post((int) $chapter_id);
            if ($post && $post->post_type === 'wcpo_chapter'
                && (int) get_post_meta($post->ID, '_wcpo_person_id', true) === (int) $person_id) {
                wp_update_post(array('ID' => $post->ID, 'menu_order' => $order));
                $order++;
            }
        }
        return true;
    }

    public static function meta($chapter_id) {
        $post = get_post($chapter_id);
        if (!$post || $post->post_type !== 'wcpo_chapter') {
            return null;
        }
        return array(
            'id'              => (int) $chapter_id,
            'title'           => $post->post_title,
            'narrative'       => $post->post_content,
            'status'          => $post->post_status,
            'order'           => (int) $post->menu_order,
            'person_id'       => (int) get_post_meta($chapter_id, '_wcpo_person_id', true),
            'period_edtf'     => get_post_meta($chapter_id, '_wcpo_period_edtf', true),
            'period_display'  => WCPO_EDTF::format(get_post_meta($chapter_id, '_wcpo_period_edtf', true)),
            'draft_proposal'  => get_post_meta($chapter_id, '_wcpo_draft_proposal', true),
            'draft_action_id' => get_post_meta($chapter_id, '_wcpo_draft_ai_action_id', true),
            'draft_created'   => get_post_meta($chapter_id, '_wcpo_draft_created_at', true),
        );
    }

    /** Chapters for a person, by menu_order (published + draft). */
    public static function list_for_person($person_id, $published_only = false) {
        return get_posts(array(
            'post_type'      => 'wcpo_chapter',
            'post_status'    => $published_only ? 'publish' : array('publish', 'draft'),
            'posts_per_page' => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
            'meta_key'       => '_wcpo_person_id',
            'meta_value'     => (int) $person_id,
        ));
    }

    /** Store a pending AI narrative draft (proposal, not content). */
    public static function set_draft($chapter_id, $text, $action_id) {
        update_post_meta($chapter_id, '_wcpo_draft_proposal', wp_kses_post($text));
        update_post_meta($chapter_id, '_wcpo_draft_ai_action_id', sanitize_text_field((string) $action_id));
        update_post_meta($chapter_id, '_wcpo_draft_created_at', current_time('mysql', true));
    }

    /**
     * Human accepts the (possibly edited) draft into post_content.
     * Citation markers [eNNN] not belonging to this chapter's accepted events
     * are stripped and reported back as warnings — the narrative may only
     * cite evidence it was given.
     */
    public static function accept_draft($chapter_id, $text) {
        $meta = self::meta($chapter_id);
        if (!$meta) {
            return new WP_Error('not_found', __('Chapter not found.', 'wcp-openbiografy'));
        }

        $valid_ids = array_map(function ($post) {
            return (int) $post->ID;
        }, WCPO_Event_Repo::for_chapter($chapter_id));

        $warnings = array();
        $text = preg_replace_callback('/\[e(\d+)\]/', function ($m) use ($valid_ids, &$warnings) {
            $id = (int) $m[1];
            if (!in_array($id, $valid_ids, true)) {
                $warnings[] = sprintf(__('Removed citation [e%d]: not an accepted event in this chapter.', 'wcp-openbiografy'), $id);
                return '';
            }
            return $m[0];
        }, (string) $text);

        wp_update_post(array('ID' => (int) $chapter_id, 'post_content' => wp_kses_post($text)));
        self::record_draft_decision($meta, 'accept');
        self::clear_draft($chapter_id);
        update_option('wcpo_last_draft_warnings', $warnings, false);
        return $warnings;
    }

    public static function dismiss_draft($chapter_id, $reason = '') {
        $meta = self::meta($chapter_id);
        if (!$meta) {
            return new WP_Error('not_found', __('Chapter not found.', 'wcp-openbiografy'));
        }
        self::record_draft_decision($meta, 'dismiss' . ($reason ? ':' . $reason : ''));
        self::clear_draft($chapter_id);
        return true;
    }

    private static function clear_draft($chapter_id) {
        delete_post_meta($chapter_id, '_wcpo_draft_proposal');
        delete_post_meta($chapter_id, '_wcpo_draft_ai_action_id');
        delete_post_meta($chapter_id, '_wcpo_draft_created_at');
    }

    private static function record_draft_decision(array $meta, $decision) {
        if (!wcpo_copilot_active() || !$meta['draft_action_id']) {
            return;
        }
        $logger = WCP_AI_Logger::instance();
        if (strpos($decision, 'accept') === 0) {
            $logger->log_decisions($meta['draft_action_id'], array((int) $meta['id']), array());
        } else {
            $logger->log_decisions($meta['draft_action_id'], array(), array((int) $meta['id']));
        }
    }
}
