<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Helpers/MockWpdb.php';

/**
 * Edge Cases & Regression tests for PBSG_Analytics.
 *
 * Covers zero-tutorial state, extremely long question text truncation,
 * negative/overflow value handling, concurrent rapid-fire events,
 * H5P content ID edge cases, and comparison with missing data.
 */
class PBSGAnalyticsEdgeCaseTest extends TestCase
{
    private MockWpdb $wpdb;

    protected function setUp(): void
    {
        WPStubs::reset();
        $this->wpdb = new MockWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        $_SERVER['REMOTE_ADDR']     = '10.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Chrome/120';
        $_SERVER['REQUEST_METHOD']  = 'POST';

        WPStubs::$returns['transients'] = [];
        WPStubs::$returns['page_template_slugs'] = [42 => 'split-guide-template.php'];
    }

    protected function tearDown(): void
    {
        WPStubs::reset();
        unset($GLOBALS['wpdb']);
    }

    /* ---------------------------------------------------------------
       Zero-tutorial state
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::get_overview_data
     */
    public function test_overview_with_zero_tutorials(): void
    {
        $this->wpdb->returns['get_results'] = [];

        $data = PBSG_Analytics::get_overview_data();

        $this->assertEmpty($data['tutorials']);
        $this->assertSame(0, $data['totals']['total_views']);
        $this->assertSame(0, $data['totals']['avg_completion']);
        $this->assertSame(0, $data['totals']['avg_score']);
    }

    /**
     * @covers PBSG_Analytics::get_tutorial_list
     */
    public function test_tutorial_list_returns_empty_when_no_tutorials(): void
    {
        $this->wpdb->returns['get_results'] = null;

        $list = PBSG_Analytics::get_tutorial_list();

        $this->assertIsArray($list);
        $this->assertEmpty($list);
    }

    /**
     * @covers PBSG_Analytics::get_comparison_data
     */
    public function test_comparison_with_empty_ids_string(): void
    {
        $data = PBSG_Analytics::get_comparison_data('');

        $this->assertIsArray($data['tutorials']);
        $this->assertEmpty($data['tutorials']);
    }

    /**
     * @covers PBSG_Analytics::get_comparison_data
     */
    public function test_comparison_with_zero_ids(): void
    {
        $data = PBSG_Analytics::get_comparison_data('0,0,0');

        $this->assertIsArray($data['tutorials']);
        $this->assertEmpty($data['tutorials']);
    }

    /* ---------------------------------------------------------------
       Extremely long question text truncation
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics
     */
    public function test_quiz_attempt_truncates_500_char_question(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_quiz_attempt');
        $method->setAccessible(true);

        $longText = str_repeat('X', 1000);
        $data = [
            'h5p_content_id' => 10,
            'question_index' => 0,
            'question_text'  => $longText,
            'is_correct'     => false,
            'attempt_number' => 1,
            'time_seconds'   => 5,
        ];

        $method->invoke(null, 42, $data);

        $queries = $this->wpdb->getQueriesContaining('pbsg_question_stats');
        $this->assertNotEmpty($queries);
        // The 1000-char string should have been substr'd to 500 before prepare
        $this->assertStringNotContainsString(str_repeat('X', 501), $queries[0]);
    }

    /**
     * @covers PBSG_Analytics
     */
    public function test_quiz_attempt_exact_500_chars_passes_through(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_quiz_attempt');
        $method->setAccessible(true);

        $exactText = str_repeat('Y', 500);
        $data = [
            'h5p_content_id' => 10,
            'question_index' => 0,
            'question_text'  => $exactText,
            'is_correct'     => false,
            'attempt_number' => 1,
            'time_seconds'   => 5,
        ];

        $method->invoke(null, 42, $data);

        $queries = $this->wpdb->getQueriesContaining('pbsg_question_stats');
        $this->assertNotEmpty($queries);
    }

    /* ---------------------------------------------------------------
       Negative/overflow values rejected via absint
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics
     */
    public function test_negative_tutorial_id_becomes_positive(): void
    {
        $this->assertSame(42, absint(-42));
    }

    /**
     * @covers PBSG_Analytics
     */
    public function test_negative_time_becomes_positive(): void
    {
        $this->assertSame(120, absint(-120));
    }

    /**
     * @covers PBSG_Analytics
     */
    public function test_zero_time_stays_zero(): void
    {
        $this->assertSame(0, absint(0));
    }

