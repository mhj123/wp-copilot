<?php
/**
 * Privacy lock.
 *
 * Items are native published posts and structure is native Pages, so on a
 * stock install WordPress would happily serve every note to anonymous
 * visitors — on the front end, in RSS feeds, in the XML sitemap and over
 * the REST API. WP Copilot is a private workspace, so by default nothing is
 * served to anyone who is not logged in.
 *
 * This lives in the plugin rather than the theme on purpose: the lock must
 * hold whichever theme happens to be active.
 *
 * To run a deliberately public site, opt out in wp-config.php:
 *
 *     define('WCP_PUBLIC_SITE', true);
 *
 * or with the 'wcp_require_login' filter.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_Privacy {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Priority 0: ahead of core's sitemap renderer and feed/template
        // loading, all of which run on or after template_redirect.
        add_action('template_redirect', array($this, 'require_login'), 0);
        add_filter('rest_authentication_errors', array($this, 'require_rest_auth'));
        add_filter('wp_sitemaps_enabled', array($this, 'filter_sitemaps_enabled'));
        add_filter('wp_robots', array($this, 'filter_robots'));
        add_filter('robots_txt', array($this, 'filter_robots_txt'), 10, 2);
    }

    /**
     * Whether anonymous visitors are locked out. True unless the site owner
     * has explicitly opted into a public site.
     */
    public static function is_locked() {
        $locked = !(defined('WCP_PUBLIC_SITE') && WCP_PUBLIC_SITE);

        return (bool) apply_filters('wcp_require_login', $locked);
    }

    /**
     * Front end, feeds and sitemaps: send anonymous visitors to the login
     * screen instead of rendering anything.
     */
    public function require_login() {
        if (!self::is_locked() || is_user_logged_in()) {
            return;
        }

        // robots.txt carries no content and should stay readable so crawlers
        // see the Disallow below.
        if (is_robots() || is_favicon()) {
            return;
        }

        // A feed reader can't follow a login redirect; refuse outright.
        if (is_feed()) {
            status_header(403);
            nocache_headers();
            exit;
        }

        nocache_headers();
        auth_redirect();
    }

    /**
     * REST API: anonymous requests get a 401 instead of content. Logged-in
     * sessions (nonce requests) and Application Passwords pass. If
     * credentials were supplied but failed, $result already holds that
     * error and is passed through untouched.
     */
    public function require_rest_auth($result) {
        if (!empty($result) || !self::is_locked()) {
            return $result;
        }

        if (!is_user_logged_in()) {
            return new WP_Error(
                'rest_authentication_required',
                __('Authentication required.', 'work-copilot'),
                array('status' => 401)
            );
        }

        return $result;
    }

    public function filter_sitemaps_enabled($enabled) {
        return self::is_locked() ? false : $enabled;
    }

    /**
     * Belt and braces: anything that does render (e.g. the login screen)
     * tells crawlers to stay away.
     */
    public function filter_robots($robots) {
        if (!self::is_locked()) {
            return $robots;
        }

        return wp_robots_no_robots($robots);
    }

    public function filter_robots_txt($output, $public) {
        if (!self::is_locked()) {
            return $output;
        }

        return "User-agent: *\nDisallow: /\n";
    }
}
