<?php
/**
 * Strict-JSON LLM gateway.
 *
 * Single choke point for every Anthropic call in the plugin: bounded input,
 * strict JSON output, schema validation BEFORE anything is persisted, and a
 * mandatory AI-log row (§7). Callers never talk to the API directly.
 *
 * GUARDRAIL (§12.1/§12.5): this class returns parsed proposals to callers and
 * writes nothing except the audit log row. Persistence of proposals (always
 * in proposal states) is the caller's job; acceptance is always a human's.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPW_LLM {

    const API_URL = 'https://api.anthropic.com/v1/messages';

    /**
     * Make a strict-JSON call.
     *
     * @param string $kind       classification|digest|plan_extraction|checkin|discovery_fit|ticker_resolution
     * @param string $system     System prompt (must demand JSON-only output).
     * @param string $user       Bounded context pack.
     * @param array  $opts {
     *   @type string $tier        'fast'|'strong' — picks the configured model. Default 'fast'.
     *   @type int    $max_tokens  Default 1024.
     *   @type int    $timeout     Default 60.
     *   @type array  $required    Top-level keys that must exist in the decoded object.
     *   @type string $expect      'object'|'array' — decoded JSON shape. Default 'object'.
     *   @type int    $related_id  Related object id for the log row.
     *   @type bool   $raw_text    If true, skip JSON parsing (digest markdown body). Default false.
     * }
     * @return array|WP_Error  { data, log_id, model } — data is decoded JSON (or raw text).
     */
    public static function call($kind, $system, $user, array $opts = array()) {
        $api_key = wcpw_anthropic_key();
        if (!$api_key) {
            return new WP_Error('not_configured', 'Anthropic API key not configured (Wiretap settings or Work Copilot core).');
        }

        $tier       = isset($opts['tier']) ? $opts['tier'] : 'fast';
        $model      = ($tier === 'strong') ? wcpw_get_setting('model_strong') : wcpw_get_setting('model_fast');
        $max_tokens = isset($opts['max_tokens']) ? (int) $opts['max_tokens'] : 1024;
        $timeout    = isset($opts['timeout']) ? (int) $opts['timeout'] : 60;
        $expect     = isset($opts['expect']) ? $opts['expect'] : 'object';
        $required   = isset($opts['required']) ? (array) $opts['required'] : array();
        $related_id = isset($opts['related_id']) ? (int) $opts['related_id'] : 0;
        $raw_text   = !empty($opts['raw_text']);

        $response = wp_remote_post(self::API_URL, array(
            'headers' => array(
                'Content-Type'      => 'application/json',
                'x-api-key'         => $api_key,   // GUARDRAIL (§12.8): key read from options, never logged below
                'anthropic-version' => '2023-06-01',
            ),
            'body' => wp_json_encode(array(
                'model'      => $model,
                'max_tokens' => $max_tokens,
                'system'     => $system,
                'messages'   => array(array('role' => 'user', 'content' => $user)),
            )),
            'timeout' => $timeout,
        ));

        if (is_wp_error($response)) {
            WCPW_AI_Log::insert($kind, $system, $user, array('error' => $response->get_error_message()), $model, 0, 0, $related_id);
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $msg = isset($body['error']['message']) ? $body['error']['message'] : 'Unknown API error';
            WCPW_AI_Log::insert($kind, $system, $user, array('error' => $msg, 'status' => $code), $model, 0, 0, $related_id);
            return new WP_Error('api_error', $msg, array('status' => $code));
        }

        $text       = isset($body['content'][0]['text']) ? $body['content'][0]['text'] : '';
        $tokens_in  = isset($body['usage']['input_tokens']) ? (int) $body['usage']['input_tokens'] : 0;
        $tokens_out = isset($body['usage']['output_tokens']) ? (int) $body['usage']['output_tokens'] : 0;

        if ($raw_text) {
            $log_id = WCPW_AI_Log::insert($kind, $system, $user, $text, $model, $tokens_in, $tokens_out, $related_id);
            return array('data' => $text, 'log_id' => $log_id, 'model' => $model);
        }

        $data = self::extract_json($text, $expect);
        if (is_wp_error($data)) {
            WCPW_AI_Log::insert($kind, $system, $user, array('parse_error' => $data->get_error_message(), 'raw' => mb_substr($text, 0, 2000)), $model, $tokens_in, $tokens_out, $related_id);
            return $data;
        }

        // Schema validation before anything persists (§7).
        foreach ($required as $key) {
            if (!array_key_exists($key, $data)) {
                $err = new WP_Error('schema_error', "LLM output missing required key: {$key}");
                WCPW_AI_Log::insert($kind, $system, $user, array('schema_error' => $key, 'raw' => $data), $model, $tokens_in, $tokens_out, $related_id);
                return $err;
            }
        }

        $log_id = WCPW_AI_Log::insert($kind, $system, $user, $data, $model, $tokens_in, $tokens_out, $related_id);

        return array('data' => $data, 'log_id' => $log_id, 'model' => $model);
    }

    /**
     * Pull the first JSON object/array out of a model reply, tolerating
     * markdown fences and prose wrappers.
     */
    private static function extract_json($text, $expect = 'object') {
        $text = preg_replace('/```(?:json)?\s*/i', '', (string) $text);
        $text = trim(str_replace('```', '', $text));

        $open  = ($expect === 'array') ? '[' : '{';
        $close = ($expect === 'array') ? ']' : '}';

        $start = strpos($text, $open);
        $end   = strrpos($text, $close);
        if ($start === false || $end === false || $end <= $start) {
            return new WP_Error('parse_error', 'No JSON ' . $expect . ' found in LLM reply');
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return new WP_Error('parse_error', 'Invalid JSON: ' . json_last_error_msg());
        }
        return $decoded;
    }
}
