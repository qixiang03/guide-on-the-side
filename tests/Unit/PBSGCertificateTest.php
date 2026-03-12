<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PBSG_Certificate.
 *
 * Covers ajax_mark_completed (auth, nonce, tutorial validation, user meta),
 * ajax_download_certificate (auth, nonce, completion check, output_pdf path),
 * and init hook registration.
 */
class PBSGCertificateTest extends TestCase
{
    /** @var array Backup of $_POST */
    private array $postBackup;

    /** @var array Backup of $_GET */
    private array $getBackup;

    protected function setUp(): void
    {
        WPStubs::reset();
        $this->postBackup = $_POST;
        $this->getBackup  = $_GET;
        $_POST = [];
        $_GET  = [];
    }

    protected function tearDown(): void
    {
        WPStubs::reset();
        $_POST = $this->postBackup;
        $_GET  = $this->getBackup;
    }

    /* ---------------------------------------------------------------
       ajax_mark_completed — auth & nonce
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Certificate::ajax_mark_completed
     */
    public function test_mark_completed_rejects_when_not_logged_in(): void
    {
        WPStubs::$returns['is_user_logged_in'] = false;

        try {
            PBSG_Certificate::ajax_mark_completed();
        } catch (WPDieException $e) {
            // Expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
        $args = WPStubs::callArgs('wp_send_json_error', 0);
        $this->assertSame(['message' => 'Not logged in'], $args[0]);
        $this->assertSame(401, $args[1]);
    }

    /**
     * @covers PBSG_Certificate::ajax_mark_completed
     */
    public function test_mark_completed_rejects_invalid_nonce(): void
    {
        WPStubs::$returns['is_user_logged_in'] = true;
        WPStubs::$returns['wp_verify_nonce']    = false;
        $_POST['nonce']                        = 'bad';
        $_POST['tutorial_id']                  = '42';

        try {
            PBSG_Certificate::ajax_mark_completed();
        } catch (WPDieException $e) {
            // Expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
        $args = WPStubs::callArgs('wp_send_json_error', 0);
        $this->assertSame(['message' => 'Bad nonce'], $args[0]);
        $this->assertSame(403, $args[1]);
    }

    /**
     * @covers PBSG_Certificate::ajax_mark_completed
     */
    public function test_mark_completed_rejects_invalid_tutorial_id(): void
    {
        WPStubs::$returns['is_user_logged_in'] = true;
        WPStubs::$returns['wp_verify_nonce']   = 1;
        $_POST['nonce']                        = 'valid';
        $_POST['tutorial_id']                  = '0';

        try {
            PBSG_Certificate::ajax_mark_completed();
        } catch (WPDieException $e) {
            // Expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
        $args = WPStubs::callArgs('wp_send_json_error', 0);
        $this->assertSame(['message' => 'Invalid tutorial'], $args[0]);
        $this->assertSame(400, $args[1]);
    }

    /**
     * @covers PBSG_Certificate::ajax_mark_completed
     */
    public function test_mark_completed_rejects_when_page_not_split_guide_template(): void
    {
        WPStubs::$returns['is_user_logged_in'] = true;
        WPStubs::$returns['wp_verify_nonce']   = 1;
        WPStubs::$returns['get_post_meta']     = ['_wp_page_template' => 'default'];
        $_POST['nonce']                        = 'valid';
        $_POST['tutorial_id']                  = '42';

        try {
            PBSG_Certificate::ajax_mark_completed();
        } catch (WPDieException $e) {
            // Expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
        $args = WPStubs::callArgs('wp_send_json_error', 0);
        $this->assertSame(['message' => 'Invalid tutorial'], $args[0]);
    }

    /**
     * @covers PBSG_Certificate::ajax_mark_completed
     */
    public function test_mark_completed_stores_meta_and_returns_success(): void
    {
        WPStubs::$returns['is_user_logged_in'] = true;
        WPStubs::$returns['wp_verify_nonce']   = 1;
        WPStubs::$returns['get_current_user_id'] = 7;
        WPStubs::$returns['get_post_meta']     = ['_wp_page_template' => 'split-guide-template.php'];
        WPStubs::$returns['current_time']     = '1234567890';
        $_POST['nonce']                        = 'valid';
        $_POST['tutorial_id']                  = '42';

        try {
            PBSG_Certificate::ajax_mark_completed();
        } catch (WPDieException $e) {
            // Expected — wp_send_json_success throws
        }

        $this->assertTrue(WPStubs::wasCalled('update_user_meta'));
        $updateArgs = WPStubs::callArgs('update_user_meta', 0);
        $this->assertSame(7, $updateArgs[0]);
        $this->assertSame('pbsg_completed_42', $updateArgs[1]);
        $this->assertSame('1234567890', $updateArgs[2]);

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_success'));
        $successArgs = WPStubs::callArgs('wp_send_json_success', 0);
        $this->assertSame(42, $successArgs[0]['tutorial_id']);
        $this->assertSame(1234567890, $successArgs[0]['completed_ts']);
    }

    /**
     * @covers PBSG_Certificate::ajax_mark_completed
     */
    public function test_mark_completed_returns_existing_timestamp_if_already_completed(): void
    {
        WPStubs::$returns['is_user_logged_in']   = true;
        WPStubs::$returns['wp_verify_nonce']     = 1;
        WPStubs::$returns['get_current_user_id'] = 7;
        WPStubs::$returns['get_post_meta']       = ['_wp_page_template' => 'split-guide-template.php'];
        WPStubs::$returns['user_meta_storage']   = [7 => ['pbsg_completed_42' => 999]];
        $_POST['nonce']                          = 'valid';
        $_POST['tutorial_id']                    = '42';

        try {
            PBSG_Certificate::ajax_mark_completed();
        } catch (WPDieException $e) {
            // Expected
        }

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_success'));
        $successArgs = WPStubs::callArgs('wp_send_json_success', 0);
        $this->assertSame(999, $successArgs[0]['completed_ts']);
    }

    /* ---------------------------------------------------------------
       ajax_download_certificate — auth, nonce, completion
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Certificate::ajax_download_certificate
     */
    public function test_download_certificate_rejects_when_not_logged_in(): void
    {
        WPStubs::$returns['is_user_logged_in'] = false;

        try {
            PBSG_Certificate::ajax_download_certificate();
        } catch (WPDieException $e) {
            $this->assertStringContainsString('Not logged in', $e->getMessage());
        }

        $this->assertTrue(WPStubs::wasCalled('wp_die'));
        $args = WPStubs::callArgs('wp_die', 0);
        $this->assertSame('Not logged in', $args[0]);
        $this->assertSame(401, $args[2]['response'] ?? null);
    }

    /**
     * @covers PBSG_Certificate::ajax_download_certificate
     */
    public function test_download_certificate_rejects_bad_nonce(): void
    {
        WPStubs::$returns['is_user_logged_in'] = true;
        WPStubs::$returns['wp_verify_nonce']   = false;
        $_GET['nonce']                         = 'bad';
        $_GET['tutorial_id']                   = '42';

        try {
            PBSG_Certificate::ajax_download_certificate();
        } catch (WPDieException $e) {
            $this->assertStringContainsString('Bad nonce', $e->getMessage());
        }

        $args = WPStubs::callArgs('wp_die', 0);
        $this->assertSame(403, $args[2]['response'] ?? null);
    }

    /**
     * @covers PBSG_Certificate::ajax_download_certificate
     */
    public function test_download_certificate_rejects_invalid_tutorial(): void
    {
        WPStubs::$returns['is_user_logged_in'] = true;
        WPStubs::$returns['wp_verify_nonce']   = 1;
        WPStubs::$returns['get_post_meta']     = ['_wp_page_template' => 'default'];
        $_GET['nonce']                         = 'valid';
        $_GET['tutorial_id']                   = '42';

        try {
            PBSG_Certificate::ajax_download_certificate();
        } catch (WPDieException $e) {
            $this->assertStringContainsString('Invalid tutorial', $e->getMessage());
        }

        $args = WPStubs::callArgs('wp_die', 0);
        $this->assertSame(400, $args[2]['response'] ?? null);
    }

    /**
     * @covers PBSG_Certificate::ajax_download_certificate
     */
    public function test_download_certificate_rejects_when_tutorial_not_completed(): void
    {
        WPStubs::$returns['is_user_logged_in']   = true;
        WPStubs::$returns['wp_verify_nonce']     = 1;
        WPStubs::$returns['get_current_user_id'] = 7;
        WPStubs::$returns['get_post_meta']       = ['_wp_page_template' => 'split-guide-template.php'];
        WPStubs::$returns['user_meta_storage']   = [7 => []]; // no completion
        $_GET['nonce']                           = 'valid';
        $_GET['tutorial_id']                     = '42';

        try {
            PBSG_Certificate::ajax_download_certificate();
        } catch (WPDieException $e) {
            $this->assertStringContainsString('Tutorial not completed yet', $e->getMessage());
        }

        $args = WPStubs::callArgs('wp_die', 0);
        $this->assertSame(403, $args[2]['response'] ?? null);
    }

    /* ---------------------------------------------------------------
       init — hook registration
       --------------------------------------------------------------- */

    /**
     * @covers PBSG_Certificate::init
     */
    public function test_init_registers_mark_completed_ajax_action(): void
    {
        PBSG_Certificate::init();

        $tags = array_column(WPStubs::$hooks['action'], 'tag');
        $this->assertContains('wp_ajax_pbsg_mark_completed', $tags);
        $this->assertContains('wp_ajax_pbsg_download_certificate', $tags);
    }
}
