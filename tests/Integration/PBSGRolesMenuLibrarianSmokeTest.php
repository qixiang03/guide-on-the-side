<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration smoke tests for PBSG_Roles, PBSG_Admin_Menu_Filter, and PBSG_Librarian_Manager.
 *
 * Verifies runtime behavior of login redirect, role column, and column rendering
 * using WP stubs (no full WordPress).
 */
final class PBSGRolesMenuLibrarianSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        WPStubs::reset();
    }

    protected function tearDown(): void
    {
        WPStubs::reset();
    }

    /* =============================================================
     *  PBSG_Admin_Menu_Filter::librarian_login_redirect
     *  ============================================================= */

    public function test_librarian_login_redirect_returns_my_tutorials_url_for_librarian(): void
    {
        $user = new WP_User([PBSG_Roles::LIBRARIAN_ROLE]);
        $redirect_to = 'https://example.com/wp-admin/';
        $result = PBSG_Admin_Menu_Filter::librarian_login_redirect($redirect_to, '', $user);

        $expected = admin_url('admin.php?page=pbsg-my-tutorials');
        $this->assertSame($expected, $result);
    }

    public function test_librarian_login_redirect_returns_original_for_non_librarian_user(): void
    {
        $user = new WP_User(['subscriber']);
        $redirect_to = 'https://example.com/wp-admin/custom-dest';
        $result = PBSG_Admin_Menu_Filter::librarian_login_redirect($redirect_to, '', $user);

        $this->assertSame($redirect_to, $result);
    }

    public function test_librarian_login_redirect_returns_original_when_not_wp_user(): void
    {
        $not_user = (object) ['roles' => [PBSG_Roles::LIBRARIAN_ROLE]];
        $redirect_to = 'https://example.com/wp-admin/';
        $result = PBSG_Admin_Menu_Filter::librarian_login_redirect($redirect_to, '', $not_user);

        $this->assertSame($redirect_to, $result);
    }

    /* =============================================================
     *  PBSG_Librarian_Manager::add_role_column
     *  ============================================================= */

    public function test_add_role_column_inserts_pbsg_role_after_email(): void
    {
        $columns = [
            'username' => 'Username',
            'name'     => 'Name',
            'email'    => 'Email',
            'registered' => 'Registered',
        ];

        $result = PBSG_Librarian_Manager::add_role_column($columns);

        $this->assertArrayHasKey('pbsg_role', $result);
        $keys = array_keys($result);
        $email_pos = array_search('email', $keys, true);
        $role_pos = array_search('pbsg_role', $keys, true);
        $this->assertNotFalse($email_pos, 'email key should exist');
        $this->assertNotFalse($role_pos, 'pbsg_role key should exist');
        $this->assertGreaterThan($email_pos, $role_pos, 'pbsg_role should appear after email');
    }

    /* =============================================================
     *  PBSG_Librarian_Manager::render_role_column
     *  ============================================================= */

    public function test_render_role_column_returns_admin_badge_for_super_admin(): void
    {
        WPStubs::$returns['is_super_admin'] = true;

        $result = PBSG_Librarian_Manager::render_role_column('', 'pbsg_role', 1);

        $this->assertStringContainsString('pbsg-role-admin', $result);
        $this->assertStringContainsString('Admin', $result);
    }

    public function test_render_role_column_returns_librarian_badge_for_librarian(): void
    {
        WPStubs::$returns['is_super_admin'] = false;
        WPStubs::$returns['get_userdata'] = (object) [
            'ID' => 2,
            'roles' => [PBSG_Roles::LIBRARIAN_ROLE],
        ];

        $result = PBSG_Librarian_Manager::render_role_column('', 'pbsg_role', 2);

        $this->assertStringContainsString('pbsg-role-librarian', $result);
        $this->assertStringContainsString('Librarian', $result);
    }

    public function test_render_role_column_returns_none_badge_for_regular_user(): void
    {
        WPStubs::$returns['is_super_admin'] = false;
        WPStubs::$returns['get_userdata'] = (object) [
            'ID' => 3,
            'roles' => ['subscriber'],
        ];

        $result = PBSG_Librarian_Manager::render_role_column('', 'pbsg_role', 3);

        $this->assertStringContainsString('pbsg-role-none', $result);
    }

    public function test_render_role_column_returns_original_value_for_other_columns(): void
    {
        $result = PBSG_Librarian_Manager::render_role_column('original', 'other_column', 1);

        $this->assertSame('original', $result);
    }
}
