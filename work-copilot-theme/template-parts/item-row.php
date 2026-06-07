<?php
/**
 * Partial: single item row
 * Expects: $item, $item_types, $priorities, $task_statuses, $item_tags, $item_contexts
 */
if (!defined('ABSPATH')) exit;
?>
<?php
$is_task = !empty($item_types) && $item_types[0] === 'task';
$is_done = !empty($task_statuses) && $task_statuses[0] === 'done';
?>
<?php
$_item_type_slug   = !empty($item_types) ? $item_types[0] : '';
$_task_status_slug = !empty($task_statuses) ? $task_statuses[0] : '';
$_due_date         = get_post_meta($item->ID, '_wcp_due_date', true) ?: '';
?>
<div class="wcp-item-row<?php echo $is_done ? ' wcp-task-done' : ''; ?>"
     data-item-id="<?php echo esc_attr($item->ID); ?>"
     data-item-type="<?php echo esc_attr($_item_type_slug); ?>"
     data-task-status="<?php echo esc_attr($_task_status_slug); ?>"
     data-due-date="<?php echo esc_attr($_due_date); ?>">
    <span class="wcp-drag-handle" title="Drag to reorder">&#8942;</span>
    <input type="checkbox"
           class="wcp-task-checkbox"
           data-item-id="<?php echo esc_attr($item->ID); ?>"
           <?php checked($is_done); ?>
           style="<?php echo $is_task ? '' : 'display:none;'; ?>">
    <span class="wcp-item-title"><?php echo esc_html($item->post_title); ?></span>
    <a href="<?php echo esc_url(get_permalink($item->ID)); ?>" class="wcp-item-view-link wcp-edit-link" title="View item">[view]</a>
    <?php $source_url = get_post_meta($item->ID, '_wcp_source_url', true); ?>
    <?php if ($source_url) : ?>
        <a href="<?php echo esc_url($source_url); ?>" class="wcp-source-link" target="_blank" rel="noopener" title="<?php echo esc_attr($source_url); ?>">↗</a>
    <?php endif; ?>
    <input type="text" class="wcp-item-title-input" style="display:none;" value="<?php echo esc_attr($item->post_title); ?>">

    <?php if (!empty($item->post_content)) : ?>
        <span class="wcp-item-description"><?php echo esc_html(wp_strip_all_tags($item->post_content)); ?></span>
    <?php endif; ?>

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

    <?php $current_status = !empty($task_statuses) ? $task_statuses[0] : ''; ?>
    <select class="wcp-inline-select wcp-status-select"
            data-item-id="<?php echo esc_attr($item->ID); ?>"
            style="<?php echo (!empty($item_types) && $item_types[0] === 'task') ? '' : 'display:none;'; ?>">
        <option value=""><?php _e('status', 'work-copilot-theme'); ?></option>
        <?php foreach (array('to-do' => 'to do', 'in-progress' => 'in progress', 'done' => 'done') as $slug => $label) : ?>
            <option value="<?php echo $slug; ?>" <?php selected($current_status, $slug); ?>><?php echo $label; ?></option>
        <?php endforeach; ?>
    </select>

    <input type="date"
           class="wcp-due-date-input"
           data-item-id="<?php echo esc_attr($item->ID); ?>"
           value="<?php echo esc_attr($_due_date); ?>"
           title="Due date"
           style="<?php echo $is_task ? '' : 'display:none;'; ?>">
    <button type="button" class="wcp-subtask-add-btn wcp-edit-link" data-item-id="<?php echo esc_attr($item->ID); ?>">+ subtask</button>
    <button type="button" class="wcp-item-delete wcp-edit-link" data-item-id="<?php echo esc_attr($item->ID); ?>">[delete]</button>

    <?php
    $subtasks = json_decode(get_post_meta($item->ID, '_wcp_subtasks', true) ?: '[]', true);
    $has_subtasks = !empty($subtasks);
    ?>
    <?php if ($has_subtasks || true) : // always render the section so the add-form has a place ?>
    <div class="wcp-subtask-section" data-item-id="<?php echo esc_attr($item->ID); ?>">
        <?php if ($has_subtasks) : ?>
        <ul class="wcp-subtask-list">
            <?php foreach ($subtasks as $st) : ?>
            <li class="wcp-subtask-row<?php echo $st['done'] ? ' wcp-subtask-done' : ''; ?>"
                data-subtask-id="<?php echo esc_attr($st['id']); ?>">
                <input type="checkbox"
                       class="wcp-subtask-checkbox"
                       data-item-id="<?php echo esc_attr($item->ID); ?>"
                       data-subtask-id="<?php echo esc_attr($st['id']); ?>"
                       <?php checked($st['done']); ?>>
                <span class="wcp-subtask-title"><?php echo esc_html($st['title']); ?></span>
                <button type="button"
                        class="wcp-subtask-delete wcp-edit-link"
                        data-item-id="<?php echo esc_attr($item->ID); ?>"
                        data-subtask-id="<?php echo esc_attr($st['id']); ?>">×</button>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <form class="wcp-subtask-add-form" data-item-id="<?php echo esc_attr($item->ID); ?>" style="display:none;">
            <input type="text" class="wcp-subtask-input" placeholder="Subtask title…" autocomplete="off">
            <button type="submit" class="wcp-btn wcp-btn-primary wcp-btn-sm">Add</button>
            <button type="button" class="wcp-subtask-add-cancel wcp-edit-link">cancel</button>
        </form>
    </div>
    <?php endif; ?>
</div>
