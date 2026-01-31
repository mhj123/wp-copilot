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
    wp_enqueue_style('wcp-theme-style', get_stylesheet_uri(), array(), '1.2.0');

    // Custom theme styles
    wp_enqueue_style('wcp-theme-custom', get_template_directory_uri() . '/assets/css/theme.css', array(), '1.2.0');

    // Theme JavaScript
    wp_enqueue_script('wcp-theme-js', get_template_directory_uri() . '/assets/js/theme.js', array('jquery'), '1.2.0', true);

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

// Enqueue AI widget assets
function wcp_theme_enqueue_ai_widget() {
    // Only enqueue on frontend pages and if user is logged in
    if (is_admin() || !is_user_logged_in() || !get_option('wcp_ai_enabled', false)) {
        return;
    }

    // Only on pages
    if (!is_page()) {
        return;
    }

    // Version for cache busting - update with each change
    $widget_version = '1.2.0';

    // Enqueue widget CSS
    wp_enqueue_style(
        'wcp-ai-widget',
        get_template_directory_uri() . '/assets/css/ai-widget.css',
        array(),
        $widget_version
    );

    // Enqueue widget JavaScript
    wp_enqueue_script(
        'wcp-ai-widget',
        get_template_directory_uri() . '/assets/js/ai-widget.js',
        array('jquery'),
        $widget_version,
        true
    );
}
add_action('wp_enqueue_scripts', 'wcp_theme_enqueue_ai_widget');

// Include AI widget in footer
function wcp_theme_ai_widget_footer() {
    // Only show on frontend pages if user is logged in
    if (is_admin() || !is_user_logged_in() || !get_option('wcp_ai_enabled', false)) {
        return;
    }

    // Only on pages
    if (!is_page()) {
        return;
    }

    get_template_part('template-parts/ai-widget');
}
add_action('wp_footer', 'wcp_theme_ai_widget_footer');

// Add body classes
function wcp_theme_body_classes($classes) {
    if (is_page()) {
        $classes[] = 'wcp-page-view';
    }
    return $classes;
}
add_filter('body_class', 'wcp_theme_body_classes');

// Get Headings directly under a Page
function wcp_theme_get_page_headings($page_id) {
    return get_posts(array(
        'post_type' => 'wcp_heading',
        'posts_per_page' => -1,
        'orderby' => 'menu_order title',
        'order' => 'ASC',
        'meta_query' => array(
            array('key' => '_wcp_parent_type', 'value' => 'page'),
            array('key' => '_wcp_parent_id', 'value' => $page_id),
        ),
    ));
}

// Get context term for a Heading
function wcp_theme_get_heading_context_term($heading_id) {
    $terms = get_terms(array(
        'taxonomy' => 'wcp_context',
        'hide_empty' => false,
        'meta_query' => array(
            array('key' => 'wcp_ref_type', 'value' => 'wcp_heading'),
            array('key' => 'wcp_ref_id', 'value' => $heading_id),
        ),
    ));
    return !empty($terms) ? $terms[0] : null;
}

// Get items for a specific Heading
function wcp_theme_get_heading_items($heading_id) {
    $heading_term = wcp_theme_get_heading_context_term($heading_id);
    if (!$heading_term) return array();

    return get_posts(array(
        'post_type' => 'post',
        'posts_per_page' => -1,
        'tax_query' => array(
            array(
                'taxonomy' => 'wcp_context',
                'field' => 'term_id',
                'terms' => $heading_term->term_id,
            ),
        ),
        'orderby' => 'date',
        'order' => 'DESC',
    ));
}

// Get items for Page ONLY (exclude descendant Heading items)
function wcp_theme_get_page_only_items($page_id) {
    $page_term = wcp_theme_get_page_context_term($page_id);
    if (!$page_term) return array();

    // Get all Heading terms under this page
    $headings = wcp_theme_get_page_headings($page_id);
    $heading_term_ids = array();
    foreach ($headings as $heading) {
        $term = wcp_theme_get_heading_context_term($heading->ID);
        if ($term) $heading_term_ids[] = $term->term_id;
    }

    $tax_query = array(
        'relation' => 'AND',
        array(
            'taxonomy' => 'wcp_context',
            'field' => 'term_id',
            'terms' => $page_term->term_id,
        ),
    );

    // Exclude items in Heading contexts
    if (!empty($heading_term_ids)) {
        $tax_query[] = array(
            'taxonomy' => 'wcp_context',
            'field' => 'term_id',
            'terms' => $heading_term_ids,
            'operator' => 'NOT IN',
        );
    }

    return get_posts(array(
        'post_type' => 'post',
        'posts_per_page' => -1,
        'tax_query' => $tax_query,
        'orderby' => 'date',
        'order' => 'DESC',
    ));
}

// Get breadcrumb trail for a Page
function wcp_theme_get_page_breadcrumbs($page_id) {
    $breadcrumbs = array();
    $current_page = get_post($page_id);

    if (!$current_page) {
        return $breadcrumbs;
    }

    // Build breadcrumb trail by walking up parent chain
    $trail = array();
    $current = $current_page;

    while ($current) {
        $trail[] = array(
            'id' => $current->ID,
            'title' => $current->post_title,
            'url' => get_permalink($current->ID),
        );

        if ($current->post_parent) {
            $current = get_post($current->post_parent);
        } else {
            $current = null;
        }
    }

    // Reverse to get root-to-current order
    return array_reverse($trail);
}

// Get breadcrumb trail for a Heading
function wcp_theme_get_heading_breadcrumbs($heading_id) {
    $breadcrumbs = array();
    $heading = get_post($heading_id);

    if (!$heading || $heading->post_type !== 'wcp_heading') {
        return $breadcrumbs;
    }

    // Get parent type and ID
    $parent_type = get_post_meta($heading_id, '_wcp_parent_type', true);
    $parent_id = get_post_meta($heading_id, '_wcp_parent_id', true);

    // Build parent breadcrumbs first
    if ($parent_type === 'page' && $parent_id) {
        $breadcrumbs = wcp_theme_get_page_breadcrumbs($parent_id);
    } elseif ($parent_type === 'wcp_heading' && $parent_id) {
        $breadcrumbs = wcp_theme_get_heading_breadcrumbs($parent_id);
    }

    // Add current heading
    $breadcrumbs[] = array(
        'id' => $heading->ID,
        'title' => $heading->post_title,
        'url' => null, // Headings don't have permalinks
        'type' => 'heading',
    );

    return $breadcrumbs;
}

// Get breadcrumb trail for an Item Post from its context terms
function wcp_theme_get_item_breadcrumbs($post_id) {
    $context_terms = wp_get_post_terms($post_id, 'wcp_context');

    if (empty($context_terms) || is_wp_error($context_terms)) {
        return array();
    }

    // Use the first context term
    $term = $context_terms[0];

    // Get cached path from term meta
    $cached_path = get_term_meta($term->term_id, 'wcp_cached_path', true);
    $ref_type = get_term_meta($term->term_id, 'wcp_ref_type', true);
    $ref_id = get_term_meta($term->term_id, 'wcp_ref_id', true);

    // Build breadcrumbs from the referenced post (page or heading)
    if ($ref_type === 'page' && $ref_id) {
        return wcp_theme_get_page_breadcrumbs($ref_id);
    } elseif ($ref_type === 'wcp_heading' && $ref_id) {
        return wcp_theme_get_heading_breadcrumbs($ref_id);
    }

    return array();
}
