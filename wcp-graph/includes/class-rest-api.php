<?php
/**
 * Graph REST API
 *
 * Namespace wcp-graph/v1. Same auth model as wcp-delegation: cookie +
 * X-WP-Nonce from the theme, Basic auth via Application Password for the
 * agent. The agent gets read access to the whole graph; writes go through
 * the same routes the panel uses, but per the human-in-the-loop principle
 * AI-driven edge *proposals* will live in a later layer — these routes are
 * direct user actions.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPG_REST_API {

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
        $namespace = 'wcp-graph/v1';

        // Edges for one post, both directions (the Connections panel query)
        register_rest_route($namespace, '/posts/(?P<post_id>\d+)/edges', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_post_edges'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // All edges with a predicate, optionally scoped (the table query)
        register_rest_route($namespace, '/edges', array(
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'list_edges'),
                'permission_callback' => array($this, 'check_permission'),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'create_edge'),
                'permission_callback' => array($this, 'check_permission'),
            ),
        ));

        register_rest_route($namespace, '/edges/(?P<edge_id>\d+)', array(
            'methods'             => 'DELETE',
            'callback'            => array($this, 'delete_edge'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Predicate vocabulary, for autocomplete
        register_rest_route($namespace, '/predicates', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'list_predicates'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Entity search, for the object picker
        register_rest_route($namespace, '/entities', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'search_entities'),
            'permission_callback' => array($this, 'check_permission'),
        ));
    }

    public function check_permission() {
        return current_user_can('edit_posts');
    }

    public function get_post_edges($request) {
        $post_id = (int) $request->get_param('post_id');
        $repo    = WCPG_Graph_Repository::instance();

        if (!$repo->is_entity($post_id)) {
            return new WP_Error('wcpg_bad_post', __('Not a graph entity.', 'wcp-graph'), array('status' => 404));
        }

        return rest_ensure_response($repo->edges_for_post($post_id));
    }

    public function list_edges($request) {
        $predicate = sanitize_title($request->get_param('predicate') ?: '');
        if ('' === $predicate) {
            return new WP_Error('wcpg_no_predicate', __('Pass ?predicate=slug.', 'wcp-graph'), array('status' => 400));
        }

        $edges = WCPG_Graph_Repository::instance()->edges_by_predicate($predicate);
        return rest_ensure_response(array('edges' => $edges));
    }

    public function create_edge($request) {
        $edge = WCPG_Graph_Repository::instance()->add_edge(
            (int) $request->get_param('subject_id'),
            (string) $request->get_param('predicate'),
            (int) $request->get_param('object_id'),
            (string) $request->get_param('object_value')
        );

        if (is_wp_error($edge)) {
            return $edge;
        }
        return rest_ensure_response(array('success' => true, 'edge' => $edge));
    }

    public function delete_edge($request) {
        $result = WCPG_Graph_Repository::instance()->delete_edge((int) $request->get_param('edge_id'));
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response(array('success' => true));
    }

    public function list_predicates() {
        return rest_ensure_response(array('predicates' => WCPG_Predicates::instance()->all()));
    }

    public function search_entities($request) {
        $query = sanitize_text_field($request->get_param('q') ?: '');
        if (strlen($query) < 2) {
            return rest_ensure_response(array('entities' => array()));
        }

        $posts = get_posts(array(
            's'              => $query,
            'post_type'      => WCPG_Graph_Repository::ENTITY_POST_TYPES,
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            'orderby'        => 'relevance',
        ));

        $entities = array_map(function ($post) {
            return array(
                'id'    => $post->ID,
                'title' => get_the_title($post),
                'type'  => $post->post_type,
                'url'   => get_permalink($post),
            );
        }, $posts);

        return rest_ensure_response(array('entities' => $entities));
    }
}
