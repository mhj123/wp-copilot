<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="profile" href="https://gmpg.org/xfn/11">
    <?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="skip-link screen-reader-text" href="#primary"><?php esc_html_e('Skip to content', 'work-copilot-theme'); ?></a>

<div class="wcp-theme-container" style="--wcp-accent: <?php echo esc_attr(wcp_theme_section_accent()); ?>;">

    <!-- Left Sidebar with Page Navigation -->
    <?php get_sidebar(); ?>

    <!-- Main Content Area -->
    <div id="primary" class="wcp-main-content">

        <!-- Site Header -->
        <header class="wcp-site-header">
            <div class="wcp-site-branding">
                <h1 class="wcp-site-title">
                    <a href="<?php echo esc_url(home_url('/')); ?>" rel="home">
                        <?php bloginfo('name'); ?>
                    </a>
                </h1>
                <?php
                $description = get_bloginfo('description', 'display');
                if ($description || is_customize_preview()) :
                ?>
                    <p class="wcp-site-description"><?php echo $description; ?></p>
                <?php endif; ?>
            </div>
        </header>

        <main class="wcp-content-wrapper">
