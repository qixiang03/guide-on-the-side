<?php
/**
 * PBSG Roles — Custom role and capability management for Guide on the Side.
 *
 * Registers the `pbsg_librarian` role on plugin activation with appropriate
 * capabilities for tutorial creation/management without WP admin access.
 *
 * @package    PB_Split_Guide
 * @subpackage Roles
 * @since      0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBSG_Roles {

    /**
     * Custom role slug for librarians.
     */
    const LIBRARIAN_ROLE = 'pbsg_librarian';

    /**
     * Custom capabilities defined by this plugin.
     */
    const CUSTOM_CAPS = array(
        'pbsg_view_analytics',
        'pbsg_export_csv',
        'pbsg_manage_tutorials',
        'pbsg_manage_librarians',
    );

    /**
     * Capabilities granted to the librarian role.
     *
     * @return array<string, bool>
     */
    public static function get_librarian_caps(): array {
        return array(
            // WordPress core — tutorial (page) management
            'read'                     => true,
            'upload_files'             => true,
            'edit_pages'               => true,
            'edit_others_pages'        => true,
            'edit_published_pages'     => true,
            'publish_pages'            => true,
            'delete_pages'             => true,
            'delete_others_pages'      => true,
            'delete_published_pages'   => true,
            // WordPress core — required by H5P plugin capability mapping.
            // H5P maps edit_posts → edit_h5p_contents at activation time.
            // Without edit_posts, H5P's map_capability() will strip
            // edit_h5p_contents from the role, blocking quiz editing.
            'edit_posts'               => true,
            // H5P — use content, not manage content types
            'view_h5p_contents'        => true,
            'edit_h5p_contents'        => true,
            'view_others_h5p_contents' => true,
            'view_h5p_results'         => true,
            // Plugin-specific
            'pbsg_view_analytics'      => true,
            'pbsg_export_csv'          => true,
            'pbsg_manage_tutorials'    => true,
        );
    }

    /**
     * Called on plugin activation.
     * Registers the pbsg_librarian role and grants admin the custom caps.
     */
    public static function activate() {
        // Remove first to refresh caps during development
        remove_role( self::LIBRARIAN_ROLE );

        add_role(
            self::LIBRARIAN_ROLE,
            __( 'Librarian', 'pb-split-guide' ),
            self::get_librarian_caps()
        );

        // Grant admin all custom caps (including manage_librarians)
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            foreach ( self::CUSTOM_CAPS as $cap ) {
                $admin->add_cap( $cap );
            }
        }
    }

    /**
     * Called on plugin deactivation.
     * Removes the custom role. Users with this role will have no role until
     * the plugin is reactivated.
     */
    public static function deactivate() {
        remove_role( self::LIBRARIAN_ROLE );

        // Clean custom caps from admin role
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            foreach ( self::CUSTOM_CAPS as $cap ) {
                $admin->remove_cap( $cap );
            }
        }
    }

    /**
     * Check if the current user is a GOTS admin.
     *
     * @return bool
     */
    public static function is_admin(): bool {
        return current_user_can( 'pbsg_manage_librarians' ) || is_super_admin();
    }

    /**
     * Check if the current user is a librarian.
     *
     * @return bool
     */
    public static function is_librarian(): bool {
        $user = wp_get_current_user();
        return in_array( self::LIBRARIAN_ROLE, $user->roles, true );
    }

    /**
     * Check if a user has any GOTS role (admin or librarian).
     *
     * @return bool
     */
    public static function is_gots_user(): bool {
        return self::is_admin() || self::is_librarian();
    }
}
