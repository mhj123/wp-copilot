<?php
/**
 * CSV Exporter
 *
 * Exports the Work Copilot knowledge tree (pages → subpages → headings → items)
 * as CSV, preserving the relational structure as columns. Walks the hierarchical
 * `wcp_context` taxonomy (mirrored from pages/headings by WCP_Taxonomy_Sync)
 * rather than re-deriving structure, then emits each context node's items in
 * menu_order.
 *
 * Two modes:
 *   - 'outline' : one row per page/heading AND per item (row_type column),
 *                 page/heading rows carry their own content — fully round-trips.
 *   - 'items'   : one row per item only, hierarchy flattened into columns.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_CSV_Exporter {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    private static function outline_columns() {
        return array(
            'row_type', 'id', 'page', 'subpage', 'heading', 'context_path', 'title',
            'item_type', 'status', 'priority', 'pinned', 'content', 'tags',
            'due_date', 'source_url', 'subtasks', 'menu_order',
        );
    }

    private static function items_columns() {
        return array(
            'id', 'page', 'subpage', 'heading', 'context_path', 'title',
            'item_type', 'status', 'priority', 'pinned', 'content', 'tags',
            'due_date', 'source_url', 'subtasks',
        );
    }

    /**
     * Stream the CSV to php://output. Caller is responsible for download headers.
     *
     * @param string $mode 'outline' | 'items'
     */
    public function stream($mode = 'outline') {
        $mode    = ($mode === 'items') ? 'items' : 'outline';
        $columns = ($mode === 'items') ? self::items_columns() : self::outline_columns();

        $out = fopen('php://output', 'w');
        // UTF-8 BOM so Excel reads accented characters correctly.
        fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, $columns);

        foreach ($this->walk() as $node) {
            if ($mode === 'outline') {
                fputcsv($out, $this->order_row($this->structural_row($node), $columns));
            }
            foreach ($node['items'] as $item) {
                fputcsv($out, $this->order_row($this->item_row($item, $node, $mode), $columns));
            }
        }

        fclose($out);
    }

    /**
     * Ordered, pre-order traversal of the wcp_context tree. Each node carries
     * its ancestor chain (for breadcrumb columns) and its directly-attached items.
     *
     * @return array<int,array>
     */
    private function walk() {
        $terms = WCP_Taxonomy_Sync::get_all_contexts();
        if (is_wp_error($terms) || empty($terms)) {
            return array();
        }

        $by_parent = array();
        $meta      = array();
        foreach ($terms as $t) {
            $by_parent[$t->parent][] = $t;
            $meta[$t->term_id] = array(
                'ref_type' => get_term_meta($t->term_id, 'wcp_ref_type', true),
                'ref_id'   => (int) get_term_meta($t->term_id, 'wcp_ref_id', true),
            );
        }

        $nodes = array();
        $this->dfs($by_parent, $meta, 0, array(), $nodes);
        return $nodes;
    }

    private function dfs($by_parent, $meta, $parent_id, $chain, &$nodes) {
        if (empty($by_parent[$parent_id])) {
            return;
        }

        $children = $by_parent[$parent_id];

        // Read children top-to-bottom as they appear on the site: by the ref
        // post's menu_order, then title as a stable tiebreaker.
        usort($children, function ($a, $b) use ($meta) {
            $pa = $meta[$a->term_id]['ref_id'] ? get_post($meta[$a->term_id]['ref_id']) : null;
            $pb = $meta[$b->term_id]['ref_id'] ? get_post($meta[$b->term_id]['ref_id']) : null;
            $oa = $pa ? (int) $pa->menu_order : 0;
            $ob = $pb ? (int) $pb->menu_order : 0;
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }
            return strcasecmp($a->name, $b->name);
        });

        foreach ($children as $t) {
            $ref_type = $meta[$t->term_id]['ref_type'];
            $ref_id   = $meta[$t->term_id]['ref_id'];
            $ref_post = $ref_id ? get_post($ref_id) : null;

            $node_chain = array_merge($chain, array(array('name' => $t->name, 'type' => $ref_type)));

            $nodes[] = array(
                'term'     => $t,
                'ref_type' => $ref_type,
                'ref_post' => $ref_post,
                'chain'    => $node_chain,
                'items'    => $this->get_items_for_term($t->term_id),
            );

            $this->dfs($by_parent, $meta, $t->term_id, $node_chain, $nodes);
        }
    }

    private function get_items_for_term($term_id) {
        return get_posts(array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
            'tax_query'      => array(
                array(
                    'taxonomy'         => 'wcp_context',
                    'field'            => 'term_id',
                    'terms'            => $term_id,
                    'include_children' => false,
                ),
            ),
        ));
    }

    /**
     * Split a node's ancestor chain into page / subpage / heading columns.
     * Deep page nesting collapses into `subpage`; nested headings into `heading`.
     */
    private function breadcrumb($chain) {
        $pages = array();
        $headings = array();
        $names = array();
        foreach ($chain as $c) {
            $names[] = $c['name'];
            if ($c['type'] === 'wcp_heading') {
                $headings[] = $c['name'];
            } else {
                $pages[] = $c['name'];
            }
        }
        return array(
            'page'         => isset($pages[0]) ? $pages[0] : '',
            'subpage'      => implode(' > ', array_slice($pages, 1)),
            'heading'      => implode(' > ', $headings),
            'context_path' => implode(' > ', $names),
        );
    }

    private function structural_row($node) {
        $crumb    = $this->breadcrumb($node['chain']);
        $ref_post = $node['ref_post'];
        return array(
            'row_type'     => ($node['ref_type'] === 'wcp_heading') ? 'heading' : 'page',
            'id'           => $ref_post ? $ref_post->ID : '',
            'page'         => $crumb['page'],
            'subpage'      => $crumb['subpage'],
            'heading'      => $crumb['heading'],
            'context_path' => $crumb['context_path'],
            'title'        => $ref_post ? $ref_post->post_title : $node['term']->name,
            'item_type'    => '',
            'status'       => '',
            'priority'     => '',
            'pinned'       => '',
            'content'      => $ref_post ? wp_strip_all_tags($ref_post->post_content) : '',
            'tags'         => '',
            'due_date'     => '',
            'source_url'   => '',
            'subtasks'     => '',
            'menu_order'   => $ref_post ? (int) $ref_post->menu_order : '',
        );
    }

    private function item_row($item, $node, $mode) {
        $crumb  = $this->breadcrumb($node['chain']);
        $fields = $this->item_fields($item);

        $row = array(
            'row_type'     => 'item',
            'id'           => $item->ID,
            'page'         => $crumb['page'],
            'subpage'      => $crumb['subpage'],
            'heading'      => $crumb['heading'],
            'context_path' => $crumb['context_path'],
            'title'        => $item->post_title,
            'menu_order'   => (int) $item->menu_order,
        );
        return array_merge($row, $fields);
    }

    /**
     * Item taxonomies + meta, mirroring the fields used in the item row template
     * (template-parts/item-row.php) and the delegation packet.
     */
    private function item_fields($item) {
        $types = wp_get_post_terms($item->ID, 'item_type', array('fields' => 'slugs'));
        $type  = (!is_wp_error($types) && !empty($types)) ? $types[0] : '';

        // Status lives in task_status for tasks, spec_status for specs.
        $status = '';
        if ($type === 'task') {
            $s = wp_get_post_terms($item->ID, 'task_status', array('fields' => 'slugs'));
            $status = (!is_wp_error($s) && !empty($s)) ? $s[0] : '';
        } elseif ($type === 'spec') {
            $s = wp_get_post_terms($item->ID, 'spec_status', array('fields' => 'slugs'));
            $status = (!is_wp_error($s) && !empty($s)) ? $s[0] : '';
        }

        $prio   = wp_get_post_terms($item->ID, 'priority', array('fields' => 'slugs'));
        $pinned = wp_get_post_terms($item->ID, 'pinned', array('fields' => 'slugs'));
        $tags   = wp_get_post_terms($item->ID, 'post_tag', array('fields' => 'names'));

        $subtasks = json_decode(get_post_meta($item->ID, '_wcp_subtasks', true) ?: '[]', true);
        $sub = array();
        if (is_array($subtasks)) {
            foreach ($subtasks as $st) {
                $sub[] = ($st['title'] ?? '') . ':' . (!empty($st['done']) ? 'done' : 'todo');
            }
        }

        return array(
            'item_type'  => $type,
            'status'     => $status,
            'priority'   => (!is_wp_error($prio) && !empty($prio)) ? $prio[0] : '',
            'pinned'     => (!is_wp_error($pinned) && !empty($pinned)) ? $pinned[0] : '',
            'content'    => wp_strip_all_tags($item->post_content),
            'tags'       => (!is_wp_error($tags)) ? implode(', ', $tags) : '',
            'due_date'   => get_post_meta($item->ID, '_wcp_due_date', true) ?: '',
            'source_url' => get_post_meta($item->ID, '_wcp_source_url', true) ?: '',
            'subtasks'   => implode('; ', $sub),
        );
    }

    /**
     * Project an associative row onto the column order, filling gaps with ''.
     */
    private function order_row($row, $columns) {
        $ordered = array();
        foreach ($columns as $col) {
            $ordered[] = isset($row[$col]) ? $row[$col] : '';
        }
        return $ordered;
    }
}
