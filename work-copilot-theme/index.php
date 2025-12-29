<?php
/**
 * Main template file
 */

get_header();
?>

<div class="wcp-index-content">

    <h1><?php _e('Welcome to Work Copilot', 'work-copilot-theme'); ?></h1>

    <p><?php _e('Get started by creating Pages to organize your work, then add Items to capture your thoughts and tasks.', 'work-copilot-theme'); ?></p>

    <div class="wcp-getting-started">
        <a href="<?php echo admin_url('post-new.php?post_type=page'); ?>" class="wcp-btn wcp-btn-primary">
            <?php _e('Create Your First Page', 'work-copilot-theme'); ?>
        </a>
    </div>

    <?php
    // Show recent posts if any exist
    $recent_posts = get_posts(array(
        'post_type' => 'post',
        'posts_per_page' => 5,
    ));

    if (!empty($recent_posts)) :
    ?>
    <section class="wcp-recent-items">
        <h2><?php _e('Recent Items', 'work-copilot-theme'); ?></h2>
        <div class="wcp-items-list">
            <?php foreach ($recent_posts as $post) :
                setup_postdata($post);
                $item_types = wp_get_post_terms($post->ID, 'item_type', array('fields' => 'names'));
            ?>
            <article class="wcp-item">
                <h3 class="wcp-item-title">
                    <a href="<?php echo get_permalink($post->ID); ?>"><?php echo esc_html($post->post_title); ?></a>
                </h3>
                <?php if (!empty($item_types)) : ?>
                    <span class="wcp-badge wcp-type-<?php echo esc_attr($item_types[0]); ?>">
                        <?php echo esc_html($item_types[0]); ?>
                    </span>
                <?php endif; ?>
                <div class="wcp-item-excerpt">
                    <?php echo wp_trim_words($post->post_content, 20); ?>
                </div>
            </article>
            <?php endforeach;
            wp_reset_postdata();
            ?>
        </div>
    </section>
    <?php endif; ?>

</div>

<?php
get_footer();
