<?php
/**
 * Ticker registry (§4.3) — wcp_ticker terms carry canonical symbol, asset
 * class, price-source ids and aliases. Seeded at activation from bundled
 * lists; extended by human confirmation of LLM-resolved unknowns.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPW_Ticker_Registry {

    /** In-request cache of symbol → term_id. */
    private static $symbol_cache = null;
    private static $alias_cache = null;

    /**
     * Seed the registry from the bundled JSON lists. Idempotent: existing
     * symbols are left untouched.
     */
    public static function seed() {
        // Taxonomies must exist before terms can be inserted.
        if (!taxonomy_exists('wcp_ticker')) {
            wcpw_register_types();
        }
        $seeded = 0;
        foreach (array('crypto' => 'tickers-crypto-seed.json', 'equity' => 'tickers-equity-seed.json') as $class => $file) {
            $path = WCPW_PLUGIN_DIR . 'data/' . $file;
            if (!file_exists($path)) {
                continue;
            }
            $entries = json_decode(file_get_contents($path), true);
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if (empty($entry['symbol'])) {
                    continue;
                }
                if (self::add($entry['symbol'], $class, $entry) !== 0) {
                    $seeded++;
                }
            }
        }
        return $seeded;
    }

    /**
     * Add a ticker to the registry. Returns term_id (0 if it already existed
     * or insert failed).
     */
    public static function add($symbol, $asset_class, array $extra = array(), $verified = true) {
        $symbol = strtoupper(trim($symbol));
        if (!$symbol) {
            return 0;
        }
        $existing = get_term_by('name', $symbol, 'wcp_ticker');
        if ($existing) {
            return 0;
        }
        $result = wp_insert_term($symbol, 'wcp_ticker');
        if (is_wp_error($result)) {
            return 0;
        }
        $term_id = (int) $result['term_id'];
        update_term_meta($term_id, 'canonical_symbol', $symbol);
        update_term_meta($term_id, 'asset_class', $asset_class === 'equity' ? 'equity' : 'crypto');
        if (!empty($extra['name'])) {
            update_term_meta($term_id, 'display_name', sanitize_text_field($extra['name']));
        }
        if (!empty($extra['coingecko_id'])) {
            update_term_meta($term_id, 'coingecko_id', sanitize_text_field($extra['coingecko_id']));
        }
        if (!empty($extra['stooq_symbol'])) {
            update_term_meta($term_id, 'stooq_symbol', sanitize_text_field($extra['stooq_symbol']));
        }
        if (!empty($extra['aliases']) && is_array($extra['aliases'])) {
            update_term_meta($term_id, 'aliases', array_map('strtolower', $extra['aliases']));
        }
        if (!$verified) {
            update_term_meta($term_id, 'unverified', 1);
        }
        self::$symbol_cache = null;
        self::$alias_cache = null;
        return $term_id;
    }

    /** Mark an LLM-added ticker as human-confirmed (§4.3). */
    public static function verify($symbol) {
        $term = self::get($symbol);
        if ($term) {
            delete_term_meta($term->term_id, 'unverified');
            return true;
        }
        return false;
    }

    /** @return WP_Term|null */
    public static function get($symbol) {
        $term = get_term_by('name', strtoupper(trim($symbol)), 'wcp_ticker');
        return $term ?: null;
    }

    public static function meta($symbol) {
        $term = self::get($symbol);
        if (!$term) {
            return null;
        }
        return array(
            'term_id'      => $term->term_id,
            'symbol'       => $term->name,
            'asset_class'  => get_term_meta($term->term_id, 'asset_class', true) ?: 'crypto',
            'display_name' => get_term_meta($term->term_id, 'display_name', true),
            'coingecko_id' => get_term_meta($term->term_id, 'coingecko_id', true),
            'stooq_symbol' => get_term_meta($term->term_id, 'stooq_symbol', true),
            'aliases'      => (array) get_term_meta($term->term_id, 'aliases', true),
            'unverified'   => (bool) get_term_meta($term->term_id, 'unverified', true),
            'first_call_at' => get_term_meta($term->term_id, 'first_call_at', true),
            'price_first'   => get_term_meta($term->term_id, 'price_first', true),
        );
    }

    /** Uppercase symbol set for fast prefilter membership tests. */
    public static function all_symbols() {
        if (self::$symbol_cache !== null) {
            return self::$symbol_cache;
        }
        $terms = get_terms(array('taxonomy' => 'wcp_ticker', 'hide_empty' => false, 'fields' => 'names'));
        self::$symbol_cache = is_wp_error($terms) ? array() : array_fill_keys(array_map('strtoupper', $terms), true);
        return self::$symbol_cache;
    }

    /** lowercase alias → SYMBOL map (e.g. "bitcoin" → "BTC"). */
    public static function alias_map() {
        if (self::$alias_cache !== null) {
            return self::$alias_cache;
        }
        $map = array();
        $terms = get_terms(array('taxonomy' => 'wcp_ticker', 'hide_empty' => false));
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                foreach ((array) get_term_meta($term->term_id, 'aliases', true) as $alias) {
                    if (is_string($alias) && $alias !== '') {
                        $map[strtolower($alias)] = strtoupper($term->name);
                    }
                }
            }
        }
        self::$alias_cache = $map;
        return $map;
    }

    /**
     * Backfill earliest-call anchors used by the earliness heuristic (§5) —
     * called by the F3.3 archive search and by the rec repo on first calls.
     * Only ever moves the anchor EARLIER.
     */
    public static function maybe_set_first_call($symbol, $datetime_gmt, $price) {
        $term = self::get($symbol);
        if (!$term) {
            return;
        }
        $existing = get_term_meta($term->term_id, 'first_call_at', true);
        if (!$existing || strtotime($datetime_gmt) < strtotime($existing)) {
            update_term_meta($term->term_id, 'first_call_at', $datetime_gmt);
            if ($price !== null && $price !== '') {
                update_term_meta($term->term_id, 'price_first', (float) $price);
            }
        }
    }

    /** Import extra registry entries from an uploaded JSON payload (settings). */
    public static function import_json($json) {
        $entries = json_decode($json, true);
        if (!is_array($entries)) {
            return new WP_Error('bad_json', 'Could not parse ticker JSON — expected an array of {symbol, asset_class, ...}.');
        }
        $added = 0;
        foreach ($entries as $entry) {
            if (empty($entry['symbol'])) {
                continue;
            }
            $class = isset($entry['asset_class']) ? $entry['asset_class'] : 'crypto';
            if (self::add($entry['symbol'], $class, $entry) !== 0) {
                $added++;
            }
        }
        return $added;
    }
}
