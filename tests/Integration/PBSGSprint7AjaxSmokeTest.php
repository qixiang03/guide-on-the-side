<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration smoke tests for Sprint 7 AJAX handlers.
 */
final class PBSGSprint7AjaxSmokeTest extends TestCase
{
    private PB_Split_Guide_Plugin $plugin;

    protected function setUp(): void
    {
        WPStubs::reset();
        $_POST = [];
        $_FILES = [];
        $this->plugin = new PB_Split_Guide_Plugin();
    }

    protected function tearDown(): void
    {
        WPStubs::reset();
        $_POST = [];
        $_FILES = [];
    }

    public function test_template_ajax_hooks_are_registered_in_constructor(): void
    {
        $tags = array_column(WPStubs::$hooks['action'], 'tag');

        $this->assertContains('wp_ajax_pbsg_get_templates', $tags);
        $this->assertContains('wp_ajax_pbsg_save_as_template', $tags);
        $this->assertContains('wp_ajax_pbsg_create_from_template', $tags);
    }

    public function test_export_import_init_registers_ajax_hooks(): void
    {
        WPStubs::reset();
        PBSG_Export_Import::init();

        $tags = array_column(WPStubs::$hooks['action'], 'tag');
        $this->assertContains('wp_ajax_pbsg_export_tutorial', $tags);
        $this->assertContains('wp_ajax_pbsg_import_tutorial', $tags);
    }

    public function test_ajax_get_templates_checks_nonce_and_forbidden_without_edit_pages(): void
    {
        WPStubs::$returns['current_user_can'] = false;

        try {
            $this->plugin->ajax_get_templates();
            $this->fail('Expected WPDieException from wp_send_json_error');
        } catch (WPDieException $e) {
            $this->assertTrue(WPStubs::wasCalled('check_ajax_referer'));
            $nonceArgs = WPStubs::callArgs('check_ajax_referer', 0);
            $this->assertSame('pbsg_template_picker', $nonceArgs[0]);
            $this->assertSame('nonce', $nonceArgs[1]);

            $jsonErr = WPStubs::callArgs('wp_send_json_error', 0);
            $this->assertSame(403, $jsonErr[1]);
        }
    }

    public function test_ajax_save_as_template_returns_validation_error_when_post_id_or_name_missing(): void
    {
        WPStubs::$returns['current_user_can'] = true;
        $_POST['post_id'] = 0;
        $_POST['name'] = '';

        try {
            $this->plugin->ajax_save_as_template();
            $this->fail('Expected WPDieException from wp_send_json_error');
        } catch (WPDieException $e) {
            $this->assertTrue(WPStubs::wasCalled('check_ajax_referer'));
            $jsonErr = WPStubs::callArgs('wp_send_json_error', 0);
            $this->assertStringContainsString('Name and post_id are required', (string) ($jsonErr[0]['message'] ?? ''));
        }
    }

    public function test_ajax_create_from_template_forbidden_without_edit_pages(): void
    {
        WPStubs::$returns['current_user_can'] = false;

        try {
            $this->plugin->ajax_create_from_template();
            $this->fail('Expected WPDieException from wp_send_json_error');
        } catch (WPDieException $e) {
            $this->assertTrue(WPStubs::wasCalled('check_ajax_referer'));
            $jsonErr = WPStubs::callArgs('wp_send_json_error', 0);
            $this->assertSame(403, $jsonErr[1]);
        }
    }

    public function test_handle_import_forbidden_without_edit_pages(): void
    {
        WPStubs::$returns['current_user_can'] = false;

        try {
            PBSG_Export_Import::handle_import();
            $this->fail('Expected WPDieException from wp_send_json_error');
        } catch (WPDieException $e) {
            $this->assertTrue(WPStubs::wasCalled('check_ajax_referer'));
            $nonceArgs = WPStubs::callArgs('check_ajax_referer', 0);
            $this->assertSame('pbsg_export_import', $nonceArgs[0]);
            $this->assertSame('nonce', $nonceArgs[1]);

            $jsonErr = WPStubs::callArgs('wp_send_json_error', 0);
            $this->assertSame(403, $jsonErr[1]);
        }
    }

    public function test_handle_import_returns_upload_failed_when_file_missing(): void
    {
        WPStubs::$returns['current_user_can'] = true;
        $_FILES = [];

        try {
            PBSG_Export_Import::handle_import();
            $this->fail('Expected WPDieException from wp_send_json_error');
        } catch (WPDieException $e) {
            $jsonErr = WPStubs::callArgs('wp_send_json_error', 0);
            $this->assertStringContainsString('Upload failed', (string) ($jsonErr[0]['message'] ?? ''));
        }
    }

    public function test_handle_import_rejects_invalid_export_file_json(): void
    {
        WPStubs::$returns['current_user_can'] = true;

        $tmp = tempnam(sys_get_temp_dir(), 'pbsg-import-');
        file_put_contents($tmp, '{"not_pbsg":true}');

        $_FILES['pbsg_import_file'] = [
            'name' => 'invalid.json',
            'type' => 'application/json',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmp),
        ];

        try {
            PBSG_Export_Import::handle_import();
            $this->fail('Expected WPDieException from wp_send_json_error');
        } catch (WPDieException $e) {
            $jsonErr = WPStubs::callArgs('wp_send_json_error', 0);
            $this->assertStringContainsString('Invalid export file', (string) ($jsonErr[0]['message'] ?? ''));
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
