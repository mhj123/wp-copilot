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

            <?php
            // Belongs to: every structural location this item sits in (it may be
            // assigned to several pages/headings), each as a linked path.
            $context_paths = wcp_theme_get_item_context_paths(get_the_ID());
            ?>
            <?php if (!empty($context_paths) || !empty($tags)) : ?>
            <section class="wcp-item-taxonomy">
                <?php if (!empty($context_paths)) : ?>
                <div class="wcp-item-belongs">
                    <span class="wcp-section-label"><?php _e('Belongs to', 'work-copilot-theme'); ?></span>
                    <ul class="wcp-belongs-list">
                        <?php foreach ($context_paths as $trail) : ?>
                        <li class="wcp-belongs-path">
                            <?php foreach ($trail as $i => $crumb) : ?>
                                <?php if ($i > 0) : ?><span class="wcp-belongs-sep">›</span><?php endif; ?>
                                <a href="<?php echo esc_url($crumb['url']); ?>"><?php echo esc_html($crumb['title']); ?></a>
                            <?php endforeach; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <?php if (!empty($tags)) : ?>
                <div class="wcp-item-tags">
                    <span class="wcp-section-label"><?php _e('Tags', 'work-copilot-theme'); ?></span>
                    <?php foreach ($tags as $tag) : ?>
                        <a class="wcp-tag" href="<?php echo esc_url(home_url('/?tag=' . urlencode(sanitize_title($tag)))); ?>"><?php echo esc_html($tag); ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php // Related to: semantic + structural connections (graph add-on). ?>
            <?php if (function_exists('wcpg_connections_panel')) wcpg_connections_panel(get_the_ID()); ?>

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
