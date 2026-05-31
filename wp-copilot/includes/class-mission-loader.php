<?php
/**
 * Mission Loader
 *
 * Handles loading and resolution of AI missions/personality settings.
 * Implements 4-layer prompt system with global soul + page-specific objectives.
 *
 * @package WorkCopilot
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_Mission_Loader {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get global mission/soul text
     *
     * Priority:
     * 1. /wp-copilot/soul.md (version controlled)
     * 2. wcp_ai_global_mission option (DB fallback)
     *
     * @return string Global mission text with variables substituted
     */
    public function get_global_mission() {
        $mission_text = '';

        // Check for soul.md file first
        $soul_file = WP_PLUGIN_DIR . '/wp-copilot/soul.md';

        if (file_exists($soul_file)) {
            $mission_text = file_get_contents($soul_file);
        } else {
            // Fallback to database option
            $mission_text = get_option('wcp_ai_global_mission', '');
        }

        if (empty($mission_text)) {
            return '';
        }

        // Substitute global variables
        return $this->substitute_variables($mission_text);
    }

    /**
     * Get page-specific objectives with parent inheritance
     *
     * @param int $page_id Page ID
     * @return string Page objectives text with variables substituted
     */
    public function get_page_objectives($page_id) {
        if (empty($page_id)) {
            return '';
        }

        $page = get_post($page_id);
        if (!$page || $page->post_type !== 'page') {
            return '';
        }

        // Get page-specific mission
        $page_mission = get_post_meta($page_id, '_wcp_ai_page_mission', true);
        $inherit_parent = get_post_meta($page_id, '_wcp_ai_mission_inherit_parent', true);

        // If mission is empty and inherit is enabled, check parent
        if (empty($page_mission) && $inherit_parent === '1' && $page->post_parent) {
            return $this->get_page_objectives($page->post_parent);
        }

        if (empty($page_mission)) {
            return '';
        }

        // Substitute variables with page context
        return $this->substitute_variables($page_mission, $page_id);
    }

    /**
     * Substitute variables in mission text
     *
     * Available variables:
     * - {user} - Current user's display name
     * - {role} - Current user's role(s)
     * - {page} - Current page title (if page_id provided)
     * - {parent} - Parent page title (if page_id provided and has parent)
     *
     * @param string $text Text to process
     * @param int $page_id Optional page ID for page-specific variables
     * @return string Text with variables substituted
     */
    public function substitute_variables($text, $page_id = 0) {
        $current_user = wp_get_current_user();

        // User variables
        $replacements = array(
            '{user}' => $current_user->display_name,
            '{role}' => implode(', ', $current_user->roles),
        );

        // Page-specific variables
        if (!empty($page_id)) {
            $page = get_post($page_id);

            if ($page) {
                $replacements['{page}'] = $page->post_title;

                if ($page->post_parent) {
                    $parent = get_post($page->post_parent);
                    $replacements['{parent}'] = $parent ? $parent->post_title : '';
                } else {
                    $replacements['{parent}'] = '';
                }
            }
        } else {
            // No page context, remove page variables
            $replacements['{page}'] = '';
            $replacements['{parent}'] = '';
        }

        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }

    /**
     * Get complete mission context for a page
     *
     * Returns both global and page-specific missions for debugging/display
     *
     * @param int $page_id Page ID
     * @return array ['global' => string, 'page' => string, 'source' => string]
     */
    public function get_mission_context($page_id = 0) {
        $global_mission = $this->get_global_mission();
        $page_objectives = $this->get_page_objectives($page_id);

        // Determine primary source
        $source = 'None';
        if (!empty($global_mission) && !empty($page_objectives)) {
            $source = 'Global Soul + Page Objectives';
        } elseif (!empty($page_objectives)) {
            $source = 'Page Objectives';
        } elseif (!empty($global_mission)) {
            $source = 'Global Soul';
        }

        return array(
            'global' => $global_mission,
            'page' => $page_objectives,
            'source' => $source
        );
    }
}
