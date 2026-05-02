<?php
/**
 * Template for displaying Pages with ItemPosts
 * Simplified clean layout
 */

get_header();
?>

<div class="wcp-page-content">

    <?php
    while (have_posts()) :
        the_post();
    ?>

    <!-- Page Header: Breadcrumb + Title (outside of any container box) -->
    <header class="wcp-page-header-clean">
        <?php
        // Display breadcrumbs (only if page has parents)
        $breadcrumbs = wcp_theme_get_page_breadcrumbs(get_the_ID());
        if (count($breadcrumbs) > 1) {
            include(locate_template('template-parts/breadcrumbs.php'));
        }
        ?>

        <h1 class="wcp-page-title-clean"><?php the_title(); ?></h1>
    </header>

    <!-- Page Content (only show if there is content) -->
    <?php if (get_the_content()) : ?>
    <div class="wcp-page-content-box">
        <?php the_content(); ?>
    </div>
    <?php endif; ?>

    <!-- Page Notes -->
    <?php
    $page_notes = get_post_meta(get_the_ID(), '_wcp_page_notes', true);
    if ($page_notes) : ?>
    <div class="wcp-page-notes">
        <?php echo wp_kses_post($page_notes); ?>
    </div>
    <?php endif; ?>

    <?php
    endwhile;

    // Get items and headings for this page
    $page_id = get_the_ID();
    $headings = wcp_theme_get_page_headings($page_id);

    // Show child pages if any
    $child_pages = get_pages(array(
        'parent'      => $page_id,
        'post_status' => 'publish',
        'sort_column' => 'menu_order',
        'sort_order'  => 'ASC',
    ));
    ?>

    <?php if (!empty($child_pages)) : ?>
    <section class="wcp-subpages-section">
        <h2><?php _e('Sub-pages', 'work-copilot-theme'); ?></h2>
        <ul class="wcp-subpages-list">
            <?php foreach ($child_pages as $child_page) : ?>
                <li class="wcp-subpage-item">
                    <a href="<?php echo esc_url(get_permalink($child_page->ID)); ?>">
                        <?php echo esc_html($child_page->post_title); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <!-- Items Section -->
    <section class="wcp-items-section">

        <?php
        $page_context_term = wcp_theme_get_page_context_term($page_id);
        $page_context_id   = $page_context_term ? $page_context_term->term_id : 0;
        $page_only_items   = wcp_theme_get_page_only_items($page_id);
        ?>

        <!-- Page-level items (not under any heading) -->
        <div class="wcp-items-list" data-context-id="<?php echo esc_attr($page_context_id); ?>">
            <?php foreach ($page_only_items as $item) :
                $item_types   = wp_get_post_terms($item->ID, 'item_type', array('fields' => 'names'));
                $priorities   = wp_get_post_terms($item->ID, 'priority', array('fields' => 'names'));
                $item_tags    = wp_get_post_terms($item->ID, 'post_tag', array('fields' => 'names'));
                $item_contexts = wp_get_post_terms($item->ID, 'wcp_context', array('fields' => 'names'));
            ?>
                <?php include(locate_template('template-parts/item-row.php')); ?>
            <?php endforeach; ?>
        </div>

        <!-- Quick-add item at page level -->
        <?php if ($page_context_id) : ?>
            <?php include(locate_template('template-parts/quick-add-item.php')); ?>
        <?php endif; ?>

        <!-- Headings with their items -->
        <?php foreach ($headings as $heading) :
            $heading_id      = $heading->ID;
            $items           = wcp_theme_get_heading_items($heading_id);
            $heading_term    = wcp_theme_get_heading_context_term($heading_id);
            $heading_context_id = $heading_term ? $heading_term->term_id : 0;
        ?>
            <div class="wcp-heading-group">
                <h3 class="wcp-heading-title-simple">
                    <?php echo esc_html($heading->post_title); ?>
                    <a href="<?php echo get_edit_post_link($heading_id); ?>" class="wcp-edit-link">[edit]</a>
                </h3>

                <div class="wcp-items-list" data-context-id="<?php echo esc_attr($heading_context_id); ?>">
                    <?php foreach ($items as $item) :
                        $item_types    = wp_get_post_terms($item->ID, 'item_type', array('fields' => 'names'));
                        $priorities    = wp_get_post_terms($item->ID, 'priority', array('fields' => 'names'));
                        $item_tags     = wp_get_post_terms($item->ID, 'post_tag', array('fields' => 'names'));
                        $item_contexts = wp_get_post_terms($item->ID, 'wcp_context', array('fields' => 'names'));
                    ?>
                        <?php include(locate_template('template-parts/item-row.php')); ?>
                    <?php endforeach; ?>
                </div>

                <!-- Quick-add item under this heading -->
                <?php if ($heading_context_id) :
                    $page_context_id = $heading_context_id; // reuse partial
                    include(locate_template('template-parts/quick-add-item.php'));
                    $page_context_id = $page_context_term ? $page_context_term->term_id : 0; // restore
                endif; ?>
            </div>
        <?php endforeach; ?>

        <!-- Add new heading -->
        <div class="wcp-add-heading-wrap">
            <button type="button" id="wcp-btn-new-heading" class="wcp-edit-link">+ new heading</button>
            <form id="wcp-create-heading-form" style="display:none;">
                <input type="hidden" name="page_id" value="<?php echo esc_attr($page_id); ?>">
                <input type="text" name="title" required placeholder="<?php esc_attr_e('Heading title...', 'work-copilot-theme'); ?>" class="wcp-form-control wcp-quick-title">
                <button type="submit" class="wcp-btn wcp-btn-primary wcp-btn-sm"><?php _e('Create heading', 'work-copilot-theme'); ?></button>
                <button type="button" id="wcp-btn-cancel-heading" class="wcp-edit-link"><?php _e('cancel', 'work-copilot-theme'); ?></button>
                <span class="wcp-quick-status"></span>
            </form>
        </div>

    </section>

</div><!-- .wcp-page-content -->

<?php
get_footer();
