<?php
/**
 * Partial: dynamic listing section
 * Expects: $listing (array with id, title, item_type, task_status, parent_page_id)
 *          $page_id (current page)
 */
if (!defined('ABSPATH')) exit;

$dl_items = wcp_theme_query_dynamic_listing($listing);
?>
<div class="wcp-dynamic-listing" data-listing-id="<?php echo esc_attr($listing['id']); ?>">
    <h3 class="wcp-heading-title-simple wcp-dynamic-listing-title">
        <span class="wcp-dynamic-listing-badge">list</span>
        <?php echo esc_html($listing['title']); ?>
        <button type="button"
                class="wcp-sort-due-date wcp-edit-link"
                data-scope="listing"
                data-listing-id="<?php echo esc_attr($listing['id']); ?>">sort by due date</button>
        <button type="button"
                class="wcp-edit-link wcp-dynamic-listing-refresh"
                data-page-id="<?php echo esc_attr($page_id); ?>"
                data-listing-id="<?php echo esc_attr($listing['id']); ?>">[refresh]</button>
        <button type="button"
                class="wcp-edit-link wcp-dynamic-listing-delete"
                data-page-id="<?php echo esc_attr($page_id); ?>"
                data-listing-id="<?php echo esc_attr($listing['id']); ?>">[remove]</button>
    </h3>

    <?php if (!empty($dl_items)) : ?>
        <div class="wcp-items-list wcp-dynamic-listing-items">
            <?php foreach ($dl_items as $item) :
                $item_types    = wp_get_post_terms($item->ID, 'item_type',   array('fields' => 'names'));
                $priorities    = wp_get_post_terms($item->ID, 'priority',    array('fields' => 'names'));
                $task_statuses = wp_get_post_terms($item->ID, 'task_status', array('fields' => 'slugs'));
                $item_tags     = wp_get_post_terms($item->ID, 'post_tag',    array('fields' => 'names'));
                // Show all wcp_context terms so the user can see where each item lives
                $item_contexts = wp_get_post_terms($item->ID, 'wcp_context', array('fields' => 'names'));
            ?>
                <?php include locate_template('template-parts/item-row.php'); ?>
            <?php endforeach; ?>
        </div>
    <?php else : ?>
        <p class="wcp-dynamic-listing-empty">No items match this query.</p>
    <?php endif; ?>
</div>
