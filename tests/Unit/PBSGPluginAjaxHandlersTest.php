<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Helpers/MockWpdb.php';

/**
 * Unit tests for PB_Split_Guide_Plugin AJAX handlers (guards + select success paths).
 */
final class PBSGPluginAjaxHandlersTest extends TestCase
{
    private MockWpdb $wpdb;
    private PB_Split_Guide_Plugin $plugin;
    private array $postBackup;

    protected function setUp(): void
    {
        WPStubs::reset();
        $this->wpdb = new MockWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->plugin = new PB_Split_Guide_Plugin();
        $this->postBackup = $_POST;
        $_POST = [];
    }

    protected function tearDown(): void
    {
        WPStubs::reset();
        unset($GLOBALS['wpdb']);
        $_POST = $this->postBackup;
    }

    /**
     * @covers PB_Split_Guide_Plugin::ajax_get_templates
     */
    public function test_ajax_get_templates_success(): void
    {
        WPStubs::$returns['current_user_can'] = true;
        $this->wpdb->returns['get_results'] = [];

        try {
            $this->plugin->ajax_get_templates();
        } catch (WPDieException $e) {
            // expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_success'));
        $payload = WPStubs::callArgs('wp_send_json_success', 0)[0];
        $this->assertArrayHasKey('templates', $payload);
        $this->assertSame([], $payload['templates']);
    }

    /**
     * @covers PB_Split_Guide_Plugin::ajax_save_as_template
     */
    public function test_ajax_save_as_template_rejects_missing_fields(): void
    {
        WPStubs::$returns['current_user_can'] = true;
        $_POST['post_id'] = '0';
        $_POST['name'] = '';

        try {
            $this->plugin->ajax_save_as_template();
        } catch (WPDieException $e) {
            // expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
    }

    /**
     * @covers PB_Split_Guide_Plugin::ajax_create_from_template
     */
    public function test_ajax_create_from_template_returns_error_when_template_missing(): void
    {
        WPStubs::$returns['current_user_can'] = true;
        WPStubs::$returns['get_current_user_id'] = 1;
        WPStubs::$returns['get_userdata'] = null;
        WPStubs::$returns['get_option_date_format'] = 'Y-m-d';
        WPStubs::$returns['get_option_pbsg_library_catalog_url'] = '';
        $_POST['template_id'] = '404';
        $_POST['title'] = 'Nope';
        $this->wpdb->returns['get_row'] = null;

        try {
            $this->plugin->ajax_create_from_template();
        } catch (WPDieException $e) {
            // expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
        $msg = WPStubs::callArgs('wp_send_json_error', 0)[0];
        $this->assertStringContainsString('not found', strtolower($msg['message'] ?? ''));
    }

    /**
     * @covers PB_Split_Guide_Plugin::ajax_upload_file
     */
    public function test_ajax_upload_file_rejects_without_capability(): void
    {
        WPStubs::$returns['current_user_can'] = false;

        try {
            $this->plugin->ajax_upload_file();
        } catch (WPDieException $e) {
            // expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
    }

    /**
     * @covers PB_Split_Guide_Plugin::ajax_upload_file
     */
    public function test_ajax_upload_file_rejects_when_no_file(): void
    {
        WPStubs::$returns['current_user_can'] = true;

        try {
            $this->plugin->ajax_upload_file();
        } catch (WPDieException $e) {
            // expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
    }

    /**
     * @covers PB_Split_Guide_Plugin::ajax_upload_file
     */
    public function test_ajax_upload_file_success_path(): void
    {
        WPStubs::$returns['current_user_can'] = true;
        WPStubs::$returns['media_handle_upload'] = 77;
        WPStubs::$returns['get_attached_file_77'] = '/uploads/doc.pdf';
        $_FILES['pbsg_file'] = ['tmp_name' => '/tmp/x'];

        try {
            $this->plugin->ajax_upload_file();
        } catch (WPDieException $e) {
            // expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_success'));
        $data = WPStubs::callArgs('wp_send_json_success', 0)[0];
        $this->assertSame(77, $data['id']);
        $this->assertSame('doc.pdf', $data['filename']);
    }

    /**
     * @covers PB_Split_Guide_Plugin::ajax_create_h5p
     */
    public function test_ajax_create_h5p_rejects_without_capability(): void
    {
        WPStubs::$returns['current_user_can'] = false;
        $_POST['quiz'] = wp_json_encode(['type' => 'multichoice']);

        try {
            $this->plugin->ajax_create_h5p();
        } catch (WPDieException $e) {
            // expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
    }

    /**
     * @covers PB_Split_Guide_Plugin::ajax_create_h5p
     */
    public function test_ajax_create_h5p_rejects_invalid_quiz_json(): void
    {
        WPStubs::$returns['current_user_can'] = true;
        $_POST['quiz'] = '{}';

        try {
            $this->plugin->ajax_create_h5p();
        } catch (WPDieException $e) {
            // expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
    }

    /**
     * @covers PB_Split_Guide_Plugin::ajax_get_h5p_content
     */
    public function test_ajax_get_h5p_content_rejects_invalid_id(): void
    {
        WPStubs::$returns['current_user_can'] = true;
        $_POST['h5p_id'] = '0';

        try {
            $this->plugin->ajax_get_h5p_content();
        } catch (WPDieException $e) {
            // expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
    }

    /**
     * @covers PB_Split_Guide_Plugin::ajax_get_h5p_content
     */
    public function test_ajax_get_h5p_content_rejects_when_row_missing(): void
    {
        WPStubs::$returns['current_user_can'] = true;
        $_POST['h5p_id'] = '5';
        $this->wpdb->returns['get_row'] = null;

        try {
            $this->plugin->ajax_get_h5p_content();
        } catch (WPDieException $e) {
            // expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
    }

    /**
     * @covers PB_Split_Guide_Plugin::ajax_get_h5p_content
     */
    public function test_ajax_get_h5p_content_success(): void
    {
        WPStubs::$returns['current_user_can'] = true;
        $_POST['h5p_id'] = '9';

        $ref = new ReflectionClass(PBSG_H5P_Factory::class);
        $build = $ref->getMethod('build_params');
        $params = $build->invoke(null, 'multichoice', [
            'type'     => 'multichoice',
            'question' => 'Q',
            'answers'  => [['text' => 'A', 'correct' => true]],
        ]);

        $this->wpdb->returns['get_row'] = [
            'parameters'   => wp_json_encode($params),
            'library_id'   => '1',
            'library_name' => 'H5P.MultiChoice',
        ];

        try {
            $this->plugin->ajax_get_h5p_content();
        } catch (WPDieException $e) {
            // expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_success'));
        $payload = WPStubs::callArgs('wp_send_json_success', 0)[0];
        $this->assertSame('H5P.MultiChoice', $payload['library']);
        $this->assertSame('multichoice', $payload['quiz']['type']);
    }

    /**
     * @covers PB_Split_Guide_Plugin::ajax_list_tutorials
     */
    public function test_ajax_list_tutorials_rejects_without_edit_pages(): void
    {
        WPStubs::$returns['current_user_can'] = false;

        try {
            $this->plugin->ajax_list_tutorials();
        } catch (WPDieException $e) {
            // expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
        $this->assertSame(403, WPStubs::callArgs('wp_send_json_error', 0)[1]);
    }

    /**
     * @covers PB_Split_Guide_Plugin::ajax_list_tutorials
     */
    public function test_ajax_list_tutorials_returns_split_guide_pages(): void
    {
        WPStubs::$returns['current_user_can'] = true;
        WPStubs::$returns['get_the_title'] = 'My Tutorial';
        WPStubs::$returns['get_posts'] = [
            (object) ['ID' => 12, 'post_status' => 'publish'],
        ];

        try {
            $this->plugin->ajax_list_tutorials();
        } catch (WPDieException $e) {
            // expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_success'));
        $items = WPStubs::callArgs('wp_send_json_success', 0)[0];
        $this->assertCount(1, $items);
        $this->assertSame(12, $items[0]['id']);
        $this->assertSame('My Tutorial', $items[0]['title']);
        $this->assertSame('publish', $items[0]['status']);
    }
}
