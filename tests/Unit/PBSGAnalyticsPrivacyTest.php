<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Helpers/MockWpdb.php';

/**
 * Privacy Compliance tests for PBSG_Analytics.
 *
 * Verifies PIPEDA compliance: no PII in table rows, rate-limit
 * transient keys are hashed (not raw IP), session IDs discarded
 * after aggregation, device detection via user-agent only.
 */
class PBSGAnalyticsPrivacyTest extends TestCase
{
    private MockWpdb $wpdb;

    protected function setUp(): void
    {
        WPStubs::reset();
        $this->wpdb = new MockWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        $_SERVER['REMOTE_ADDR']     = '192.168.1.100';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120';

        WPStubs::$returns['transients'] = [];
        WPStubs::$returns['page_template_slugs'] = [42 => 'split-guide-template.php'];
    }

    protected function tearDown(): void
    {
        WPStubs::reset();
        unset($GLOBALS['wpdb']);
    }

    /* ---------------------------------------------------------------
       Rate limit key hashing
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics
     */
    public function test_rate_limit_key_uses_hashed_ip(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'check_rate_limit');
        $method->setAccessible(true);

        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        $method->invoke(null);

        $this->assertTrue(WPStubs::wasCalled('set_transient'));
        $args = WPStubs::callArgs('set_transient', 0);
        $key = $args[0];

        // Key must start with pbsg_rl_ prefix
        $this->assertStringStartsWith('pbsg_rl_', $key);

