<?php
/**
 * Settings — a single wcpo_settings option array, saved via admin_post.
 * The Anthropic API key itself lives in Work Copilot core (Settings → AI).
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPO_Settings {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'menu'), 20);
        add_action('admin_post_wcpo_save_settings', array($this, 'save'));
    }

    public function menu() {
        add_submenu_page('wcpo-dashboard', __('Settings', 'wcp-openbiografy'), __('Settings', 'wcp-openbiografy'), 'manage_options', 'wcpo-settings', array($this, 'render'));
    }

    private function models() {
        // Must match WCP_AI_Client::set_overrides()'s allowlist.
        return array(
            'claude-haiku-4-5-20251001' => __('Haiku 4.5 (fast, cheap)', 'wcp-openbiografy'),
            'claude-sonnet-4-6'         => __('Sonnet 4.6 (balanced)', 'wcp-openbiografy'),
            'claude-opus-4-8'           => __('Opus 4.8 (strongest)', 'wcp-openbiografy'),
        );
    }

    public function render() {
        $fields = array(
            'batch_size'             => array(__('Batch size', 'wcp-openbiografy'), __('Sources processed per “next N” click.', 'wcp-openbiografy'), 'number'),
            'model'                  => array(__('Model — extraction & reconciliation', 'wcp-openbiografy'), '', 'model'),
            'model_draft'            => array(__('Model — narrative drafting', 'wcp-openbiografy'), '', 'model'),
            'max_context_chars'      => array(__('Max context characters', 'wcp-openbiografy'), __('Source text sent to the model is bounded to this many characters.', 'wcp-openbiografy'), 'number'),
            'max_snapshot_chars'     => array(__('Max snapshot characters', 'wcp-openbiografy'), __('Fetched text stored per source.', 'wcp-openbiografy'), 'number'),
            'fetch_timeout'          => array(__('Fetch timeout (seconds)', 'wcp-openbiografy'), '', 'number'),
            'max_pdf_mb'             => array(__('Max PDF size (MB)', 'wcp-openbiografy'), __('PDFs are sent to the model directly as documents.', 'wcp-openbiografy'), 'number'),
            'consolidate_chunk'      => array(__('Consolidation chunk size', 'wcp-openbiografy'), __('Facts per reconciliation call.', 'wcp-openbiografy'), 'number'),
            'min_confidence_display' => array(__('Confidence flag threshold', 'wcp-openbiografy'), __('Facts below this are visually flagged for careful review. They are never auto-dropped.', 'wcp-openbiografy'), 'float'),
        );
        ?>
        <div class="wrap wcpo-wrap">
            <h1><?php _e('OpenBiografy — Settings', 'wcp-openbiografy'); ?></h1>
            <?php if (isset($_GET['saved'])) : ?>
                <div class="notice notice-success"><p><?php _e('Settings saved.', 'wcp-openbiografy'); ?></p></div>
            <?php endif; ?>
            <?php if (!wcpo_copilot_active()) : ?>
                <div class="notice notice-warning"><p><?php _e('Work Copilot is not active — install and configure it (Settings → AI) to enable AI features.', 'wcp-openbiografy'); ?></p></div>
            <?php else :
                $configured = WCP_AI_Client::instance()->is_configured();
                ?>
                <p><?php echo $configured
                    ? esc_html__('Anthropic API key: configured via Work Copilot ✓', 'wcp-openbiografy')
                    : esc_html__('Anthropic API key: NOT configured — set it in Work Copilot → Settings → AI.', 'wcp-openbiografy'); ?></p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wcpo_save_settings">
                <?php wp_nonce_field('wcpo_save_settings'); ?>
                <table class="form-table">
                    <?php foreach ($fields as $key => $def) :
                        list($label, $description, $type) = $def;
                        $value = wcpo_get_setting($key);
                        ?>
                        <tr>
                            <th scope="row"><label for="wcpo-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
                            <td>
                                <?php if ($type === 'model') : ?>
                                    <select name="<?php echo esc_attr($key); ?>" id="wcpo-<?php echo esc_attr($key); ?>">
                                        <?php foreach ($this->models() as $model_id => $model_label) : ?>
                                            <option value="<?php echo esc_attr($model_id); ?>" <?php selected($value, $model_id); ?>><?php echo esc_html($model_label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($type === 'float') : ?>
                                    <input type="number" step="0.05" min="0" max="1" name="<?php echo esc_attr($key); ?>" id="wcpo-<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>">
                                <?php else : ?>
                                    <input type="number" name="<?php echo esc_attr($key); ?>" id="wcpo-<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>">
                                <?php endif; ?>
                                <?php if ($description) : ?><p class="description"><?php echo esc_html($description); ?></p><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function save() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'wcp-openbiografy'));
        }
        check_admin_referer('wcpo_save_settings');

        $models = array_keys($this->models());
        $settings = get_option('wcpo_settings', array());

        foreach (array('batch_size', 'max_context_chars', 'max_snapshot_chars', 'fetch_timeout', 'max_pdf_mb', 'consolidate_chunk') as $key) {
            if (isset($_POST[$key])) {
                $settings[$key] = max(1, (int) $_POST[$key]);
            }
        }
        if (isset($_POST['min_confidence_display'])) {
            $settings['min_confidence_display'] = max(0, min(1, (float) $_POST['min_confidence_display']));
        }
        foreach (array('model', 'model_draft') as $key) {
            if (isset($_POST[$key]) && in_array($_POST[$key], $models, true)) {
                $settings[$key] = sanitize_text_field($_POST[$key]);
            }
        }

        update_option('wcpo_settings', $settings, false);
        wp_safe_redirect(admin_url('admin.php?page=wcpo-settings&saved=1'));
        exit;
    }
}
