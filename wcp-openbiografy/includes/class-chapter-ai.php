<?php
/**
 * Chapter AI: event→chapter assignment suggestions and narrative drafting.
 *
 * Assignments are returned to the UI (pre-checked checklist), never
 * persisted here — the human's Apply click writes them via REST.
 * Narrative drafts are stored as chapter proposals, never as content.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPO_Chapter_AI {

    /**
     * Suggest chapter assignments for unassigned accepted events.
     *
     * @return array|WP_Error { assignments: [{event_id, chapter_id}], action_id }
     */
    public static function suggest_assignments($person_id) {
        $chapters = WCPO_Chapter_Repo::list_for_person($person_id);
        if (!$chapters) {
            return new WP_Error('no_chapters', __('Create at least one chapter first.', 'wcp-openbiografy'));
        }
        $events = WCPO_Event_Repo::unassigned($person_id);
        if (!$events) {
            return new WP_Error('no_events', __('No unassigned accepted events.', 'wcp-openbiografy'));
        }

        $chapter_lines  = array();
        $chapter_ids    = array();
        $chapter_titles = array();
        foreach ($chapters as $post) {
            $c = WCPO_Chapter_Repo::meta($post->ID);
            $chapter_ids[] = $c['id'];
            $chapter_titles[$c['id']] = $c['title'];
            $chapter_lines[] = sprintf('id:%d | title:%s | period:%s', $c['id'], $c['title'], $c['period_edtf'] ?: '-');
        }

        $event_lines  = array();
        $event_ids    = array();
        $event_titles = array();
        foreach ($events as $post) {
            $e = WCPO_Event_Repo::meta($post->ID);
            $event_ids[] = $e['id'];
            $event_titles[$e['id']] = trim(($e['date_display'] ? $e['date_display'] . ' — ' : '') . $e['title']);
            $event_lines[] = sprintf('id:%d | kind:%s | date:%s | %s', $e['id'], $e['kind'], $e['date_edtf'] ?: '-', $e['title']);
        }

        $system = 'You assign biographical timeline events to narrative chapters based on chapter periods and themes. '
            . 'Assign each event to the single best-fitting chapter; omit an event if none fits well. '
            . 'Use only the provided ids. Respond ONLY with a valid JSON object.';
        $user = WCPO_Person_Repo::context_block($person_id) . "\n\n"
            . "CHAPTERS:\n" . implode("\n", $chapter_lines) . "\n\n"
            . "UNASSIGNED EVENTS:\n" . implode("\n", $event_lines) . "\n\n"
            . 'Respond with JSON exactly: {"assignments": [{"event_id": 0, "chapter_id": 0}]}';

        $result = WCPO_LLM::call('assign_chapters', $system, $user, array(
            'tier'            => 'fast',
            'max_tokens'      => 4096,
            'required'        => array('assignments'),
            'context_post_id' => (int) $person_id,
        ));
        if (is_wp_error($result)) {
            return $result;
        }

        $assignments = array();
        foreach ((array) $result['data']['assignments'] as $pair) {
            if (!is_array($pair)) {
                continue;
            }
            $event_id   = isset($pair['event_id']) ? (int) $pair['event_id'] : 0;
            $chapter_id = isset($pair['chapter_id']) ? (int) $pair['chapter_id'] : 0;
            if (in_array($event_id, $event_ids, true) && in_array($chapter_id, $chapter_ids, true)) {
                $assignments[] = array(
                    'event_id'      => $event_id,
                    'chapter_id'    => $chapter_id,
                    'event_title'   => $event_titles[$event_id],
                    'chapter_title' => $chapter_titles[$chapter_id],
                );
            }
        }

        return array('assignments' => $assignments, 'action_id' => $result['action_id']);
    }

    /**
     * Draft a chapter narrative from its accepted events. The draft is stored
     * as a proposal on the chapter; a human accepts or dismisses it.
     *
     * @return array|WP_Error { draft, action_id }
     */
    public static function draft($chapter_id) {
        $chapter = WCPO_Chapter_Repo::meta($chapter_id);
        if (!$chapter) {
            return new WP_Error('not_found', __('Chapter not found.', 'wcp-openbiografy'));
        }
        $events = WCPO_Event_Repo::for_chapter($chapter_id);
        if (!$events) {
            return new WP_Error('no_events', __('This chapter has no accepted events assigned yet.', 'wcp-openbiografy'));
        }

        $event_lines = array();
        foreach ($events as $post) {
            $e = WCPO_Event_Repo::meta($post->ID);
            $cites = array();
            foreach (WCPO_Event_Repo::source_ids($e['id']) as $sid) {
                $line = WCPO_Source_Repo::citation_line($sid);
                if ($line) {
                    $cites[] = $line;
                }
            }
            $flags = array();
            if ($e['contested']) {
                $flags[] = 'CONTESTED: ' . ($e['contested_note'] ?: 'sources disagree');
            }
            if ($e['confidence'] && $e['confidence'] < (float) wcpo_get_setting('min_confidence_display')) {
                $flags[] = 'LOW CONFIDENCE';
            }
            $event_lines[] = sprintf(
                "[e%d] %s — %s (%s%s)%s%s",
                $e['id'],
                $e['title'],
                $e['description'],
                $e['date_display'] ?: 'undated',
                $e['place'] ? '; ' . $e['place'] : '',
                $flags ? ' [' . implode('; ', $flags) . ']' : '',
                $cites ? ' — sources: ' . implode(' / ', array_slice($cites, 0, 3)) : ''
            );
        }

        $system = 'You write one chapter of an evidence-based biography. '
            . 'STRICT RULES: use ONLY the supplied events — never add facts from general knowledge. '
            . 'Every factual statement must cite its event inline using its marker, e.g. [e123], placed immediately after the statement. '
            . 'Write 2-6 paragraphs of clear narrative prose for a general educated audience. '
            . 'Use cautious wording for low-confidence events ("appears to have", "probably"). '
            . 'Where an event is marked CONTESTED, mention the disagreement explicitly rather than picking a side. '
            . 'No fictionalised scenes, no invented dialogue, no embellishment. '
            . 'Respond with the narrative text only — no JSON, no headings, no preamble.';

        $user = WCPO_Person_Repo::context_block($chapter['person_id']) . "\n\n"
            . 'CHAPTER: ' . $chapter['title']
            . ($chapter['period_edtf'] ? ' (' . $chapter['period_display'] . ')' : '') . "\n\n"
            . "EVENTS (cite by marker):\n" . implode("\n", $event_lines);

        $result = WCPO_LLM::call('draft_chapter', $system, $user, array(
            'tier'            => 'draft',
            'max_tokens'      => 4096,
            'raw_text'        => true,
            'context_post_id' => (int) $chapter_id,
        ));
        if (is_wp_error($result)) {
            return $result;
        }

        WCPO_Chapter_Repo::set_draft($chapter_id, $result['data'], $result['action_id']);
        return array('draft' => $result['data'], 'action_id' => $result['action_id']);
    }
}
