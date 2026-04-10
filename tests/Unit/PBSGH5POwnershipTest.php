<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Helpers/MockWpdb.php';

/**
 * Unit tests for H5P ownership endpoints: rename and duplicate.
 */
class PBSGH5POwnershipTest extends TestCase
{
    private MockWpdb $wpdb;

    protected function setUp(): void
    {
        WPStubs::reset();
        $this->wpdb = new MockWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
        WPStubs::$returns['current_user_can'] = true;
    }

    protected function tearDown(): void
    {
        WPStubs::reset();
        unset($GLOBALS['wpdb']);
        unset($_POST['h5p_id'], $_POST['title'], $_POST['post_title'], $_POST['step_index']);
    }

    public function test_rename_succeeds_for_owner(): void
    {
        WPStubs::$returns['get_current_user_id'] = 5;
        $this->wpdb->returns['get_var'] = '5';

        $_POST['h5p_id'] = '42';
        $_POST['title'] = 'New Quiz Name';

        $plugin = new PB_Split_Guide_Plugin();

        try {
            $plugin->ajax_rename_h5p();
        } catch (WPDieException $e) {
            // Expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_success'));

        $updateCalls = array_filter($this->wpdb->calls, fn($c) => $c['method'] === 'update');
        $this->assertNotEmpty($updateCalls);
        $lastUpdate = end($updateCalls);
        $this->assertSame(['title' => 'New Quiz Name'], $lastUpdate['args'][1]);
        $this->assertSame(['id' => 42], $lastUpdate['args'][2]);
    }

    public function test_rename_rejects_non_owner(): void
    {
        WPStubs::$returns['get_current_user_id'] = 5;
        $this->wpdb->returns['get_var'] = '99';

        $_POST['h5p_id'] = '42';
        $_POST['title'] = 'New Quiz Name';

        $plugin = new PB_Split_Guide_Plugin();

        try {
            $plugin->ajax_rename_h5p();
        } catch (WPDieException $e) {
            // Expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
        $args = WPStubs::callArgs('wp_send_json_error', 0);
        $this->assertStringContainsString('owner', strtolower($args[0]['message']));
    }

    public function test_rename_rejects_empty_title(): void
    {
        WPStubs::$returns['get_current_user_id'] = 5;
        $this->wpdb->returns['get_var'] = '5';

        $_POST['h5p_id'] = '42';
        $_POST['title'] = '   ';

        $plugin = new PB_Split_Guide_Plugin();

        try {
            $plugin->ajax_rename_h5p();
        } catch (WPDieException $e) {
            // Expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
    }

    public function test_rename_checks_referer(): void
    {
        WPStubs::$returns['get_current_user_id'] = 5;
        $this->wpdb->returns['get_var'] = '5';

        $_POST['h5p_id'] = '42';
        $_POST['title'] = 'Test';

        $plugin = new PB_Split_Guide_Plugin();

        try {
            $plugin->ajax_rename_h5p();
        } catch (WPDieException $e) {
            // Expected
        }

        $this->assertTrue(WPStubs::wasCalled('check_ajax_referer'));
        $args = WPStubs::callArgs('check_ajax_referer', 0);
        $this->assertSame('pbsg_h5p_picker', $args[0]);
    }

    public function test_duplicate_returns_error_for_missing_source(): void
    {
        $_POST['h5p_id'] = '999';
        $_POST['post_title'] = 'Tutorial 7';
        $_POST['step_index'] = '4';

        $this->wpdb->returns['get_row'] = null;

        $plugin = new PB_Split_Guide_Plugin();

        try {
            $plugin->ajax_duplicate_h5p();
        } catch (WPDieException $e) {
            // Expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
        $args = WPStubs::callArgs('wp_send_json_error', 0);
        $this->assertStringContainsString('not found', strtolower($args[0]['message']));
    }

    public function test_duplicate_checks_referer_and_capability(): void
    {
        $_POST['h5p_id'] = '42';
        $_POST['post_title'] = 'Tutorial 7';
        $_POST['step_index'] = '4';
        $this->wpdb->returns['get_row'] = null;

        $plugin = new PB_Split_Guide_Plugin();

        try {
            $plugin->ajax_duplicate_h5p();
        } catch (WPDieException $e) {
            // Expected
        }

        $this->assertTrue(WPStubs::wasCalled('check_ajax_referer'));
        $args = WPStubs::callArgs('check_ajax_referer', 0);
        $this->assertSame('pbsg_h5p_picker', $args[0]);
    }
}
