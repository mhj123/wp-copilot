<?php
/**
 * Researcher Mode provisioning.
 *
 * Build 0 plumbing only: creates/adopts a native Library page and stores a
 * page-template definition on it so child paper pages inherit evidence headings.
 * No research AI actions are wired here; future actions should only check the
 * persistent wcp_researcher_mode_active flag before surfacing/running.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_Researcher_Mode {

    const OPTION_ACTIVE               = 'wcp_researcher_mode_active';
    const OPTION_LIBRARY_ID           = 'wcp_researcher_library_page_id';
    const OPTION_RESEARCH_ROOT_ID     = 'wcp_researcher_research_root_id';
    const OPTION_TEMPLATE_VER         = 'wcp_researcher_template_version';
    const OPTION_PROJECT_TEMPLATE_VER = 'wcp_researcher_project_template_version';
    const TEMPLATE_VERSION            = '2026-08-build0.1';
    const PROJECT_TEMPLATE_VERSION    = '2026-08-build0.7';
    const LIBRARY_TITLE               = 'Library';
    const RESEARCH_ROOT_TITLE         = 'Research';

    private static $instance = null;

    /**
     * The evidence-heading contract downstream research builds depend on.
     * Keep this as the single source of truth for easy iteration.
     */
    private static $evidence_headings = array(
        'Summary',
        'Findings',
        'Notes',
    );

    /**
     * Project-page heading contract for pages created under the Research root.
     * Keep this as the single source of truth for Build 0.5. Description/
     * Objectives/Context deliberately live as content_blocks (page body
     * sections) in project_template() instead, not as real wcp_heading
     * posts — they're project framing prose, not a growing list that needs
     * the Heading+Items mechanism the way Sources does.
     */
    private static $project_headings = array(
        'Sources',
    );

    /**
     * Content sections (page-body prose, not structural headings) for
     * pages created under the Research root. Rendered into post_content
     * by WCP_Page_Template_Manager::apply_template().
     */
    private static $project_content_blocks = array(
        array('title' => 'Description', 'level' => 'h2', 'placeholder' => 'What is this research project about?'),
        array('title' => 'Objectives',   'level' => 'h2', 'placeholder' => 'What are you trying to find out or achieve?'),
        array('title' => 'Context',      'level' => 'h2', 'placeholder' => 'Background, prior work, or why this project exists now.'),
    );

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public static function is_active() {
        return (bool) get_option(self::OPTION_ACTIVE, false);
    }

    public static function evidence_headings() {
        return self::$evidence_headings;
    }

    public static function project_headings() {
        return self::$project_headings;
    }

    public static function project_content_blocks() {
        return self::$project_content_blocks;
    }

    public function enable() {
        if (!current_user_can('manage_options')) {
            return new WP_Error('forbidden', __('Only administrators can enable Researcher mode.', 'work-copilot'));
        }

        $library_id = $this->ensure_library_page();
        if (is_wp_error($library_id)) {
            return $library_id;
        }

        $template_result = $this->ensure_research_template($library_id);
        if (is_wp_error($template_result)) {
            return $template_result;
        }

        $research_root_id = $this->ensure_research_root_page();
        if (is_wp_error($research_root_id)) {
            return $research_root_id;
        }

        $project_template_result = $this->ensure_project_template($research_root_id);
        if (is_wp_error($project_template_result)) {
            return $project_template_result;
        }

        update_option(self::OPTION_LIBRARY_ID, (int) $library_id, false);
        update_option(self::OPTION_RESEARCH_ROOT_ID, (int) $research_root_id, false);
        update_option(self::OPTION_TEMPLATE_VER, self::TEMPLATE_VERSION, false);
        update_option(self::OPTION_PROJECT_TEMPLATE_VER, self::PROJECT_TEMPLATE_VERSION, false);

        return array(
            'library_id'            => (int) $library_id,
            'research_root_id'      => (int) $research_root_id,
            'template'              => $template_result,
            'project_template'      => $project_template_result,
        );
    }

    public function disable() {
        if (!current_user_can('manage_options')) {
            return new WP_Error('forbidden', __('Only administrators can disable Researcher mode.', 'work-copilot'));
        }

        // Deliberately delete nothing. The settings option is the feature gate;
        // Library, templates, headings and child papers remain intact.
        return true;
    }

    public function get_library_page_id() {
        $stored_id = (int) get_option(self::OPTION_LIBRARY_ID, 0);
        if ($stored_id) {
            $stored = get_post($stored_id);
            if ($stored && $stored->post_type === 'page' && $stored->post_status !== 'trash') {
                return $stored_id;
            }
        }

        return $this->find_library_page_id();
    }

    public function get_research_root_page_id() {
        $stored_id = (int) get_option(self::OPTION_RESEARCH_ROOT_ID, 0);
        if ($stored_id) {
            $stored = get_post($stored_id);
            if ($stored && $stored->post_type === 'page' && $stored->post_status !== 'trash') {
                return $stored_id;
            }
        }

        return $this->find_research_root_page_id();
    }

    private function ensure_library_page() {
        $existing_id = $this->get_library_page_id();
        if ($existing_id) {
            return $existing_id;
        }

        return $this->create_root_page(self::LIBRARY_TITLE);
    }

    private function ensure_research_root_page() {
        $existing_id = $this->get_research_root_page_id();
        if ($existing_id) {
            return $existing_id;
        }

        return $this->create_root_page(self::RESEARCH_ROOT_TITLE);
    }

    private function create_root_page($title) {
        $page_id = wp_insert_post(array(
            'post_type'    => 'page',
            'post_title'   => $title,
            'post_name'    => sanitize_title($title),
            'post_status'  => 'publish',
            'post_author'  => get_current_user_id() ?: 1,
            'post_content' => '',
        ), true);

        if (is_wp_error($page_id)) {
            return $page_id;
        }

        return (int) $page_id;
    }

    private function find_library_page_id() {
        return $this->find_root_page_id(self::LIBRARY_TITLE);
    }

    private function find_research_root_page_id() {
        return $this->find_root_page_id(self::RESEARCH_ROOT_TITLE);
    }

    private function find_root_page_id($title) {
        // Deliberately non-destructive: if the site already has a top-level
        // page titled/slugged "Library" or "Research", Researcher Mode adopts
        // that page and stamps the template there rather than creating another.
        $pages = get_posts(array(
            'post_type'              => 'page',
            'post_status'            => array('publish', 'draft', 'private'),
            'title'                  => $title,
            'posts_per_page'         => 1,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ));

        if (!empty($pages)) {
            return (int) $pages[0]->ID;
        }

        $by_path = get_page_by_path(sanitize_title($title));
        if ($by_path && $by_path->post_type === 'page' && $by_path->post_status !== 'trash') {
            return (int) $by_path->ID;
        }

        return 0;
    }

    private function ensure_research_template($library_id) {
        $template = $this->research_template();
        $encoded  = wp_json_encode($template);
        if (!$encoded) {
            return new WP_Error('template_encode_failed', __('Could not encode the Researcher mode page template.', 'work-copilot'));
        }

        $existing = get_post_meta($library_id, '_wcp_page_template', true);
        if ($existing === $encoded) {
            return 'unchanged';
        }

        update_post_meta($library_id, '_wcp_page_template', $encoded);
        update_post_meta($library_id, '_wcp_researcher_template', '1');

        return empty($existing) ? 'created' : 'updated';
    }

    private function ensure_project_template($research_root_id) {
        $template = $this->project_template();
        $encoded  = wp_json_encode($template);
        if (!$encoded) {
            return new WP_Error('project_template_encode_failed', __('Could not encode the Researcher mode project page template.', 'work-copilot'));
        }

        $existing = get_post_meta($research_root_id, '_wcp_page_template', true);
        if ($existing === $encoded) {
            return 'unchanged';
        }

        update_post_meta($research_root_id, '_wcp_page_template', $encoded);
        update_post_meta($research_root_id, '_wcp_researcher_project_template', '1');

        return empty($existing) ? 'created' : 'updated';
    }

    public function research_template() {
        $headings = array();
        $order = 0;
        foreach (self::evidence_headings() as $title) {
            $headings[] = array(
                'title'       => $title,
                'placeholder' => '',
                'items'       => array(),
                'menu_order'  => $order,
            );
            $order += 10;
        }

        return array(
            'content_blocks' => array(),
            'headings'       => $headings,
        );
    }

    public function project_template() {
        $headings = array();
        $order = 0;
        foreach (self::project_headings() as $title) {
            $headings[] = array(
                'title'       => $title,
                'placeholder' => '',
                'items'       => array(),
                'menu_order'  => $order,
            );
            $order += 10;
        }

        return array(
            'content_blocks' => self::project_content_blocks(),
            'headings'       => $headings,
        );
    }
}

/**
 * Public seam for future research features.
 */
function wcp_researcher_mode_enabled() {
    return WCP_Researcher_Mode::is_active();
}
