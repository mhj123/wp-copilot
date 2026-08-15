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
$_item_type_slug    = !empty($item_types) ? $item_types[0] : '';
$_task_status_slug  = !empty($task_statuses) ? $task_statuses[0] : '';
$_is_spec           = $_item_type_slug === 'spec';
$_created_by        = get_post_meta($item->ID, '_wcp_created_by', true);
if (!$_created_by && get_post_meta($item->ID, '_wcp_ai_generated', true)) {
    $_created_by = 'copilot'; // legacy content predating the _wcp_created_by marker
}
$_creator_class     = $_created_by === 'hermes' ? ' wcp-by-hermes' : ($_created_by === 'copilot' ? ' wcp-by-copilot' : '');
$_pinned_slugs      = wp_get_post_terms($item->ID, 'pinned', array('fields' => 'slugs'));
$_is_pinned         = !is_wp_error($_pinned_slugs) && in_array('yes', $_pinned_slugs, true);
$_spec_statuses     = wp_get_post_terms($item->ID, 'spec_status', array('fields' => 'slugs'));
$_spec_statuses     = is_wp_error($_spec_statuses) ? array() : $_spec_statuses;
$_spec_status_slug  = !empty($_spec_statuses) ? $_spec_statuses[0] : '';
$_due_date          = get_post_meta($item->ID, '_wcp_due_date', true) ?: '';
$_context_ids       = wp_get_post_terms($item->ID, 'wcp_context', array('fields' => 'ids'));
$_context_ids       = is_wp_error($_context_ids) ? array() : $_context_ids;
$_delegation_active = class_exists('WCPD_Delegation_Manager');
$_delegations       = $_delegation_active ? WCPD_Delegation_Manager::instance()->get_delegations_for_item($item->ID) : array();
$_delegation_labels = array(
    'pending'     => 'delegated',
    'in_progress' => 'in progress',
    'needs_input' => 'question',
    'completed'   => 'completed',
    'failed'      => 'failed',
);
?>
<div class="wcp-item-row<?php echo $is_done ? ' wcp-task-done' : ''; echo esc_attr($_creator_class); echo $_is_pinned ? ' wcp-pinned' : ''; ?>"
     id="wcp-item-<?php echo esc_attr($item->ID); ?>"
     data-item-id="<?php echo esc_attr($item->ID); ?>"
     data-parent-id="<?php echo esc_attr($item->post_parent ?: 0); ?>"
     data-item-type="<?php echo esc_attr($_item_type_slug); ?>"
     data-task-status="<?php echo esc_attr($_task_status_slug); ?>"
     data-spec-status="<?php echo esc_attr($_spec_status_slug); ?>"
     data-due-date="<?php echo esc_attr($_due_date); ?>"
     data-priority="<?php echo esc_attr(!empty($priorities) ? $priorities[0] : ''); ?>"
     data-created="<?php echo esc_attr(get_post_time('U', true, $item)); ?>"
     data-context-ids="<?php echo esc_attr(implode(',', $_context_ids)); ?>"
     data-tags="<?php echo esc_attr(implode(',', $item_tags)); ?>">
    <span class="wcp-drag-handle" title="Drag to reorder">&#8942;</span>
    <input type="checkbox"
           class="wcp-task-checkbox"
           data-item-id="<?php echo esc_attr($item->ID); ?>"
           <?php checked($is_done); ?>
           style="<?php echo $is_task ? '' : 'display:none;'; ?>">
    <span class="wcp-item-title"><?php echo esc_html($item->post_title); ?></span>
    <input type="text" class="wcp-item-title-input" style="display:none;" value="<?php echo esc_attr($item->post_title); ?>">
    <span class="wcp-item-created" title="<?php echo esc_attr(get_the_time('j M Y', $item)); ?>"><?php echo esc_html(human_time_diff(get_post_time('U', true, $item))); ?> ago</span>

    <span class="wcp-row-actions">
        <select class="wcp-inline-select wcp-type-select" data-item-id="<?php echo esc_attr($item->ID); ?>">
            <option value=""><?php _e('type', 'work-copilot-theme'); ?></option>
            <?php foreach (array('task', 'info', 'learning', 'spec') as $type) : ?>
                <option value="<?php echo $type; ?>" <?php selected(!empty($item_types) && $item_types[0] === $type); ?>><?php echo $type; ?></option>
            <?php endforeach; ?>
        </select>

        <select class="wcp-inline-select wcp-priority-select" data-item-id="<?php echo esc_attr($item->ID); ?>">
            <option value=""><?php _e('prio', 'work-copilot-theme'); ?></option>
            <?php foreach (array('critical', 'high', 'medium', 'low') as $prio) : ?>
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

        <select class="wcp-inline-select wcp-spec-status-select"
                data-item-id="<?php echo esc_attr($item->ID); ?>"
                style="<?php echo $_is_spec ? '' : 'display:none;'; ?>">
            <option value=""><?php _e('status', 'work-copilot-theme'); ?></option>
            <?php foreach (array('draft' => 'draft', 'review' => 'review', 'final' => 'final') as $slug => $label) : ?>
                <option value="<?php echo $slug; ?>" <?php selected($_spec_status_slug, $slug); ?>><?php echo $label; ?></option>
            <?php endforeach; ?>
        </select>

        <input type="date"
               class="wcp-due-date-input"
               data-item-id="<?php echo esc_attr($item->ID); ?>"
               value="<?php echo esc_attr($_due_date); ?>"
               title="Due date"
               style="<?php echo $is_task ? '' : 'display:none;'; ?>">
        <button type="button" class="wcp-subtask-add-btn wcp-edit-link" data-item-id="<?php echo esc_attr($item->ID); ?>">+ subtask</button>
        <button type="button" class="wcp-item-context-btn wcp-edit-link" data-item-id="<?php echo esc_attr($item->ID); ?>">+ context</button>
        <button type="button" class="wcp-item-tag-btn wcp-edit-link" data-item-id="<?php echo esc_attr($item->ID); ?>">+ tag</button>
        <button type="button" class="wcp-item-delete wcp-edit-link" data-item-id="<?php echo esc_attr($item->ID); ?>">[delete]</button>
        <a href="<?php echo esc_url(get_permalink($item->ID)); ?>" class="wcp-item-view-link wcp-edit-link" title="View item">[view]</a>
        <button type="button" class="wcp-item-ai-btn wcp-edit-link" data-item-id="<?php echo esc_attr($item->ID); ?>" title="AI actions">[ai]</button>
        <button type="button" class="wcp-desc-toggle wcp-edit-link" data-item-id="<?php echo esc_attr($item->ID); ?>" title="Show/hide description">[desc]</button>
        <label class="wcp-pin-toggle" title="Pin to top of page">
            <input type="checkbox" class="wcp-pin-checkbox" data-item-id="<?php echo esc_attr($item->ID); ?>" <?php checked($_is_pinned); ?>>
            <span class="wcp-pin-icon" aria-hidden="true">&#128204;</span>
        </label>
    </span>

    <input type="checkbox" class="wcp-item-select-cb" data-item-id="<?php echo esc_attr($item->ID); ?>" data-item-title="<?php echo esc_attr($item->post_title); ?>" style="display:none;">
    <?php $source_url = get_post_meta($item->ID, '_wcp_source_url', true); ?>
    <?php if ($source_url) : ?>
        <a href="<?php echo esc_url($source_url); ?>" class="wcp-source-link" target="_blank" rel="noopener" title="<?php echo esc_attr($source_url); ?>">↗</a>
    <?php endif; ?>

    <?php $_desc_raw = wp_strip_all_tags($item->post_content); ?>
    <span class="wcp-item-description<?php echo empty($item->post_content) ? ' wcp-item-description-empty' : ''; ?>"
          data-item-id="<?php echo esc_attr($item->ID); ?>"
          data-raw="<?php echo esc_attr($_desc_raw); ?>"><?php echo esc_html($_desc_raw); ?></span>

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
                <a href="<?php echo esc_url(home_url('/?tag=' . urlencode(sanitize_title($tag)))); ?>"
                   class="wcp-pill wcp-pill-tag"><?php echo esc_html($tag); ?></a>
            <?php endforeach; ?>
        </span>
    <?php endif; ?>

    <?php if (!empty($_delegations)) :
        $_latest_dlg   = end($_delegations);
        $_dlg_status   = $_latest_dlg['status'] ?? 'pending';
        $_dlg_label    = $_delegation_labels[$_dlg_status] ?? $_dlg_status;
    ?>
        <span class="wcp-item-meta-pills">
            <span class="wcp-pill wcp-pill-delegation wcp-delegation-status-<?php echo esc_attr($_dlg_status); ?>">&#8644; <?php echo esc_html($_dlg_label); ?></span>
        </span>
    <?php endif; ?>

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
        <!-- Per-item AI panel -->
        <div class="wcp-item-ai-panel" data-item-id="<?php echo esc_attr($item->ID); ?>" style="display:none;">
            <div class="wcp-item-ai-chips">
                <button type="button" class="wcp-item-ai-chip" data-action="action_plan">Action plan</button>
                <button type="button" class="wcp-item-ai-chip" data-action="action_plan_from_context">Action plan from context</button>
                <button type="button" class="wcp-item-ai-chip" data-action="improve_phrasing">Improve phrasing</button>
                <button type="button" class="wcp-item-ai-chip" data-action="freeform">Freeform…</button>
                <button type="button" class="wcp-item-ai-chip" data-action="suggest_subtasks">Add subtasks</button>
                <button type="button" class="wcp-item-ai-chip" data-action="suggest_contexts">Auto-associate</button>
                <button type="button" class="wcp-item-ai-chip" data-action="to_goal">Convert to goal</button>
                <?php if ($_delegation_active && get_option('wcpd_enabled') === '1') : ?>
                <button type="button" class="wcp-item-ai-chip" data-action="delegate">Delegate</button>
                <?php endif; ?>
            </div>
            <div class="wcp-item-ai-result" style="display:none;"></div>
        </div>

        <?php // Delegation review: report, artifacts, clarification Q&A — read-only except answer forms ?>
        <?php foreach ($_delegations as $_dlg) :
            $_has_detail = !empty($_dlg['report']) || !empty($_dlg['artifact_ids']) || !empty($_dlg['clarifications']);
            if (!$_has_detail) continue;
            $_dlg_status = $_dlg['status'] ?? 'pending';
        ?>
        <div class="wcp-delegation-report" data-delegation-id="<?php echo esc_attr($_dlg['id']); ?>">
            <div class="wcp-delegation-report-header">
                <span class="wcp-pill wcp-pill-delegation wcp-delegation-status-<?php echo esc_attr($_dlg_status); ?>">&#8644; <?php echo esc_html($_delegation_labels[$_dlg_status] ?? $_dlg_status); ?></span>
                <?php if (!empty($_dlg['status_message'])) : ?>
                    <span class="wcp-delegation-status-message"><?php echo esc_html($_dlg['status_message']); ?></span>
                <?php endif; ?>
            </div>

            <?php foreach ((array) ($_dlg['clarifications'] ?? array()) as $_q) : ?>
                <?php if (empty($_q['answer'])) : ?>
                <div class="wcp-delegation-question">
                    <p class="wcp-delegation-question-text"><strong>Agent asks:</strong> <?php echo esc_html($_q['question']); ?></p>
                    <textarea class="wcp-delegation-answer-input" rows="2" placeholder="Your answer…"></textarea>
                    <button type="button" class="wcp-btn wcp-btn-primary wcp-btn-sm wcp-delegation-answer-send"
                            data-delegation-id="<?php echo esc_attr($_dlg['id']); ?>"
                            data-question-id="<?php echo esc_attr($_q['id']); ?>">Send answer</button>
                </div>
                <?php else : ?>
                <div class="wcp-delegation-qa">
                    <p><strong>Q:</strong> <?php echo esc_html($_q['question']); ?></p>
                    <p><strong>A:</strong> <?php echo esc_html($_q['answer']); ?></p>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if (!empty($_dlg['report'])) : ?>
                <div class="wcp-delegation-report-text"><?php echo nl2br(esc_html(wcp_theme_repair_escaped_text($_dlg['report']))); ?></div>
            <?php endif; ?>

            <?php if (!empty($_dlg['artifact_ids'])) : ?>
                <ul class="wcp-delegation-artifacts">
                    <?php foreach ((array) $_dlg['artifact_ids'] as $_aid) :
                        $_aurl = wp_get_attachment_url($_aid);
                        if (!$_aurl) continue;
                    ?>
                    <li><a href="<?php echo esc_url($_aurl); ?>" target="_blank" rel="noopener">&#128206; <?php echo esc_html(get_the_title($_aid) ?: basename($_aurl)); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <!-- Context picker -->
        <div class="wcp-item-context-panel" data-item-id="<?php echo esc_attr($item->ID); ?>" style="display:none;">
            <div class="wcp-item-context-tree"></div>
        </div>

        <!-- Tag editor -->
        <div class="wcp-item-tag-panel" data-item-id="<?php echo esc_attr($item->ID); ?>" style="display:none;">
            <div class="wcp-item-tag-pills">
                <?php foreach ($item_tags as $tag) : ?>
                <span class="wcp-item-tag-pill">
                    <?php echo esc_html($tag); ?>
                    <button type="button" class="wcp-item-tag-remove" data-tag="<?php echo esc_attr($tag); ?>" data-item-id="<?php echo esc_attr($item->ID); ?>">×</button>
                </span>
                <?php endforeach; ?>
            </div>
            <form class="wcp-item-tag-form" data-item-id="<?php echo esc_attr($item->ID); ?>">
                <input type="text" class="wcp-item-tag-input" placeholder="Add tag…" autocomplete="off">
                <button type="submit" class="wcp-btn wcp-btn-primary wcp-btn-sm">Add</button>
            </form>
        </div>

        <form class="wcp-subtask-add-form" data-item-id="<?php echo esc_attr($item->ID); ?>" style="display:none;">
            <input type="text" class="wcp-subtask-input" placeholder="Subtask title…" autocomplete="off">
            <button type="submit" class="wcp-btn wcp-btn-primary wcp-btn-sm">Add</button>
            <button type="button" class="wcp-subtask-add-cancel wcp-edit-link">cancel</button>
        </form>
    </div>
    <?php endif; ?>
</div>
