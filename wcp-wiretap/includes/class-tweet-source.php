<?php
/**
 * X API v2 client (§9).
 *
 * All provider access is wrapped here so a future adapter swap is contained.
 * Every tweet object returned by the API is metered as one read against the
 * monthly budget. Rate limits are respected with a stored backoff timestamp.
 *
 * GUARDRAIL (§12.8): the bearer token is read from options at call time and
 * never written to any log or snapshot.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPW_Tweet_Source {

    const BASE = 'https://api.twitter.com/2';

    const TWEET_FIELDS = 'created_at,conversation_id,referenced_tweets,entities,public_metrics,author_id,text';

    /** True while a 429 backoff window is active. */
    public static function backing_off() {
        return time() < (int) get_option('wcpw_x_backoff_until', 0);
    }

    /**
     * Low-level GET. Returns decoded body array or WP_Error.
     * Meters reads for every tweet object in data/includes.
     */
    private static function get($path, array $params = array()) {
        $token = wcpw_get_setting('x_bearer_token');
        if (!$token) {
            return new WP_Error('not_configured', 'X API bearer token not configured.');
        }
        if (self::backing_off()) {
            return new WP_Error('rate_limited', 'X API rate limit backoff active — resuming later.');
        }

        $url = self::BASE . $path;
        if ($params) {
            $url .= '?' . http_build_query($params);
        }

        $response = wp_remote_get($url, array(
            'headers' => array('Authorization' => 'Bearer ' . $token),
            'timeout' => 30,
        ));
        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 429) {
            // Respect rate-limit headers with backoff + resume (§6).
            $reset = (int) wp_remote_retrieve_header($response, 'x-rate-limit-reset');
            update_option('wcpw_x_backoff_until', $reset > time() ? $reset : time() + 15 * MINUTE_IN_SECONDS, false);
            return new WP_Error('rate_limited', 'X API rate limited (429); backoff stored.');
        }
        if ($code === 403) {
            $msg = isset($body['detail']) ? $body['detail'] : 'Forbidden — this endpoint may require a higher X API tier.';
            return new WP_Error('forbidden', $msg, array('status' => 403));
        }
        if ($code !== 200) {
            $msg = isset($body['detail']) ? $body['detail'] : (isset($body['title']) ? $body['title'] : 'X API error');
            return new WP_Error('x_api_error', $msg, array('status' => $code));
        }

        // Meter: each returned tweet object = one pay-per-use read (§9).
        $reads = 0;
        if (isset($body['data']) && is_array($body['data']) && isset($body['data'][0])) {
            $reads += count($body['data']);
        }
        if (isset($body['includes']['tweets'])) {
            $reads += count($body['includes']['tweets']);
        }
        if ($reads) {
            wcpw_add_reads($reads);
        }

        return is_array($body) ? $body : array();
    }

    /** Resolve @handle → user object {id, username, name, description, ...}. */
    public static function resolve_user($handle) {
        $handle = ltrim(trim($handle), '@');
        $body = self::get('/users/by/username/' . rawurlencode($handle), array(
            'user.fields' => 'id,username,name,description,public_metrics,protected',
        ));
        if (is_wp_error($body)) {
            return $body;
        }
        if (empty($body['data']['id'])) {
            return new WP_Error('user_not_found', 'X user not found: @' . $handle);
        }
        return $body['data'];
    }

    /**
     * Fetch a user's recent tweets since a marker, with quoted tweets expanded.
     *
     * @return array|WP_Error { tweets: [], quoted: [tweet_id => text], user_error: ''|suspended|... }
     */
    public static function fetch_user_tweets($user_id, $since_id = '', $start_time = '') {
        $params = array(
            'max_results'  => 100,
            'tweet.fields' => self::TWEET_FIELDS,
            'expansions'   => 'referenced_tweets.id,referenced_tweets.id.author_id',
        );
        if ($since_id) {
            $params['since_id'] = $since_id;
        } elseif ($start_time) {
            $params['start_time'] = $start_time;
        }

        $body = self::get('/users/' . rawurlencode($user_id) . '/tweets', $params);
        if (is_wp_error($body)) {
            return $body;
        }

        $quoted = array();
        if (!empty($body['includes']['tweets'])) {
            foreach ($body['includes']['tweets'] as $inc) {
                $quoted[$inc['id']] = $inc['text'];
            }
        }

        return array(
            'tweets' => isset($body['data']) ? $body['data'] : array(),
            'quoted' => $quoted,
        );
    }

    /** Members of an X List (bulk KOL import, F2). */
    public static function get_list_members($list_id) {
        $body = self::get('/lists/' . rawurlencode($list_id) . '/members', array(
            'max_results' => 100,
            'user.fields' => 'id,username,name,description',
        ));
        if (is_wp_error($body)) {
            return $body;
        }
        return isset($body['data']) ? $body['data'] : array();
    }

    /**
     * One page of a user's following list, capped (graph triangulation, F3.2).
     * Returns array of user objects with bios for the topical-fit pass.
     */
    public static function get_following($user_id, $max = 1000) {
        $body = self::get('/users/' . rawurlencode($user_id) . '/following', array(
            'max_results' => min(1000, max(1, (int) $max)),
            'user.fields' => 'id,username,name,description,public_metrics',
        ));
        if (is_wp_error($body)) {
            return $body;
        }
        return isset($body['data']) ? $body['data'] : array();
    }

    /**
     * Capped full-archive cashtag search (earliest-callers, F3.3).
     * Requires a paid X API tier; callers must handle the 'forbidden' error
     * by falling back to the ingested corpus.
     */
    public static function search_all($query, $start_time, $end_time, $max_total = 500) {
        $collected = array();
        $next_token = '';
        $max_total = max(10, (int) $max_total);

        do {
            $params = array(
                'query'        => $query,
                'max_results'  => min(500, $max_total - count($collected)),
                'tweet.fields' => self::TWEET_FIELDS,
                'expansions'   => 'author_id',
                'user.fields'  => 'id,username,name,public_metrics',
            );
            if ($start_time) {
                $params['start_time'] = $start_time;
            }
            if ($end_time) {
                $params['end_time'] = $end_time;
            }
            if ($next_token) {
                $params['next_token'] = $next_token;
            }

            $body = self::get('/tweets/search/all', $params);
            if (is_wp_error($body)) {
                // Partial results are still useful.
                if ($collected) {
                    break;
                }
                return $body;
            }

            $users = array();
            if (!empty($body['includes']['users'])) {
                foreach ($body['includes']['users'] as $u) {
                    $users[$u['id']] = $u;
                }
            }
            foreach ((array) (isset($body['data']) ? $body['data'] : array()) as $tweet) {
                $tweet['author'] = isset($users[$tweet['author_id']]) ? $users[$tweet['author_id']] : null;
                $collected[] = $tweet;
            }

            $next_token = isset($body['meta']['next_token']) ? $body['meta']['next_token'] : '';
        } while ($next_token && count($collected) < $max_total);

        return $collected;
    }
}
