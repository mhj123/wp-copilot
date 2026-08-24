<?php
/**
 * CSV Importer
 *
 * Round-trips the CSV produced by WCP_CSV_Exporter back into the knowledge tree.
 * Two-phase: build_plan() is a dry run (no writes) that powers the preview, and
 * commit() performs the writes after the user confirms — keeping a human in the
 * loop, per the project's guardrails.
 *
 * Structure is recreated by replaying rows in file order (the export is written
 * in pre-order, so a parent always precedes its children) and resolving each
 * node's parent by its breadcrumb path. Pages/headings are created/updated and
 * the existing WCP_Taxonomy_Sync rebuilds their wcp_context terms; items are
 * then attached to the resolved context term.
 *
 * Safety: on UPDATE, page/heading post_content is NEVER overwritten (the export
 * flattens rich content to plain text, so re-importing must not clobber bodies).
 * Only items have their content updated.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_CSV_Importer {

    private static $instance = null;

    private static $valid_item_types   = array('task', 'note', 'learning', 'spec');
    private static $valid_task_status  = array('to-do', 'in-progress', 'done');
    private static $valid_spec_status  = array('draft', 'review', 'final');
    private static $valid_priorities   = array('critical', 'high', 'medium', 'low');
    private static $valid_pinned       = array('yes', 'no');

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Parse a CSV file into normalized rows keyed by column name.
     *
     * @return array|WP_Error { mode: 'outline'|'items', rows: array[] }
     */
    public function parse($file_path) {
        $handle = @fopen($file_path, 'r');
        if (!$handle) {
            return new WP_Error('unreadable', 'Could not read the uploaded file.');
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return new WP_Error('empty', 'The CSV appears to be empty.');
        }

        // Strip a UTF-8 BOM from the first header cell if present.
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $header = array_map(function ($h) { return strtolower(trim($h)); }, $header);

        $mode = in_array('row_type', $header, true) ? 'outline' : 'items';

        $rows = array();
        while (($data = fgetcsv($handle)) !== false) {
            if (count(array_filter($data, function ($v) { return trim((string) $v) !== ''; })) === 0) {
                continue; // skip blank lines
            }
            $row = array();
            foreach ($header as $i => $col) {
                $row[$col] = isset($data[$i]) ? $data[$i] : '';
            }
            // In items-only mode every row is an item.
            if ($mode === 'items') {
                $row['row_type'] = 'item';
            }
            $rows[] = $row;
        }
        fclose($handle);

        return array('mode' => $mode, 'rows' => $rows);
    }

    /**
     * Dry run: classify each row as create/update/skip and tally warnings.
     * Performs no writes.
     */
    public function build_plan($rows) {
        $summary = array(
            'pages_create'    => 0, 'pages_update'    => 0,
            'headings_create' => 0, 'headings_update' => 0,
            'items_create'    => 0, 'items_update'    => 0,
            'skipped'         => 0,
            'content_changes' => 0, // items whose content will change
            'warnings'        => array(),
        );

        $path_map = $this->seed_existing_paths(); // context_path => post info

        foreach ($rows as $n => $row) {
            $line = $n + 2; // +1 header, +1 to 1-index
            $type = $this->row_type($row);

            if ($type === null) {
                $summary['skipped']++;
                $summary['warnings'][] = "Row {$line}: unknown row_type, skipped.";
                continue;
            }

            $context_path = trim($row['context_path'] ?? '');
            $title        = trim($row['title'] ?? '');
            if ($title === '') {
                $summary['skipped']++;
                $summary['warnings'][] = "Row {$line}: missing title, skipped.";
                continue;
            }

            $existing = $this->match_existing($row, $type);

            if ($type === 'page') {
                $existing ? $summary['pages_update']++ : $summary['pages_create']++;
                $path_map[$context_path] = array('type' => 'page');
            } elseif ($type === 'heading') {
                $parent_path = $this->parent_path($context_path);
                if ($parent_path !== '' && !isset($path_map[$parent_path])) {
                    $summary['warnings'][] = "Row {$line}: heading \"{$title}\" — parent \"{$parent_path}\" not found yet; will attach at root.";
                }
                $existing ? $summary['headings_update']++ : $summary['headings_create']++;
                $path_map[$context_path] = array('type' => 'heading');
            } else { // item
                if (!isset($path_map[$context_path]) && $context_path !== '') {
                    $summary['warnings'][] = "Row {$line}: item \"{$title}\" — context \"{$context_path}\" not found; item will be created unattached.";
                }
                if ($existing) {
                    $summary['items_update']++;
                    if (wp_strip_all_tags($existing->post_content) !== trim($row['content'] ?? '')) {
                        $summary['content_changes']++;
                    }
                } else {
                    $summary['items_create']++;
                }
            }
        }

        return $summary;
    }

    /**
     * Execute the import. Mirrors build_plan's traversal but writes.
     *
     * @return array result counts
     */
    public function commit($rows) {
        $result = array('pages' => 0, 'headings' => 0, 'items' => 0, 'skipped' => 0, 'errors' => array());

        // context_path => array('post_id'=>int, 'type'=>'page'|'heading', 'term_id'=>int)
        $path_map = $this->seed_existing_paths();

        foreach ($rows as $n => $row) {
            $line = $n + 2;
            $type = $this->row_type($row);
            $title = trim($row['title'] ?? '');
            if ($type === null || $title === '') {
                $result['skipped']++;
                continue;
            }

            $context_path = trim($row['context_path'] ?? '');

            if ($type === 'page') {
                $post_id = $this->upsert_page($row, $context_path, $path_map);
                if ($post_id) {
                    $path_map[$context_path] = array('post_id' => $post_id, 'type' => 'page', 'term_id' => $this->term_id_for_ref($post_id));
                    $result['pages']++;
                } else {
                    $result['errors'][] = "Row {$line}: failed to save page \"{$title}\".";
                }
            } elseif ($type === 'heading') {
                $post_id = $this->upsert_heading($row, $context_path, $path_map);
                if ($post_id) {
                    $path_map[$context_path] = array('post_id' => $post_id, 'type' => 'heading', 'term_id' => $this->term_id_for_ref($post_id));
                    $result['headings']++;
                } else {
                    $result['errors'][] = "Row {$line}: failed to save heading \"{$title}\".";
                }
            } else { // item
                $post_id = $this->upsert_item($row, $context_path, $path_map);
                if ($post_id) {
                    $result['items']++;
                } else {
                    $result['errors'][] = "Row {$line}: failed to save item \"{$title}\".";
                }
            }
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // Upserts
    // ------------------------------------------------------------------

    private function upsert_page($row, $context_path, $path_map) {
        $existing    = $this->match_existing($row, 'page');
        $parent_path = $this->parent_path($context_path);
        $parent_id   = 0;
        if ($parent_path !== '' && isset($path_map[$parent_path]['post_id']) && $path_map[$parent_path]['type'] === 'page') {
            $parent_id = (int) $path_map[$parent_path]['post_id'];
        }

        $data = array(
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => trim($row['title']),
            'post_parent' => $parent_id,
        );
        $this->maybe_set_menu_order($data, $row);

        if ($existing) {
            $data['ID'] = $existing->ID;
            // Never overwrite rich page content with flattened export text.
            $post_id = wp_update_post($data, true);
        } else {
            $data['post_content'] = (string) ($row['content'] ?? '');
            $post_id = wp_insert_post($data, true);
        }

        if (is_wp_error($post_id) || !$post_id) {
            return 0;
        }

        // Deterministically (re)build the context term now that parent is set.
        WCP_Taxonomy_Sync::instance()->sync_page_to_taxonomy($post_id, get_post($post_id), (bool) $existing);
        return $post_id;
    }

    private function upsert_heading($row, $context_path, $path_map) {
        $existing    = $this->match_existing($row, 'heading');
        $parent_path = $this->parent_path($context_path);

        $parent_type = '';
        $parent_id   = 0;
        if ($parent_path !== '' && isset($path_map[$parent_path]['post_id'])) {
            $parent_id   = (int) $path_map[$parent_path]['post_id'];
            $parent_type = $path_map[$parent_path]['type'] === 'heading' ? 'wcp_heading' : 'page';
        }

        $data = array(
            'post_type'   => 'wcp_heading',
            'post_status' => 'publish',
            'post_title'  => trim($row['title']),
        );
        $this->maybe_set_menu_order($data, $row);

        if ($existing) {
            $data['ID'] = $existing->ID;
            $post_id = wp_update_post($data, true);
        } else {
            $post_id = wp_insert_post($data, true);
        }

        if (is_wp_error($post_id) || !$post_id) {
            return 0;
        }

        // Parent linkage must be set before the sync places the context term.
        update_post_meta($post_id, '_wcp_parent_type', $parent_type);
        update_post_meta($post_id, '_wcp_parent_id', $parent_id);
        WCP_Taxonomy_Sync::instance()->sync_heading_to_taxonomy($post_id, get_post($post_id), (bool) $existing);
        return $post_id;
    }

    private function upsert_item($row, $context_path, $path_map) {
        $existing = $this->match_existing($row, 'item');

        $data = array(
            'post_type'    => 'post',
            'post_status'  => 'publish',
            'post_title'   => trim($row['title']),
            'post_content' => (string) ($row['content'] ?? ''),
        );
        $this->maybe_set_menu_order($data, $row);

        if ($existing) {
            $data['ID'] = $existing->ID;
            $post_id = wp_update_post($data, true);
        } else {
            $post_id = wp_insert_post($data, true);
        }

        if (is_wp_error($post_id) || !$post_id) {
            return 0;
        }

        $this->apply_item_taxonomies($post_id, $row);

        // Attach to the resolved context term.
        if ($context_path !== '' && isset($path_map[$context_path]['term_id']) && $path_map[$context_path]['term_id']) {
            wp_set_post_terms($post_id, array((int) $path_map[$context_path]['term_id']), 'wcp_context');
        }

        return $post_id;
    }

    private function apply_item_taxonomies($post_id, $row) {
        $type = sanitize_key($row['item_type'] ?? '');
        if (in_array($type, self::$valid_item_types, true)) {
            wp_set_post_terms($post_id, array($type), 'item_type');
        }

        $status = sanitize_key($row['status'] ?? '');
        if ($type === 'task' && in_array($status, self::$valid_task_status, true)) {
            wp_set_post_terms($post_id, array($status), 'task_status');
        } elseif ($type === 'spec' && in_array($status, self::$valid_spec_status, true)) {
            wp_set_post_terms($post_id, array($status), 'spec_status');
        }

        $priority = sanitize_key($row['priority'] ?? '');
        if (in_array($priority, self::$valid_priorities, true)) {
            wp_set_post_terms($post_id, array($priority), 'priority');
        }

        $pinned = sanitize_key($row['pinned'] ?? '');
        if (in_array($pinned, self::$valid_pinned, true)) {
            wp_set_post_terms($post_id, array($pinned), 'pinned');
        }

        $tags = trim($row['tags'] ?? '');
        if ($tags !== '') {
            $tag_names = array_filter(array_map('trim', explode(',', $tags)));
            wp_set_post_terms($post_id, $tag_names, 'post_tag');
        }

        $due = trim($row['due_date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
            update_post_meta($post_id, '_wcp_due_date', $due);
        }

        $src = trim($row['source_url'] ?? '');
        if ($src !== '') {
            update_post_meta($post_id, '_wcp_source_url', esc_url_raw($src));
        }

        $subtasks = $this->parse_subtasks($row['subtasks'] ?? '');
        if (!empty($subtasks)) {
            update_post_meta($post_id, '_wcp_subtasks', wp_json_encode($subtasks));
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Only write menu_order when the column is actually present and filled —
     * otherwise (e.g. items-only CSVs) an update would wrongly reset ordering.
     */
    private function maybe_set_menu_order(&$data, $row) {
        if (isset($row['menu_order']) && trim((string) $row['menu_order']) !== '') {
            $data['menu_order'] = (int) $row['menu_order'];
        }
    }

    private function row_type($row) {
        $t = strtolower(trim($row['row_type'] ?? ''));
        return in_array($t, array('page', 'heading', 'item'), true) ? $t : null;
    }

    /**
     * Find an existing post for a row via its `id` column, requiring the
     * post type to match the row type. Returns WP_Post or null.
     */
    private function match_existing($row, $type) {
        $id = (int) ($row['id'] ?? 0);
        if (!$id) {
            return null;
        }
        $post = get_post($id);
        if (!$post) {
            return null;
        }
        $expected = ($type === 'page') ? 'page' : (($type === 'heading') ? 'wcp_heading' : 'post');
        return ($post->post_type === $expected) ? $post : null;
    }

    private function parent_path($context_path) {
        $parts = explode(' > ', $context_path);
        array_pop($parts);
        return implode(' > ', $parts);
    }

    /**
     * Build context_path => {post_id,type,term_id} for all existing structure,
     * so items can attach to (and headings nest under) what's already there.
     */
    private function seed_existing_paths() {
        $terms = WCP_Taxonomy_Sync::get_all_contexts();
        if (is_wp_error($terms) || empty($terms)) {
            return array();
        }

        $by_id = array();
        foreach ($terms as $t) {
            $by_id[$t->term_id] = $t;
        }

        $map = array();
        foreach ($terms as $t) {
            // Walk to root to compute the path (matches the export breadcrumb).
            $names = array($t->name);
            $cursor = $t;
            while ($cursor->parent && isset($by_id[$cursor->parent])) {
                $cursor = $by_id[$cursor->parent];
                array_unshift($names, $cursor->name);
            }
            $path     = implode(' > ', $names);
            $ref_type = get_term_meta($t->term_id, 'wcp_ref_type', true);
            $ref_id   = (int) get_term_meta($t->term_id, 'wcp_ref_id', true);
            $map[$path] = array(
                'post_id' => $ref_id,
                'type'    => ($ref_type === 'wcp_heading') ? 'heading' : 'page',
                'term_id' => (int) $t->term_id,
            );
        }
        return $map;
    }

    private function term_id_for_ref($post_id) {
        $terms = get_terms(array(
            'taxonomy'   => 'wcp_context',
            'hide_empty' => false,
            'number'     => 1,
            'meta_query' => array(
                array('key' => 'wcp_ref_id', 'value' => $post_id),
            ),
        ));
        return (!is_wp_error($terms) && !empty($terms)) ? (int) $terms[0]->term_id : 0;
    }

    /**
     * Parse "title:done; title:todo" back into the subtask structure the app
     * stores (matching uniqid('st_') ids used by the REST add_subtask handler).
     */
    private function parse_subtasks($cell) {
        $cell = trim($cell);
        if ($cell === '') {
            return array();
        }
        $out = array();
        foreach (explode(';', $cell) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            $pos = strrpos($chunk, ':');
            if ($pos === false) {
                $title = $chunk;
                $done  = false;
            } else {
                $title = trim(substr($chunk, 0, $pos));
                $state = strtolower(trim(substr($chunk, $pos + 1)));
                $done  = in_array($state, array('done', '1', 'true', 'yes'), true);
            }
            if ($title === '') {
                continue;
            }
            $out[] = array('id' => uniqid('st_'), 'title' => $title, 'done' => $done);
        }
        return $out;
    }
}