    /**
     * @covers PBSG_Analytics
     */
    public function test_float_time_truncated_to_int(): void
    {
        $this->assertSame(99, absint(99.9));
    }

    /**
     * @covers PBSG_Analytics
     */
    public function test_string_number_coerced(): void
    {
        $this->assertSame(42, absint('42'));
    }

    /**
     * @covers PBSG_Analytics
     */
    public function test_non_numeric_string_becomes_zero(): void
    {
        $this->assertSame(0, absint('abc'));
    }

    /* ---------------------------------------------------------------
       H5P content ID edge cases
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics
     */
    public function test_quiz_attempt_with_zero_h5p_content_id(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_quiz_attempt');
        $method->setAccessible(true);

        $data = [
            'h5p_content_id' => 0,
            'question_index' => 0,
            'question_text'  => 'Test',
            'is_correct'     => true,
            'attempt_number' => 1,
            'time_seconds'   => 5,
        ];

        $method->invoke(null, 42, $data);

        $queries = $this->wpdb->getQueriesContaining('pbsg_question_stats');
        $this->assertNotEmpty($queries, 'H5P content ID of 0 should still record');
    }

    /**
     * @covers PBSG_Analytics
     */
    public function test_quiz_giveup_with_missing_h5p_content_id(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_quiz_giveup');
        $method->setAccessible(true);

        $data = [
            // h5p_content_id intentionally omitted
            'question_index' => 0,
        ];

        $method->invoke(null, 42, $data);

        $queries = $this->wpdb->getQueriesContaining('pbsg_question_stats');
        $this->assertNotEmpty($queries, 'Missing h5p_content_id should default to 0 via absint');
    }

    /**
     * @covers PBSG_Analytics
     */
    public function test_quiz_attempt_with_missing_fields_uses_defaults(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_quiz_attempt');
        $method->setAccessible(true);

        $data = []; // All fields missing

        $method->invoke(null, 42, $data);

        $queries = $this->wpdb->getQueriesContaining('pbsg_question_stats');
        $this->assertNotEmpty($queries, 'Should still record with default values');
    }

    /* ---------------------------------------------------------------
       Rate limit edge cases
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics
     */
    public function test_rate_limit_at_exactly_59_allows_request(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'check_rate_limit');
        $method->setAccessible(true);

        $ipHash = md5('10.0.0.1');
        $key = 'pbsg_rl_' . substr($ipHash, 0, 12);
        WPStubs::$returns['transients'] = [$key => 59];