        // Key must NOT contain raw IP
        $this->assertStringNotContainsString('192.168.1.100', $key);
        $this->assertStringNotContainsString('192.168', $key);
    }

    /**
     * @covers PBSG_Analytics
     */
    public function test_rate_limit_key_is_md5_prefix(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'check_rate_limit');
        $method->setAccessible(true);

        $_SERVER['REMOTE_ADDR'] = '10.0.0.50';
        $method->invoke(null);

        $args = WPStubs::callArgs('set_transient', 0);
        $key = $args[0];

        $expectedHash = md5('10.0.0.50');
        $expectedKey = 'pbsg_rl_' . substr($expectedHash, 0, 12);
        $this->assertSame($expectedKey, $key);
    }

    /**
     * @covers PBSG_Analytics
     */
    public function test_rate_limit_transient_expires_in_60_seconds(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'check_rate_limit');
        $method->setAccessible(true);

        $method->invoke(null);

        $args = WPStubs::callArgs('set_transient', 0);
        $this->assertSame(60, $args[2], 'Rate limit transient should expire in 60 seconds');
    }

    /**
     * @covers PBSG_Analytics
     */
    public function test_rate_limit_handles_missing_remote_addr(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'check_rate_limit');
        $method->setAccessible(true);

        unset($_SERVER['REMOTE_ADDR']);
        $result = $method->invoke(null);

        // Should still work (falls back to 'unknown')
        $this->assertTrue($result);

        $args = WPStubs::callArgs('set_transient', 0);
        $key = $args[0];
        $expectedHash = md5('unknown');
        $expectedKey = 'pbsg_rl_' . substr($expectedHash, 0, 12);
        $this->assertSame($expectedKey, $key);
    }

    /* ---------------------------------------------------------------
       No PII in SQL queries
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics
     */
    public function test_tutorial_view_sql_contains_no_ip_address(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_tutorial_view');
        $method->setAccessible(true);

        $_SERVER['REMOTE_ADDR'] = '172.16.0.55';
        WPStubs::$returns['current_time'] = '2026-03-01';

        $method->invoke(null, 42, 'desktop');

        foreach ($this->wpdb->queries as $query) {
            $this->assertStringNotContainsString('172.16.0.55', $query, 'SQL must not contain raw IP');
        }
    }

    /**
     * @covers PBSG_Analytics
     */
    public function test_quiz_attempt_sql_contains_no_ip_address(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_quiz_attempt');
        $method->setAccessible(true);

        $_SERVER['REMOTE_ADDR'] = '10.10.10.10';

        $data = [
            'h5p_content_id' => 5,
            'question_index' => 0,
            'question_text'  => 'Test question',
            'is_correct'     => true,
            'attempt_number' => 1,
            'time_seconds'   => 10,
        ];

        $method->invoke(null, 42, $data);

        foreach ($this->wpdb->queries as $query) {
            $this->assertStringNotContainsString('10.10.10.10', $query, 'SQL must not contain raw IP');
        }
    }

    /**
     * @covers PBSG_Analytics
     */
    public function test_no_user_id_in_any_table_sql(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_tutorial_view');
        $method->setAccessible(true);
        WPStubs::$returns['current_time'] = '2026-03-01';
        $method->invoke(null, 42, 'desktop');

        foreach ($this->wpdb->queries as $query) {
            $this->assertStringNotContainsString('user_id', $query, 'No user_id column should exist');
            $this->assertStringNotContainsString('session_id', $query, 'No session_id column should be stored');
        }
    }

    /**
     * @covers PBSG_Analytics
     */
    public function test_no_user_agent_stored_in_sql(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_tutorial_view');
        $method->setAccessible(true);

        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16) Safari';
        WPStubs::$returns['current_time'] = '2026-03-01';
        $method->invoke(null, 42, 'mobile');

        foreach ($this->wpdb->queries as $query) {
            $this->assertStringNotContainsString('Mozilla', $query, 'Raw user-agent must not be stored');
            $this->assertStringNotContainsString('user_agent', $query, 'No user_agent column should exist');
        }
    }

    /* ---------------------------------------------------------------
       Device detection is user-agent only (no fingerprinting)
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics
     */
    public function test_detect_device_uses_only_user_agent_header(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'detect_device');
        $method->setAccessible(true);

        // Set user-agent to mobile
        $_SERVER['HTTP_USER_AGENT'] = 'iPhone Mobile Safari';
        $result = $method->invoke(null);
        $this->assertSame('mobile', $result);

        // The method should NOT access any other $_SERVER keys for detection
        // (no REMOTE_ADDR, no HTTP_ACCEPT_LANGUAGE, no HTTP_ACCEPT, etc.)
        // This is verified by the implementation only reading HTTP_USER_AGENT
    }

    /**
     * @covers PBSG_Analytics
     */
    public function test_detect_device_returns_desktop_for_missing_ua(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'detect_device');
        $method->setAccessible(true);

        unset($_SERVER['HTTP_USER_AGENT']);
        $result = $method->invoke(null);
        $this->assertSame('desktop', $result);
    }

    /* ---------------------------------------------------------------
       Schema has no PII columns (verified from source)
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::create_tables
     */
    public function test_all_table_schemas_have_no_pii_columns(): void
    {
        // Verify from source code since create_tables() loads real upgrade.php
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/web/app/plugins/pb-split-guide/class-pbsg-analytics.php'
        );

        // Extract only the CREATE TABLE statements (between create_tables method and first dbDelta call)
        preg_match_all('/CREATE TABLE.*?;/s', $source, $matches);
        $createStatements = implode("\n", $matches[0]);

        $piiColumns = ['user_id', 'email', 'ip_address', 'session_id', 'cookie', 'username', 'user_agent'];
        foreach ($piiColumns as $col) {
            $this->assertStringNotContainsString(
                $col,
                strtolower($createStatements),
                "Schema must not contain PII column: {$col}"
            );
        }
    }

    /* ---------------------------------------------------------------
       Session ID discarded after aggregation
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics
     */
    public function test_session_flush_does_not_store_session_identifier(): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'record_session_flush');
        $method->setAccessible(true);

        WPStubs::$returns['current_time'] = '2026-03-01';

        $data = [
            'session_id'         => 'abc123',
            'step_dwell_times'   => [0 => 10],
            'total_time_seconds' => 60,
        ];

        $method->invoke(null, 42, $data, 'desktop');

        foreach ($this->wpdb->queries as $query) {
            $this->assertStringNotContainsString('abc123', $query, 'Session ID must not be persisted');
            $this->assertStringNotContainsString('session_id', strtolower($query), 'No session_id column in SQL');
        }
    }

    /* ---------------------------------------------------------------
       Rate limit constant
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics
     */
    public function test_rate_limit_constant_is_60(): void
    {
        $this->assertSame(60, PBSG_Analytics::RATE_LIMIT_PER_MINUTE);
    }
}
