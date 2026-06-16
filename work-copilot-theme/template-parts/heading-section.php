<?php
/**
 * Template part for displaying a Heading section
 *
 * Expects: $heading, $page_id
 */

$heading_id = $heading->ID;
$heading_term = wcp_theme_get_heading_context_term($heading_id);
$items = wcp_theme_get_heading_items($heading_id);

$heading_created_by = get_post_meta($heading_id, '_wcp_created_by', true);
$heading_creator_class = $heading_created_by === 'hermes' ? ' wcp-by-hermes' : ($heading_created_by === 'copilot' ? ' wcp-by-copilot' : '');
?>

<div class="wcp-heading-section<?php echo esc_attr($heading_creator_class); ?>" data-heading-id="<?php echo esc_attr($heading_id); ?>">
    <div class="wcp-heading-header">
        <h3 class="wcp-heading-title">
            <span class="wcp-toggle-icon">▶</span>
            <?php echo esc_html($heading->post_title); ?>
            <span class="wcp-item-count">(<?php echo count($items); ?>)</span>
        </h3>
        <button type="button" class="wcp-btn-add-item wcp-btn wcp-btn-secondary" data-heading-id="<?php echo esc_attr($heading_id); ?>">
            + Add Item
        </button>
    </div>

    <div class="wcp-heading-body" style="display: none;">
        <?php
        // Display heading breadcrumbs
        $breadcrumbs = wcp_theme_get_heading_breadcrumbs($heading_id);
        if (!empty($breadcrumbs)) {
            $show_home = false; // Don't show home link in heading sections
            include(locate_template('template-parts/breadcrumbs.php'));
        }
        ?>

        <?php if (!empty($heading->post_content)) : ?>
            <div class="wcp-heading-content">
                <?php echo wpautop($heading->post_content); ?>
            </div>
        <?php endif; ?>

        <!-- Item creation form (initially hidden) -->
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
                <p class="description"><?php _e('Current heading is pre-selected. You can add or remove contexts.', 'work-copilot-theme'); ?></p>
            </div>

            <div class="wcp-form-row">
                <div class="wcp-form-group">
                    <label><?php _e('Item Type', 'work-copilot-theme'); ?></label>
                    <select name="item_type" class="wcp-form-control">
                        <option value=""><?php _e('-- Select --', 'work-copilot-theme'); ?></option>
                        <option value="task"><?php _e('Task', 'work-copilot-theme'); ?></option>
                        <option value="info"><?php _e('Info', 'work-copilot-theme'); ?></option>
                        <option value="learning"><?php _e('Learning', 'work-copilot-theme'); ?></option>
                        <option value="spec"><?php _e('Spec', 'work-copilot-theme'); ?></option>
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

        <!-- Items list -->
        <div class="wcp-heading-items-list">
            <?php if (empty($items)) : ?>
                <p class="wcp-no-items"><?php _e('No items under this heading yet.', 'work-copilot-theme'); ?></p>
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

                        <h4 class="wcp-item-title">
                            <a href="<?php echo get_permalink($item->ID); ?>"><?php echo esc_html($item->post_title); ?></a>
                        </h4>

                        <?php if (!empty($item->post_content)) : ?>
                            <div class="wcp-item-excerpt">
                                <?php echo wp_trim_words($item->post_content, 30); ?>
                            </div>
                        <?php endif; ?>

                        <span class="wcp-item-date"><?php echo get_the_date('M j', $item->ID); ?></span>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if (function_exists('wcpg_render_tables')) wcpg_render_tables($page_id, $heading_id); ?>
    </div>
</div>
