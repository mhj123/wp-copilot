<?php
/**
 * Graph repository — the single custom table (edges) and all access to it.
 *
 * An edge is a triple: subject (wp_posts.ID) — predicate (wcp_predicate
 * term_id) — object (wp_posts.ID, or a literal string, never both).
 * Invariants live here, not in the schema: exactly one of object_id /
 * object_value set, no duplicate triples, edges removed when either
 * endpoint post or the predicate term is deleted.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPG_Graph_Repository {

    private static $instance = null;

    /** Post types that count as graph entities. */
    const ENTITY_POST_TYPES = array('post', 'page', 'wcp_heading');

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('before_delete_post', array($this, 'on_post_deleted'));
        add_action('pre_delete_term', array($this, 'on_term_deleted'), 10, 2);
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'wcp_edges';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table           = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            subject_id BIGINT UNSIGNED NOT NULL,
            predicate_id BIGINT UNSIGNED NOT NULL,
            object_id BIGINT UNSIGNED NULL,
            object_value TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY subject (subject_id, predicate_id),
            KEY object (object_id, predicate_id),
            KEY predicate (predicate_id)
        ) {$charset_collate};";

        dbDelta($sql);
        update_option('wcpg_db_version', WCPG_VERSION);
    }

    /**
     * Create an edge. Pass either $object_id or $object_value.
     *
     * @return array|WP_Error The created edge row.
     */
    public function add_edge($subject_id, $predicate_label, $object_id = 0, $object_value = '') {
        global $wpdb;

        $subject_id   = (int) $subject_id;
        $object_id    = (int) $object_id;
        $object_value = is_string($object_value) ? trim(wp_strip_all_tags($object_value)) : '';

        if (!$this->is_entity($subject_id)) {
            return new WP_Error('wcpg_bad_subject', __('Subject is not a graph entity.', 'wcp-graph'), array('status' => 400));
        }
        if ($object_id && '' !== $object_value) {
            return new WP_Error('wcpg_two_objects', __('An edge has an entity object or a literal value, not both.', 'wcp-graph'), array('status' => 400));
        }
        if (!$object_id && '' === $object_value) {
            return new WP_Error('wcpg_no_object', __('An edge needs an object: an entity or a literal value.', 'wcp-graph'), array('status' => 400));
        }
        if ($object_id && !$this->is_entity($object_id)) {
            return new WP_Error('wcpg_bad_object', __('Object is not a graph entity.', 'wcp-graph'), array('status' => 400));
        }
        if ($object_id && $object_id === $subject_id) {
            return new WP_Error('wcpg_self_edge', __('An entity cannot relate to itself.', 'wcp-graph'), array('status' => 400));
        }

        $predicate = WCPG_Predicates::instance()->get_or_create($predicate_label);
        if (is_wp_error($predicate)) {
            return $predicate;
        }

        if ($this->edge_exists($subject_id, $predicate->term_id, $object_id, $object_value)) {
            return new WP_Error('wcpg_duplicate', __('This connection already exists.', 'wcp-graph'), array('status' => 409));
        }

        $now      = current_time('mysql');
        $inserted = $wpdb->insert(
            self::table(),
            array(
                'subject_id'   => $subject_id,
                'predicate_id' => $predicate->term_id,
                'object_id'    => $object_id ? $object_id : null,
                'object_value' => $object_id ? null : $object_value,
                'created_at'   => $now,
                'updated_at'   => $now,
            )
        );

        if (false === $inserted) {
            return new WP_Error('wcpg_db_error', __('Could not save the connection.', 'wcp-graph'), array('status' => 500));
        }

        return $this->format_edge($this->get_edge($wpdb->insert_id));
    }

    public function delete_edge($edge_id) {
        global $wpdb;
        $deleted = $wpdb->delete(self::table(), array('id' => (int) $edge_id));
        if (!$deleted) {
            return new WP_Error('wcpg_not_found', __('Connection not found.', 'wcp-graph'), array('status' => 404));
        }
        return true;
    }

    public function get_edge($edge_id) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int) $edge_id));
    }

    /** All edges where the post is subject (outbound) or object (inbound). */
    public function edges_for_post($post_id) {
        global $wpdb;
        $table   = self::table();
        $post_id = (int) $post_id;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE subject_id = %d OR object_id = %d ORDER BY created_at ASC",
            $post_id,
            $post_id
        ));

        $out = array('outbound' => array(), 'inbound' => array());
        foreach ($rows as $row) {
            $edge = $this->format_edge($row);
            if ((int) $row->subject_id === $post_id) {
                $out['outbound'][] = $edge;
            } else {
                $out['inbound'][] = $edge;
            }
        }
        return $out;
    }

    /**
     * Edges by predicate, optionally limited to subjects that are
     * descendants of a structural taxonomy term (the table query).
     */
    public function edges_by_predicate($predicate_slug, $subject_ids = array()) {
        global $wpdb;
        $table = self::table();

        $predicate = get_term_by('slug', sanitize_title($predicate_slug), WCPG_Predicates::TAXONOMY);
        if (!$predicate) {
            return array();
        }

        $sql    = "SELECT * FROM {$table} WHERE predicate_id = %d";
        $params = array($predicate->term_id);

        if (!empty($subject_ids)) {
            $placeholders = implode(',', array_fill(0, count($subject_ids), '%d'));
            $sql         .= " AND subject_id IN ({$placeholders})";
            $params       = array_merge($params, array_map('intval', $subject_ids));
        }

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params));
        return array_map(array($this, 'format_edge'), $rows);
    }

    public function edge_exists($subject_id, $predicate_id, $object_id, $object_value) {
        global $wpdb;
        $table = self::table();

        if ($object_id) {
            $sql = $wpdb->prepare(
                "SELECT id FROM {$table} WHERE subject_id = %d AND predicate_id = %d AND object_id = %d",
                $subject_id, $predicate_id, $object_id
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT id FROM {$table} WHERE subject_id = %d AND predicate_id = %d AND object_value = %s",
                $subject_id, $predicate_id, $object_value
            );
        }
        return (bool) $wpdb->get_var($sql);
    }

    public function is_entity($post_id) {
        $post = get_post($post_id);
        return $post
            && in_array($post->post_type, self::ENTITY_POST_TYPES, true)
            && 'trash' !== $post->post_status;
    }

    /** Shape an edge row for the REST API / panel UI. */
    public function format_edge($row) {
        if (!$row) {
            return null;
        }

        $predicate = get_term((int) $row->predicate_id, WCPG_Predicates::TAXONOMY);
        $edge      = array(
            'id'              => (int) $row->id,
            'subject_id'      => (int) $row->subject_id,
            'subject_title'   => get_the_title((int) $row->subject_id),
            'subject_url'     => get_permalink((int) $row->subject_id),
            'predicate'       => $predicate ? $predicate->name : '',
            'predicate_slug'  => $predicate ? $predicate->slug : '',
            'inverse_label'   => $predicate ? (string) get_term_meta($predicate->term_id, 'inverse_label', true) : '',
            'object_id'       => $row->object_id ? (int) $row->object_id : null,
            'object_value'    => $row->object_value,
        );

        if ($edge['object_id']) {
            $edge['object_title'] = get_the_title($edge['object_id']);
            $edge['object_url']   = get_permalink($edge['object_id']);
        }

        return $edge;
    }

    /** Cascade: a deleted post takes its edges with it, both directions. */
    public function on_post_deleted($post_id) {
        global $wpdb;
        $table = self::table();
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE subject_id = %d OR object_id = %d",
            (int) $post_id,
            (int) $post_id
        ));
    }

    /** Cascade: a deleted predicate takes its edges with it. */
    public function on_term_deleted($term_id, $taxonomy) {
        if (WCPG_Predicates::TAXONOMY !== $taxonomy) {
            return;
        }
        global $wpdb;
        $wpdb->delete(self::table(), array('predicate_id' => (int) $term_id));
    }
}