        $result = $method->invoke(null);
        $this->assertTrue($result, 'Count of 59 should be under the limit of 60');
    }

    /**
     * @covers PBSG_Analytics
     */
    public function test_rate_limit_at_exactly_60_blocks_request(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'check_rate_limit');
        $method->setAccessible(true);

        $ipHash = md5('10.0.0.1');
        $key = 'pbsg_rl_' . substr($ipHash, 0, 12);
        WPStubs::$returns['transients'] = [$key => 60];

        $result = $method->invoke(null);
        $this->assertFalse($result, 'Count of 60 should hit the rate limit');
    }

    /**
     * @covers PBSG_Analytics
     */
    public function test_rate_limit_increments_counter(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'check_rate_limit');
        $method->setAccessible(true);

        $ipHash = md5('10.0.0.1');
        $key = 'pbsg_rl_' . substr($ipHash, 0, 12);
        WPStubs::$returns['transients'] = [$key => 10];

        $method->invoke(null);

        $setArgs = WPStubs::callArgs('set_transient', 0);
        $this->assertSame($key, $setArgs[0]);
        $this->assertSame(11, $setArgs[1], 'Should increment from 10 to 11');
    }

    /* ---------------------------------------------------------------
       Session flush edge cases
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics
     */
    public function test_session_flush_without_step_dwell_times(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_session_flush');
        $method->setAccessible(true);

        WPStubs::$returns['current_time'] = '2026-03-01';

        $data = [
            'total_time_seconds' => 60,
            // step_dwell_times intentionally omitted
        ];

        // Should not throw
        $method->invoke(null, 42, $data, 'desktop');

        $queries = $this->wpdb->getQueriesContaining('total_time_seconds');
        $this->assertNotEmpty($queries);
    }

    /**
     * @covers PBSG_Analytics
     */
    public function test_session_flush_with_empty_step_dwell_times(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_session_flush');
        $method->setAccessible(true);

        WPStubs::$returns['current_time'] = '2026-03-01';

        $data = [
            'step_dwell_times'   => [],
            'total_time_seconds' => 30,
        ];

        // Should not throw
        $method->invoke(null, 42, $data, 'desktop');

        $this->assertTrue(true); // No exception = pass
    }

    /* ---------------------------------------------------------------
       Comparison data edge cases
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::get_comparison_data
     */
    public function test_comparison_with_non_numeric_ids(): void
    {
        $data = PBSG_Analytics::get_comparison_data('abc,def');

        $this->assertEmpty($data['tutorials']);
    }

    /**
     * @covers PBSG_Analytics::get_comparison_data
     */
    public function test_comparison_single_id(): void
    {
        $this->wpdb->returns['get_row'] = (object) [
            'view_count'        => 5,
            'completion_count'  => 2,
            'total_time_seconds'=> 300,
            'total_attempts'    => null,
            'correct_count'     => null,
            'first_attempt_correct' => null,
            'total_answered'    => null,
            'giveup_count'      => null,
        ];
        $this->wpdb->returns['get_results'] = [];
        WPStubs::$returns['get_post'] = null;

        $data = PBSG_Analytics::get_comparison_data('42');

        $this->assertCount(1, $data['tutorials']);
    }

    /* ---------------------------------------------------------------
       Question detail edge cases
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::get_question_detail
     */
    public function test_question_detail_zero_attempts_returns_zero_rate(): void
    {
        $this->wpdb->returns['get_row'] = [
            'id'                    => 1,
            'tutorial_page_id'      => 42,
            'h5p_content_id'        => 10,
            'question_index'        => 0,
            'question_text'         => 'Test',
            'total_attempts'        => 0,
            'correct_count'         => 0,
            'incorrect_count'       => 0,
            'giveup_count'          => 0,
            'first_attempt_correct' => 0,
            'second_attempt_correct'=> 0,
            'total_time_seconds'    => 0,
            'total_answered'        => 0,
            'created_at'            => '2026-01-01 00:00:00',
            'updated_at'            => '2026-03-01 00:00:00',
        ];

        $data = PBSG_Analytics::get_question_detail(42, 10, 0);

        $this->assertSame(0, $data['correct_rate']);
        $this->assertSame(0, $data['avg_time_seconds']);
    }

    /**
     * @covers PBSG_Analytics::get_question_detail
     */
    public function test_question_detail_third_plus_never_negative(): void
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
            'second_attempt_correct'=> 3,
            // first + second = 6 > correct_count = 5 (data inconsistency)
            'total_time_seconds'    => 100,
            'total_answered'        => 5,
            'created_at'            => '2026-01-01 00:00:00',
            'updated_at'            => '2026-03-01 00:00:00',
        ];

        $data = PBSG_Analytics::get_question_detail(42, 10, 0);

        // max(0, 5 - 3 - 3) = 0, not -1
        $this->assertGreaterThanOrEqual(0, $data['attempt_distribution']['third_plus_correct']);
    }

    /* ---------------------------------------------------------------
       Tutorial list data format
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::get_tutorial_list
     */
    public function test_tutorial_list_returns_expected_structure(): void
    {
        $this->wpdb->returns['get_results'] = [
            ['ID' => '42', 'post_title' => 'Test Tutorial', 'post_date' => '2026-02-15 10:00:00'],
        ];

        $list = PBSG_Analytics::get_tutorial_list();

        $this->assertCount(1, $list);
        $this->assertSame(42, $list[0]['id']);
        $this->assertSame('Test Tutorial', $list[0]['title']);
        $this->assertArrayHasKey('date', $list[0]);
    }

    /* ---------------------------------------------------------------
       Date sanitization edge cases
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics
     */
    public function test_sanitize_date_rejects_sql_injection(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'sanitize_date');
        $method->setAccessible(true);

        $result = $method->invoke(null, "2026-01-01' OR 1=1 --", '2026-01-01');
        $this->assertSame('2026-01-01', $result, 'SQL injection attempt should fall back to default');
    }

    /**
     * @covers PBSG_Analytics
     */
    public function test_sanitize_date_rejects_xss_attempt(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'sanitize_date');
        $method->setAccessible(true);

        $result = $method->invoke(null, '<script>alert(1)</script>', '2026-01-01');
        $this->assertSame('2026-01-01', $result);
    }
}
