<?php
/**
 * Partial: quick inline item creation form
 * Expects: $page_context_id (the wcp_context term_id to assign the new item to)
 */
if (!defined('ABSPATH')) exit;
?>
<div class="wcp-quick-add-wrap">
    <button type="button" class="wcp-btn-quick-add-item wcp-edit-link" data-context-id="<?php echo esc_attr($page_context_id); ?>">+ add item</button>
    <form class="wcp-quick-item-form" style="display:none;" data-context-id="<?php echo esc_attr($page_context_id); ?>">
        <input type="hidden" name="context_id" value="<?php echo esc_attr($page_context_id); ?>">
        <input type="text" name="title" required placeholder="<?php esc_attr_e('Item title...', 'work-copilot-theme'); ?>" class="wcp-form-control wcp-quick-title">
        <select name="item_type" class="wcp-inline-select">
            <option value=""><?php _e('type', 'work-copilot-theme'); ?></option>
            <option value="task"><?php _e('task', 'work-copilot-theme'); ?></option>
            <option value="info"><?php _e('info', 'work-copilot-theme'); ?></option>
            <option value="learning"><?php _e('learning', 'work-copilot-theme'); ?></option>
            <option value="spec"><?php _e('spec', 'work-copilot-theme'); ?></option>
        </select>
        <select name="priority" class="wcp-inline-select">
            <option value=""><?php _e('prio', 'work-copilot-theme'); ?></option>
            <option value="high"><?php _e('high', 'work-copilot-theme'); ?></option>
            <option value="medium"><?php _e('medium', 'work-copilot-theme'); ?></option>
            <option value="low"><?php _e('low', 'work-copilot-theme'); ?></option>
        </select>
        <input type="text" name="tags" class="wcp-quick-tags" placeholder="<?php esc_attr_e('tags...', 'work-copilot-theme'); ?>">
        <button type="submit" class="wcp-btn wcp-btn-primary wcp-btn-sm"><?php _e('Add', 'work-copilot-theme'); ?></button>
        <button type="button" class="wcp-btn-cancel-quick wcp-edit-link"><?php _e('cancel', 'work-copilot-theme'); ?></button>
        <div class="wcp-form-context-section">
            <button type="button" class="wcp-toggle-form-contexts wcp-edit-link"><?php _e('+ pages...', 'work-copilot-theme'); ?></button>
            <div class="wcp-form-contexts" style="display:none;"></div>
        </div>
        <span class="wcp-quick-status"></span>
    </form>
</div>
