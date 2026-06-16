<?php
/**
 * Delegation REST API
 *
 * Namespace wcp-delegation/v1. The same permission check serves both the
 * theme UI (cookie + X-WP-Nonce) and the Hermes agent (Basic auth via a
 * WordPress Application Password). All routes 403 when the feature toggle
 * is off — a one-click kill-switch for the agent surface.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPD_REST_API {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        $namespace = 'wcp-delegation/v1';

        // User-facing: delegate an item (multipart: instruction + files[])
        register_rest_route($namespace, '/items/(?P<item_id>\d+)/delegate', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'delegate_item'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Agent-facing: list delegations (polling fallback), ?status= filter
        register_rest_route($namespace, '/delegations', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'list_delegations'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Agent-facing: full work packet
        register_rest_route($namespace, '/delegations/(?P<delegation_id>[a-zA-Z0-9_-]+)', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_delegation_packet'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Agent-facing: status update (+ question when status=needs_input)
        register_rest_route($namespace, '/delegations/(?P<delegation_id>[a-zA-Z0-9_-]+)/status', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'update_delegation_status'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Agent-facing: artifact upload (multipart files[])
        register_rest_route($namespace, '/delegations/(?P<delegation_id>[a-zA-Z0-9_-]+)/artifacts', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'upload_delegation_artifacts'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // User-facing: answer an agent question (clarification loop)
        register_rest_route($namespace, '/delegations/(?P<delegation_id>[a-zA-Z0-9_-]+)/answer', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'answer_delegation_question'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // User-facing: create a context review from the AI assistant widget
        register_rest_route($namespace, '/reviews', array(
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'create_review'),
                'permission_callback' => array($this, 'check_permission'),
            ),
            // Agent-facing: list reviews (polling fallback), ?status= filter
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'list_reviews'),
                'permission_callback' => array($this, 'check_permission'),
            ),
        ));

        // Agent-facing: full review work packet
        register_rest_route($namespace, '/reviews/(?P<review_id>[a-zA-Z0-9_-]+)', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_review_packet'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Agent-facing: review status update (+ report → appended to chat)
        register_rest_route($namespace, '/reviews/(?P<review_id>[a-zA-Z0-9_-]+)/status', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'update_review_status'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Agent-facing: create content. Gated by the extra wcpd_allow_create toggle.
        register_rest_route($namespace, '/create/page', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'create_page'),
            'permission_callback' => array($this, 'check_create_permission'),
        ));

        register_rest_route($namespace, '/create/heading', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'create_heading'),
            'permission_callback' => array($this, 'check_create_permission'),
        ));

        register_rest_route($namespace, '/create/item', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'create_item'),
            'permission_callback' => array($this, 'check_create_permission'),
        ));
    }

    public function check_create_permission() {
        return WCPD_Delegation_Manager::instance()->is_create_enabled() && current_user_can('edit_posts');
    }

    public function create_page($request) {
        $result = WCPD_Delegation_Manager::instance()->create_page(
            $request->get_param('title') ?: '',
            $request->get_param('content') ?: '',
            (int) $request->get_param('parent_id')
        );
        return is_wp_error($result) ? $result : rest_ensure_response($result);
    }

    public function create_heading($request) {
        $result = WCPD_Delegation_Manager::instance()->create_heading(
            $request->get_param('title') ?: '',
            (int) $request->get_param('parent_id'),
            sanitize_key($request->get_param('parent_type') ?: ''),
            $request->get_param('content') ?: ''
        );
        return is_wp_error($result) ? $result : rest_ensure_response($result);
    }

    public function create_item($request) {
        $result = WCPD_Delegation_Manager::instance()->create_item(array(
            'title'             => $request->get_param('title') ?: '',
            'content'           => $request->get_param('content') ?: '',
            'item_type'         => $request->get_param('item_type') ?: '',
            'status'            => $request->get_param('status') ?: '',
            'priority'          => $request->get_param('priority') ?: '',
            'tags'              => $request->get_param('tags') ?: '',
            'due_date'          => $request->get_param('due_date') ?: '',
            'context_id'        => (int) $request->get_param('context_id'),
            'parent_heading_id' => (int) $request->get_param('parent_heading_id'),
            'parent_page_id'    => (int) $request->get_param('parent_page_id'),
        ));
        return is_wp_error($result) ? $result : rest_ensure_response($result);
    }

    public function check_permission() {
        return WCPD_Delegation_Manager::instance()->is_enabled() && current_user_can('edit_posts');
    }

    public function delegate_item($request) {
        $item_id     = (int) $request->get_param('item_id');
        $instruction = $request->get_param('instruction');
        $files       = $request->get_file_params();

        $result = WCPD_Delegation_Manager::instance()->create_delegation($item_id, $instruction, $files);
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array(
            'success'        => true,
            'delegation'     => $result['delegation'],
            'telegram_sent'  => $result['telegram_sent'],
            'telegram_error' => $result['telegram_error'],
            'skipped_files'  => $result['skipped_files'],
        ));
    }

    public function list_delegations($request) {
        $status = sanitize_key($request->get_param('status') ?: '');
        return rest_ensure_response(array(
            'success'     => true,
            'delegations' => WCPD_Delegation_Manager::instance()->list_delegations($status),
        ));
    }

    public function get_delegation_packet($request) {
        $packet = WCPD_Delegation_Manager::instance()->build_packet($request->get_param('delegation_id'));
        if (is_wp_error($packet)) {
            return $packet;
        }
        return rest_ensure_response($packet);
    }

    public function update_delegation_status($request) {
        $result = WCPD_Delegation_Manager::instance()->update_status(
            $request->get_param('delegation_id'),
            sanitize_key($request->get_param('status') ?: ''),
            $request->get_param('message') ?: '',
            $request->get_param('report') ?: '',
            $request->get_param('question') ?: ''
        );

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array('success' => true, 'delegation' => $result));
    }

    public function upload_delegation_artifacts($request) {
        $result = WCPD_Delegation_Manager::instance()->add_artifacts(
            $request->get_param('delegation_id'),
            $request->get_file_params()
        );

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array(
            'success'   => true,
            'artifacts' => $result['artifacts'],
            'skipped'   => $result['skipped'],
        ));
    }

    public function answer_delegation_question($request) {
        $result = WCPD_Delegation_Manager::instance()->answer_question(
            $request->get_param('delegation_id'),
            $request->get_param('question_id') ?: '',
            $request->get_param('answer') ?: ''
        );

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array('success' => true, 'delegation' => $result));
    }

    public function create_review($request) {
        $result = WCPD_Delegation_Manager::instance()->create_review(
            $request->get_param('conversation_id') ?: '',
            (int) $request->get_param('page_id'),
            sanitize_key($request->get_param('context_mode') ?: 'page'),
            $request->get_param('selected_pages') ?: array(),
            $request->get_param('instruction') ?: ''
        );

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array(
            'success'        => true,
            'review'         => $result['review'],
            'telegram_sent'  => $result['telegram_sent'],
            'telegram_error' => $result['telegram_error'],
        ));
    }

    public function list_reviews($request) {
        $status = sanitize_key($request->get_param('status') ?: '');
        return rest_ensure_response(array(
            'success' => true,
            'reviews' => WCPD_Delegation_Manager::instance()->list_reviews($status),
        ));
    }

    public function get_review_packet($request) {
        $packet = WCPD_Delegation_Manager::instance()->build_review_packet($request->get_param('review_id'));
        if (is_wp_error($packet)) {
            return $packet;
        }
        return rest_ensure_response($packet);
    }

    public function update_review_status($request) {
        $result = WCPD_Delegation_Manager::instance()->update_review_status(
            $request->get_param('review_id'),
            sanitize_key($request->get_param('status') ?: ''),
            $request->get_param('message') ?: '',
            $request->get_param('report') ?: ''
        );

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array('success' => true, 'review' => $result));
    }
}
