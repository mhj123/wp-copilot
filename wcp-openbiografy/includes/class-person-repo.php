<?php
/**
 * Person repository. The person post is the public page target; biographical
 * meta (EDTF dates, places, occupation) also feeds every extraction prompt
 * via context_block() so the model can disambiguate the subject.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPO_Person_Repo {

    const FIELDS = array('birth_edtf', 'death_edtf', 'birth_place', 'death_place', 'occupation', 'context_note');

    public static function create($name, array $fields = array()) {
        $post_id = wp_insert_post(array(
            'post_type'   => 'wcpo_person',
            'post_status' => 'publish',
            'post_title'  => sanitize_text_field($name),
        ), true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        self::update($post_id, $fields);
        return $post_id;
    }

    public static function update($person_id, array $fields) {
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }
            $value = sanitize_textarea_field((string) $fields[$field]);
            if (in_array($field, array('birth_edtf', 'death_edtf'), true) && !WCPO_EDTF::is_valid($value)) {
                return new WP_Error('bad_edtf', sprintf(__('Invalid EDTF date for %s: %s', 'wcp-openbiografy'), $field, $value));
            }
            update_post_meta($person_id, '_wcpo_' . $field, $value);
        }
        if (!empty($fields['name'])) {
            wp_update_post(array('ID' => (int) $person_id, 'post_title' => sanitize_text_field($fields['name'])));
        }
        return true;
    }

    public static function meta($person_id) {
        $post = get_post($person_id);
        if (!$post || $post->post_type !== 'wcpo_person') {
            return null;
        }
        $meta = array(
            'id'     => (int) $person_id,
            'name'   => $post->post_title,
            'slug'   => $post->post_name,
            'bio'    => $post->post_content,
            'url'    => get_permalink($post),
            'status' => $post->post_status,
        );
        foreach (self::FIELDS as $field) {
            $meta[$field] = get_post_meta($person_id, '_wcpo_' . $field, true);
        }
        return $meta;
    }

    /** All people, for the admin person selector. */
    public static function all() {
        return get_posts(array(
            'post_type'      => 'wcpo_person',
            'post_status'    => array('publish', 'draft', 'private'),
            'posts_per_page' => 100,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ));
    }

    /**
     * Bounded person block injected into every AI prompt — name, dates and the
     * free-text context note used to disambiguate (e.g. from namesakes).
     */
    public static function context_block($person_id) {
        $p = self::meta($person_id);
        if (!$p) {
            return '';
        }
        $lines = array('SUBJECT PERSON: ' . $p['name']);
        if ($p['birth_edtf'] || $p['death_edtf']) {
            $lines[] = 'Lived: ' . ($p['birth_edtf'] ?: '?') . ' – ' . ($p['death_edtf'] ?: '?');
        }
        if ($p['birth_place']) {
            $lines[] = 'Born in: ' . $p['birth_place'];
        }
        if ($p['occupation']) {
            $lines[] = 'Occupation: ' . $p['occupation'];
        }
        if ($p['context_note']) {
            $lines[] = 'Context: ' . $p['context_note'];
        }
        return implode("\n", $lines);
    }
}
