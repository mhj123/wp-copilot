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

add_filter('document_title_parts', function($title) {
    if (is_singular()) {
        $title['title'] = get_the_ID();
        unset($title['tagline']);
    }
    return $title;
});

add_filter('admin_title', function($admin_title) {
    global $post;
    if ($post && $post->post_title) {
        $admin_title = str_replace($post->post_title, $post->ID, $admin_title);
    }
    return $admin_title;
});


// Enqueue scripts and styles
function wcp_theme_scripts() {
    // Main theme stylesheet
    wp_enqueue_style('wcp-theme-style', get_stylesheet_uri(), array(), '1.2.0');

    // Custom theme styles
    wp_enqueue_style('wcp-theme-custom', get_template_directory_uri() . '/assets/css/theme.css', array(), '2.1.0');

    // SortableJS for drag-to-reorder
    wp_enqueue_script('sortablejs', 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js', array(), '1.15.2', true);

    // Theme JavaScript
    wp_enqueue_script('wcp-theme-js', get_template_directory_uri() . '/assets/js/theme.js', array('jquery', 'sortablejs'), '1.8.0', true);

    // Localize script with data
    wp_localize_script('wcp-theme-js', 'wcpThemeData', array(
        'restUrl' => rest_url('work-copilot/v1'),
        'delegationRestUrl' => rest_url('wcp-delegation/v1'),
        'nonce' => wp_create_nonce('wp_rest'),
        'homeUrl' => home_url(),
        'adminUrl' => admin_url(),
        'isLoggedIn' => is_user_logged_in(),
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

// Return array of ancestor page IDs for a given page (parent, grandparent, …)
function wcp_theme_get_page_ancestors($page_id) {
    $ancestors = array();
    $p = get_post($page_id);
    while ($p && $p->post_parent) {
        $ancestors[] = (int) $p->post_parent;
        $p = get_post($p->post_parent);
    }
    return $ancestors;
}

// Recursively build collapsible page navigation.
// Subpages are hidden by default; ancestors of the current page are pre-expanded.
function wcp_theme_build_page_nav($parent_id = 0, $current_page_id = 0, $depth = 0, $open_ids = array()) {
    $pages = wcp_theme_get_page_tree($parent_id);
    if (empty($pages)) return '';

    $output = '<ul class="wcp-page-list">';

    foreach ($pages as $page) {
        $is_current  = ($page->ID === $current_page_id);
        $is_open     = $is_current || in_array($page->ID, $open_ids);
        $children    = wcp_theme_build_page_nav($page->ID, $current_page_id, $depth + 1, $open_ids);
        $has_children = !empty($children);

        $classes = array();
        if ($is_current)  $classes[] = 'current-page';

        $output .= '<li' . ($classes ? ' class="' . implode(' ', $classes) . '"' : '') . '>';
        $output .= '<div class="wcp-nav-row">';

        if ($has_children) {
            $output .= '<button type="button" class="wcp-nav-toggle" data-page-id="' . $page->ID . '" aria-expanded="' . ($is_open ? 'true' : 'false') . '">'
                     . ($is_open ? '▾' : '▸') . '</button>';
        } else {
            $output .= '<span class="wcp-nav-toggle-spacer"></span>';
        }

        $output .= '<a href="' . esc_url(get_permalink($page->ID)) . '">' . esc_html($page->post_title) . '</a>';
        $output .= '</div>';

        if ($has_children) {
            $output .= '<div class="wcp-nav-children"' . (!$is_open ? ' style="display:none;"' : '') . '>';
            $output .= $children;
            $output .= '</div>';
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
/**
 * tax_query clause that excludes Done tasks. Done items are hidden everywhere in
 * the frontend UI (they remain findable in WP Admin). NOT IN also keeps every
 * post that has no task_status at all — info/learning/spec and open tasks.
 */
function wcp_theme_exclude_done_clause() {
    return array(
        'taxonomy' => 'task_status',
        'field'    => 'slug',
        'terms'    => array('done'),
        'operator' => 'NOT IN',
    );
}

/**
 * tax_query clause that excludes pinned items. Pinned items are lifted out of
 * their normal lists and shown in a "Pinned" block at the top of the page.
 */
function wcp_theme_exclude_pinned_clause() {
    return array(
        'taxonomy' => 'pinned',
        'field'    => 'slug',
        'terms'    => array('yes'),
        'operator' => 'NOT IN',
    );
}

/**
 * Pinned items for a page: those marked pinned within this page's own context
 * or any of its (live) heading contexts — the same scope the page renders.
 * Done tasks stay hidden even when pinned.
 */
function wcp_theme_get_page_pinned_items($page_id) {
    $page_term = wcp_theme_get_page_context_term($page_id);
    if (!$page_term) {
        return array();
    }

    $term_ids = array($page_term->term_id);
    foreach (wcp_theme_get_page_headings($page_id) as $heading) {
        $term = wcp_theme_get_heading_context_term($heading->ID);
        if ($term) {
            $term_ids[] = $term->term_id;
        }
    }

    return get_posts(array(
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => array('menu_order' => 'ASC', 'date' => 'DESC'),
        'tax_query'      => array(
            'relation' => 'AND',
            array(
                'taxonomy'         => 'wcp_context',
                'field'            => 'term_id',
                'terms'            => $term_ids,
                'include_children' => false,
            ),
            array(
                'taxonomy' => 'pinned',
                'field'    => 'slug',
                'terms'    => array('yes'),
            ),
            wcp_theme_exclude_done_clause(),
        ),
    ));
}

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
        'order' => 'ASC',
    );

    $args['tax_query'][] = wcp_theme_exclude_done_clause();

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
    if (is_admin() || !is_user_logged_in() || !get_option('wcp_ai_enabled', false)) {
        return;
    }

    // Version for cache busting - update with each change
    $widget_version = '2.0.0';

    // Enqueue widget CSS
    wp_enqueue_style(
        'wcp-ai-widget',
        get_template_directory_uri() . '/assets/css/ai-widget.css',
        array(),
        $widget_version
    );

    // Markdown renderer
    wp_enqueue_script(
        'marked',
        'https://cdn.jsdelivr.net/npm/marked/marked.min.js',
        array(),
        '12.0.0',
        true
    );

    // Enqueue widget JavaScript
    wp_enqueue_script(
        'wcp-ai-widget',
        get_template_directory_uri() . '/assets/js/ai-widget.js',
        array('jquery', 'marked'),
        $widget_version,
        true
    );
}
add_action('wp_enqueue_scripts', 'wcp_theme_enqueue_ai_widget');

// Include AI widget in footer
function wcp_theme_ai_widget_footer() {
    if (is_admin() || !is_user_logged_in() || !get_option('wcp_ai_enabled', false)) {
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
            wcp_theme_exclude_done_clause(),
            wcp_theme_exclude_pinned_clause(),
        ),
        'orderby' => array('menu_order' => 'ASC', 'date' => 'ASC'),
    ));
}

// Get items belonging directly to a page — excludes items in child page contexts
// and items in live heading contexts (those render under their heading).
// Items in orphaned heading contexts (heading term exists, heading post doesn't) are included.
function wcp_theme_get_page_only_items($page_id) {
    $page_term = wcp_theme_get_page_context_term($page_id);
    if (!$page_term) return array();

    // Terms to include: the page's own term + any orphaned heading terms
    $include_term_ids = array($page_term->term_id);

    // Terms to always exclude: live heading terms (rendered in headings loop)
    $headings = wcp_theme_get_page_headings($page_id);
    $live_heading_term_ids = array();
    foreach ($headings as $heading) {
        $term = wcp_theme_get_heading_context_term($heading->ID);
        if ($term) $live_heading_term_ids[] = $term->term_id;
    }

    // Collect orphaned heading terms: direct children of the page's context term
    // that are not live heading terms. These are items from deleted headings and
    // should be "adopted" into the page level.
    // Note: child page terms are intentionally NOT excluded here. An item explicitly
    // assigned to both a parent page and a child page must appear on both.
    // The IN check on include_term_ids already ensures items appear only on pages
    // they were explicitly assigned to — no child-page NOT IN needed.
    $child_page_term_ids = array(); // still needed to detect orphaned vs child-page terms
    $child_pages = get_pages(array('parent' => $page_id, 'post_status' => 'publish'));
    foreach ($child_pages as $child) {
        $child_term = wcp_theme_get_page_context_term($child->ID);
        if ($child_term) $child_page_term_ids[] = $child_term->term_id;
    }

    $not_orphaned = array_merge($live_heading_term_ids, $child_page_term_ids);
    $direct_children = get_terms(array(
        'taxonomy'   => 'wcp_context',
        'parent'     => $page_term->term_id,
        'hide_empty' => false,
    ));
    if (!is_wp_error($direct_children)) {
        foreach ($direct_children as $child_term) {
            if (!in_array($child_term->term_id, $not_orphaned)) {
                $include_term_ids[] = $child_term->term_id;
            }
        }
    }

    // Only exclude items that have a live heading term — those belong under their
    // heading section, not at page level. Child page terms are NOT excluded so that
    // items assigned to multiple pages all appear where they were assigned.
    $tax_query = array(
        'relation' => 'AND',
        array(
            'taxonomy'         => 'wcp_context',
            'field'            => 'term_id',
            'terms'            => $include_term_ids,
            'include_children' => false, // don't bleed into subpage/subheading terms
        ),
    );

    if (!empty($live_heading_term_ids)) {
        $tax_query[] = array(
            'taxonomy' => 'wcp_context',
            'field'    => 'term_id',
            'terms'    => $live_heading_term_ids,
            'operator' => 'NOT IN',
        );
    }

    $tax_query[] = wcp_theme_exclude_done_clause();
    $tax_query[] = wcp_theme_exclude_pinned_clause();

    return get_posts(array(
        'post_type'      => 'post',
        'posts_per_page' => -1,
        'tax_query'      => $tax_query,
        'orderby'        => array('menu_order' => 'ASC', 'date' => 'DESC'),
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

/**
 * Resolve the URL of the page where an item is situated, anchored to the item
 * row (#wcp-item-<id>). Used by the dashboard so clicking a task lands on the
 * item in its page context — where it can be interacted with — rather than the
 * standalone single-item view. Falls back to the item permalink if unresolved.
 */
/**
 * Resolve the id of the page an item is situated on (walking up through any
 * nested headings). Returns 0 if it can't be resolved.
 */
function wcp_theme_get_item_page_id($item_id) {
    $terms = wp_get_post_terms($item_id, 'wcp_context');
    if (empty($terms) || is_wp_error($terms)) {
        return 0;
    }

    $ref_type = get_term_meta($terms[0]->term_id, 'wcp_ref_type', true);
    $ref_id   = (int) get_term_meta($terms[0]->term_id, 'wcp_ref_id', true);

    $guard = 0;
    while ($ref_type === 'wcp_heading' && $ref_id && $guard++ < 20) {
        $parent_type = get_post_meta($ref_id, '_wcp_parent_type', true);
        $ref_id      = (int) get_post_meta($ref_id, '_wcp_parent_id', true);
        $ref_type    = $parent_type;
    }

    return ($ref_type === 'page' && $ref_id) ? $ref_id : 0;
}

function wcp_theme_get_item_page_url($item_id) {
    $page_id = wcp_theme_get_item_page_id($item_id);
    return $page_id ? get_permalink($page_id) . '#wcp-item-' . $item_id : get_permalink($item_id);
}

/**
 * All structural locations an item belongs to: one breadcrumb trail per
 * wcp_context term it is assigned to (an item can live on several pages or
 * headings). Each trail is an array of {id, title, url}, root-to-leaf.
 */
function wcp_theme_get_item_context_paths($item_id) {
    $terms = wp_get_post_terms($item_id, 'wcp_context');
    if (empty($terms) || is_wp_error($terms)) {
        return array();
    }

    $paths = array();
    foreach ($terms as $term) {
        $ref_type = get_term_meta($term->term_id, 'wcp_ref_type', true);
        $ref_id   = (int) get_term_meta($term->term_id, 'wcp_ref_id', true);

        $trail = array();
        if ($ref_type === 'page' && $ref_id) {
            $trail = wcp_theme_get_page_breadcrumbs($ref_id);
        } elseif ($ref_type === 'wcp_heading' && $ref_id) {
            $trail = wcp_theme_get_heading_breadcrumbs($ref_id);
        }
        if (!empty($trail)) {
            $paths[] = $trail;
        }
    }
    return $paths;
}

// Run a dynamic listing query and return matching posts
function wcp_theme_query_dynamic_listing($listing) {
    $tax_query = array('relation' => 'AND');

    if (!empty($listing['item_type'])) {
        $tax_query[] = array(
            'taxonomy' => 'item_type',
            'field'    => 'slug',
            'terms'    => $listing['item_type'],
        );
    }

    if (!empty($listing['task_status'])) {
        $tax_query[] = array(
            'taxonomy' => 'task_status',
            'field'    => 'slug',
            'terms'    => $listing['task_status'],
        );
    } else {
        // Hide Done tasks unless this listing deliberately targets a status.
        $tax_query[] = wcp_theme_exclude_done_clause();
    }

    if (!empty($listing['parent_page_id'])) {
        $parent_term = wcp_theme_get_page_context_term((int) $listing['parent_page_id']);
        if ($parent_term) {
            $child_ids    = get_term_children($parent_term->term_id, 'wcp_context');
            $context_ids  = array_merge(array($parent_term->term_id), $child_ids ?: array());
            $tax_query[]  = array(
                'taxonomy' => 'wcp_context',
                'field'    => 'term_id',
                'terms'    => $context_ids,
                'operator' => 'IN',
            );
        }
    }

    return get_posts(array(
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => 200,
        'orderby'        => 'date',
        'order'          => 'ASC',
        'tax_query'      => count($tax_query) > 1 ? $tax_query : array_slice($tax_query, 1),
    ));
}

/**
 * Require authentication for ALL REST API requests.
 *
 * This site sits behind a server-level Basic Auth gate that must exempt
 * /wp-json so Application Password credentials reach WordPress. This filter
 * puts an equivalent lock back at the WP layer: logged-in sessions (theme
 * nonce requests) and Application Passwords (Hermes) pass; anonymous
 * requests get a 401 instead of content. If credentials were supplied but
 * failed, $result already holds that error and is passed through untouched.
 */
add_filter('rest_authentication_errors', function ($result) {
    if (!empty($result)) {
        return $result;
    }
    if (!is_user_logged_in()) {
        return new WP_Error(
            'rest_authentication_required',
            __('Authentication required.', 'work-copilot-theme'),
            array('status' => 401)
        );
    }
    return $result;
});
