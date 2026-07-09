<?php
/**
 * Price source adapter (§F7): CoinGecko (crypto, free tier) + Stooq
 * (equities, free EOD/delayed — the UI documents the delay caveat).
 *
 * Observations are cached ≥ price_cache_minutes and every fresh observation
 * is written to the prices table (§4.5).
 *
 * GUARDRAIL (§12.3): read-only market data. No exchange, brokerage or wallet
 * API exists anywhere in this plugin, and none may be added.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPW_Price_Source {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'wcp_wiretap_prices';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $table = self::table();
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticker VARCHAR(16) NOT NULL DEFAULT '',
            asset_class VARCHAR(10) NOT NULL DEFAULT '',
            price DOUBLE DEFAULT NULL,
            source VARCHAR(20) DEFAULT '',
            observed_at DATETIME DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY ticker_observed (ticker, observed_at)
        ) {$charset};");
    }

    /**
     * Current price for a registry symbol. Returns float or null.
     * Cached via transient; fresh fetches are recorded in the prices table.
     */
    public static function get_price($symbol) {
        $symbol = strtoupper(trim($symbol));
        $meta = WCPW_Ticker_Registry::meta($symbol);
        if (!$meta) {
            return null;
        }

        $cache_key = 'wcpw_price_' . $symbol;
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return (float) $cached;
        }

        $price = null;
        $source = '';
        if ($meta['asset_class'] === 'equity') {
            $price = self::fetch_stooq($meta['stooq_symbol'] ?: strtolower($symbol) . '.us');
            $source = 'stooq';
        } else {
            $gecko_id = $meta['coingecko_id'] ?: self::resolve_coingecko_id($symbol, $meta['term_id']);
            if ($gecko_id) {
                $price = self::fetch_coingecko($gecko_id);
                $source = 'coingecko';
            }
        }

        if ($price === null) {
            return null;
        }

        set_transient($cache_key, $price, (int) wcpw_get_setting('price_cache_minutes') * MINUTE_IN_SECONDS);
        self::record($symbol, $meta['asset_class'], $price, $source);
        return $price;
    }

    /** Write an observation row (§4.5). */
    public static function record($symbol, $asset_class, $price, $source) {
        global $wpdb;
        $wpdb->insert(self::table(), array(
            'ticker'      => strtoupper($symbol),
            'asset_class' => $asset_class,
            'price'       => (float) $price,
            'source'      => $source,
            'observed_at' => current_time('mysql', true),
        ));
    }

    /** Price series since a timestamp (check-in context packs). */
    public static function series_since($symbol, $since_gmt, $limit = 200) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT price, observed_at FROM {$table}
             WHERE ticker = %s AND observed_at >= %s ORDER BY observed_at ASC LIMIT %d",
            strtoupper($symbol), $since_gmt, $limit
        ), ARRAY_A);
    }

    /**
     * Historical daily price (earliest-callers ranking, F3.3).
     * Crypto only — CoinGecko /history. Returns float or null.
     */
    public static function get_historical_price($symbol, $date_ymd) {
        $meta = WCPW_Ticker_Registry::meta($symbol);
        if (!$meta || $meta['asset_class'] !== 'crypto' || !$meta['coingecko_id']) {
            return null;
        }
        $cache_key = 'wcpw_hprice_' . $symbol . '_' . $date_ymd;
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return (float) $cached;
        }
        $url = 'https://api.coingecko.com/api/v3/coins/' . rawurlencode($meta['coingecko_id'])
             . '/history?date=' . rawurlencode(gmdate('d-m-Y', strtotime($date_ymd)));
        $response = wp_remote_get($url, array('timeout' => 20));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['market_data']['current_price']['usd'])) {
            return null;
        }
        $price = (float) $body['market_data']['current_price']['usd'];
        set_transient($cache_key, $price, WEEK_IN_SECONDS);
        return $price;
    }

    private static function fetch_coingecko($gecko_id) {
        $url = 'https://api.coingecko.com/api/v3/simple/price?ids=' . rawurlencode($gecko_id) . '&vs_currencies=usd';
        $response = wp_remote_get($url, array('timeout' => 20));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return isset($body[$gecko_id]['usd']) ? (float) $body[$gecko_id]['usd'] : null;
    }

    private static function fetch_stooq($stooq_symbol) {
        // Stooq CSV: Symbol,Date,Time,Open,High,Low,Close,Volume
        $url = 'https://stooq.com/q/l/?s=' . rawurlencode($stooq_symbol) . '&f=sd2t2ohlcv&h&e=csv';
        $response = wp_remote_get($url, array('timeout' => 20));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $lines = array_filter(explode("\n", trim(wp_remote_retrieve_body($response))));
        if (count($lines) < 2) {
            return null;
        }
        $cols = str_getcsv($lines[1]);
        // Close is column index 6; "N/D" means unknown symbol.
        if (!isset($cols[6]) || $cols[6] === 'N/D' || !is_numeric($cols[6])) {
            return null;
        }
        return (float) $cols[6];
    }

    /**
     * One-time CoinGecko id lookup for registry entries missing it (e.g.
     * tickers added via LLM resolution). Caches onto the term.
     */
    private static function resolve_coingecko_id($symbol, $term_id) {
        $url = 'https://api.coingecko.com/api/v3/search?query=' . rawurlencode($symbol);
        $response = wp_remote_get($url, array('timeout' => 20));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return '';
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['coins'])) {
            return '';
        }
        foreach ($body['coins'] as $coin) {
            if (strtoupper($coin['symbol']) === strtoupper($symbol)) {
                update_term_meta($term_id, 'coingecko_id', $coin['id']);
                return $coin['id'];
            }
        }
        return '';
    }
}
