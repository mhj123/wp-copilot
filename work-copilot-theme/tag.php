<?php
/**
 * Tag archive — lists all items tagged with a given tag
 */

get_header();

$tag = get_queried_object();
$tag_name = $tag ? $tag->name : '';
$tag_slug = $tag ? $tag->slug : '';

$items = $tag ? get_posts(array(
    'post_type'      => 'post',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'tag'            => $tag_slug,
    'orderby'        => array('menu_order' => 'ASC', 'date' => 'ASC'),
)) : array();
?>

<div class="wcp-page-content">
    <header class="wcp-page-header-clean">
        <p class="wcp-tag-archive-label">Tag</p>
        <h1 class="wcp-page-title-clean"><?php echo esc_html($tag_name); ?></h1>
    </header>

    <section class="wcp-items-section">
        <div class="wcp-items-toolbar">
            <span class="wcp-tag-item-count"><?php echo count($items); ?> item<?php echo count($items) !== 1 ? 's' : ''; ?></span>
        </div>

        <div class="wcp-items-list">
            <?php if (empty($items)) : ?>
                <p style="color:#999;font-style:italic;padding:20px 0;">No items tagged "<?php echo esc_html($tag_name); ?>".</p>
            <?php else : ?>
                <?php foreach ($items as $item) :
                    $item_types    = wp_get_post_terms($item->ID, 'item_type',   array('fields' => 'names'));
                    $priorities    = wp_get_post_terms($item->ID, 'priority',    array('fields' => 'names'));
                    $task_statuses = wp_get_post_terms($item->ID, 'task_status', array('fields' => 'slugs'));
                    $item_tags     = wp_get_post_terms($item->ID, 'post_tag',    array('fields' => 'names'));
                    $item_contexts = wp_get_post_terms($item->ID, 'wcp_context', array('fields' => 'names'));
                ?>
                    <?php include locate_template('template-parts/item-row.php'); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php
get_footer();
