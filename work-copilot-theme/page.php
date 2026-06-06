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
    <div class="wcp-page-content-box" data-section="page-content" data-page-id="<?php echo esc_attr(get_the_ID()); ?>">
        <div class="wcp-section-header">
            <span class="wcp-section-label">Page content</span>
            <button type="button" class="wcp-toggle-section wcp-edit-link"
                    data-section="page-content"
                    data-page-id="<?php echo esc_attr(get_the_ID()); ?>">hide</button>
        </div>
        <div class="wcp-section-body">
            <?php the_content(); ?>
        </div>
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

        <!-- Filter toolbar -->
        <div class="wcp-items-toolbar">
            <div class="wcp-filter-group" role="group" aria-label="Filter items">
                <button type="button" class="wcp-filter-btn active" data-filter="all">All items</button>
                <button type="button" class="wcp-filter-btn" data-filter="tasks">All tasks</button>
                <button type="button" class="wcp-filter-btn" data-filter="open">Open tasks</button>
            </div>
            <button type="button" class="wcp-toggle-descriptions wcp-edit-link" title="Toggle descriptions">descriptions</button>
        </div>

        <?php
        $page_context_term = wcp_theme_get_page_context_term($page_id);
        $page_context_id   = $page_context_term ? $page_context_term->term_id : 0;
        $page_only_items   = wcp_theme_get_page_only_items($page_id);

        // Context term IDs that belong to this page or its headings — suppress these
        // from item context pills since they're already implied by the page structure.
        $local_context_ids = $page_context_id ? array($page_context_id) : array();
        foreach ($headings as $_h) {
            $_ht = wcp_theme_get_heading_context_term($_h->ID);
            if ($_ht) $local_context_ids[] = $_ht->term_id;
        }
        ?>

        <!-- Page-level items (not under any heading) -->
        <div class="wcp-items-list" data-context-id="<?php echo esc_attr($page_context_id); ?>">
            <?php foreach ($page_only_items as $item) :
                $item_types    = wp_get_post_terms($item->ID, 'item_type', array('fields' => 'names'));
                $priorities    = wp_get_post_terms($item->ID, 'priority', array('fields' => 'names'));
                $task_statuses = wp_get_post_terms($item->ID, 'task_status', array('fields' => 'slugs'));
                $item_tags     = wp_get_post_terms($item->ID, 'post_tag', array('fields' => 'names'));
                $item_contexts = array_values(array_map(
                    function($t) { return $t->name; },
                    array_filter(
                        wp_get_post_terms($item->ID, 'wcp_context'),
                        function($t) use ($local_context_ids) { return !in_array($t->term_id, $local_context_ids); }
                    )
                ));
            ?>
                <?php include(locate_template('template-parts/item-row.php')); ?>
            <?php endforeach; ?>
        </div>

        <!-- Quick-add item at page level -->
        <?php if ($page_context_id) : ?>
            <?php include(locate_template('template-parts/quick-add-item.php')); ?>
        <?php endif; ?>

        <!-- Headings with their items -->
        <div id="wcp-headings-sortable" data-page-id="<?php echo esc_attr($page_id); ?>">
        <?php foreach ($headings as $heading) :
            $heading_id      = $heading->ID;
            $items           = wcp_theme_get_heading_items($heading_id);
            $heading_term    = wcp_theme_get_heading_context_term($heading_id);
            $heading_context_id = $heading_term ? $heading_term->term_id : 0;
            $is_goal         = get_post_meta($heading_id, '_wcp_is_goal', true) === '1';
        ?>
            <div class="wcp-heading-group<?php echo $is_goal ? ' wcp-goal-group' : ''; ?>" data-heading-id="<?php echo esc_attr($heading_id); ?>">
                <h3 class="wcp-heading-title-simple">
                    <span class="wcp-heading-drag-handle" title="Drag to reorder">⠿</span>
                    <?php if ($is_goal) : ?>
                        <span class="wcp-goal-badge">Goal</span>
                    <?php endif; ?>
                    <?php echo esc_html($heading->post_title); ?>
                    <a href="<?php echo get_edit_post_link($heading_id); ?>" class="wcp-edit-link">[edit]</a>
                    <button type="button" class="wcp-heading-delete wcp-edit-link" data-heading-id="<?php echo esc_attr($heading_id); ?>">[delete]</button>
                </h3>
                <?php if ($is_goal && !empty($heading->post_content)) : ?>
                    <div class="wcp-goal-description"><?php echo wpautop(esc_html($heading->post_content)); ?></div>
                <?php endif; ?>

                <div class="wcp-items-list" data-context-id="<?php echo esc_attr($heading_context_id); ?>">
                    <?php foreach ($items as $item) :
                        $item_types    = wp_get_post_terms($item->ID, 'item_type', array('fields' => 'names'));
                        $priorities    = wp_get_post_terms($item->ID, 'priority', array('fields' => 'names'));
                        $task_statuses = wp_get_post_terms($item->ID, 'task_status', array('fields' => 'slugs'));
                        $item_tags     = wp_get_post_terms($item->ID, 'post_tag', array('fields' => 'names'));
                        $item_contexts = array_values(array_map(
                            function($t) { return $t->name; },
                            array_filter(
                                wp_get_post_terms($item->ID, 'wcp_context'),
                                function($t) use ($local_context_ids) { return !in_array($t->term_id, $local_context_ids); }
                            )
                        ));
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
        </div><!-- #wcp-headings-sortable -->

        <!-- Dynamic listings -->
        <?php
        $dynamic_listings = json_decode(get_post_meta($page_id, '_wcp_dynamic_listings', true) ?: '[]', true);
        foreach ($dynamic_listings as $listing) :
            include locate_template('template-parts/dynamic-listing.php');
        endforeach;
        ?>

        <!-- Add new heading / goal / subpage / dynamic list -->
        <div class="wcp-add-heading-wrap">
            <button type="button" id="wcp-btn-new-heading" class="wcp-edit-link">+ new heading</button>
            <button type="button" id="wcp-btn-new-goal" class="wcp-edit-link" data-page-id="<?php echo esc_attr($page_id); ?>">+ new goal</button>
            <button type="button" id="wcp-btn-new-subpage" class="wcp-edit-link" data-page-id="<?php echo esc_attr($page_id); ?>">+ new subpage</button>
            <button type="button" id="wcp-btn-new-dynamic-listing" class="wcp-edit-link" data-page-id="<?php echo esc_attr($page_id); ?>">+ dynamic list</button>
            <form id="wcp-create-subpage-form" style="display:none;">
                <input type="hidden" name="page_id" value="<?php echo esc_attr($page_id); ?>">
                <input type="text" name="title" required placeholder="<?php esc_attr_e('Subpage title...', 'work-copilot-theme'); ?>" class="wcp-form-control wcp-quick-title">
                <button type="submit" class="wcp-btn wcp-btn-primary wcp-btn-sm"><?php _e('Create subpage', 'work-copilot-theme'); ?></button>
                <button type="button" id="wcp-btn-cancel-subpage" class="wcp-edit-link"><?php _e('cancel', 'work-copilot-theme'); ?></button>
                <span class="wcp-quick-status"></span>
            </form>
            <form id="wcp-create-heading-form" style="display:none;">
                <input type="hidden" name="page_id" value="<?php echo esc_attr($page_id); ?>">
                <input type="text" name="title" required placeholder="<?php esc_attr_e('Heading title...', 'work-copilot-theme'); ?>" class="wcp-form-control wcp-quick-title">
                <button type="submit" class="wcp-btn wcp-btn-primary wcp-btn-sm"><?php _e('Create heading', 'work-copilot-theme'); ?></button>
                <button type="button" id="wcp-btn-cancel-heading" class="wcp-edit-link"><?php _e('cancel', 'work-copilot-theme'); ?></button>
                <span class="wcp-quick-status"></span>
            </form>
            <form id="wcp-create-dynamic-listing-form" style="display:none;">
                <input type="hidden" name="page_id" value="<?php echo esc_attr($page_id); ?>">
                <input type="text" name="title" required placeholder="<?php esc_attr_e('List title, e.g. Open tasks', 'work-copilot-theme'); ?>" class="wcp-form-control wcp-quick-title">
                <select name="item_type" class="wcp-inline-select">
                    <option value=""><?php _e('Any type', 'work-copilot-theme'); ?></option>
                    <option value="task"><?php _e('Task', 'work-copilot-theme'); ?></option>
                    <option value="info"><?php _e('Info', 'work-copilot-theme'); ?></option>
                    <option value="learning"><?php _e('Learning', 'work-copilot-theme'); ?></option>
                </select>
                <select name="task_status" class="wcp-inline-select wcp-dl-status-select">
                    <option value=""><?php _e('Any status', 'work-copilot-theme'); ?></option>
                    <option value="to-do"><?php _e('To do', 'work-copilot-theme'); ?></option>
                    <option value="in-progress"><?php _e('In progress', 'work-copilot-theme'); ?></option>
                    <option value="done"><?php _e('Done', 'work-copilot-theme'); ?></option>
                </select>
                <select name="parent_page_id" class="wcp-inline-select wcp-dl-page-select">
                    <option value=""><?php _e('All pages', 'work-copilot-theme'); ?></option>
                    <?php
                    $all_pages = get_posts(array('post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC'));
                    foreach ($all_pages as $p) :
                    ?>
                        <option value="<?php echo esc_attr($p->ID); ?>"><?php echo esc_html($p->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="wcp-btn wcp-btn-primary wcp-btn-sm"><?php _e('Add list', 'work-copilot-theme'); ?></button>
                <button type="button" id="wcp-btn-cancel-dynamic-listing" class="wcp-edit-link"><?php _e('cancel', 'work-copilot-theme'); ?></button>
                <span class="wcp-quick-status"></span>
            </form>
        </div>

        <!-- Goal creation modal -->
        <div id="wcp-goal-modal" style="display:none;" aria-modal="true" role="dialog">
            <div class="wcp-modal-overlay">
                <div class="wcp-modal-box">

                    <!-- Step 1: describe the goal -->
                    <div id="wcp-goal-step-1">
                        <h2><?php _e('Create a Goal', 'work-copilot-theme'); ?></h2>
                        <p><?php _e('Describe what you want to achieve:', 'work-copilot-theme'); ?></p>
                        <textarea id="wcp-goal-description" rows="4" class="wcp-form-control" placeholder="<?php esc_attr_e('e.g. Launch the new product landing page by end of month', 'work-copilot-theme'); ?>"></textarea>
                        <div class="wcp-modal-actions">
                            <button type="button" id="wcp-goal-plan-btn" class="wcp-btn wcp-btn-primary"><?php _e('Plan with AI', 'work-copilot-theme'); ?></button>
                            <button type="button" class="wcp-goal-cancel wcp-edit-link"><?php _e('Cancel', 'work-copilot-theme'); ?></button>
                        </div>
                        <p class="wcp-goal-step1-status" style="display:none;"></p>
                    </div>

                    <!-- Step 2: review AI plan -->
                    <div id="wcp-goal-step-2" style="display:none;">
                        <h2><?php _e('Review Goal Plan', 'work-copilot-theme'); ?></h2>

                        <div class="wcp-form-group">
                            <label><?php _e('Goal title', 'work-copilot-theme'); ?></label>
                            <input type="text" id="wcp-goal-title" class="wcp-form-control" placeholder="<?php esc_attr_e('Short goal title', 'work-copilot-theme'); ?>">
                        </div>

                        <div class="wcp-form-group">
                            <label><?php _e('AI understanding — edit if needed', 'work-copilot-theme'); ?></label>
                            <textarea id="wcp-goal-understanding" rows="3" class="wcp-form-control"></textarea>
                        </div>

                        <div class="wcp-form-group">
                            <label><?php _e('Action items (uncheck any you don\'t want)', 'work-copilot-theme'); ?></label>
                            <ul id="wcp-goal-action-items" class="wcp-goal-items-list"></ul>
                        </div>

                        <div class="wcp-modal-actions">
                            <button type="button" id="wcp-goal-create-btn" class="wcp-btn wcp-btn-primary"><?php _e('Create Goal', 'work-copilot-theme'); ?></button>
                            <button type="button" class="wcp-goal-cancel wcp-edit-link"><?php _e('Cancel', 'work-copilot-theme'); ?></button>
                        </div>
                        <p class="wcp-goal-step2-status" style="display:none;"></p>
                    </div>

                </div>
            </div>
        </div>

    </section>

    <!-- Page Notes (bottom, collapsible) -->
    <?php $page_notes = get_post_meta($page_id, '_wcp_page_notes', true); ?>
    <div class="wcp-page-notes-wrap" data-page-id="<?php echo esc_attr($page_id); ?>">
        <div class="wcp-section-header">
            <span class="wcp-section-label">Notes</span>
            <button type="button" class="wcp-toggle-section wcp-edit-link"
                    data-section="page-notes"
                    data-page-id="<?php echo esc_attr($page_id); ?>">hide</button>
            <button type="button" class="wcp-page-notes-edit wcp-edit-link">[edit]</button>
        </div>
        <div class="wcp-section-body">
            <div class="wcp-page-notes-display<?php echo $page_notes ? '' : ' wcp-page-notes-empty'; ?>">
                <?php echo $page_notes ? wp_kses_post($page_notes) : '<span class="wcp-page-notes-placeholder">Add notes…</span>'; ?>
            </div>
            <div class="wcp-page-notes-editor" style="display:none;">
                <textarea class="wcp-page-notes-textarea wcp-form-control" rows="4"><?php echo esc_textarea($page_notes); ?></textarea>
                <div class="wcp-page-notes-actions">
                    <button type="button" class="wcp-page-notes-save wcp-btn wcp-btn-primary wcp-btn-sm">Save</button>
                    <button type="button" class="wcp-page-notes-cancel wcp-edit-link">cancel</button>
                    <span class="wcp-page-notes-status"></span>
                </div>
            </div>
        </div>
    </div>

</div><!-- .wcp-page-content -->

<?php
get_footer();
