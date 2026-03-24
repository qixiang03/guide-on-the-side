<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Helpers/MockWpdb.php';

/**
 * Unit tests for PB_Split_Guide_Plugin::ajax_list_h5p().
 *
 * Covers H5P table existence check, error when table missing,
 * success response and items structure when table exists.
 */
class PBSGH5PAjaxTest extends TestCase
{
    private MockWpdb $wpdb;

    protected function setUp(): void
    {
        WPStubs::reset();
        $this->wpdb = new MockWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        // Grant the RBAC capability so the handler reaches DB logic
        WPStubs::$returns['current_user_can'] = true;
    }

    protected function tearDown(): void
    {
        WPStubs::reset();
        unset($GLOBALS['wpdb']);
    }

    /**
     * @covers PB_Split_Guide_Plugin::ajax_list_h5p
     */
    public function test_returns_error_when_h5p_table_does_not_exist(): void
    {
        $table = $this->wpdb->prefix . 'h5p_contents';
        $this->wpdb->returns['get_var'] = null;

        $plugin = new PB_Split_Guide_Plugin();

        try {
            $plugin->ajax_list_h5p();
        } catch (WPDieException $e) {
            // Expected — wp_send_json_error throws
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
        $args = WPStubs::callArgs('wp_send_json_error', 0);
        $this->assertSame(['message' => 'H5P table not found. Are you using the standard H5P plugin?'], $args[0]);
    }

    /**
     * @covers PB_Split_Guide_Plugin::ajax_list_h5p
     */
    public function test_returns_success_with_items_when_h5p_table_exists(): void
    {
        $table = $this->wpdb->prefix . 'h5p_contents';
        $this->wpdb->returns['get_var'] = $table;
        $this->wpdb->returns['get_results'] = [
            ['id' => '1', 'title' => 'Quiz One'],
            ['id' => '2', 'title' => ''],
        ];

        $plugin = new PB_Split_Guide_Plugin();

        try {
            $plugin->ajax_list_h5p();
        } catch (WPDieException $e) {
            // Expected — wp_send_json_success throws
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_success'));
        $args = WPStubs::callArgs('wp_send_json_success', 0);
        $this->assertArrayHasKey('items', $args[0]);
        $items = $args[0]['items'];
        $this->assertCount(2, $items);
        $this->assertSame(1, $items[0]['id']);
        $this->assertSame('Quiz One', $items[0]['title']);
        $this->assertSame(2, $items[1]['id']);
        $this->assertSame('H5P #2', $items[1]['title']);
    }

    /**
     * @covers PB_Split_Guide_Plugin::ajax_list_h5p
     */
    public function test_returns_empty_items_when_table_exists_but_no_rows(): void
    {
        $table = $this->wpdb->prefix . 'h5p_contents';
        $this->wpdb->returns['get_var'] = $table;
        $this->wpdb->returns['get_results'] = [];

        $plugin = new PB_Split_Guide_Plugin();

        try {
            $plugin->ajax_list_h5p();
        } catch (WPDieException $e) {
            // Expected
        }

        $args = WPStubs::callArgs('wp_send_json_success', 0);
        $this->assertSame([], $args[0]['items']);
    }

    /**
     * @covers PB_Split_Guide_Plugin::ajax_list_h5p
     */
    public function test_checks_referer_before_db_access(): void
    {
        $this->wpdb->returns['get_var'] = null;

        $plugin = new PB_Split_Guide_Plugin();

        try {
            $plugin->ajax_list_h5p();
        } catch (WPDieException $e) {
            // Expected
        }

        $this->assertTrue(WPStubs::wasCalled('check_ajax_referer'));
        $args = WPStubs::callArgs('check_ajax_referer', 0);
        $this->assertSame('pbsg_h5p_picker', $args[0]);
        $this->assertSame('nonce', $args[1]);
    }
}
