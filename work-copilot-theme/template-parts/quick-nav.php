<?php
/**
 * Quick-jump palette (Cmd/Ctrl+K).
 *
 * Injected on every workspace page via wp_footer (see wcp_theme_quick_nav_footer()).
 * Reuses the theme's existing .wcp-modal-overlay / .wcp-modal-box chrome, with
 * wcp-jump-* modifiers for the top-anchored palette layout.
 *
 * @package Work_Copilot_Theme
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div id="wcp-jump-modal" class="wcp-jump-modal" style="display:none;">
    <div class="wcp-modal-overlay wcp-jump-overlay">
        <div class="wcp-modal-box wcp-jump-box" role="dialog" aria-modal="true"
             aria-label="<?php esc_attr_e('Jump to a page', 'work-copilot-theme'); ?>">

            <input type="text"
                   id="wcp-jump-input"
                   class="wcp-jump-input"
                   autocomplete="off"
                   spellcheck="false"
                   role="combobox"
                   aria-expanded="true"
                   aria-controls="wcp-jump-results"
                   aria-autocomplete="list"
                   placeholder="<?php esc_attr_e('Jump to a page…', 'work-copilot-theme'); ?>">

            <ul id="wcp-jump-results" class="wcp-jump-results" role="listbox"
                aria-label="<?php esc_attr_e('Matching pages', 'work-copilot-theme'); ?>"></ul>

            <p class="wcp-jump-empty" style="display:none;">
                <?php esc_html_e('No pages match.', 'work-copilot-theme'); ?>
            </p>
        </div>
    </div>
</div>
