<?php
/**
 * Sidebar with Page Navigation
 */
?>

<aside class="wcp-sidebar" role="navigation" aria-label="<?php esc_attr_e('Page Navigation', 'work-copilot-theme'); ?>">

    <div class="wcp-sidebar-header">
        <h2><a href="<?php echo esc_url(home_url('/')); ?>" class="wcp-sidebar-logo"><?php bloginfo('name'); ?></a></h2>
    </div>

    <nav class="wcp-page-navigation">
        <?php
        global $post;
        $current_page_id = is_page() ? $post->ID : 0;
        $open_ids = $current_page_id ? wcp_theme_get_page_ancestors($current_page_id) : array();
        echo wcp_theme_build_page_nav(0, $current_page_id, 0, $open_ids);
        ?>
    </nav>

    <div class="wcp-sidebar-footer">
        <a href="<?php echo admin_url('post-new.php?post_type=page'); ?>" class="wcp-add-page-btn">
            + <?php _e('New Page', 'work-copilot-theme'); ?>
        </a>
    </div>

</aside>
