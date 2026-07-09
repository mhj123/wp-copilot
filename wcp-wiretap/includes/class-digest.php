<?php
/**
 * Daily digest (§F4): deterministic pre-aggregation in code, then ONE LLM
 * call over the bounded pack → markdown draft post.
 *
 * GUARDRAIL (§12.1): digests are saved as DRAFTS, never published.
 * GUARDRAIL (§12.7): every digest carries the not-financial-advice disclaimer.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPW_Digest {

    const DISCLAIMER = "\n\n---\n*This digest is AI-generated commentary on public social posts. "
        . "It is not financial advice. Verify everything before acting.*";

    /** Cron entry point — daily window. */
    public static function run_scheduled() {
        if (!wcpw_acquire_lock('digest', 15 * MINUTE_IN_SECONDS)) {
            return;
        }
        $started = current_time('mysql', true);
        $result = self::generate(24);
        wcpw_record_run('digest', array(
            'started_at' => $started,
            'counts'     => array('generated' => is_wp_error($result) ? 0 : 1),
            'errors'     => is_wp_error($result) ? array($result->get_error_message()) : array(),
        ));
        wcpw_release_lock('digest');
    }

    /**
     * Generate a digest for an arbitrary trailing window (§F4 "generate now").
     *
     * @return int|WP_Error draft post id
     */
    public static function generate($window_hours = 24) {
        $window_hours = max(1, (int) $window_hours);
        $since = gmdate('Y-m-d H:i:s', time() - $window_hours * HOUR_IN_SECONDS);

        // ---- Deterministic pre-aggregation (code, not LLM) ----
        $rows = WCPW_Tweet_Repo::signal_rows_since($since);
        if (empty($rows)) {
            return new WP_Error('empty_window', 'No analyzed activity in the window — nothing to digest.');
        }

        $tickers = array();   // SYM => { mentions, kols:set, stances:{}, excerpts:[], links:[] }
        $themes = array();
        foreach ($rows as $row) {
            $payload = json_decode($row['signals_json'], true);
            if (!is_array($payload)) {
                continue;
            }
            $handle = $row['author_handle'];
            foreach ((array) (isset($payload['signals']) ? $payload['signals'] : array()) as $signal) {
                if (empty($signal['ticker'])) {
                    continue;
                }
                $sym = strtoupper($signal['ticker']);
                if (!isset($tickers[$sym])) {
                    $tickers[$sym] = array('mentions' => 0, 'kols' => array(), 'stances' => array(), 'excerpts' => array(), 'links' => array());
                }
                $tickers[$sym]['mentions']++;
                $tickers[$sym]['kols'][$handle] = true;
                $stance = isset($signal['classification']) ? $signal['classification'] : 'mention';
                $dir = isset($signal['direction']) ? $signal['direction'] : '';
                $key = $stance . ($dir ? '/' . $dir : '');
                $tickers[$sym]['stances'][$key] = isset($tickers[$sym]['stances'][$key]) ? $tickers[$sym]['stances'][$key] + 1 : 1;
                if (!empty($signal['rationale']) && count($tickers[$sym]['excerpts']) < 3) {
                    $tickers[$sym]['excerpts'][] = '@' . $handle . ': ' . mb_substr($signal['rationale'], 0, 160);
                }
                if (count($tickers[$sym]['links']) < 2) {
                    $tickers[$sym]['links'][] = 'https://x.com/' . $handle . '/status/' . $row['tweet_id'];
                }
            }
            foreach ((array) (isset($payload['themes']) ? $payload['themes'] : array()) as $theme) {
                $key = strtolower(trim((string) $theme));
                if ($key !== '') {
                    $themes[$key] = isset($themes[$key]) ? $themes[$key] + 1 : 1;
                }
            }
        }

        uasort($tickers, function ($a, $b) {
            return $b['mentions'] <=> $a['mentions'];
        });
        $tickers = array_slice($tickers, 0, 15, true);
        arsort($themes);
        $themes = array_slice($themes, 0, 10, true);

        // New vs reinforced calls in the window.
        $new_calls = array();
        foreach (WCPW_Recommendation_Repo::created_since($since) as $rec) {
            $meta = WCPW_Recommendation_Repo::meta($rec->ID);
            $new_calls[] = array(
                'ticker'     => $meta['ticker'],
                'kol'        => $meta['kol'] ? '@' . $meta['kol']['handle'] : '?',
                'direction'  => $meta['direction'],
                'confidence' => $meta['confidence'],
                'earliness'  => $meta['earliness_at_call'] ? $meta['earliness_at_call']['band'] : '',
                'reinforced' => $meta['reinforced_count'],
            );
        }

        // ---- Build the bounded pack ----
        $pack = "Window: last {$window_hours}h. Tracked KOL activity summary (pre-aggregated):\n\n";
        $pack .= "TICKERS:\n";
        foreach ($tickers as $sym => $agg) {
            $earliness = WCPW_Earliness::compute($sym);
            $stances = array();
            foreach ($agg['stances'] as $k => $count) {
                $stances[] = "{$k}×{$count}";
            }
            $pack .= sprintf(
                "$%s — %d mentions by %d KOLs (%s); earliness: %s\n",
                $sym, $agg['mentions'], count($agg['kols']), implode(', ', $stances),
                $earliness['band']
            );
            foreach ($agg['excerpts'] as $excerpt) {
                $pack .= "  · {$excerpt}\n";
            }
            foreach ($agg['links'] as $link) {
                $pack .= "  link: {$link}\n";
            }
        }
        $pack .= "\nNEW CALLS (deterministic, in-window):\n";
        if ($new_calls) {
            foreach ($new_calls as $call) {
                $pack .= sprintf(
                    "$%s %s by %s (conf %.2f, earliness %s%s)\n",
                    $call['ticker'], $call['direction'], $call['kol'], $call['confidence'], $call['earliness'],
                    $call['reinforced'] ? ", reinforced ×{$call['reinforced']}" : ''
                );
            }
        } else {
            $pack .= "none\n";
        }
        $pack .= "\nTHEMES (mention counts): ";
        foreach ($themes as $theme => $count) {
            $pack .= "{$theme}×{$count}, ";
        }
        $pack .= "\n";

        $system = 'You write a concise daily market digest from pre-aggregated tracked-KOL activity. '
            . 'Use ONLY the facts provided — do not invent tickers, prices or calls. Quote sparingly '
            . 'and reference the provided links for notable threads. Write markdown with EXACTLY these sections: '
            . "\n## Market pulse\n## New calls\n(table: ticker | KOL | direction | confidence | earliness)\n"
            . "## Repeat / reinforced calls\n## Tickers & theses\n## Themes to watch\n"
            . 'Keep it tight and information-dense.';

        $result = WCPW_LLM::call('digest', $system, $pack, array(
            'tier'       => 'strong',
            'max_tokens' => 3000,
            'raw_text'   => true,
        ));
        if (is_wp_error($result)) {
            return $result;
        }

        // GUARDRAIL (§12.1): saved as a DRAFT — never published by code.
        $post_id = wp_insert_post(array(
            'post_type'    => 'post',
            'post_status'  => 'draft',
            'post_title'   => sprintf('Wiretap Digest — %s (%dh window)', gmdate('Y-m-d'), $window_hours),
            'post_content' => $result['data'] . self::DISCLAIMER,
        ), true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        wp_set_post_tags($post_id, array('wcp-wiretap-digest'), true);
        WCPW_AI_Log::set_related($result['log_id'], $post_id);
        update_option('wcpw_last_digest_id', (int) $post_id, false);

        if (wcpw_get_setting('notify_digest')) {
            WCPW_Telegram::notify("📰 Wiretap digest ready (draft): " . get_edit_post_link($post_id, ''));
        }

        return $post_id;
    }

    /** Latest digest draft for the dashboard tab. */
    public static function latest() {
        $id = (int) get_option('wcpw_last_digest_id', 0);
        if ($id && get_post($id)) {
            return get_post($id);
        }
        $posts = get_posts(array(
            'post_type'      => 'post',
            'post_status'    => array('draft', 'publish'),
            'posts_per_page' => 1,
            'tag'            => 'wcp-wiretap-digest',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ));
        return $posts ? $posts[0] : null;
    }
}
