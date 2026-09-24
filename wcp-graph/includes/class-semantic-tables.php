<?php
/**
 * Semantic tables — layer 2 of the graph.
 *
 * A table is a saved query, not data: rows are the items the host page or
 * heading contains (derived from structure, so adding an item adds a row),
 * columns are predicates, and each cell renders the stored edges
 * (subject = row item, predicate = column). Editing a cell writes/removes
 * edges through the same routes the Connections panel uses. Deleting a
 * table or column removes only the view config — the edges remain facts.
 *
 * Config lives in page meta: array of
 *   { id: "tbl_…", heading_id: int (0 = page level), columns: [term_id…] }
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPG_Semantic_Tables {

    const META_KEY = '_wcpg_tables';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
    }

    public function get_tables($page_id) {
        $tables = get_post_meta((int) $page_id, self::META_KEY, true);
        return is_array($tables) ? $tables : array();
    }

    private function save_tables($page_id, $tables) {
        update_post_meta((int) $page_id, self::META_KEY, array_values($tables));
    }

    /**
     * @param string $labels Comma-separated predicate labels; each is
     *                       created on first use, like the panel does.
     */
    public function create_table($page_id, $heading_id, $labels) {
        $page_id = (int) $page_id;
        if (!get_post($page_id) || 'page' !== get_post_type($page_id)) {
            return new WP_Error('wcpg_bad_page', __('Tables live on pages.', 'wcp-graph'), array('status' => 400));
        }

        $column_ids = array();
        foreach (array_filter(array_map('trim', explode(',', (string) $labels))) as $label) {
            $predicate = WCPG_Predicates::instance()->get_or_create($label);
            if (is_wp_error($predicate)) {
                return $predicate;
            }
            $column_ids[] = $predicate->term_id;
        }
        if (empty($column_ids)) {
            return new WP_Error('wcpg_no_columns', __('A table needs at least one column predicate.', 'wcp-graph'), array('status' => 400));
        }

        $tables   = $this->get_tables($page_id);
        $tables[] = array(
            'id'         => uniqid('tbl_'),
            'heading_id' => (int) $heading_id,
            'columns'    => array_values(array_unique($column_ids)),
        );
        $this->save_tables($page_id, $tables);
        return end($tables);
    }

    public function delete_table($page_id, $table_id) {
        $tables = $this->get_tables($page_id);
        $kept   = array_filter($tables, function ($table) use ($table_id) {
            return $table['id'] !== $table_id;
        });
        if (count($kept) === count($tables)) {
            return new WP_Error('wcpg_not_found', __('Table not found.', 'wcp-graph'), array('status' => 404));
        }
        $this->save_tables($page_id, $kept);
        return true;
    }

    public function add_column($page_id, $table_id, $label) {
        $predicate = WCPG_Predicates::instance()->get_or_create($label);
        if (is_wp_error($predicate)) {
            return $predicate;
        }

        $tables = $this->get_tables($page_id);
        foreach ($tables as &$table) {
            if ($table['id'] === $table_id) {
                if (in_array($predicate->term_id, $table['columns'], true)) {
                    return new WP_Error('wcpg_duplicate', __('That column already exists.', 'wcp-graph'), array('status' => 409));
                }
                $table['columns'][] = $predicate->term_id;
                $this->save_tables($page_id, $tables);
                return true;
            }
        }
        return new WP_Error('wcpg_not_found', __('Table not found.', 'wcp-graph'), array('status' => 404));
    }

    public function remove_column($page_id, $table_id, $predicate_id) {
        $tables = $this->get_tables($page_id);
        foreach ($tables as &$table) {
            if ($table['id'] === $table_id) {
                $table['columns'] = array_values(array_diff($table['columns'], array((int) $predicate_id)));
                if (empty($table['columns'])) {
                    return $this->delete_table($page_id, $table_id);
                }
                $this->save_tables($page_id, $tables);
                return true;
            }
        }
        return new WP_Error('wcpg_not_found', __('Table not found.', 'wcp-graph'), array('status' => 404));
    }

    /** Render every table configured for this page+heading scope. */
    public function render($page_id, $heading_id = 0) {
        if (!current_user_can('edit_posts')) {
            return;
        }

        $page_id    = (int) $page_id;
        $heading_id = (int) $heading_id;
        $scoped     = array_filter($this->get_tables($page_id), function ($table) use ($heading_id) {
            return (int) $table['heading_id'] === $heading_id;
        });
        ?>
        <div class="wcpg-tables" data-page-id="<?php echo esc_attr($page_id); ?>" data-heading-id="<?php echo esc_attr($heading_id); ?>">
            <?php foreach ($scoped as $table) : ?>
                <?php $this->render_table($page_id, $table); ?>
            <?php endforeach; ?>

            <button type="button" class="wcpg-add-table wcp-edit-link"><?php esc_html_e('+ table', 'wcp-graph'); ?></button>
            <form class="wcpg-table-form" hidden>
                <input type="text" class="wcpg-table-columns" required
                       placeholder="<?php esc_attr_e('column predicates, e.g. fulfiller, launched', 'wcp-graph'); ?>" />
                <button type="submit" class="wcpg-save"><?php esc_html_e('Create table', 'wcp-graph'); ?></button>
                <span class="wcpg-form-error" role="alert"></span>
            </form>
        </div>
        <?php
    }

    private function render_table($page_id, $table) {
        $items = WCPG_Structure::instance()->items_for_scope($page_id, $table['heading_id']);

        $predicates = array();
        foreach ($table['columns'] as $term_id) {
            $term = get_term((int) $term_id, WCPG_Predicates::TAXONOMY);
            if ($term && !is_wp_error($term)) {
                $predicates[] = $term;
            }
        }
        if (empty($predicates)) {
            return;
        }

        $matrix = WCPG_Graph_Repository::instance()->edges_matrix(
            wp_list_pluck($items, 'ID'),
            wp_list_pluck($predicates, 'term_id')
        );
        ?>
        <div class="wcpg-table-wrap" data-table-id="<?php echo esc_attr($table['id']); ?>">
            <table class="wcpg-table">
                <thead>
                    <tr>
                        <th class="wcpg-th-subject"><?php esc_html_e('Item', 'wcp-graph'); ?></th>
                        <?php foreach ($predicates as $predicate) : ?>
                            <th data-predicate-id="<?php echo esc_attr($predicate->term_id); ?>">
                                <?php echo esc_html($predicate->name); ?>
                                <button type="button" class="wcpg-col-delete" title="<?php esc_attr_e('Remove column (keeps the connections)', 'wcp-graph'); ?>">&times;</button>
                            </th>
                        <?php endforeach; ?>
                        <th class="wcpg-th-actions">
                            <button type="button" class="wcpg-col-add" title="<?php esc_attr_e('Add column', 'wcp-graph'); ?>">+</button>
                            <button type="button" class="wcpg-table-delete" title="<?php esc_attr_e('Remove table (keeps the connections)', 'wcp-graph'); ?>">&#128465;</button>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)) : ?>
                        <tr><td colspan="<?php echo count($predicates) + 2; ?>" class="wcpg-empty">
                            <?php esc_html_e('No items here yet — rows appear as items are added.', 'wcp-graph'); ?>
                        </td></tr>
                    <?php endif; ?>
                    <?php foreach ($items as $item) : ?>
                        <tr data-subject-id="<?php echo esc_attr($item->ID); ?>">
                            <td class="wcpg-td-subject">
                                <a href="<?php echo esc_url(get_permalink($item)); ?>"><?php echo esc_html(get_the_title($item)); ?></a>
                            </td>
                            <?php foreach ($predicates as $predicate) : ?>
                                <td class="wcpg-cell"
                                    data-subject-id="<?php echo esc_attr($item->ID); ?>"
                                    data-predicate="<?php echo esc_attr($predicate->name); ?>">
                                    <?php
                                    $key = $item->ID . ':' . $predicate->term_id;
                                    foreach (isset($matrix[$key]) ? $matrix[$key] : array() as $edge) {
                                        $this->render_chip($edge);
                                    }
                                    ?>
                                    <button type="button" class="wcpg-cell-add" title="<?php esc_attr_e('Add value', 'wcp-graph'); ?>">+</button>
                                </td>
                            <?php endforeach; ?>
                            <td></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_chip($edge) {
        ?>
        <span class="wcpg-chip-wrap" data-edge-id="<?php echo esc_attr($edge['id']); ?>">
            <?php if ($edge['object_id']) : ?>
                <a class="wcpg-chip" href="<?php echo esc_url($edge['object_url']); ?>"><?php echo esc_html($edge['object_title']); ?></a>
            <?php else : ?>
                <span class="wcpg-literal"><?php echo esc_html($edge['object_value']); ?></span>
            <?php endif; ?>
            <button type="button" class="wcpg-delete" title="<?php esc_attr_e('Remove', 'wcp-graph'); ?>">&times;</button>
        </span>
        <?php
    }
}
