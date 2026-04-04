<?php
/**
 * PBSG Analytics — Core analytics engine for Guide on the Side.
 *
 * Handles:
 *  - Database schema creation (3 custom tables)
 *  - AJAX endpoints for event ingestion (nopriv — students aren't logged in)
 *  - AJAX endpoints for dashboard data retrieval (admin-only)
 *  - CSV export
 *  - Rate limiting via transients
 *
 * Privacy: All data is aggregate-only. No PII, no cookies, no localStorage,
 *          no persistent identifiers. PIPEDA / UPEI compliant.
 *
 * @package    PB_Split_Guide
 * @subpackage Analytics
 * @since      1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBSG_Analytics {

    /**
     * WordPress database prefix for our custom tables.
     */
    const TABLE_TUTORIAL_STATS  = 'pbsg_tutorial_stats';
    const TABLE_QUESTION_STATS  = 'pbsg_question_stats';
    const TABLE_DAILY_STATS     = 'pbsg_daily_stats';

    /**
     * Valid event types accepted by the tracking endpoint.
     */
    const VALID_EVENTS = array(
        'tutorial_view',
        'tutorial_complete',
        'slide_view',
        'quiz_attempt',
        'quiz_giveup',
        'session_flush',
    );

    /**
     * Device categories derived from user-agent parsing.
     */
    const DEVICE_DESKTOP = 'desktop';
    const DEVICE_TABLET  = 'tablet';
    const DEVICE_MOBILE  = 'mobile';

    /**
     * Rate limit: max events per IP per minute (prevents bot hammering).
     * Uses WordPress transients — no PII stored (transient key is hashed).
     */
    const RATE_LIMIT_PER_MINUTE = 60;

    /**
     * Initialize hooks.
     */
    public static function init() {
        // Public AJAX endpoints (students aren't logged in)
        add_action( 'wp_ajax_nopriv_pbsg_track_event', array( __CLASS__, 'handle_track_event' ) );
        add_action( 'wp_ajax_pbsg_track_event', array( __CLASS__, 'handle_track_event' ) );

        // Admin-only AJAX endpoints
        add_action( 'wp_ajax_pbsg_get_analytics', array( __CLASS__, 'handle_get_analytics' ) );
        add_action( 'wp_ajax_pbsg_export_csv', array( __CLASS__, 'handle_export_csv' ) );

        // Schema upgrade check
        add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade_schema' ) );
    }

    /* =========================================================================
       DATABASE SCHEMA
       ========================================================================= */

    /**
     * Create custom analytics tables on plugin activation.
     * Called from the main plugin file's register_activation_hook.
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $tutorial_table  = $wpdb->prefix . self::TABLE_TUTORIAL_STATS;
        $question_table  = $wpdb->prefix . self::TABLE_QUESTION_STATS;
        $daily_table     = $wpdb->prefix . self::TABLE_DAILY_STATS;

        /**
         * Table 1: Tutorial-level aggregate counters.
         * One row per tutorial page. Atomic increments only.
         */
        $sql_tutorial = "CREATE TABLE {$tutorial_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            tutorial_page_id BIGINT(20) UNSIGNED NOT NULL,
            view_count BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            completion_count BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            total_time_seconds BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            total_sessions BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY tutorial_page_id (tutorial_page_id)
        ) {$charset_collate};";

        /**
         * Table 2: Per-question aggregate counters.
         * One row per (tutorial, h5p_content_id, question_index) combination.
         */
        $sql_question = "CREATE TABLE {$question_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            tutorial_page_id BIGINT(20) UNSIGNED NOT NULL,
            h5p_content_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            question_index INT UNSIGNED NOT NULL DEFAULT 0,
            question_text VARCHAR(500) NOT NULL DEFAULT '',
            total_attempts BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            correct_count BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            incorrect_count BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            giveup_count BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            first_attempt_correct BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            second_attempt_correct BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            total_time_seconds BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            total_answered BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            incorrect_attempts BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            total_retries BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            max_retries_single_session INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY tutorial_question (tutorial_page_id, h5p_content_id, question_index)
        ) {$charset_collate};";

        /**
         * Table 3: Daily rollup for trend charts.
         * One row per (date, tutorial, device_type). Powers the overview chart.
         */
        $sql_daily = "CREATE TABLE {$daily_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            stat_date DATE NOT NULL,
            tutorial_page_id BIGINT(20) UNSIGNED NOT NULL,
            device_type VARCHAR(10) NOT NULL DEFAULT 'desktop',
            view_count INT UNSIGNED NOT NULL DEFAULT 0,
            completion_count INT UNSIGNED NOT NULL DEFAULT 0,
            total_time_seconds INT UNSIGNED NOT NULL DEFAULT 0,
            step_views TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY daily_tutorial_device (stat_date, tutorial_page_id, device_type),
            KEY idx_date (stat_date),
            KEY idx_tutorial (tutorial_page_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_tutorial );
        dbDelta( $sql_question );
        dbDelta( $sql_daily );

        // Store schema version for future migrations
        update_option( 'pbsg_analytics_db_version', '1.1.0' );
    }

    /**
     * Check and apply schema upgrades on admin_init.
     * Uses dbDelta which is idempotent — safe to re-run.
     */
    public static function maybe_upgrade_schema() {
        $current = get_option( 'pbsg_analytics_db_version', '1.0.0' );
        if ( version_compare( $current, '1.1.0', '<' ) ) {
            self::create_tables();
        }
    }

    /* =========================================================================
       EVENT TRACKING ENDPOINT (Public — wp_ajax_nopriv)
       ========================================================================= */

    /**
     * Handle incoming analytics events from split-guide-tracker.js.
     * Accepts JSON POST body with event_type and associated data.
     * All storage is aggregate — no session IDs are persisted.
     */
    public static function handle_track_event() {
        // Verify this is a POST request
        if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
            wp_send_json_error( 'Invalid method', 405 );
        }

        // Rate limiting (hashed IP — no PII stored)
        if ( ! self::check_rate_limit() ) {
            wp_send_json_error( 'Rate limited', 429 );
        }

        // Parse JSON body
        $raw  = file_get_contents( 'php://input' );
        $data = json_decode( $raw, true );

        if ( ! $data || ! isset( $data['event_type'] ) ) {
            wp_send_json_error( 'Invalid payload', 400 );
        }

        $event_type = sanitize_text_field( $data['event_type'] );

        if ( ! in_array( $event_type, self::VALID_EVENTS, true ) ) {
            wp_send_json_error( 'Unknown event type', 400 );
        }

        // Validate tutorial_page_id exists and uses our template
        $tutorial_id = isset( $data['tutorial_page_id'] ) ? absint( $data['tutorial_page_id'] ) : 0;
        if ( ! $tutorial_id || ! self::is_valid_tutorial( $tutorial_id ) ) {
            wp_send_json_error( 'Invalid tutorial', 400 );
        }

        // Parse device type from user-agent (no fingerprinting)
        $device = self::detect_device();

        // Route to handler
        switch ( $event_type ) {
            case 'tutorial_view':
                self::record_tutorial_view( $tutorial_id, $device );
                break;

            case 'tutorial_complete':
                $total_time = isset( $data['total_time_seconds'] ) ? absint( $data['total_time_seconds'] ) : 0;
                self::record_tutorial_complete( $tutorial_id, $device, $total_time );
                break;

            case 'slide_view':
                $step_index = isset( $data['step_index'] ) ? absint( $data['step_index'] ) : 0;
                $dwell_time = isset( $data['dwell_time_seconds'] ) ? absint( $data['dwell_time_seconds'] ) : 0;
                self::record_slide_view( $tutorial_id, $step_index, $dwell_time );
                break;

            case 'quiz_attempt':
                self::record_quiz_attempt( $tutorial_id, $data );
                break;

            case 'quiz_giveup':
                self::record_quiz_giveup( $tutorial_id, $data );
                break;

            case 'session_flush':
                self::record_session_flush( $tutorial_id, $data, $device );
                break;
        }

        wp_send_json_success( array( 'recorded' => $event_type ) );
    }

    /* =========================================================================
       EVENT RECORDERS (private — atomic increments)
       ========================================================================= */

    private static function record_tutorial_view( $tutorial_id, $device ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_TUTORIAL_STATS;
        $daily = $wpdb->prefix . self::TABLE_DAILY_STATS;
        $today = current_time( 'Y-m-d' );

        // Upsert tutorial stats
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$table} (tutorial_page_id, view_count) VALUES (%d, 1)
             ON DUPLICATE KEY UPDATE view_count = view_count + 1",
            $tutorial_id
        ) );

        // Upsert daily stats
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$daily} (stat_date, tutorial_page_id, device_type, view_count)
             VALUES (%s, %d, %s, 1)
             ON DUPLICATE KEY UPDATE view_count = view_count + 1",
            $today, $tutorial_id, $device
        ) );
    }

    private static function record_tutorial_complete( $tutorial_id, $device, $total_time ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_TUTORIAL_STATS;
        $daily = $wpdb->prefix . self::TABLE_DAILY_STATS;
        $today = current_time( 'Y-m-d' );

        // Ensure view_count >= completion_count using GREATEST — a completion
        // always guarantees at least one view even if the view event was lost.
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$table} (tutorial_page_id, view_count, completion_count, total_time_seconds, total_sessions)
             VALUES (%d, 1, 1, %d, 1)
             ON DUPLICATE KEY UPDATE
                completion_count = completion_count + 1,
                total_time_seconds = total_time_seconds + %d,
                total_sessions = total_sessions + 1,
                view_count = GREATEST(view_count, completion_count)",
            $tutorial_id, $total_time, $total_time
        ) );

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$daily} (stat_date, tutorial_page_id, device_type, view_count, completion_count, total_time_seconds)
             VALUES (%s, %d, %s, 1, 1, %d)
             ON DUPLICATE KEY UPDATE
                completion_count = completion_count + 1,
                total_time_seconds = total_time_seconds + %d,
                view_count = GREATEST(view_count, completion_count)",
            $today, $tutorial_id, $device, $total_time, $total_time
        ) );
    }

    private static function record_slide_view( $tutorial_id, $step_index, $dwell_time ) {
        global $wpdb;
        $daily = $wpdb->prefix . self::TABLE_DAILY_STATS;
        $today = current_time( 'Y-m-d' );

        // Find today's row for this tutorial (any device type)
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, step_views FROM {$daily}
             WHERE stat_date = %s AND tutorial_page_id = %d
             ORDER BY view_count DESC LIMIT 1",
            $today, $tutorial_id
        ) );

        $step_views = array();
        if ( $row && $row->step_views ) {
            $step_views = json_decode( $row->step_views, true ) ?: array();
        }

        $key = 'step_' . $step_index;
        if ( ! isset( $step_views[ $key ] ) ) {
            $step_views[ $key ] = array( 'views' => 0, 'total_dwell' => 0 );
        }
        $step_views[ $key ]['views']++;
        $step_views[ $key ]['total_dwell'] += $dwell_time;

        if ( $row ) {
            $wpdb->update(
                $daily,
                array( 'step_views' => wp_json_encode( $step_views ) ),
                array( 'id' => $row->id ),
                array( '%s' ),
                array( '%d' )
            );
        } else {
            // No daily row yet — create one so step data isn't lost
            $device = self::detect_device();
            $wpdb->insert(
                $daily,
                array(
                    'stat_date'        => $today,
                    'tutorial_page_id' => $tutorial_id,
                    'device_type'      => $device,
                    'step_views'       => wp_json_encode( $step_views ),
                ),
                array( '%s', '%d', '%s', '%s' )
            );
        }
    }

    private static function record_quiz_attempt( $tutorial_id, $data ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_QUESTION_STATS;

        $h5p_id    = isset( $data['h5p_content_id'] ) ? absint( $data['h5p_content_id'] ) : 0;
        $q_index   = isset( $data['question_index'] ) ? absint( $data['question_index'] ) : 0;
        $q_text    = isset( $data['question_text'] ) ? sanitize_text_field( substr( $data['question_text'], 0, 500 ) ) : '';
        $correct   = ! empty( $data['is_correct'] ) ? 1 : 0;
        $attempt   = isset( $data['attempt_number'] ) ? absint( $data['attempt_number'] ) : 1;
        $time_spent = isset( $data['time_seconds'] ) ? absint( $data['time_seconds'] ) : 0;

        $incorrect = 1 - $correct;
        $is_retry  = ( $attempt > 1 ) ? 1 : 0;

        // Base upsert with retry tracking columns
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$table}
                (tutorial_page_id, h5p_content_id, question_index, question_text,
                 total_attempts, correct_count, incorrect_count, total_time_seconds, total_answered,
                 first_attempt_correct, second_attempt_correct,
                 incorrect_attempts, total_retries, max_retries_single_session)
             VALUES (%d, %d, %d, %s, 1, %d, %d, %d, 0, %d, %d, %d, %d, %d)
             ON DUPLICATE KEY UPDATE
                total_attempts = total_attempts + 1,
                correct_count = correct_count + %d,
                incorrect_count = incorrect_count + %d,
                total_time_seconds = total_time_seconds + %d,
                question_text = IF(question_text = '', %s, question_text),
                first_attempt_correct = first_attempt_correct + %d,
                second_attempt_correct = second_attempt_correct + %d,
                incorrect_attempts = incorrect_attempts + %d,
                total_retries = total_retries + %d,
                max_retries_single_session = GREATEST(max_retries_single_session, %d)",
            $tutorial_id, $h5p_id, $q_index, $q_text,
            $correct, $incorrect, $time_spent,
            ( $attempt === 1 && $correct ? 1 : 0 ),
            ( $attempt === 2 && $correct ? 1 : 0 ),
            $incorrect, $is_retry, $attempt,
            $correct, $incorrect, $time_spent, $q_text,
            ( $attempt === 1 && $correct ? 1 : 0 ),
            ( $attempt === 2 && $correct ? 1 : 0 ),
            $incorrect, $is_retry, $attempt
        ) );

        // If correct, also increment total_answered (unique answer — correct eventually)
        if ( $correct ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$table}
                 SET total_answered = total_answered + 1
                 WHERE tutorial_page_id = %d AND h5p_content_id = %d AND question_index = %d",
                $tutorial_id, $h5p_id, $q_index
            ) );
        }
    }

    private static function record_quiz_giveup( $tutorial_id, $data ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_QUESTION_STATS;

        $h5p_id  = isset( $data['h5p_content_id'] ) ? absint( $data['h5p_content_id'] ) : 0;
        $q_index = isset( $data['question_index'] ) ? absint( $data['question_index'] ) : 0;

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$table}
                (tutorial_page_id, h5p_content_id, question_index, giveup_count)
             VALUES (%d, %d, %d, 1)
             ON DUPLICATE KEY UPDATE giveup_count = giveup_count + 1",
            $tutorial_id, $h5p_id, $q_index
        ) );
    }

    private static function record_session_flush( $tutorial_id, $data, $device ) {
        // Session flush sends accumulated dwell times for all steps visited
        if ( isset( $data['step_dwell_times'] ) && is_array( $data['step_dwell_times'] ) ) {
            foreach ( $data['step_dwell_times'] as $step_index => $dwell_seconds ) {
                self::record_slide_view( $tutorial_id, absint( $step_index ), absint( $dwell_seconds ) );
            }
        }

        // If total time is included, record it
        if ( isset( $data['total_time_seconds'] ) && absint( $data['total_time_seconds'] ) > 0 ) {
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_TUTORIAL_STATS;
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$table}
                 SET total_time_seconds = total_time_seconds + %d,
                     total_sessions = total_sessions + 1
                 WHERE tutorial_page_id = %d",
                absint( $data['total_time_seconds'] ), $tutorial_id
            ) );
        }
    }

    /* =========================================================================
       ANALYTICS DATA RETRIEVAL (Admin-only)
       ========================================================================= */

    /**
     * AJAX handler for dashboard data requests.
     * Returns JSON with computed metrics for the requested view.
     */
    public static function handle_get_analytics() {
        if ( ! current_user_can( 'pbsg_view_analytics' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $view = isset( $_GET['view'] ) ? sanitize_text_field( $_GET['view'] ) : 'overview';

        switch ( $view ) {
            case 'overview':
                wp_send_json_success( self::get_overview_data() );
                break;

            case 'tutorial':
                $tutorial_id = isset( $_GET['tutorial_id'] ) ? absint( $_GET['tutorial_id'] ) : 0;
                wp_send_json_success( self::get_tutorial_detail( $tutorial_id ) );
                break;

            case 'question':
                $tutorial_id = isset( $_GET['tutorial_id'] ) ? absint( $_GET['tutorial_id'] ) : 0;
                $h5p_id      = isset( $_GET['h5p_id'] ) ? absint( $_GET['h5p_id'] ) : 0;
                $q_index     = isset( $_GET['q_index'] ) ? absint( $_GET['q_index'] ) : 0;
                wp_send_json_success( self::get_question_detail( $tutorial_id, $h5p_id, $q_index ) );
                break;

            case 'compare':
                $ids = isset( $_GET['ids'] ) ? sanitize_text_field( $_GET['ids'] ) : '';
                wp_send_json_success( self::get_comparison_data( $ids ) );
                break;

            default:
                wp_send_json_error( 'Unknown view', 400 );
        }
    }

    /**
     * Get overview dashboard data — all tutorials summary.
     */
    public static function get_overview_data() {
        global $wpdb;
        $daily = $wpdb->prefix . self::TABLE_DAILY_STATS;

        $date_from = self::sanitize_date( isset( $_GET['date_from'] ) ? $_GET['date_from'] : '', date( 'Y-m-d', strtotime( '-30 days' ) ) );
        $date_to   = self::sanitize_date( isset( $_GET['date_to'] ) ? $_GET['date_to'] : '', date( 'Y-m-d' ) );
        $device    = isset( $_GET['device'] ) ? sanitize_text_field( $_GET['device'] ) : '';

        // Build device filter clause — shared across tutorials, trend, and KPIs
        $device_where = $device ? $wpdb->prepare( " AND d.device_type = %s", $device ) : '';

        // Tutorial summaries — aggregated from daily stats with date + device filtering
        $tutorials = $wpdb->get_results( $wpdb->prepare(
            "SELECT d.tutorial_page_id, p.post_title AS tutorial_name,
                    SUM(d.view_count) AS view_count,
                    SUM(d.completion_count) AS completion_count,
                    CASE WHEN SUM(d.view_count) > 0
                        THEN ROUND(SUM(d.completion_count) / SUM(d.view_count) * 100, 1)
                        ELSE 0 END AS completion_rate,
                    CASE WHEN SUM(d.completion_count) > 0
                        THEN ROUND(SUM(d.total_time_seconds) / SUM(d.completion_count))
                        ELSE 0 END AS avg_time_seconds
             FROM {$daily} d
             JOIN {$wpdb->posts} p ON p.ID = d.tutorial_page_id
             WHERE p.post_status = 'publish' AND d.stat_date BETWEEN %s AND %s {$device_where}
             GROUP BY d.tutorial_page_id, p.post_title
             ORDER BY SUM(d.view_count) DESC",
            $date_from, $date_to
        ), ARRAY_A ) ?: array();

        // Add question stats (avg score) per tutorial
        $q_table = $wpdb->prefix . self::TABLE_QUESTION_STATS;
        foreach ( $tutorials as &$t ) {
            $tid = $t['tutorial_page_id'];
            $q_stats = $wpdb->get_row( $wpdb->prepare(
                "SELECT
                    SUM(total_attempts) AS total_attempts,
                    SUM(correct_count) AS correct_count
                 FROM {$q_table} WHERE tutorial_page_id = %d",
                $tid
            ) );
            $t['avg_score'] = ( $q_stats && $q_stats->total_attempts > 0 )
                ? round( $q_stats->correct_count / $q_stats->total_attempts * 100, 1 )
                : 0;
        }
        unset( $t );

        // Daily trend data (filtered by date range + device)
        // Use LEAST to clamp completions <= views defensively
        // Note: $device_where re-aliased for non-joined query (uses device_type directly)
        $device_where_bare = $device ? $wpdb->prepare( " AND device_type = %s", $device ) : '';
        $daily_trend = $wpdb->get_results( $wpdb->prepare(
            "SELECT stat_date,
                    SUM(view_count) AS views,
                    LEAST(SUM(completion_count), SUM(view_count)) AS completions
             FROM {$daily}
             WHERE stat_date BETWEEN %s AND %s {$device_where_bare}
             GROUP BY stat_date
             ORDER BY stat_date ASC",
            $date_from, $date_to
        ), ARRAY_A ) ?: array();

        // Device breakdown
        $device_breakdown = $wpdb->get_results( $wpdb->prepare(
            "SELECT device_type, SUM(view_count) AS views
             FROM {$daily}
             WHERE stat_date BETWEEN %s AND %s
             GROUP BY device_type
             ORDER BY views DESC",
            $date_from, $date_to
        ), ARRAY_A ) ?: array();

        // Aggregate KPIs
        $totals = array(
            'total_views'       => array_sum( array_column( $tutorials, 'view_count' ) ),
            'total_completions' => array_sum( array_column( $tutorials, 'completion_count' ) ),
            'avg_completion'    => 0,
            'avg_score'         => 0,
        );
        if ( $totals['total_views'] > 0 ) {
            $totals['avg_completion'] = round( $totals['total_completions'] / $totals['total_views'] * 100, 1 );
        }
        if ( count( $tutorials ) > 0 ) {
            $totals['avg_score'] = round( array_sum( array_column( $tutorials, 'avg_score' ) ) / count( $tutorials ), 1 );
        }

        // Attach per-tutorial benchmarks for badge colouring + attention flags
        $site_benchmarks = PB_Split_Guide_Plugin::resolve_benchmarks();
        foreach ( $tutorials as &$t ) {
            $t['benchmarks'] = PB_Split_Guide_Plugin::resolve_benchmarks( $t['tutorial_page_id'] );
        }
        unset( $t );

        return array(
            'totals'           => $totals,
            'tutorials'        => $tutorials,
            'daily_trend'      => $daily_trend,
            'device_breakdown' => $device_breakdown,
            'benchmarks'       => $site_benchmarks,
            'date_scope'       => array(
                'date_from' => $date_from,
                'date_to'   => $date_to,
            ),
        );
    }

    /**
     * Get tutorial detail data — per-step funnel, dwell times, questions.
     */
    public static function get_tutorial_detail( $tutorial_id ) {
        global $wpdb;
        $daily   = $wpdb->prefix . self::TABLE_DAILY_STATS;
        $q_table = $wpdb->prefix . self::TABLE_QUESTION_STATS;

        $date_from = self::sanitize_date( isset( $_GET['date_from'] ) ? $_GET['date_from'] : '', date( 'Y-m-d', strtotime( '-30 days' ) ) );
        $date_to   = self::sanitize_date( isset( $_GET['date_to'] ) ? $_GET['date_to'] : '', date( 'Y-m-d' ) );
        $device    = isset( $_GET['device'] ) ? sanitize_text_field( $_GET['device'] ) : '';

        // Build device filter clauses
        $device_where      = $device ? $wpdb->prepare( " AND d.device_type = %s", $device ) : '';
        $device_where_bare = $device ? $wpdb->prepare( " AND device_type = %s", $device ) : '';

        // Tutorial aggregate stats — from daily stats with date range + device filtering
        $stats = $wpdb->get_row( $wpdb->prepare(
            "SELECT d.tutorial_page_id, p.post_title AS tutorial_name,
                    COALESCE(SUM(d.view_count), 0) AS view_count,
                    COALESCE(SUM(d.completion_count), 0) AS completion_count,
                    COALESCE(SUM(d.total_time_seconds), 0) AS total_time_seconds
             FROM {$daily} d
             JOIN {$wpdb->posts} p ON p.ID = d.tutorial_page_id
             WHERE d.tutorial_page_id = %d AND d.stat_date BETWEEN %s AND %s {$device_where}
             GROUP BY d.tutorial_page_id, p.post_title",
            $tutorial_id, $date_from, $date_to
        ), ARRAY_A );

        if ( ! $stats ) {
            // No daily data for this range — return zeroed stats with question data
            $post_title = get_the_title( $tutorial_id );
            $stats = array(
                'tutorial_page_id'  => $tutorial_id,
                'tutorial_name'     => $post_title ?: 'Tutorial #' . $tutorial_id,
                'view_count'        => 0,
                'completion_count'  => 0,
                'total_time_seconds'=> 0,
                'completion_rate'   => 0,
                'avg_time_seconds'  => 0,
            );
        } else {
            $stats['completion_rate'] = $stats['view_count'] > 0
                ? round( $stats['completion_count'] / $stats['view_count'] * 100, 1 )
                : 0;
            $stats['avg_time_seconds'] = $stats['completion_count'] > 0
                ? round( $stats['total_time_seconds'] / $stats['completion_count'] )
                : 0;
        }

        // Daily views for this tutorial — filtered by date range + device
        $daily_views = $wpdb->get_results( $wpdb->prepare(
            "SELECT stat_date, SUM(view_count) AS views, SUM(completion_count) AS completions
             FROM {$daily}
             WHERE tutorial_page_id = %d AND stat_date BETWEEN %s AND %s {$device_where_bare}
             GROUP BY stat_date ORDER BY stat_date ASC",
            $tutorial_id, $date_from, $date_to
        ), ARRAY_A ) ?: array();

        // Step dwell times — filtered by date range + device
        $step_data = $wpdb->get_results( $wpdb->prepare(
            "SELECT step_views FROM {$daily}
             WHERE tutorial_page_id = %d AND step_views IS NOT NULL AND stat_date BETWEEN %s AND %s {$device_where_bare}",
            $tutorial_id, $date_from, $date_to
        ), ARRAY_A ) ?: array();

        $step_aggregates = array();
        foreach ( $step_data as $row ) {
            $steps = json_decode( $row['step_views'], true ) ?: array();
            foreach ( $steps as $key => $vals ) {
                if ( ! isset( $step_aggregates[ $key ] ) ) {
                    $step_aggregates[ $key ] = array( 'views' => 0, 'total_dwell' => 0 );
                }
                $step_aggregates[ $key ]['views']      += $vals['views'];
                $step_aggregates[ $key ]['total_dwell'] += $vals['total_dwell'];
            }
        }

        // Calculate avg dwell per step
        $step_dwell = array();
        foreach ( $step_aggregates as $key => $agg ) {
            $step_dwell[ $key ] = array(
                'views'          => $agg['views'],
                'avg_dwell_secs' => $agg['views'] > 0 ? round( $agg['total_dwell'] / $agg['views'] ) : 0,
            );
        }

        // Question stats for this tutorial
        $questions = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$q_table} WHERE tutorial_page_id = %d ORDER BY question_index ASC",
            $tutorial_id
        ), ARRAY_A ) ?: array();

        foreach ( $questions as &$q ) {
            $q['correct_rate'] = $q['total_attempts'] > 0
                ? round( $q['correct_count'] / $q['total_attempts'] * 100, 1 )
                : 0;
            $q['avg_attempts'] = $q['total_answered'] > 0
                ? round( $q['total_attempts'] / $q['total_answered'], 1 )
                : 0;
        }
        unset( $q );

        // Give-up rate
        $total_giveups     = array_sum( array_column( $questions, 'giveup_count' ) );
        $total_completions = (int) $stats['completion_count'];
        $giveup_rate       = $total_completions > 0
            ? round( $total_giveups / $total_completions * 100, 1 )
            : 0;

        // Step names from tutorial post meta
        $step_names = array();
        $steps_json = get_post_meta( $tutorial_id, '_pbsg_steps_json', true );
        $steps_data = $steps_json ? json_decode( $steps_json, true ) : array();
        if ( is_array( $steps_data ) ) {
            foreach ( $steps_data as $idx => $step ) {
                $step_names[ 'step_' . $idx ] = ! empty( $step['title'] ) ? $step['title'] : '';
            }
        }

        return array(
            'stats'       => $stats,
            'daily_views' => $daily_views,
            'step_dwell'  => $step_dwell,
            'step_names'  => $step_names,
            'questions'   => $questions,
            'giveup_rate' => $giveup_rate,
            'benchmarks'  => PB_Split_Guide_Plugin::resolve_benchmarks( $tutorial_id ),
            'device_note' => $device ? ucfirst( $device ) . ' only — views, completions, funnel, and dwell times are filtered to this device type.' : '',
            'date_scope'  => array(
                'date_from' => $date_from,
                'date_to'   => $date_to,
            ),
        );
    }

    /**
     * Get question drill-down data.
     */
    public static function get_question_detail( $tutorial_id, $h5p_id, $q_index ) {
        global $wpdb;
        $q_table = $wpdb->prefix . self::TABLE_QUESTION_STATS;

        $question = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$q_table}
             WHERE tutorial_page_id = %d AND h5p_content_id = %d AND question_index = %d",
            $tutorial_id, $h5p_id, $q_index
        ), ARRAY_A );

        if ( ! $question ) {
            return array( 'error' => 'Question not found or has no data' );
        }

        $question['correct_rate'] = $question['total_attempts'] > 0
            ? round( $question['correct_count'] / $question['total_attempts'] * 100, 1 )
            : 0;

        // Attempt distribution (estimated from stored aggregates)
        $total_correct = (int) $question['correct_count'];
        $first_correct = (int) $question['first_attempt_correct'];
        $second_correct = (int) $question['second_attempt_correct'];
        $third_plus    = max( 0, $total_correct - $first_correct - $second_correct );

        $question['attempt_distribution'] = array(
            'first_attempt_correct'  => $first_correct,
            'second_attempt_correct' => $second_correct,
            'third_plus_correct'     => $third_plus,
            'giveups'                => (int) $question['giveup_count'],
        );

        $question['avg_time_seconds'] = $question['total_answered'] > 0
            ? round( $question['total_time_seconds'] / $question['total_answered'] )
            : 0;

        // Retry statistics
        $question['retry_stats'] = array(
            'incorrect_attempts'        => (int) ( $question['incorrect_attempts'] ?? 0 ),
            'total_retries'             => (int) ( $question['total_retries'] ?? 0 ),
            'max_retries_single_session'=> (int) ( $question['max_retries_single_session'] ?? 0 ),
            'avg_retries_per_completion'=> $question['total_answered'] > 0
                ? round( (int) ( $question['total_retries'] ?? 0 ) / $question['total_answered'], 1 )
                : 0,
        );

        $question['date_scope'] = array( 'all_time' => true );
        $question['benchmarks'] = PB_Split_Guide_Plugin::resolve_benchmarks( $tutorial_id );

        return $question;
    }

    /* =========================================================================
       CSV EXPORT
       ========================================================================= */

    private static function send_csv_headers( $filename ) {
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
    }

    public static function handle_export_csv() {
        if ( ! current_user_can( 'pbsg_export_csv' ) ) {
            wp_die( 'Unauthorized' );
        }

        $export_type = isset( $_GET['type'] ) ? sanitize_text_field( $_GET['type'] ) : 'overview';

        $date_from = self::sanitize_date( isset( $_GET['date_from'] ) ? $_GET['date_from'] : '', date( 'Y-m-d', strtotime( '-30 days' ) ) );
        $date_to   = self::sanitize_date( isset( $_GET['date_to'] ) ? $_GET['date_to'] : '', date( 'Y-m-d' ) );

        $output = fopen( 'php://output', 'w' );

        if ( 'overview' === $export_type ) {
            $filename = 'tutorial-analytics-overview-' . $date_from . '-to-' . $date_to . '.csv';
            self::send_csv_headers( $filename );

            fputcsv( $output, array( 'Tutorial', 'Views', 'Completions', 'Completion Rate (%)', 'Avg Score (%)', 'Avg Time (s)' ) );
            $data = self::get_overview_data();
            foreach ( $data['tutorials'] as $t ) {
                fputcsv( $output, array(
                    $t['tutorial_name'],
                    $t['view_count'],
                    $t['completion_count'],
                    $t['completion_rate'],
                    $t['avg_score'],
                    $t['avg_time_seconds'],
                ) );
            }
        } elseif ( 'compare' === $export_type ) {
            $ids  = isset( $_GET['ids'] ) ? sanitize_text_field( $_GET['ids'] ) : '';
            $data = self::get_comparison_data( $ids );
            $tuts = array_values( $data['tutorials'] );

            $tut_names = array_map( function( $t ) {
                return sanitize_title( $t['name'] );
            }, $tuts );
            $filename = 'comparison-' . implode( '-', $tut_names ) . '-' . $date_from . '-to-' . $date_to . '.csv';
            self::send_csv_headers( $filename );

            fputcsv( $output, array( 'Metric', 'Tutorial 1', 'Tutorial 2', 'Tutorial 3' ) );

            $metrics = array(
                array( 'Views', 'views' ),
                array( 'Completions', 'completions' ),
                array( 'Completion Rate (%)', 'completion_rate' ),
                array( 'Avg Time (s)', 'avg_time_seconds' ),
                array( 'Avg Score (%)', 'avg_score' ),
                array( 'First Attempt Rate (%)', 'first_attempt_rate' ),
                array( 'Avg Attempts', 'avg_attempts' ),
                array( 'Give-up Rate (%)', 'giveup_rate' ),
            );

            // Tutorial names header
            $name_row = array( 'Tutorial' );
            for ( $i = 0; $i < 3; $i++ ) {
                $name_row[] = isset( $tuts[ $i ] ) ? $tuts[ $i ]['name'] : '';
            }
            fputcsv( $output, $name_row );

            foreach ( $metrics as $m ) {
                $row = array( $m[0] );
                for ( $i = 0; $i < 3; $i++ ) {
                    $row[] = isset( $tuts[ $i ] ) ? $tuts[ $i ][ $m[1] ] : '';
                }
                fputcsv( $output, $row );
            }
        } elseif ( 'questions' === $export_type ) {
            $tutorial_id = isset( $_GET['tutorial_id'] ) ? absint( $_GET['tutorial_id'] ) : 0;
            $tutorial_slug = sanitize_title( get_the_title( $tutorial_id ) ?: 'tutorial-' . $tutorial_id );
            $filename = $tutorial_slug . '-tutorial-' . $date_from . '-to-' . $date_to . '.csv';
            self::send_csv_headers( $filename );

            fputcsv( $output, array( 'Question', 'H5P ID', 'Attempts', 'Correct', 'Incorrect', 'Give-ups', 'Correct Rate (%)', 'Avg Attempts', 'Incorrect Attempts', 'Total Retries', 'Max Retries' ) );

            global $wpdb;
            $q_table = $wpdb->prefix . self::TABLE_QUESTION_STATS;
            $questions = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$q_table} WHERE tutorial_page_id = %d ORDER BY question_index",
                $tutorial_id
            ), ARRAY_A ) ?: array();

            foreach ( $questions as $q ) {
                $rate = $q['total_attempts'] > 0 ? round( $q['correct_count'] / $q['total_attempts'] * 100, 1 ) : 0;
                $avg  = $q['total_answered'] > 0 ? round( $q['total_attempts'] / $q['total_answered'], 1 ) : 0;
                fputcsv( $output, array(
                    $q['question_text'] ?: 'Q' . ( $q['question_index'] + 1 ),
                    $q['h5p_content_id'],
                    $q['total_attempts'],
                    $q['correct_count'],
                    $q['incorrect_count'],
                    $q['giveup_count'],
                    $rate,
                    $avg,
                    $q['incorrect_attempts'] ?? 0,
                    $q['total_retries'] ?? 0,
                    $q['max_retries_single_session'] ?? 0,
                ) );
            }
        } elseif ( 'question_detail' === $export_type ) {
            $tutorial_id = isset( $_GET['tutorial_id'] ) ? absint( $_GET['tutorial_id'] ) : 0;
            $h5p_id      = isset( $_GET['h5p_id'] ) ? absint( $_GET['h5p_id'] ) : 0;
            $q_index     = isset( $_GET['q_index'] ) ? absint( $_GET['q_index'] ) : 0;
            $tutorial_slug = sanitize_title( get_the_title( $tutorial_id ) ?: 'tutorial-' . $tutorial_id );
            $filename = $tutorial_slug . '-question-' . $date_from . '-to-' . $date_to . '.csv';
            self::send_csv_headers( $filename );

            fputcsv( $output, array(
                'Question', 'H5P ID', 'Question Index', 'Total Attempts',
                'Correct', 'Incorrect', 'Give-ups', 'Correct Rate (%)',
                '1st Attempt Correct', '2nd Attempt Correct', '3rd+ Attempt Correct',
                'Avg Attempts', 'Avg Time (s)',
                'Incorrect Attempts', 'Total Retries', 'Max Retries',
            ) );

            $question = self::get_question_detail( $tutorial_id, $h5p_id, $q_index );

            if ( ! isset( $question['error'] ) ) {
                $dist  = $question['attempt_distribution'];
                $retry = $question['retry_stats'];
                $avg_attempts = $question['total_answered'] > 0
                    ? round( $question['total_attempts'] / $question['total_answered'], 1 )
                    : 0;

                fputcsv( $output, array(
                    $question['question_text'] ?: 'Q' . ( $question['question_index'] + 1 ),
                    $question['h5p_content_id'],
                    $question['question_index'],
                    $question['total_attempts'],
                    $question['correct_count'],
                    $question['incorrect_count'],
                    $question['giveup_count'],
                    $question['correct_rate'],
                    $dist['first_attempt_correct'],
                    $dist['second_attempt_correct'],
                    $dist['third_plus_correct'],
                    $avg_attempts,
                    $question['avg_time_seconds'],
                    $retry['incorrect_attempts'],
                    $retry['total_retries'],
                    $retry['max_retries_single_session'],
                ) );
            }
        }

        fclose( $output );
        exit;
    }

    /* =========================================================================
       COMPARISON DATA (Admin-only)
       ========================================================================= */

    /**
     * Get comparison data for up to 3 tutorials side-by-side.
     *
     * @param string $ids_string Comma-separated tutorial page IDs.
     * @return array Keyed by tutorial_page_id with aggregate metrics.
     */
    public static function get_comparison_data( $ids_string ) {
        global $wpdb;
        $daily   = $wpdb->prefix . self::TABLE_DAILY_STATS;
        $q_table = $wpdb->prefix . self::TABLE_QUESTION_STATS;

        // Parse and limit to 3 IDs
        $raw_ids = array_filter( array_map( 'absint', explode( ',', $ids_string ) ) );
        $ids     = array_slice( $raw_ids, 0, 3 );

        if ( empty( $ids ) ) {
            return array( 'tutorials' => array(), 'date_scope' => array() );
        }

        $date_from = self::sanitize_date( isset( $_GET['date_from'] ) ? $_GET['date_from'] : '', date( 'Y-m-d', strtotime( '-30 days' ) ) );
        $date_to   = self::sanitize_date( isset( $_GET['date_to'] ) ? $_GET['date_to'] : '', date( 'Y-m-d' ) );
        $device    = isset( $_GET['device'] ) ? sanitize_text_field( $_GET['device'] ) : '';

        // Build device filter clauses for comparison queries
        $device_where      = $device ? $wpdb->prepare( " AND d.device_type = %s", $device ) : '';
        $device_where_bare = $device ? $wpdb->prepare( " AND device_type = %s", $device ) : '';

        $tutorials = array();

        foreach ( $ids as $tid ) {
            // Aggregate from daily stats (date + device filterable)
            $stats = $wpdb->get_row( $wpdb->prepare(
                "SELECT COALESCE(SUM(d.view_count), 0) AS view_count,
                        COALESCE(SUM(d.completion_count), 0) AS completion_count,
                        COALESCE(SUM(d.total_time_seconds), 0) AS total_time_seconds
                 FROM {$daily} d
                 WHERE d.tutorial_page_id = %d AND d.stat_date BETWEEN %s AND %s {$device_where}",
                $tid, $date_from, $date_to
            ) );

            $views       = (int) $stats->view_count;
            $completions = (int) $stats->completion_count;
            $comp_rate   = $views > 0 ? round( $completions / $views * 100, 1 ) : 0;
            $avg_time    = $completions > 0 ? round( $stats->total_time_seconds / $completions ) : 0;

            // Device breakdown (date-filtered)
            $device_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT device_type, SUM(view_count) AS views
                 FROM {$daily}
                 WHERE tutorial_page_id = %d AND stat_date BETWEEN %s AND %s
                 GROUP BY device_type",
                $tid, $date_from, $date_to
            ), ARRAY_A ) ?: array();

            $total_device_views = array_sum( array_column( $device_rows, 'views' ) );
            $devices = array( 'desktop' => 0, 'tablet' => 0, 'mobile' => 0 );
            foreach ( $device_rows as $dr ) {
                $dt = $dr['device_type'];
                if ( isset( $devices[ $dt ] ) ) {
                    $devices[ $dt ] = $total_device_views > 0
                        ? round( (int) $dr['views'] / $total_device_views * 100, 1 )
                        : 0;
                }
            }

            // Step funnel data (date + device filtered)
            $step_data = $wpdb->get_results( $wpdb->prepare(
                "SELECT step_views FROM {$daily}
                 WHERE tutorial_page_id = %d AND step_views IS NOT NULL AND stat_date BETWEEN %s AND %s {$device_where_bare}",
                $tid, $date_from, $date_to
            ), ARRAY_A ) ?: array();

            $step_aggregates = array();
            foreach ( $step_data as $row ) {
                $steps = json_decode( $row['step_views'], true ) ?: array();
                foreach ( $steps as $key => $vals ) {
                    if ( ! isset( $step_aggregates[ $key ] ) ) {
                        $step_aggregates[ $key ] = 0;
                    }
                    $step_aggregates[ $key ] += $vals['views'];
                }
            }

            // Sort funnel by step index
            uksort( $step_aggregates, function( $a, $b ) {
                return (int) str_replace( 'step_', '', $a ) - (int) str_replace( 'step_', '', $b );
            } );

            $funnel = array();
            foreach ( $step_aggregates as $key => $step_views ) {
                $funnel[] = array(
                    'step'  => $key,
                    'views' => $step_views,
                );
            }

            // Question stats (all-time, not date-filtered)
            $q_stats = $wpdb->get_row( $wpdb->prepare(
                "SELECT SUM(total_attempts) AS total_attempts,
                        SUM(correct_count) AS correct_count,
                        SUM(first_attempt_correct) AS first_attempt_correct,
                        SUM(total_answered) AS total_answered,
                        SUM(giveup_count) AS giveup_count
                 FROM {$q_table} WHERE tutorial_page_id = %d",
                $tid
            ) );

            $total_attempts = (int) ( $q_stats->total_attempts ?? 0 );
            $correct_count  = (int) ( $q_stats->correct_count ?? 0 );
            $first_correct  = (int) ( $q_stats->first_attempt_correct ?? 0 );
            $total_answered = (int) ( $q_stats->total_answered ?? 0 );
            $total_giveups  = (int) ( $q_stats->giveup_count ?? 0 );

            $avg_score          = $total_attempts > 0 ? round( $correct_count / $total_attempts * 100, 1 ) : 0;
            $first_attempt_rate = $total_answered > 0 ? round( $first_correct / $total_answered * 100, 1 ) : 0;
            $avg_attempts       = $total_answered > 0 ? round( $total_attempts / $total_answered, 1 ) : 0;
            $giveup_rate        = $total_answered > 0 ? round( $total_giveups / $total_answered * 100, 1 ) : 0;

            // Hardest question (lowest correct rate with at least 1 attempt)
            $hardest = $wpdb->get_row( $wpdb->prepare(
                "SELECT question_text, question_index,
                        ROUND(correct_count / total_attempts * 100, 1) AS correct_rate
                 FROM {$q_table}
                 WHERE tutorial_page_id = %d AND total_attempts > 0
                 ORDER BY (correct_count / total_attempts) ASC
                 LIMIT 1",
                $tid
            ), ARRAY_A );

            // Get tutorial name and published date
            $post = get_post( $tid );
            $name = $post ? $post->post_title : 'Tutorial #' . $tid;
            $meta = $post ? 'Published ' . get_the_date( 'M j, Y', $post ) : '';

            $tutorials[ $tid ] = array(
                'name'               => $name,
                'meta'               => $meta,
                'views'              => $views,
                'completions'        => $completions,
                'completion_rate'    => $comp_rate,
                'avg_time_seconds'   => $avg_time,
                'avg_score'          => $avg_score,
                'first_attempt_rate' => $first_attempt_rate,
                'avg_attempts'       => $avg_attempts,
                'giveup_rate'        => $giveup_rate,
                'hardest_question'   => $hardest ?: null,
                'funnel'             => $funnel,
                'devices'            => $devices,
                'benchmarks'         => PB_Split_Guide_Plugin::resolve_benchmarks( $tid ),
            );
        }

        return array(
            'tutorials'  => $tutorials,
            'date_scope' => array(
                'date_from' => $date_from,
                'date_to'   => $date_to,
            ),
        );
    }

    /**
     * Get list of all published tutorials with the split-guide template.
     * Used for the comparison dropdown selectors.
     *
     * @return array [ { id, title, date } ]
     */
    public static function get_tutorial_list() {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_date
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_status = 'publish'
               AND pm.meta_key = '_wp_page_template'
               AND pm.meta_value IN ('split-guide-template.php', 'templates/split-guide-template.php')
             ORDER BY p.post_title ASC",
            ARRAY_A
        );

        $tutorials = array();
        foreach ( $results ?: array() as $row ) {
            $tutorials[] = array(
                'id'    => (int) $row['ID'],
                'title' => $row['post_title'],
                'date'  => date( 'M j, Y', strtotime( $row['post_date'] ) ),
            );
        }

        return $tutorials;
    }

    /* =========================================================================
       UTILITY METHODS
       ========================================================================= */

    /**
     * Validate and sanitize a YYYY-MM-DD date string.
     */
    private static function sanitize_date( $date_str, $default ) {
        $date_str = sanitize_text_field( $date_str );
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_str ) ) {
            return $date_str;
        }
        return $default;
    }

    /**
     * Check if a page ID is a valid split-guide tutorial.
     */
    private static function is_valid_tutorial( $page_id ) {
        $template = get_page_template_slug( $page_id );
        return ( 'split-guide-template.php' === $template || 'templates/split-guide-template.php' === $template );
    }

    /**
     * Simple device detection from user-agent (no fingerprinting).
     */
    private static function detect_device() {
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( $_SERVER['HTTP_USER_AGENT'] ) : '';

        if ( preg_match( '/tablet|ipad|playbook|silk/i', $ua ) ) {
            return self::DEVICE_TABLET;
        }
        if ( preg_match( '/mobile|android|iphone|ipod|blackberry|opera mini|iemobile/i', $ua ) ) {
            return self::DEVICE_MOBILE;
        }
        return self::DEVICE_DESKTOP;
    }

    /**
     * Rate limiter using hashed transient keys (no PII stored).
     */
    private static function check_rate_limit() {
        $ip_hash = md5( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
        $key     = 'pbsg_rl_' . substr( $ip_hash, 0, 12 );
        $count   = (int) get_transient( $key );

        if ( $count >= self::RATE_LIMIT_PER_MINUTE ) {
            return false;
        }

        set_transient( $key, $count + 1, 60 );
        return true;
    }

}
