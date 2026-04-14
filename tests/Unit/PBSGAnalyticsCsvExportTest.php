<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Helpers/MockWpdb.php';

/**
 * CSV Export tests for PBSG_Analytics::handle_export_csv().
 *
 * Covers admin-only access enforcement, export type routing,
 * and source-level verification of CSV column definitions.
 *
 * Note: handle_export_csv() calls header() and exit, which don't work
 * in a test environment with prior output. Tests verify access control
 * via WPDieException and verify CSV column definitions from source code.
 */
class PBSGAnalyticsCsvExportTest extends TestCase
{
    private MockWpdb $wpdb;
    private string $sourceCode;

    /** @var array Backup of $_GET */
    private array $getBackup;

    protected function setUp(): void
    {
        WPStubs::reset();
        $this->wpdb = new MockWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        $this->getBackup = $_GET;
        $_GET = [];

        $this->sourceCode = file_get_contents(
            dirname(__DIR__, 2) . '/web/app/plugins/pb-split-guide/class-pbsg-analytics.php'
        );
    }

    protected function tearDown(): void
    {
        WPStubs::reset();
        $_GET = $this->getBackup;
        unset($GLOBALS['wpdb']);
    }

    /* ---------------------------------------------------------------
       Admin-only access
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::handle_export_csv
     */
    public function test_export_csv_rejects_unauthorized_user(): void
    {
        WPStubs::$returns['current_user_can'] = false;

        try {
            PBSG_Analytics::handle_export_csv();
        } catch (WPDieException $e) {
            // Expected — wp_die throws
        }

        $this->assertTrue(WPStubs::wasCalled('wp_die'));
        $args = WPStubs::callArgs('wp_die', 0);
        $this->assertSame('Unauthorized', $args[0]);
    }

    /**
     * @covers PBSG_Analytics::handle_export_csv
     */
    public function test_export_csv_checks_capability(): void
    {
        WPStubs::$returns['current_user_can'] = false;

        try {
            PBSG_Analytics::handle_export_csv();
        } catch (WPDieException $e) {
            // Expected
        }

        // The source verifies pbsg_export_csv capability
        $this->assertStringContainsString("current_user_can( 'pbsg_export_csv' )", $this->sourceCode);
    }

    /* ---------------------------------------------------------------
       Overview CSV column definitions (verified from source)
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::handle_export_csv
     */
    public function test_overview_csv_source_has_correct_headers(): void
    {
        $expectedHeaders = [
            'Tutorial',
            'Views',
            'Completions',
            'Completion Rate (%)',
            'Avg Score (%)',
            'Avg Time (s)',
        ];

        foreach ($expectedHeaders as $header) {
            $this->assertStringContainsString(
                "'" . $header . "'",
                $this->sourceCode,
                "Overview CSV should include header: {$header}"
            );
        }
    }

    /**
     * @covers PBSG_Analytics::handle_export_csv
     */
    public function test_overview_csv_filename_includes_date_range(): void
    {
        // Source uses: 'tutorial-analytics-overview-' . $date_from . '-to-' . $date_to . '.csv'
        $this->assertStringContainsString(
            "tutorial-analytics-overview-",
            $this->sourceCode
        );
        $this->assertStringContainsString(
            "-to-",
            $this->sourceCode
        );
    }

    /* ---------------------------------------------------------------
       Questions CSV column definitions (verified from source)
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::handle_export_csv
     */
    public function test_questions_csv_source_has_correct_headers(): void
    {
        $expectedHeaders = [
            'Question',
            'H5P ID',
            'Attempts',
            'Correct',
            'Incorrect',
            'Give-ups',
            'Correct Rate (%)',
            'Avg Attempts',
            'Incorrect Attempts',
            'Total Retries',
            'Max Retries',
        ];

        foreach ($expectedHeaders as $header) {
            $this->assertStringContainsString(
                "'" . $header . "'",
                $this->sourceCode,
                "Questions CSV should include header: {$header}"
            );
        }
    }

    /* ---------------------------------------------------------------
       Question detail CSV column definitions (verified from source)
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::handle_export_csv
     */
    public function test_question_detail_csv_source_has_attempt_columns(): void
    {
        $attemptHeaders = [
            '1st Attempt Correct',
            '2nd Attempt Correct',
            '3rd+ Attempt Correct',
        ];

        foreach ($attemptHeaders as $header) {
            $this->assertStringContainsString(
                "'" . $header . "'",
                $this->sourceCode,
                "Question detail CSV should include header: {$header}"
            );
        }
    }

