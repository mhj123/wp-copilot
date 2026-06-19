<?php
/**
 * Daily Dashboard — homepage
 */

get_header();

$today      = date('Y-m-d');
$today_ts   = strtotime('today midnight');
$tomorrow   = date('Y-m-d', strtotime('+1 day'));
$week_end   = date('Y-m-d', strtotime('+7 days'));

// ── Priority sort order ──────────────────────────────────────────
$prio_order = array('critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, '' => 4);

// ── Helper: prefetch taxonomy + meta for an array of posts ───────
function wcp_db_prefetch($posts) {
    $data = array();
    foreach ($posts as $p) {
        $prios    = wp_get_post_terms($p->ID, 'priority',    array('fields' => 'slugs'));
        $stats    = wp_get_post_terms($p->ID, 'task_status', array('fields' => 'slugs'));
        $ctxs     = wp_get_post_terms($p->ID, 'wcp_context', array('fields' => 'names'));
        $itypes   = wp_get_post_terms($p->ID, 'item_type',   array('fields' => 'names'));
        $tags     = wp_get_post_terms($p->ID, 'post_tag',    array('fields' => 'names'));
        $data[$p->ID] = array(
            'priority'   => !empty($prios)  ? $prios[0]  : '',
            'status'     => !empty($stats)  ? $stats[0]  : '',
            'contexts'   => !is_wp_error($ctxs)  ? $ctxs  : array(),
            'item_types' => !is_wp_error($itypes) ? $itypes : array(),
            'tags'       => !is_wp_error($tags)   ? $tags   : array(),
            'due_date'   => get_post_meta($p->ID, '_wcp_due_date', true),
        );
    }
    return $data;
}

// ── Helper: due date label ────────────────────────────────────────
function wcp_due_label($due_date) {
    if (!$due_date) return '';
    $diff = (int) round((strtotime($due_date) - strtotime('today midnight')) / 86400);
    if ($diff < 0)  return abs($diff) === 1 ? '1 day overdue' : abs($diff) . ' days overdue';
    if ($diff === 0) return 'Due today';
    return 'Due ' . date('D j M', strtotime($due_date));
}

// ── Not-done tax_query fragment ───────────────────────────────────
$not_done = array(
    'taxonomy' => 'task_status',
    'field'    => 'slug',
    'terms'    => 'done',
    'operator' => 'NOT IN',
);
$is_task = array(
    'taxonomy' => 'item_type',
    'field'    => 'slug',
    'terms'    => 'task',
);

// ────────────────────────────────────────────────────────────────
// Section 1: Overdue & due today
// ────────────────────────────────────────────────────────────────
$overdue_posts = get_posts(array(
    'post_type'      => 'post',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'tax_query'      => array('relation' => 'AND', $is_task, $not_done),
    'meta_query'     => array(
        'relation' => 'AND',
        array('key' => '_wcp_due_date', 'value' => '', 'compare' => '!='),
        array('key' => '_wcp_due_date', 'value' => $today, 'compare' => '<=', 'type' => 'DATE'),
    ),
));
$overdue_data = wcp_db_prefetch($overdue_posts);
usort($overdue_posts, function($a, $b) use ($prio_order, $overdue_data) {
    $oa = $prio_order[$overdue_data[$a->ID]['priority']] ?? 4;
    $ob = $prio_order[$overdue_data[$b->ID]['priority']] ?? 4;
    if ($oa !== $ob) return $oa - $ob;
    return strcmp($overdue_data[$a->ID]['due_date'], $overdue_data[$b->ID]['due_date']);
});
$overdue_ids = array_column($overdue_posts, 'ID');

// Split into strictly-overdue (due before today) and due-today.
$today_posts        = array();
$strict_overdue_posts = array();
foreach ($overdue_posts as $p) {
    if ($overdue_data[$p->ID]['due_date'] === $today) {
        $today_posts[] = $p;
    } else {
        $strict_overdue_posts[] = $p;
    }
}

// ────────────────────────────────────────────────────────────────
// Section 2: Critical / High — no (or future) due date
// ────────────────────────────────────────────────────────────────
$urgent_posts = get_posts(array(
    'post_type'      => 'post',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'tax_query'      => array(
        'relation' => 'AND',
        $is_task,
        $not_done,
        array('taxonomy' => 'priority', 'field' => 'slug', 'terms' => array('critical', 'high'), 'operator' => 'IN'),
    ),
));
// Exclude those already in overdue list
$urgent_posts = array_filter($urgent_posts, function($p) use ($overdue_ids) {
    return !in_array($p->ID, $overdue_ids);
});
$urgent_data = wcp_db_prefetch($urgent_posts);
usort($urgent_posts, function($a, $b) use ($prio_order, $urgent_data) {
    $oa = $prio_order[$urgent_data[$a->ID]['priority']] ?? 4;
    $ob = $prio_order[$urgent_data[$b->ID]['priority']] ?? 4;
    return $oa - $ob;
});

// ────────────────────────────────────────────────────────────────
// Section 3: Upcoming week (tomorrow → +7 days)
// ────────────────────────────────────────────────────────────────
$upcoming_posts = get_posts(array(
    'post_type'      => 'post',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'tax_query'      => array('relation' => 'AND', $is_task, $not_done),
    'meta_query'     => array(
        array('key' => '_wcp_due_date', 'value' => array($tomorrow, $week_end), 'compare' => 'BETWEEN', 'type' => 'DATE'),
    ),
    'orderby'  => 'meta_value',
    'meta_key' => '_wcp_due_date',
    'order'    => 'ASC',
));
$upcoming_data = wcp_db_prefetch($upcoming_posts);
// Group by day
$by_day = array();
foreach ($upcoming_posts as $p) {
    $d = $upcoming_data[$p->ID]['due_date'];
    $by_day[$d][] = $p;
}
ksort($by_day);

// ────────────────────────────────────────────────────────────────
// Section 3b: Pinned items (from any page)
// ────────────────────────────────────────────────────────────────
$pinned_posts = get_posts(array(
    'post_type'      => 'post',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'tax_query'      => array(
        'relation' => 'AND',
        array('taxonomy' => 'pinned', 'field' => 'slug', 'terms' => 'yes'),
        $not_done,
    ),
));
$pinned_data = wcp_db_prefetch($pinned_posts);
usort($pinned_posts, function($a, $b) use ($prio_order, $pinned_data) {
    $oa = $prio_order[$pinned_data[$a->ID]['priority']] ?? 4;
    $ob = $prio_order[$pinned_data[$b->ID]['priority']] ?? 4;
    return $oa - $ob;
});

// ────────────────────────────────────────────────────────────────
// Section 4: Scheduled pages (next 14 days)
// ────────────────────────────────────────────────────────────────
$sched_pages = get_posts(array(
    'post_type'      => 'page',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'meta_query'     => array(
        array(
            'key'     => '_wcp_schedule_next_run',
            'value'   => array($today_ts, $today_ts + 14 * DAY_IN_SECONDS),
            'compare' => 'BETWEEN',
            'type'    => 'NUMERIC',
        ),
    ),
    'orderby'  => 'meta_value_num',
    'meta_key' => '_wcp_schedule_next_run',
    'order'    => 'ASC',
));

// ────────────────────────────────────────────────────────────────
// Section 5: Recent activity
// ────────────────────────────────────────────────────────────────
$recent_items = get_posts(array(
    'post_type'      => 'post',
    'post_status'    => 'publish',
    'posts_per_page' => 15,
    'orderby'        => 'modified',
    'order'          => 'DESC',
    'date_query'     => array(array('after' => '7 days ago', 'column' => 'post_modified')),
));

// ────────────────────────────────────────────────────────────────
// Section 7: Calendar
// ────────────────────────────────────────────────────────────────
$week_mon     = strtotime('monday this week midnight');
if (date('N') == 1) $week_mon = strtotime('today midnight'); // today is Monday
$week_sun_end = $week_mon + 7 * DAY_IN_SECONDS;
$cal_events   = WCP_Calendar_Importer::instance()->get_events($week_mon, $week_sun_end);
// Group by day key YYYY-MM-DD
$cal_by_day = array();
for ($i = 0; $i < 7; $i++) {
    $cal_by_day[date('Y-m-d', $week_mon + $i * DAY_IN_SECONDS)] = array();
}
foreach ($cal_events as $ev) {
    $day_key = date('Y-m-d', $ev['start_ts']);
    if (isset($cal_by_day[$day_key])) {
        $cal_by_day[$day_key][] = $ev;
    }
}
?>

<div class="wcp-dashboard">

    <div class="wcp-dashboard-header">
        <h1 class="wcp-dashboard-date"><?php echo date('l, j F Y'); ?></h1>
        <div class="wcp-dash-tabs" role="tablist">
            <button type="button" class="wcp-dash-tab active" data-tab="dashboard" role="tab">Dashboard</button>
            <button type="button" class="wcp-dash-tab" data-tab="structure" role="tab">Structure</button>
        </div>
    </div>

    <!-- Structure tab panel -->
    <div id="wcp-dash-panel-structure" class="wcp-dash-panel" style="display:none;">
        <div id="wcp-structure-tree" class="wcp-structure-tree-container">
            <p class="wcp-tree-loading"><?php _e('Loading&hellip;', 'work-copilot-theme'); ?></p>
        </div>
    </div>

    <!-- Dashboard tab panel -->
    <div id="wcp-dash-panel-dashboard" class="wcp-dash-panel">

    <!-- ── Row 1: three task columns ─────────────────────────────── -->
    <div class="wcp-dash-row wcp-dash-three-col">

        <!-- LEFT COLUMN: Overdue + Critical -->
        <div class="wcp-dash-col">

            <!-- Overdue -->
            <div class="wcp-dash-card">
                <h2 class="wcp-dash-card-title">
                    <?php if (!empty($strict_overdue_posts)) : ?>
                        <span class="wcp-dash-badge wcp-dash-badge-red"><?php echo count($strict_overdue_posts); ?></span>
                    <?php endif; ?>
                    Overdue
                </h2>
                <?php if (empty($strict_overdue_posts)) : ?>
                    <p class="wcp-dash-empty">Nothing overdue.</p>
                <?php else : ?>
                    <ul class="wcp-dash-task-list">
                    <?php foreach ($strict_overdue_posts as $item) :
                        $d = $overdue_data[$item->ID];
                        $due_label = wcp_due_label($d['due_date']);
                    ?>
                        <li class="wcp-dash-task<?php echo $d['priority'] === 'critical' ? ' wcp-dash-critical' : ''; ?>">
                            <div class="wcp-dash-task-main">
                                <?php if ($d['priority']) : ?>
                                    <span class="wcp-dash-prio wcp-dash-prio-<?php echo esc_attr($d['priority']); ?>"><?php echo esc_html($d['priority']); ?></span>
                                <?php endif; ?>
                                <a href="<?php echo esc_url(wcp_theme_get_item_page_url($item->ID)); ?>" class="wcp-dash-task-title"><?php echo esc_html($item->post_title); ?></a>
                            </div>
                            <div class="wcp-dash-task-meta">
                                <?php if (!empty($d['contexts'])) : ?>
                                    <span class="wcp-dash-ctx"><?php echo esc_html($d['contexts'][0]); ?></span>
                                <?php endif; ?>
                                <span class="wcp-dash-due wcp-dash-overdue"><?php echo esc_html($due_label); ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- Critical / High -->
            <div class="wcp-dash-card">
                <h2 class="wcp-dash-card-title">
                    <?php if (!empty($urgent_posts)) : ?>
                        <span class="wcp-dash-badge"><?php echo count($urgent_posts); ?></span>
                    <?php endif; ?>
                    Critical &amp; high priority
                </h2>
                <?php if (empty($urgent_posts)) : ?>
                    <p class="wcp-dash-empty">No critical or high priority tasks.</p>
                <?php else : ?>
                    <ul class="wcp-dash-task-list">
                    <?php foreach ($urgent_posts as $item) :
                        $d = $urgent_data[$item->ID];
                    ?>
                        <li class="wcp-dash-task<?php echo $d['priority'] === 'critical' ? ' wcp-dash-critical' : ''; ?>">
                            <div class="wcp-dash-task-main">
                                <span class="wcp-dash-prio wcp-dash-prio-<?php echo esc_attr($d['priority']); ?>"><?php echo esc_html($d['priority']); ?></span>
                                <a href="<?php echo esc_url(wcp_theme_get_item_page_url($item->ID)); ?>" class="wcp-dash-task-title"><?php echo esc_html($item->post_title); ?></a>
                            </div>
                            <?php if (!empty($d['contexts'])) : ?>
                            <div class="wcp-dash-task-meta">
                                <span class="wcp-dash-ctx"><?php echo esc_html($d['contexts'][0]); ?></span>
                                <?php if ($d['status'] && $d['status'] !== 'to-do') : ?>
                                    <span class="wcp-dash-status"><?php echo esc_html($d['status']); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

        </div><!-- left column -->

        <!-- CENTER COLUMN: Today + This week -->
        <div class="wcp-dash-col">

            <!-- Today -->
            <div class="wcp-dash-card">
                <h2 class="wcp-dash-card-title">
                    <?php if (!empty($today_posts)) : ?>
                        <span class="wcp-dash-badge"><?php echo count($today_posts); ?></span>
                    <?php endif; ?>
                    Today
                </h2>
                <?php if (empty($today_posts)) : ?>
                    <p class="wcp-dash-empty">Nothing due today.</p>
                <?php else : ?>
                    <ul class="wcp-dash-task-list">
                    <?php foreach ($today_posts as $item) :
                        $d = $overdue_data[$item->ID];
                    ?>
                        <li class="wcp-dash-task<?php echo $d['priority'] === 'critical' ? ' wcp-dash-critical' : ''; ?>">
                            <div class="wcp-dash-task-main">
                                <?php if ($d['priority']) : ?>
                                    <span class="wcp-dash-prio wcp-dash-prio-<?php echo esc_attr($d['priority']); ?>"><?php echo esc_html($d['priority']); ?></span>
                                <?php endif; ?>
                                <a href="<?php echo esc_url(wcp_theme_get_item_page_url($item->ID)); ?>" class="wcp-dash-task-title"><?php echo esc_html($item->post_title); ?></a>
                            </div>
                            <?php if (!empty($d['contexts'])) : ?>
                            <div class="wcp-dash-task-meta">
                                <span class="wcp-dash-ctx"><?php echo esc_html($d['contexts'][0]); ?></span>
                            </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- Upcoming week -->
            <div class="wcp-dash-card">
                <h2 class="wcp-dash-card-title">This week</h2>
                <?php if (empty($by_day) || array_sum(array_map('count', $by_day)) === 0) : ?>
                    <p class="wcp-dash-empty">No tasks due this week.</p>
                <?php else : ?>
                    <?php foreach ($by_day as $date => $day_tasks) :
                        if (empty($day_tasks)) continue;
                        $day_label = date('D j M', strtotime($date));
                    ?>
                    <div class="wcp-dash-day-group">
                        <h3 class="wcp-dash-day-label"><?php echo esc_html($day_label); ?></h3>
                        <ul class="wcp-dash-task-list">
                        <?php foreach ($day_tasks as $item) :
                            $d = $upcoming_data[$item->ID];
                        ?>
                            <li class="wcp-dash-task<?php echo $d['priority'] === 'critical' ? ' wcp-dash-critical' : ''; ?>">
                                <div class="wcp-dash-task-main">
                                    <?php if ($d['priority']) : ?>
                                        <span class="wcp-dash-prio wcp-dash-prio-<?php echo esc_attr($d['priority']); ?>"><?php echo esc_html($d['priority']); ?></span>
                                    <?php endif; ?>
                                    <a href="<?php echo esc_url(wcp_theme_get_item_page_url($item->ID)); ?>" class="wcp-dash-task-title"><?php echo esc_html($item->post_title); ?></a>
                                </div>
                                <?php if (!empty($d['contexts'])) : ?>
                                <div class="wcp-dash-task-meta">
                                    <span class="wcp-dash-ctx"><?php echo esc_html($d['contexts'][0]); ?></span>
                                </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div><!-- center column -->

        <!-- RIGHT COLUMN: Pinned (from any page) -->
        <div class="wcp-dash-col">
            <div class="wcp-dash-card">
                <h2 class="wcp-dash-card-title">
                    <?php if (!empty($pinned_posts)) : ?>
                        <span class="wcp-dash-badge"><?php echo count($pinned_posts); ?></span>
                    <?php endif; ?>
                    Pinned
                </h2>
                <?php if (empty($pinned_posts)) : ?>
                    <p class="wcp-dash-empty">No pinned items.</p>
                <?php else : ?>
                    <ul class="wcp-dash-task-list">
                    <?php foreach ($pinned_posts as $item) :
                        $d         = $pinned_data[$item->ID];
                        $pg_id     = wcp_theme_get_item_page_id($item->ID);
                        $pg_label  = $pg_id ? get_the_title($pg_id) : (!empty($d['contexts']) ? $d['contexts'][0] : '');
                        $due_label = wcp_due_label($d['due_date']);
                    ?>
                        <li class="wcp-dash-task wcp-dash-pinned<?php echo $d['priority'] === 'critical' ? ' wcp-dash-critical' : ''; ?>">
                            <?php if ($pg_label) : ?>
                                <span class="wcp-dash-ctx-inset"><?php echo esc_html($pg_label); ?></span>
                            <?php endif; ?>
                            <div class="wcp-dash-task-main">
                                <?php if ($d['priority']) : ?>
                                    <span class="wcp-dash-prio wcp-dash-prio-<?php echo esc_attr($d['priority']); ?>"><?php echo esc_html($d['priority']); ?></span>
                                <?php endif; ?>
                                <a href="<?php echo esc_url(wcp_theme_get_item_page_url($item->ID)); ?>" class="wcp-dash-task-title"><?php echo esc_html($item->post_title); ?></a>
                            </div>
                            <?php if ($due_label) : ?>
                            <div class="wcp-dash-task-meta">
                                <span class="wcp-dash-due<?php echo (strtotime($d['due_date']) < strtotime('today midnight')) ? ' wcp-dash-overdue' : ''; ?>"><?php echo esc_html($due_label); ?></span>
                            </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div><!-- right column -->

    </div><!-- .wcp-dash-three-col -->

    <!-- ── Calendar week ─────────────────────────────────────────── -->
    <div class="wcp-dash-card wcp-dash-calendar-card">
        <div class="wcp-dash-card-header">
            <h2 class="wcp-dash-card-title">Week of <?php echo date('j M', $week_mon); ?></h2>
            <div class="wcp-dash-cal-upload">
                <label class="wcp-edit-link wcp-cal-upload-label" title="Import Outlook .ics file">
                    ↑ import .ics
                    <input type="file" id="wcp-cal-file-input" accept=".ics,text/calendar" style="display:none;">
                </label>
                <span id="wcp-cal-upload-status" style="font-size:12px;color:#888;"></span>
            </div>
        </div>
        <?php if (empty($cal_events)) : ?>
            <p class="wcp-dash-empty">No calendar events this week. Upload an Outlook .ics file to see them here.</p>
        <?php else : ?>
            <div class="wcp-dash-cal-grid">
                <?php foreach ($cal_by_day as $date => $events) :
                    $is_today = ($date === $today);
                    $day_name = date('D', strtotime($date));
                    $day_num  = date('j', strtotime($date));
                ?>
                <div class="wcp-dash-cal-col<?php echo $is_today ? ' wcp-dash-cal-today' : ''; ?>">
                    <div class="wcp-dash-cal-col-header">
                        <span class="wcp-dash-cal-day-name"><?php echo $day_name; ?></span>
                        <span class="wcp-dash-cal-day-num"><?php echo $day_num; ?></span>
                    </div>
                    <?php
                    $all_day_evs = array_filter($events, fn($e) => $e['all_day']);
                    $timed_evs   = array_filter($events, fn($e) => !$e['all_day']);
                    usort($timed_evs, fn($a,$b) => $a['start_ts'] - $b['start_ts']);
                    ?>
                    <?php foreach ($all_day_evs as $ev) : ?>
                        <div class="wcp-dash-cal-allday"><?php echo esc_html($ev['title']); ?></div>
                    <?php endforeach; ?>
                    <?php foreach ($timed_evs as $ev) : ?>
                        <div class="wcp-dash-cal-event">
                            <span class="wcp-dash-cal-time"><?php echo date('H:i', $ev['start_ts']); ?></span>
                            <span class="wcp-dash-cal-evtitle"><?php echo esc_html($ev['title']); ?></span>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($events)) : ?>
                        <div class="wcp-dash-cal-empty">—</div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ── Scheduled pages lookahead ─────────────────────────────── -->
    <?php if (!empty($sched_pages)) : ?>
    <div class="wcp-dash-card">
        <h2 class="wcp-dash-card-title">Upcoming scheduled pages</h2>
        <ul class="wcp-dash-sched-list">
        <?php foreach ($sched_pages as $page) :
            $next_run = (int) get_post_meta($page->ID, '_wcp_schedule_next_run', true);
            $sched    = json_decode(get_post_meta($page->ID, '_wcp_page_schedule', true), true) ?: array();
            $freq     = ucfirst($sched['frequency'] ?? '');
            $when     = date('D j M', $next_run);
        ?>
            <li class="wcp-dash-sched-item">
                <span class="wcp-dash-sched-freq"><?php echo esc_html($freq); ?></span>
                <a href="<?php echo esc_url(get_permalink($page->ID)); ?>" class="wcp-dash-task-title"><?php echo esc_html($page->post_title); ?></a>
                <span class="wcp-dash-due"><?php echo esc_html($when); ?></span>
            </li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ── Recent activity summary ───────────────────────────────── -->
    <?php
    $cached_summary = get_transient('wcp_activity_summary');
    $summary_text   = $cached_summary['summary'] ?? '';
    $summary_count  = $cached_summary['post_count'] ?? 0;
    $summary_time   = $cached_summary['generated_at'] ?? '';
    $summary_age    = $summary_time ? human_time_diff(strtotime($summary_time), current_time('timestamp')) . ' ago' : '';
    ?>
    <div class="wcp-dash-card" id="wcp-dash-activity-card">
        <div class="wcp-dash-card-header">
            <h2 class="wcp-dash-card-title">
                This week
                <?php if ($summary_age) : ?>
                    <span class="wcp-dash-summary-age"><?php echo esc_html($summary_age); ?></span>
                <?php endif; ?>
            </h2>
            <button type="button" id="wcp-dash-summarise-btn" class="wcp-edit-link">
                <?php echo $summary_text ? 'Refresh summary' : 'Generate summary'; ?>
            </button>
        </div>
        <div id="wcp-dash-activity-summary">
            <?php if ($summary_text) : ?>
                <p class="wcp-dash-summary-text"><?php echo nl2br(esc_html($summary_text)); ?></p>
                <p class="wcp-dash-summary-meta"><?php echo esc_html($summary_count); ?> items created in the last 7 days</p>
            <?php else : ?>
                <p class="wcp-dash-empty">Click "Generate summary" to get an AI overview of this week's activity.</p>
            <?php endif; ?>
        </div>
    </div>

    </div><!-- #wcp-dash-panel-dashboard -->

</div><!-- .wcp-dashboard -->

<?php
get_footer();
