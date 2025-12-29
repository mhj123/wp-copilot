<?php
/**
 * Public Frontend
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_Public {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('the_content', array($this, 'enhance_page_content'));
        add_filter('the_content', array($this, 'enhance_heading_content'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    public function enqueue_scripts() {
        if (is_page() || is_singular('wcp_heading') || is_tax('wcp_context')) {
            wp_enqueue_style(
                'work-copilot-public',
                WCP_PLUGIN_URL . 'assets/css/public.css',
                array(),
                WCP_VERSION
            );

            wp_enqueue_script(
                'work-copilot-public',
                WCP_PLUGIN_URL . 'assets/js/public.js',
                array('jquery'),
                WCP_VERSION,
                true
            );

            wp_localize_script('work-copilot-public', 'wcpData', array(
                'restUrl' => rest_url('work-copilot/v1'),
                'nonce' => wp_create_nonce('wp_rest'),
            ));
        }
    }

    /**
     * Enhance Page content with ItemPosts
     */
    public function enhance_page_content($content) {
        if (!is_singular('page') || !is_main_query()) {
            return $content;
        }

        global $post;

        // Get context term for this page
        $terms = get_terms(array(
            'taxonomy' => 'wcp_context',
            'hide_empty' => false,
            'meta_query' => array(
                array('key' => 'wcp_ref_type', 'value' => 'page'),
                array('key' => 'wcp_ref_id', 'value' => $post->ID),
            ),
        ));

        if (empty($terms)) {
            return $content;
        }

        $term_id = $terms[0]->term_id;

        // Get child terms (for descendant headings)
        $child_term_ids = get_term_children($term_id, 'wcp_context');
        $all_term_ids = array_merge(array($term_id), $child_term_ids);

        // Query ItemPosts
        $items_query = new WP_Query(array(
            'post_type' => 'post',
            'posts_per_page' => 100,
            'tax_query' => array(
                array(
                    'taxonomy' => 'wcp_context',
                    'field' => 'term_id',
                    'terms' => $all_term_ids,
                ),
            ),
            'orderby' => 'date',
            'order' => 'DESC',
        ));

        if ($items_query->have_posts()) {
            $items_html = '<div class="wcp-page-items">';
            $items_html .= '<h2>' . __('Items', 'work-copilot') . '</h2>';

            // Filter controls
            $items_html .= '<div class="wcp-filters" data-page-id="' . esc_attr($post->ID) . '">';
            $items_html .= '<select class="wcp-filter" data-filter="item_type">';
            $items_html .= '<option value="">' . __('All Types', 'work-copilot') . '</option>';
            $items_html .= '<option value="task">' . __('Tasks', 'work-copilot') . '</option>';
            $items_html .= '<option value="info">' . __('Info', 'work-copilot') . '</option>';
            $items_html .= '<option value="learning">' . __('Learnings', 'work-copilot') . '</option>';
            $items_html .= '</select>';

            $items_html .= '<select class="wcp-filter" data-filter="priority">';
            $items_html .= '<option value="">' . __('All Priorities', 'work-copilot') . '</option>';
            $items_html .= '<option value="high">' . __('High', 'work-copilot') . '</option>';
            $items_html .= '<option value="medium">' . __('Medium', 'work-copilot') . '</option>';
            $items_html .= '<option value="low">' . __('Low', 'work-copilot') . '</option>';
            $items_html .= '</select>';
            $items_html .= '</div>';

            $items_html .= '<div class="wcp-items-list">';

            while ($items_query->have_posts()) {
                $items_query->the_post();
                $item_types = wp_get_post_terms(get_the_ID(), 'item_type', array('fields' => 'names'));
                $priorities = wp_get_post_terms(get_the_ID(), 'priority', array('fields' => 'names'));
                $pinned = wp_get_post_terms(get_the_ID(), 'pinned', array('fields' => 'names'));

                $is_pinned = in_array('yes', $pinned);
                $is_ai_generated = get_post_meta(get_the_ID(), '_wcp_ai_generated', true);

                $items_html .= '<div class="wcp-item' . ($is_pinned ? ' wcp-pinned' : '') . '">';
                $items_html .= '<h3><a href="' . get_permalink() . '">' . get_the_title() . '</a></h3>';

                if ($is_ai_generated) {
                    $items_html .= '<span class="wcp-badge wcp-ai-badge">' . __('AI Generated', 'work-copilot') . '</span>';
                }

                if (!empty($item_types)) {
                    $items_html .= '<span class="wcp-badge wcp-type-' . esc_attr($item_types[0]) . '">' . esc_html($item_types[0]) . '</span>';
                }

                if (!empty($priorities)) {
                    $items_html .= '<span class="wcp-badge wcp-priority-' . esc_attr($priorities[0]) . '">' . esc_html($priorities[0]) . '</span>';
                }

                $items_html .= '<div class="wcp-excerpt">' . get_the_excerpt() . '</div>';
                $items_html .= '<div class="wcp-meta">' . get_the_date() . '</div>';
                $items_html .= '</div>';
            }

            $items_html .= '</div>'; // .wcp-items-list
            $items_html .= '</div>'; // .wcp-page-items

            wp_reset_postdata();

            $content .= $items_html;
        }

        return $content;
    }

    /**
     * Enhance Heading content with ItemPosts
     */
    public function enhance_heading_content($content) {
        if (!is_singular('wcp_heading') || !is_main_query()) {
            return $content;
        }

        global $post;

        // Similar to enhance_page_content but for headings
        // (Implementation would be nearly identical)

        return $content;
    }
}
