<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PBSG_Librarian_Manager class.
 *
 * Tests librarian management, native Pressbooks integration, and
 * Network Users role column via source code verification.
 */
final class PBSGLibrarianManagerTest extends TestCase
{
    /** @var string Source code of the librarian manager class */
    private string $sourceCode;

    protected function setUp(): void
    {
        $this->sourceCode = file_get_contents(
            dirname(__DIR__, 2) . '/web/app/plugins/pb-split-guide/includes/class-pbsg-librarian-manager.php'
        );
    }

    /* =============================================================
       Constants
       ============================================================= */

    public function test_page_slug_constant(): void
    {
        $this->assertSame('pbsg-manage-librarians', PBSG_Librarian_Manager::PAGE_SLUG);
    }

    /* =============================================================
       Init Hook Registration
       ============================================================= */

    public function test_init_registers_admin_menu_hooks(): void
    {
        $this->assertStringContainsString("'admin_menu', array( __CLASS__, 'register_admin_menu' ), 1001", $this->sourceCode);
        $this->assertStringContainsString("'network_admin_menu', array( __CLASS__, 'register_admin_menu' ), 1001", $this->sourceCode);
    }

    public function test_init_registers_enqueue_hook(): void
    {
        $this->assertStringContainsString("'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' )", $this->sourceCode);
    }

    public function test_init_registers_form_handler(): void
    {
        $this->assertStringContainsString("'admin_init', array( __CLASS__, 'handle_form_submissions' )", $this->sourceCode);
    }

    public function test_init_registers_last_login_tracker(): void
    {
        $this->assertStringContainsString("'wp_login', array( __CLASS__, 'record_last_login' ), 10, 2", $this->sourceCode);
    }

    public function test_init_registers_native_user_creation_hook(): void
    {
        $this->assertStringContainsString("'wpmu_new_user', array( __CLASS__, 'on_native_user_created' )", $this->sourceCode);
    }

    public function test_init_registers_role_assignment_handler(): void
    {
        $this->assertStringContainsString("'admin_init', array( __CLASS__, 'handle_assign_librarian_role' )", $this->sourceCode);
    }

    public function test_init_registers_network_users_column_filter(): void
    {
        $this->assertStringContainsString("'wpmu_users_columns', array( __CLASS__, 'add_role_column' )", $this->sourceCode);
    }

    public function test_init_registers_column_render_filter(): void
    {
        $this->assertStringContainsString("'manage_users_custom_column', array( __CLASS__, 'render_role_column' ), 10, 3", $this->sourceCode);
    }

    /* =============================================================
       Menu Registration
       ============================================================= */

    public function test_menu_uses_manage_librarians_capability(): void
    {
        $this->assertStringContainsString("'pbsg_manage_librarians'", $this->sourceCode);
    }

    public function test_menu_uses_groups_dashicon(): void
    {
        $this->assertStringContainsString("'dashicons-groups'", $this->sourceCode);
    }

    /* =============================================================
       Form Handling
       ============================================================= */

    public function test_form_handler_checks_capability(): void
    {
        $this->assertStringContainsString("current_user_can( 'pbsg_manage_librarians' )", $this->sourceCode);
    }

    public function test_form_handler_supports_register_action(): void
    {
        $this->assertStringContainsString("case 'register':", $this->sourceCode);
    }

    public function test_form_handler_supports_deactivate_action(): void
    {
        $this->assertStringContainsString("case 'deactivate':", $this->sourceCode);
    }

    public function test_form_handler_does_not_support_edit_action(): void
    {
        // Edit is handled by native Pressbooks user-edit.php
        $this->assertStringNotContainsString("case 'edit':", $this->sourceCode);
    }

    /* =============================================================
       Registration
       ============================================================= */

    public function test_register_uses_nonce_verification(): void
    {
        $this->assertStringContainsString("wp_verify_nonce( \$_POST['_wpnonce'] ?? '', 'pbsg_register_librarian' )", $this->sourceCode);
    }

    public function test_register_sanitizes_username(): void
    {
        $this->assertStringContainsString('sanitize_user(', $this->sourceCode);
    }

    public function test_register_sanitizes_email(): void
    {
        $this->assertStringContainsString('sanitize_email(', $this->sourceCode);
    }

    public function test_register_validates_email(): void
    {
        $this->assertStringContainsString('is_email( $email )', $this->sourceCode);
    }

    public function test_register_checks_username_exists(): void
    {
        $this->assertStringContainsString('username_exists( $username )', $this->sourceCode);
    }

    public function test_register_checks_email_exists(): void
    {
        $this->assertStringContainsString('email_exists( $email )', $this->sourceCode);
    }

    public function test_register_auto_generates_password(): void
    {
        $this->assertStringContainsString('wp_generate_password( 16, true, true )', $this->sourceCode);
    }

    public function test_register_assigns_librarian_role(): void
    {
        $this->assertStringContainsString("'role'         => PBSG_Roles::LIBRARIAN_ROLE", $this->sourceCode);
    }

