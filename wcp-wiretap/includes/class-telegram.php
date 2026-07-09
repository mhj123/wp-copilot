<?php
/**
 * Telegram notifications — NOTIFY-ONLY.
 *
 * GUARDRAIL (§12.4): Telegram is one-way. Messages never contain inline
 * keyboards, action buttons, or callback data. Approvals happen only in the
 * dashboard.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPW_Telegram {

    /**
     * Send a plain-text message. Returns true or WP_Error.
     */
    public static function notify($text) {
        $token   = wcpw_get_setting('telegram_bot_token');
        $chat_id = wcpw_get_setting('telegram_chat_id');
        if (!$token || !$chat_id) {
            return new WP_Error('telegram_not_configured', 'Telegram bot token or chat ID not set');
        }

        $response = wp_remote_post('https://api.telegram.org/bot' . $token . '/sendMessage', array(
            'timeout' => 15,
            'body'    => array(
                'chat_id'                  => $chat_id,
                'text'                     => (string) $text,
                'disable_web_page_preview' => true,
                // GUARDRAIL (§12.4): no reply_markup — ever.
            ),
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['ok'])) {
            $desc = isset($body['description']) ? $body['description'] : 'unknown error';
            return new WP_Error('telegram_error', 'Telegram API error: ' . $desc);
        }
        return true;
    }

    public static function test() {
        return self::notify('Work Copilot Wiretap: test message — your Telegram settings work.');
    }
}
