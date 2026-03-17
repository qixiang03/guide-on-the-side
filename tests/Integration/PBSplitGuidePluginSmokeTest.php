<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration smoke tests for PB_Split_Guide_Plugin.
 *
 * These tests verify the plugin's wiring with WordPress APIs (hooks,
 * template loading, meta-save guards, asset enqueue conditions, AJAX
 * handler) using lightweight stubs — no real WordPress runtime, no H5P.
 */
final class PBSplitGuidePluginSmokeTest extends TestCase
{
    private PB_Split_Guide_Plugin $plugin;

    protected function setUp(): void
    {
        if ($this->getName() === 'test_activation_hook_registered_with_create_tables') {
            $this->plugin = new PB_Split_Guide_Plugin();
            return;
        }
        WPStubs::reset();

        // Instantiate a fresh plugin (registers hooks via constructor)
        $this->plugin = new PB_Split_Guide_Plugin();
    }

    protected function tearDown(): void
    {
        WPStubs::reset();
        unset($_POST['pbsg_nonce'], $_POST['pbsg_steps_json'], $_POST['pbsg_header_note']);

        if (defined('DOING_AUTOSAVE')) {
            // Cannot undefine a constant; tests that define it must run last or
            // in isolation. We handle this with @runInSeparateProcess where needed.
        }
    }

    /* =============================================================
     *  Hook Registration
     * ============================================================= */

    public function test_constructor_registers_theme_page_templates_filter(): void
    {
        $tags = array_column(WPStubs::$hooks['filter'], 'tag');
        $this->assertContains('theme_page_templates', $tags);
    }

    public function test_constructor_registers_template_include_filter(): void
    {
        $tags = array_column(WPStubs::$hooks['filter'], 'tag');
        $this->assertContains('template_include', $tags);
    }

    public function test_constructor_registers_meta_boxes_action(): void
    {
        $tags = array_column(WPStubs::$hooks['action'], 'tag');
        $this->assertContains('add_meta_boxes_page', $tags);
    }

    public function test_constructor_registers_save_post_action(): void
    {
        $tags = array_column(WPStubs::$hooks['action'], 'tag');
        $this->assertContains('save_post_page', $tags);
    }

    public function test_constructor_registers_frontend_enqueue_action(): void
    {
        $tags = array_column(WPStubs::$hooks['action'], 'tag');
        $this->assertContains('wp_enqueue_scripts', $tags);
    }

    public function test_constructor_registers_admin_enqueue_action(): void
    {
        $tags = array_column(WPStubs::$hooks['action'], 'tag');
        $this->assertContains('admin_enqueue_scripts', $tags);
    }

    public function test_constructor_registers_ajax_handler(): void
    {
        $tags = array_column(WPStubs::$hooks['action'], 'tag');
        $this->assertContains('wp_ajax_pbsg_list_h5p', $tags);
    }

    public function test_total_hook_count_matches_expected(): void
    {
        $this->assertCount(4, WPStubs::$hooks['filter'], 'Expected 4 filters');
        $this->assertCount(17, WPStubs::$hooks['action'], 'Expected 17 actions');
    }

    /* =============================================================
     *  Template Registration
     * ============================================================= */

    public function test_register_page_template_adds_split_guide_entry(): void
    {
        $result = $this->plugin->register_page_template([]);

        $this->assertArrayHasKey('split-guide-template.php', $result);
        $this->assertSame('Split Guide (H5P + Tutorial)', $result['split-guide-template.php']);
    }

    public function test_register_page_template_preserves_existing_templates(): void
    {
        $existing = ['other-template.php' => 'Other Template'];
        $result   = $this->plugin->register_page_template($existing);

        $this->assertArrayHasKey('other-template.php', $result);
        $this->assertArrayHasKey('split-guide-template.php', $result);
    }

    /* =============================================================
     *  Template Loading
     * ============================================================= */

    public function test_load_page_template_returns_plugin_template_when_matched(): void
    {
        WPStubs::$returns['is_page'] = true;
        WPStubs::$returns['get_queried_object_id'] = 42;
        WPStubs::$returns['get_post_meta'] = [
            '_wp_page_template' => 'split-guide-template.php',
        ];

        $result = $this->plugin->load_page_template('/theme/default.php');

        $this->assertStringEndsWith(
            'templates/split-guide-template.php',
            $result
        );
    }

