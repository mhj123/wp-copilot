<?php
/**
 * Structure reader — layer 1 of the graph.
 *
 * Pages, headings and items already form a containment hierarchy in native
 * WordPress state (post_parent, _wcp_parent_id meta, wcp_context term
 * assignments). This class reads that structure as derived `contains` /
 * `within` edges at query time. Nothing here is ever written to the edges
 * table: derived edges cannot drift from the structure because they ARE
 * the structure, read through a different lens.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPG_Structure {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** The wcp_context term mirroring a page or heading, or null. */
    public function context_term($ref_type, $ref_id) {
        $terms = get_terms(array(
            'taxonomy'   => 'wcp_context',
            'hide_empty' => false,
            'number'     => 1,
            'meta_query' => array(
                array('key' => 'wcp_ref_type', 'value' => $ref_type),
                array('key' => 'wcp_ref_id', 'value' => (int) $ref_id),
            ),
        ));
        return (!is_wp_error($terms) && !empty($terms)) ? $terms[0] : null;
    }

    /** Items assigned directly to a context term (no descendants). */
    public function items_in_context($term_id) {
        if (!$term_id) {
            return array();
        }
        return get_posts(array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => array('menu_order' => 'ASC', 'date' => 'ASC'),
            'tax_query'      => array(
                array(
                    'taxonomy'         => 'wcp_context',
                    'field'            => 'term_id',
                    'terms'            => (int) $term_id,
                    'include_children' => false,
                ),
            ),
        ));
    }

    public function headings_for_page($page_id) {
        return get_posts(array(
            'post_type'      => 'wcp_heading',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'menu_order title',
            'order'          => 'ASC',
            'meta_query'     => array(
                array('key' => '_wcp_parent_type', 'value' => 'page'),
                array('key' => '_wcp_parent_id', 'value' => (int) $page_id),
            ),
        ));
    }

    /** Items contained by a page (heading_id = 0) or by one heading. */
    public function items_for_scope($page_id, $heading_id = 0) {
        $term = $heading_id
            ? $this->context_term('wcp_heading', $heading_id)
            : $this->context_term('page', $page_id);
        return $term ? $this->items_in_context($term->term_id) : array();
    }

    /** Pages/headings whose context terms an item is assigned to. */
    public function containers_of_item($item_id) {
        $terms = wp_get_post_terms($item_id, 'wcp_context');
        if (is_wp_error($terms)) {
            return array();
        }

        $containers = array();
        foreach ($terms as $term) {
            $ref_type = get_term_meta($term->term_id, 'wcp_ref_type', true);
            $ref_id   = (int) get_term_meta($term->term_id, 'wcp_ref_id', true);
            if (!$ref_id || !in_array($ref_type, array('page', 'wcp_heading'), true)) {
                continue;
            }
            $container = get_post($ref_id);
            if ($container && 'publish' === $container->post_status) {
                $containers[] = $container;
            }
        }
        return $containers;
    }

    /**
     * Derived edges for a post, shaped like stored edges plus
     * `derived: true` (and no id — they cannot be deleted, only the
     * structure they reflect can change).
     */
    public function derived_edges_for($post_id) {
        $post = get_post($post_id);
        $out  = array('outbound' => array(), 'inbound' => array());
        if (!$post) {
            return $out;
        }

        if ('page' === $post->post_type) {
            foreach (get_pages(array('parent' => $post_id, 'post_status' => 'publish')) as $child) {
                $out['outbound'][] = $this->contains_edge($post_id, $child->ID);
            }
            foreach ($this->headings_for_page($post_id) as $heading) {
                $out['outbound'][] = $this->contains_edge($post_id, $heading->ID);
            }
            foreach ($this->items_for_scope($post_id) as $item) {
                $out['outbound'][] = $this->contains_edge($post_id, $item->ID);
            }
            if ($post->post_parent) {
                $out['inbound'][] = $this->contains_edge($post->post_parent, $post_id);
            }
        } elseif ('wcp_heading' === $post->post_type) {
            $term = $this->context_term('wcp_heading', $post_id);
            if ($term) {
                foreach ($this->items_in_context($term->term_id) as $item) {
                    $out['outbound'][] = $this->contains_edge($post_id, $item->ID);
                }
            }
            $parent_id = (int) get_post_meta($post_id, '_wcp_parent_id', true);
            if ($parent_id) {
                $out['inbound'][] = $this->contains_edge($parent_id, $post_id);
            }
        } elseif ('post' === $post->post_type) {
            foreach ($this->containers_of_item($post_id) as $container) {
                $out['inbound'][] = $this->contains_edge($container->ID, $post_id);
            }
        }

        return $out;
    }

    private function contains_edge($subject_id, $object_id) {
        return array(
            'id'             => 0,
            'derived'        => true,
            'subject_id'     => (int) $subject_id,
            'subject_title'  => get_the_title($subject_id),
            'subject_url'    => get_permalink($subject_id),
            'predicate'      => 'contains',
            'predicate_slug' => 'contains',
            'inverse_label'  => 'within',
            'object_id'      => (int) $object_id,
            'object_title'   => get_the_title($object_id),
            'object_url'     => get_permalink($object_id),
            'object_value'   => null,
        );
    }
}
