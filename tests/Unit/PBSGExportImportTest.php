<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PBSG_Export_Import AJAX registration and handler guards.
 */
final class PBSGExportImportTest extends TestCase
{
    private array $postBackup;
    private array $filesBackup;

    protected function setUp(): void
    {
        WPStubs::reset();
        $this->postBackup = $_POST;
        $this->filesBackup = $_FILES ?? [];
        $_POST = [];
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        WPStubs::reset();
        $_POST = $this->postBackup;
        $_FILES = $this->filesBackup;
    }

    /**
     * @covers PBSG_Export_Import::init
     */
    public function test_init_registers_export_and_import_ajax_actions(): void
    {
        PBSG_Export_Import::init();

        $tags = array_column(WPStubs::$hooks['action'], 'tag');
        $this->assertContains('wp_ajax_pbsg_export_tutorial', $tags);
        $this->assertContains('wp_ajax_pbsg_import_tutorial', $tags);
    }

    /**
     * @covers PBSG_Export_Import::handle_export
     */
    public function test_handle_export_wp_dies_when_user_cannot_edit_post(): void
    {
        $_POST['nonce'] = 'n';
        $_POST['post_id'] = '5';

        WPStubs::$returns['current_user_can_resolver'] = static function (string $cap, ...$args): bool {
            return !($cap === 'edit_post' && isset($args[0]) && (int) $args[0] === 5);
        };

        $this->expectException(WPDieException::class);

        PBSG_Export_Import::handle_export();
    }

    /**
     * @covers PBSG_Export_Import::handle_export
     */
    public function test_handle_export_wp_dies_when_post_missing(): void
    {
        $_POST['nonce'] = 'n';
        $_POST['post_id'] = '9';

        WPStubs::$returns['current_user_can_resolver'] = static fn (): bool => true;
        WPStubs::$returns['get_post'] = null;

        $this->expectException(WPDieException::class);

        PBSG_Export_Import::handle_export();
    }

    /**
     * @covers PBSG_Export_Import::handle_import
     */
    public function test_handle_import_rejects_without_edit_pages(): void
    {
        WPStubs::$returns['current_user_can'] = false;

        try {
            PBSG_Export_Import::handle_import();
        } catch (WPDieException $e) {
            // expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
        $args = WPStubs::callArgs('wp_send_json_error', 0);
        $this->assertSame(403, $args[1]);
    }

    /**
     * @covers PBSG_Export_Import::handle_import
     */
    public function test_handle_import_rejects_bad_upload(): void
    {
        WPStubs::$returns['current_user_can'] = true;
        $_FILES['pbsg_import_file'] = ['error' => UPLOAD_ERR_NO_FILE];

        try {
            PBSG_Export_Import::handle_import();
        } catch (WPDieException $e) {
            // expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
    }

    /**
     * @covers PBSG_Export_Import::handle_import
     */
    public function test_handle_import_rejects_invalid_json_package(): void
    {
        WPStubs::$returns['current_user_can'] = true;

        $tmp = tempnam(sys_get_temp_dir(), 'pbsgexp');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, '{"not":"export"}');
        $_FILES['pbsg_import_file'] = ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp];

        try {
            PBSG_Export_Import::handle_import();
        } catch (WPDieException $e) {
            // expected
        } finally {
            @unlink($tmp);
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
        $payload = WPStubs::callArgs('wp_send_json_error', 0)[0];
        $this->assertArrayHasKey('message', $payload);
        $this->assertStringContainsString('Invalid export file', $payload['message']);
    }

    /**
     * @covers PBSG_Export_Import::handle_import
     */
    public function test_handle_import_creates_draft_from_minimal_package(): void
    {
        WPStubs::$returns['current_user_can'] = true;
        WPStubs::$returns['get_current_user_id'] = 42;
        WPStubs::$returns['wp_insert_post'] = 9001;

        $package = [
            'pbsg_version' => PBSG_Export_Import::EXPORT_VERSION,
            'title'        => 'Imported',
            'header_note'  => '',
            'post_content' => '<p>Hi</p>',
            'steps'        => [],
            'attachments'  => [],
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'pbsgexp');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, wp_json_encode($package));
        $_FILES['pbsg_import_file'] = ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp];

        try {
            PBSG_Export_Import::handle_import();
        } catch (WPDieException $e) {
            // expected — success path throws from wp_send_json_success
        } finally {
            @unlink($tmp);
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_success'));
        $data = WPStubs::callArgs('wp_send_json_success', 0)[0];
        $this->assertSame(9001, $data['post_id']);
        $this->assertSame('Imported', $data['title']);

        $wpArgs = WPStubs::callArgs('wp_insert_post', 0);
        $this->assertTrue($wpArgs[1]);
        $postarr = $wpArgs[0];
        $this->assertSame('Imported', $postarr['post_title']);
        $this->assertSame('<p>Hi</p>', $postarr['post_content']);
        $this->assertSame(PB_Split_Guide_Plugin::TEMPLATE_SLUG, $postarr['meta_input']['_wp_page_template']);
    }
}
