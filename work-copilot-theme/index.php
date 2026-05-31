<?php
/**
 * Main template file
 */

get_header();
?>

<div class="wcp-index-content">

    <section class="wcp-structure-section">
        <h2><?php _e('Structure', 'work-copilot-theme'); ?></h2>
        <div id="wcp-structure-tree" class="wcp-structure-tree-container">
            <p class="wcp-tree-loading"><?php _e('Loading&hellip;', 'work-copilot-theme'); ?></p>
        </div>
    </section>

</div>

<?php
get_footer();
