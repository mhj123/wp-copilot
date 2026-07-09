<?php
/**
 * OpenBiografy admin: Dashboard / Review / Timeline / Chapters.
 * Server-rendered; all actions go through REST + fetch() in assets/admin.js.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPO_Dashboard {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
    }

    public function menu() {
        add_menu_page(__('OpenBiografy', 'wcp-openbiografy'), __('OpenBiografy', 'wcp-openbiografy'), 'manage_options', 'wcpo-dashboard', array($this, 'render_dashboard'), 'dashicons-book-alt', 3.4);
        add_submenu_page('wcpo-dashboard', __('Dashboard', 'wcp-openbiografy'), __('Dashboard', 'wcp-openbiografy'), 'manage_options', 'wcpo-dashboard', array($this, 'render_dashboard'));
        add_submenu_page('wcpo-dashboard', __('Review Facts', 'wcp-openbiografy'), __('Review Facts', 'wcp-openbiografy'), 'manage_options', 'wcpo-review', array($this, 'render_review'));
        add_submenu_page('wcpo-dashboard', __('Timeline', 'wcp-openbiografy'), __('Timeline', 'wcp-openbiografy'), 'manage_options', 'wcpo-timeline', array($this, 'render_timeline'));
        add_submenu_page('wcpo-dashboard', __('Chapters', 'wcp-openbiografy'), __('Chapters', 'wcp-openbiografy'), 'manage_options', 'wcpo-chapters', array($this, 'render_chapters'));
    }

    public function assets($hook) {
        if (strpos((string) $hook, 'wcpo-') === false) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_style('wcpo-admin', WCPO_PLUGIN_URL . 'assets/admin.css', array(), WCPO_VERSION);
        wp_enqueue_script('wcpo-admin', WCPO_PLUGIN_URL . 'assets/admin.js', array(), WCPO_VERSION, true);
        wp_localize_script('wcpo-admin', 'wcpoConfig', array(
            'root'      => esc_url_raw(rest_url('wcp-openbiografy/v1/')),
            'nonce'     => wp_create_nonce('wp_rest'),
            'personId'  => $this->current_person_id(),
            'batchSize' => (int) wcpo_get_setting('batch_size'),
            'kinds'     => wcpo_kinds(),
        ));
    }

    // -------------------------------------------------------- Person context

    private function current_person_id() {
        $user_id = get_current_user_id();
        if (isset($_GET['wcpo_person'])) {
            $person_id = (int) $_GET['wcpo_person'];
            update_user_meta($user_id, 'wcpo_current_person', $person_id);
        } else {
            $person_id = (int) get_user_meta($user_id, 'wcpo_current_person', true);
        }
        if ($person_id && WCPO_Person_Repo::meta($person_id)) {
            return $person_id;
        }
        $people = WCPO_Person_Repo::all();
        return $people ? (int) $people[0]->ID : 0;
    }

    private function person_selector($page) {
        $people = WCPO_Person_Repo::all();
        $current = $this->current_person_id();
        ?>
        <form method="get" class="wcpo-person-select">
            <input type="hidden" name="page" value="<?php echo esc_attr($page); ?>">
            <label><?php _e('Person:', 'wcp-openbiografy'); ?>
                <select name="wcpo_person" onchange="this.form.submit()">
                    <?php foreach ($people as $post) : ?>
                        <option value="<?php echo (int) $post->ID; ?>" <?php selected($current, $post->ID); ?>><?php echo esc_html($post->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($current) : ?>
                <a href="<?php echo esc_url(get_permalink($current)); ?>" target="_blank"><?php _e('View public page ↗', 'wcp-openbiografy'); ?></a>
            <?php endif; ?>
        </form>
        <?php
    }

    private function kind_select($name, $selected, $class = '') {
        $html = '<select name="' . esc_attr($name) . '" class="' . esc_attr($class) . '">';
        foreach (wcpo_kinds() as $kind) {
            $html .= '<option value="' . esc_attr($kind) . '"' . selected($selected, $kind, false) . '>' . esc_html($kind) . '</option>';
        }
        return $html . '</select>';
    }

    private function confidence_badge($confidence) {
        $threshold = (float) wcpo_get_setting('min_confidence_display');
        $class = $confidence < $threshold ? 'wcpo-conf wcpo-conf-low' : 'wcpo-conf';
        $title = $confidence < $threshold ? __('Below confidence threshold — review carefully (flagged, never dropped)', 'wcp-openbiografy') : __('Model confidence', 'wcp-openbiografy');
        return '<span class="' . $class . '" title="' . esc_attr($title) . '">' . esc_html(number_format($confidence * 100, 0)) . '%</span>';
    }

    // ------------------------------------------------------------- Dashboard

    public function render_dashboard() {
        $person_id = $this->current_person_id();
        ?>
        <div class="wrap wcpo-wrap">
            <h1><?php _e('OpenBiografy — Dashboard', 'wcp-openbiografy'); ?></h1>
            <?php $this->person_selector('wcpo-dashboard'); ?>

            <?php if (!$person_id) : ?>
                <p><?php _e('Create the person whose life you are documenting:', 'wcp-openbiografy'); ?></p>
            <?php endif; ?>

            <?php $this->person_form($person_id); ?>

            <?php if (!$person_id) : ?>
                </div>
                <?php
                return;
            endif;

            $sources = WCPO_Source_Repo::counts($person_id);
            $facts   = WCPO_Fact_Repo::counts($person_id);
            $events  = WCPO_Event_Repo::counts($person_id);
            $chapters = WCPO_Chapter_Repo::list_for_person($person_id);
            $drafts_pending = 0;
            foreach ($chapters as $chapter) {
                if (get_post_meta($chapter->ID, '_wcpo_draft_proposal', true)) {
                    $drafts_pending++;
                }
            }
            ?>

            <div class="wcpo-counts">
                <div class="wcpo-count-card">
                    <h3><?php _e('Sources', 'wcp-openbiografy'); ?> (<?php echo (int) $sources['total']; ?>)</h3>
                    <p><?php printf(__('new %1$d · fetched %2$d · extracted %3$d · failed %4$d', 'wcp-openbiografy'), $sources['new'], $sources['fetched'], $sources['extracted'], $sources['fetch_failed'] + $sources['extract_failed']); ?></p>
                </div>
                <div class="wcpo-count-card">
                    <h3><?php _e('Facts', 'wcp-openbiografy'); ?></h3>
                    <p><?php printf(__('proposed %1$d · accepted %2$d · dismissed %3$d · unconsolidated %4$d', 'wcp-openbiografy'), $facts['proposed'], $facts['accepted'], $facts['dismissed'], $facts['unconsolidated']); ?></p>
                </div>
                <div class="wcpo-count-card">
                    <h3><?php _e('Timeline events', 'wcp-openbiografy'); ?></h3>
                    <p><?php printf(__('proposed %1$d · accepted %2$d · contested %3$d', 'wcp-openbiografy'), $events['proposed'], $events['accepted'], $events['contested']); ?></p>
                </div>
                <div class="wcpo-count-card">
                    <h3><?php _e('Chapters', 'wcp-openbiografy'); ?></h3>
                    <p><?php printf(__('%1$d chapters · %2$d drafts pending review', 'wcp-openbiografy'), count($chapters), $drafts_pending); ?></p>
                </div>
            </div>

            <div id="wcpo-warnings" class="wcpo-warnings" data-wcpo-warnings></div>

            <h2><?php _e('Add sources', 'wcp-openbiografy'); ?></h2>
            <p class="description"><?php _e('Paste URLs (one per line), or upload documents (PDF, TXT, MD). Each becomes one source.', 'wcp-openbiografy'); ?></p>
            <textarea id="wcpo-urls" rows="4" class="large-text" placeholder="https://…&#10;https://…"></textarea>
            <p>
                <button class="button button-primary" id="wcpo-add-urls"><?php _e('Add URLs', 'wcp-openbiografy'); ?></button>
                <button class="button" id="wcpo-upload-doc"><?php _e('Upload documents…', 'wcp-openbiografy'); ?></button>
            </p>

            <h2><?php _e('Process pipeline', 'wcp-openbiografy'); ?></h2>
            <p class="description"><?php _e('Each step is user-triggered and processes one source per call, in batches of your configured size. Nothing runs in the background.', 'wcp-openbiografy'); ?></p>
            <p>
                <button class="button" data-wcpo-batch="fetch-next"><?php printf(__('Fetch next %d', 'wcp-openbiografy'), (int) wcpo_get_setting('batch_size')); ?></button>
                <button class="button" data-wcpo-batch="extract-next"><?php printf(__('Extract facts from next %d', 'wcp-openbiografy'), (int) wcpo_get_setting('batch_size')); ?></button>
                <button class="button" data-wcpo-batch="consolidate-next"><?php _e('Consolidate timeline', 'wcp-openbiografy'); ?></button>
                <button class="button" id="wcpo-stop" style="display:none"><?php _e('Stop', 'wcp-openbiografy'); ?></button>
                <span id="wcpo-progress" class="wcpo-progress"></span>
            </p>
            <p>
                <button class="button" id="wcpo-export"><?php _e('Export project JSON', 'wcp-openbiografy'); ?></button>
            </p>

            <h2><?php _e('Sources', 'wcp-openbiografy'); ?></h2>
            <?php $this->sources_table($person_id); ?>
        </div>
        <?php
    }

    private function person_form($person_id) {
        $person = $person_id ? WCPO_Person_Repo::meta($person_id) : null;
        $open = !$person_id;
        ?>
        <details class="wcpo-person-details" <?php echo $open ? 'open' : ''; ?>>
            <summary><?php echo $person ? esc_html(sprintf(__('Subject details: %s', 'wcp-openbiografy'), $person['name'])) : esc_html__('New person', 'wcp-openbiografy'); ?></summary>
            <div class="wcpo-person-form" id="wcpo-person-form" data-person="<?php echo (int) $person_id; ?>">
                <p><label><?php _e('Name', 'wcp-openbiografy'); ?><br><input type="text" name="name" class="regular-text" value="<?php echo esc_attr($person ? $person['name'] : ''); ?>"></label></p>
                <p>
                    <label><?php _e('Born (EDTF)', 'wcp-openbiografy'); ?> <input type="text" name="birth_edtf" class="wcpo-edtf" placeholder="1857-03-12" value="<?php echo esc_attr($person ? $person['birth_edtf'] : ''); ?>"></label>
                    <label><?php _e('Died (EDTF)', 'wcp-openbiografy'); ?> <input type="text" name="death_edtf" class="wcpo-edtf" placeholder="1932~" value="<?php echo esc_attr($person ? $person['death_edtf'] : ''); ?>"></label>
                </p>
                <p>
                    <label><?php _e('Birth place', 'wcp-openbiografy'); ?> <input type="text" name="birth_place" value="<?php echo esc_attr($person ? $person['birth_place'] : ''); ?>"></label>
                    <label><?php _e('Death place', 'wcp-openbiografy'); ?> <input type="text" name="death_place" value="<?php echo esc_attr($person ? $person['death_place'] : ''); ?>"></label>
                    <label><?php _e('Occupation', 'wcp-openbiografy'); ?> <input type="text" name="occupation" value="<?php echo esc_attr($person ? $person['occupation'] : ''); ?>"></label>
                </p>
                <p><label><?php _e('Context note (injected into every AI prompt — disambiguate from namesakes, key affiliations, etc.)', 'wcp-openbiografy'); ?><br>
                    <textarea name="context_note" rows="2" class="large-text"><?php echo esc_textarea($person ? $person['context_note'] : ''); ?></textarea></label></p>
                <p>
                    <button class="button button-primary" id="wcpo-save-person"><?php echo $person ? esc_html__('Save person', 'wcp-openbiografy') : esc_html__('Create person', 'wcp-openbiografy'); ?></button>
                    <?php if ($person) : ?>
                        <a class="button" href="<?php echo esc_url(get_edit_post_link($person_id)); ?>"><?php _e('Edit bio & portrait (native editor)', 'wcp-openbiografy'); ?></a>
                    <?php endif; ?>
                </p>
            </div>
        </details>
        <?php
    }

    private function sources_table($person_id) {
        $sources = WCPO_Source_Repo::list_for_person($person_id);
        if (!$sources) {
            echo '<p>' . esc_html__('No sources yet.', 'wcp-openbiografy') . '</p>';
            return;
        }
        ?>
        <table class="widefat striped wcpo-sources">
            <thead><tr>
                <th><?php _e('Source', 'wcp-openbiografy'); ?></th>
                <th><?php _e('Type', 'wcp-openbiografy'); ?></th>
                <th><?php _e('Tier', 'wcp-openbiografy'); ?></th>
                <th><?php _e('Status', 'wcp-openbiografy'); ?></th>
                <th><?php _e('Facts', 'wcp-openbiografy'); ?></th>
                <th><?php _e('Actions', 'wcp-openbiografy'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($sources as $post) :
                $s = WCPO_Source_Repo::meta($post->ID);
                $link = $s['url'] ?: $s['attachment_url'];
                ?>
                <tr data-wcpo-row data-source="<?php echo (int) $s['id']; ?>">
                    <td>
                        <?php if ($link) : ?><a href="<?php echo esc_url($link); ?>" target="_blank" rel="noopener"><?php echo esc_html($s['cite_title'] ?: $s['title']); ?></a>
                        <?php else : ?><?php echo esc_html($s['cite_title'] ?: $s['title']); ?><?php endif; ?>
                        <?php if ($s['fetch_error']) : ?><br><span class="wcpo-error">⚠ <?php echo esc_html($s['fetch_error']); ?></span><?php endif; ?>
                        <?php if ($s['snapshot_chars']) : ?>
                            <details class="wcpo-snapshot"><summary><?php printf(esc_html__('view text (%s chars)', 'wcp-openbiografy'), number_format_i18n($s['snapshot_chars'])); ?></summary>
                                <div class="wcpo-snapshot-text"><?php echo esc_html(mb_substr($post->post_content, 0, 2000)); ?><?php echo $s['snapshot_chars'] > 2000 ? '…' : ''; ?></div>
                            </details>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($s['doc_kind'] ?: $s['source_type']); ?></td>
                    <td><?php echo esc_html(str_replace('_', ' ', (string) $s['source_tier'])); ?></td>
                    <td><span class="wcpo-status wcpo-status-<?php echo esc_attr($s['fetch_status']); ?>"><?php echo esc_html($s['fetch_status']); ?></span></td>
                    <td><?php echo $s['facts_extracted'] ? (int) $s['facts_extracted'] : '—'; ?></td>
                    <td>
                        <?php if (in_array($s['fetch_status'], array('fetch_failed', 'extract_failed'), true)) : ?>
                            <button class="button button-small wcpo-act" data-route="retry-source" data-params='{"source_id":<?php echo (int) $s['id']; ?>}'><?php _e('Retry', 'wcp-openbiografy'); ?></button>
                        <?php endif; ?>
                        <?php if ($s['source_type'] === 'url' && in_array($s['fetch_status'], array('new', 'fetch_failed'), true)) : ?>
                            <button class="button button-small wcpo-paste-text" data-source="<?php echo (int) $s['id']; ?>"><?php _e('Paste text', 'wcp-openbiografy'); ?></button>
                        <?php endif; ?>
                        <button class="button button-small button-link-delete wcpo-act" data-confirm="<?php esc_attr_e('Trash this source and its still-proposed facts?', 'wcp-openbiografy'); ?>" data-route="delete-source" data-params='{"source_id":<?php echo (int) $s['id']; ?>}'><?php _e('Delete', 'wcp-openbiografy'); ?></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // ---------------------------------------------------------------- Review

    public function render_review() {
        $person_id = $this->current_person_id();
        $groups = $person_id ? WCPO_Fact_Repo::proposed_by_source($person_id) : array();
        ?>
        <div class="wrap wcpo-wrap">
            <h1><?php _e('OpenBiografy — Review Facts', 'wcp-openbiografy'); ?></h1>
            <?php $this->person_selector('wcpo-review'); ?>

            <?php if (!$groups) : ?>
                <p><?php _e('No proposed facts to review. Run “Extract facts” on the Dashboard.', 'wcp-openbiografy'); ?></p>
            <?php endif; ?>

            <?php foreach ($groups as $source_id => $posts) :
                $s = WCPO_Source_Repo::meta($source_id);
                $link = $s ? ($s['url'] ?: $s['attachment_url']) : '';
                ?>
                <div class="wcpo-source-group" data-wcpo-group>
                    <div class="wcpo-group-head">
                        <h2>
                            <?php if ($link) : ?><a href="<?php echo esc_url($link); ?>" target="_blank" rel="noopener"><?php echo esc_html($s['cite_title'] ?: $s['title']); ?></a>
                            <?php else : ?><?php echo esc_html($s ? ($s['cite_title'] ?: $s['title']) : ('#' . $source_id)); ?><?php endif; ?>
                        </h2>
                        <?php if ($s) : ?>
                            <span class="wcpo-badge"><?php echo esc_html($s['doc_kind'] ?: '?'); ?></span>
                            <span class="wcpo-badge"><?php echo esc_html(str_replace('_', ' ', (string) ($s['source_tier'] ?: 'unknown'))); ?></span>
                        <?php endif; ?>
                        <span class="wcpo-group-count"><?php printf(esc_html__('%d proposed', 'wcp-openbiografy'), count($posts)); ?></span>
                        <button class="button wcpo-act wcpo-accept-all" data-route="accept-source-facts" data-params='{"source_id":<?php echo (int) $source_id; ?>}' data-removes="group"><?php _e('Accept all remaining', 'wcp-openbiografy'); ?></button>
                    </div>

                    <?php foreach ($posts as $post) :
                        $f = WCPO_Fact_Repo::meta($post->ID);
                        ?>
                        <div class="wcpo-fact-row" data-wcpo-row data-fact="<?php echo (int) $f['id']; ?>">
                            <div class="wcpo-fact-main">
                                <textarea name="claim" rows="2"><?php echo esc_textarea($f['claim']); ?></textarea>
                                <div class="wcpo-fact-fields">
                                    <input type="text" name="date_edtf" class="wcpo-edtf" placeholder="EDTF" value="<?php echo esc_attr($f['date_edtf']); ?>" title="<?php esc_attr_e('EDTF date: 1932, 1932-03, 1932~, 1891/1894', 'wcp-openbiografy'); ?>">
                                    <input type="text" name="place" placeholder="<?php esc_attr_e('Place', 'wcp-openbiografy'); ?>" value="<?php echo esc_attr($f['place']); ?>">
                                    <?php echo $this->kind_select('kind', $f['kind']); // phpcs:ignore ?>
                                    <?php echo $this->confidence_badge($f['confidence']); // phpcs:ignore ?>
                                </div>
                                <?php if ($f['quote']) : ?>
                                    <details class="wcpo-quote"><summary><?php _e('supporting quote', 'wcp-openbiografy'); ?></summary>
                                        <blockquote><?php echo esc_html($f['quote']); ?><?php echo $f['locator'] ? ' <cite>(' . esc_html($f['locator']) . ')</cite>' : ''; ?></blockquote>
                                    </details>
                                <?php endif; ?>
                            </div>
                            <div class="wcpo-fact-actions">
                                <button class="button button-primary wcpo-accept-fact"><?php _e('Accept', 'wcp-openbiografy'); ?></button>
                                <button class="button wcpo-dismiss-fact"><?php _e('Dismiss', 'wcp-openbiografy'); ?></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------- Timeline

    public function render_timeline() {
        $person_id = $this->current_person_id();
        $proposed = $person_id ? WCPO_Event_Repo::proposed($person_id) : array();
        $accepted = $person_id ? WCPO_Event_Repo::accepted($person_id) : array();
        $unconsolidated = $person_id ? count(WCPO_Fact_Repo::accepted_unconsolidated($person_id, WCPO_Event_Repo::fact_ids_in_proposed($person_id))) : 0;
        ?>
        <div class="wrap wcpo-wrap">
            <h1><?php _e('OpenBiografy — Timeline', 'wcp-openbiografy'); ?></h1>
            <?php $this->person_selector('wcpo-timeline'); ?>

            <p>
                <?php printf(esc_html__('%d accepted facts are not yet consolidated into timeline events.', 'wcp-openbiografy'), $unconsolidated); ?>
                <button class="button" data-wcpo-batch="consolidate-next" <?php disabled(!$unconsolidated); ?>><?php _e('Consolidate', 'wcp-openbiografy'); ?></button>
                <button class="button" id="wcpo-stop" style="display:none"><?php _e('Stop', 'wcp-openbiografy'); ?></button>
                <span id="wcpo-progress" class="wcpo-progress"></span>
            </p>

            <?php if ($proposed) : ?>
                <h2><?php printf(esc_html__('Proposed events (%d)', 'wcp-openbiografy'), count($proposed)); ?></h2>
                <?php foreach ($proposed as $post) :
                    $this->event_card($post->ID, true);
                endforeach; ?>
            <?php endif; ?>

            <h2><?php printf(esc_html__('Accepted timeline (%d)', 'wcp-openbiografy'), count($accepted)); ?></h2>
            <?php if (!$accepted) : ?>
                <p><?php _e('No accepted events yet.', 'wcp-openbiografy'); ?></p>
            <?php endif; ?>
            <?php foreach ($accepted as $post) :
                $this->event_card($post->ID, false);
            endforeach; ?>
        </div>
        <?php
    }

    private function event_card($event_id, $is_proposed) {
        $e = WCPO_Event_Repo::meta($event_id);
        ?>
        <div class="wcpo-event-card <?php echo $e['contested'] ? 'wcpo-contested' : ''; ?>" data-wcpo-row data-event="<?php echo (int) $e['id']; ?>">
            <div class="wcpo-event-main">
                <?php if ($is_proposed) : ?>
                    <input type="text" name="title" class="wcpo-event-title" value="<?php echo esc_attr($e['title']); ?>">
                    <textarea name="description" rows="2"><?php echo esc_textarea($e['description']); ?></textarea>
                    <div class="wcpo-fact-fields">
                        <input type="text" name="date_edtf" class="wcpo-edtf" placeholder="EDTF" value="<?php echo esc_attr($e['date_edtf']); ?>">
                        <input type="text" name="place" placeholder="<?php esc_attr_e('Place', 'wcp-openbiografy'); ?>" value="<?php echo esc_attr($e['place']); ?>">
                        <?php echo $this->kind_select('kind', $e['kind']); // phpcs:ignore ?>
                        <?php echo $this->confidence_badge($e['confidence']); // phpcs:ignore ?>
                    </div>
                <?php else : ?>
                    <strong><?php echo esc_html($e['date_display'] ?: __('undated', 'wcp-openbiografy')); ?></strong> —
                    <strong><?php echo esc_html($e['title']); ?></strong>
                    <span class="wcpo-badge"><?php echo esc_html($e['kind']); ?></span>
                    <?php if ($e['place']) : ?><span class="wcpo-badge"><?php echo esc_html($e['place']); ?></span><?php endif; ?>
                    <p><?php echo esc_html($e['description']); ?></p>
                <?php endif; ?>

                <?php if ($e['contested']) : ?>
                    <p class="wcpo-contested-note">⚖ <strong><?php _e('Contested:', 'wcp-openbiografy'); ?></strong> <?php echo esc_html($e['contested_note'] ?: __('sources disagree', 'wcp-openbiografy')); ?></p>
                <?php endif; ?>

                <details class="wcpo-member-facts">
                    <summary><?php printf(esc_html__('%d supporting facts', 'wcp-openbiografy'), count($e['fact_ids'])); ?></summary>
                    <ul>
                        <?php foreach ($e['fact_ids'] as $fact_id) :
                            $f = WCPO_Fact_Repo::meta($fact_id);
                            if (!$f) {
                                continue;
                            }
                            $cite = WCPO_Source_Repo::citation_line($f['source_id']);
                            ?>
                            <li><?php echo esc_html($f['claim']); ?>
                                <em>(<?php echo esc_html($f['date_display'] ?: __('undated', 'wcp-openbiografy')); ?><?php echo $cite ? ' — ' . esc_html($cite) : ''; ?>)</em></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            </div>
            <?php if ($is_proposed) : ?>
                <div class="wcpo-fact-actions">
                    <button class="button button-primary wcpo-accept-event"><?php _e('Accept', 'wcp-openbiografy'); ?></button>
                    <button class="button wcpo-dismiss-event"><?php _e('Dismiss', 'wcp-openbiografy'); ?></button>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------- Chapters

    public function render_chapters() {
        $person_id = $this->current_person_id();
        $chapters = $person_id ? WCPO_Chapter_Repo::list_for_person($person_id) : array();
        $unassigned = $person_id ? count(WCPO_Event_Repo::unassigned($person_id)) : 0;
        ?>
        <div class="wrap wcpo-wrap">
            <h1><?php _e('OpenBiografy — Chapters', 'wcp-openbiografy'); ?></h1>
            <?php $this->person_selector('wcpo-chapters'); ?>

            <h2><?php _e('New chapter', 'wcp-openbiografy'); ?></h2>
            <p>
                <input type="text" id="wcpo-chapter-title" placeholder="<?php esc_attr_e('Chapter title', 'wcp-openbiografy'); ?>" class="regular-text">
                <input type="text" id="wcpo-chapter-period" class="wcpo-edtf" placeholder="<?php esc_attr_e('Period EDTF, e.g. 1891/1904 or ../1880', 'wcp-openbiografy'); ?>">
                <button class="button button-primary" id="wcpo-create-chapter"><?php _e('Create chapter', 'wcp-openbiografy'); ?></button>
            </p>

            <p>
                <?php printf(esc_html__('%d accepted events are not assigned to any chapter.', 'wcp-openbiografy'), $unassigned); ?>
                <button class="button" id="wcpo-suggest" <?php disabled(!$unassigned || !$chapters); ?>><?php _e('Suggest assignments (AI)', 'wcp-openbiografy'); ?></button>
                <span id="wcpo-progress" class="wcpo-progress"></span>
            </p>
            <div id="wcpo-assignments"></div>

            <?php foreach ($chapters as $index => $post) :
                $c = WCPO_Chapter_Repo::meta($post->ID);
                $events = WCPO_Event_Repo::for_chapter($c['id']);
                ?>
                <div class="wcpo-chapter-card" data-chapter="<?php echo (int) $c['id']; ?>">
                    <div class="wcpo-chapter-head">
                        <span class="wcpo-chapter-order"><?php echo (int) $c['order']; ?></span>
                        <input type="text" name="title" value="<?php echo esc_attr($c['title']); ?>">
                        <input type="text" name="period_edtf" class="wcpo-edtf" value="<?php echo esc_attr($c['period_edtf']); ?>" placeholder="EDTF period">
                        <label><input type="checkbox" name="publish" <?php checked($c['status'], 'publish'); ?>> <?php _e('Published', 'wcp-openbiografy'); ?></label>
                        <button class="button wcpo-save-chapter"><?php _e('Save', 'wcp-openbiografy'); ?></button>
                        <button class="button wcpo-move-up" title="<?php esc_attr_e('Move up', 'wcp-openbiografy'); ?>">↑</button>
                        <button class="button wcpo-move-down" title="<?php esc_attr_e('Move down', 'wcp-openbiografy'); ?>">↓</button>
                    </div>

                    <details class="wcpo-chapter-events">
                        <summary><?php printf(esc_html__('%d assigned events', 'wcp-openbiografy'), count($events)); ?></summary>
                        <ul>
                            <?php foreach ($events as $event_post) :
                                $e = WCPO_Event_Repo::meta($event_post->ID);
                                ?>
                                <li>[e<?php echo (int) $e['id']; ?>] <?php echo esc_html($e['date_display'] ?: '·'); ?> — <?php echo esc_html($e['title']); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>

                    <?php if ($c['narrative']) : ?>
                        <details class="wcpo-narrative"><summary><?php _e('Current narrative', 'wcp-openbiografy'); ?></summary>
                            <div class="wcpo-narrative-text"><?php echo wp_kses_post(wpautop($c['narrative'])); ?></div>
                        </details>
                    <?php endif; ?>

                    <?php if ($c['draft_proposal']) : ?>
                        <div class="wcpo-draft">
                            <h4><?php printf(esc_html__('AI draft (proposed %s) — review, edit, then accept or dismiss', 'wcp-openbiografy'), esc_html($c['draft_created'])); ?></h4>
                            <textarea name="draft" rows="12" class="large-text"><?php echo esc_textarea($c['draft_proposal']); ?></textarea>
                            <p>
                                <button class="button button-primary wcpo-accept-draft"><?php _e('Accept draft as narrative', 'wcp-openbiografy'); ?></button>
                                <button class="button wcpo-dismiss-draft"><?php _e('Dismiss draft', 'wcp-openbiografy'); ?></button>
                            </p>
                        </div>
                    <?php else : ?>
                        <p><button class="button wcpo-draft-chapter" <?php disabled(!$events); ?>><?php _e('Draft narrative (AI)', 'wcp-openbiografy'); ?></button>
                        <?php if (!$events) : ?><span class="description"><?php _e('Assign events first.', 'wcp-openbiografy'); ?></span><?php endif; ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }
}
