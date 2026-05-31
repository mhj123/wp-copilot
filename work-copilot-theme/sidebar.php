<?php
/**
 * Sidebar with Page Navigation
 */
?>

<aside class="wcp-sidebar" role="navigation" aria-label="<?php esc_attr_e('Page Navigation', 'work-copilot-theme'); ?>">

    <div class="wcp-sidebar-header">
        <h2><?php _e('Pages', 'work-copilot-theme'); ?></h2>
    </div>

    <nav class="wcp-page-navigation">
        <?php
        global $post;
        $current_page_id = is_page() ? $post->ID : 0;
        echo wcp_theme_build_page_nav(0, $current_page_id);
        ?>
    </nav>

    <div class="wcp-sidebar-footer">
        <a href="<?php echo admin_url('post-new.php?post_type=page'); ?>" class="wcp-add-page-btn">
            + <?php _e('New Page', 'work-copilot-theme'); ?>
        </a>
    </div>

</aside>