    public function test_load_page_template_passes_through_when_not_a_page(): void
    {
        WPStubs::$returns['is_page'] = false;

        $original = '/theme/default.php';
        $result   = $this->plugin->load_page_template($original);

        $this->assertSame($original, $result);
    }

    public function test_load_page_template_passes_through_when_template_differs(): void
    {
        WPStubs::$returns['is_page'] = true;
        WPStubs::$returns['get_queried_object_id'] = 42;
        WPStubs::$returns['get_post_meta'] = [
            '_wp_page_template' => 'some-other-template.php',
        ];

        $original = '/theme/default.php';
        $result   = $this->plugin->load_page_template($original);

        $this->assertSame($original, $result);
    }

    /* =============================================================
     *  Meta Save Guards
     * ============================================================= */

    public function test_save_meta_bails_when_nonce_missing(): void
    {
        unset($_POST['pbsg_nonce']);

        $this->plugin->save_meta(1, (object) ['ID' => 1]);

        $this->assertFalse(WPStubs::wasCalled('update_post_meta'));
    }

    public function test_save_meta_bails_when_nonce_invalid(): void
    {
        $_POST['pbsg_nonce'] = 'bad_nonce';
        WPStubs::$returns['wp_verify_nonce'] = false;

        $this->plugin->save_meta(1, (object) ['ID' => 1]);

        $this->assertFalse(WPStubs::wasCalled('update_post_meta'));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_save_meta_bails_during_autosave(): void
    {
        define('DOING_AUTOSAVE', true);

        WPStubs::reset();
        $_POST['pbsg_nonce'] = 'valid';
        WPStubs::$returns['wp_verify_nonce'] = 1;

        $plugin = new PB_Split_Guide_Plugin();
        $plugin->save_meta(1, (object) ['ID' => 1]);

        $this->assertFalse(WPStubs::wasCalled('update_post_meta'));
    }

    public function test_save_meta_bails_when_user_lacks_capability(): void
    {
        $_POST['pbsg_nonce'] = 'valid';
        WPStubs::$returns['wp_verify_nonce'] = 1;
        WPStubs::$returns['current_user_can'] = false;

        $this->plugin->save_meta(1, (object) ['ID' => 1]);

        $this->assertFalse(WPStubs::wasCalled('update_post_meta'));
    }

    public function test_save_meta_normalizes_steps_and_updates_post_meta(): void
    {
        $_POST['pbsg_nonce'] = 'valid';
        $_POST['pbsg_steps_json'] = json_encode([
            ['url' => 'https://upei.ca/tut', 'h5p_id' => '5', 'title' => 'Step One'],
        ]);
        $_POST['pbsg_header_note'] = 'Welcome note';

        WPStubs::$returns['wp_verify_nonce'] = 1;
        WPStubs::$returns['current_user_can'] = true;

        $this->plugin->save_meta(99, (object) ['ID' => 99]);

        $this->assertTrue(WPStubs::wasCalled('update_post_meta'));
        $this->assertSame(3, WPStubs::callCount('update_post_meta'));

        $stepsCall = WPStubs::callArgs('update_post_meta', 0);
        $this->assertSame(99, $stepsCall[0]);
        $this->assertSame('_pbsg_steps_json', $stepsCall[1]);

        $saved = json_decode($stepsCall[2], true);
        $this->assertCount(1, $saved);
        $this->assertSame('url', $saved[0]['tutorial_type']);
        $this->assertSame('https://upei.ca/tut', $saved[0]['tutorial_url']);
        $this->assertSame(5, $saved[0]['h5p_id']);

        $noteCall = WPStubs::callArgs('update_post_meta', 1);
        $this->assertSame('_pbsg_header_note', $noteCall[1]);
        $this->assertSame('Welcome note', $noteCall[2]);

        $templateCall = WPStubs::callArgs('update_post_meta', 2);
        $this->assertSame(99, $templateCall[0]);
        $this->assertSame('_wp_page_template', $templateCall[1]);
        $this->assertSame('split-guide-template.php', $templateCall[2]);
    }

    /* =============================================================
     *  Asset Enqueue — Frontend
     * ============================================================= */

    public function test_enqueue_assets_skips_when_not_a_page(): void
    {
        WPStubs::$returns['is_page'] = false;

        $this->plugin->enqueue_assets();

        $this->assertFalse(WPStubs::wasCalled('wp_enqueue_style'));
    }

    public function test_enqueue_assets_skips_when_template_does_not_match(): void
    {
        WPStubs::$returns['is_page'] = true;
        WPStubs::$returns['get_queried_object_id'] = 10;
        WPStubs::$returns['get_post_meta'] = [
            '_wp_page_template' => 'default',
        ];

        $this->plugin->enqueue_assets();

        $this->assertFalse(WPStubs::wasCalled('wp_enqueue_style'));
    }

    public function test_enqueue_assets_enqueues_css_when_template_matches(): void
    {
        WPStubs::$returns['is_page'] = true;
        WPStubs::$returns['get_queried_object_id'] = 10;
        WPStubs::$returns['get_post_meta'] = [
            '_wp_page_template' => 'split-guide-template.php',
        ];

        $this->plugin->enqueue_assets();

        $this->assertTrue(WPStubs::wasCalled('wp_enqueue_style'));

        $args = WPStubs::callArgs('wp_enqueue_style', 0);
        $this->assertSame('pbsg_split_guide_css', $args[0]);
        $this->assertStringContainsString('split-guide.css', $args[1]);
    }

    /* =============================================================
     *  Asset Enqueue — Admin
     * ============================================================= */

    public function test_enqueue_admin_assets_skips_non_editor_pages(): void
    {
        $this->plugin->enqueue_admin_assets('edit.php');

        $this->assertFalse(WPStubs::wasCalled('wp_enqueue_script'));
    }

    public function test_enqueue_admin_assets_skips_non_page_post_type(): void
    {
        $screen = (object) ['post_type' => 'post'];
        WPStubs::$returns['get_current_screen'] = $screen;

        $this->plugin->enqueue_admin_assets('post.php');

        $this->assertFalse(WPStubs::wasCalled('wp_enqueue_script'));
    }

    public function test_enqueue_admin_assets_loads_for_page_editor(): void
    {
        $screen = (object) ['post_type' => 'page'];
        WPStubs::$returns['get_current_screen'] = $screen;

        $this->plugin->enqueue_admin_assets('post.php');

        $this->assertTrue(WPStubs::wasCalled('wp_enqueue_script'));
        $this->assertTrue(WPStubs::wasCalled('wp_enqueue_style'));
        $this->assertTrue(WPStubs::wasCalled('wp_enqueue_media'));
        $this->assertTrue(WPStubs::wasCalled('add_thickbox'));
        $this->assertTrue(WPStubs::wasCalled('wp_localize_script'));

        $localize = WPStubs::callArgs('wp_localize_script', 0);
        $this->assertSame('PBSG_ADMIN', $localize[1]);
        $this->assertArrayHasKey('templateSlug', $localize[2]);
        $this->assertArrayHasKey('ajaxUrl', $localize[2]);
        $this->assertArrayHasKey('nonce', $localize[2]);
    }

    /* =============================================================
     *  AJAX Handler
     * ============================================================= */

    public function test_ajax_list_h5p_checks_referer(): void
    {
        // The handler uses $wpdb which isn't stubbed, so we only verify
        // the nonce check fires before the DB access would occur.
        // We catch the PHP warning/error from missing $wpdb gracefully.
        try {
            @$this->plugin->ajax_list_h5p();
        } catch (\Throwable $e) {
            // Expected: $wpdb is null — that's fine, we only care about the referer check.
        }

        $this->assertTrue(WPStubs::wasCalled('check_ajax_referer'));
        $args = WPStubs::callArgs('check_ajax_referer', 0);
        $this->assertSame('pbsg_h5p_picker', $args[0]);
        $this->assertSame('nonce', $args[1]);
    }

    /* =============================================================
     *  Certificate init & activation hook
     * ============================================================= */

    public function test_certificate_init_registers_ajax_actions(): void
    {
        WPStubs::reset();
        PBSG_Certificate::init();

        $tags = array_column(WPStubs::$hooks['action'], 'tag');
        $this->assertContains('wp_ajax_pbsg_mark_completed', $tags);
        $this->assertContains('wp_ajax_pbsg_download_certificate', $tags);
    }

    /**
     * Plugin file registers activation hook at load time; run in separate process
     * so we see the call before any other test's setUp has reset WPStubs.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_activation_hook_registered_with_create_tables(): void
    {
        $this->assertTrue(WPStubs::wasCalled('register_activation_hook'));
        $args = WPStubs::callArgs('register_activation_hook', 0);
        $this->assertIsArray($args);
        $this->assertCount(2, $args);
        $this->assertSame(['PBSG_Analytics', 'create_tables'], $args[1]);
    }
}