    /**
     * @covers PBSG_Analytics::handle_export_csv
     */
    public function test_question_detail_csv_source_has_all_headers(): void
    {
        $expectedHeaders = [
            'Question',
            'H5P ID',
            'Question Index',
            'Total Attempts',
            'Correct Rate (%)',
            'Avg Attempts',
            'Avg Time (s)',
            'Incorrect Attempts',
            'Total Retries',
            'Max Retries',
        ];

        foreach ($expectedHeaders as $header) {
            $this->assertStringContainsString(
                "'" . $header . "'",
                $this->sourceCode,
                "Question detail CSV should include header: {$header}"
            );
        }
    }

    /* ---------------------------------------------------------------
       Compare CSV column definitions (verified from source)
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::handle_export_csv
     */
    public function test_compare_csv_source_has_metric_column(): void
    {
        $this->assertStringContainsString("'Metric'", $this->sourceCode);
        // Headers are now dynamic — built from actual tutorial names via $t['name']
        $this->assertStringContainsString("\$t['name']", $this->sourceCode);
    }

    /**
     * @covers PBSG_Analytics::handle_export_csv
     */
    public function test_compare_csv_source_includes_all_metrics(): void
    {
        $metrics = [
            'Views',
            'Completions',
            'Completion Rate (%)',
            'Avg Time (s)',
            'Avg Score (%)',
            'First Attempt Rate (%)',
            'Avg Attempts',
            'Give-up Rate (%)',
        ];

        foreach ($metrics as $metric) {
            $this->assertStringContainsString(
                "'" . $metric . "'",
                $this->sourceCode,
                "Compare CSV should include metric: {$metric}"
            );
        }
    }

    /* ---------------------------------------------------------------
       CSV output configuration (verified from source)
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::handle_export_csv
     */
    public function test_csv_sets_content_type_header(): void
    {
        $this->assertStringContainsString(
            "Content-Type: text/csv; charset=utf-8",
            $this->sourceCode
        );
    }

    /**
     * @covers PBSG_Analytics::handle_export_csv
     */
    public function test_csv_sets_content_disposition_header(): void
    {
        $this->assertStringContainsString(
            "Content-Disposition: attachment",
            $this->sourceCode
        );
    }

    /**
     * @covers PBSG_Analytics::handle_export_csv
     */
    public function test_csv_sets_cache_control_header(): void
    {
        $this->assertStringContainsString(
            "Cache-Control: no-cache, no-store, must-revalidate",
            $this->sourceCode
        );
    }

    /**
     * @covers PBSG_Analytics::handle_export_csv
     */
    public function test_csv_uses_fputcsv_for_proper_escaping(): void
    {
        // fputcsv handles CSV escaping (quotes, commas, newlines) automatically
        $fputcsvCount = substr_count($this->sourceCode, 'fputcsv(');
        $this->assertGreaterThan(0, $fputcsvCount, 'Should use fputcsv for proper escaping');
    }

    /**
     * @covers PBSG_Analytics::handle_export_csv
     */
    public function test_csv_writes_to_php_output(): void
    {
        $this->assertStringContainsString(
            "fopen( 'php://output', 'w' )",
            $this->sourceCode
        );
    }

    /* ---------------------------------------------------------------
       Export type routing (verified from source)
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::handle_export_csv
     */
    public function test_csv_supports_four_export_types(): void
    {
        // Verify all 4 export types are handled
        $this->assertStringContainsString("'overview' === \$export_type", $this->sourceCode);
        $this->assertStringContainsString("'questions' === \$export_type", $this->sourceCode);
        $this->assertStringContainsString("'question_detail' === \$export_type", $this->sourceCode);
        $this->assertStringContainsString("'compare' === \$export_type", $this->sourceCode);
    }

    /**
     * @covers PBSG_Analytics::handle_export_csv
     */
    public function test_csv_default_export_type_is_overview(): void
    {
        // Source: sanitize_text_field( $_GET['type'] ) : 'overview'
        $this->assertStringContainsString(": 'overview'", $this->sourceCode);
    }
}
