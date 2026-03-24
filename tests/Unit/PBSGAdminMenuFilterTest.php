<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PBSG_Admin_Menu_Filter class.
 *
 * Tests menu filtering logic, allowed slugs, and redirect behavior
 * via source code verification.
 */
final class PBSGAdminMenuFilterTest extends TestCase
{
    /** @var string Source code of the admin menu filter class */
    private string $sourceCode;

    protected function setUp(): void
    {
        $this->sourceCode = file_get_contents(
            dirname(__DIR__, 2) . '/web/app/plugins/pb-split-guide/includes/class-pbsg-admin-menu-filter.php'
        );
    }

    /* =============================================================
       Allowed Menu Slugs
       ============================================================= */

    public function test_allowed_menu_slugs_includes_core_pages(): void
    {
        $expected = [
            'index.php',
            'pbsg-my-tutorials',
            'edit.php?post_type=page',
            'pbsg-analytics',
            'upload.php',
            'h5p',
            'profile.php',
        ];

        foreach ($expected as $slug) {
            $this->assertContains($slug, PBSG_Admin_Menu_Filter::ALLOWED_MENU_SLUGS, "Missing slug: {$slug}");
        }
    }

    public function test_allowed_menu_slugs_does_not_include_settings(): void
    {
        $restricted = [
            'options-general.php',
            'plugins.php',
            'themes.php',
            'users.php',
            'tools.php',
            'edit-comments.php',
        ];

        foreach ($restricted as $slug) {
            $this->assertNotContains($slug, PBSG_Admin_Menu_Filter::ALLOWED_MENU_SLUGS, "Should NOT include: {$slug}");
        }
    }

    public function test_allowed_menu_slugs_count_matches_expected(): void
    {
        $this->assertCount(7, PBSG_Admin_Menu_Filter::ALLOWED_MENU_SLUGS);
    }

    /* =============================================================
       Allowed Scripts (Direct URL access)
       ============================================================= */

    public function test_allowed_scripts_includes_tutorial_management_pages(): void
    {
        $expected = [
            'index.php', 'edit.php', 'post.php', 'post-new.php',
            'revision.php', 'upload.php', 'media-new.php', 'profile.php',
            'admin-ajax.php', 'admin-post.php', 'admin.php',
        ];

        foreach ($expected as $script) {
            $this->assertContains($script, PBSG_Admin_Menu_Filter::ALLOWED_SCRIPTS, "Missing script: {$script}");
        }
    }

    public function test_allowed_scripts_does_not_include_restricted_pages(): void
    {
        $restricted = [
            'options-general.php', 'plugins.php', 'themes.php',
            'users.php', 'tools.php', 'import.php', 'export.php',
        ];

        foreach ($restricted as $script) {
            $this->assertNotContains($script, PBSG_Admin_Menu_Filter::ALLOWED_SCRIPTS, "Should NOT include: {$script}");
        }
    }

    /* =============================================================
       Allowed Page Parameters
       ============================================================= */

    public function test_allowed_page_params_includes_plugin_pages(): void
    {
        $expected = [
            'pbsg-my-tutorials',
            'pbsg-analytics',
            'h5p',
            'h5p_new',
        ];

        foreach ($expected as $param) {
            $this->assertContains($param, PBSG_Admin_Menu_Filter::ALLOWED_PAGE_PARAMS, "Missing param: {$param}");
        }
    }

    public function test_allowed_page_params_excludes_manage_librarians(): void
    {
        // Librarians should not be able to access the librarian management page
        $this->assertNotContains('pbsg-manage-librarians', PBSG_Admin_Menu_Filter::ALLOWED_PAGE_PARAMS);
    }

    /* =============================================================
       Source Code Behavior Verification
       ============================================================= */

    public function test_filter_menus_exits_early_for_admins(): void
    {
        $this->assertStringContainsString('PBSG_Roles::is_admin()', $this->sourceCode);
    }

    public function test_filter_menus_only_applies_to_librarians(): void
    {
        $this->assertStringContainsString('PBSG_Roles::is_librarian()', $this->sourceCode);
    }

    public function test_filter_menus_removes_h5p_libraries_submenu(): void
    {
        $this->assertStringContainsString("remove_submenu_page( 'h5p', 'h5p_libraries' )", $this->sourceCode);
    }

    public function test_filter_menus_removes_h5p_settings_submenu(): void
    {
        $this->assertStringContainsString("remove_submenu_page( 'options-general.php', 'h5p_settings' )", $this->sourceCode);
    }

    public function test_redirect_unauthorized_skips_ajax_requests(): void
    {
        $this->assertStringContainsString('wp_doing_ajax()', $this->sourceCode);
    }

    public function test_redirect_unauthorized_restricts_non_page_post_types(): void
    {
        // The filter should block edit.php with non-page post types
        $this->assertStringContainsString("'post_type'", $this->sourceCode);
        $this->assertStringContainsString("!== 'page'", $this->sourceCode);
    }

    public function test_redirect_sends_librarian_to_my_tutorials(): void
    {
        $this->assertStringContainsString("admin_url( 'admin.php?page=pbsg-my-tutorials' )", $this->sourceCode);
    }

    public function test_login_redirect_hook_is_registered(): void
    {
        $this->assertStringContainsString("'login_redirect'", $this->sourceCode);
        $this->assertStringContainsString('librarian_login_redirect', $this->sourceCode);
    }

    public function test_login_redirect_targets_my_tutorials(): void
    {
        $this->assertStringContainsString('PBSG_Roles::LIBRARIAN_ROLE', $this->sourceCode);
        $this->assertStringContainsString("admin_url( 'admin.php?page=pbsg-my-tutorials' )", $this->sourceCode);
    }

    /* =============================================================
       Init Registration
       ============================================================= */

    public function test_init_registers_three_hooks(): void
    {
        // admin_menu, admin_init, and login_redirect
        $count = substr_count($this->sourceCode, 'add_action(') + substr_count($this->sourceCode, 'add_filter(');
        $this->assertSame(3, $count, 'init() should register exactly 3 hooks');
    }

    public function test_filter_menus_priority_is_9999(): void
    {
        $this->assertStringContainsString("'admin_menu', array( __CLASS__, 'filter_menus' ), 9999", $this->sourceCode);
    }

    public function test_redirect_priority_is_1(): void
    {
        $this->assertStringContainsString("'admin_init', array( __CLASS__, 'redirect_unauthorized' ), 1", $this->sourceCode);
    }
}