    public function test_register_sends_email_notification(): void
    {
        $this->assertStringContainsString("wp_new_user_notification( \$user_id, null, 'user' )", $this->sourceCode);
    }

    /* =============================================================
       Deactivation
       ============================================================= */

    public function test_deactivate_uses_nonce_verification(): void
    {
        $this->assertStringContainsString("'pbsg_deactivate_librarian'", $this->sourceCode);
    }

    public function test_deactivate_verifies_librarian_role(): void
    {
        $this->assertStringContainsString('PBSG_Roles::LIBRARIAN_ROLE, $user->roles', $this->sourceCode);
    }

    public function test_deactivate_supports_tutorial_reassignment(): void
    {
        $this->assertStringContainsString("'post_author' => \$reassign_to", $this->sourceCode);
    }

    public function test_deactivate_sets_subscriber_role(): void
    {
        $this->assertStringContainsString("\$user->set_role( 'subscriber' )", $this->sourceCode);
    }

    /* =============================================================
       Native Pressbooks Integration
       ============================================================= */

    public function test_native_user_created_stores_transient(): void
    {
        $this->assertStringContainsString("set_transient(", $this->sourceCode);
        $this->assertStringContainsString("'pbsg_new_user_prompt_'", $this->sourceCode);
    }

    public function test_native_user_created_skips_existing_librarians(): void
    {
        $this->assertStringContainsString("PBSG_Roles::LIBRARIAN_ROLE, \$user->roles", $this->sourceCode);
    }

    public function test_native_user_created_transient_expires_in_5_minutes(): void
    {
        $this->assertStringContainsString('300 // 5 minutes', $this->sourceCode);
    }

    public function test_notice_uses_nonce_url(): void
    {
        $this->assertStringContainsString('wp_nonce_url(', $this->sourceCode);
        $this->assertStringContainsString("'pbsg_assign_librarian_'", $this->sourceCode);
    }

    public function test_notice_fires_on_both_admin_and_network(): void
    {
        $this->assertStringContainsString("'admin_notices'", $this->sourceCode);
        $this->assertStringContainsString("'network_admin_notices'", $this->sourceCode);
    }

    public function test_assign_role_verifies_nonce(): void
    {
        $this->assertStringContainsString("wp_verify_nonce( \$_GET['_wpnonce'] ?? '', 'pbsg_assign_librarian_' . \$user_id )", $this->sourceCode);
    }

    public function test_assign_role_checks_capability(): void
    {
        // The handler checks both custom cap and super admin
        $this->assertStringContainsString("current_user_can( 'pbsg_manage_librarians' )", $this->sourceCode);
        $this->assertStringContainsString('is_super_admin()', $this->sourceCode);
    }

    public function test_assign_role_sets_librarian_role(): void
    {
        $this->assertStringContainsString("\$user->set_role( PBSG_Roles::LIBRARIAN_ROLE )", $this->sourceCode);
    }

    /* =============================================================
       Network Users — GOTS Role Column
       ============================================================= */

    public function test_role_column_inserts_after_email(): void
    {
        $this->assertStringContainsString("\$key === 'email'", $this->sourceCode);
        $this->assertStringContainsString("'pbsg_role'", $this->sourceCode);
    }

    public function test_role_column_renders_admin_badge(): void
    {
        $this->assertStringContainsString('pbsg-role-admin', $this->sourceCode);
        $this->assertStringContainsString('is_super_admin( $user_id )', $this->sourceCode);
    }

    public function test_role_column_renders_librarian_badge(): void
    {
        $this->assertStringContainsString('pbsg-role-librarian', $this->sourceCode);
    }

    public function test_role_column_renders_none_badge(): void
    {
        $this->assertStringContainsString('pbsg-role-none', $this->sourceCode);
    }

    /* =============================================================
       Enqueue Assets
       ============================================================= */

    public function test_enqueue_loads_badges_on_network_users_page(): void
    {
        $this->assertStringContainsString("'users.php'", $this->sourceCode);
        $this->assertStringContainsString("'users-network'", $this->sourceCode);
        $this->assertStringContainsString("'pbsg-role-badges'", $this->sourceCode);
    }

    public function test_enqueue_loads_full_assets_on_manage_page(): void
    {
        $this->assertStringContainsString("'toplevel_page_' . self::PAGE_SLUG", $this->sourceCode);
        $this->assertStringContainsString("'pbsg-librarian-manager'", $this->sourceCode);
    }

    /* =============================================================
       Last Login Tracking
       ============================================================= */

    public function test_record_last_login_updates_user_meta(): void
    {
        $this->assertStringContainsString("update_user_meta( \$user->ID, 'pbsg_last_login'", $this->sourceCode);
    }

    /* =============================================================
       Render Page
       ============================================================= */

    public function test_render_page_checks_capability(): void
    {
        $method_start = strpos($this->sourceCode, 'function render_page()');
        $this->assertNotFalse($method_start);

        $method_section = substr($this->sourceCode, $method_start, 200);
        $this->assertStringContainsString("current_user_can( 'pbsg_manage_librarians' )", $method_section);
    }
}
