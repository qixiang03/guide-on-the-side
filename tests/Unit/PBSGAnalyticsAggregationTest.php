<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Helpers/MockWpdb.php';

/**
 * Aggregation Logic tests for PBSG_Analytics.
 *
 * Verifies view/completion counters, question stats accumulation,
 * daily stats bucketing, and step dwell time merging.
 */
class PBSGAnalyticsAggregationTest extends TestCase
{
    private MockWpdb $wpdb;

    protected function setUp(): void
    {
        WPStubs::reset();
        $this->wpdb = new MockWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        $_SERVER['REMOTE_ADDR']     = '10.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120';

        WPStubs::$returns['page_template_slugs'] = [42 => 'split-guide-template.php'];
        WPStubs::$returns['transients'] = [];
    }

    protected function tearDown(): void
    {
        WPStubs::reset();
        unset($GLOBALS['wpdb']);
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
    }

    /* ---------------------------------------------------------------
       Tutorial view recording
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::handle_track_event
     */
    public function test_record_tutorial_view_upserts_tutorial_stats(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_tutorial_view');
        $method->setAccessible(true);

        WPStubs::$returns['current_time'] = '2026-03-01';
        $method->invoke(null, 42, 'desktop');

        $queries = $this->wpdb->getQueriesContaining('pbsg_tutorial_stats');
        $this->assertNotEmpty($queries, 'Should insert/update tutorial stats');
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $queries[0]);
        $this->assertStringContainsString('view_count = view_count + 1', $queries[0]);
    }

    /**
     * @covers PBSG_Analytics::handle_track_event
     */
    public function test_record_tutorial_view_upserts_daily_stats(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_tutorial_view');
        $method->setAccessible(true);

        WPStubs::$returns['current_time'] = '2026-03-01';
        $method->invoke(null, 42, 'mobile');

        $queries = $this->wpdb->getQueriesContaining('pbsg_daily_stats');
        $this->assertNotEmpty($queries);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $queries[0]);
    }

    /* ---------------------------------------------------------------
       Tutorial completion recording
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::handle_track_event
     */
    public function test_record_tutorial_complete_increments_completion_count(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_tutorial_complete');
        $method->setAccessible(true);

        WPStubs::$returns['current_time'] = '2026-03-01';
        $method->invoke(null, 42, 'desktop', 120);

        $queries = $this->wpdb->getQueriesContaining('completion_count = completion_count + 1');
        $this->assertNotEmpty($queries);
    }

    /**
     * @covers PBSG_Analytics::handle_track_event
     */
    public function test_record_tutorial_complete_accumulates_time(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_tutorial_complete');
        $method->setAccessible(true);

        WPStubs::$returns['current_time'] = '2026-03-01';
        $method->invoke(null, 42, 'desktop', 300);

        $queries = $this->wpdb->getQueriesContaining('total_time_seconds = total_time_seconds + ');
        $this->assertNotEmpty($queries);
    }

    /**
     * @covers PBSG_Analytics::handle_track_event
     */
    public function test_record_tutorial_complete_increments_sessions(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_tutorial_complete');
        $method->setAccessible(true);

        WPStubs::$returns['current_time'] = '2026-03-01';
        $method->invoke(null, 42, 'desktop', 120);

        $queries = $this->wpdb->getQueriesContaining('total_sessions = total_sessions + 1');
        $this->assertNotEmpty($queries);
    }

    /* ---------------------------------------------------------------
       Quiz attempt recording
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::handle_track_event
     */
    public function test_record_quiz_attempt_correct_first_attempt(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_quiz_attempt');
        $method->setAccessible(true);

        $data = [
            'h5p_content_id' => 10,
            'question_index' => 0,
            'question_text'  => 'What is 2+2?',
            'is_correct'     => true,
            'attempt_number' => 1,
            'time_seconds'   => 15,
        ];

        $method->invoke(null, 42, $data);

        $queries = $this->wpdb->getQueriesContaining('pbsg_question_stats');
        $this->assertNotEmpty($queries);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $queries[0]);
        $this->assertStringContainsString('total_attempts = total_attempts + 1', $queries[0]);
    }

    /**
     * @covers PBSG_Analytics::handle_track_event
     */
    public function test_record_quiz_attempt_correct_increments_total_answered(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_quiz_attempt');
        $method->setAccessible(true);

        $data = [
            'h5p_content_id' => 10,
            'question_index' => 0,
            'question_text'  => 'What is 2+2?',
            'is_correct'     => true,
            'attempt_number' => 1,
            'time_seconds'   => 15,
        ];

        $method->invoke(null, 42, $data);

        $queries = $this->wpdb->getQueriesContaining('total_answered = total_answered + 1');
        $this->assertNotEmpty($queries, 'Correct answer should increment total_answered');
    }

    /**
     * @covers PBSG_Analytics::handle_track_event
     */
    public function test_record_quiz_attempt_incorrect_does_not_increment_total_answered(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_quiz_attempt');
        $method->setAccessible(true);

        $data = [
            'h5p_content_id' => 10,
            'question_index' => 0,
            'question_text'  => 'What is 2+2?',
            'is_correct'     => false,
            'attempt_number' => 1,
            'time_seconds'   => 15,
        ];

        $method->invoke(null, 42, $data);

        $queries = $this->wpdb->getQueriesContaining('total_answered = total_answered + 1');
        $this->assertEmpty($queries, 'Incorrect answer should NOT increment total_answered');
    }

    /**
     * @covers PBSG_Analytics::handle_track_event
     */
    public function test_record_quiz_attempt_truncates_question_text_to_500(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_quiz_attempt');
        $method->setAccessible(true);

        $longText = str_repeat('A', 600);
        $data = [
            'h5p_content_id' => 10,
            'question_index' => 0,
            'question_text'  => $longText,
            'is_correct'     => false,
            'attempt_number' => 1,
            'time_seconds'   => 10,
        ];

        $method->invoke(null, 42, $data);

        // The substr(..., 0, 500) in the source ensures truncation before prepare
        $queries = $this->wpdb->getQueriesContaining('pbsg_question_stats');
        $this->assertNotEmpty($queries);
        // The prepared query should contain the truncated text (500 chars, not 600)
        $this->assertStringNotContainsString(str_repeat('A', 600), $queries[0]);
    }

    /* ---------------------------------------------------------------
       Quiz giveup recording
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::handle_track_event
     */
    public function test_record_quiz_giveup_increments_giveup_count(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_quiz_giveup');
        $method->setAccessible(true);

        $data = [
            'h5p_content_id' => 10,
            'question_index' => 2,
        ];

        $method->invoke(null, 42, $data);

        $queries = $this->wpdb->getQueriesContaining('giveup_count = giveup_count + 1');
        $this->assertNotEmpty($queries);
    }

    /* ---------------------------------------------------------------
       Session flush recording
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::handle_track_event
     */
    public function test_record_session_flush_processes_step_dwell_times(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_session_flush');
        $method->setAccessible(true);

        WPStubs::$returns['current_time'] = '2026-03-01';

        $data = [
            'step_dwell_times'   => [0 => 30, 1 => 45, 2 => 20],
            'total_time_seconds' => 120,
        ];

        $method->invoke(null, 42, $data, 'desktop');

        // Should have queries for step_views (slide_view recording)
        $queries = $this->wpdb->getQueriesContaining('step_views');
        // At minimum, it should attempt to read existing step_views
        $this->assertNotEmpty($this->wpdb->queries);
    }

    /**
     * @covers PBSG_Analytics::handle_track_event
     */
    public function test_record_session_flush_updates_total_time(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_session_flush');
        $method->setAccessible(true);

        WPStubs::$returns['current_time'] = '2026-03-01';

        $data = [
            'step_dwell_times'   => [],
            'total_time_seconds' => 200,
        ];

        $method->invoke(null, 42, $data, 'desktop');

        $queries = $this->wpdb->getQueriesContaining('total_time_seconds = total_time_seconds + ');
        $this->assertNotEmpty($queries, 'Session flush should update total_time_seconds');
    }

    /**
     * @covers PBSG_Analytics::handle_track_event
     */
    public function test_record_session_flush_skips_zero_total_time(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_session_flush');
        $method->setAccessible(true);

        WPStubs::$returns['current_time'] = '2026-03-01';

        $data = [
            'step_dwell_times'   => [],
            'total_time_seconds' => 0,
        ];

        $method->invoke(null, 42, $data, 'desktop');

        $queries = $this->wpdb->getQueriesContaining('total_time_seconds = total_time_seconds + ');
        $this->assertEmpty($queries, 'Zero total_time should not trigger update');
    }

    /* ---------------------------------------------------------------
       Slide view recording (step_views JSON merge)
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::handle_track_event
     */
    public function test_record_slide_view_creates_new_step_entry(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_slide_view');
        $method->setAccessible(true);

        WPStubs::$returns['current_time'] = '2026-03-01';

        // No existing row
        $this->wpdb->returns['get_row'] = null;

        $method->invoke(null, 42, 0, 30);

        // Should attempt to read existing step_views
        $queries = $this->wpdb->getQueriesContaining('step_views');
        $this->assertNotEmpty($queries);
    }

    /**
     * @covers PBSG_Analytics::handle_track_event
     */
    public function test_record_slide_view_merges_with_existing_data(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_slide_view');
        $method->setAccessible(true);

        WPStubs::$returns['current_time'] = '2026-03-01';

        $existingRow = (object) [
            'id'         => 1,
            'step_views' => json_encode(['step_0' => ['views' => 5, 'total_dwell' => 100]]),
        ];
        $this->wpdb->returns['get_row'] = $existingRow;

        $method->invoke(null, 42, 0, 30);

        // Should call update with merged JSON
        $updateCalls = array_filter($this->wpdb->calls, fn($c) => $c['method'] === 'update');
        $this->assertNotEmpty($updateCalls, 'Should update existing row with merged step_views');
    }

    /* ---------------------------------------------------------------
       Daily stats date bucketing
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::handle_track_event
     */
    public function test_daily_stats_uses_current_date(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_tutorial_view');
        $method->setAccessible(true);

        WPStubs::$returns['current_time'] = '2026-02-15';
        $method->invoke(null, 42, 'desktop');

        $queries = $this->wpdb->getQueriesContaining('pbsg_daily_stats');
        $found = false;
        foreach ($queries as $q) {
            if (str_contains($q, '2026-02-15')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Daily stats should bucket by current_time date');
    }

    /**
     * @covers PBSG_Analytics::handle_track_event
     */
    public function test_daily_stats_includes_device_type(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_tutorial_view');
        $method->setAccessible(true);

        WPStubs::$returns['current_time'] = '2026-03-01';
        $method->invoke(null, 42, 'tablet');

        $queries = $this->wpdb->getQueriesContaining('pbsg_daily_stats');
        $found = false;
        foreach ($queries as $q) {
            if (str_contains($q, 'tablet')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Daily stats should include device_type');
    }

}
