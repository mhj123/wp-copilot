<?php
/**
 * OpenAlex Client - Scholarly search via the OpenAlex Works API
 *
 * Alternative provider to WCP_Exa_Client for the Find References flows,
 * chosen by the user at the point of placing the query. Read-only — never
 * writes to the database; findings are only surfaced as proposals.
 *
 * Returns the same {title, url, snippet} shape as WCP_Exa_Client::search(),
 * so everything downstream is provider-agnostic.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_OpenAlex_Client {

    private static $instance = null;
    private $api_url = 'https://api.openalex.org/works';

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * OpenAlex needs no credentials to answer; a key and/or contact email
     * only buy better rate limits, so it is always "configured".
     */
    public function is_configured() {
        return true;
    }

    /**
     * @param string $query       Keyword-style query (OpenAlex search is lexical, not neural)
     * @param int    $num_results Max results to return
     * @param bool   $semantic    Use OpenAlex's embedding-based search.semantic
     *   (meaning match on title+abstract, beta, max 50 results) instead of the
     *   default lexical search. Best with a full natural-language sentence.
     * @return array|WP_Error Array of {title, url, snippet, abstract}, or WP_Error
     */
    public function search($query, $num_results = 8, $semantic = false) {
        if (empty(trim($query))) {
            return new WP_Error('empty_query', 'Cannot search with an empty query');
        }

        $args = array(
            ($semantic ? 'search.semantic' : 'search') => $query,
            'per-page' => (int) $num_results,
            'select'   => 'id,doi,title,type,publication_year,cited_by_count,authorships,primary_location,open_access,abstract_inverted_index',
        );
        $api_key = get_option('wcp_openalex_api_key', '');
        $email   = get_option('wcp_openalex_email', '');
        if ($api_key !== '') { $args['api_key'] = $api_key; }
        if ($email !== '')   { $args['mailto']  = $email; }

        $response = wp_remote_get(add_query_arg(array_map('rawurlencode', $args), $this->api_url), array('timeout' => 30));
        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200) {
            $message = is_array($data) && !empty($data['message']) ? $data['message'] : 'Unknown API error';
            return new WP_Error('api_error', 'OpenAlex: ' . $message, array('status' => $code));
        }
        if (!isset($data['results']) || !is_array($data['results'])) {
            return new WP_Error('parse_error', 'Could not parse OpenAlex response');
        }

        $findings = array();
        foreach ($data['results'] as $work) {
            $url = $work['doi'] ?? ($work['primary_location']['landing_page_url'] ?? ($work['id'] ?? ''));
            if (empty($url)) { continue; }

            $abstract = $this->rebuild_abstract($work['abstract_inverted_index'] ?? null);

            // Citation line first so the excerpt carries who/when/where as
            // well as what — the synthesis step only sees this text.
            $names = array();
            foreach (array_slice($work['authorships'] ?? array(), 0, 3) as $a) {
                if (!empty($a['author']['display_name'])) { $names[] = $a['author']['display_name']; }
            }
            if (count($work['authorships'] ?? array()) > 3) { $names[] = 'et al.'; }
            $venue = $work['primary_location']['source']['display_name'] ?? '';
            $meta = trim(implode(', ', $names)
                . (!empty($work['publication_year']) ? ' (' . $work['publication_year'] . ')' : '')
                . ($venue !== '' ? ', ' . $venue : '')
                . (isset($work['cited_by_count']) ? ' — cited by ' . (int) $work['cited_by_count'] : ''));

            $findings[] = array(
                'title'    => $work['title'] ?? $url,
                'url'      => $url,
                'snippet'  => trim($meta . ($abstract !== '' ? '. Abstract: ' . $abstract : '')),
                'abstract' => $abstract,
                'provider' => 'openalex',
                // Structured fields for display in the proposal list.
                'meta'     => array_filter(array(
                    'authors'  => sanitize_text_field(implode(', ', $names)),
                    'year'     => (int) ($work['publication_year'] ?? 0) ?: '',
                    'venue'    => sanitize_text_field($venue),
                    'cited_by' => isset($work['cited_by_count']) ? (int) $work['cited_by_count'] : '',
                    'type'     => sanitize_text_field($work['type'] ?? ''),
                    'is_oa'    => !empty($work['open_access']['is_oa']),
                ), function ($v) { return $v !== '' && $v !== false; }),
            );
        }

        return $findings;
    }

    /**
     * OpenAlex ships abstracts as an inverted index (word => positions);
     * lay the words back out in position order.
     */
    private function rebuild_abstract($index) {
        if (!is_array($index) || empty($index)) {
            return '';
        }
        $words = array();
        foreach ($index as $word => $positions) {
            foreach ((array) $positions as $pos) {
                $words[(int) $pos] = $word;
            }
        }
        ksort($words);
        return trim(implode(' ', $words));
    }
}
