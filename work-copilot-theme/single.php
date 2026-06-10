<?php
/**
 * Single Post Template
 * Displays individual ItemPosts
 */

get_header();
?>

<div class="wcp-single-container">
    <?php get_sidebar(); ?>

    <main class="wcp-single-content">
        <?php
        while (have_posts()) :
            the_post();

            // Get taxonomies
            $item_types = wp_get_post_terms(get_the_ID(), 'item_type', array('fields' => 'names'));
            $priorities = wp_get_post_terms(get_the_ID(), 'priority', array('fields' => 'names'));
            $contexts = wp_get_post_terms(get_the_ID(), 'wcp_context', array('fields' => 'names'));
            $tags = wp_get_post_tags(get_the_ID(), array('fields' => 'names'));
        ?>

        <article id="post-<?php the_ID(); ?>" <?php post_class('wcp-single-item'); ?>>
            <header class="wcp-item-header">
                <?php
                // Display breadcrumbs
                $breadcrumbs = wcp_theme_get_item_breadcrumbs(get_the_ID());
                if (!empty($breadcrumbs)) {
                    include(locate_template('template-parts/breadcrumbs.php'));
                }
                ?>

                <h1 class="wcp-item-title"><?php the_title(); ?></h1>

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

                    <span class="wcp-item-date">
                        <?php echo get_the_date(); ?>
                    </span>
                </div>
            </header>

            <div class="wcp-item-content">
                <?php the_content(); ?>
            </div>

            <?php if (function_exists('wcpg_connections_panel')) wcpg_connections_panel(get_the_ID()); ?>

            <?php if (!empty($tags)) : ?>
            <footer class="wcp-item-footer">
                <div class="wcp-item-tags">
                    <strong>Tags:</strong>
                    <?php foreach ($tags as $tag) : ?>
                        <span class="wcp-tag"><?php echo esc_html($tag); ?></span>
                    <?php endforeach; ?>
                </div>
            </footer>
            <?php endif; ?>

            <div class="wcp-item-actions">
                <?php
                // Get edit link - pass post ID explicitly
                $edit_link = get_edit_post_link(get_the_ID());

                if ($edit_link && current_user_can('edit_post', get_the_ID())) :
                ?>
                    <a href="<?php echo esc_url($edit_link); ?>" class="wcp-btn wcp-btn-secondary">
                        Edit Item
                    </a>
                <?php elseif (is_user_logged_in()) : ?>
                    <span class="wcp-notice">You don't have permission to edit this item.</span>
                <?php else : ?>
                    <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="wcp-btn wcp-btn-secondary">
                        Log in to edit
                    </a>
                <?php endif; ?>

                <?php
                // Get the context page to link back
                if (!empty($contexts)) {
                    $context_terms = wp_get_post_terms(get_the_ID(), 'wcp_context');
                    if (!empty($context_terms)) {
                        $ref_id = get_term_meta($context_terms[0]->term_id, 'wcp_ref_id', true);
                        if ($ref_id) {
                            $page_url = get_permalink($ref_id);
                            if ($page_url) {
                                echo '<a href="' . esc_url($page_url) . '" class="wcp-btn wcp-btn-secondary">Back to Page</a>';
                            }
                        }
                    }
                }
                ?>
            </div>
        </article>

        <?php endwhile; ?>
    </main>
</div>

<?php
get_footer();
