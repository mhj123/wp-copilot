<?php
/**
 * REST endpoints (§8) — namespace wcp-wiretap/v1.
 *
 * All actions are nonce-protected (REST cookie auth) and capability-gated.
 * GUARDRAIL (§12.2): every state transition past a proposal (accept, arm,
 * promote) lives HERE, behind a human request — never in cron.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPW_REST_API {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function check_permission() {
        return current_user_can('manage_options');
    }

    public function register_routes() {
        $ns = 'wcp-wiretap/v1';
        $routes = array(
            // KOLs
            'add-kol'           => 'add_kol',
            'import-list'       => 'import_list',
            'promote-kol'       => 'promote_kol',
            'dismiss-kol'       => 'dismiss_kol',
            'update-kol'        => 'update_kol',
            // Recommendations
            'accept-rec'        => 'accept_rec',
            'dismiss-rec'       => 'dismiss_rec',
            'edit-rec'          => 'edit_rec',
            // Trade plans
            'create-plan'       => 'create_plan',
            'arm-plan'          => 'arm_plan',
            'cancel-plan'       => 'cancel_plan',
            'close-plan'        => 'close_plan',
            // AI
            'checkin'           => 'checkin',
            'generate-digest'   => 'generate_digest',
            // Ops
            'run-now'           => 'run_now',
            // Discovery
            'discover-graph'    => 'discover_graph',
            'discover-earliest' => 'discover_earliest',
            // Extras
            'mute-ticker'       => 'mute_ticker',
            'test-connections'  => 'test_connections',
        );
        foreach ($routes as $route => $method) {
            register_rest_route($ns, '/' . $route, array(
                'methods'             => 'POST',
                'callback'            => array($this, $method),
                'permission_callback' => array($this, 'check_permission'),
            ));
        }
    }

    // ------------------------------------------------------------------ KOLs

    public function add_kol($request) {
        $handle = ltrim(sanitize_text_field((string) $request->get_param('handle')), '@');
        if (!$handle) {
            return new WP_Error('bad_request', 'Handle required', array('status' => 400));
        }

        // Soft cap warning (F2) — allowed, but flagged against the budget meter.
        $warning = '';
        $max = (int) wcpw_get_setting('max_kols');
        if (WCPW_KOLs::active_count() >= $max) {
            $warning = sprintf('You now track more than %d KOLs — check the budget meter; polling cost scales linearly.', $max);
        }

        // Resolve x_user_id on save (F2); tolerate API failure (resolved on first fetch).
        $x_user_id = '';
        $user = WCPW_Tweet_Source::resolve_user($handle);
        if (!is_wp_error($user)) {
            $x_user_id = $user['id'];
            $handle = $user['username'];
        } elseif ($user->get_error_code() === 'user_not_found') {
            return new WP_Error('user_not_found', 'No X account found for @' . $handle, array('status' => 404));
        }

        $status = $request->get_param('tracking_status') === 'suggested' ? 'suggested' : 'active';
        $kol_id = WCPW_KOLs::create($handle, array(
            'x_user_id'        => $x_user_id,
            'trust_score'      => (int) ($request->get_param('trust_score') ?: 3),
            'tracking_status'  => $status,
            'discovery_source' => sanitize_text_field((string) $request->get_param('discovery_source')),
            'discovery_reason' => sanitize_text_field((string) $request->get_param('discovery_reason')),
        ));
        if (is_wp_error($kol_id)) {
            return $kol_id;
        }
        return rest_ensure_response(array('success' => true, 'kol' => WCPW_KOLs::meta($kol_id), 'warning' => $warning));
    }

    public function import_list($request) {
        $list_id = sanitize_text_field((string) $request->get_param('list_id'));
        if (!$list_id) {
            return new WP_Error('bad_request', 'List ID required', array('status' => 400));
        }
        $added = WCPW_Ingest::import_list($list_id);
        if (is_wp_error($added)) {
            return $added;
        }
        return rest_ensure_response(array('success' => true, 'imported' => $added));
    }

    public function promote_kol($request) {
        // The ONLY path that starts spending ongoing polling budget (§F3).
        $kol_id = (int) $request->get_param('kol_id');
        WCPW_KOLs::set_status($kol_id, 'active');
        return rest_ensure_response(array('success' => true, 'kol' => WCPW_KOLs::meta($kol_id)));
    }

    public function dismiss_kol($request) {
        // Dismissed suggestions are suppressed from re-suggestion (§F3).
        $kol_id = (int) $request->get_param('kol_id');
        WCPW_KOLs::set_status($kol_id, 'dismissed');
        return rest_ensure_response(array('success' => true));
    }

    public function update_kol($request) {
        $kol_id = (int) $request->get_param('kol_id');
        if (!get_post($kol_id)) {
            return new WP_Error('not_found', 'KOL not found', array('status' => 404));
        }
        $trust = $request->get_param('trust_score');
        if ($trust !== null) {
            update_post_meta($kol_id, '_wcpw_trust_score', max(1, min(5, (int) $trust)));
        }
        $status = $request->get_param('tracking_status');
        if ($status !== null) {
            WCPW_KOLs::set_status($kol_id, sanitize_key($status));
        }
        $notes = $request->get_param('notes');
        if ($notes !== null) {
            update_post_meta($kol_id, '_wcpw_notes', sanitize_textarea_field($notes));
        }
        return rest_ensure_response(array('success' => true, 'kol' => WCPW_KOLs::meta($kol_id)));
    }

    // -------------------------------------------------------- Recommendations

    public function accept_rec($request) {
        $rec_id = (int) $request->get_param('rec_id');
        WCPW_Recommendation_Repo::decide($rec_id, 'accept');
        return rest_ensure_response(array('success' => true));
    }

    public function dismiss_rec($request) {
        $rec_id = (int) $request->get_param('rec_id');
        $reason = sanitize_text_field((string) $request->get_param('reason')); // noise|too_late|dont_trust|other
        WCPW_Recommendation_Repo::decide($rec_id, 'dismiss', $reason);
        return rest_ensure_response(array('success' => true));
    }

    public function edit_rec($request) {
        $rec_id = (int) $request->get_param('rec_id');
        $changes = array();
        foreach (array('direction', 'confidence', 'ticker', 'rationale_excerpt') as $field) {
            $value = $request->get_param($field);
            if ($value !== null) {
                $changes[$field] = $value;
            }
        }
        $applied = WCPW_Recommendation_Repo::edit($rec_id, $changes);
        return rest_ensure_response(array('success' => true, 'applied' => $applied, 'rec' => WCPW_Recommendation_Repo::meta($rec_id)));
    }

    // ------------------------------------------------------------ Trade plans

    public function create_plan($request) {
        $rec_id = (int) $request->get_param('rec_id');
        $plan_id = WCPW_Trade_Plan::propose_from_rec($rec_id);
        if (is_wp_error($plan_id)) {
            return $plan_id;
        }
        return rest_ensure_response(array('success' => true, 'plan' => WCPW_Trade_Plan::meta($plan_id)));
    }

    public function arm_plan($request) {
        $plan_id = (int) $request->get_param('plan_id');
        $result = WCPW_Trade_Plan::arm($plan_id, array(
            'entry_low'    => $request->get_param('entry_low'),
            'entry_high'   => $request->get_param('entry_high'),
            'invalidation' => $request->get_param('invalidation'),
        ));
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response(array('success' => true, 'plan' => WCPW_Trade_Plan::meta($plan_id)));
    }

    public function cancel_plan($request) {
        $result = WCPW_Trade_Plan::transition((int) $request->get_param('plan_id'), 'wcp_cancelled');
        return is_wp_error($result) ? $result : rest_ensure_response(array('success' => true));
    }

    public function close_plan($request) {
        $result = WCPW_Trade_Plan::transition((int) $request->get_param('plan_id'), 'wcp_closed');
        return is_wp_error($result) ? $result : rest_ensure_response(array('success' => true));
    }

    // -------------------------------------------------------------------- AI

    public function checkin($request) {
        $object_id = (int) $request->get_param('object_id');
        $memo = WCPW_Checkin::run($object_id);
        if (is_wp_error($memo)) {
            return $memo;
        }
        return rest_ensure_response(array('success' => true, 'memo' => $memo));
    }

    public function generate_digest($request) {
        $hours = (int) ($request->get_param('window_hours') ?: 24);
        $post_id = WCPW_Digest::generate($hours);
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        return rest_ensure_response(array(
            'success'   => true,
            'post_id'   => $post_id,
            'edit_link' => get_edit_post_link($post_id, ''),
        ));
    }

    // ------------------------------------------------------------------- Ops

    public function run_now($request) {
        $job = sanitize_key((string) $request->get_param('job'));
        switch ($job) {
            case 'fetch':
                WCPW_Ingest::run();
                // fetch chains analyze automatically when it inserted rows
                break;
            case 'analyze':
                WCPW_Analyzer::run_chunk();
                break;
            case 'rollup':
                WCPW_Themes::run_rollup();
                break;
            case 'price_watch':
                WCPW_Trade_Plan::run_price_watch();
                break;
            default:
                return new WP_Error('bad_request', 'Unknown job: ' . $job, array('status' => 400));
        }
        return rest_ensure_response(array('success' => true, 'job' => $job));
    }

    // ------------------------------------------------------------- Discovery

    public function discover_graph($request) {
        // Cost-confirmed in the UI (§F3.2) — the endpoint just does the work.
        $kol_id = (int) $request->get_param('kol_id');
        $result = WCPW_Discovery::graph_triangulate($kol_id);
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response(array_merge(array('success' => true), $result));
    }

    public function discover_earliest($request) {
        // Cost-confirmed in the UI (§F3.3).
        $ticker = sanitize_text_field((string) $request->get_param('ticker'));
        if (!$ticker) {
            return new WP_Error('bad_request', 'Ticker required', array('status' => 400));
        }
        $result = WCPW_Discovery::earliest_callers(
            $ticker,
            sanitize_text_field((string) $request->get_param('start_date')),
            sanitize_text_field((string) $request->get_param('end_date'))
        );
        return rest_ensure_response(array_merge(array('success' => true), $result));
    }

    // ---------------------------------------------------------------- Extras

    public function mute_ticker($request) {
        // Suppresses ALERTS for a ticker for N days; ingestion continues.
        $ticker = strtoupper(ltrim(sanitize_text_field((string) $request->get_param('ticker')), '$'));
        $days = max(0, (int) ($request->get_param('days') ?: 7));
        $muted = get_option('wcpw_muted_tickers', array());
        if ($days === 0) {
            unset($muted[$ticker]);
        } else {
            $muted[$ticker] = time() + $days * DAY_IN_SECONDS;
        }
        update_option('wcpw_muted_tickers', $muted, false);
        return rest_ensure_response(array('success' => true, 'muted' => $muted));
    }

    public function test_connections($request) {
        $which = sanitize_key((string) $request->get_param('which'));
        switch ($which) {
            case 'telegram':
                $result = WCPW_Telegram::test();
                break;
            case 'x':
                $result = WCPW_Tweet_Source::resolve_user('x'); // any public account proves auth
                break;
            case 'anthropic':
                $result = WCPW_LLM::call('classification', 'Reply with JSON {"ok":true}', 'ping', array(
                    'tier' => 'fast', 'max_tokens' => 50, 'required' => array('ok'),
                ));
                break;
            default:
                return new WP_Error('bad_request', 'Unknown test', array('status' => 400));
        }
        if (is_wp_error($result)) {
            return rest_ensure_response(array('success' => false, 'message' => $result->get_error_message()));
        }
        return rest_ensure_response(array('success' => true));
    }
}
