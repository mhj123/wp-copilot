<?php
/**
 * Conversations Manager
 *
 * Manages persistent AI conversations (one per page)
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_Conversations_Manager {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Constructor - no hooks needed for now
    }

    /**
     * Get or create conversation for a page
     *
     * @param int $context_post_id The page ID
     * @param int $user_id The user ID
     * @return string|WP_Error conversation_id on success, WP_Error on failure
     */
    public function get_or_create_conversation($context_post_id, $user_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'wcp_ai_conversations';

        // Check if conversation already exists for this page/user
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT conversation_id FROM $table
            WHERE context_post_id = %d AND user_id = %d AND status = 'active'
            ORDER BY last_activity_at DESC LIMIT 1",
            $context_post_id,
            $user_id
        ));

        if ($existing) {
            // Touch the conversation to update last_activity_at
            $this->touch_conversation($existing->conversation_id);
            return $existing->conversation_id;
        }

        // Create new conversation
        $conversation_id = wp_generate_uuid4();

        $inserted = $wpdb->insert(
            $table,
            array(
                'conversation_id' => $conversation_id,
                'user_id' => $user_id,
                'context_post_id' => $context_post_id,
                'conversation_title' => null, // Will be set after first message
                'status' => 'active',
                'metadata' => json_encode(array())
            ),
            array('%s', '%d', '%d', '%s', '%s', '%s')
        );

        if ($inserted === false) {
            return new WP_Error('db_error', 'Failed to create conversation');
        }

        return $conversation_id;
    }

    /**
     * Add message to conversation
     *
     * @param string $conversation_id The conversation ID
     * @param string $role user|assistant|system
     * @param string $content Message content
     * @param array $metadata Optional metadata
     * @return int|WP_Error message_id on success, WP_Error on failure
     */
    public function add_message($conversation_id, $role, $content, $metadata = array()) {
        global $wpdb;

        $table = $wpdb->prefix . 'wcp_ai_messages';

        $inserted = $wpdb->insert(
            $table,
            array(
                'conversation_id' => $conversation_id,
                'role' => $role,
                'content' => $content,
                'metadata' => json_encode($metadata)
            ),
            array('%s', '%s', '%s', '%s')
        );

        if ($inserted === false) {
            return new WP_Error('db_error', 'Failed to add message');
        }

        $message_id = $wpdb->insert_id;

        // Update conversation title if this is the first user message
        if ($role === 'user') {
            $this->maybe_update_conversation_title($conversation_id, $content);
        }

        // Touch conversation to update last_activity_at
        $this->touch_conversation($conversation_id);

        return $message_id;
    }

    /**
     * Get conversation messages
     *
     * @param string $conversation_id The conversation ID
     * @param int $limit Maximum number of messages to return
     * @param int $offset Offset for pagination
     * @return array Array of message objects
     */
    public function get_messages($conversation_id, $limit = 50, $offset = 0) {
        global $wpdb;

        $table = $wpdb->prefix . 'wcp_ai_messages';

        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table
            WHERE conversation_id = %s
            ORDER BY timestamp ASC
            LIMIT %d OFFSET %d",
            $conversation_id,
            $limit,
            $offset
        ), ARRAY_A);

        // Decode metadata for each message
        foreach ($messages as &$message) {
            if (!empty($message['metadata'])) {
                $message['metadata'] = json_decode($message['metadata'], true);
            } else {
                $message['metadata'] = array();
            }
        }

        return $messages;
    }

    /**
     * Get conversation details
     *
     * @param string $conversation_id The conversation ID
     * @return object|null Conversation object or null if not found
     */
    public function get_conversation($conversation_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'wcp_ai_conversations';

        $conversation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE conversation_id = %s",
            $conversation_id
        ));

        if ($conversation && !empty($conversation->metadata)) {
            $conversation->metadata = json_decode($conversation->metadata, true);
        }

        return $conversation;
    }

    /**
     * Archive conversation
     *
     * @param string $conversation_id The conversation ID
     * @return bool True on success, false on failure
     */
    public function archive_conversation($conversation_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'wcp_ai_conversations';

        $updated = $wpdb->update(
            $table,
            array('status' => 'archived'),
            array('conversation_id' => $conversation_id),
            array('%s'),
            array('%s')
        );

        return $updated !== false;
    }

    /**
     * Update last activity timestamp
     *
     * @param string $conversation_id The conversation ID
     * @return void
     */
    private function touch_conversation($conversation_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'wcp_ai_conversations';

        // MySQL will auto-update last_activity_at via ON UPDATE CURRENT_TIMESTAMP
        // But we need to trigger an update
        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET last_activity_at = CURRENT_TIMESTAMP WHERE conversation_id = %s",
            $conversation_id
        ));
    }

    /**
     * Generate and update conversation title from first user message
     *
     * @param string $conversation_id The conversation ID
     * @param string $first_message First user message
     * @return void
     */
    private function maybe_update_conversation_title($conversation_id, $first_message) {
        global $wpdb;

        $table = $wpdb->prefix . 'wcp_ai_conversations';

        // Check if title is already set
        $conversation = $this->get_conversation($conversation_id);
        if ($conversation && !empty($conversation->conversation_title)) {
            return; // Title already set
        }

        // Generate title from first message (truncate to 50 chars)
        $title = $this->generate_conversation_title($first_message);

        $wpdb->update(
            $table,
            array('conversation_title' => $title),
            array('conversation_id' => $conversation_id),
            array('%s'),
            array('%s')
        );
    }

    /**
     * Generate conversation title from message content
     *
     * @param string $message Message content
     * @return string Generated title
     */
    private function generate_conversation_title($message) {
        // Strip HTML and trim
        $title = wp_strip_all_tags($message);
        $title = trim($title);

        // Truncate to 50 characters
        if (strlen($title) > 50) {
            $title = substr($title, 0, 47) . '...';
        }

        // Default if empty
        if (empty($title)) {
            $title = 'Untitled conversation';
        }

        return $title;
    }

    /**
     * Get message count for a conversation
     *
     * @param string $conversation_id The conversation ID
     * @return int Message count
     */
    public function get_message_count($conversation_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'wcp_ai_messages';

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE conversation_id = %s",
            $conversation_id
        ));

        return (int) $count;
    }
}
