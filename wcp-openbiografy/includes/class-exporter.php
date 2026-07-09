<?php
/**
 * Full-project JSON export — data portability (PRD NFR7). Everything the
 * plugin knows about one person: person, sources with citations, facts,
 * events, chapters. Dismissed items are included for auditability.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPO_Exporter {

    public static function project_json($person_id) {
        $person = WCPO_Person_Repo::meta($person_id);
        if (!$person) {
            return new WP_Error('not_found', __('Person not found.', 'wcp-openbiografy'));
        }

        $sources = array();
        foreach (WCPO_Source_Repo::list_for_person($person_id) as $post) {
            $sources[] = WCPO_Source_Repo::meta($post->ID);
        }

        $facts = array();
        foreach (WCPO_Fact_Repo::STATUSES as $status) {
            $posts = get_posts(array(
                'post_type'      => 'wcpo_fact',
                'post_status'    => $status,
                'posts_per_page' => -1,
                'meta_key'       => '_wcpo_person_id',
                'meta_value'     => (int) $person_id,
            ));
            foreach ($posts as $post) {
                $facts[] = WCPO_Fact_Repo::meta($post->ID);
            }
        }

        $events = array();
        foreach (array('wcpo_proposed', 'wcpo_accepted', 'wcpo_dismissed') as $status) {
            $posts = get_posts(array(
                'post_type'      => 'wcpo_event',
                'post_status'    => $status,
                'posts_per_page' => -1,
                'meta_key'       => '_wcpo_person_id',
                'meta_value'     => (int) $person_id,
            ));
            foreach ($posts as $post) {
                $events[] = WCPO_Event_Repo::meta($post->ID);
            }
        }

        $chapters = array();
        foreach (WCPO_Chapter_Repo::list_for_person($person_id) as $post) {
            $chapters[] = WCPO_Chapter_Repo::meta($post->ID);
        }

        return array(
            'format'      => 'openbiografy/v1',
            'exported_at' => current_time('mysql', true),
            'site'        => home_url(),
            'person'      => $person,
            'sources'     => $sources,
            'facts'       => $facts,
            'events'      => $events,
            'chapters'    => $chapters,
        );
    }
}
