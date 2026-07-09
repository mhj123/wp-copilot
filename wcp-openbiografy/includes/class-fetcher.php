<?php
/**
 * Source fetcher. Dereferences one URL source into a plain-text snapshot
 * using an 80/20 DOM readability heuristic (no external deps). Document
 * sources: plain-text/markdown files are read directly; PDFs skip straight
 * to 'fetched' because they are sent to the model as document blocks at
 * extraction time.
 *
 * JS-rendered and paywalled pages will fail or yield junk — the manual
 * paste-text fallback (update-source REST) covers those.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPO_Fetcher {

    /** Process one 'new' source. Returns updated source meta or WP_Error. */
    public static function fetch($source_id) {
        $source = WCPO_Source_Repo::meta($source_id);
        if (!$source) {
            return new WP_Error('not_found', __('Source not found.', 'wcp-openbiografy'));
        }

        if ($source['source_type'] === 'document') {
            return self::fetch_document($source);
        }
        return self::fetch_url($source);
    }

    private static function fetch_document(array $source) {
        $file = get_attached_file($source['attachment_id']);
        if (!$file || !file_exists($file)) {
            WCPO_Source_Repo::set_fetch_status($source['id'], 'fetch_failed', 'Attached file not found');
            return new WP_Error('file_missing', __('Attached file not found.', 'wcp-openbiografy'));
        }
        $mime = get_post_mime_type($source['attachment_id']);

        if ($mime === 'application/pdf') {
            // No local text snapshot: the PDF itself goes to the model at
            // extraction time as a document content block.
            update_post_meta($source['id'], '_wcpo_fetched_at', current_time('mysql', true));
            WCPO_Source_Repo::set_fetch_status($source['id'], 'fetched');
            return WCPO_Source_Repo::meta($source['id']);
        }

        if (in_array($mime, array('text/plain', 'text/markdown', 'text/csv'), true)) {
            WCPO_Source_Repo::save_snapshot($source['id'], file_get_contents($file));
            WCPO_Source_Repo::set_fetch_status($source['id'], 'fetched');
            return WCPO_Source_Repo::meta($source['id']);
        }

        WCPO_Source_Repo::set_fetch_status($source['id'], 'fetch_failed', 'Unsupported file type: ' . $mime . ' (use PDF or plain text, or paste the text manually)');
        return new WP_Error('bad_type', __('Unsupported file type.', 'wcp-openbiografy'));
    }

    private static function fetch_url(array $source) {
        $response = wp_remote_get($source['url'], array(
            'timeout'     => (int) wcpo_get_setting('fetch_timeout'),
            'redirection' => 3,
            'user-agent'  => 'WCPOpenBiografy/' . WCPO_VERSION . ' (+' . home_url() . ')',
        ));

        if (is_wp_error($response)) {
            WCPO_Source_Repo::set_fetch_status($source['id'], 'fetch_failed', $response->get_error_message());
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        update_post_meta($source['id'], '_wcpo_http_status', $code);
        if ($code < 200 || $code >= 300) {
            WCPO_Source_Repo::set_fetch_status($source['id'], 'fetch_failed', 'HTTP ' . $code);
            return new WP_Error('http_error', 'HTTP ' . $code);
        }

        $extracted = self::extract_text(wp_remote_retrieve_body($response));
        if (trim($extracted['text']) === '') {
            WCPO_Source_Repo::set_fetch_status($source['id'], 'fetch_failed', 'No readable text found (JS-rendered page? Paste text manually.)');
            return new WP_Error('empty', __('No readable text found.', 'wcp-openbiografy'));
        }

        WCPO_Source_Repo::save_snapshot($source['id'], $extracted['text']);
        // Page metadata as citation hints — prefill only, human edits win.
        WCPO_Source_Repo::save_citation($source['id'], array(
            'cite_title'  => $extracted['title'],
            'cite_author' => $extracted['author'],
            'cite_date'   => $extracted['date'],
        ));
        WCPO_Source_Repo::set_fetch_status($source['id'], 'fetched');
        return WCPO_Source_Repo::meta($source['id']);
    }

    /**
     * 80/20 readability: strip chrome, prefer <article> then <main> then the
     * densest content container, then join headings/paragraphs/list items in
     * document order.
     *
     * @return array { text, title, author, date }
     */
    public static function extract_text($html) {
        $out = array('text' => '', 'title' => '', 'author' => '', 'date' => '');
        if (trim((string) $html) === '' || !class_exists('DOMDocument')) {
            // Last resort without DOM: crude tag strip.
            $out['text'] = trim(wp_strip_all_tags((string) $html));
            return $out;
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        // The xml declaration hack forces UTF-8 without the deprecated
        // mb_convert_encoding HTML-ENTITIES round-trip.
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        // Citation hints.
        $title_nodes = $dom->getElementsByTagName('title');
        if ($title_nodes->length) {
            $out['title'] = trim($title_nodes->item(0)->textContent);
        }
        foreach (array(
            'author' => '//meta[@name="author"]/@content',
            'date'   => '//meta[@property="article:published_time"]/@content | //meta[@name="date"]/@content',
        ) as $key => $query) {
            $nodes = $xpath->query($query);
            if ($nodes && $nodes->length) {
                $out[$key] = trim($nodes->item(0)->nodeValue);
            }
        }
        // Keep only the date part of an ISO timestamp.
        if ($out['date'] && preg_match('/^(\d{4}-\d{2}(-\d{2})?)/', $out['date'], $m)) {
            $out['date'] = $m[1];
        }

        // Remove non-content chrome.
        foreach (array('script', 'style', 'noscript', 'nav', 'header', 'footer', 'aside', 'form', 'iframe', 'svg', 'button') as $tag) {
            $nodes = $dom->getElementsByTagName($tag);
            while ($nodes->length) {
                $node = $nodes->item(0);
                $node->parentNode->removeChild($node);
            }
        }

        // Candidate container: article → main → densest div/section with ≥2 <p> → body.
        $container = null;
        foreach (array('article', 'main') as $tag) {
            $nodes = $dom->getElementsByTagName($tag);
            if ($nodes->length) {
                $container = $nodes->item(0);
                break;
            }
        }
        if (!$container) {
            $best_len = 0;
            foreach ($xpath->query('//div | //section') as $node) {
                $p_count = 0;
                foreach ($node->childNodes as $child) {
                    if ($child->nodeName === 'p') {
                        $p_count++;
                    }
                }
                if ($p_count < 2) {
                    continue;
                }
                $len = strlen(trim($node->textContent));
                if ($len > $best_len) {
                    $best_len = $len;
                    $container = $node;
                }
            }
        }
        if (!$container) {
            $body = $dom->getElementsByTagName('body');
            $container = $body->length ? $body->item(0) : $dom->documentElement;
        }
        if (!$container) {
            return $out;
        }

        // Text units in document order.
        $lines = array();
        $units = $xpath->query('.//p | .//h1 | .//h2 | .//h3 | .//h4 | .//h5 | .//h6 | .//li | .//blockquote | .//td', $container);
        foreach ($units as $node) {
            // Skip nested duplicates (e.g. p inside blockquote already captured).
            if ($node->nodeName !== 'p' || !$node->parentNode || $node->parentNode->nodeName !== 'blockquote') {
                $text = preg_replace('/\s+/u', ' ', trim($node->textContent));
                if ($text !== '' && mb_strlen($text) > 2) {
                    $lines[] = $text;
                }
            }
        }
        if (!$lines) {
            $lines[] = preg_replace('/\s+/u', ' ', trim($container->textContent));
        }

        $out['text'] = implode("\n\n", $lines);
        return $out;
    }
}
