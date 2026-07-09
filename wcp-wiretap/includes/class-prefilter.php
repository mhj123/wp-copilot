<?php
/**
 * Cheap pre-filter (§F1) — cashtag / ticker-pattern / lexicon scan, no LLM.
 * Zero signals → the tweet is marked `skipped` and never reaches the model.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPW_Prefilter {

    /**
     * Scan tweet text (+ stored entities) for candidate signals.
     *
     * @return array {
     *   candidates: string[] uppercase symbols ($BTC → BTC),
     *   registry_hits: string[] candidates present in the registry,
     *   alias_hits: string[] symbols matched by name alias ("bitcoin" → BTC),
     *   lexicon_hits: string[] matched lexicon terms,
     *   has_signal: bool
     * }
     */
    public static function scan($text, $entities_json = '') {
        $text = (string) $text;
        $candidates = array();

        // 1) Cashtags from the API's entity extraction (most reliable).
        $entities = json_decode((string) $entities_json, true);
        if (!empty($entities['cashtags'])) {
            foreach ($entities['cashtags'] as $tag) {
                if (!empty($tag['tag'])) {
                    $candidates[strtoupper($tag['tag'])] = true;
                }
            }
        }

        // 2) Ticker pattern in the raw text ($sol, $HYPE, $BRK.B).
        if (preg_match_all('/\$([A-Za-z][A-Za-z0-9\.]{0,9})\b/', $text, $matches)) {
            foreach ($matches[1] as $sym) {
                // Skip pure dollar amounts like $100 / $4.5k
                if (!preg_match('/^\d/', $sym)) {
                    $candidates[strtoupper($sym)] = true;
                }
            }
        }

        $registry = WCPW_Ticker_Registry::all_symbols();
        $registry_hits = array_values(array_intersect(array_keys($candidates), array_keys($registry)));

        // 3) Name aliases ("thinking bitcoin bottoms here" with no cashtag).
        $alias_hits = array();
        $lower = strtolower($text);
        foreach (WCPW_Ticker_Registry::alias_map() as $alias => $symbol) {
            if (strpos($lower, $alias) !== false && preg_match('/\b' . preg_quote($alias, '/') . '\b/', $lower)) {
                $alias_hits[$symbol] = true;
                $candidates[$symbol] = true;
            }
        }
        $alias_hits = array_keys($alias_hits);

        // 4) Trading lexicon (settings-editable).
        $lexicon_hits = array();
        foreach ((array) wcpw_get_setting('lexicon') as $term) {
            $term = strtolower(trim((string) $term));
            if ($term !== '' && preg_match('/\b' . preg_quote($term, '/') . '\b/', $lower)) {
                $lexicon_hits[] = $term;
            }
        }

        return array(
            'candidates'    => array_keys($candidates),
            'registry_hits' => $registry_hits,
            'alias_hits'    => $alias_hits,
            'lexicon_hits'  => $lexicon_hits,
            'has_signal'    => !empty($candidates) || !empty($lexicon_hits),
        );
    }
}
