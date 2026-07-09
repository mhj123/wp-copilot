<?php
/**
 * Fact extractor. Turns one fetched source into proposed facts (atomic,
 * source-linked claims) plus a source classification (citation, document
 * kind, source tier) — one bounded LLM call per source.
 *
 * GUARDRAIL: every fact lands in wcpo_proposed. Nothing here can accept.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPO_Extractor {

    /** Process one 'fetched' source. Returns array{facts_created:int}|WP_Error. */
    public static function extract($source_id) {
        $source = WCPO_Source_Repo::meta($source_id);
        if (!$source) {
            return new WP_Error('not_found', __('Source not found.', 'wcp-openbiografy'));
        }
        $person_id = $source['person_id'];
        $person_block = WCPO_Person_Repo::context_block($person_id);

        $is_pdf = ($source['source_type'] === 'document' && $source['mime'] === 'application/pdf');
        $snapshot = get_post($source_id)->post_content;

        if (!$is_pdf && trim($snapshot) === '') {
            WCPO_Source_Repo::set_fetch_status($source_id, 'extract_failed', 'No text snapshot to extract from');
            return new WP_Error('empty', __('No text snapshot to extract from.', 'wcp-openbiografy'));
        }

        $system = self::system_prompt();
        $user   = self::user_prompt($person_block, $source, $is_pdf ? '' : $snapshot);

        $opts = array(
            'tier'            => 'fast',
            'max_tokens'      => 8192,
            'required'        => array('citation', 'facts'),
            'context_post_id' => (int) $source_id,
        );
        if ($is_pdf) {
            $opts['attachment_id'] = $source['attachment_id'];
        }

        $result = WCPO_LLM::call('extract_facts', $system, $user, $opts);
        if (is_wp_error($result)) {
            WCPO_Source_Repo::set_fetch_status($source_id, 'extract_failed', $result->get_error_message());
            return $result;
        }

        $data      = $result['data'];
        $action_id = $result['action_id'];

        // Classification + citation prefill (human edits always win).
        $citation = is_array($data['citation']) ? $data['citation'] : array();
        WCPO_Source_Repo::save_citation($source_id, array(
            'cite_title'     => isset($citation['title']) ? $citation['title'] : null,
            'cite_author'    => isset($citation['author']) ? $citation['author'] : null,
            'cite_date'      => isset($citation['date']) ? $citation['date'] : null,
            'cite_publisher' => isset($citation['publisher']) ? $citation['publisher'] : null,
        ));
        WCPO_Source_Repo::save_classification(
            $source_id,
            isset($data['doc_kind']) ? (string) $data['doc_kind'] : 'unknown',
            isset($data['source_tier']) ? (string) $data['source_tier'] : 'unknown',
            isset($data['tier_confidence']) ? (float) $data['tier_confidence'] : 0
        );

        $created = 0;
        foreach ((array) $data['facts'] as $fact) {
            if (!is_array($fact) || empty($fact['claim'])) {
                continue;
            }
            $fact_id = WCPO_Fact_Repo::create(array(
                'person_id'    => $person_id,
                'source_id'    => (int) $source_id,
                'claim'        => (string) $fact['claim'],
                'kind'         => isset($fact['kind']) ? (string) $fact['kind'] : 'other',
                'date_edtf'    => isset($fact['date_edtf']) ? (string) $fact['date_edtf'] : '',
                'place'        => isset($fact['place']) ? (string) $fact['place'] : '',
                'quote'        => isset($fact['quote']) ? (string) $fact['quote'] : '',
                'locator'      => isset($fact['locator']) ? (string) $fact['locator'] : '',
                'confidence'   => isset($fact['confidence']) ? (float) $fact['confidence'] : 0.5,
                'ai_action_id' => $action_id,
            ));
            if (!is_wp_error($fact_id)) {
                $created++;
            }
        }

        update_post_meta($source_id, '_wcpo_extracted_at', current_time('mysql', true));
        update_post_meta($source_id, '_wcpo_facts_extracted_count', $created);
        update_post_meta($source_id, '_wcpo_ai_action_id', $action_id);
        WCPO_Source_Repo::set_fetch_status($source_id, 'extracted');

        return array('facts_created' => $created, 'action_id' => $action_id);
    }

    private static function system_prompt() {
        return 'You extract atomic biographical facts about ONE specific person from a source document. '
            . 'Rules: each fact is ONE atomic claim in a single sentence, directly supported by the source text — never inferred from general knowledge. '
            . 'Include a short verbatim supporting quote from the source for each fact where possible. '
            . 'Use EDTF for dates: YYYY, YYYY-MM, YYYY-MM-DD; append ~ for circa, ? for uncertain; use YYYY/YYYY for ranges; leave empty if the source gives no date. '
            . 'Do not over-normalise vague dates. Preserve ambiguity rather than guessing. '
            . 'If the document is not substantially about the subject person, extract only the facts that concern them (possibly none). '
            . 'Respond ONLY with a valid JSON object, no prose.';
    }

    private static function user_prompt($person_block, array $source, $snapshot) {
        $kinds = implode('|', wcpo_kinds());
        $doc_kinds = implode('|', wcpo_doc_kinds());
        $tiers = implode('|', wcpo_source_tiers());

        $user = $person_block . "\n\n";
        $user .= "SOURCE: " . ($source['url'] ?: $source['title']) . "\n\n";

        if ($snapshot !== '') {
            $max = (int) wcpo_get_setting('max_context_chars');
            $user .= "SOURCE TEXT:\n" . mb_substr($snapshot, 0, $max) . "\n\n";
        } else {
            $user .= "The source document is attached as a PDF.\n\n";
        }

        $user .= "Extract all biographical facts about the subject person and classify the source. "
            . "Respond with JSON exactly in this shape:\n"
            . '{'
            . '"citation": {"title": "", "author": "", "date": "", "publisher": ""}, '
            . '"doc_kind": "' . $doc_kinds . '", '
            . '"source_tier": "' . $tiers . '", '
            . '"tier_confidence": 0.0, '
            . '"facts": [{'
            . '"claim": "one atomic sentence", '
            . '"kind": "' . $kinds . '", '
            . '"date_edtf": "", "place": "", '
            . '"quote": "verbatim supporting excerpt", '
            . '"locator": "page/section if known", '
            . '"confidence": 0.0'
            . '}]'
            . '}';
        return $user;
    }
}
