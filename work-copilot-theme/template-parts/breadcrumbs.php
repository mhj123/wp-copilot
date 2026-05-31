<?php
/**
 * Template part for displaying breadcrumbs
 *
 * Expected variables:
 * - $breadcrumbs: Array of breadcrumb items with 'title', 'url', and optional 'type'
 * - $show_home: Boolean, whether to prepend a "Home" link (default: true)
 */

if (empty($breadcrumbs)) {
    return;
}

$show_home = isset($show_home) ? $show_home : true;
?>

<nav class="wcp-breadcrumbs" aria-label="Breadcrumb">
    <ol class="wcp-breadcrumb-list">
        <?php if ($show_home) : ?>
        <li class="wcp-breadcrumb-item">
            <a href="<?php echo esc_url(home_url('/')); ?>" class="wcp-breadcrumb-link">
                <span class="wcp-breadcrumb-home-icon">🏠</span>
                <span class="wcp-breadcrumb-text">Home</span>
            </a>
        </li>
        <?php endif; ?>

        <?php foreach ($breadcrumbs as $index => $crumb) :
            $is_last = ($index === count($breadcrumbs) - 1);
            $has_url = !empty($crumb['url']);
            $is_heading = isset($crumb['type']) && $crumb['type'] === 'heading';
        ?>
        <li class="wcp-breadcrumb-item <?php echo $is_last ? 'wcp-breadcrumb-current' : ''; ?>">
            <span class="wcp-breadcrumb-separator" aria-hidden="true">›</span>

            <?php if ($has_url && !$is_last) : ?>
                <a href="<?php echo esc_url($crumb['url']); ?>" class="wcp-breadcrumb-link">
                    <?php if ($is_heading) : ?>
                        <span class="wcp-breadcrumb-heading-icon">📑</span>
                    <?php endif; ?>
                    <span class="wcp-breadcrumb-text"><?php echo esc_html($crumb['title']); ?></span>
                </a>
            <?php else : ?>
                <span class="wcp-breadcrumb-current-text">
                    <?php if ($is_heading) : ?>
                        <span class="wcp-breadcrumb-heading-icon">📑</span>
                    <?php endif; ?>
                    <span class="wcp-breadcrumb-text"><?php echo esc_html($crumb['title']); ?></span>
                </span>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ol>
</nav>
