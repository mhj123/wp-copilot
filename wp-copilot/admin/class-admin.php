<?php
/**
 * Admin Interface
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_Admin {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('add_meta_boxes', array($this, 'add_ai_meta_boxes'));
        add_action('admin_post_wcp_export_csv', array($this, 'handle_export_csv'));
        add_action('admin_post_wcp_import_csv_preview', array($this, 'handle_import_preview'));
        add_action('admin_post_wcp_import_csv_commit', array($this, 'handle_import_commit'));

        // Creator provenance: filter dropdown on the list screens.
        add_action('restrict_manage_posts', array($this, 'render_creator_filter'));
        add_action('pre_get_posts', array($this, 'filter_by_creator'));
    }

    /** Post types that carry a creator marker. */
    private function creator_screens() {
        return array('post', 'page', 'wcp_heading');
    }

    /** "Created by" dropdown above the post list. */
    public function render_creator_filter() {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->post_type, $this->creator_screens(), true)) {
            return;
        }
        $current = isset($_GET['wcp_created_by']) ? sanitize_key($_GET['wcp_created_by']) : '';
        $options = array(
            ''        => __('All creators', 'work-copilot'),
            'manual'  => __('Manual', 'work-copilot'),
            'copilot' => __('Copilot (AI)', 'work-copilot'),
            'hermes'  => __('Hermes', 'work-copilot'),
        );
        echo '<select name="wcp_created_by">';
        foreach ($options as $val => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($val), selected($current, $val, false), esc_html($label));
        }
        echo '</select>';
    }

    /** Apply the creator filter to the admin list query. */
    public function filter_by_creator($query) {
        global $pagenow;
        if (!is_admin() || $pagenow !== 'edit.php' || !$query->is_main_query()) {
            return;
        }
        $pt = $query->get('post_type') ?: 'post';
        if (!in_array($pt, $this->creator_screens(), true) || empty($_GET['wcp_created_by'])) {
            return;
        }
        $val = sanitize_key($_GET['wcp_created_by']);
        if ($val === 'manual') {
            $query->set('meta_query', array(array('key' => '_wcp_created_by', 'compare' => 'NOT EXISTS')));
        } elseif (in_array($val, array('copilot', 'hermes'), true)) {
            $query->set('meta_query', array(array('key' => '_wcp_created_by', 'value' => $val)));
        }
    }

    public function add_creator_column($columns) {
        $columns['wcp_created_by'] = __('Creator', 'work-copilot');
        return $columns;
    }

    public function render_creator_column($column, $post_id) {
        if ($column !== 'wcp_created_by') {
            return;
        }
        $by = get_post_meta($post_id, '_wcp_created_by', true);
        if (!$by && get_post_meta($post_id, '_wcp_ai_generated', true)) {
            $by = 'copilot';
        }
        $labels = array('copilot' => __('Copilot (AI)', 'work-copilot'), 'hermes' => __('Hermes', 'work-copilot'));
        echo $by ? esc_html(isset($labels[$by]) ? $labels[$by] : $by) : '—';
    }

    public function add_admin_menu() {
        add_menu_page(
            __('Work Copilot', 'work-copilot'),
            __('Work Copilot', 'work-copilot'),
            'edit_posts',
            'work-copilot',
            array($this, 'render_dashboard'),
            'dashicons-networking',
            3
        );

        add_submenu_page(
            'work-copilot',
            __('Dashboard', 'work-copilot'),
            __('Dashboard', 'work-copilot'),
            'edit_posts',
            'work-copilot',
            array($this, 'render_dashboard')
        );

        add_submenu_page(
            'work-copilot',
            __('AI Audit Log', 'work-copilot'),
            __('AI Audit Log', 'work-copilot'),
            'edit_posts',
            'work-copilot-ai-log',
            array($this, 'render_ai_log')
        );

        add_submenu_page(
            'work-copilot',
            __('Import / Export', 'work-copilot'),
            __('Import / Export', 'work-copilot'),
            'edit_posts',
            'work-copilot-import-export',
            array($this, 'render_import_export')
        );
    }

    /**
     * Import / Export admin screen. Export is live; import is phase 2.
     */
    public function render_import_export() {
        ?>
        <div class="wrap">
            <h1><?php _e('Work Copilot — Import / Export', 'work-copilot'); ?></h1>

            <h2><?php _e('Export to CSV', 'work-copilot'); ?></h2>
            <p class="description">
                <?php _e('Exports your full knowledge tree — pages, subpages, headings and items (of each type) — preserving the structure as columns, with content, tags, status, priority, due dates, source URLs and subtasks.', 'work-copilot'); ?>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wcp_export_csv">
                <?php wp_nonce_field('wcp_export_csv'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php _e('Format', 'work-copilot'); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="radio" name="mode" value="outline" checked>
                                    <strong><?php _e('Full outline', 'work-copilot'); ?></strong> —
                                    <?php _e('one row per page, heading and item (with a row_type column). Captures structural content and fully round-trips for re-import.', 'work-copilot'); ?>
                                </label>
                                <br>
                                <label>
                                    <input type="radio" name="mode" value="items">
                                    <strong><?php _e('Items only', 'work-copilot'); ?></strong> —
                                    <?php _e('one row per item, with the page / subpage / heading as columns. Tidier for spreadsheets and pivots.', 'work-copilot'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Download CSV', 'work-copilot')); ?>
            </form>

            <hr>

            <h2><?php _e('Import from CSV', 'work-copilot'); ?></h2>
            <?php
            $token = isset($_GET['wcp_preview']) ? sanitize_key($_GET['wcp_preview']) : '';
            $rkey  = isset($_GET['wcp_result']) ? sanitize_key($_GET['wcp_result']) : '';

            if ($rkey) {
                $this->render_import_result($rkey);
            } elseif ($token) {
                $this->render_import_preview($token);
            } else {
                $this->render_import_upload();
            }
            ?>
        </div>
        <?php
    }

    private function render_import_upload() {
        ?>
        <p class="description">
            <?php _e('Upload a CSV in either export format. You will see a preview of what will be created or updated before anything is written. Matching is by the <code>id</code> column; rows without a matching id are created.', 'work-copilot'); ?>
        </p>
        <p class="description">
            <?php _e('Note: pages and headings that already exist keep their current content (only their title, structure and order are updated). Only items have their content updated from the CSV.', 'work-copilot'); ?>
        </p>
        <p>
            <a href="<?php echo esc_url(WCP_PLUGIN_URL . 'sample-import.csv'); ?>" download>
                <?php _e('Download a sample CSV', 'work-copilot'); ?>
            </a>
            — <?php _e('covers a page, sub-page, heading and one item of each type. Required columns: row_type, title, context_path (leave id blank to create new).', 'work-copilot'); ?>
        </p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="wcp_import_csv_preview">
            <?php wp_nonce_field('wcp_import_csv'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php _e('CSV file', 'work-copilot'); ?></th>
                    <td><input type="file" name="import_file" accept=".csv,text/csv" required></td>
                </tr>
            </table>
            <?php submit_button(__('Preview import', 'work-copilot')); ?>
        </form>
        <?php
    }

    private function render_import_preview($token) {
        $payload = get_transient('wcp_import_' . $token);
        if (!$payload || empty($payload['summary'])) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Preview expired or not found. Please upload the file again.', 'work-copilot') . '</p></div>';
            $this->render_import_upload();
            return;
        }

        $s = $payload['summary'];
        ?>
        <p class="description"><?php printf(esc_html__('Detected format: %s', 'work-copilot'), '<strong>' . esc_html($payload['mode']) . '</strong>'); ?></p>
        <table class="widefat striped" style="max-width:640px">
            <tbody>
                <tr><td><?php _e('Pages', 'work-copilot'); ?></td><td><?php printf(esc_html__('%d new, %d updated', 'work-copilot'), $s['pages_create'], $s['pages_update']); ?></td></tr>
                <tr><td><?php _e('Headings', 'work-copilot'); ?></td><td><?php printf(esc_html__('%d new, %d updated', 'work-copilot'), $s['headings_create'], $s['headings_update']); ?></td></tr>
                <tr><td><?php _e('Items', 'work-copilot'); ?></td><td><?php printf(esc_html__('%d new, %d updated', 'work-copilot'), $s['items_create'], $s['items_update']); ?></td></tr>
                <tr><td><?php _e('Item content changes', 'work-copilot'); ?></td><td><?php echo (int) $s['content_changes']; ?></td></tr>
                <tr><td><?php _e('Skipped rows', 'work-copilot'); ?></td><td><?php echo (int) $s['skipped']; ?></td></tr>
            </tbody>
        </table>

        <?php if (!empty($s['warnings'])) : ?>
            <div class="notice notice-warning inline">
                <p><strong><?php _e('Warnings:', 'work-copilot'); ?></strong></p>
                <ul style="list-style:disc;margin-left:20px">
                    <?php foreach (array_slice($s['warnings'], 0, 50) as $w) : ?>
                        <li><?php echo esc_html($w); ?></li>
                    <?php endforeach; ?>
                    <?php if (count($s['warnings']) > 50) : ?>
                        <li><?php printf(esc_html__('…and %d more.', 'work-copilot'), count($s['warnings']) - 50); ?></li>
                    <?php endif; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="wcp_import_csv_commit">
            <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
            <?php wp_nonce_field('wcp_import_commit'); ?>
            <?php submit_button(__('Confirm import', 'work-copilot'), 'primary'); ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=work-copilot-import-export')); ?>" class="button"><?php _e('Cancel', 'work-copilot'); ?></a>
        </form>
        <?php
    }

    private function render_import_result($rkey) {
        $r = get_transient('wcp_import_result_' . $rkey);
        if (!$r) {
            $this->render_import_upload();
            return;
        }
        delete_transient('wcp_import_result_' . $rkey);
        ?>
        <div class="notice notice-success inline"><p><?php _e('Import complete.', 'work-copilot'); ?></p></div>
        <table class="widefat striped" style="max-width:640px">
            <tbody>
                <tr><td><?php _e('Pages saved', 'work-copilot'); ?></td><td><?php echo (int) $r['pages']; ?></td></tr>
                <tr><td><?php _e('Headings saved', 'work-copilot'); ?></td><td><?php echo (int) $r['headings']; ?></td></tr>
                <tr><td><?php _e('Items saved', 'work-copilot'); ?></td><td><?php echo (int) $r['items']; ?></td></tr>
                <tr><td><?php _e('Skipped', 'work-copilot'); ?></td><td><?php echo (int) $r['skipped']; ?></td></tr>
            </tbody>
        </table>
        <?php if (!empty($r['errors'])) : ?>
            <div class="notice notice-error inline">
                <p><strong><?php _e('Errors:', 'work-copilot'); ?></strong></p>
                <ul style="list-style:disc;margin-left:20px">
                    <?php foreach ($r['errors'] as $e) : ?><li><?php echo esc_html($e); ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <p><a href="<?php echo esc_url(admin_url('admin.php?page=work-copilot-import-export')); ?>" class="button"><?php _e('Import another file', 'work-copilot'); ?></a></p>
        <?php
    }

    /**
     * Parse the upload, build a dry-run plan, stash it in a transient, and
     * redirect to the preview screen. No content is written here.
     */
    public function handle_import_preview() {
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to import.', 'work-copilot'), '', array('response' => 403));
        }
        check_admin_referer('wcp_import_csv');

        $base = admin_url('admin.php?page=work-copilot-import-export');

        if (empty($_FILES['import_file']) || !isset($_FILES['import_file']['error']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            wp_safe_redirect(add_query_arg('wcp_error', 'upload', $base));
            exit;
        }

        $parsed = WCP_CSV_Importer::instance()->parse($_FILES['import_file']['tmp_name']);
        if (is_wp_error($parsed)) {
            wp_safe_redirect(add_query_arg('wcp_error', 'parse', $base));
            exit;
        }

        $summary = WCP_CSV_Importer::instance()->build_plan($parsed['rows']);

        $token = wp_generate_password(20, false);
        set_transient('wcp_import_' . $token, array(
            'mode'    => $parsed['mode'],
            'rows'    => $parsed['rows'],
            'summary' => $summary,
        ), HOUR_IN_SECONDS);

        wp_safe_redirect(add_query_arg('wcp_preview', $token, $base));
        exit;
    }

    /**
     * Commit a previously-previewed import.
     */
    public function handle_import_commit() {
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to import.', 'work-copilot'), '', array('response' => 403));
        }
        check_admin_referer('wcp_import_commit');

        $base    = admin_url('admin.php?page=work-copilot-import-export');
        $token   = isset($_POST['token']) ? sanitize_key($_POST['token']) : '';
        $payload = $token ? get_transient('wcp_import_' . $token) : false;

        if (!$payload || empty($payload['rows'])) {
            wp_safe_redirect(add_query_arg('wcp_error', 'expired', $base));
            exit;
        }

        $result = WCP_CSV_Importer::instance()->commit($payload['rows']);
        delete_transient('wcp_import_' . $token);

        $rkey = wp_generate_password(20, false);
        set_transient('wcp_import_result_' . $rkey, $result, HOUR_IN_SECONDS);

        wp_safe_redirect(add_query_arg('wcp_result', $rkey, $base));
        exit;
    }

    /**
     * Stream the CSV export as a file download.
     */
    public function handle_export_csv() {
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to export.', 'work-copilot'), '', array('response' => 403));
        }
        check_admin_referer('wcp_export_csv');

        $mode = (isset($_POST['mode']) && $_POST['mode'] === 'items') ? 'items' : 'outline';

        $label    = ($mode === 'items') ? 'compact' : 'full';
        $filename = 'work-copilot-export-' . $label . '-' . gmdate('Y-m-d') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        WCP_CSV_Exporter::instance()->stream($mode);
        exit;
    }

    public function enqueue_scripts($hook) {
        // Enqueue on Work Copilot pages
        if (strpos($hook, 'work-copilot') !== false || in_array($hook, array('post.php', 'post-new.php', 'edit.php'), true)) {
            wp_enqueue_style(
                'work-copilot-admin',
                WCP_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                WCP_VERSION
            );

            wp_enqueue_script(
                'work-copilot-admin',
                WCP_PLUGIN_URL . 'assets/js/admin.js',
                array('jquery'),
                WCP_VERSION,
                true
            );

            wp_localize_script('work-copilot-admin', 'wcpData', array(
                'restUrl' => rest_url('work-copilot/v1'),
                'nonce' => wp_create_nonce('wp_rest'),
            ));
        }
    }

    public function render_dashboard() {
        ?>
        <div class="wrap wcp-dashboard">
            <h1><?php _e('Work Copilot Dashboard', 'work-copilot'); ?></h1>

            <div class="wcp-grid">
                <div class="wcp-col-8">
                    <div class="wcp-card">
                        <h2><?php _e('Quick Create ItemPost', 'work-copilot'); ?></h2>
                        <form id="wcp-quick-create">
                            <input type="text" id="wcp-quick-title" placeholder="<?php _e('Title', 'work-copilot'); ?>" style="width: 100%; margin-bottom: 10px; padding: 8px;">
                            <textarea id="wcp-quick-content" placeholder="<?php _e('Content', 'work-copilot'); ?>" style="width: 100%; height: 120px; margin-bottom: 10px; padding: 8px;"></textarea>

                            <div style="margin-bottom: 10px;">
                                <label><?php _e('Contexts:', 'work-copilot'); ?></label>
                                <div id="wcp-context-selector"></div>
                            </div>

                            <div style="margin-bottom: 10px;">
                                <label><?php _e('Item Type:', 'work-copilot'); ?></label>
                                <select id="wcp-item-type" style="width: 100%;">
                                    <option value="">-</option>
                                    <option value="task"><?php _e('Task', 'work-copilot'); ?></option>
                                    <option value="info"><?php _e('Info', 'work-copilot'); ?></option>
                                    <option value="learning"><?php _e('Learning', 'work-copilot'); ?></option>
                                </select>
                            </div>

                            <div style="margin-bottom: 10px;">
                                <label><?php _e('Priority:', 'work-copilot'); ?></label>
                                <select id="wcp-priority" style="width: 100%;">
                                    <option value="">-</option>
                                    <option value="high"><?php _e('High', 'work-copilot'); ?></option>
                                    <option value="medium"><?php _e('Medium', 'work-copilot'); ?></option>
                                    <option value="low"><?php _e('Low', 'work-copilot'); ?></option>
                                </select>
                            </div>

                            <button type="submit" class="button button-primary"><?php _e('Create Item', 'work-copilot'); ?></button>
                            <button type="button" id="wcp-ai-suggest" class="button"><?php _e('AI Suggest Tags', 'work-copilot'); ?></button>
                        </form>
                    </div>

                    <div class="wcp-card" style="margin-top: 20px;">
                        <h2><?php _e('Recent ItemPosts', 'work-copilot'); ?></h2>
                        <div id="wcp-recent-items"></div>
                    </div>
                </div>

                <div class="wcp-col-4">
                    <div class="wcp-card">
                        <h2><?php _e('Context Tree', 'work-copilot'); ?></h2>
                        <div id="wcp-context-tree"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_ai_log() {
        $logger = WCP_AI_Logger::instance();
        $actions = $logger->get_recent_actions(100);
        ?>
        <div class="wrap">
            <h1><?php _e('AI Audit Log', 'work-copilot'); ?></h1>
            <p><?php _e('All AI interactions are logged for transparency and auditability.', 'work-copilot'); ?></p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Timestamp', 'work-copilot'); ?></th>
                        <th><?php _e('Action Type', 'work-copilot'); ?></th>
                        <th><?php _e('Model', 'work-copilot'); ?></th>
                        <th><?php _e('Context', 'work-copilot'); ?></th>
                        <th><?php _e('Accepted', 'work-copilot'); ?></th>
                        <th><?php _e('Dismissed', 'work-copilot'); ?></th>
                        <th><?php _e('Action', 'work-copilot'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($actions)): ?>
                        <tr>
                            <td colspan="7"><?php _e('No AI actions logged yet.', 'work-copilot'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($actions as $action): ?>
                            <tr>
                                <td><?php echo esc_html($action['timestamp']); ?></td>
                                <td><?php echo esc_html($action['action_type']); ?></td>
                                <td><?php echo esc_html($action['model']); ?></td>
                                <td>
                                    <?php if ($action['context_post_id']): ?>
                                        <a href="<?php echo esc_url(get_edit_post_link($action['context_post_id'])); ?>">
                                            <?php echo esc_html(get_the_title($action['context_post_id'])); ?>
                                        </a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo !empty($action['accepted_items']) ? count($action['accepted_items']) : 0; ?></td>
                                <td><?php echo !empty($action['dismissed_items']) ? count($action['dismissed_items']) : 0; ?></td>
                                <td>
                                    <button class="button button-small wcp-view-action-details" data-action-id="<?php echo esc_attr($action['action_id']); ?>">
                                        <?php _e('View Details', 'work-copilot'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function add_ai_meta_boxes() {
        // Add AI assistant meta box to posts AND pages
        add_meta_box(
            'wcp_ai_assistant',
            __('AI Assistant', 'work-copilot'),
            array($this, 'render_editor_ai_meta_box'),
            array('post', 'page'),
            'side',
            'default'
        );
    }

    /**
     * Render enhanced AI assistant meta box for editor
     */
    public function render_editor_ai_meta_box($post) {
        // Get saved prompts
        $saved_prompts = get_option('wcp_saved_prompts', array());
        if (empty($saved_prompts)) {
            $saved_prompts = array(
                array('label' => 'Expand', 'prompt' => 'Expand this with more detail and examples'),
                array('label' => 'Concise', 'prompt' => 'Make this more concise while keeping key points'),
                array('label' => 'Actions', 'prompt' => 'Add actionable next steps'),
            );
        }
        ?>
        <div class="wcp-editor-ai" data-post-id="<?php echo esc_attr($post->ID); ?>">
            <!-- Prompt chips -->
            <div class="wcp-editor-ai-chips">
                <?php foreach ($saved_prompts as $prompt): ?>
                    <button type="button" class="wcp-editor-chip" data-prompt="<?php echo esc_attr($prompt['prompt']); ?>">
                        <?php echo esc_html($prompt['label']); ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- Context selector -->
            <div class="wcp-editor-ai-context">
                <label><?php _e('Context:', 'work-copilot'); ?></label>
                <select id="wcp-editor-context-mode">
                    <option value="page"><?php _e('This Page', 'work-copilot'); ?></option>
                    <option value="corpus"><?php _e('Entire Corpus (RAG)', 'work-copilot'); ?></option>
                </select>
            </div>

            <!-- Prompt input -->
            <div class="wcp-editor-ai-input">
                <textarea
                    id="wcp-editor-ai-prompt"
                    placeholder="<?php _e('Describe how to modify your draft...', 'work-copilot'); ?>"
                    rows="3"
                ></textarea>
            </div>

            <!-- Actions -->
            <div class="wcp-editor-ai-actions">
                <button type="button" id="wcp-editor-ai-generate" class="button button-primary">
                    <?php _e('Generate', 'work-copilot'); ?>
                </button>
                <button type="button" id="wcp-editor-ai-save-prompt" class="button" title="<?php _e('Save prompt as chip', 'work-copilot'); ?>">
                    <span class="dashicons dashicons-star-empty"></span>
                </button>
            </div>

            <!-- Loading indicator -->
            <div class="wcp-editor-ai-loading" style="display: none;">
                <span class="spinner is-active"></span>
                <span><?php _e('Generating...', 'work-copilot'); ?></span>
            </div>

            <!-- Response area -->
            <div class="wcp-editor-ai-response" style="display: none;">
                <h4><?php _e('AI Response:', 'work-copilot'); ?></h4>
                <div class="wcp-editor-ai-response-content"></div>
                <div class="wcp-editor-ai-response-actions">
                    <button type="button" id="wcp-editor-ai-insert" class="button button-primary">
                        <?php _e('Insert into Content', 'work-copilot'); ?>
                    </button>
                    <button type="button" id="wcp-editor-ai-discard" class="button">
                        <?php _e('Discard', 'work-copilot'); ?>
                    </button>
                </div>
            </div>
        </div>

        <style>
            .wcp-editor-ai-chips {
                display: flex;
                flex-wrap: wrap;
                gap: 5px;
                margin-bottom: 10px;
            }
            .wcp-editor-chip {
                padding: 4px 10px;
                background: #e8f4fc;
                border: 1px solid #b8daff;
                border-radius: 12px;
                font-size: 11px;
                color: #0073aa;
                cursor: pointer;
            }
            .wcp-editor-chip:hover {
                background: #cce5ff;
            }
            .wcp-editor-ai-context {
                margin-bottom: 10px;
            }
            .wcp-editor-ai-context label {
                display: block;
                font-weight: 600;
                margin-bottom: 5px;
                font-size: 11px;
            }
            .wcp-editor-ai-context select {
                width: 100%;
            }
            .wcp-editor-ai-input textarea {
                width: 100%;
                margin-bottom: 10px;
            }
            .wcp-editor-ai-actions {
                display: flex;
                gap: 5px;
                margin-bottom: 10px;
            }
            .wcp-editor-ai-actions .dashicons {
                margin-top: 3px;
            }
            .wcp-editor-ai-loading {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 10px;
                background: #f7f7f7;
                border-radius: 4px;
                margin-bottom: 10px;
            }
            .wcp-editor-ai-response {
                background: #f0f6fc;
                border: 1px solid #b8daff;
                border-radius: 4px;
                padding: 10px;
                margin-top: 10px;
            }
            .wcp-editor-ai-response h4 {
                margin: 0 0 10px 0;
                font-size: 12px;
                color: #0073aa;
            }
            .wcp-editor-ai-response-content {
                background: #fff;
                border: 1px solid #ddd;
                padding: 10px;
                border-radius: 4px;
                margin-bottom: 10px;
                max-height: 200px;
                overflow-y: auto;
                white-space: pre-wrap;
                font-size: 12px;
            }
            .wcp-editor-ai-response-actions {
                display: flex;
                gap: 5px;
            }
        </style>
        <?php
    }


}
