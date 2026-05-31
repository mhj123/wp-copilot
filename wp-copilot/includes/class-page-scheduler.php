<?php
/**
 * Page Scheduler
 *
 * Handles two things that share one code path:
 *  1. Manual "Create subpage" button (called from REST endpoint)
 *  2. Scheduled automatic page creation (driven by WP-Cron)
 *
 * Core function: create_child_page() — used by both paths.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCP_Page_Scheduler {

    const CRON_HOOK     = 'wcp_scheduled_page_check';
    const CRON_INTERVAL = 'wcp_quarter_hourly';   // 15 min — see register_cron_interval()

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter( 'cron_schedules', array( $this, 'register_cron_interval' ) );
        add_action( self::CRON_HOOK, array( $this, 'check_and_run_due_schedules' ) );
    }

    // -------------------------------------------------------------------------
    // Cron interval registration
    // -------------------------------------------------------------------------

    public function register_cron_interval( $schedules ) {
        if ( ! isset( $schedules[ self::CRON_INTERVAL ] ) ) {
            $schedules[ self::CRON_INTERVAL ] = array(
                'interval' => 900,
                'display'  => __( 'Every 15 minutes', 'work-copilot' ),
            );
        }
        return $schedules;
    }

    /**
     * Ensure the recurring check event is scheduled.
     * Called on init (self-healing, mirrors Raindrop pattern).
     */
    public function ensure_cron_scheduled() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), self::CRON_INTERVAL, self::CRON_HOOK );
        }
    }

    // -------------------------------------------------------------------------
    // Core: create a child page and apply parent template
    // -------------------------------------------------------------------------

    /**
     * Create a child page under $parent_id with the given title,
     * applying the parent's template if one is defined.
     *
     * Returns array { page_id, page_url } or WP_Error.
     * Safe to call from both REST context (logged-in user) and cron (no user).
     *
     * @param int    $parent_id
     * @param string $title
     * @return array|WP_Error
     */
    public static function create_child_page( $parent_id, $title ) {
        $author = get_current_user_id() ?: 1;

        $page_id = wp_insert_post( array(
            'post_type'   => 'page',
            'post_title'  => sanitize_text_field( $title ),
            'post_status' => 'publish',
            'post_author' => $author,
            'post_parent' => intval( $parent_id ),
        ), true );

        if ( is_wp_error( $page_id ) ) {
            return $page_id;
        }

        // Apply parent template (content blocks + section headings + checklist items)
        $template_manager = WCP_Page_Template_Manager::instance();
        $template         = $template_manager->get_template( $parent_id );
        if ( $template ) {
            $template_manager->apply_template( $page_id, $template );
        }

        return array(
            'page_id'  => $page_id,
            'page_url' => get_permalink( $page_id ),
        );
    }

    // -------------------------------------------------------------------------
    // Schedule management
    // -------------------------------------------------------------------------

    /**
     * Read and decode the schedule stored on a page.
     *
     * @param  int        $page_id
     * @return array|null
     */
    public function get_schedule( $page_id ) {
        $raw = get_post_meta( $page_id, '_wcp_page_schedule', true );
        if ( empty( $raw ) ) return null;
        $s = json_decode( $raw, true );
        return ( is_array( $s ) && ! empty( $s['enabled'] ) ) ? $s : null;
    }

    /**
     * Called after saving the schedule metabox.
     * Recalculates next_run and persists it.
     *
     * @param int $page_id
     */
    public function sync_schedule( $page_id ) {
        $schedule = $this->get_schedule( $page_id );

        if ( ! $schedule ) {
            delete_post_meta( $page_id, '_wcp_schedule_next_run' );
            return;
        }

        // Preserve existing next_run if it's still in the future; recalculate otherwise
        $existing = (int) get_post_meta( $page_id, '_wcp_schedule_next_run', true );
        if ( $existing > time() ) {
            return;
        }

        $next = $this->calculate_next_run( $schedule );
        update_post_meta( $page_id, '_wcp_schedule_next_run', $next );
    }

    /**
     * Calculate the Unix timestamp of the next page-creation event.
     *
     * @param  array $schedule  { frequency, day_of_week, hour, minute }
     * @return int
     */
    public function calculate_next_run( $schedule ) {
        $frequency   = $schedule['frequency']   ?? 'weekly';
        $day_of_week = intval( $schedule['day_of_week'] ?? 1 ); // 0=Sun … 6=Sat
        $hour        = intval( $schedule['hour']        ?? 9  );
        $minute      = intval( $schedule['minute']      ?? 0  );

        $intervals = array(
            'weekly'      => 7 * DAY_IN_SECONDS,
            'fortnightly' => 14 * DAY_IN_SECONDS,
            'monthly'     => 28 * DAY_IN_SECONDS,
        );
        $interval = $intervals[ $frequency ] ?? 7 * DAY_IN_SECONDS;

        // Find the next occurrence of $day_of_week at $hour:$minute in local time
        $tz   = wp_timezone();
        $now  = new DateTimeImmutable( 'now', $tz );
        $date = clone $now;

        // Walk forward day by day (max 7 steps) until we hit the right weekday
        for ( $i = 0; $i <= 7; $i++ ) {
            if ( (int) $date->format( 'w' ) === $day_of_week ) {
                $candidate = $date->setTime( $hour, $minute, 0 );
                if ( $candidate > $now ) {
                    return $candidate->getTimestamp();
                }
            }
            $date = $date->modify( '+1 day' );
        }

        // Fallback: interval from now
        return time() + $interval;
    }

    /**
     * Advance next_run by one interval after a page has been created.
     *
     * @param int   $page_id
     * @param array $schedule
     */
    private function advance_next_run( $page_id, $schedule ) {
        $intervals = array(
            'weekly'      => 7 * DAY_IN_SECONDS,
            'fortnightly' => 14 * DAY_IN_SECONDS,
            'monthly'     => 28 * DAY_IN_SECONDS,
        );
        $interval = $intervals[ $schedule['frequency'] ?? 'weekly' ] ?? 7 * DAY_IN_SECONDS;
        $current  = (int) get_post_meta( $page_id, '_wcp_schedule_next_run', true );
        $next     = ( $current > 0 ) ? $current + $interval : time() + $interval;
        update_post_meta( $page_id, '_wcp_schedule_next_run', $next );
    }

    // -------------------------------------------------------------------------
    // Cron callback
    // -------------------------------------------------------------------------

    /**
     * Runs every 15 minutes. Finds all pages whose next_run is due and creates them.
     */
    public function check_and_run_due_schedules() {
        $now = time();

        $pages = get_posts( array(
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'     => '_wcp_schedule_next_run',
                    'value'   => $now,
                    'compare' => '<=',
                    'type'    => 'NUMERIC',
                ),
            ),
            'fields' => 'ids',
        ) );

        foreach ( $pages as $page_id ) {
            $schedule = $this->get_schedule( $page_id );
            if ( ! $schedule ) continue;

            $this->run_for_page( $page_id, $schedule );
        }
    }

    /**
     * Create the scheduled child page for one parent page.
     *
     * @param int   $page_id
     * @param array $schedule
     */
    public function run_for_page( $page_id, $schedule ) {
        $title  = $this->generate_title( $schedule['title_pattern'] ?? get_the_title( $page_id ) );
        $result = self::create_child_page( $page_id, $title );

        if ( is_wp_error( $result ) ) {
            // Advance anyway so a broken schedule doesn't fire every 15 minutes
            $this->advance_next_run( $page_id, $schedule );
            return;
        }

        $this->advance_next_run( $page_id, $schedule );
        $this->send_notification( $page_id, $title, $result['page_url'], $schedule );
    }

    /**
     * Replace {date} in a title pattern with the current formatted date.
     *
     * @param  string $pattern
     * @return string
     */
    private function generate_title( $pattern ) {
        $date = wp_date( get_option( 'date_format', 'j F Y' ) );
        return str_replace( '{date}', $date, $pattern );
    }

    /**
     * Send an email notification when a scheduled page is created.
     *
     * @param int    $parent_id
     * @param string $title
     * @param string $url
     * @param array  $schedule
     */
    private function send_notification( $parent_id, $title, $url, $schedule ) {
        $to      = sanitize_email( $schedule['notify_email'] ?? get_option( 'admin_email' ) );
        $subject = sprintf( __( '[WPCopilot] New page created: %s', 'work-copilot' ), $title );
        $body    = sprintf(
            __( "A scheduled page has been created:\n\nTitle: %s\nParent: %s\nURL: %s\n\nOpen it now and fill in your check-in.", 'work-copilot' ),
            $title,
            get_the_title( $parent_id ),
            $url
        );
        wp_mail( $to, $subject, $body );
    }
}
