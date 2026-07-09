<?php
/**
 * Timeline reconciler. Aggregates accepted, unconsolidated facts into
 * proposed timeline events — the layer that turns "moved to Paris in 1891"
 * from three sources into one reviewed timeline node.
 *
 * Facts are chunked by date proximity (they arrive date-sorted) so duplicate
 * claims land in the same LLM call. One chunk per REST call, so the dashboard
 * "Consolidate" button loops like fetch/extract do.
 *
 * GUARDRAIL: events are proposals; member facts are only stamped when a
 * human accepts the event. Conflicts become contested events, never merged
 * silently.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPO_Reconciler {

    /**
     * Consolidate one chunk of unconsolidated accepted facts.
     *
     * @return array|WP_Error { events_created:int, facts_considered:int, remaining:int }
     */
    public static function consolidate($person_id) {
        $exclude = WCPO_Event_Repo::fact_ids_in_proposed($person_id);
        $facts = WCPO_Fact_Repo::accepted_unconsolidated($person_id, $exclude);
        if (!$facts) {
            return array('events_created' => 0, 'facts_considered' => 0, 'remaining' => 0);
        }

        $chunk_size = max(10, (int) wcpo_get_setting('consolidate_chunk'));
        $chunk = array_slice($facts, 0, $chunk_size);
        $remaining_after = count($facts) - count($chunk);

        $fact_lines = array();
        $valid_ids  = array();
        foreach ($chunk as $post) {
            $f = WCPO_Fact_Repo::meta($post->ID);
            $source = WCPO_Source_Repo::meta($f['source_id']);
            $valid_ids[] = $f['id'];
            $fact_lines[] = sprintf(
                'id:%d | kind:%s | date:%s | place:%s | claim:%s | source:%s (%s)',
                $f['id'],
                $f['kind'],
                $f['date_edtf'] ?: '-',
                $f['place'] ?: '-',
                $f['claim'],
                $source ? ($source['cite_title'] ?: $source['title']) : '?',
                $source ? ($source['source_tier'] ?: 'unknown') : 'unknown'
            );
        }

        $system = 'You reconcile atomic biographical facts about one person into timeline events. '
            . 'Group facts that describe the SAME real-world event (e.g. the same move, the same appointment reported by several sources) into one event. '
            . 'A single-fact event is fine — most facts stand alone. NEVER merge facts that describe different events. '
            . 'If grouped facts materially conflict (different dates, contradictory details), still create ONE event but set contested=true and explain the conflict in contested_note — do not flatten or discard either version. '
            . 'Every fact id you output MUST come from the provided list, and each fact may appear in at most one event. Include every provided fact in exactly one event. '
            . 'Use EDTF dates (YYYY, YYYY-MM, YYYY-MM-DD, ~ for circa, YYYY/YYYY ranges). '
            . 'importance reflects how biography-shaping the event is (0-1). '
            . 'Respond ONLY with a valid JSON object.';

        $user = WCPO_Person_Repo::context_block($person_id) . "\n\n"
            . "ACCEPTED FACTS (sorted by date):\n" . implode("\n", $fact_lines) . "\n\n"
            . 'Respond with JSON exactly in this shape:'
            . '{"events": [{"title": "", "description": "1-2 sentences", "kind": "' . implode('|', wcpo_kinds()) . '", '
            . '"date_edtf": "", "place": "", "fact_ids": [1,2], '
            . '"contested": false, "contested_note": "", "confidence": 0.0, "importance": 0.0}]}';

        $result = WCPO_LLM::call('consolidate', $system, $user, array(
            'tier'            => 'fast',
            'max_tokens'      => 8192,
            'required'        => array('events'),
            'context_post_id' => (int) $person_id,
        ));
        if (is_wp_error($result)) {
            return $result;
        }

        $created = 0;
        $used_ids = array();
        foreach ((array) $result['data']['events'] as $event) {
            if (!is_array($event) || empty($event['title']) || empty($event['fact_ids'])) {
                continue;
            }
            // Only provided fact ids, each in at most one event this run.
            $fact_ids = array();
            foreach ((array) $event['fact_ids'] as $fid) {
                $fid = (int) $fid;
                if (in_array($fid, $valid_ids, true) && !in_array($fid, $used_ids, true)) {
                    $fact_ids[] = $fid;
                    $used_ids[] = $fid;
                }
            }
            if (!$fact_ids) {
                continue;
            }
            $event_id = WCPO_Event_Repo::create(array(
                'person_id'      => (int) $person_id,
                'title'          => (string) $event['title'],
                'description'    => isset($event['description']) ? (string) $event['description'] : '',
                'kind'           => isset($event['kind']) ? (string) $event['kind'] : 'other',
                'date_edtf'      => isset($event['date_edtf']) ? (string) $event['date_edtf'] : '',
                'place'          => isset($event['place']) ? (string) $event['place'] : '',
                'fact_ids'       => $fact_ids,
                'contested'      => !empty($event['contested']),
                'contested_note' => isset($event['contested_note']) ? (string) $event['contested_note'] : '',
                'confidence'     => isset($event['confidence']) ? (float) $event['confidence'] : 0.5,
                'importance'     => isset($event['importance']) ? (float) $event['importance'] : 0.5,
                'ai_action_id'   => $result['action_id'],
            ));
            if (!is_wp_error($event_id)) {
                $created++;
            }
        }

        return array(
            'events_created'  => $created,
            'facts_considered' => count($chunk),
            'remaining'       => $remaining_after,
        );
    }
}
