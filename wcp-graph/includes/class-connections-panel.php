<?php
/**
 * Connections panel — the graph's first UI surface.
 *
 * Rendered on every entity (page, post, heading) by the theme calling
 * wcpg_connections_panel(). Shows outbound edges as "predicate → object"
 * and inbound ones under the predicate's inverse label (or "← predicate"
 * when none is set). Each chip links to the other endpoint — this is the
 * traversal UI. Adding a connection writes a triple directly.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPG_Connections_Panel {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    public function enqueue_assets() {
        if (!is_singular(WCPG_Graph_Repository::ENTITY_POST_TYPES) || !current_user_can('edit_posts')) {
            return;
        }

        wp_enqueue_style('wcpg-panel', WCPG_PLUGIN_URL . 'assets/css/connections-panel.css', array(), WCPG_VERSION);
        wp_enqueue_script('wcpg-panel', WCPG_PLUGIN_URL . 'assets/js/connections-panel.js', array(), WCPG_VERSION, true);
        wp_localize_script('wcpg-panel', 'wcpGraphData', array(
            'restUrl' => rest_url('wcp-graph/v1'),
            'nonce'   => wp_create_nonce('wp_rest'),
        ));
    }

    public function render($post_id) {
        $repo = WCPG_Graph_Repository::instance();
        if (!$repo->is_entity($post_id) || !current_user_can('edit_posts')) {
            return;
        }

        $edges      = $repo->edges_for_post($post_id);
        $predicates = WCPG_Predicates::instance()->all();
        ?>
        <section class="wcpg-panel" data-post-id="<?php echo esc_attr($post_id); ?>">
            <div class="wcp-section-header">
                <span class="wcp-section-label"><?php esc_html_e('Connections', 'wcp-graph'); ?></span>
                <button type="button" class="wcpg-add-toggle wcp-edit-link"><?php esc_html_e('+ add', 'wcp-graph'); ?></button>
            </div>

            <ul class="wcpg-edge-list">
                <?php foreach ($edges['outbound'] as $edge) : ?>
                    <li class="wcpg-edge" data-edge-id="<?php echo esc_attr($edge['id']); ?>">
                        <span class="wcpg-predicate"><?php echo esc_html($edge['predicate']); ?> →</span>
                        <?php if ($edge['object_id']) : ?>
                            <a class="wcpg-chip" href="<?php echo esc_url($edge['object_url']); ?>"><?php echo esc_html($edge['object_title']); ?></a>
                        <?php else : ?>
                            <span class="wcpg-literal"><?php echo esc_html($edge['object_value']); ?></span>
                        <?php endif; ?>
                        <button type="button" class="wcpg-delete" title="<?php esc_attr_e('Remove connection', 'wcp-graph'); ?>">&times;</button>
                    </li>
                <?php endforeach; ?>

                <?php foreach ($edges['inbound'] as $edge) : ?>
                    <li class="wcpg-edge wcpg-inbound" data-edge-id="<?php echo esc_attr($edge['id']); ?>">
                        <span class="wcpg-predicate">
                            <?php
                            if ('' !== $edge['inverse_label']) {
                                echo esc_html($edge['inverse_label']) . ' →';
                            } else {
                                echo '← ' . esc_html($edge['predicate']);
                            }
                            ?>
                        </span>
                        <a class="wcpg-chip" href="<?php echo esc_url($edge['subject_url']); ?>"><?php echo esc_html($edge['subject_title']); ?></a>
                        <button type="button" class="wcpg-delete" title="<?php esc_attr_e('Remove connection', 'wcp-graph'); ?>">&times;</button>
                    </li>
                <?php endforeach; ?>

                <?php if (empty($edges['outbound']) && empty($edges['inbound'])) : ?>
                    <li class="wcpg-empty"><?php esc_html_e('No connections yet.', 'wcp-graph'); ?></li>
                <?php endif; ?>
            </ul>

            <form class="wcpg-add-form" hidden>
                <input type="text" class="wcpg-input-predicate" list="wcpg-predicates"
                       placeholder="<?php esc_attr_e('relationship, e.g. fulfils', 'wcp-graph'); ?>" required />
                <datalist id="wcpg-predicates">
                    <?php foreach ($predicates as $predicate) : ?>
                        <option value="<?php echo esc_attr($predicate['label']); ?>"></option>
                    <?php endforeach; ?>
                </datalist>

                <span class="wcpg-object-entity">
                    <input type="text" class="wcpg-input-object" autocomplete="off"
                           placeholder="<?php esc_attr_e('search pages, posts…', 'wcp-graph'); ?>" />
                    <input type="hidden" class="wcpg-object-id" value="" />
                    <ul class="wcpg-suggestions" hidden></ul>
                </span>

                <input type="text" class="wcpg-input-literal" hidden
                       placeholder="<?php esc_attr_e('value, e.g. 2026-03-01', 'wcp-graph'); ?>" />

                <label class="wcpg-literal-toggle">
                    <input type="checkbox" class="wcpg-literal-checkbox" />
                    <?php esc_html_e('plain value', 'wcp-graph'); ?>
                </label>

                <button type="submit" class="wcpg-save"><?php esc_html_e('Connect', 'wcp-graph'); ?></button>
                <span class="wcpg-form-error" role="alert"></span>
            </form>
        </section>
        <?php
    }
}
