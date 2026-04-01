<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Cross-Editing & Ownership Transfer feature.
 *
 * Tests capability filtering logic, settings helpers, transfer validation,
 * and touched tracking. Uses source code analysis for structural verification
 * (same pattern as PBSGRolesTest).
 */
final class PBSGCrossEditTest extends TestCase
{
    /** @var string Source code of the roles class */
    private string $rolesSource;

    /** @var string Source code of the main plugin class */
    private string $pluginSource;

    /** @var string Source code of the My Tutorials template */
    private string $templateSource;

    protected function setUp(): void
    {
        $pluginDir = dirname(__DIR__, 2) . '/web/app/plugins/pb-split-guide/';
        $this->rolesSource    = file_get_contents($pluginDir . 'includes/class-pbsg-roles.php');
        $this->pluginSource   = file_get_contents($pluginDir . 'pb-split-guide.php');
        $this->templateSource = file_get_contents($pluginDir . 'templates/admin-my-tutorials.php');
    }

    /* =============================================================
       Settings Constants & Registration
       ============================================================= */

    public function test_cross_edit_option_constant_is_defined(): void
    {
        $this->assertSame('pbsg_cross_edit_enabled', PB_Split_Guide_Plugin::OPTION_CROSS_EDIT);
    }

    public function test_transfer_option_constant_is_defined(): void
    {
        $this->assertSame('pbsg_ownership_transfer_enabled', PB_Split_Guide_Plugin::OPTION_TRANSFER);
    }

    public function test_settings_registration_includes_cross_edit_option(): void
    {
        $this->assertStringContainsString(
            "register_setting('pbsg_guide_settings', self::OPTION_CROSS_EDIT",
            $this->pluginSource,
            'register_guide_settings() must register the cross-edit option'
        );
    }

    public function test_settings_registration_includes_transfer_option(): void
    {
        $this->assertStringContainsString(
            "register_setting('pbsg_guide_settings', self::OPTION_TRANSFER",
            $this->pluginSource,
            'register_guide_settings() must register the transfer option'
        );
    }

    public function test_cross_edit_setting_defaults_to_true(): void
    {
        $this->assertMatchesRegularExpression(
            "/OPTION_CROSS_EDIT.*'default'\s*=>\s*true/s",
            $this->pluginSource,
            'Cross-edit setting must default to true'
        );
    }

    public function test_transfer_setting_defaults_to_true(): void
    {
        $this->assertMatchesRegularExpression(
            "/OPTION_TRANSFER.*'default'\s*=>\s*true/s",
            $this->pluginSource,
            'Transfer setting must default to true'
        );
    }

    /* =============================================================
       Settings Helper Methods
       ============================================================= */

    public function test_is_cross_edit_enabled_method_exists(): void
    {
        $this->assertStringContainsString(
            'function is_cross_edit_enabled(): bool',
            $this->pluginSource
        );
    }

    public function test_is_transfer_enabled_method_exists(): void
    {
        $this->assertStringContainsString(
            'function is_transfer_enabled(): bool',
            $this->pluginSource
        );
    }

    /* =============================================================
       Capability Filtering — is_tutorial() Guard
       ============================================================= */

    public function test_is_tutorial_helper_exists_in_roles_class(): void
    {
        $this->assertStringContainsString(
            'function is_tutorial( int $post_id ): bool',
            $this->rolesSource
        );
    }

    public function test_is_tutorial_checks_page_template_meta(): void
    {
        $this->assertStringContainsString(
            "'_wp_page_template'",
            $this->rolesSource,
            'is_tutorial() must check _wp_page_template meta'
        );
        $this->assertStringContainsString(
            "'split-guide-template.php'",
            $this->rolesSource,
            'is_tutorial() must compare against split-guide-template.php'
        );
    }

    public function test_is_tutorial_rejects_zero_post_id(): void
    {
        $this->assertStringContainsString(
            '$post_id <= 0',
            $this->rolesSource,
            'is_tutorial() must return false for zero/negative post IDs'
        );
    }

    /* =============================================================
       Capability Filtering — Cross-Edit Logic
       ============================================================= */

    public function test_filter_grants_edit_others_pages_when_cross_edit_on(): void
    {
        $this->assertStringContainsString(
            "'edit_others_pages'",
            $this->rolesSource,
            'Capability filter must reference edit_others_pages'
        );
    }

    public function test_filter_blocks_delete_on_others_tutorials(): void
    {
        $this->assertStringContainsString(
            "'delete_others_pages'",
            $this->rolesSource,
            'Filter must handle delete_others_pages'
        );
        $this->assertStringContainsString(
            "'delete_published_pages'",
            $this->rolesSource,
            'Filter must handle delete_published_pages'
        );
    }

    public function test_filter_blocks_publish_on_others_tutorials(): void
    {
        $this->assertStringContainsString(
            "'publish_pages'",
            $this->rolesSource,
            'Filter must handle publish_pages'
        );
    }

    public function test_filter_checks_post_ownership(): void
    {
        $this->assertStringContainsString(
            'post_author',
            $this->rolesSource,
            'Filter must check post_author for ownership'
        );
    }

    public function test_filter_reads_cross_edit_option(): void
    {
        $this->assertStringContainsString(
            "'pbsg_cross_edit_enabled'",
            $this->rolesSource,
            'Filter must read the pbsg_cross_edit_enabled option'
        );
    }

    public function test_filter_still_blocks_h5p_caps(): void
    {
        $this->assertStringContainsString(
            'BLOCKED_H5P_CAPS',
            $this->rolesSource,
            'Filter must still deny blocked H5P caps'
        );
    }

