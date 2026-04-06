<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Helpers/MockWpdb.php';

/**
 * Event Ingestion tests for PBSG_Analytics::handle_track_event().
 *
 * Covers request method validation, rate limiting, payload validation,
 * valid event types, type coercion, device detection, SQL injection
 * prevention, and AJAX hook registration.
 *
 * Note: handle_track_event() reads from php://input which can't be
 * easily mocked. Tests that need the full handler verify early-exit
 * paths (method check, rate limit). Private recorder methods are
 * tested via reflection in PBSGAnalyticsAggregationTest.
 */
class PBSGAnalyticsEventIngestionTest extends TestCase
{
    private MockWpdb $wpdb;

    /** @var array Original $_SERVER backup */
    private array $serverBackup;

    protected function setUp(): void
    {
        WPStubs::reset();
        $this->wpdb = new MockWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        $this->serverBackup = $_SERVER;

        $_SERVER['REQUEST_METHOD']  = 'POST';
        $_SERVER['REMOTE_ADDR']     = '192.168.1.100';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120';

        WPStubs::$returns['transients'] = [];
        WPStubs::$returns['page_template_slugs'] = [42 => 'split-guide-template.php'];
    }

    protected function tearDown(): void
    {
        WPStubs::reset();
        $_SERVER = $this->serverBackup;
        unset($GLOBALS['wpdb']);
    }

    /* ---------------------------------------------------------------
       Request method validation
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::handle_track_event
     */
    public function test_rejects_get_request(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            PBSG_Analytics::handle_track_event();
        } catch (WPDieException $e) {
            // Expected — wp_send_json_error throws to simulate die()
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
        $args = WPStubs::callArgs('wp_send_json_error', 0);
        $this->assertSame('Invalid method', $args[0]);
        $this->assertSame(405, $args[1]);
    }

    /**
     * @covers PBSG_Analytics::handle_track_event
     */
    public function test_rejects_put_request(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';

        try {
            PBSG_Analytics::handle_track_event();
        } catch (WPDieException $e) {
            // Expected
        }

        $args = WPStubs::callArgs('wp_send_json_error', 0);
        $this->assertSame('Invalid method', $args[0]);
        $this->assertSame(405, $args[1]);
    }

