<?php
/**
 * Work Copilot Theme Functions
 */

if (!defined('ABSPATH')) {
    exit;
}

// Theme setup
function wcp_theme_setup() {
    // Add default posts and comments RSS feed links to head
    add_theme_support('automatic-feed-links');

    // Let WordPress manage the document title
    add_theme_support('title-tag');

    // Enable support for Post Thumbnails
    add_theme_support('post-thumbnails');

    // Switch default core markup to output valid HTML5
    add_theme_support('html5', array(
        'search-form',
        'comment-form',
        'comment-list',
        'gallery',
        'caption',
    ));

    // Add theme support for selective refresh for widgets
    add_theme_support('customize-selective-refresh-widgets');
}
add_action('after_setup_theme', 'wcp_theme_setup');

// Enqueue scripts and styles
function wcp_theme_scripts() {
    // Main theme stylesheet
    wp_enqueue_style('wcp-theme-style', get_stylesheet_uri(), array(), '1.1.0');

    // Custom theme styles
    wp_enqueue_style('wcp-theme-custom', get_template_directory_uri() . '/assets/css/theme.css', array(), '1.1.0');

    // Theme JavaScript
    wp_enqueue_script('wcp-theme-js', get_template_directory_uri() . '/assets/js/theme.js', array('jquery'), '1.0.0', true);

    // Localize script with data
    wp_localize_script('wcp-theme-js', 'wcpThemeData', array(
        'restUrl' => rest_url('work-copilot/v1'),
        'nonce' => wp_create_nonce('wp_rest'),
        'homeUrl' => home_url(),
    ));
}
add_action('wp_enqueue_scripts', 'wcp_theme_scripts');

// Get hierarchical page tree
function wcp_theme_get_page_tree($parent_id = 0) {
    $args = array(
        'post_type' => 'page',
        'post_parent' => $parent_id,
        'orderby' => 'menu_order title',
        'order' => 'ASC',
        'posts_per_page' => -1,
        'post_status' => 'publish',
    );

    return get_posts($args);
}

// Recursively build page navigation
function wcp_theme_build_page_nav($parent_id = 0, $current_page_id = 0) {
    $pages = wcp_theme_get_page_tree($parent_id);

    if (empty($pages)) {
        return '';
    }

    $output = '<ul class="wcp-page-list">';

    foreach ($pages as $page) {
        $is_current = ($page->ID == $current_page_id) ? ' class="current-page"' : '';
        $output .= '<li' . $is_current . '>';
        $output .= '<a href="' . get_permalink($page->ID) . '">' . esc_html($page->post_title) . '</a>';

        // Check for child pages
        $children = wcp_theme_build_page_nav($page->ID, $current_page_id);
        if ($children) {
            $output .= $children;
        }

        $output .= '</li>';
    }

    $output .= '</ul>';

    return $output;
}

// Get context term for a page
function wcp_theme_get_page_context_term($page_id) {
    $terms = get_terms(array(
        'taxonomy' => 'wcp_context',
        'hide_empty' => false,
        'meta_query' => array(
            array('key' => 'wcp_ref_type', 'value' => 'page'),
            array('key' => 'wcp_ref_id', 'value' => $page_id),
        ),
    ));

    return !empty($terms) ? $terms[0] : null;
}

// Get items for a page
function wcp_theme_get_page_items($page_id, $filters = array()) {
    $context_term = wcp_theme_get_page_context_term($page_id);

    if (!$context_term) {
        return array();
    }

    // Get term and all descendants
    $term_ids = array($context_term->term_id);
    $children = get_term_children($context_term->term_id, 'wcp_context');
    if (!is_wp_error($children)) {
        $term_ids = array_merge($term_ids, $children);
    }

    $args = array(
        'post_type' => 'post',
        'posts_per_page' => -1,
        'tax_query' => array(
            array(
                'taxonomy' => 'wcp_context',
                'field' => 'term_id',
                'terms' => $term_ids,
            ),
        ),
        'orderby' => 'date',
        'order' => 'DESC',
    );

    // Apply filters
    if (!empty($filters['item_type'])) {
        $args['tax_query'][] = array(
            'taxonomy' => 'item_type',
            'field' => 'slug',
            'terms' => $filters['item_type'],
        );
    }

    if (!empty($filters['priority'])) {
        $args['tax_query'][] = array(
            'taxonomy' => 'priority',
            'field' => 'slug',
            'terms' => $filters['priority'],
        );
    }

    return get_posts($args);
}

// Add body classes
function wcp_theme_body_classes($classes) {
    if (is_page()) {
        $classes[] = 'wcp-page-view';
    }
    return $classes;
}
add_filter('body_class', 'wcp_theme_body_classes');
