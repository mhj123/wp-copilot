<?php
/**
 * Wiretap admin dashboard (§8): Inbox / Trade plans / KOLs / Digest /
 * Emerging / Runs & budget. Server-rendered, actions via REST + fetch().
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPW_Dashboard {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_footer', array($this, 'footer_js'));
    }

    public function menu() {
        add_menu_page(__('Wiretap', 'wcp-wiretap'), __('Wiretap', 'wcp-wiretap'), 'manage_options', 'wcp-wiretap', array($this, 'render_inbox'), 'dashicons-rss', 3.2);
        add_submenu_page('wcp-wiretap', __('Inbox', 'wcp-wiretap'), __('Inbox', 'wcp-wiretap'), 'manage_options', 'wcp-wiretap', array($this, 'render_inbox'));
        add_submenu_page('wcp-wiretap', __('Trade Plans', 'wcp-wiretap'), __('Trade Plans', 'wcp-wiretap'), 'manage_options', 'wcp-wiretap-plans', array($this, 'render_plans'));
        add_submenu_page('wcp-wiretap', __('KOLs', 'wcp-wiretap'), __('KOLs', 'wcp-wiretap'), 'manage_options', 'wcp-wiretap-kols', array($this, 'render_kols'));
        add_submenu_page('wcp-wiretap', __('Digest', 'wcp-wiretap'), __('Digest', 'wcp-wiretap'), 'manage_options', 'wcp-wiretap-digest', array($this, 'render_digest'));
        add_submenu_page('wcp-wiretap', __('Emerging', 'wcp-wiretap'), __('Emerging', 'wcp-wiretap'), 'manage_options', 'wcp-wiretap-emerging', array($this, 'render_emerging'));
        add_submenu_page('wcp-wiretap', __('Runs & Budget', 'wcp-wiretap'), __('Runs & Budget', 'wcp-wiretap'), 'manage_options', 'wcp-wiretap-runs', array($this, 'render_runs'));
    }

    private function is_wiretap_screen() {
        return isset($_GET['page']) && strpos((string) $_GET['page'], 'wcp-wiretap') === 0;
    }

    // ------------------------------------------------------------------ Inbox

    public function render_inbox() {
        $last_view = get_option('wcpw_inbox_last_view', '1970-01-01 00:00:00');
        update_option('wcpw_inbox_last_view', current_time('mysql', true), false);

        $pending = WCPW_Recommendation_Repo::pending(50);
        $highlight = isset($_GET['rec']) ? (int) $_GET['rec'] : 0;

        // Alerts strip: high-confidence new calls + triggered plans since last view (§8).
        $alerts = array();
        foreach ($pending as $post) {
            if ($post->post_date_gmt > $last_view && get_post_meta($post->ID, '_wcpw_alerted', true)) {
                $meta = WCPW_Recommendation_Repo::meta($post->ID);
                $alerts[] = sprintf('$%s %s by @%s', $meta['ticker'], $meta['direction'], $meta['kol'] ? $meta['kol']['handle'] : '?');
            }
        }
        foreach (WCPW_Trade_Plan::list_by_status('wcp_triggered', 10) as $plan_post) {
            $triggered_at = get_post_meta($plan_post->ID, '_wcpw_triggered_at', true);
            if ($triggered_at > $last_view) {
                $alerts[] = sprintf('Plan triggered: %s', esc_html($plan_post->post_title));
            }
        }
        ?>
        <div class="wrap">
            <h1><?php _e('Wiretap — Inbox', 'wcp-wiretap'); ?></h1>
            <?php if ($alerts) : ?>
                <div class="notice notice-info"><p><strong><?php _e('Since your last visit:', 'wcp-wiretap'); ?></strong>
                    <?php echo esc_html(implode(' · ', array_slice($alerts, 0, 6))); ?></p></div>
            <?php endif; ?>
            <?php $this->budget_strip(); ?>

            <?php if (!$pending) : ?>
                <p><?php _e('No pending recommendations. The analyzer will file new calls here.', 'wcp-wiretap'); ?></p>
            <?php endif; ?>

            <?php foreach ($pending as $post) :
                $rec = WCPW_Recommendation_Repo::meta($post->ID);
                $kol = $rec['kol'];
                $earliness = $rec['earliness_at_call'];
                $price_now = WCPW_Price_Source::get_price($rec['ticker']);
                $since_pct = ($price_now !== null && $rec['price_at_call'])
                    ? ($price_now / (float) $rec['price_at_call'] - 1) * 100 : null;
                $tweet_link = $kol ? 'https://x.com/' . $kol['handle'] . '/status/' . $rec['source_tweet_id'] : '';
                ?>
                <div class="wcpw-card <?php echo $highlight === (int) $post->ID ? 'wcpw-highlight' : ''; ?>" id="rec-<?php echo (int) $post->ID; ?>">
                    <div class="wcpw-card-head">
                        <strong>$<?php echo esc_html($rec['ticker']); ?></strong>
                        <span class="wcpw-dir wcpw-dir-<?php echo esc_attr($rec['direction']); ?>"><?php echo esc_html(strtoupper($rec['direction'])); ?></span>
                        <?php if ($kol) : ?>
                            <a href="https://x.com/<?php echo esc_attr($kol['handle']); ?>" target="_blank" rel="noopener">@<?php echo esc_html($kol['handle']); ?></a>
                            <span class="wcpw-trust" title="Trust score">★<?php echo (int) $kol['trust_score']; ?></span>
                        <?php endif; ?>
                        <span title="Model confidence">conf <?php echo esc_html(number_format($rec['confidence'] * 100, 0)); ?>%</span>
                        <?php if ($since_pct !== null) : ?>
                            <span class="wcpw-pct <?php echo $since_pct >= 0 ? 'up' : 'down'; ?>" title="Since call ($<?php echo esc_attr($rec['price_at_call']); ?> → $<?php echo esc_attr($price_now); ?>)">
                                <?php echo esc_html(sprintf('%+.1f%%', $since_pct)); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($earliness) : ?>
                            <span class="wcpw-band wcpw-band-<?php echo esc_attr($earliness['band']); ?>" title="<?php echo esc_attr(WCPW_Earliness::failure_modes_tooltip()); ?>">
                                <?php echo esc_html(WCPW_Earliness::band_label($earliness['band'])); ?>
                            </span>
                        <?php endif; ?>
                        <span class="wcpw-date"><?php echo esc_html(mysql2date('j M H:i', $post->post_date)); ?></span>
                    </div>

                    <?php if ($rec['rationale_excerpt']) : ?>
                        <p class="wcpw-rationale"><?php echo esc_html($rec['rationale_excerpt']); ?>
                            <?php if ($tweet_link) : ?> <a href="<?php echo esc_url($tweet_link); ?>" target="_blank" rel="noopener">[thread ↗]</a><?php endif; ?>
                        </p>
                    <?php endif; ?>

                    <?php // "Why am I seeing this" — newness + earliness facts, no black boxes. ?>
                    <p class="wcpw-why">
                        <?php
                        $why = array();
                        $reasons = array('first_call_by_kol' => 'first call by this KOL on this ticker', 'direction_change' => 'direction flip vs prior call', 'window_elapsed' => 'previous same-direction call aged out');
                        $why[] = isset($reasons[$rec['newness_reason']]) ? 'New call: ' . $reasons[$rec['newness_reason']] : 'New call';
                        if ($rec['reinforced_count']) {
                            $why[] = 'reinforced ×' . $rec['reinforced_count'];
                        }
                        if ($rec['low_confidence']) {
                            $why[] = '⚠ low confidence — flagged for review, not dropped';
                        }
                        if ($rec['ticker_unverified']) {
                            $why[] = '⚠ ticker unverified — confirm via Edit';
                        }
                        echo esc_html(implode(' · ', $why));
                        ?>
                        <?php if ($earliness) : ?><br><em><?php echo esc_html($earliness['facts']); ?></em><?php endif; ?>
                    </p>

                    <?php
                    // Prior calls by the same KOL — queried live (§F1).
                    if ($kol) {
                        $priors = WCPW_Recommendation_Repo::prior_calls($rec['kol_id'], $rec['ticker'], 4);
                        $prior_bits = array();
                        foreach ($priors as $prior) {
                            if ((int) $prior->ID === (int) $post->ID) {
                                continue;
                            }
                            $prior_bits[] = mysql2date('j M y', $prior->post_date) . ' ' . get_post_meta($prior->ID, '_wcpw_direction', true);
                        }
                        if ($prior_bits) {
                            echo '<p class="wcpw-priors">Prior calls: ' . esc_html(implode(' · ', $prior_bits)) . '</p>';
                        }
                    }

                    // Check-in memos.
                    foreach (array_slice($rec['checkins'], -1) as $memo) {
                        printf(
                            '<p class="wcpw-memo">🔎 <strong>%s</strong> (%s) — %s <em>Next: %s</em></p>',
                            esc_html($memo['thesis_status']), esc_html($memo['at']),
                            esc_html($memo['rationale']), esc_html($memo['suggested_next_look'])
                        );
                    }
                    ?>

                    <div class="wcpw-actions">
                        <button class="button button-primary" data-wcpw="accept-rec" data-rec="<?php echo (int) $post->ID; ?>"><?php _e('Accept', 'wcp-wiretap'); ?></button>
                        <select class="wcpw-dismiss-reason" data-rec="<?php echo (int) $post->ID; ?>">
                            <option value=""><?php _e('Dismiss…', 'wcp-wiretap'); ?></option>
                            <option value="noise"><?php _e('Noise', 'wcp-wiretap'); ?></option>
                            <option value="too_late"><?php _e('Too late', 'wcp-wiretap'); ?></option>
                            <option value="dont_trust"><?php _e("Don't trust the call", 'wcp-wiretap'); ?></option>
                            <option value="other"><?php _e('Other', 'wcp-wiretap'); ?></option>
                        </select>
                        <button class="button" data-wcpw="edit-rec" data-rec="<?php echo (int) $post->ID; ?>"
                                data-ticker="<?php echo esc_attr($rec['ticker']); ?>"
                                data-direction="<?php echo esc_attr($rec['direction']); ?>"
                                data-confidence="<?php echo esc_attr($rec['confidence']); ?>"><?php _e('Edit', 'wcp-wiretap'); ?></button>
                        <button class="button" data-wcpw="create-plan" data-rec="<?php echo (int) $post->ID; ?>"><?php _e('Create plan', 'wcp-wiretap'); ?></button>
                        <button class="button" data-wcpw="checkin" data-object="<?php echo (int) $post->ID; ?>"><?php _e('Check in', 'wcp-wiretap'); ?></button>
                        <button class="button-link" data-wcpw="mute-ticker" data-ticker="<?php echo esc_attr($rec['ticker']); ?>" title="<?php esc_attr_e('Mute alerts for this ticker for 7 days (ingestion continues)', 'wcp-wiretap'); ?>">🔇</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        $this->styles();
    }

    // ------------------------------------------------------------ Trade plans

    public function render_plans() {
        $columns = array(
            'wcp_proposed'  => __('Proposed', 'wcp-wiretap'),
            'wcp_armed'     => __('Armed (watched every 15 min)', 'wcp-wiretap'),
            'wcp_triggered' => __('Triggered 🎯', 'wcp-wiretap'),
        );
        $done_statuses = array('wcp_invalidated', 'wcp_closed', 'wcp_expired', 'wcp_cancelled');
        ?>
        <div class="wrap">
            <h1><?php _e('Wiretap — Trade Plans', 'wcp-wiretap'); ?></h1>
            <p class="description"><?php _e('Triggered means "notify", never "execute". There is no order routing anywhere in this plugin. Equity prices are EOD/delayed (Stooq).', 'wcp-wiretap'); ?></p>
            <div class="wcpw-kanban">
                <?php foreach ($columns as $status => $label) : ?>
                    <div class="wcpw-col">
                        <h2><?php echo esc_html($label); ?></h2>
                        <?php foreach (WCPW_Trade_Plan::list_by_status($status, 30) as $post) :
                            $plan = WCPW_Trade_Plan::meta($post->ID);
                            $price_now = WCPW_Price_Source::get_price($plan['ticker']);
                            ?>
                            <div class="wcpw-card <?php echo $status === 'wcp_triggered' ? 'wcpw-highlight' : ''; ?>" id="plan-<?php echo (int) $post->ID; ?>">
                                <div class="wcpw-card-head">
                                    <strong>$<?php echo esc_html($plan['ticker']); ?></strong>
                                    <span class="wcpw-dir wcpw-dir-<?php echo esc_attr($plan['direction']); ?>"><?php echo esc_html(strtoupper($plan['direction'])); ?></span>
                                    <?php if ($price_now !== null) : ?><span>now $<?php echo esc_html($price_now); ?></span><?php endif; ?>
                                </div>
                                <p class="wcpw-rationale"><?php echo esc_html(mb_substr($plan['thesis'], 0, 220)); ?></p>
                                <p class="wcpw-why">
                                    <?php
                                    echo 'Entry: ' . ($plan['entry_low'] !== null ? esc_html($plan['entry_low'] . '–' . $plan['entry_high']) : '<em>unspecified — set levels to arm</em>');
                                    echo ' · Invalidation: ' . ($plan['invalidation'] ? esc_html($plan['invalidation']) : '—');
                                    echo $plan['targets'] ? ' · Targets: ' . esc_html(implode(', ', array_map('strval', $plan['targets']))) : '';
                                    echo $plan['timeframe'] ? ' · TF: ' . esc_html($plan['timeframe']) : '';
                                    echo ' · Expires: ' . esc_html(mysql2date('j M', $plan['expires_at']));
                                    ?>
                                </p>
                                <div class="wcpw-actions">
                                    <?php if ($status === 'wcp_proposed') : ?>
                                        <input type="text" size="6" placeholder="low" class="wcpw-lvl-low" value="<?php echo esc_attr($plan['entry_low']); ?>">
                                        <input type="text" size="6" placeholder="high" class="wcpw-lvl-high" value="<?php echo esc_attr($plan['entry_high']); ?>">
                                        <input type="text" size="8" placeholder="invalidation" class="wcpw-lvl-inv" value="<?php echo esc_attr($plan['invalidation']); ?>">
                                        <button class="button button-primary" data-wcpw="arm-plan" data-plan="<?php echo (int) $post->ID; ?>"><?php _e('Approve & arm', 'wcp-wiretap'); ?></button>
                                        <button class="button" data-wcpw="cancel-plan" data-plan="<?php echo (int) $post->ID; ?>"><?php _e('Cancel', 'wcp-wiretap'); ?></button>
                                    <?php elseif ($status === 'wcp_armed') : ?>
                                        <button class="button" data-wcpw="cancel-plan" data-plan="<?php echo (int) $post->ID; ?>"><?php _e('Cancel', 'wcp-wiretap'); ?></button>
                                        <button class="button" data-wcpw="close-plan" data-plan="<?php echo (int) $post->ID; ?>"><?php _e('Close', 'wcp-wiretap'); ?></button>
                                    <?php else : ?>
                                        <button class="button button-primary" data-wcpw="close-plan" data-plan="<?php echo (int) $post->ID; ?>"><?php _e('Close', 'wcp-wiretap'); ?></button>
                                    <?php endif; ?>
                                    <button class="button" data-wcpw="checkin" data-object="<?php echo (int) $post->ID; ?>"><?php _e('Check in', 'wcp-wiretap'); ?></button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <h2><?php _e('Recently finished', 'wcp-wiretap'); ?></h2>
            <table class="widefat striped" style="max-width:900px">
                <thead><tr><th><?php _e('Plan', 'wcp-wiretap'); ?></th><th><?php _e('Status', 'wcp-wiretap'); ?></th><th><?php _e('Created', 'wcp-wiretap'); ?></th></tr></thead>
                <tbody>
                <?php foreach (WCPW_Trade_Plan::list_by_status($done_statuses, 15) as $post) : ?>
                    <tr>
                        <td><?php echo esc_html($post->post_title); ?></td>
                        <td><?php echo esc_html(str_replace('wcp_', '', $post->post_status)); ?></td>
                        <td><?php echo esc_html(mysql2date('j M Y', $post->post_date)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        $this->styles();
    }

    // ------------------------------------------------------------------ KOLs

    public function render_kols() {
        $per_read = (float) wcpw_get_setting('per_read_price_usd');
        $earliest_cap = (int) wcpw_get_setting('earliest_search_max_results');
        ?>
        <div class="wrap">
            <h1><?php _e('Wiretap — KOLs', 'wcp-wiretap'); ?></h1>
            <?php $this->budget_strip(); ?>

            <div class="wcpw-card">
                <h2><?php _e('Add / import', 'wcp-wiretap'); ?></h2>
                <p>
                    <input type="text" id="wcpw-new-handle" placeholder="@handle">
                    <button class="button button-primary" data-wcpw="add-kol"><?php _e('Add KOL', 'wcp-wiretap'); ?></button>
                    &nbsp;&nbsp;
                    <input type="text" id="wcpw-list-id" placeholder="<?php esc_attr_e('X List ID', 'wcp-wiretap'); ?>">
                    <button class="button" data-wcpw="import-list"><?php _e('Import list', 'wcp-wiretap'); ?></button>
                </p>
                <p>
                    <input type="text" id="wcpw-earliest-ticker" placeholder="$TICKER" size="8">
                    <input type="date" id="wcpw-earliest-start">
                    <input type="date" id="wcpw-earliest-end">
                    <button class="button" data-wcpw="discover-earliest" data-cost="<?php echo esc_attr(number_format($earliest_cap * $per_read, 2)); ?>">
                        <?php _e('Find earliest callers', 'wcp-wiretap'); ?>
                    </button>
                    <span class="description"><?php printf(esc_html__('~$%s per query (archive search; falls back to your corpus if unavailable)', 'wcp-wiretap'), esc_html(number_format($earliest_cap * $per_read, 2))); ?></span>
                </p>
                <div id="wcpw-earliest-results"></div>
            </div>

            <h2><?php _e('Suggestion queue', 'wcp-wiretap'); ?></h2>
            <table class="widefat striped">
                <thead><tr><th><?php _e('Handle', 'wcp-wiretap'); ?></th><th><?php _e('Source', 'wcp-wiretap'); ?></th><th><?php _e('Reason', 'wcp-wiretap'); ?></th><th></th></tr></thead>
                <tbody>
                <?php $suggested = WCPW_KOLs::list_by_status('suggested'); ?>
                <?php if (!$suggested) : ?>
                    <tr><td colspan="4"><?php _e('No suggestions yet — the nightly corpus scan and graph expansion feed this queue.', 'wcp-wiretap'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($suggested as $kol) : $meta = WCPW_KOLs::meta($kol->ID); ?>
                    <tr>
                        <td><a href="https://x.com/<?php echo esc_attr($meta['handle']); ?>" target="_blank" rel="noopener">@<?php echo esc_html($meta['handle']); ?></a></td>
                        <td><?php echo esc_html($meta['discovery_source']); ?></td>
                        <td><?php echo esc_html($meta['discovery_reason']); ?></td>
                        <td>
                            <button class="button button-primary" data-wcpw="promote-kol" data-kol="<?php echo (int) $kol->ID; ?>"><?php _e('Track', 'wcp-wiretap'); ?></button>
                            <button class="button" data-wcpw="dismiss-kol" data-kol="<?php echo (int) $kol->ID; ?>"><?php _e('Dismiss', 'wcp-wiretap'); ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2><?php _e('Tracked', 'wcp-wiretap'); ?></h2>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php _e('Handle', 'wcp-wiretap'); ?></th><th><?php _e('Trust', 'wcp-wiretap'); ?></th>
                    <th><?php _e('Status', 'wcp-wiretap'); ?></th><th><?php _e('Last fetched', 'wcp-wiretap'); ?></th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach (array_merge(WCPW_KOLs::list_by_status('active'), WCPW_KOLs::list_by_status('paused')) as $kol) :
                    $meta = WCPW_KOLs::meta($kol->ID); ?>
                    <tr>
                        <td><a href="https://x.com/<?php echo esc_attr($meta['handle']); ?>" target="_blank" rel="noopener">@<?php echo esc_html($meta['handle']); ?></a></td>
                        <td>
                            <select data-wcpw="set-trust" data-kol="<?php echo (int) $kol->ID; ?>">
                                <?php for ($i = 1; $i <= 5; $i++) : ?>
                                    <option value="<?php echo $i; ?>" <?php selected($meta['trust_score'], $i); ?>><?php echo str_repeat('★', $i); ?></option>
                                <?php endfor; ?>
                            </select>
                            <?php if ($meta['trust_score'] >= (int) wcpw_get_setting('trust_alert_min')) : ?>
                                <span class="wcpw-band wcpw-band-on_time"><?php _e('tier-1', 'wcp-wiretap'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($meta['tracking_status']); ?><?php echo $meta['pause_reason'] ? ' — ' . esc_html($meta['pause_reason']) : ''; ?></td>
                        <td><?php echo $meta['last_fetched_at'] ? esc_html(mysql2date('j M H:i', $meta['last_fetched_at'])) : '—'; ?></td>
                        <td>
                            <?php if ($meta['tracking_status'] === 'active') : ?>
                                <button class="button" data-wcpw="pause-kol" data-kol="<?php echo (int) $kol->ID; ?>"><?php _e('Pause', 'wcp-wiretap'); ?></button>
                            <?php else : ?>
                                <button class="button" data-wcpw="promote-kol" data-kol="<?php echo (int) $kol->ID; ?>"><?php _e('Resume', 'wcp-wiretap'); ?></button>
                            <?php endif; ?>
                            <?php if ($meta['trust_score'] >= (int) wcpw_get_setting('trust_alert_min')) : ?>
                                <button class="button" data-wcpw="discover-graph" data-kol="<?php echo (int) $kol->ID; ?>"
                                        data-cost="<?php echo esc_attr(number_format(1000 * $per_read, 2)); ?>"
                                        title="<?php esc_attr_e('Fetch following list (1 page) and triangulate against other tier-1 KOLs', 'wcp-wiretap'); ?>">
                                    <?php _e('Expand graph', 'wcp-wiretap'); ?>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        $this->styles();
    }

    // ---------------------------------------------------------------- Digest

    public function render_digest() {
        $latest = WCPW_Digest::latest();
        ?>
        <div class="wrap">
            <h1><?php _e('Wiretap — Digest', 'wcp-wiretap'); ?></h1>
            <p>
                <?php _e('Window (hours):', 'wcp-wiretap'); ?>
                <input type="number" id="wcpw-digest-hours" value="24" min="1" max="168" style="width:70px">
                <button class="button button-primary" data-wcpw="generate-digest"><?php _e('Generate now', 'wcp-wiretap'); ?></button>
                <span class="description"><?php _e('Saved as a draft post tagged wcp-wiretap-digest — never auto-published.', 'wcp-wiretap'); ?></span>
            </p>
            <?php if ($latest) : ?>
                <div class="wcpw-card">
                    <h2><?php echo esc_html($latest->post_title); ?>
                        <a class="button" style="margin-left:10px" href="<?php echo esc_url(get_edit_post_link($latest->ID)); ?>"><?php _e('Open draft', 'wcp-wiretap'); ?></a>
                    </h2>
                    <div class="wcpw-digest-body"><?php echo wp_kses_post(wpautop($latest->post_content)); ?></div>
                </div>
            <?php else : ?>
                <p><?php _e('No digest yet — generate one or wait for the 07:00 run.', 'wcp-wiretap'); ?></p>
            <?php endif; ?>
        </div>
        <?php
        $this->styles();
    }

    // -------------------------------------------------------------- Emerging

    public function render_emerging() {
        $emerging = get_option('wcpw_emerging', array());
        $entries = isset($emerging['entries']) ? $emerging['entries'] : array();
        ?>
        <div class="wrap">
            <h1><?php _e('Wiretap — Emerging', 'wcp-wiretap'); ?></h1>
            <p class="description" title="<?php echo esc_attr(WCPW_Earliness::failure_modes_tooltip()); ?>">
                <?php _e('Ranked by velocity × distinct KOLs × trust weighting. Hover for heuristic caveats.', 'wcp-wiretap'); ?>
                <?php if (!empty($emerging['computed_at'])) : ?>
                    <?php printf(esc_html__('Computed %s.', 'wcp-wiretap'), esc_html($emerging['computed_at'])); ?>
                <?php endif; ?>
            </p>
            <table class="widefat striped">
                <thead><tr>
                    <th></th><th><?php _e('Name', 'wcp-wiretap'); ?></th><th><?php _e('Label', 'wcp-wiretap'); ?></th>
                    <th><?php _e('7d mentions', 'wcp-wiretap'); ?></th><th><?php _e('Velocity', 'wcp-wiretap'); ?></th>
                    <th><?php _e('KOLs', 'wcp-wiretap'); ?></th><th><?php _e('14d trend', 'wcp-wiretap'); ?></th>
                    <th><?php _e('Earliness', 'wcp-wiretap'); ?></th>
                </tr></thead>
                <tbody>
                <?php if (!$entries) : ?>
                    <tr><td colspan="8"><?php _e('Nothing emerging yet — the nightly rollup populates this panel.', 'wcp-wiretap'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($entries as $entry) : ?>
                    <tr>
                        <td><?php echo $entry['object_type'] === 'ticker' ? '💲' : '🧵'; ?></td>
                        <td><strong><?php echo esc_html($entry['object_type'] === 'ticker' ? '$' . $entry['object_key'] : $entry['object_key']); ?></strong></td>
                        <td><?php echo esc_html($entry['label']); ?></td>
                        <td><?php echo (int) $entry['mentions_7d']; ?></td>
                        <td><?php echo esc_html($entry['velocity']); ?>×</td>
                        <td><?php echo (int) $entry['distinct_kols']; ?></td>
                        <td class="wcpw-spark"><?php echo esc_html($this->spark($entry['sparkline'])); ?></td>
                        <td>
                            <?php if (!empty($entry['earliness'])) : ?>
                                <span class="wcpw-band wcpw-band-<?php echo esc_attr($entry['earliness']['band']); ?>" title="<?php echo esc_attr($entry['earliness']['facts']); ?>">
                                    <?php echo esc_html(WCPW_Earliness::band_label($entry['earliness']['band'])); ?>
                                </span>
                            <?php else : ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        $this->styles();
    }

    // ---------------------------------------------------------- Runs & budget

    public function render_runs() {
        $runs = array_reverse(get_option('wcpw_runs', array()));
        $tokens = WCPW_AI_Log::tokens_this_month();
        ?>
        <div class="wrap">
            <h1><?php _e('Wiretap — Runs & Budget', 'wcp-wiretap'); ?></h1>
            <?php $this->budget_strip(true); ?>
            <p>
                <?php printf(esc_html__('Anthropic tokens this month: %s in / %s out.', 'wcp-wiretap'), number_format($tokens['in']), number_format($tokens['out'])); ?>
                <?php if (WCPW_Tweet_Source::backing_off()) : ?>
                    <strong style="color:#d63638"><?php _e('X API backoff active.', 'wcp-wiretap'); ?></strong>
                <?php endif; ?>
            </p>
            <p>
                <button class="button" data-wcpw="run-now" data-job="fetch"><?php _e('Fetch now', 'wcp-wiretap'); ?></button>
                <button class="button" data-wcpw="run-now" data-job="analyze"><?php _e('Analyze now', 'wcp-wiretap'); ?></button>
                <button class="button" data-wcpw="run-now" data-job="rollup"><?php _e('Rollup now', 'wcp-wiretap'); ?></button>
                <button class="button" data-wcpw="run-now" data-job="price_watch"><?php _e('Price check now', 'wcp-wiretap'); ?></button>
                <span class="description"><?php printf(esc_html__('%d tweets pending analysis.', 'wcp-wiretap'), WCPW_Tweet_Repo::pending_count()); ?></span>
            </p>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php _e('Job', 'wcp-wiretap'); ?></th><th><?php _e('Finished', 'wcp-wiretap'); ?></th>
                    <th><?php _e('Counts', 'wcp-wiretap'); ?></th><th><?php _e('Reads', 'wcp-wiretap'); ?></th>
                    <th><?php _e('Errors', 'wcp-wiretap'); ?></th>
                </tr></thead>
                <tbody>
                <?php if (!$runs) : ?>
                    <tr><td colspan="5"><?php _e('No runs recorded yet.', 'wcp-wiretap'); ?></td></tr>
                <?php endif; ?>
                <?php foreach (array_slice($runs, 0, 40) as $run) : ?>
                    <tr>
                        <td><?php echo esc_html($run['job']); ?></td>
                        <td><?php echo esc_html($run['finished_at']); ?></td>
                        <td><code><?php echo esc_html(wp_json_encode($run['counts'])); ?></code></td>
                        <td><?php echo (int) $run['reads_used']; ?></td>
                        <td style="color:#d63638"><?php echo esc_html(implode(' | ', array_slice((array) $run['errors'], 0, 3))); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        $this->styles();
    }

    // --------------------------------------------------------------- Helpers

    private function budget_strip($detailed = false) {
        $reads = wcpw_reads_this_month();
        $spend = wcpw_spend_this_month_usd();
        $cap = (float) wcpw_get_setting('monthly_read_cap_usd');
        $pct = $cap > 0 ? min(100, ($spend / $cap) * 100) : 0;
        $color = $pct > 90 ? '#d63638' : ($pct > 70 ? '#dba617' : '#2271b1');
        ?>
        <div class="wcpw-budget" title="<?php esc_attr_e('X API pay-per-use reads this month', 'wcp-wiretap'); ?>">
            <div class="wcpw-budget-bar"><span style="width:<?php echo esc_attr($pct); ?>%;background:<?php echo esc_attr($color); ?>"></span></div>
            <span><?php printf(
                esc_html__('X reads: %s (~$%s of $%s cap)', 'wcp-wiretap'),
                number_format($reads), number_format($spend, 2), number_format($cap, 0)
            ); ?></span>
            <?php if ($detailed && wcpw_budget_exhausted()) : ?>
                <strong style="color:#d63638"> — <?php _e('cap reached: polling stopped until next month or cap raise', 'wcp-wiretap'); ?></strong>
            <?php endif; ?>
        </div>
        <?php
    }

    private function spark(array $values) {
        $blocks = array('▁', '▂', '▃', '▄', '▅', '▆', '▇', '█');
        $max = max(1, max($values));
        $out = '';
        foreach ($values as $v) {
            $out .= $blocks[min(7, (int) floor($v / $max * 7))];
        }
        return $out;
    }

    private function styles() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        ?>
        <style>
            .wcpw-card { background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:12px 16px; margin:12px 0; max-width:980px; }
            .wcpw-card.wcpw-highlight { border-color:#2271b1; box-shadow:0 0 0 2px rgba(34,113,177,.25); }
            .wcpw-card-head { display:flex; gap:10px; align-items:center; flex-wrap:wrap; font-size:14px; }
            .wcpw-dir { padding:1px 8px; border-radius:10px; font-size:11px; font-weight:600; background:#f0f0f1; }
            .wcpw-dir-long, .wcpw-dir-accumulate { background:#d5f5e3; color:#1e8449; }
            .wcpw-dir-short, .wcpw-dir-exit { background:#fdecea; color:#c0392b; }
            .wcpw-trust { color:#dba617; font-weight:600; }
            .wcpw-pct.up { color:#1e8449; font-weight:600; } .wcpw-pct.down { color:#c0392b; font-weight:600; }
            .wcpw-band { padding:1px 8px; border-radius:10px; font-size:11px; background:#f0f0f1; cursor:help; }
            .wcpw-band-too_early { background:#e8f0fe; color:#1a56db; }
            .wcpw-band-on_time { background:#d5f5e3; color:#1e8449; }
            .wcpw-band-crowded { background:#fef3cd; color:#997404; }
            .wcpw-band-late, .wcpw-band-quiet_mover { background:#fdecea; color:#c0392b; }
            .wcpw-date { color:#787c82; margin-left:auto; font-size:12px; }
            .wcpw-rationale { margin:8px 0 4px; }
            .wcpw-why { color:#646970; font-size:12px; margin:4px 0; }
            .wcpw-priors, .wcpw-memo { font-size:12px; color:#646970; margin:4px 0; }
            .wcpw-memo { background:#f6f7f7; padding:6px 10px; border-radius:4px; }
            .wcpw-actions { margin-top:8px; display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
            .wcpw-kanban { display:flex; gap:16px; align-items:flex-start; }
            .wcpw-col { flex:1; min-width:280px; }
            .wcpw-spark { font-family:monospace; letter-spacing:1px; color:#2271b1; }
            .wcpw-budget { display:flex; align-items:center; gap:10px; margin:10px 0; max-width:980px; }
            .wcpw-budget-bar { flex:0 0 220px; height:8px; background:#f0f0f1; border-radius:4px; overflow:hidden; }
            .wcpw-budget-bar span { display:block; height:100%; }
            .wcpw-digest-body { max-width:800px; }
        </style>
        <?php
    }

    /** Shared fetch() helper + event delegation for all data-wcpw buttons. */
    public function footer_js() {
        if (!$this->is_wiretap_screen()) {
            return;
        }
        ?>
        <script>
        (function() {
            const rest = <?php echo wp_json_encode(rest_url('wcp-wiretap/v1/')); ?>;
            const nonce = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;

            async function api(route, data) {
                const res = await fetch(rest + route, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                    body: JSON.stringify(data || {})
                });
                const body = await res.json();
                if (!res.ok) throw new Error(body.message || res.statusText);
                return body;
            }

            function busy(el, on) { el.disabled = on; el.style.opacity = on ? .6 : 1; }

            document.addEventListener('change', async (e) => {
                const el = e.target;
                if (el.matches('.wcpw-dismiss-reason') && el.value) {
                    try {
                        await api('dismiss-rec', { rec_id: el.dataset.rec, reason: el.value });
                        document.getElementById('rec-' + el.dataset.rec).style.display = 'none';
                    } catch (err) { alert(err.message); }
                }
                if (el.matches('[data-wcpw="set-trust"]')) {
                    try { await api('update-kol', { kol_id: el.dataset.kol, trust_score: el.value }); }
                    catch (err) { alert(err.message); }
                }
            });

            document.addEventListener('click', async (e) => {
                const el = e.target.closest('[data-wcpw]');
                if (!el || el.matches('select')) return;
                const action = el.dataset.wcpw;
                try {
                    switch (action) {
                        case 'accept-rec':
                            busy(el, true);
                            await api('accept-rec', { rec_id: el.dataset.rec });
                            document.getElementById('rec-' + el.dataset.rec).style.display = 'none';
                            break;
                        case 'edit-rec': {
                            const direction = prompt('Direction (long/short/accumulate/exit/watch):', el.dataset.direction);
                            if (direction === null) return;
                            const ticker = prompt('Ticker (confirming also verifies unknown tickers):', el.dataset.ticker);
                            if (ticker === null) return;
                            const confidence = prompt('Confidence 0–1:', el.dataset.confidence);
                            if (confidence === null) return;
                            busy(el, true);
                            await api('edit-rec', { rec_id: el.dataset.rec, direction, ticker, confidence });
                            location.reload();
                            break;
                        }
                        case 'create-plan':
                            busy(el, true); el.textContent = 'Extracting…';
                            await api('create-plan', { rec_id: el.dataset.rec });
                            alert('Trade plan proposed — review it on the Trade Plans tab.');
                            el.textContent = 'Create plan';
                            break;
                        case 'checkin':
                            busy(el, true); el.textContent = 'Checking…';
                            const memo = (await api('checkin', { object_id: el.dataset.object })).memo;
                            alert('Thesis: ' + memo.thesis_status + '\nNext: ' + memo.suggested_next_look + '\n\n' + memo.rationale);
                            location.reload();
                            break;
                        case 'mute-ticker':
                            await api('mute-ticker', { ticker: el.dataset.ticker, days: 7 });
                            alert('$' + el.dataset.ticker + ' alerts muted for 7 days (ingestion continues).');
                            break;
                        case 'arm-plan': {
                            const card = el.closest('.wcpw-card');
                            busy(el, true);
                            await api('arm-plan', {
                                plan_id: el.dataset.plan,
                                entry_low: card.querySelector('.wcpw-lvl-low').value,
                                entry_high: card.querySelector('.wcpw-lvl-high').value,
                                invalidation: card.querySelector('.wcpw-lvl-inv').value
                            });
                            location.reload();
                            break;
                        }
                        case 'cancel-plan':
                            busy(el, true);
                            await api('cancel-plan', { plan_id: el.dataset.plan });
                            location.reload();
                            break;
                        case 'close-plan':
                            busy(el, true);
                            await api('close-plan', { plan_id: el.dataset.plan });
                            location.reload();
                            break;
                        case 'add-kol': {
                            const handle = document.getElementById('wcpw-new-handle').value.trim();
                            if (!handle) return;
                            busy(el, true);
                            const out = await api('add-kol', { handle });
                            if (out.warning) alert(out.warning);
                            location.reload();
                            break;
                        }
                        case 'import-list': {
                            const listId = document.getElementById('wcpw-list-id').value.trim();
                            if (!listId) return;
                            busy(el, true);
                            const out = await api('import-list', { list_id: listId });
                            alert('Imported ' + out.imported + ' accounts.');
                            location.reload();
                            break;
                        }
                        case 'promote-kol':
                            busy(el, true);
                            await api('promote-kol', { kol_id: el.dataset.kol });
                            location.reload();
                            break;
                        case 'dismiss-kol':
                            busy(el, true);
                            await api('dismiss-kol', { kol_id: el.dataset.kol });
                            location.reload();
                            break;
                        case 'pause-kol':
                            busy(el, true);
                            await api('update-kol', { kol_id: el.dataset.kol, tracking_status: 'paused' });
                            location.reload();
                            break;
                        case 'discover-graph':
                            // Cost confirmation before any budgeted discovery action (§F3.2).
                            if (!confirm('Fetch this KOL\'s following list? Estimated cost ~$' + el.dataset.cost + ' of X API reads.')) return;
                            busy(el, true); el.textContent = 'Expanding…';
                            const g = await api('discover-graph', { kol_id: el.dataset.kol });
                            alert('Fetched ' + g.fetched + ' follows; suggested ' + g.suggested + ' new KOLs (see suggestion queue).');
                            location.reload();
                            break;
                        case 'discover-earliest': {
                            // Cost confirmation (§F3.3).
                            const ticker = document.getElementById('wcpw-earliest-ticker').value.trim();
                            if (!ticker) return;
                            if (!confirm('Run archive search for ' + ticker + '? Estimated cost up to ~$' + el.dataset.cost + '.')) return;
                            busy(el, true); el.textContent = 'Searching…';
                            const out = await api('discover-earliest', {
                                ticker,
                                start_date: document.getElementById('wcpw-earliest-start').value,
                                end_date: document.getElementById('wcpw-earliest-end').value
                            });
                            busy(el, false); el.textContent = 'Find earliest callers';
                            const box = document.getElementById('wcpw-earliest-results');
                            let html = '<p><strong>Source: ' + out.source + '</strong>' + (out.error ? ' (' + out.error + ')' : '') + '</p><ol>';
                            out.results.forEach(r => {
                                const move = (r.price_then && r.price_now) ? ' | $' + r.price_then + ' → $' + r.price_now : '';
                                html += '<li><a href="https://x.com/' + r.handle + '" target="_blank">@' + r.handle + '</a> — ' + r.first_mention + move +
                                    ' <button class="button button-small" data-wcpw="add-suggested" data-handle="' + r.handle + '">+ suggest</button></li>';
                            });
                            box.innerHTML = html + '</ol>';
                            break;
                        }
                        case 'add-suggested':
                            busy(el, true);
                            await api('add-kol', { handle: el.dataset.handle, tracking_status: 'suggested', discovery_source: 'earliest_caller', discovery_reason: 'Early caller search result' });
                            el.textContent = '✓ suggested';
                            break;
                        case 'generate-digest':
                            busy(el, true); el.textContent = 'Generating…';
                            const d = await api('generate-digest', { window_hours: document.getElementById('wcpw-digest-hours').value });
                            location.reload();
                            break;
                        case 'run-now':
                            busy(el, true); el.textContent = 'Running…';
                            await api('run-now', { job: el.dataset.job });
                            location.reload();
                            break;
                    }
                } catch (err) {
                    alert(err.message);
                    busy(el, false);
                }
            });
        })();
        </script>
        <?php
    }
}
