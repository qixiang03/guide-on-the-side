<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PBSG_Roles class.
 *
 * Tests role registration, capability definitions, and helper methods.
 */
final class PBSGRolesTest extends TestCase
{
    /** @var string Source code of the roles class */
    private string $sourceCode;

    protected function setUp(): void
    {
        $this->sourceCode = file_get_contents(
            dirname(__DIR__, 2) . '/web/app/plugins/pb-split-guide/includes/class-pbsg-roles.php'
        );
    }

    /* =============================================================
       Constants & Definitions
       ============================================================= */

    public function test_librarian_role_constant_is_defined(): void
    {
        $this->assertSame('pbsg_librarian', PBSG_Roles::LIBRARIAN_ROLE);
    }

    public function test_custom_caps_constant_contains_four_capabilities(): void
    {
        $this->assertCount(4, PBSG_Roles::CUSTOM_CAPS);
        $this->assertContains('pbsg_view_analytics', PBSG_Roles::CUSTOM_CAPS);
        $this->assertContains('pbsg_export_csv', PBSG_Roles::CUSTOM_CAPS);
        $this->assertContains('pbsg_manage_tutorials', PBSG_Roles::CUSTOM_CAPS);
        $this->assertContains('pbsg_manage_librarians', PBSG_Roles::CUSTOM_CAPS);
    }

    /* =============================================================
       Librarian Capabilities
       ============================================================= */

    public function test_librarian_caps_include_core_page_capabilities(): void
    {
        $caps = PBSG_Roles::get_librarian_caps();

        $expected_core = [
            'read', 'upload_files', 'edit_pages', 'edit_others_pages',
            'edit_published_pages', 'publish_pages', 'delete_pages',
            'delete_others_pages', 'delete_published_pages',
            'edit_posts', // Required by H5P capability mapping
        ];

        foreach ($expected_core as $cap) {
            $this->assertArrayHasKey($cap, $caps, "Missing core cap: {$cap}");
            $this->assertTrue($caps[$cap], "Core cap {$cap} should be true");
        }
    }

    public function test_librarian_caps_include_h5p_use_capabilities(): void
    {
        $caps = PBSG_Roles::get_librarian_caps();

        $expected_h5p = [
            'view_h5p_contents', 'edit_h5p_contents',
            'view_others_h5p_contents', 'view_h5p_results',
        ];

        foreach ($expected_h5p as $cap) {
            $this->assertArrayHasKey($cap, $caps, "Missing H5P cap: {$cap}");
            $this->assertTrue($caps[$cap], "H5P cap {$cap} should be true");
        }
    }

    public function test_librarian_caps_include_plugin_capabilities(): void
    {
        $caps = PBSG_Roles::get_librarian_caps();

        $this->assertArrayHasKey('pbsg_view_analytics', $caps);
        $this->assertTrue($caps['pbsg_view_analytics']);

        $this->assertArrayHasKey('pbsg_export_csv', $caps);
        $this->assertTrue($caps['pbsg_export_csv']);

        $this->assertArrayHasKey('pbsg_manage_tutorials', $caps);
        $this->assertTrue($caps['pbsg_manage_tutorials']);
    }

    public function test_librarian_caps_exclude_admin_only_capabilities(): void
    {
        $caps = PBSG_Roles::get_librarian_caps();

        $admin_only = [
            'manage_options', 'manage_h5p_libraries',
            'install_recommended_h5p_libraries', 'activate_plugins',
            'edit_theme_options', 'manage_network',
            'create_users', 'delete_users', 'list_users', 'promote_users',
            'pbsg_manage_librarians',
        ];

        foreach ($admin_only as $cap) {
            $this->assertArrayNotHasKey($cap, $caps, "Librarian should NOT have: {$cap}");
        }
    }

    /* =============================================================
       Source Code Structure Verification
       ============================================================= */

    public function test_activate_method_calls_remove_role_before_add_role(): void
    {
        $removePos = strpos($this->sourceCode, "remove_role( self::LIBRARIAN_ROLE )");
        $addPos = strpos($this->sourceCode, "add_role(");

        $this->assertNotFalse($removePos, 'activate() should call remove_role');
        $this->assertNotFalse($addPos, 'activate() should call add_role');
        $this->assertLessThan($addPos, $removePos, 'remove_role must come before add_role');
    }

    public function test_activate_method_grants_admin_custom_caps(): void
    {
        $this->assertStringContainsString("get_role( 'administrator' )", $this->sourceCode);
        $this->assertStringContainsString('$admin->add_cap( $cap )', $this->sourceCode);
    }

    public function test_deactivate_method_removes_role(): void
    {
        // Verify deactivate method exists and removes the role
        $this->assertStringContainsString('public static function deactivate()', $this->sourceCode);
        $this->assertStringContainsString("remove_role( self::LIBRARIAN_ROLE )", $this->sourceCode);
    }

    public function test_deactivate_method_cleans_admin_caps(): void
    {
        $this->assertStringContainsString('$admin->remove_cap( $cap )', $this->sourceCode);
    }

    public function test_is_admin_checks_manage_librarians_or_super_admin(): void
    {
        $this->assertStringContainsString("current_user_can( 'pbsg_manage_librarians' )", $this->sourceCode);
        $this->assertStringContainsString('is_super_admin()', $this->sourceCode);
    }

    public function test_is_librarian_checks_user_roles(): void
    {
        $this->assertStringContainsString('$user->roles', $this->sourceCode);
        $this->assertStringContainsString('self::LIBRARIAN_ROLE', $this->sourceCode);
    }
}
