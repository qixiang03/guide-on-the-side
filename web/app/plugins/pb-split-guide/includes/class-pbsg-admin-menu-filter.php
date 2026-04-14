<?php
/**
 * PBSG Admin Menu Filter — WP admin lockdown for librarians.
 *
 * Hides non-GOTS menu items from librarian users and blocks direct URL
 * access to restricted admin pages via server-side redirect.
 *
 * @package    PB_Split_Guide
 * @subpackage Roles
 * @since      0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBSG_Admin_Menu_Filter {

    /**
     * Menu slugs that librarians are allowed to see.
     */
    const ALLOWED_MENU_SLUGS = array(
        'index.php',                   // WP Dashboard (home)
        'pbsg-my-tutorials',           // My Tutorials
        'edit.php?post_type=page',     // Tutorials (Pages)
        'pbsg-analytics',              // Tutorial Analytics
        'upload.php',                  // Media Library
        'h5p',                         // H5P Content
        'profile.php',                 // Own profile
    );

    /**
     * Admin pages (script names) that librarians may access directly.
     */
    const ALLOWED_SCRIPTS = array(
        'index.php',
        'edit.php',
        'post.php',
        'post-new.php',
        'revision.php',            // Browse/restore tutorial revisions
        'upload.php',
        'media-new.php',
        'profile.php',
        'admin-ajax.php',
        'admin-post.php',
        'admin.php',
    );

    /**
     * Page parameters (?page=...) that librarians may access.
     */
    const ALLOWED_PAGE_PARAMS = array(
        'pbsg-my-tutorials',
        'pbsg-new-tutorial',
        'pbsg-analytics',
        'h5p',
        'h5p_new',
    );

    /**
     * Initialize hooks.
     */
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'filter_menus' ), 9999 );
        add_action( 'admin_init', array( __CLASS__, 'redirect_unauthorized' ), 1 );
        add_filter( 'login_redirect', array( __CLASS__, 'librarian_login_redirect' ), 10, 3 );
    }

    /**
     * Remove admin menu items that librarians should not see.
     */
    public static function filter_menus() {
        // Admins and super admins see everything
        if ( PBSG_Roles::is_admin() ) {
            return;
        }

        // Only filter for our librarian role
        if ( ! PBSG_Roles::is_librarian() ) {
            return;
        }

        global $menu;
        if ( ! is_array( $menu ) ) {
            return;
        }

        foreach ( $menu as $position => $item ) {
            if ( ! isset( $item[2] ) ) {
                continue;
            }
            if ( ! in_array( $item[2], self::ALLOWED_MENU_SLUGS, true ) ) {
                remove_menu_page( $item[2] );
            }
        }

        // Remove H5P "Libraries" submenu (requires manage_h5p_libraries)
        remove_submenu_page( 'h5p', 'h5p_libraries' );
        // Remove H5P "Settings" submenu
        remove_submenu_page( 'options-general.php', 'h5p_settings' );
    }

    /**
     * Server-side guard: redirect librarians away from restricted admin pages.
     * This prevents direct URL access even if menus are hidden.
     */
    public static function redirect_unauthorized() {
        if ( ! is_admin() || wp_doing_ajax() ) {
            return;
        }

        // Admins pass through
        if ( PBSG_Roles::is_admin() ) {
            return;
        }

        // Only restrict our librarian role
        if ( ! PBSG_Roles::is_librarian() ) {
            return;
        }

        $current_script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( $_SERVER['SCRIPT_NAME'] ) : '';
        $page_param     = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : '';
        $post_type      = isset( $_GET['post_type'] ) ? sanitize_text_field( $_GET['post_type'] ) : '';

        // Check script name
        $script_allowed = in_array( $current_script, self::ALLOWED_SCRIPTS, true );

        // If it's admin.php, we need to also check the ?page= param
        if ( $current_script === 'admin.php' && $page_param !== '' ) {
            $script_allowed = in_array( $page_param, self::ALLOWED_PAGE_PARAMS, true );
        }

        // For edit.php and post-new.php, require post_type=page explicitly.
        // Without this, navigating to edit.php (no params) shows the Posts
        // list, which librarians shouldn't access (edit_posts is only granted
        // for H5P capability mapping, not for actual post management).
        if ( in_array( $current_script, array( 'edit.php', 'post-new.php' ), true ) ) {
            if ( $post_type !== 'page' ) {
                $script_allowed = false;
            }
        }

        if ( ! $script_allowed ) {
            wp_safe_redirect( admin_url( 'admin.php?page=pbsg-my-tutorials' ) );
            exit;
        }
    }

    /**
     * Redirect librarians to "My Tutorials" after login instead of WP dashboard.
     *
     * @param string  $redirect_to The redirect destination URL.
     * @param string  $requested   The requested redirect URL.
     * @param WP_User $user        The logged-in user object.
     * @return string
     */
    public static function librarian_login_redirect( $redirect_to, $requested, $user ) {
        if ( ! is_a( $user, 'WP_User' ) ) {
            return $redirect_to;
        }

        if ( in_array( PBSG_Roles::LIBRARIAN_ROLE, $user->roles, true ) ) {
            return admin_url( 'admin.php?page=pbsg-my-tutorials' );
        }

        return $redirect_to;
    }
}
