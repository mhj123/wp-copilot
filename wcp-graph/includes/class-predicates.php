<?php
/**
 * Predicates — the open, emergent edge vocabulary.
 *
 * A flat custom taxonomy: the user types any label and it becomes a term;
 * autocomplete in the panel steers reuse so the vocabulary converges
 * instead of fragmenting. Term meta `inverse_label` is display-only — one
 * edge, two readings ("fulfils" / "fulfilled by"); the inverse is never
 * stored as a second edge.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPG_Predicates {

    const TAXONOMY = 'wcp_predicate';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'register_taxonomy'));
        add_action('admin_menu', array($this, 'add_admin_menu'), 20);
        add_action(self::TAXONOMY . '_add_form_fields', array($this, 'add_form_fields'));
        add_action(self::TAXONOMY . '_edit_form_fields', array($this, 'edit_form_fields'));
        add_action('created_' . self::TAXONOMY, array($this, 'save_term_meta'));
        add_action('edited_' . self::TAXONOMY, array($this, 'save_term_meta'));
    }

    /**
     * A taxonomy with no post type gets a management screen but no menu
     * entry anywhere; link it under the Work Copilot menu (or Settings,
     * if the core plugin is inactive).
     */
    public function add_admin_menu() {
        global $menu;
        $parent = 'options-general.php';
        foreach ((array) $menu as $item) {
            if (isset($item[2]) && 'work-copilot' === $item[2]) {
                $parent = 'work-copilot';
                break;
            }
        }

        add_submenu_page(
            $parent,
            __('Predicates', 'wcp-graph'),
            __('Predicates', 'wcp-graph'),
            'manage_categories',
            'edit-tags.php?taxonomy=' . self::TAXONOMY
        );
    }

    public function register_taxonomy() {
        register_taxonomy(self::TAXONOMY, array(), array(
            'labels' => array(
                'name'          => __('Predicates', 'wcp-graph'),
                'singular_name' => __('Predicate', 'wcp-graph'),
                'add_new_item'  => __('Add New Predicate', 'wcp-graph'),
                'edit_item'     => __('Edit Predicate', 'wcp-graph'),
                'search_items'  => __('Search Predicates', 'wcp-graph'),
            ),
            'public'            => false,
            'show_ui'           => true,
            'show_in_menu'      => true,
            'show_admin_column' => false,
            'hierarchical'      => false,
            'rewrite'           => false,
            'show_in_rest'      => false,
        ));
    }

    /**
     * Find a predicate by label, creating it on first use.
     *
     * @return WP_Term|WP_Error
     */
    public function get_or_create($label) {
        $label = trim(wp_strip_all_tags((string) $label));
        if ('' === $label) {
            return new WP_Error('wcpg_no_predicate', __('A connection needs a label.', 'wcp-graph'), array('status' => 400));
        }

        $existing = get_term_by('slug', sanitize_title($label), self::TAXONOMY);
        if (!$existing) {
            $existing = get_term_by('name', $label, self::TAXONOMY);
        }
        if ($existing instanceof WP_Term) {
            return $existing;
        }

        $result = wp_insert_term($label, self::TAXONOMY);
        if (is_wp_error($result)) {
            return $result;
        }
        return get_term($result['term_id'], self::TAXONOMY);
    }

    /** All predicates, shaped for autocomplete. */
    public function all() {
        $terms = get_terms(array(
            'taxonomy'   => self::TAXONOMY,
            'hide_empty' => false,
            'orderby'    => 'name',
        ));
        if (is_wp_error($terms)) {
            return array();
        }

        return array_map(function ($term) {
            return array(
                'id'            => $term->term_id,
                'label'         => $term->name,
                'slug'          => $term->slug,
                'inverse_label' => (string) get_term_meta($term->term_id, 'inverse_label', true),
            );
        }, $terms);
    }

    public function add_form_fields() {
        ?>
        <div class="form-field">
            <label for="wcpg-inverse-label"><?php esc_html_e('Inverse label', 'wcp-graph'); ?></label>
            <input type="text" name="wcpg_inverse_label" id="wcpg-inverse-label" />
            <p><?php esc_html_e('How the connection reads from the object\'s side, e.g. "fulfils" reads back as "fulfilled by". Display only.', 'wcp-graph'); ?></p>
        </div>
        <?php
    }

    public function edit_form_fields($term) {
        $inverse = get_term_meta($term->term_id, 'inverse_label', true);
        ?>
        <tr class="form-field">
            <th scope="row"><label for="wcpg-inverse-label"><?php esc_html_e('Inverse label', 'wcp-graph'); ?></label></th>
            <td>
                <input type="text" name="wcpg_inverse_label" id="wcpg-inverse-label" value="<?php echo esc_attr($inverse); ?>" />
                <p class="description"><?php esc_html_e('How the connection reads from the object\'s side, e.g. "fulfils" reads back as "fulfilled by". Display only.', 'wcp-graph'); ?></p>
            </td>
        </tr>
        <?php
    }

    public function save_term_meta($term_id) {
        if (isset($_POST['wcpg_inverse_label'])) {
            $value = sanitize_text_field(wp_unslash($_POST['wcpg_inverse_label']));
            if ('' === $value) {
                delete_term_meta($term_id, 'inverse_label');
            } else {
                update_term_meta($term_id, 'inverse_label', $value);
            }
        }
    }
}
