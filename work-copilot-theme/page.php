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

    <?php
    endwhile;

    // Get items and headings for this page
    $page_id = get_the_ID();
    $headings = wcp_theme_get_page_headings($page_id);
    ?>

    <!-- Items Section: Unified view of all headings and items -->
    <section class="wcp-items-section">
        <h2><?php _e('Items', 'work-copilot-theme'); ?></h2>

        <?php if (!empty($headings)) : ?>
            <!-- Display each heading with its items -->
            <?php foreach ($headings as $heading) :
                $heading_id = $heading->ID;
                $items = wcp_theme_get_heading_items($heading_id);
                $heading_term = wcp_theme_get_heading_context_term($heading_id);
            ?>
                <div class="wcp-heading-group">
                    <h3 class="wcp-heading-title-simple">
                        <?php echo esc_html($heading->post_title); ?>
                        <a href="<?php echo get_edit_post_link($heading_id); ?>" class="wcp-edit-link">[edit heading]</a>
                    </h3>

                    <?php if (!empty($items)) : ?>
                        <div class="wcp-items-list">
                            <?php foreach ($items as $item) :
                                $item_types = wp_get_post_terms($item->ID, 'item_type', array('fields' => 'names'));
                                $priorities = wp_get_post_terms($item->ID, 'priority', array('fields' => 'names'));
                            ?>
                                <div class="wcp-item-row">
                                    <span class="wcp-item-text">
                                        <?php echo esc_html($item->post_title); ?>
                                    </span>
                                    <a href="<?php echo get_edit_post_link($item->ID); ?>" class="wcp-edit-link">[edit text]</a>

                                    <select class="wcp-inline-select" disabled>
                                        <option><?php echo !empty($item_types) ? esc_html($item_types[0]) : 'task/info/learning'; ?></option>
                                    </select>

                                    <select class="wcp-inline-select" disabled>
                                        <option><?php echo !empty($priorities) ? esc_html($priorities[0]) : 'prio'; ?></option>
                                    </select>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <p class="wcp-no-items-in-heading"><?php _e('No items under this heading yet.', 'work-copilot-theme'); ?></p>
                    <?php endif; ?>

                    <button type="button" class="wcp-btn-add-item-inline wcp-btn-add-item wcp-btn wcp-btn-secondary" data-heading-id="<?php echo esc_attr($heading_id); ?>" data-context-id="<?php echo esc_attr($heading_term ? $heading_term->term_id : ''); ?>">
                        + Add item under <?php echo esc_html($heading->post_title); ?>
                    </button>

                    <!-- Hidden inline form for adding items (will be shown on click) -->
                    <form class="wcp-heading-item-form wcp-item-form" style="display: none;" data-heading-id="<?php echo esc_attr($heading_id); ?>" data-context-id="<?php echo esc_attr($heading_term ? $heading_term->term_id : ''); ?>">
                        <input type="hidden" name="page_id" value="<?php echo esc_attr($page_id); ?>">
                        <input type="hidden" name="heading_id" value="<?php echo esc_attr($heading_id); ?>">

                        <div class="wcp-form-group">
                            <label><?php _e('Title', 'work-copilot-theme'); ?> *</label>
                            <input type="text" name="title" required class="wcp-form-control wcp-heading-item-title">
                        </div>

                        <div class="wcp-form-group">
                            <label><?php _e('Content', 'work-copilot-theme'); ?></label>
                            <textarea name="content" rows="4" class="wcp-form-control wcp-heading-item-content"></textarea>
                        </div>

                        <div class="wcp-form-group">
                            <label><?php _e('Contexts', 'work-copilot-theme'); ?></label>
                            <div class="wcp-context-selector-wrapper wcp-heading-contexts">
                                <p class="wcp-loading"><?php _e('Loading contexts...', 'work-copilot-theme'); ?></p>
                            </div>
                            <p class="description"><?php _e('Current heading is pre-selected.', 'work-copilot-theme'); ?></p>
                        </div>

                        <div class="wcp-form-row">
                            <div class="wcp-form-group">
                                <label><?php _e('Item Type', 'work-copilot-theme'); ?></label>
                                <select name="item_type" class="wcp-form-control">
                                    <option value=""><?php _e('-- Select --', 'work-copilot-theme'); ?></option>
                                    <option value="task"><?php _e('Task', 'work-copilot-theme'); ?></option>
                                    <option value="info"><?php _e('Info', 'work-copilot-theme'); ?></option>
                                    <option value="learning"><?php _e('Learning', 'work-copilot-theme'); ?></option>
                                </select>
                            </div>

                            <div class="wcp-form-group">
                                <label><?php _e('Priority', 'work-copilot-theme'); ?></label>
                                <select name="priority" class="wcp-form-control">
                                    <option value=""><?php _e('-- Select --', 'work-copilot-theme'); ?></option>
                                    <option value="high"><?php _e('High', 'work-copilot-theme'); ?></option>
                                    <option value="medium"><?php _e('Medium', 'work-copilot-theme'); ?></option>
                                    <option value="low"><?php _e('Low', 'work-copilot-theme'); ?></option>
                                </select>
                            </div>
                        </div>

                        <div class="wcp-form-group">
                            <label><?php _e('Tags', 'work-copilot-theme'); ?></label>
                            <input type="text" name="tags" class="wcp-form-control" placeholder="<?php esc_attr_e('Comma-separated', 'work-copilot-theme'); ?>">
                        </div>

                        <button type="submit" class="wcp-btn wcp-btn-primary"><?php _e('Create Item', 'work-copilot-theme'); ?></button>
                        <button type="button" class="wcp-btn-cancel-item wcp-btn wcp-btn-secondary"><?php _e('Cancel', 'work-copilot-theme'); ?></button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php else : ?>
            <p class="wcp-no-headings-message"><?php _e('No headings yet. Create a heading first to organize your items.', 'work-copilot-theme'); ?></p>
        <?php endif; ?>

        <!-- General Add Item button -->
        <div class="wcp-add-item-general">
            <button type="button" id="wcp-btn-add-item-general" class="wcp-btn wcp-btn-primary">
                + Add item
            </button>
        </div>

        <!-- General item creation form (initially hidden) -->
        <form id="wcp-create-item-form" class="wcp-item-form" style="display: none;">
            <input type="hidden" name="page_id" value="<?php echo esc_attr($page_id); ?>">

            <div class="wcp-form-group">
                <label for="wcp-item-title"><?php _e('Title', 'work-copilot-theme'); ?> *</label>
                <input type="text" id="wcp-item-title" name="title" required class="wcp-form-control">
            </div>

            <div class="wcp-form-group">
                <label for="wcp-item-content"><?php _e('Content', 'work-copilot-theme'); ?></label>
                <textarea id="wcp-item-content" name="content" rows="6" class="wcp-form-control"></textarea>
            </div>

            <div class="wcp-form-group">
                <label for="wcp-item-contexts"><?php _e('Select Heading / Context', 'work-copilot-theme'); ?></label>
                <div id="wcp-item-contexts" class="wcp-context-selector-wrapper">
                    <p class="wcp-loading"><?php _e('Loading contexts...', 'work-copilot-theme'); ?></p>
                </div>
            </div>

            <div class="wcp-form-row">
                <div class="wcp-form-group">
                    <label for="wcp-item-type"><?php _e('Item Type', 'work-copilot-theme'); ?></label>
                    <select id="wcp-item-type" name="item_type" class="wcp-form-control">
                        <option value=""><?php _e('-- Select --', 'work-copilot-theme'); ?></option>
                        <option value="task"><?php _e('Task', 'work-copilot-theme'); ?></option>
                        <option value="info"><?php _e('Info', 'work-copilot-theme'); ?></option>
                        <option value="learning"><?php _e('Learning', 'work-copilot-theme'); ?></option>
                    </select>
                </div>

                <div class="wcp-form-group">
                    <label for="wcp-item-priority"><?php _e('Priority', 'work-copilot-theme'); ?></label>
                    <select id="wcp-item-priority" name="priority" class="wcp-form-control">
                        <option value=""><?php _e('-- Select --', 'work-copilot-theme'); ?></option>
                        <option value="high"><?php _e('High', 'work-copilot-theme'); ?></option>
                        <option value="medium"><?php _e('Medium', 'work-copilot-theme'); ?></option>
                        <option value="low"><?php _e('Low', 'work-copilot-theme'); ?></option>
                    </select>
                </div>
            </div>

            <div class="wcp-form-group">
                <label for="wcp-item-tags"><?php _e('Tags (comma-separated)', 'work-copilot-theme'); ?></label>
                <input type="text" id="wcp-item-tags" name="tags" class="wcp-form-control" placeholder="<?php esc_attr_e('tag1, tag2, tag3', 'work-copilot-theme'); ?>">
            </div>

            <div class="wcp-form-actions">
                <button type="submit" class="wcp-btn wcp-btn-primary">
                    <?php _e('Create Item', 'work-copilot-theme'); ?>
                </button>
                <button type="button" id="wcp-btn-cancel-general-item" class="wcp-btn wcp-btn-secondary">
                    <?php _e('Cancel', 'work-copilot-theme'); ?>
                </button>
                <span class="wcp-form-status"></span>
            </div>
        </form>
    </section>

</div><!-- .wcp-page-content -->

<?php
get_footer();