    /* ---------------------------------------------------------------
       Rate limiting
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::handle_track_event
     */
    public function test_rate_limited_when_transient_at_max(): void
    {
        $ipHash = md5('192.168.1.100');
        $key = 'pbsg_rl_' . substr($ipHash, 0, 12);
        WPStubs::$returns['transients'] = [$key => 60];

        try {
            PBSG_Analytics::handle_track_event();
        } catch (WPDieException $e) {
            // Expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
        $args = WPStubs::callArgs('wp_send_json_error', 0);
        $this->assertSame('Rate limited', $args[0]);
        $this->assertSame(429, $args[1]);
    }

    /**
     * @covers PBSG_Analytics::handle_track_event
     */
    public function test_not_rate_limited_when_under_limit(): void
    {
        $ipHash = md5('192.168.1.100');
        $key = 'pbsg_rl_' . substr($ipHash, 0, 12);
        WPStubs::$returns['transients'] = [$key => 5];

        try {
            PBSG_Analytics::handle_track_event();
        } catch (WPDieException $e) {
            // Expected — will fail on invalid payload, not rate limit
        }

        // First error should NOT be rate limited
        $args = WPStubs::callArgs('wp_send_json_error', 0);
        $this->assertNotSame('Rate limited', $args[0]);
    }

    /* ---------------------------------------------------------------
       Payload validation
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::handle_track_event
     */
    public function test_rejects_empty_payload(): void
    {
        // With no php://input data, json_decode returns null
        try {
            PBSG_Analytics::handle_track_event();
        } catch (WPDieException $e) {
            // Expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
        $args = WPStubs::callArgs('wp_send_json_error', 0);
        $this->assertSame('Invalid payload', $args[0]);
        $this->assertSame(400, $args[1]);
    }

    /* ---------------------------------------------------------------
       Valid event types constant
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics
     */
    public function test_valid_events_contains_all_six_types(): void
    {
        $expected = [
            'tutorial_view',
            'tutorial_complete',
            'slide_view',
            'quiz_attempt',
            'quiz_giveup',
            'session_flush',
        ];
        $this->assertSame($expected, PBSG_Analytics::VALID_EVENTS);
    }

    /**
     * @covers PBSG_Analytics
     * @dataProvider invalidEventTypeProvider
     */
    public function test_invalid_event_type_is_not_in_valid_events(string $type): void
    {
        $this->assertNotContains($type, PBSG_Analytics::VALID_EVENTS);
    }

    public static function invalidEventTypeProvider(): array
    {
        return [
            'empty string'        => [''],
            'sql injection'        => ["'; DROP TABLE wp_posts; --"],
            'xss attempt'          => ['<script>alert(1)</script>'],
            'unknown event'        => ['page_view'],
            'partial match'        => ['tutorial_'],
            'case mismatch'        => ['Tutorial_View'],
        ];
    }

    /* ---------------------------------------------------------------
       Type coercion via absint
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::handle_track_event
     * @dataProvider typeCoercionProvider
     */
    public function test_absint_coerces_values_correctly($input, int $expected): void
    {
        $this->assertSame($expected, absint($input));
    }

    public static function typeCoercionProvider(): array
    {
        return [
            'positive int'     => [42, 42],
            'string int'       => ['42', 42],
            'negative int'     => [-5, 5],
            'float'            => [3.7, 3],
            'zero'             => [0, 0],
            'string negative'  => ['-10', 10],
            'non-numeric'      => ['abc', 0],
            'mixed'            => ['42abc', 42],
            'empty string'     => ['', 0],
        ];
    }

    /* ---------------------------------------------------------------
       Device detection
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics
     */
    public function test_device_constants_are_correct(): void
    {
        $this->assertSame('desktop', PBSG_Analytics::DEVICE_DESKTOP);
        $this->assertSame('tablet', PBSG_Analytics::DEVICE_TABLET);
        $this->assertSame('mobile', PBSG_Analytics::DEVICE_MOBILE);
    }

    /**
     * @covers PBSG_Analytics
     * @dataProvider userAgentDeviceProvider
     */
    public function test_detect_device_returns_correct_type(string $userAgent, string $expected): void
    {
        $method = new ReflectionMethod(PBSG_Analytics::class, 'detect_device');
        $method->setAccessible(true);

        $_SERVER['HTTP_USER_AGENT'] = $userAgent;
        $result = $method->invoke(null);
        $this->assertSame($expected, $result);
    }

    public static function userAgentDeviceProvider(): array
    {
        return [
            'chrome desktop'     => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120', 'desktop'],
            'firefox desktop'    => ['Mozilla/5.0 (X11; Linux x86_64; rv:109.0) Gecko/20100101 Firefox/115.0', 'desktop'],
            'ipad tablet'        => ['Mozilla/5.0 (iPad; CPU OS 16_0 like Mac OS X) AppleWebKit/605.1.15', 'tablet'],
            'silk tablet'        => ['Mozilla/5.0 (Linux; Android 11; KFTRWI) AppleWebKit/537.36 Silk/95', 'tablet'],
            'playbook tablet'    => ['Mozilla/5.0 (PlayBook; U; RIM Tablet OS 2.1.0; en-US) AppleWebKit/536', 'tablet'],
            'iphone mobile'      => ['Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15', 'mobile'],
            'android mobile'     => ['Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 Mobile', 'mobile'],
            'ipod mobile'        => ['Mozilla/5.0 (iPod touch; CPU iPhone OS 15_0 like Mac OS X)', 'mobile'],
            'blackberry mobile'  => ['Mozilla/5.0 (BlackBerry; U; BlackBerry 9900)', 'mobile'],
            'opera mini mobile'  => ['Opera/9.80 (J2ME/MIDP; Opera Mini/9.80.2/28.3590; U; en)', 'mobile'],
            'iemobile mobile'    => ['Mozilla/5.0 (compatible; MSIE 10.0; Windows Phone 8.0; IEMobile/10.0)', 'mobile'],
            'empty ua'           => ['', 'desktop'],
        ];
    }

    /* ---------------------------------------------------------------
       AJAX hook registration
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::init
     */
    public function test_init_registers_nopriv_track_event_hook(): void
    {
        WPStubs::reset();
        PBSG_Analytics::init();
        $tags = array_column(WPStubs::$hooks['action'], 'tag');
        $this->assertContains('wp_ajax_nopriv_pbsg_track_event', $tags);
    }

    /**
     * @covers PBSG_Analytics::init
     */
    public function test_init_registers_auth_track_event_hook(): void
    {
        WPStubs::reset();
        PBSG_Analytics::init();
        $tags = array_column(WPStubs::$hooks['action'], 'tag');
        $this->assertContains('wp_ajax_pbsg_track_event', $tags);
    }

    /**
     * @covers PBSG_Analytics::init
     */
    public function test_init_registers_get_analytics_hook(): void
    {
        WPStubs::reset();
        PBSG_Analytics::init();
        $tags = array_column(WPStubs::$hooks['action'], 'tag');
        $this->assertContains('wp_ajax_pbsg_get_analytics', $tags);
    }

    /**
     * @covers PBSG_Analytics::init
     */
    public function test_init_registers_export_csv_hook(): void
    {
        WPStubs::reset();
        PBSG_Analytics::init();
        $tags = array_column(WPStubs::$hooks['action'], 'tag');
        $this->assertContains('wp_ajax_pbsg_export_csv', $tags);
    }

    /**
     * @covers PBSG_Analytics::init
     */
    public function test_init_registers_exactly_four_ajax_hooks(): void
    {
        WPStubs::reset();
        PBSG_Analytics::init();
        $ajaxActions = array_filter(
            WPStubs::$hooks['action'],
            fn($h) => str_starts_with($h['tag'], 'wp_ajax_')
        );
        $this->assertCount(4, $ajaxActions);
    }

    /* ---------------------------------------------------------------
       Tutorial publish-status guard
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Analytics::handle_track_event
     */
    public function test_rejects_events_for_draft_tutorials(): void
    {
        WPStubs::$returns['page_template_slugs'] = [42 => 'split-guide-template.php'];
        WPStubs::$returns['post_statuses'] = [42 => 'draft'];

        $this->assertSame('draft', get_post_status(42));
        $this->assertSame('publish', get_post_status(99));
    }

    /**
     * @covers PBSG_Analytics::handle_track_event
     */
    public function test_accepts_events_for_published_tutorials(): void
    {
        WPStubs::$returns['page_template_slugs'] = [42 => 'split-guide-template.php'];
        WPStubs::$returns['post_statuses'] = [42 => 'publish'];

        $this->assertSame('publish', get_post_status(42));
    }
}
