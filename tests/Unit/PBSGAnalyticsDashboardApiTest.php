<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Helpers/MockWpdb.php';

/**
 * Dashboard Data API tests for PBSG_Analytics.
 *
 * Covers handle_get_analytics routing, capability checks,
 * JSON structure, date range filtering, empty-state handling,
 * and dashboard init/enqueue behavior.
 */
class PBSGAnalyticsDashboardApiTest extends TestCase
{
    private MockWpdb $wpdb;

    /** @var array Backup of $_GET */
    private array $getBackup;

    protected function setUp(): void
    {
        WPStubs::reset();
        $this->wpdb = new MockWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        $this->getBackup = $_GET;
        $_GET = [];

        WPStubs::$returns['current_user_can'] = true;
    }

    protected function tearDown(): void
    {
        WPStubs::reset();
        $_GET = $this->getBackup;
        unset($GLOBALS['wpdb']);
    }

    /* ---------------------------------------------------------------
       Admin capability check
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::handle_get_analytics
     */
    public function test_handle_get_analytics_rejects_unauthorized_user(): void
    {
        WPStubs::$returns['current_user_can'] = false;

        try {
            PBSG_Analytics::handle_get_analytics();
        } catch (WPDieException $e) {
            // Expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
        $args = WPStubs::callArgs('wp_send_json_error', 0);
        $this->assertSame('Unauthorized', $args[0]);
        $this->assertSame(403, $args[1]);
    }

    /**
     * @covers PBSG_Analytics::handle_get_analytics
     */
    public function test_handle_get_analytics_allows_authorized_user(): void
    {
        WPStubs::$returns['current_user_can'] = true;
        $_GET['view'] = 'overview';

        $this->wpdb->returns['get_results'] = [];

        try {
            PBSG_Analytics::handle_get_analytics();
        } catch (WPDieException $e) {
            // Expected — wp_send_json_success throws
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_success'));
    }

    /* ---------------------------------------------------------------
       View routing
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::handle_get_analytics
     */
    public function test_routes_to_overview_by_default(): void
    {
        $this->wpdb->returns['get_results'] = [];

        try {
            PBSG_Analytics::handle_get_analytics();
        } catch (WPDieException $e) {
            // Expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_success'));
        $data = WPStubs::callArgs('wp_send_json_success', 0)[0];
        $this->assertArrayHasKey('totals', $data);
        $this->assertArrayHasKey('tutorials', $data);
    }

    /**
     * @covers PBSG_Analytics::handle_get_analytics
     */
    public function test_routes_to_tutorial_detail_view(): void
    {
        $_GET['view']        = 'tutorial';
        $_GET['tutorial_id'] = '42';

        $this->wpdb->returns['get_row'] = null;
        $this->wpdb->returns['get_results'] = [];

        try {
            PBSG_Analytics::handle_get_analytics();
        } catch (WPDieException $e) {
            // Expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_success'));
        $data = WPStubs::callArgs('wp_send_json_success', 0)[0];
        $this->assertArrayHasKey('stats', $data);
        $this->assertArrayHasKey('questions', $data);
    }

    /**
     * @covers PBSG_Analytics::handle_get_analytics
     */
    public function test_routes_to_question_detail_view(): void
    {
        $_GET['view']        = 'question';
        $_GET['tutorial_id'] = '42';
        $_GET['h5p_id']      = '10';
        $_GET['q_index']     = '0';

        $this->wpdb->returns['get_row'] = null;

        try {
            PBSG_Analytics::handle_get_analytics();
        } catch (WPDieException $e) {
            // Expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_success'));
    }

    /**
     * @covers PBSG_Analytics::handle_get_analytics
     */
    public function test_routes_to_compare_view(): void
    {
        $_GET['view'] = 'compare';
        $_GET['ids']  = '42,43';

        $this->wpdb->returns['get_row'] = (object) [
            'view_count'        => 0,
            'completion_count'  => 0,
            'total_time_seconds'=> 0,
            'total_attempts'    => null,
            'correct_count'     => null,
            'first_attempt_correct' => null,
            'total_answered'    => null,
            'giveup_count'      => null,
        ];
        $this->wpdb->returns['get_results'] = [];
        WPStubs::$returns['get_post'] = null;

        try {
            PBSG_Analytics::handle_get_analytics();
        } catch (WPDieException $e) {
            // Expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_success'));
        $data = WPStubs::callArgs('wp_send_json_success', 0)[0];
        $this->assertArrayHasKey('tutorials', $data);
    }

    /**
     * @covers PBSG_Analytics::handle_get_analytics
     */
    public function test_unknown_view_returns_error(): void
    {
        $_GET['view'] = 'nonexistent';

        try {
            PBSG_Analytics::handle_get_analytics();
        } catch (WPDieException $e) {
            // Expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
        $args = WPStubs::callArgs('wp_send_json_error', 0);
        $this->assertSame('Unknown view', $args[0]);
        $this->assertSame(400, $args[1]);
    }

    /* ---------------------------------------------------------------
       Overview data structure
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::get_overview_data
     */
    public function test_overview_data_has_required_keys(): void
    {
        $this->wpdb->returns['get_results'] = [];

        $data = PBSG_Analytics::get_overview_data();

        $this->assertArrayHasKey('totals', $data);
        $this->assertArrayHasKey('tutorials', $data);
        $this->assertArrayHasKey('daily_trend', $data);
        $this->assertArrayHasKey('device_breakdown', $data);
        $this->assertArrayHasKey('date_scope', $data);
    }

    /**
     * @covers PBSG_Analytics::get_overview_data
     */
    public function test_overview_totals_has_required_keys(): void
    {
        $this->wpdb->returns['get_results'] = [];

        $data = PBSG_Analytics::get_overview_data();

        $this->assertArrayHasKey('total_views', $data['totals']);
        $this->assertArrayHasKey('total_completions', $data['totals']);
        $this->assertArrayHasKey('avg_completion', $data['totals']);
        $this->assertArrayHasKey('avg_score', $data['totals']);
    }

    /**
     * @covers PBSG_Analytics::get_overview_data
     */
    public function test_overview_empty_state_returns_zeroed_totals(): void
    {
        $this->wpdb->returns['get_results'] = [];

        $data = PBSG_Analytics::get_overview_data();

        $this->assertSame(0, $data['totals']['total_views']);
        $this->assertSame(0, $data['totals']['total_completions']);
        $this->assertSame(0, $data['totals']['avg_completion']);
        $this->assertSame(0, $data['totals']['avg_score']);
    }

    /**
     * @covers PBSG_Analytics::get_overview_data
     */
    public function test_overview_date_scope_uses_defaults(): void
    {
        $this->wpdb->returns['get_results'] = [];
        $_GET = [];

        $data = PBSG_Analytics::get_overview_data();

        $scope = $data['date_scope'];
        $this->assertArrayHasKey('date_from', $scope);
        $this->assertArrayHasKey('date_to', $scope);
        $this->assertSame(date('Y-m-d'), $scope['date_to']);
        $this->assertSame(date('Y-m-d', strtotime('-30 days')), $scope['date_from']);
    }

    /**
     * @covers PBSG_Analytics::get_overview_data
     */
    public function test_overview_date_scope_respects_custom_range(): void
    {
        $this->wpdb->returns['get_results'] = [];
        $_GET['date_from'] = '2026-01-01';
        $_GET['date_to']   = '2026-01-31';

        $data = PBSG_Analytics::get_overview_data();

        $this->assertSame('2026-01-01', $data['date_scope']['date_from']);
        $this->assertSame('2026-01-31', $data['date_scope']['date_to']);
    }

    /* ---------------------------------------------------------------
       Tutorial detail data structure
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::get_tutorial_detail
     */
    public function test_tutorial_detail_has_required_keys(): void
    {
        $this->wpdb->returns['get_row'] = null;
        $this->wpdb->returns['get_results'] = [];

        $data = PBSG_Analytics::get_tutorial_detail(42);

        $this->assertArrayHasKey('stats', $data);
        $this->assertArrayHasKey('daily_views', $data);
        $this->assertArrayHasKey('step_dwell', $data);
        $this->assertArrayHasKey('questions', $data);
        $this->assertArrayHasKey('giveup_rate', $data);
        $this->assertArrayHasKey('date_scope', $data);
    }

    /**
     * @covers PBSG_Analytics::get_tutorial_detail
     */
    public function test_tutorial_detail_empty_returns_zeroed_stats(): void
    {
        $this->wpdb->returns['get_row'] = null;
        $this->wpdb->returns['get_results'] = [];

        $data = PBSG_Analytics::get_tutorial_detail(42);

        $this->assertSame(0, $data['stats']['view_count']);
        $this->assertSame(0, $data['stats']['completion_count']);
        $this->assertSame(0, $data['stats']['completion_rate']);
    }

    /* ---------------------------------------------------------------
       Question detail data structure
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::get_question_detail
     */
    public function test_question_detail_not_found_returns_error(): void
    {
        $this->wpdb->returns['get_row'] = null;

        $data = PBSG_Analytics::get_question_detail(42, 10, 0);

        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Question not found or has no data', $data['error']);
    }

    /**
     * @covers PBSG_Analytics::get_question_detail
     */
    public function test_question_detail_returns_attempt_distribution(): void
    {
        $this->wpdb->returns['get_row'] = [
            'id'                    => 1,
            'tutorial_page_id'      => 42,
            'h5p_content_id'        => 10,
            'question_index'        => 0,
            'question_text'         => 'What is 2+2?',
            'total_attempts'        => 100,
            'correct_count'         => 80,
            'incorrect_count'       => 20,
            'giveup_count'          => 5,
            'first_attempt_correct' => 50,
            'second_attempt_correct'=> 20,
            'total_time_seconds'    => 3000,
            'total_answered'        => 75,
            'created_at'            => '2026-01-01 00:00:00',
            'updated_at'            => '2026-03-01 00:00:00',
        ];

        $data = PBSG_Analytics::get_question_detail(42, 10, 0);

        $this->assertArrayHasKey('attempt_distribution', $data);
        $dist = $data['attempt_distribution'];
        $this->assertSame(50, $dist['first_attempt_correct']);
        $this->assertSame(20, $dist['second_attempt_correct']);
        $this->assertSame(10, $dist['third_plus_correct']);
        $this->assertSame(5, $dist['giveups']);
    }

    /**
     * @covers PBSG_Analytics::get_question_detail
     */
    public function test_question_detail_correct_rate_calculation(): void
    {
        $this->wpdb->returns['get_row'] = [
            'id'                    => 1,
            'tutorial_page_id'      => 42,
            'h5p_content_id'        => 10,
            'question_index'        => 0,
            'question_text'         => 'Test',
            'total_attempts'        => 200,
            'correct_count'         => 150,
            'incorrect_count'       => 50,
            'giveup_count'          => 0,
            'first_attempt_correct' => 100,
            'second_attempt_correct'=> 30,
            'total_time_seconds'    => 6000,
            'total_answered'        => 100,
            'created_at'            => '2026-01-01 00:00:00',
            'updated_at'            => '2026-03-01 00:00:00',
        ];

        $data = PBSG_Analytics::get_question_detail(42, 10, 0);

        $this->assertSame(75.0, $data['correct_rate']);
    }

    /**
     * @covers PBSG_Analytics::get_question_detail
     */
    public function test_question_detail_has_all_time_date_scope(): void
    {
        $this->wpdb->returns['get_row'] = [
            'id'                    => 1,
            'tutorial_page_id'      => 42,
            'h5p_content_id'        => 10,
            'question_index'        => 0,
            'question_text'         => 'Test',
            'total_attempts'        => 10,
            'correct_count'         => 5,
            'incorrect_count'       => 5,
            'giveup_count'          => 0,
            'first_attempt_correct' => 3,
            'second_attempt_correct'=> 1,
            'total_time_seconds'    => 300,
            'total_answered'        => 5,
            'created_at'            => '2026-01-01 00:00:00',
            'updated_at'            => '2026-03-01 00:00:00',
        ];

        $data = PBSG_Analytics::get_question_detail(42, 10, 0);

        $this->assertArrayHasKey('date_scope', $data);
        $this->assertTrue($data['date_scope']['all_time']);
    }

    /* ---------------------------------------------------------------
       Comparison data
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::get_comparison_data
     */
    public function test_comparison_empty_ids_returns_empty(): void
    {
        $data = PBSG_Analytics::get_comparison_data('');

        $this->assertEmpty($data['tutorials']);
    }

    /**
     * @covers PBSG_Analytics::get_comparison_data
     */
    public function test_comparison_limits_to_three_tutorials(): void
    {
        $this->wpdb->returns['get_row'] = (object) [
            'view_count'        => 10,
            'completion_count'  => 5,
            'total_time_seconds'=> 600,
            'total_attempts'    => null,
            'correct_count'     => null,
            'first_attempt_correct' => null,
            'total_answered'    => null,
            'giveup_count'      => null,
        ];
        $this->wpdb->returns['get_results'] = [];
        WPStubs::$returns['get_post'] = null;

        $data = PBSG_Analytics::get_comparison_data('1,2,3,4,5');

        $this->assertLessThanOrEqual(3, count($data['tutorials']));
    }

    /* ---------------------------------------------------------------
       Date sanitization
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics
     * @dataProvider dateSanitizationProvider
     */
    public function test_sanitize_date(string $input, string $default, string $expected): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'sanitize_date');
        $method->setAccessible(true);

        $result = $method->invoke(null, $input, $default);
        $this->assertSame($expected, $result);
    }

    public static function dateSanitizationProvider(): array
    {
        return [
            'valid date'           => ['2026-03-01', '2026-01-01', '2026-03-01'],
            'empty string'         => ['', '2026-01-01', '2026-01-01'],
            'invalid format'       => ['03/01/2026', '2026-01-01', '2026-01-01'],
            'sql injection'        => ["2026-03-01'; DROP TABLE --", '2026-01-01', '2026-01-01'],
            'partial date'         => ['2026-03', '2026-01-01', '2026-01-01'],
            'extra characters'     => ['2026-03-01T00:00:00', '2026-01-01', '2026-01-01'],
            'whitespace padded'    => [' 2026-03-01 ', '2026-01-01', '2026-03-01'],
        ];
    }

    /* ---------------------------------------------------------------
       Tutorial validation
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics
     */
    public function test_is_valid_tutorial_accepts_split_guide_template(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'is_valid_tutorial');
        $method->setAccessible(true);

        WPStubs::$returns['page_template_slugs'] = [42 => 'split-guide-template.php'];
        $this->assertTrue($method->invoke(null, 42));
    }

    /**
     * @covers PBSG_Analytics
     */
    public function test_is_valid_tutorial_accepts_templates_subdir(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'is_valid_tutorial');
        $method->setAccessible(true);

        WPStubs::$returns['page_template_slugs'] = [42 => 'templates/split-guide-template.php'];
        $this->assertTrue($method->invoke(null, 42));
    }

    /**
     * @covers PBSG_Analytics
     */
    public function test_is_valid_tutorial_rejects_other_template(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'is_valid_tutorial');
        $method->setAccessible(true);

        WPStubs::$returns['page_template_slugs'] = [42 => 'default'];
        $this->assertFalse($method->invoke(null, 42));
    }

    /**
     * @covers PBSG_Analytics
     */
    public function test_is_valid_tutorial_rejects_nonexistent_page(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'is_valid_tutorial');
        $method->setAccessible(true);

        WPStubs::$returns['page_template_slugs'] = [];
        $this->assertFalse($method->invoke(null, 999));
    }

    /* ---------------------------------------------------------------
       Dashboard init
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics_Dashboard::init
     */
    public function test_dashboard_init_registers_admin_menu_hook(): void
    {
        WPStubs::reset();
        PBSG_Analytics_Dashboard::init();

        $tags = array_column(WPStubs::$hooks['action'], 'tag');
        $this->assertContains('admin_menu', $tags);
    }

    /**
     * @covers PBSG_Analytics_Dashboard::init
     */
    public function test_dashboard_init_registers_enqueue_hook(): void
    {
        WPStubs::reset();
        PBSG_Analytics_Dashboard::init();

        $tags = array_column(WPStubs::$hooks['action'], 'tag');
        $this->assertContains('admin_enqueue_scripts', $tags);
    }

    /* ---------------------------------------------------------------
       Dashboard enqueue assets
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics_Dashboard::enqueue_assets
     */
    public function test_dashboard_enqueue_skips_non_analytics_pages(): void
    {
        PBSG_Analytics_Dashboard::enqueue_assets('edit.php');

        $this->assertFalse(WPStubs::wasCalled('wp_enqueue_style'));
    }

    /**
     * @covers PBSG_Analytics_Dashboard::enqueue_assets
     */
    public function test_dashboard_enqueue_loads_on_analytics_page(): void
    {
        $this->wpdb->returns['get_results'] = [];

        PBSG_Analytics_Dashboard::enqueue_assets('toplevel_page_pbsg-analytics');

        $this->assertTrue(WPStubs::wasCalled('wp_enqueue_style'));
        $this->assertTrue(WPStubs::wasCalled('wp_enqueue_script'));
        $this->assertTrue(WPStubs::wasCalled('wp_localize_script'));
    }

    /**
     * @covers PBSG_Analytics_Dashboard::enqueue_assets
     */
    public function test_dashboard_localized_data_has_required_keys(): void
    {
        $this->wpdb->returns['get_results'] = [];

        PBSG_Analytics_Dashboard::enqueue_assets('toplevel_page_pbsg-analytics');

        $locArgs = WPStubs::callArgs('wp_localize_script', 0);
        $this->assertSame('pbsg-analytics-dashboard', $locArgs[0]);
        $this->assertSame('pbsgAnalytics', $locArgs[1]);

        $l10n = $locArgs[2];
        $this->assertArrayHasKey('ajaxUrl', $l10n);
        $this->assertArrayHasKey('nonce', $l10n);
        $this->assertArrayHasKey('exportUrl', $l10n);
        $this->assertArrayHasKey('tutorials', $l10n);
    }

    /* ---------------------------------------------------------------
       Dashboard render_dashboard — $_GET sanitization & data attributes
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics_Dashboard::render_dashboard
     */
    public function test_render_dashboard_default_view_is_overview(): void
    {
        $_GET = [];
        ob_start();
        PBSG_Analytics_Dashboard::render_dashboard();
        $html = ob_get_clean();

        $this->assertStringContainsString('data-view="overview"', $html);
        $this->assertStringContainsString('data-tutorial-id="0"', $html);
        $this->assertStringContainsString('data-h5p-id="0"', $html);
        $this->assertStringContainsString('data-q-index="0"', $html);
    }

    /**
     * @covers PBSG_Analytics_Dashboard::render_dashboard
     */
    public function test_render_dashboard_tab_sanitized_to_view(): void
    {
        $_GET = ['tab' => 'tutorial'];
        ob_start();
        PBSG_Analytics_Dashboard::render_dashboard();
        $html = ob_get_clean();

        $this->assertStringContainsString('data-view="tutorial"', $html);
    }

    /**
     * @covers PBSG_Analytics_Dashboard::render_dashboard
     */
    public function test_render_dashboard_tab_overview_compare_renders_correctly(): void
    {
        $_GET = ['tab' => 'compare'];
        ob_start();
        PBSG_Analytics_Dashboard::render_dashboard();
        $html = ob_get_clean();

        $this->assertStringContainsString('data-view="compare"', $html);
    }

    /**
     * @covers PBSG_Analytics_Dashboard::render_dashboard
     */
    public function test_render_dashboard_tutorial_id_absint(): void
    {
        $_GET = ['tab' => 'tutorial', 'tutorial_id' => '42'];
        WPStubs::$returns['get_the_title'] = 'My Tutorial';
        ob_start();
        PBSG_Analytics_Dashboard::render_dashboard();
        $html = ob_get_clean();

        $this->assertStringContainsString('data-tutorial-id="42"', $html);
        $this->assertStringContainsString('data-view="tutorial"', $html);
    }

    /**
     * @covers PBSG_Analytics_Dashboard::render_dashboard
     */
    public function test_render_dashboard_invalid_tutorial_id_sanitized_to_zero(): void
    {
        $_GET = ['tutorial_id' => 'abc'];
        ob_start();
        PBSG_Analytics_Dashboard::render_dashboard();
        $html = ob_get_clean();

        $this->assertStringContainsString('data-tutorial-id="0"', $html);
    }

    /**
     * @covers PBSG_Analytics_Dashboard::render_dashboard
     */
    public function test_render_dashboard_h5p_id_and_q_index_absint(): void
    {
        $_GET = ['tab' => 'question', 'tutorial_id' => '10', 'h5p_id' => '5', 'q_index' => '2'];
        WPStubs::$returns['get_the_title'] = 'Tutorial Title';
        ob_start();
        PBSG_Analytics_Dashboard::render_dashboard();
        $html = ob_get_clean();

        $this->assertStringContainsString('data-view="question"', $html);
        $this->assertStringContainsString('data-tutorial-id="10"', $html);
        $this->assertStringContainsString('data-h5p-id="5"', $html);
        $this->assertStringContainsString('data-q-index="2"', $html);
    }
}
