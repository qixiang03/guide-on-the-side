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
     * H5P capabilities that must NEVER be granted to librarians.
     *
     * H5P's assign_capabilities() auto-maps these from core caps that
     * librarians legitimately need (edit_others_pages → install_recommended,
     * manage_options → manage_h5p_libraries). We deny them at runtime via
     * the user_has_cap filter so no database race condition can re-grant them.
     */
    const BLOCKED_H5P_CAPS = array(
        'install_recommended_h5p_libraries', // mapped from edit_others_pages — installs content types from Hub
        'manage_h5p_libraries',              // mapped from manage_options — admin Libraries page
        'disable_h5p_security',              // mapped from install_plugins — disables file extension checks
    );

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

        // Best-effort cleanup: remove any H5P-auto-mapped caps from the
        // stored role. The real enforcement is the user_has_cap filter in
        // init(), which denies these at runtime regardless of DB state.
        $role = get_role( self::LIBRARIAN_ROLE );
        if ( $role ) {
            foreach ( self::BLOCKED_H5P_CAPS as $cap ) {
                $role->remove_cap( $cap );
            }
        }

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
     * Initialize runtime hooks.
     * Call this from the main plugin file on every request.
     */
    public static function init() {
        // Runtime capability filter — intercepts current_user_can() checks
        // to deny H5P management caps for librarians. This is immune to
        // H5P's assign_capabilities() re-adding caps at any hook priority,
        // because we filter at check time rather than modifying stored caps.
        add_filter( 'user_has_cap', array( __CLASS__, 'filter_librarian_caps' ), 10, 4 );
    }

    /**
     * Deny blocked H5P capabilities for librarian users at runtime.
     *
     * WordPress calls this filter every time current_user_can() is invoked.
     * For librarians, we force-deny capabilities that H5P auto-maps from
     * core caps (e.g. edit_others_pages → install_recommended_h5p_libraries).
     * This prevents librarians from installing new content types from the
     * H5P Hub while still allowing them to create and edit H5P content.
     *
     * @param bool[]   $allcaps All capabilities for the user.
     * @param string[] $caps    Required primitive capabilities for the check.
     * @param array    $args    Arguments: [0] = requested cap, [1] = user ID.
     * @param WP_User  $user    The user object.
     * @return bool[] Filtered capabilities.
     */
    public static function filter_librarian_caps( $allcaps, $caps, $args, $user ) {
        if ( ! in_array( self::LIBRARIAN_ROLE, $user->roles, true ) ) {
            return $allcaps;
        }

        foreach ( self::BLOCKED_H5P_CAPS as $cap ) {
            $allcaps[ $cap ] = false;
        }

        return $allcaps;
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
