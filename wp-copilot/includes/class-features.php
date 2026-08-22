<?php
/**
 * Feature flags.
 *
 * One registry of experimental and deferred features, so the shipped release
 * and the author's working instance can be the same codebase differing only by
 * configuration. There is no long-lived experimental branch to keep merged.
 *
 * Flags are configuration, not user settings. They are set with constants in
 * wp-config.php and have no admin UI on purpose — a release should not offer
 * users switches for features that are unfinished, and reviewers should not
 * find half-built surfaces behind a toggle.
 *
 * Turn on a single feature:
 *
 *     define('WCP_FEATURE_PAGE_TEMPLATES', true);
 *
 * Turn on everything not enabled by default (development instances):
 *
 *     define('WCP_EXPERIMENTAL', true);
 *
 * A per-feature constant always beats the master switch, so an experimental
 * instance can still hold one feature off:
 *
 *     define('WCP_EXPERIMENTAL', true);
 *     define('WCP_FEATURE_PAGE_SCHEDULER', false);
 *
 * NOTE: flags gate user-facing surfaces, never schema. Anything that creates
 * or migrates a table must run regardless of its flag, or a flagged-off
 * install and a flagged-on one drift apart at the database level and no
 * migration can reconcile them afterwards.
 *
 * This is deliberately NOT wired to wcp_ai_enabled or wcp_embeddings_enabled.
 * Those are legitimate user settings that happen to be booleans; folding them
 * in here would turn a user's preference into a developer's switch.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_Features {

    /** Prefix for per-feature override constants. */
    const CONSTANT_PREFIX = 'WCP_FEATURE_';

    /** @var array|null Lazily built registry. */
    private static $registry = null;

    /** @var array Resolved values, memoised per request. */
    private static $resolved = array();

    /**
     * The feature registry.
     *
     * 'default' is what a stock install gets. Everything defaulting to false
     * is code that ships but stays dark until someone opts in.
     *
     * @return array<string, array{label: string, description: string, default: bool}>
     */
    public static function registry() {
        if (self::$registry !== null) {
            return self::$registry;
        }

        $registry = array(
            'spec_status' => array(
                'label'       => __('Spec status taxonomy', 'work-copilot'),
                'description' => __('draft/review/final workflow state, shown only on spec items.', 'work-copilot'),
                'default'     => false,
            ),
            'thinking_budget' => array(
                'label'       => __('Thinking budget selector', 'work-copilot'),
                'description' => __('Per-request reasoning budget control in the AI widget.', 'work-copilot'),
                'default'     => false,
            ),
            'auto_route' => array(
                'label'       => __('Auto-route intent detection', 'work-copilot'),
                'description' => __('Infers which AI action to run instead of asking. Explicit selection is the default.', 'work-copilot'),
                'default'     => false,
            ),
            'generate_headings' => array(
                'label'       => __('Generate headings', 'work-copilot'),
                'description' => __('Headings-only generation. Overlaps generate structure.', 'work-copilot'),
                'default'     => false,
            ),
            'fetch_posts' => array(
                'label'       => __('Fetch posts', 'work-copilot'),
                'description' => __('Interpret-and-execute retrieval of existing posts into a page.', 'work-copilot'),
                'default'     => false,
            ),
            'fetch_structure' => array(
                'label'       => __('Fetch structure', 'work-copilot'),
                'description' => __('Structure retrieval via chat. Corpus-context chat already covers this.', 'work-copilot'),
                'default'     => false,
            ),
            'coaching_dialogue' => array(
                'label'       => __('Coaching dialogue', 'work-copilot'),
                'description' => __('Multi-turn coaching action. Revisit post-launch as a prompt preset.', 'work-copilot'),
                'default'     => false,
            ),
            'mission_priorities' => array(
                'label'       => __('Mission priorities', 'work-copilot'),
                'description' => __('Derives priorities from the page mission. Surface is unfinished.', 'work-copilot'),
                'default'     => false,
            ),
            'goals' => array(
                'label'       => __('Goals', 'work-copilot'),
                'description' => __('Two-step goal planning, goal creation, and convert-item-to-goal.', 'work-copilot'),
                'default'     => false,
            ),
            'suggest_subtasks' => array(
                'label'       => __('Suggest subtasks', 'work-copilot'),
                'description' => __('Standalone subtask suggestion. Action plan already accepts as subtasks.', 'work-copilot'),
                'default'     => false,
            ),
            'page_templates' => array(
                'label'       => __('Page templates', 'work-copilot'),
                'description' => __('Template system for page structure. Phases 2 and 3 are unbuilt.', 'work-copilot'),
                'default'     => false,
            ),
            'pdf_summary' => array(
                'label'       => __('PDF summary import', 'work-copilot'),
                'description' => __('Upload a PDF and ask Claude to propose a reviewed summary ItemPost.', 'work-copilot'),
                'default'     => false,
            ),
            'page_scheduler' => array(
                'label'       => __('Page scheduler', 'work-copilot'),
                'description' => __('Cron-created pages, and the upcoming-scheduled-pages card on the homepage. Unattended creation needs reconciling with the human-in-the-loop guarantee before this ships.', 'work-copilot'),
                'default'     => false,
            ),
            'section_duplicate' => array(
                'label'       => __('Section duplicate', 'work-copilot'),
                'description' => __('Duplicate a section as a fresh checklist. Belongs with the template system.', 'work-copilot'),
                'default'     => false,
            ),
            'page_notes' => array(
                'label'       => __('Page notes metabox', 'work-copilot'),
                'description' => __('A third free-text field on pages, alongside mission and description.', 'work-copilot'),
                'default'     => false,
            ),
            'admin_dashboard' => array(
                'label'       => __('Admin dashboard', 'work-copilot'),
                'description' => __('wp-admin dashboard screen. The workspace dashboard is the product.', 'work-copilot'),
                'default'     => false,
            ),
            'structure_tree' => array(
                'label'       => __('Structure tree panel', 'work-copilot'),
                'description' => __('Homepage structure tree. Redundant with the sidebar navigation.', 'work-copilot'),
                'default'     => false,
            ),
        );

        /**
         * Filter the feature registry.
         *
         * Add-ons may register their own flags. Use this rather than editing
         * the array above, so core stays the single source of truth for core.
         *
         * @param array $registry
         */
        self::$registry = apply_filters('wcp_feature_registry', $registry);

        return self::$registry;
    }

    /**
     * Whether the master experimental switch is on.
     *
     * @return bool
     */
    public static function is_experimental_mode() {
        return defined('WCP_EXPERIMENTAL') && WCP_EXPERIMENTAL;
    }

    /**
     * Whether a feature is enabled.
     *
     * Resolution order, first match wins:
     *   1. WCP_FEATURE_<SLUG> constant, if defined
     *   2. WCP_EXPERIMENTAL, if on — enables anything defaulting to false
     *   3. the registry default
     *
     * The result then passes through the wcp_feature_enabled filter.
     *
     * An unknown slug is false. That is the safe direction: a typo hides a
     * feature rather than silently exposing an unfinished one.
     *
     * @param string $slug
     * @return bool
     */
    public static function enabled($slug) {
        if (isset(self::$resolved[$slug])) {
            return self::$resolved[$slug];
        }

        $registry = self::registry();

        if (!isset($registry[$slug])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                _doing_it_wrong(
                    __METHOD__,
                    sprintf(
                        /* translators: %s: feature flag slug */
                        esc_html__('Unknown feature flag "%s".', 'work-copilot'),
                        esc_html($slug)
                    ),
                    '1.3.0'
                );
            }
            return false;
        }

        $constant = self::CONSTANT_PREFIX . strtoupper($slug);

        if (defined($constant)) {
            $enabled = (bool) constant($constant);
        } elseif (self::is_experimental_mode()) {
            $enabled = true;
        } else {
            $enabled = (bool) $registry[$slug]['default'];
        }

        /**
         * Filter whether a single feature is enabled.
         *
         * @param bool   $enabled
         * @param string $slug
         * @param array  $feature Registry entry.
         */
        $enabled = (bool) apply_filters('wcp_feature_enabled', $enabled, $slug, $registry[$slug]);

        self::$resolved[$slug] = $enabled;

        return $enabled;
    }

    /**
     * Every flag with its resolved state. Useful for support output.
     *
     * @return array<string, bool>
     */
    public static function all() {
        $out = array();
        foreach (array_keys(self::registry()) as $slug) {
            $out[$slug] = self::enabled($slug);
        }
        return $out;
    }

    /**
     * Drop memoised values. Tests only — flags do not change within a request.
     *
     * @return void
     */
    public static function flush() {
        self::$resolved = array();
        self::$registry = null;
    }
}

/**
 * Whether a feature is enabled.
 *
 * @param string $slug Feature slug from the registry.
 * @return bool
 */
function wcp_feature($slug) {
    return WCP_Features::enabled($slug);
}
