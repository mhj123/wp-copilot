<?php
/**
 * REST object-level authorization helper.
 *
 * The blanket `edit_posts` gate on every route (class-rest-api.php:371)
 * only proves the caller can create content somewhere — it says nothing
 * about the specific object a write endpoint is about to act on. This
 * closes that gap for endpoints that take a caller-supplied ID.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_REST_Auth {

    /**
     * Assert the current user holds $cap on the object identified by $id.
     *
     * @param int    $id  Post ID the request is about to act on.
     * @param string $cap A meta capability that accepts an object ID as its
     *                    second arg — 'edit_post', 'delete_post', 'read_post'.
     * @return true|WP_Error
     */
    public static function require_object($id, $cap) {
        $id = (int) $id;

        if (!$id) {
            return new WP_Error('invalid_id', 'Missing or invalid ID', array('status' => 400));
        }

        if (!current_user_can($cap, $id)) {
            return new WP_Error('forbidden', 'Permission denied', array('status' => 403));
        }

        return true;
    }
}
