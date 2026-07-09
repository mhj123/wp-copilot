<?php
/**
 * REST endpoints — namespace wcp-openbiografy/v1.
 *
 * All actions are nonce-protected (REST cookie auth) and capability-gated.
 * GUARDRAIL: every state transition past a proposal (accept-fact,
 * accept-event, apply-assignments, accept-draft) lives HERE, behind a human
 * request — never in cron, never inside an AI call.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPO_REST_API {

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
        $ns = 'wcp-openbiografy/v1';

        $post_routes = array(
            // People
            'add-person'          => 'add_person',
            'update-person'       => 'update_person',
            // Sources
            'add-sources'         => 'add_sources',
            'add-document-source' => 'add_document_source',
            'update-source'       => 'update_source',
            'retry-source'        => 'retry_source',
            'delete-source'       => 'delete_source',
            // Pipeline (process exactly ONE item; the dashboard JS loops N)
            'fetch-next'          => 'fetch_next',
            'extract-next'        => 'extract_next',
            'consolidate-next'    => 'consolidate_next',
            // Fact review
            'accept-fact'         => 'accept_fact',
            'dismiss-fact'        => 'dismiss_fact',
            'edit-fact'           => 'edit_fact',
            'accept-source-facts' => 'accept_source_facts',
            // Event review
            'accept-event'        => 'accept_event',
            'dismiss-event'       => 'dismiss_event',
            'edit-event'          => 'edit_event',
            // Chapters
            'create-chapter'      => 'create_chapter',
            'update-chapter'      => 'update_chapter',
            'reorder-chapters'    => 'reorder_chapters',
            'suggest-assignments' => 'suggest_assignments',
            'apply-assignments'   => 'apply_assignments',
            'draft-chapter'       => 'draft_chapter',
            'accept-draft'        => 'accept_draft',
            'dismiss-draft'       => 'dismiss_draft',
        );
        foreach ($post_routes as $route => $method) {
            register_rest_route($ns, '/' . $route, array(
                'methods'             => 'POST',
                'callback'            => array($this, $method),
                'permission_callback' => array($this, 'check_permission'),
            ));
        }

        foreach (array('status' => 'status', 'export-json' => 'export_json') as $route => $method) {
            register_rest_route($ns, '/' . $route, array(
                'methods'             => 'GET',
                'callback'            => array($this, $method),
                'permission_callback' => array($this, 'check_permission'),
            ));
        }
    }

    private function person_id($request) {
        return (int) $request->get_param('person_id');
    }

    // ---------------------------------------------------------------- Status

    public function status($request) {
        $person_id = $this->person_id($request);
        if (!$person_id) {
            return new WP_Error('bad_request', 'person_id required', array('status' => 400));
        }
        $source_counts = WCPO_Source_Repo::counts($person_id);
        $fact_counts   = WCPO_Fact_Repo::counts($person_id);
        $event_counts  = WCPO_Event_Repo::counts($person_id);

        // Eval-style warnings (PRD §19) — cheap structural checks, surfaced,
        // never auto-resolved.
        $warnings = array();
        if ($source_counts['fetch_failed']) {
            $warnings[] = sprintf(_n('%d source failed to fetch — retry or paste its text manually.', '%d sources failed to fetch — retry or paste their text manually.', $source_counts['fetch_failed'], 'wcp-openbiografy'), $source_counts['fetch_failed']);
        }
        if ($source_counts['extract_failed']) {
            $warnings[] = sprintf(__('%d sources failed extraction — retry from the source table.', 'wcp-openbiografy'), $source_counts['extract_failed']);
        }
        $low = WCPO_Fact_Repo::low_confidence_proposed($person_id);
        if ($low) {
            $warnings[] = sprintf(__('%d proposed facts are below the confidence threshold — flagged for careful review, never dropped.', 'wcp-openbiografy'), $low);
        }
        if ($event_counts['contested']) {
            $warnings[] = sprintf(__('%d accepted events are contested — sources disagree; the conflict is preserved.', 'wcp-openbiografy'), $event_counts['contested']);
        }
        $draft_warnings = get_option('wcpo_last_draft_warnings', array());
        foreach ((array) $draft_warnings as $w) {
            $warnings[] = $w;
        }

        return rest_ensure_response(array(
            'success'  => true,
            'sources'  => $source_counts,
            'facts'    => $fact_counts,
            'events'   => $event_counts,
            'warnings' => $warnings,
        ));
    }

    // ---------------------------------------------------------------- People

    public function add_person($request) {
        $name = sanitize_text_field((string) $request->get_param('name'));
        if (!$name) {
            return new WP_Error('bad_request', 'Name required', array('status' => 400));
        }
        $person_id = WCPO_Person_Repo::create($name, $this->person_fields($request));
        if (is_wp_error($person_id)) {
            return $person_id;
        }
        return rest_ensure_response(array('success' => true, 'person' => WCPO_Person_Repo::meta($person_id)));
    }

    public function update_person($request) {
        $person_id = $this->person_id($request);
        $fields = $this->person_fields($request);
        if ($request->get_param('name') !== null) {
            $fields['name'] = (string) $request->get_param('name');
        }
        $result = WCPO_Person_Repo::update($person_id, $fields);
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response(array('success' => true, 'person' => WCPO_Person_Repo::meta($person_id)));
    }

    private function person_fields($request) {
        $fields = array();
        foreach (WCPO_Person_Repo::FIELDS as $field) {
            $value = $request->get_param($field);
            if ($value !== null) {
                $fields[$field] = (string) $value;
            }
        }
        return $fields;
    }

    // --------------------------------------------------------------- Sources

    public function add_sources($request) {
        $person_id = $this->person_id($request);
        if (!$person_id) {
            return new WP_Error('bad_request', 'person_id required', array('status' => 400));
        }
        $urls = preg_split('/[\r\n]+/', (string) $request->get_param('urls'));
        $created = 0;
        $skipped = array();
        foreach ($urls as $url) {
            $url = trim($url);
            if ($url === '') {
                continue;
            }
            $result = WCPO_Source_Repo::create_from_url($person_id, $url);
            if (is_wp_error($result)) {
                $skipped[] = $result->get_error_message();
            } else {
                $created++;
            }
        }
        return rest_ensure_response(array('success' => true, 'created' => $created, 'skipped' => $skipped));
    }

    public function add_document_source($request) {
        $person_id     = $this->person_id($request);
        $attachment_id = (int) $request->get_param('attachment_id');
        if (!$person_id || !$attachment_id) {
            return new WP_Error('bad_request', 'person_id and attachment_id required', array('status' => 400));
        }
        $source_id = WCPO_Source_Repo::create_from_attachment($person_id, $attachment_id);
        if (is_wp_error($source_id)) {
            return $source_id;
        }
        return rest_ensure_response(array('success' => true, 'source' => WCPO_Source_Repo::meta($source_id)));
    }

    public function update_source($request) {
        $source_id = (int) $request->get_param('source_id');
        if (!WCPO_Source_Repo::meta($source_id)) {
            return new WP_Error('not_found', 'Source not found', array('status' => 404));
        }
        $cite = array();
        foreach (WCPO_Source_Repo::CITE_FIELDS as $field) {
            $value = $request->get_param($field);
            if ($value !== null) {
                $cite[$field] = (string) $value;
            }
        }
        if ($cite) {
            WCPO_Source_Repo::save_citation($source_id, $cite, true); // human edit — overwrite
        }
        // Manual text fallback for JS-rendered / paywalled pages.
        $text = $request->get_param('text');
        if ($text !== null && trim((string) $text) !== '') {
            WCPO_Source_Repo::save_snapshot($source_id, sanitize_textarea_field((string) $text));
            WCPO_Source_Repo::set_fetch_status($source_id, 'fetched');
        }
        return rest_ensure_response(array('success' => true, 'source' => WCPO_Source_Repo::meta($source_id)));
    }

    public function retry_source($request) {
        $source_id = (int) $request->get_param('source_id');
        $status = WCPO_Source_Repo::retry($source_id);
        return rest_ensure_response(array('success' => true, 'fetch_status' => $status));
    }

    public function delete_source($request) {
        $source_id = (int) $request->get_param('source_id');
        if (!WCPO_Source_Repo::meta($source_id)) {
            return new WP_Error('not_found', 'Source not found', array('status' => 404));
        }
        $trashed_facts = WCPO_Source_Repo::delete($source_id);
        return rest_ensure_response(array('success' => true, 'trashed_proposed_facts' => $trashed_facts));
    }

    // -------------------------------------------------------------- Pipeline

    public function fetch_next($request) {
        $person_id = $this->person_id($request);
        $source = WCPO_Source_Repo::next_with_status($person_id, 'new');
        if (!$source) {
            return rest_ensure_response(array('success' => true, 'done' => true, 'remaining' => 0));
        }
        $result = WCPO_Fetcher::fetch($source->ID);
        $remaining = WCPO_Source_Repo::count_with_status($person_id, 'new');
        return rest_ensure_response(array(
            'success'   => !is_wp_error($result),
            'done'      => false,
            'source'    => WCPO_Source_Repo::meta($source->ID),
            'error'     => is_wp_error($result) ? $result->get_error_message() : '',
            'remaining' => $remaining,
        ));
    }

    public function extract_next($request) {
        $person_id = $this->person_id($request);
        $source = WCPO_Source_Repo::next_with_status($person_id, 'fetched');
        if (!$source) {
            return rest_ensure_response(array('success' => true, 'done' => true, 'remaining' => 0));
        }
        $result = WCPO_Extractor::extract($source->ID);
        $remaining = WCPO_Source_Repo::count_with_status($person_id, 'fetched');
        return rest_ensure_response(array(
            'success'       => !is_wp_error($result),
            'done'          => false,
            'source'        => WCPO_Source_Repo::meta($source->ID),
            'facts_created' => is_wp_error($result) ? 0 : $result['facts_created'],
            'error'         => is_wp_error($result) ? $result->get_error_message() : '',
            'remaining'     => $remaining,
        ));
    }

    public function consolidate_next($request) {
        $person_id = $this->person_id($request);
        if (!$person_id) {
            return new WP_Error('bad_request', 'person_id required', array('status' => 400));
        }
        $result = WCPO_Reconciler::consolidate($person_id);
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response(array_merge(array('success' => true, 'done' => $result['facts_considered'] === 0), $result));
    }

    // ----------------------------------------------------------- Fact review

    public function accept_fact($request) {
        $fact_id = (int) $request->get_param('fact_id');
        if (!WCPO_Fact_Repo::meta($fact_id)) {
            return new WP_Error('not_found', 'Fact not found', array('status' => 404));
        }
        // Optional inline edits are applied (and diff-logged) before acceptance.
        $edits = $this->fact_edit_params($request);
        if ($edits) {
            $result = WCPO_Fact_Repo::edit($fact_id, $edits);
            if (is_wp_error($result)) {
                return $result;
            }
        }
        WCPO_Fact_Repo::decide($fact_id, 'accept');
        return rest_ensure_response(array('success' => true, 'fact' => WCPO_Fact_Repo::meta($fact_id)));
    }

    public function dismiss_fact($request) {
        $fact_id = (int) $request->get_param('fact_id');
        if (!WCPO_Fact_Repo::meta($fact_id)) {
            return new WP_Error('not_found', 'Fact not found', array('status' => 404));
        }
        WCPO_Fact_Repo::decide($fact_id, 'dismiss', (string) $request->get_param('reason'));
        return rest_ensure_response(array('success' => true));
    }

    public function edit_fact($request) {
        $fact_id = (int) $request->get_param('fact_id');
        if (!WCPO_Fact_Repo::meta($fact_id)) {
            return new WP_Error('not_found', 'Fact not found', array('status' => 404));
        }
        $result = WCPO_Fact_Repo::edit($fact_id, $this->fact_edit_params($request));
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response(array('success' => true, 'changed' => $result, 'fact' => WCPO_Fact_Repo::meta($fact_id)));
    }

    private function fact_edit_params($request) {
        $edits = array();
        foreach (array('claim', 'date_edtf', 'place', 'quote', 'locator', 'confidence', 'kind') as $field) {
            $value = $request->get_param($field);
            if ($value !== null) {
                $edits[$field] = $value;
            }
        }
        return $edits;
    }

    public function accept_source_facts($request) {
        $source_id = (int) $request->get_param('source_id');
        if (!WCPO_Source_Repo::meta($source_id)) {
            return new WP_Error('not_found', 'Source not found', array('status' => 404));
        }
        $accepted = WCPO_Fact_Repo::accept_all_for_source($source_id);
        return rest_ensure_response(array('success' => true, 'accepted' => $accepted));
    }

    // ---------------------------------------------------------- Event review

    public function accept_event($request) {
        $event_id = (int) $request->get_param('event_id');
        $result = WCPO_Event_Repo::decide($event_id, 'accept');
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response(array('success' => true, 'event' => WCPO_Event_Repo::meta($event_id)));
    }

    public function dismiss_event($request) {
        $event_id = (int) $request->get_param('event_id');
        $result = WCPO_Event_Repo::decide($event_id, 'dismiss', (string) $request->get_param('reason'));
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response(array('success' => true));
    }

    public function edit_event($request) {
        $event_id = (int) $request->get_param('event_id');
        $edits = array();
        foreach (array('title', 'description', 'date_edtf', 'place', 'kind', 'contested', 'contested_note', 'importance') as $field) {
            $value = $request->get_param($field);
            if ($value !== null) {
                $edits[$field] = $value;
            }
        }
        $result = WCPO_Event_Repo::edit($event_id, $edits);
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response(array('success' => true, 'changed' => $result, 'event' => WCPO_Event_Repo::meta($event_id)));
    }

    // -------------------------------------------------------------- Chapters

    public function create_chapter($request) {
        $person_id = $this->person_id($request);
        $title = sanitize_text_field((string) $request->get_param('title'));
        if (!$person_id || !$title) {
            return new WP_Error('bad_request', 'person_id and title required', array('status' => 400));
        }
        $chapter_id = WCPO_Chapter_Repo::create($person_id, $title, (string) $request->get_param('period_edtf'));
        if (is_wp_error($chapter_id)) {
            return $chapter_id;
        }
        return rest_ensure_response(array('success' => true, 'chapter' => WCPO_Chapter_Repo::meta($chapter_id)));
    }

    public function update_chapter($request) {
        $chapter_id = (int) $request->get_param('chapter_id');
        if (!WCPO_Chapter_Repo::meta($chapter_id)) {
            return new WP_Error('not_found', 'Chapter not found', array('status' => 404));
        }
        $fields = array();
        if ($request->get_param('title') !== null) {
            $fields['title'] = (string) $request->get_param('title');
        }
        if ($request->get_param('period_edtf') !== null) {
            $fields['period_edtf'] = (string) $request->get_param('period_edtf');
        }
        if ($request->get_param('publish') !== null) {
            $fields['publish'] = (bool) $request->get_param('publish');
        }
        $result = WCPO_Chapter_Repo::update($chapter_id, $fields);
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response(array('success' => true, 'chapter' => WCPO_Chapter_Repo::meta($chapter_id)));
    }

    public function reorder_chapters($request) {
        $person_id = $this->person_id($request);
        $order = array_map('intval', (array) $request->get_param('order'));
        WCPO_Chapter_Repo::reorder($person_id, $order);
        return rest_ensure_response(array('success' => true));
    }

    public function suggest_assignments($request) {
        $person_id = $this->person_id($request);
        $result = WCPO_Chapter_AI::suggest_assignments($person_id);
        if (is_wp_error($result)) {
            return $result;
        }
        // Suggestions are NOT persisted — the human applies them explicitly.
        return rest_ensure_response(array_merge(array('success' => true), $result));
    }

    public function apply_assignments($request) {
        $pairs = (array) $request->get_param('pairs');
        $action_id = sanitize_text_field((string) $request->get_param('action_id'));
        $applied = array();
        foreach ($pairs as $pair) {
            $event_id   = isset($pair['event_id']) ? (int) $pair['event_id'] : 0;
            $chapter_id = isset($pair['chapter_id']) ? (int) $pair['chapter_id'] : 0;
            $event   = WCPO_Event_Repo::meta($event_id);
            $chapter = WCPO_Chapter_Repo::meta($chapter_id);
            if ($event && $chapter && $event['person_id'] === $chapter['person_id']) {
                update_post_meta($event_id, '_wcpo_chapter_id', $chapter_id);
                $applied[] = $event_id;
            }
        }
        if ($action_id && wcpo_copilot_active()) {
            WCP_AI_Logger::instance()->log_decisions($action_id, $applied, array());
        }
        return rest_ensure_response(array('success' => true, 'applied' => count($applied)));
    }

    public function draft_chapter($request) {
        $chapter_id = (int) $request->get_param('chapter_id');
        $result = WCPO_Chapter_AI::draft($chapter_id);
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response(array_merge(array('success' => true), $result));
    }

    public function accept_draft($request) {
        $chapter_id = (int) $request->get_param('chapter_id');
        $text = (string) $request->get_param('text');
        if (trim($text) === '') {
            return new WP_Error('bad_request', 'Draft text required', array('status' => 400));
        }
        $warnings = WCPO_Chapter_Repo::accept_draft($chapter_id, $text);
        if (is_wp_error($warnings)) {
            return $warnings;
        }
        return rest_ensure_response(array('success' => true, 'warnings' => $warnings, 'chapter' => WCPO_Chapter_Repo::meta($chapter_id)));
    }

    public function dismiss_draft($request) {
        $chapter_id = (int) $request->get_param('chapter_id');
        $result = WCPO_Chapter_Repo::dismiss_draft($chapter_id, (string) $request->get_param('reason'));
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response(array('success' => true));
    }

    // ---------------------------------------------------------------- Export

    public function export_json($request) {
        $person_id = $this->person_id($request);
        $data = WCPO_Exporter::project_json($person_id);
        if (is_wp_error($data)) {
            return $data;
        }
        return rest_ensure_response($data);
    }
}
