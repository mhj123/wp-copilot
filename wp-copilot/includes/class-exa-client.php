<?php
/**
 * Exa Client - Handles web search via the Exa Search API
 *
 * Used by the "Web search" AI assistant action to bring back findings
 * from the live web. Read-only — never writes to the database itself;
 * results are only ever surfaced as a chat message the user may choose
 * to save as item(s).
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_Exa_Client {

    private static $instance = null;
    private $api_key;
    private $api_url = 'https://api.exa.ai/search';

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->api_key = get_option('wcp_exa_api_key', '');
    }

    /**
     * Check if Exa search is configured
     */
    public function is_configured() {
        return !empty($this->api_key);
    }

    /**
     * Run a web search and return per-result findings.
     *
     * @param string $query       Search query (natural language is fine — Exa is a neural search engine)
     * @param int    $num_results Max results to return
     * @return array|WP_Error Array of {title, url, snippet}, or WP_Error
     */
    public function search($query, $num_results = 8) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', 'Exa API key not configured');
        }

        if (empty(trim($query))) {
            return new WP_Error('empty_query', 'Cannot search with an empty query');
        }

        $response = wp_remote_post($this->api_url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key'    => $this->api_key,
            ),
            'body' => wp_json_encode(array(
                'query'         => $query,
                'numResults'    => $num_results,
                'contents'      => array(
                    'highlights' => array(
                        'numSentences'     => 1,
                        'highlightsPerUrl' => 1,
                    ),
                ),
            )),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        if ($response_code !== 200) {
            $error_message = isset($data['error']) ? $data['error'] : 'Unknown API error';
            return new WP_Error('api_error', $error_message, array('status' => $response_code));
        }

        if (!isset($data['results']) || !is_array($data['results'])) {
            return new WP_Error('parse_error', 'Could not parse Exa search response');
        }

        $findings = array();
        foreach ($data['results'] as $result) {
            $snippet = '';
            if (!empty($result['highlights']) && is_array($result['highlights'])) {
                // Despite highlightsPerUrl:1 capping this to a single string,
                // Exa's numSentences appears to apply PER query-term match
                // location within the source document, not to the highlight
                // as a whole — a doc with several match locations comes back
                // as multiple long sentence-windows concatenated together
                // (observed up to ~4000 chars from a single "highlight").
                // Cap it ourselves rather than trust the API to bound it.
                $snippet = implode(' … ', $result['highlights']);
            } elseif (!empty($result['text'])) {
                $snippet = mb_substr($result['text'], 0, 400);
            }

            $findings[] = array(
                'title'   => $result['title'] ?? $result['url'] ?? 'Untitled',
                'url'     => $result['url'] ?? '',
                'snippet' => $this->truncate_snippet(trim($snippet), 500),
            );
        }

        return $findings;
    }

    /**
     * Truncate a snippet to a sane atomic-note length, preserving word
     * boundaries. Findings are meant to be a short pointer to the source,
     * not a dump of the paper's text — the full document is at the URL.
     */
    private function truncate_snippet($text, $max_chars) {
        if (mb_strlen($text) <= $max_chars) {
            return $text;
        }

        $truncated = mb_substr($text, 0, $max_chars);
        $last_space = mb_strrpos($truncated, ' ');
        if ($last_space !== false && $last_space > $max_chars * 0.8) {
            $truncated = mb_substr($truncated, 0, $last_space);
        }

        return rtrim($truncated, " .…") . '…';
    }

    /**
     * Test connection to Exa
     */
    public function test_connection() {
        $result = $this->search('test', 1);

        if (is_wp_error($result)) {
            return $result;
        }

        return array(
            'success' => true,
            'message' => 'Exa Search API connection successful',
        );
    }
}
