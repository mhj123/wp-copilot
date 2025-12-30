<?php
/**
 * Template for displaying Pages with ItemPosts
 */

get_header();
?>

<div class="wcp-page-content">

    <?php
    while (have_posts()) :
        the_post();
    ?>

    <article id="post-<?php the_ID(); ?>" <?php post_class('wcp-page-article'); ?>>

        <header class="wcp-page-header">
            <h1 class="wcp-page-title"><?php the_title(); ?></h1>
        </header>

        <div class="wcp-page-body">
            <?php the_content(); ?>
        </div>

    </article>

    <?php
    endwhile;

    // Get items for this page
    $page_id = get_the_ID();
    $items = wcp_theme_get_page_items($page_id);
    ?>

    <!-- Semantic Search Widget -->
    <?php if (get_option('wcp_embeddings_enabled', false)) : ?>
    <section class="wcp-semantic-search-section">
        <div class="wcp-search-toggle">
            <button type="button" id="wcp-toggle-search" class="wcp-btn wcp-btn-secondary">
                <span class="wcp-search-icon">🔍</span>
                <?php _e('Search My Notes', 'work-copilot-theme'); ?>
            </button>
        </div>

        <div id="wcp-search-panel" class="wcp-search-panel" style="display: none;">
            <div class="wcp-search-input-wrapper">
                <input
                    type="text"
                    id="wcp-semantic-search-input"
                    class="wcp-form-control wcp-search-input"
                    placeholder="<?php esc_attr_e('Search by meaning... e.g., "customer feedback"', 'work-copilot-theme'); ?>"
                >
                <button type="button" id="wcp-semantic-search-btn" class="wcp-btn wcp-btn-primary wcp-search-btn">
                    <?php _e('Search', 'work-copilot-theme'); ?>
                </button>
            </div>

            <div id="wcp-search-results" class="wcp-search-results"></div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Create ItemPost Form -->
    <section class="wcp-create-item-section">
        <h2><?php _e('Create New Item', 'work-copilot-theme'); ?></h2>

        <form id="wcp-create-item-form" class="wcp-item-form">
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
                <label for="wcp-item-contexts"><?php _e('Contexts (Pages and Headings)', 'work-copilot-theme'); ?></label>
                <div id="wcp-item-contexts" class="wcp-context-selector-wrapper">
                    <p class="wcp-loading"><?php _e('Loading contexts...', 'work-copilot-theme'); ?></p>
                </div>
                <p class="description"><?php _e('Select which pages or headings this item belongs to. Current page is pre-selected.', 'work-copilot-theme'); ?></p>
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
                <span class="wcp-form-status"></span>
            </div>
        </form>
    </section>

    <!-- Child Pages -->
    <?php
    $child_pages = wcp_theme_get_page_tree($page_id);
    if (!empty($child_pages)) :
    ?>
    <section class="wcp-child-pages-section">
        <h2><?php _e('Sub-Pages', 'work-copilot-theme'); ?> (<?php echo count($child_pages); ?>)</h2>
        <div class="wcp-child-pages-list">
            <?php foreach ($child_pages as $child_page) : ?>
                <a href="<?php echo get_permalink($child_page->ID); ?>" class="wcp-child-page-link">
                    <span class="wcp-child-page-icon">📄</span>
                    <?php echo esc_html($child_page->post_title); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- ItemPosts List -->
    <section class="wcp-items-section">
        <h2><?php _e('Items', 'work-copilot-theme'); ?> (<?php echo count($items); ?>)</h2>

        <!-- Filters -->
        <div class="wcp-items-filters">
            <select id="wcp-filter-type" class="wcp-filter-control">
                <option value=""><?php _e('All Types', 'work-copilot-theme'); ?></option>
                <option value="task"><?php _e('Tasks', 'work-copilot-theme'); ?></option>
                <option value="info"><?php _e('Info', 'work-copilot-theme'); ?></option>
                <option value="learning"><?php _e('Learnings', 'work-copilot-theme'); ?></option>
            </select>

            <select id="wcp-filter-priority" class="wcp-filter-control">
                <option value=""><?php _e('All Priorities', 'work-copilot-theme'); ?></option>
                <option value="high"><?php _e('High', 'work-copilot-theme'); ?></option>
                <option value="medium"><?php _e('Medium', 'work-copilot-theme'); ?></option>
                <option value="low"><?php _e('Low', 'work-copilot-theme'); ?></option>
            </select>
        </div>

        <div id="wcp-items-list" class="wcp-items-list">
            <?php if (empty($items)) : ?>
                <p class="wcp-no-items"><?php _e('No items yet. Create your first item above!', 'work-copilot-theme'); ?></p>
            <?php else : ?>
                <?php foreach ($items as $item) :
                    $item_types = wp_get_post_terms($item->ID, 'item_type', array('fields' => 'names'));
                    $priorities = wp_get_post_terms($item->ID, 'priority', array('fields' => 'names'));
                    $pinned = wp_get_post_terms($item->ID, 'pinned', array('fields' => 'names'));
                    $is_pinned = in_array('yes', $pinned);
                ?>
                <article class="wcp-item <?php echo $is_pinned ? 'wcp-item-pinned' : ''; ?>">
                    <div class="wcp-item-meta">
                        <?php if (!empty($item_types)) : ?>
                            <span class="wcp-badge wcp-type-<?php echo esc_attr($item_types[0]); ?>">
                                <?php echo esc_html($item_types[0]); ?>
                            </span>
                        <?php endif; ?>

                        <?php if (!empty($priorities)) : ?>
                            <span class="wcp-badge wcp-priority-<?php echo esc_attr($priorities[0]); ?>">
                                <?php echo esc_html($priorities[0]); ?>
                            </span>
                        <?php endif; ?>

                        <?php if ($is_pinned) : ?>
                            <span class="wcp-badge wcp-pinned-badge">📌</span>
                        <?php endif; ?>
                    </div>

                    <h3 class="wcp-item-title">
                        <a href="<?php echo get_permalink($item->ID); ?>"><?php echo esc_html($item->post_title); ?></a>
                    </h3>

                    <div class="wcp-item-excerpt">
                        <?php echo wp_trim_words($item->post_content, 30); ?>
                    </div>

                    <span class="wcp-item-date"><?php echo get_the_date('M j', $item->ID); ?></span>
                </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

</div><!-- .wcp-page-content -->

<?php
get_footer();