    public function test_filter_skips_non_librarian_users(): void
    {
        $this->assertStringContainsString(
            'self::LIBRARIAN_ROLE',
            $this->rolesSource
        );
    }

    /* =============================================================
       Transfer AJAX Endpoint
       ============================================================= */

    public function test_transfer_ajax_handler_registered(): void
    {
        $this->assertStringContainsString(
            "wp_ajax_pbsg_transfer_ownership",
            $this->pluginSource,
            'Transfer AJAX handler must be registered'
        );
    }

    public function test_transfer_handler_checks_nonce(): void
    {
        $this->assertStringContainsString(
            "check_ajax_referer('pbsg_transfer_ownership'",
            $this->pluginSource,
            'Transfer handler must verify nonce'
        );
    }

    public function test_transfer_handler_validates_target_user_role(): void
    {
        $this->assertStringContainsString(
            'LIBRARIAN_ROLE',
            $this->pluginSource,
            'Transfer handler must validate target has librarian or admin role'
        );
    }

    public function test_transfer_handler_checks_tutorial_template(): void
    {
        $this->assertStringContainsString(
            'is_tutorial',
            $this->pluginSource,
            'Transfer handler must verify posts are tutorials'
        );
    }

    public function test_transfer_handler_prevents_self_transfer(): void
    {
        $this->assertStringContainsString(
            'already owned',
            $this->pluginSource,
            'Transfer handler must prevent self-transfer'
        );
    }

    public function test_transfer_handler_updates_post_author(): void
    {
        $this->assertStringContainsString(
            "'post_author' => \$new_owner_id",
            $this->pluginSource,
            'Transfer handler must update post_author'
        );
    }

    public function test_transfer_targets_ajax_registered(): void
    {
        $this->assertStringContainsString(
            "wp_ajax_pbsg_get_transfer_targets",
            $this->pluginSource,
            'Transfer targets AJAX handler must be registered'
        );
    }

    /* =============================================================
       Touched Tracking
       ============================================================= */

    public function test_save_meta_tracks_editors(): void
    {
        $this->assertStringContainsString(
            "'_pbsg_editors'",
            $this->pluginSource,
            'save_meta() must update the _pbsg_editors post meta'
        );
    }

    public function test_editors_meta_stores_user_id_and_timestamp(): void
    {
        $this->assertStringContainsString(
            "'user_id'",
            $this->pluginSource
        );
        $this->assertStringContainsString(
            "'last_edited'",
            $this->pluginSource
        );
    }

    /* =============================================================
       My Tutorials Template
       ============================================================= */

    public function test_template_has_recently_worked_on_tab(): void
    {
        $this->assertStringContainsString(
            'Recently Worked On',
            $this->templateSource,
            'Template must have "Recently Worked On" tab'
        );
    }

    public function test_template_has_my_tutorials_tab(): void
    {
        $this->assertStringContainsString(
            'My Tutorials',
            $this->templateSource,
            'Template must have "My Tutorials" tab'
        );
    }

    public function test_template_shows_owner_name(): void
    {
        $this->assertStringContainsString(
            'owner_name',
            $this->templateSource,
            'Template must display the tutorial owner name'
        );
    }

    public function test_template_has_transfer_button(): void
    {
        $this->assertStringContainsString(
            'pbsg-transfer-single',
            $this->templateSource,
            'Template must have an inline transfer button'
        );
    }

    public function test_template_has_bulk_transfer_button(): void
    {
        $this->assertStringContainsString(
            'pbsg-bulk-transfer',
            $this->templateSource,
            'Template must have a bulk transfer button'
        );
    }

    public function test_template_has_select_all_checkbox(): void
    {
        $this->assertStringContainsString(
            'pbsg-select-all-tutorials',
            $this->templateSource,
            'Template must have a select-all checkbox'
        );
    }

    /* =============================================================
       Owner Metabox
       ============================================================= */

    public function test_owner_metabox_registered(): void
    {
        $this->assertStringContainsString(
            'pbsg_owner_box',
            $this->pluginSource,
            'Owner metabox must be registered'
        );
    }

    public function test_owner_metabox_render_method_exists(): void
    {
        $this->assertStringContainsString(
            'function render_owner_metabox',
            $this->pluginSource,
            'render_owner_metabox() method must exist'
        );
    }

    public function test_owner_metabox_has_transfer_button(): void
    {
        $this->assertStringContainsString(
            'Transfer Ownership',
            $this->pluginSource,
            'Owner metabox must show a Transfer Ownership button'
        );
    }

    /* =============================================================
       Tutorial Attributes Metabox Hidden
       ============================================================= */

    public function test_page_attributes_metabox_removed_for_tutorials(): void
    {
        $this->assertStringContainsString(
            "remove_meta_box('pageparentdiv'",
            $this->pluginSource,
            'Tutorial Attributes metabox must be removed for tutorials'
        );
    }

    /* =============================================================
       Settings UI — Permissions Section
       ============================================================= */

    public function test_settings_page_has_permissions_section(): void
    {
        $this->assertStringContainsString(
            'Permissions',
            $this->pluginSource,
            'Settings page must have a Permissions section'
        );
    }

    public function test_settings_page_has_cross_edit_checkbox(): void
    {
        $this->assertStringContainsString(
            'pbsg_cross_edit_toggle',
            $this->pluginSource,
            'Settings page must have the cross-edit toggle checkbox'
        );
    }

    public function test_settings_page_has_transfer_checkbox(): void
    {
        $this->assertStringContainsString(
            'pbsg_transfer_toggle',
            $this->pluginSource,
            'Settings page must have the transfer toggle checkbox'
        );
    }
}
