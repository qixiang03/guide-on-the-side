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
     * Runtime capability filter for librarian users.
     *
     * 1. Denies blocked H5P capabilities (existing behavior).
     * 2. When cross-edit is ON, grants edit access to other librarians'
     *    tutorials but blocks delete/publish on posts they don't own.
     *    ONLY applies to Split Guide tutorials — Pressbooks native pages
     *    (Home, About, ToC, etc.) are never affected.
     *
     * @param bool[]   $allcaps All capabilities for the user.
     * @param string[] $caps    Required primitive capabilities for the check.
     * @param array    $args    Arguments: [0] = requested cap, [1] = user ID, [2] = post ID (for meta caps).
     * @param WP_User  $user    The user object.
     * @return bool[] Filtered capabilities.
     */
    public static function filter_librarian_caps( $allcaps, $caps, $args, $user ) {
        if ( ! in_array( self::LIBRARIAN_ROLE, $user->roles, true ) ) {
            return $allcaps;
        }

        // --- Existing: deny blocked H5P caps ---
        foreach ( self::BLOCKED_H5P_CAPS as $cap ) {
            $allcaps[ $cap ] = false;
        }

        // --- Cross-edit capability filtering ---
        // Only applies when a specific post is being checked (meta cap resolution)
        if ( empty( $args[2] ) ) {
            // List-level cap check (no specific post). Grant edit_others_pages
            // when cross-edit is ON so the list table shows other tutorials.
            if ( function_exists( 'get_option' ) ) {
                $cross_edit = (bool) get_option( 'pbsg_cross_edit_enabled', true );
                if ( ! $cross_edit ) {
                    // Cross-edit OFF: remove ability to see others' pages in lists
                    $allcaps['edit_others_pages'] = false;
                    $allcaps['delete_others_pages'] = false;
                }
            }
            return $allcaps;
        }

        // Post-specific cap check — get the post ID
        $post_id = (int) $args[2];

        // Only apply cross-edit logic to Split Guide tutorials
        if ( ! self::is_tutorial( $post_id ) ) {
            return $allcaps;
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return $allcaps;
        }

        $is_own = ( (int) $post->post_author === (int) $user->ID );
        $cross_edit = function_exists( 'get_option' )
            ? (bool) get_option( 'pbsg_cross_edit_enabled', true )
            : true;

        if ( ! $is_own ) {
            if ( $cross_edit ) {
                // Cross-edit ON: allow editing, block delete/publish
                $allcaps['edit_others_pages']   = true;
                $allcaps['edit_published_pages'] = true;
                // Block destructive actions on others' tutorials
                $allcaps['delete_others_pages']     = false;
                $allcaps['delete_published_pages']   = false;
                $allcaps['delete_pages']             = false;
                $allcaps['publish_pages']            = false;
            } else {
                // Cross-edit OFF: no access to others' tutorials
                $allcaps['edit_others_pages']        = false;
                $allcaps['delete_others_pages']       = false;
                $allcaps['delete_published_pages']    = false;
                $allcaps['publish_pages']             = false;
            }
        }
        // If $is_own, don't modify caps — librarian keeps full control on own tutorials

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

    /**
     * Check if a post is a Split Guide tutorial (by template meta).
     *
     * @param int $post_id The post ID to check.
     * @return bool
     */
    public static function is_tutorial( int $post_id ): bool {
        if ( $post_id <= 0 ) {
            return false;
        }
        $template = get_post_meta( $post_id, '_wp_page_template', true );
        return $template === 'split-guide-template.php';
    }
}
