<?php
/**
 * Public person page: template loading, footnote rendering and schema.org
 * JSON-LD. Only ACCEPTED events/facts and PUBLISHED chapters ever render —
 * proposals are admin-only by construction.
 *
 * Footnote numbering dedupes by source: every citation of the same source,
 * whether from a chapter narrative or a timeline entry, shares one number.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPO_Frontend {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('template_include', array($this, 'template'));
        add_action('wp_enqueue_scripts', array($this, 'assets'));
        add_action('wp_head', array($this, 'json_ld'));
    }

    /** Plugin template for single people unless the theme overrides it. */
    public function template($template) {
        if (is_singular('wcpo_person') && !locate_template('single-wcpo_person.php')) {
            return WCPO_PLUGIN_DIR . 'templates/single-wcpo_person.php';
        }
        return $template;
    }

    public function assets() {
        if (is_singular('wcpo_person')) {
            wp_enqueue_style('wcpo-frontend', WCPO_PLUGIN_URL . 'assets/frontend.css', array(), WCPO_VERSION);
        }
    }

    // ------------------------------------------------------------- Footnotes

    /**
     * Page-wide source → footnote-number map, in first-citation order:
     * published chapters first (reading order), then the timeline.
     */
    public static function source_map($person_id) {
        $map = array();
        $n   = 0;
        $seen_event_sources = function ($event_id) use (&$map, &$n) {
            foreach (WCPO_Event_Repo::source_ids($event_id) as $sid) {
                if (!isset($map[$sid])) {
                    $map[$sid] = ++$n;
                }
            }
        };

        foreach (WCPO_Chapter_Repo::list_for_person($person_id, true) as $chapter) {
            if (preg_match_all('/\[e(\d+)\]/', $chapter->post_content, $m)) {
                foreach ($m[1] as $event_id) {
                    $seen_event_sources((int) $event_id);
                }
            }
        }
        foreach (WCPO_Event_Repo::accepted($person_id) as $event) {
            $seen_event_sources((int) $event->ID);
        }
        foreach (WCPO_Fact_Repo::accepted_dated($person_id) as $fact) {
            $sid = (int) get_post_meta($fact->ID, '_wcpo_source_id', true);
            if ($sid && !isset($map[$sid])) {
                $map[$sid] = ++$n;
            }
        }
        return $map;
    }

    /** Superscript footnote links for one event's sources. */
    public static function footnote_sup($event_id, array $map) {
        $links = array();
        foreach (WCPO_Event_Repo::source_ids($event_id) as $sid) {
            if (isset($map[$sid])) {
                $links[] = '<a href="#wcpo-fn-' . (int) $map[$sid] . '">' . (int) $map[$sid] . '</a>';
            }
        }
        return $links ? '<sup class="wcpo-fn">[' . implode(',', $links) . ']</sup>' : '';
    }

    /**
     * Chapter narrative with [eNNN] markers converted to footnote links.
     * Markers pointing at events that are no longer accepted are dropped.
     */
    public static function render_narrative($content, array $map) {
        $html = preg_replace_callback('/\s*\[e(\d+)\]/', function ($m) use ($map) {
            $event = WCPO_Event_Repo::meta((int) $m[1]);
            if (!$event || $event['status'] !== 'wcpo_accepted') {
                return '';
            }
            return self::footnote_sup((int) $m[1], $map);
        }, (string) $content);
        return wpautop($html);
    }

    /** Ordered footnote list items: number => source meta. */
    public static function footnotes(array $map) {
        asort($map);
        $notes = array();
        foreach ($map as $sid => $n) {
            $source = WCPO_Source_Repo::meta($sid);
            if ($source) {
                $notes[$n] = $source;
            }
        }
        return $notes;
    }

    // --------------------------------------------------------------- JSON-LD

    /** schema.org Person + Event graph, only clean ISO-expressible dates. */
    public function json_ld() {
        if (!is_singular('wcpo_person')) {
            return;
        }
        $person_id = get_the_ID();
        $person = WCPO_Person_Repo::meta($person_id);
        if (!$person) {
            return;
        }

        $node = array(
            '@type' => 'Person',
            '@id'   => $person['url'] . '#person',
            'name'  => $person['name'],
            'url'   => $person['url'],
        );
        if ($person['bio']) {
            $node['description'] = wp_strip_all_tags($person['bio']);
        }
        if ($person['occupation']) {
            $node['jobTitle'] = $person['occupation'];
        }
        $birth = WCPO_EDTF::to_iso($person['birth_edtf']);
        if ($birth) {
            $node['birthDate'] = $birth;
        }
        $death = WCPO_EDTF::to_iso($person['death_edtf']);
        if ($death) {
            $node['deathDate'] = $death;
        }
        if ($person['birth_place']) {
            $node['birthPlace'] = array('@type' => 'Place', 'name' => $person['birth_place']);
        }
        if ($person['death_place']) {
            $node['deathPlace'] = array('@type' => 'Place', 'name' => $person['death_place']);
        }
        $image = get_the_post_thumbnail_url($person_id, 'large');
        if ($image) {
            $node['image'] = $image;
        }

        $graph = array($node);
        foreach (WCPO_Event_Repo::accepted($person_id) as $post) {
            $e = WCPO_Event_Repo::meta($post->ID);
            $iso = WCPO_EDTF::to_iso($e['date_edtf']);
            if (!$iso) {
                continue; // fuzzy dates are not presented as exact
            }
            $event_node = array(
                '@type'     => 'Event',
                'name'      => $e['title'],
                'startDate' => $iso,
                'about'     => array('@id' => $person['url'] . '#person'),
            );
            if ($e['description']) {
                $event_node['description'] = $e['description'];
            }
            if ($e['place']) {
                $event_node['location'] = array('@type' => 'Place', 'name' => $e['place']);
            }
            $graph[] = $event_node;
        }

        echo '<script type="application/ld+json">'
            . wp_json_encode(array('@context' => 'https://schema.org', '@graph' => $graph), JSON_UNESCAPED_SLASHES)
            . '</script>' . "\n";
    }
}
