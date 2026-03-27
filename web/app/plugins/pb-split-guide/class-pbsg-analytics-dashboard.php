<?php
/**
 * PBSG Analytics Dashboard — WordPress admin page for Tutorial Analytics.
 *
 * Renders three views:
 *  A. Overview Dashboard (all tutorials summary)
 *  B. Tutorial Detail (per-tutorial funnel, dwell, questions)
 *  C. Question Drill-Down (per-question attempt distribution)
 *
 * Styling follows UPEI Library Design System:
 *  - Lusitana (headings), Roboto (body), Roboto Condensed (buttons/nav)
 *  - Color palette: #517E1B green, #8C2004 red, #333333 dark, #F8F8F8 cards
 *
 * @package    PB_Split_Guide
 * @subpackage Analytics
 * @since      1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBSG_Analytics_Dashboard {

    /**
     * Initialize admin hooks.
     */
    public static function init() {
        // Priority 1001 ensures this fires AFTER Pressbooks SideBar (priority 999)
        // rebuilds menus, so our menu item isn't removed.
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 1001 );
        add_action( 'network_admin_menu', array( __CLASS__, 'register_admin_menu' ), 1001 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    /**
     * Register the "Tutorial Analytics" admin menu page.
     */
    public static function register_admin_menu() {
        add_menu_page(
            __( 'Tutorial Analytics', 'pb-split-guide' ),
            __( 'Tutorial Analytics', 'pb-split-guide' ),
            'edit_pages', // Capability — librarians/admins
            'pbsg-analytics',
            array( __CLASS__, 'render_dashboard' ),
            'dashicons-chart-bar',
            30
        );
    }

    /**
     * Enqueue dashboard CSS and JS only on our admin page.
     */
    public static function enqueue_assets( $hook ) {
        if ( 'toplevel_page_pbsg-analytics' !== $hook ) {
            return;
        }

        $plugin_url = plugin_dir_url( __FILE__ );

        // Google Fonts
        wp_enqueue_style(
            'pbsg-google-fonts',
            'https://fonts.googleapis.com/css2?family=Lusitana:wght@400;700&family=Roboto:wght@300;400;500;700&family=Roboto+Condensed:wght@400;700&display=swap',
            array(),
            null
        );

        // Dashboard styles
        wp_enqueue_style(
            'pbsg-analytics-dashboard',
            $plugin_url . 'assets/analytics-dashboard.css',
            array(),
            '1.3.0'
        );

        // Dashboard JS
        wp_enqueue_script(
            'pbsg-analytics-dashboard',
            $plugin_url . 'assets/analytics-dashboard.js',
            array( 'jquery' ),
            '1.3.0',
            true
        );

        // Localize AJAX data
        wp_localize_script( 'pbsg-analytics-dashboard', 'pbsgAnalytics', array(
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'pbsg_analytics_nonce' ),
            'exportUrl' => admin_url( 'admin-ajax.php?action=pbsg_export_csv' ),
            'tutorials' => PBSG_Analytics::get_tutorial_list(),
        ) );
    }

    /**
     * Render the main dashboard page.
     * The page shell is server-rendered; data is loaded via AJAX.
     */
    public static function render_dashboard() {
        $current_view = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'overview';
        $tutorial_id  = isset( $_GET['tutorial_id'] ) ? absint( $_GET['tutorial_id'] ) : 0;
        $h5p_id       = isset( $_GET['h5p_id'] ) ? absint( $_GET['h5p_id'] ) : 0;
        $q_index      = isset( $_GET['q_index'] ) ? absint( $_GET['q_index'] ) : 0;
        ?>
        <div class="wrap pbsg-analytics-wrap">

            <!-- Page Header -->
            <div class="pbsg-header">
                <div class="pbsg-header-left">
                    <h1 class="h1 pbsg-page-title">
                        <?php esc_html_e( 'Tutorial Analytics', 'pb-split-guide' ); ?>
                    </h1>
                    <p class="p pbsg-subtitle">
                        <?php esc_html_e( 'Anonymous usage metrics across all Guide on the Side tutorials', 'pb-split-guide' ); ?>
                    </p>
                </div>
                <div class="pbsg-header-right">
                    <button type="button" class="button pbsg-btn pbsg-btn-sm" id="pbsg-refresh-btn">
                        ⟳ <?php esc_html_e( 'Refresh', 'pb-split-guide' ); ?>
                    </button>
                    <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=pbsg_export_csv&type=overview' ) ); ?>"
                       class="button pbsg-btn pbsg-btn-sm" id="pbsg-export-btn">
                        ↓ <?php esc_html_e( 'Export CSV', 'pb-split-guide' ); ?>
                    </a>
                </div>
            </div>

            <!-- View Navigation Tabs -->
            <nav class="pbsg-nav-tabs" role="tablist">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=pbsg-analytics&tab=overview' ) ); ?>"
                   class="a pbsg-tab <?php echo ( 'overview' === $current_view ) ? 'active' : ''; ?>"
                   role="tab">
                    <?php esc_html_e( 'Overview', 'pb-split-guide' ); ?>
                </a>
                <?php if ( $tutorial_id ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=pbsg-analytics&tab=tutorial&tutorial_id=' . $tutorial_id ) ); ?>"
                   class="a pbsg-tab <?php echo ( 'tutorial' === $current_view ) ? 'active' : ''; ?>"
                   role="tab">
                    <?php esc_html_e( 'Tutorial Detail', 'pb-split-guide' ); ?>
                </a>
                <?php endif; ?>
                <?php if ( $h5p_id || $q_index ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=pbsg-analytics&tab=question&tutorial_id=' . $tutorial_id . '&h5p_id=' . $h5p_id . '&q_index=' . $q_index ) ); ?>"
                   class="a pbsg-tab <?php echo ( 'question' === $current_view ) ? 'active' : ''; ?>"
                   role="tab">
                    <?php esc_html_e( 'Question Detail', 'pb-split-guide' ); ?>
                </a>
                <?php endif; ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=pbsg-analytics&tab=compare' ) ); ?>"
                   class="a pbsg-tab <?php echo ( 'compare' === $current_view ) ? 'active' : ''; ?>"
                   role="tab">
                    <?php esc_html_e( 'Compare', 'pb-split-guide' ); ?>
                </a>
            </nav>

            <!-- Breadcrumb -->
            <div class="pbsg-breadcrumb" id="pbsg-breadcrumb">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=pbsg-analytics' ) ); ?>">
                    <?php esc_html_e( 'Tutorial Analytics', 'pb-split-guide' ); ?>
                </a>
                <?php if ( 'tutorial' === $current_view || 'question' === $current_view ) : ?>
                    <span class="sep">›</span>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=pbsg-analytics&tab=tutorial&tutorial_id=' . $tutorial_id ) ); ?>"
                       id="pbsg-breadcrumb-tutorial">
                        <?php echo esc_html( get_the_title( $tutorial_id ) ?: 'Tutorial #' . $tutorial_id ); ?>
                    </a>
                <?php endif; ?>
                <?php if ( 'question' === $current_view ) : ?>
                    <span class="sep">›</span>
                    <span id="pbsg-breadcrumb-question">
                        <?php printf( esc_html__( 'Question %d', 'pb-split-guide' ), $q_index + 1 ); ?>
                    </span>
                <?php endif; ?>
                <?php if ( 'compare' === $current_view ) : ?>
                    <span class="sep">›</span>
                    <span><?php esc_html_e( 'Compare Tutorials', 'pb-split-guide' ); ?></span>
                <?php endif; ?>
            </div>

            <!-- Filter Bar -->
            <div class="pbsg-filter-bar" id="pbsg-filter-bar">
                <label class="label" for="pbsg-date-from"><?php esc_html_e( 'Date Range', 'pb-split-guide' ); ?></label>
                <input type="date" id="pbsg-date-from" value="<?php echo esc_attr( date( 'Y-m-d', strtotime( '-30 days' ) ) ); ?>">
                <span class="span pbsg-filter-sep"><?php esc_html_e( 'to', 'pb-split-guide' ); ?></span>
                <input type="date" id="pbsg-date-to" value="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>">

                <?php if ( 'overview' === $current_view ) : ?>
                <label for="pbsg-device-filter" class="pbsg-filter-device-label">
                    <?php esc_html_e( 'Device', 'pb-split-guide' ); ?>
                </label>
                <select id="pbsg-device-filter">
                    <option value=""><?php esc_html_e( 'All Devices', 'pb-split-guide' ); ?></option>
                    <option value="desktop"><?php esc_html_e( 'Desktop', 'pb-split-guide' ); ?></option>
                    <option value="tablet"><?php esc_html_e( 'Tablet', 'pb-split-guide' ); ?></option>
                    <option value="mobile"><?php esc_html_e( 'Mobile', 'pb-split-guide' ); ?></option>
                </select>
                <?php endif; ?>

                <span class="pbsg-filter-spacer"></span>
                <button type="button" class="button pbsg-btn pbsg-btn-primary pbsg-btn-sm" id="pbsg-apply-filters">
                    <?php esc_html_e( 'Apply', 'pb-split-guide' ); ?>
                </button>
            </div>

            <!-- Dynamic Content Area — populated by analytics-dashboard.js -->
            <div id="pbsg-dashboard-content"
                 data-view="<?php echo esc_attr( $current_view ); ?>"
                 data-tutorial-id="<?php echo esc_attr( $tutorial_id ); ?>"
                 data-h5p-id="<?php echo esc_attr( $h5p_id ); ?>"
                 data-q-index="<?php echo esc_attr( $q_index ); ?>">

                <!-- Loading State -->
                <div class="pbsg-loading" id="pbsg-loading">
                    <div class="pbsg-spinner"></div>
                    <p><?php esc_html_e( 'Loading analytics data…', 'pb-split-guide' ); ?></p>
                </div>

                <!-- Empty State (shown when no data) -->
                <div class="pbsg-empty-state" id="pbsg-empty-state" style="display:none;">
                    <div class="pbsg-empty-icon">📊</div>
                    <h2><?php esc_html_e( 'No analytics data yet', 'pb-split-guide' ); ?></h2>
                    <p><?php esc_html_e( 'Data will appear here once students begin viewing tutorials. The tracker collects anonymous usage statistics automatically.', 'pb-split-guide' ); ?></p>
                </div>

                <!-- KPI Stats Row (populated by JS) -->
                <div class="pbsg-stats-row" id="pbsg-stats-row" style="display:none;"></div>

                <!-- Main Content Grid (populated by JS) -->
                <div id="pbsg-main-content" style="display:none;"></div>
            </div>
        </div>
        <?php
    }
}
