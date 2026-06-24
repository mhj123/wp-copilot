<?php
/**
 * Template for search results — intercepts ?s= queries.
 * Searches item posts (post type = post) only. Renders flat, one row per result.
 */

get_header();

$search_query = get_search_query();

$results = new WP_Query( array(
    'post_type'      => 'post',
    'post_status'    => 'publish',
    's'              => $search_query,
    'posts_per_page' => 100,
    'orderby'        => 'relevance',
) );
?>

<div class="wcp-search-results">

    <header class="wcp-page-header-clean">
        <h1 class="wcp-page-title-clean">
            Search: <em><?php echo esc_html( $search_query ); ?></em>
        </h1>
        <p class="wcp-search-count">
            <?php
            if ( $results->found_posts === 1 ) {
                echo '1 item found';
            } else {
                echo esc_html( $results->found_posts ) . ' items found';
            }
            ?>
        </p>
    </header>

    <?php if ( $results->have_posts() ) : ?>

        <div class="wcp-items-list wcp-search-items-list">
            <?php while ( $results->have_posts() ) : $results->the_post(); ?>
                <?php
                $item          = get_post();
                $item_types    = wp_get_post_terms( $item->ID, 'item_type',   array( 'fields' => 'names' ) );
                $priorities    = wp_get_post_terms( $item->ID, 'priority',    array( 'fields' => 'names' ) );
                $task_statuses = wp_get_post_terms( $item->ID, 'task_status', array( 'fields' => 'slugs' ) );
                $item_tags     = wp_get_post_terms( $item->ID, 'post_tag',    array( 'fields' => 'names' ) );
                $item_contexts = wp_get_post_terms( $item->ID, 'wcp_context', array( 'fields' => 'names' ) );
                include locate_template( 'template-parts/item-row.php' );
                ?>
            <?php endwhile; ?>
            <?php wp_reset_postdata(); ?>
        </div>

    <?php else : ?>

        <p class="wcp-search-empty">No items found for &ldquo;<?php echo esc_html( $search_query ); ?>&rdquo;.</p>

    <?php endif; ?>

</div>

<?php get_footer(); ?>
