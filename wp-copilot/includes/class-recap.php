<?php
/**
 * Recap: a saved, read-only snapshot of the corpus (areas, composition,
 * recent activity) plus AI-written commentary and next-focus suggestions.
 *
 * - All numbers are computed here, server-side. The AI only writes text.
 * - AI guardrail: the AI returns strict JSON that is display-only. It never
 *   writes to the corpus; the only thing created is the wcp_recap snapshot,
 *   and only when the user clicks Generate (no cron/background generation).
 * - Snapshots store the data (JSON), not HTML, so the template can improve
 *   later and old recaps re-render with it.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_Recap {

    const POST_TYPE  = 'wcp_recap';
    const META_KEY   = '_wcp_recap_data';
    const KEEP_LAST  = 30;
    const MAX_AREAS  = 24;  // bounds the AI prompt and the snapshot
    const STALE_DAYS = 35;

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'register_post_type'));
    }

    public function register_post_type() {
        register_post_type(self::POST_TYPE, array(
            'labels'       => array('name' => __('Recaps', 'work-copilot'), 'singular_name' => __('Recap', 'work-copilot')),
            'public'       => false,
            'show_ui'      => false,
            'show_in_rest' => false,
            'supports'     => array('title'),
        ));
    }

    // ------------------------------------------------------------------
    // REST handlers (routes registered in WCP_REST_API::register_routes)
    // ------------------------------------------------------------------

    public function rest_list($request) {
        $posts = get_posts(array(
            'post_type' => self::POST_TYPE, 'post_status' => 'publish',
            'posts_per_page' => self::KEEP_LAST, 'orderby' => 'date', 'order' => 'DESC',
        ));
        $out = array();
        foreach ($posts as $p) {
            $data = get_post_meta($p->ID, self::META_KEY, true);
            $out[] = array(
                'id'      => $p->ID,
                'date'    => get_the_date('j M Y, H:i', $p),
                'totals'  => is_array($data) && isset($data['totals']) ? $data['totals'] : null,
                'ai_used' => is_array($data) && !empty($data['ai_used']),
            );
        }
        return rest_ensure_response(array('success' => true, 'recaps' => $out));
    }

    public function rest_get($request) {
        $post = $this->get_recap_post((int) $request['id']);
        if (is_wp_error($post)) {
            return $post;
        }
        $data = get_post_meta($post->ID, self::META_KEY, true);
        if (!is_array($data)) {
            return new WP_Error('recap_empty', 'Recap data is missing', array('status' => 404));
        }
        return rest_ensure_response(array(
            'success' => true,
            'id'      => $post->ID,
            'date'    => get_the_date('j M Y, H:i', $post),
            'html'    => $this->render($data),
        ));
    }

    public function rest_delete($request) {
        $post = $this->get_recap_post((int) $request['id']);
        if (is_wp_error($post)) {
            return $post;
        }
        wp_delete_post($post->ID, true);
        return rest_ensure_response(array('success' => true));
    }

    public function rest_create($request) {
        set_time_limit(120);
        $data = $this->collect();
        if (empty($data['areas'])) {
            return new WP_Error('no_pages', 'There are no top-level pages to recap yet', array('status' => 400));
        }

        $ai_error = null;
        if (get_option('wcp_ai_enabled', false) && WCP_AI_Client::instance()->is_configured()) {
            $result = $this->add_ai_commentary($data);
            if (is_wp_error($result)) {
                // Still save the stats-only snapshot; tell the user why the text is missing.
                $ai_error = $result->get_error_message();
            }
        }
        if (empty($data['ai_used'])) {
            $this->add_fallback_commentary($data);
        }

        // Prompt-only fields don't belong in the saved snapshot.
        foreach ($data['areas'] as &$a) {
            unset($a['content_excerpt'], $a['recent_items'], $a['modified_ts']);
        }
        unset($a);

        $post_id = wp_insert_post(array(
            'post_type'   => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title'  => 'Recap — ' . current_time('Y-m-d H:i'),
            'post_author' => get_current_user_id(),
        ), true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        update_post_meta($post_id, self::META_KEY, wp_slash($data));
        $this->prune();

        return rest_ensure_response(array(
            'success'  => true,
            'id'       => $post_id,
            'ai_error' => $ai_error,
        ));
    }

    private function get_recap_post($id) {
        $post = $id ? get_post($id) : null;
        if (!$post || $post->post_type !== self::POST_TYPE) {
            return new WP_Error('not_found', 'Recap not found', array('status' => 404));
        }
        return $post;
    }

    private function prune() {
        // Slice in PHP: WP_Query ignores `offset` when posts_per_page is -1, which would return (and delete) everything.
        $ids = get_posts(array(
            'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'fields' => 'ids',
            'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'DESC',
        ));
        foreach (array_slice($ids, self::KEEP_LAST) as $id) {
            wp_delete_post($id, true);
        }
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    /** Fixed template + data embedded as inert JSON (tag/quote-escaped, so it can't break out of the script). */
    public function render($data) {
        $tpl = file_get_contents(WCP_PLUGIN_DIR . 'templates/recap.html');
        $json = wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        return str_replace('/*__WCP_RECAP_DATA__*/null', $json, $tpl);
    }

    // ------------------------------------------------------------------
    // Data pack (no AI)
    // ------------------------------------------------------------------

    public function collect() {
        global $wpdb;
        $now = current_time('timestamp');

        $pages = get_posts(array(
            'post_type' => 'page', 'post_status' => 'publish', 'post_parent' => 0,
            'posts_per_page' => self::MAX_AREAS, 'orderby' => 'menu_order title', 'order' => 'ASC',
        ));

        $areas = array();
        foreach ($pages as $page) {
            $area = $this->collect_area($page, $now);
            if ($area) {
                $areas[] = $area;
            }
        }

        // Thin = well below the typical area; stale = untouched for a while.
        $counts = array_column($areas, 'items');
        sort($counts);
        $median = $counts ? $counts[(int) floor(count($counts) / 2)] : 0;
        foreach ($areas as &$a) {
            $a['flag'] = '';
            if ($a['items'] > 0 && $a['modified_ts'] && ($now - $a['modified_ts']) > self::STALE_DAYS * DAY_IN_SECONDS) {
                $a['flag'] = 'stale';
            } elseif ($median && $a['items'] < 0.4 * $median) {
                $a['flag'] = 'thin';
            }
        }
        unset($a);

        $total_words = 0;
        foreach ($areas as $a) {
            $total_words += $a['words'];
        }

        // Edits per day, last 30 days (oldest → newest, zero-filled).
        $since = gmdate('Y-m-d 00:00:00', $now - 29 * DAY_IN_SECONDS);
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(post_modified) d, COUNT(*) c FROM {$wpdb->posts}
             WHERE post_type = 'post' AND post_status = 'publish' AND post_modified >= %s GROUP BY d", $since), OBJECT_K);
        $daily = array();
        for ($i = 29; $i >= 0; $i--) {
            $day = wp_date('Y-m-d', $now - $i * DAY_IN_SECONDS);
            $daily[] = isset($rows[$day]) ? (int) $rows[$day]->c : 0;
        }

        $recent_areas = array();
        foreach ($areas as $a) {
            if ($a['w7'] + $a['n30'] > 0) {
                $recent_areas[] = array($a['name'], array('w' => $a['w7'], 'n' => $a['n30']));
            }
        }
        usort($recent_areas, function ($x, $y) { return ($y[1]['w'] + $y[1]['n']) <=> ($x[1]['w'] + $x[1]['n']); });

        return array(
            'generated'   => wp_date('j M Y, H:i', $now),
            'ai_used'     => false,
            'totals'      => array(
                'pages'    => (int) wp_count_posts('page')->publish,
                'headings' => (int) wp_count_posts('wcp_heading')->publish,
                'items'    => (int) wp_count_posts('post')->publish,
                'words'    => $total_words,
            ),
            'areas'        => $areas,
            'types'        => $this->term_counts('item_type'),
            'status'       => $this->term_counts('task_status'),
            'daily30'      => $daily,
            'recentAreas'  => array_slice($recent_areas, 0, 5),
            'ai'           => $this->ai_decision_counts($now),
            'summary'      => '',
            'highlights'   => array(),
            'suggestions'  => array(),
        );
    }

    private function collect_area($page, $now) {
        $term_id = $this->context_term_id('page', $page->ID);
        if (!$term_id) {
            return null;
        }

        // Everything under this page's term, including sub-pages and headings.
        $item_ids = get_posts(array(
            'post_type' => 'post', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1,
            'tax_query' => array(array('taxonomy' => 'wcp_context', 'field' => 'term_id', 'terms' => $term_id, 'include_children' => true)),
        ));
        $words = 0; $latest = null; $w7 = 0; $n30 = 0;
        $recent = array();
        foreach ($item_ids as $id) {
            $p = get_post($id);
            $words += str_word_count(wp_strip_all_tags($p->post_content));
            $ts = strtotime($p->post_modified);
            if (!$latest || $ts > $latest['ts']) {
                $latest = array('ts' => $ts, 'title' => $p->post_title);
            }
            if ($ts >= $now - 7 * DAY_IN_SECONDS) {
                $w7++;
            } elseif ($ts >= $now - 30 * DAY_IN_SECONDS) {
                $n30++;
            }
            if ($ts >= $now - 30 * DAY_IN_SECONDS) {
                $recent[] = array('ts' => $ts, 'title' => $p->post_title, 'excerpt' => mb_substr(wp_strip_all_tags($p->post_content), 0, 120));
            }
        }
        usort($recent, function ($a, $b) { return $b['ts'] <=> $a['ts']; });

        // Headings directly under the page, plus its page-level items.
        $headings = array();
        $heading_posts = get_posts(array(
            'post_type' => 'wcp_heading', 'post_status' => 'publish', 'posts_per_page' => 40,
            'orderby' => 'menu_order', 'order' => 'ASC',
            'meta_query' => array(array('key' => '_wcp_parent_type', 'value' => 'page'), array('key' => '_wcp_parent_id', 'value' => $page->ID, 'type' => 'NUMERIC')),
        ));
        foreach ($heading_posts as $h) {
            $htid = $this->context_term_id('wcp_heading', $h->ID);
            $headings[] = array_merge(array($h->post_title), $htid ? $this->term_item_stats($htid) : array(0, 0));
        }
        $page_level = $this->term_item_stats($term_id, false);
        if ($page_level[0] > 0) {
            $headings[] = array_merge(array('(page level)'), $page_level);
        }

        // Direct sub-pages (items roll up from deeper pages/headings) → latest-activity and most-content lists.
        $subs = array();
        $children = get_posts(array(
            'post_type' => 'page', 'post_status' => 'publish', 'post_parent' => $page->ID,
            'posts_per_page' => 100, 'orderby' => 'menu_order title', 'order' => 'ASC',
        ));
        foreach ($children as $child) {
            $ctid = $this->context_term_id('page', $child->ID);
            if (!$ctid) {
                continue;
            }
            list($n, $ts) = $this->term_activity($ctid, true);
            if ($n > 0) {
                $subs[] = array('name' => $child->post_title, 'url' => get_permalink($child->ID), 'items' => $n, 'ts' => $ts);
            }
        }
        list($own_n, $own_ts) = $this->term_activity($term_id, false);
        if ($own_n > 0 && $subs) { // only meaningful alongside sub-pages
            $subs[] = array('name' => '(this page)', 'url' => get_permalink($page->ID), 'items' => $own_n, 'ts' => $own_ts);
        }
        $by_recent = $subs; usort($by_recent, function ($a, $b) { return $b['ts'] <=> $a['ts']; });
        $by_items  = $subs; usort($by_items,  function ($a, $b) { return $b['items'] <=> $a['items']; });
        $recent_subs = array(); $top_subs = array();
        foreach (array_slice($by_recent, 0, 3) as $x) {
            $recent_subs[] = array($x['name'], $x['url'], human_time_diff($x['ts'], $now) . ' ago');
        }
        foreach (array_slice($by_items, 0, 3) as $x) {
            $top_subs[] = array($x['name'], $x['url'], $x['items']);
        }
        $descendants = get_pages(array('child_of' => $page->ID, 'post_status' => 'publish', 'number' => 0));

        return array(
            'page_id'     => $page->ID,
            'name'        => $page->post_title,
            'subpages'    => is_array($descendants) ? count($descendants) : 0,
            'recent_subs' => $recent_subs,
            'top_subs'    => $top_subs,
            'url'         => get_permalink($page->ID),
            'items'       => count($item_ids),
            'words'       => $words,
            'headings'    => $headings,
            'modified_ts' => $latest ? $latest['ts'] : 0,
            'when'        => $latest ? human_time_diff($latest['ts'], $now) . ' ago' : '',
            'about'       => '',
            'latest'      => '',
            'w7'          => $w7,
            'n30'         => $n30,
            // Not shown in the template — bounded context for the AI prompt only.
            'content_excerpt' => mb_substr(wp_strip_all_tags($page->post_content), 0, 200),
            'recent_items'    => array_slice($recent, 0, 6),
        );
    }

    /** [item count, latest modified timestamp] for one context term (cheap: one query, newest item only). */
    private function term_activity($term_id, $include_children) {
        $q = new WP_Query(array(
            'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 1,
            'orderby' => 'modified', 'order' => 'DESC', 'no_found_rows' => false,
            'tax_query' => array(array('taxonomy' => 'wcp_context', 'field' => 'term_id', 'terms' => $term_id, 'include_children' => $include_children)),
        ));
        return array((int) $q->found_posts, $q->have_posts() ? strtotime($q->posts[0]->post_modified) : 0);
    }

    /** [item count, word count] for one term; optionally excluding descendant terms. */
    private function term_item_stats($term_id, $include_children = false) {
        $ids = get_posts(array(
            'post_type' => 'post', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1,
            'tax_query' => array(array('taxonomy' => 'wcp_context', 'field' => 'term_id', 'terms' => $term_id, 'include_children' => $include_children)),
        ));
        $words = 0;
        foreach ($ids as $id) {
            $words += str_word_count(wp_strip_all_tags(get_post_field('post_content', $id)));
        }
        return array(count($ids), $words);
    }

    private function context_term_id($ref_type, $ref_id) {
        $terms = get_terms(array(
            'taxonomy' => 'wcp_context', 'hide_empty' => false, 'number' => 1,
            'meta_query' => array(array('key' => 'wcp_ref_type', 'value' => $ref_type), array('key' => 'wcp_ref_id', 'value' => $ref_id, 'type' => 'NUMERIC')),
        ));
        return (!is_wp_error($terms) && !empty($terms)) ? (int) $terms[0]->term_id : 0;
    }

    private function term_counts($taxonomy) {
        $terms = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC'));
        $out = array();
        if (!is_wp_error($terms)) {
            foreach ($terms as $t) {
                $out[] = array(html_entity_decode($t->name), (int) $t->count);
            }
        }
        return $out;
    }

    /** Accepted/dismissed AI proposal counts over the last 30 days (from the audit log, 90-day retention). */
    private function ai_decision_counts($now) {
        $accepted = 0; $dismissed = 0;
        $cutoff = $now - 30 * DAY_IN_SECONDS;
        foreach (WCP_AI_Logger::instance()->get_recent_actions(500) as $row) {
            if (strtotime($row['timestamp']) < $cutoff) {
                continue;
            }
            $accepted  += is_array($row['accepted_items'])  ? count($row['accepted_items'])  : 0;
            $dismissed += is_array($row['dismissed_items']) ? count($row['dismissed_items']) : 0;
        }
        return array('accepted' => $accepted, 'dismissed' => $dismissed);
    }

    // ------------------------------------------------------------------
    // AI commentary (strict JSON, display-only)
    // ------------------------------------------------------------------

    private function add_ai_commentary(&$data) {
        // Bounded context pack: per-area stats + a few recent titles. Never full content.
        $lines = array();
        foreach ($data['areas'] as $a) {
            $line = "PAGE [id:{$a['page_id']}] {$a['name']} — {$a['items']} items, {$a['words']} words"
                  . ($a['flag'] ? ", flagged {$a['flag']}" : '') . ($a['when'] ? ", last edited {$a['when']}" : '');
            if ($a['content_excerpt'] !== '') {
                $line .= "\n  Page text: {$a['content_excerpt']}";
            }
            if (!empty($a['recent_subs'])) {
                $line .= "\n  Recently active sub-pages: " . implode('; ', array_map(function ($r) { return "{$r[0]} ({$r[2]})"; }, $a['recent_subs']));
            }
            $hs = array();
            foreach ($a['headings'] as $h) {
                $hs[] = "{$h[0]} ({$h[1]} items, {$h[2]} words)";
            }
            if ($hs) {
                $line .= "\n  Headings: " . implode('; ', $hs);
            }
            foreach ($a['recent_items'] as $r) {
                $line .= "\n  Recent: \"{$r['title']}\" (" . human_time_diff($r['ts'], current_time('timestamp')) . " ago)"
                       . ($r['excerpt'] !== '' ? " — {$r['excerpt']}" : '');
            }
            $lines[] = $line;
        }
        $mission = WCP_Mission_Loader::instance()->get_global_mission();

        $sys = "You write a recap of a personal knowledge/work corpus for its owner. You are given each top-level page "
             . "with real counts, its headings, and recently edited items. Use ONLY facts in the data — never invent numbers or items. "
             . "Return ONLY a JSON object: {"
             . "\"summary\": \"3-5 sentences on where the content is concentrated and what has been happening\", "
             . "\"areas\": [{\"page_id\": <id from the list>, \"about\": \"one sentence on what this page seems to be about\", "
             . "\"latest\": \"short description of its latest activity (name the sub-pages involved if given), or empty if none\"}], "
             . "\"highlights\": [{\"text\": \"a notable recent change\", \"when\": \"e.g. 3 days ago\"}] (3-6, most recent first), "
             . "\"suggestions\": [{\"title\": \"...\", \"why\": \"...\", \"evidence\": \"the specific data points this rests on\", "
             . "\"page_id\": <id from the list>}] (3-5; prefer thin/stale areas, empty headings, and neglected dependencies)}. "
             . "Every page in the list needs one entry in areas.";
        $usr = "Corpus totals: {$data['totals']['pages']} pages, {$data['totals']['headings']} headings, "
             . "{$data['totals']['items']} items, {$data['totals']['words']} words.\n\n" . implode("\n\n", $lines)
             . ($mission ? "\n\nCopilot mission:\n{$mission}" : '');

        $response = WCP_AI_Client::instance()->request_with_conversation($sys, $usr, array(), 8192, 90);
        if (is_wp_error($response)) {
            return $response;
        }
        if (($response['stop_reason'] ?? null) === 'max_tokens') {
            return new WP_Error('response_truncated', 'The AI response was cut off, so this recap has statistics only.');
        }
        $parsed = WCP_AI_Actions::instance()->parse_json_response($response['content']);
        if (is_wp_error($parsed) || !is_array($parsed)) {
            return new WP_Error('invalid_response', 'The AI response could not be read, so this recap has statistics only.');
        }

        // Validate: only ids from the pack are honoured; everything is plain text (rendered escaped).
        $by_id = array();
        foreach ($data['areas'] as $i => $a) {
            $by_id[$a['page_id']] = $i;
        }
        foreach ((array) ($parsed['areas'] ?? array()) as $pa) {
            $pid = (int) ($pa['page_id'] ?? 0);
            if (isset($by_id[$pid])) {
                $data['areas'][$by_id[$pid]]['about']  = sanitize_text_field($pa['about'] ?? '');
                $data['areas'][$by_id[$pid]]['latest'] = sanitize_text_field($pa['latest'] ?? '');
            }
        }
        $data['summary'] = sanitize_textarea_field($parsed['summary'] ?? '');
        foreach ((array) ($parsed['highlights'] ?? array()) as $h) {
            if (!empty($h['text'])) {
                $data['highlights'][] = array(sanitize_text_field($h['text']), sanitize_text_field($h['when'] ?? ''));
            }
        }
        foreach ((array) ($parsed['suggestions'] ?? array()) as $s) {
            $pid = (int) ($s['page_id'] ?? 0);
            if (empty($s['title']) || !isset($by_id[$pid])) {
                continue; // unknown page → drop rather than guess
            }
            $area = $data['areas'][$by_id[$pid]];
            $data['suggestions'][] = array(
                'title' => sanitize_text_field($s['title']),
                'why'   => sanitize_textarea_field($s['why'] ?? ''),
                'ev'    => sanitize_text_field($s['evidence'] ?? ''),
                'link'  => $area['name'],
                'url'   => $area['url'],
            );
        }
        $data['ai_used'] = true;

        WCP_AI_Logger::instance()->log_action('recap', array(
            'model'         => $response['model'] ?? null,
            'prompt'        => $sys,
            'input_context' => array('areas' => count($data['areas']), 'totals' => $data['totals']),
            'output'        => $parsed,
        ));
        return true;
    }

    /** No-AI fallback: real facts only, no generated prose. */
    private function add_fallback_commentary(&$data) {
        $recent = array();
        foreach ($data['areas'] as &$a) {
            if (!empty($a['recent_items'])) {
                $a['latest'] = 'Edited “' . $a['recent_items'][0]['title'] . '”';
                foreach (array_slice($a['recent_items'], 0, 2) as $r) {
                    $recent[] = array('ts' => $r['ts'], 'text' => $r['title'] . ' — ' . $a['name']);
                }
            } elseif ($a['when']) {
                $a['latest'] = 'Last edit: ' . $a['when'];
            }
        }
        unset($a);
        usort($recent, function ($x, $y) { return $y['ts'] <=> $x['ts']; });
        foreach (array_slice($recent, 0, 6) as $r) {
            $data['highlights'][] = array($r['text'], human_time_diff($r['ts'], current_time('timestamp')) . ' ago');
        }
    }
}
