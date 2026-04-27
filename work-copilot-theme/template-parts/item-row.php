<?php
/**
 * Partial: single item row
 * Expects: $item, $item_types, $priorities, $item_tags, $item_contexts
 */
if (!defined('ABSPATH')) exit;
?>
<div class="wcp-item-row" data-item-id="<?php echo esc_attr($item->ID); ?>">
    <span class="wcp-drag-handle" title="Drag to reorder">&#8942;</span>
    <span class="wcp-item-title"><?php echo esc_html($item->post_title); ?></span>
    <input type="text" class="wcp-item-title-input" style="display:none;" value="<?php echo esc_attr($item->post_title); ?>">

    <?php if (!empty($item_contexts)) : ?>
        <span class="wcp-item-meta-pills">
            <?php foreach ($item_contexts as $ctx) : ?>
                <span class="wcp-pill wcp-pill-context"><?php echo esc_html($ctx); ?></span>
            <?php endforeach; ?>
        </span>
    <?php endif; ?>

    <?php if (!empty($item_tags)) : ?>
        <span class="wcp-item-meta-pills">
            <?php foreach ($item_tags as $tag) : ?>
                <span class="wcp-pill wcp-pill-tag"><?php echo esc_html($tag); ?></span>
            <?php endforeach; ?>
        </span>
    <?php endif; ?>

    <select class="wcp-inline-select wcp-type-select" data-item-id="<?php echo esc_attr($item->ID); ?>">
        <option value=""><?php _e('type', 'work-copilot-theme'); ?></option>
        <?php foreach (array('task', 'info', 'learning') as $type) : ?>
            <option value="<?php echo $type; ?>" <?php selected(!empty($item_types) && $item_types[0] === $type); ?>><?php echo $type; ?></option>
        <?php endforeach; ?>
    </select>

    <select class="wcp-inline-select wcp-priority-select" data-item-id="<?php echo esc_attr($item->ID); ?>">
        <option value=""><?php _e('prio', 'work-copilot-theme'); ?></option>
        <?php foreach (array('high', 'medium', 'low') as $prio) : ?>
            <option value="<?php echo $prio; ?>" <?php selected(!empty($priorities) && $priorities[0] === $prio); ?>><?php echo $prio; ?></option>
        <?php endforeach; ?>
    </select>

    <button type="button" class="wcp-item-delete wcp-edit-link" data-item-id="<?php echo esc_attr($item->ID); ?>">[delete]</button>
</div>
