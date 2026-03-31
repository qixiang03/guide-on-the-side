<?php
/**
 * PBSG Librarian Manager — Admin page for managing librarian accounts.
 *
 * Provides a custom admin page for admins to:
 *  - List all librarian users with key metadata
 *  - Register new librarian accounts
 *  - Link to native Pressbooks user-edit.php for profile editing
 *  - Deactivate librarian accounts (with tutorial reassignment)
 *  - Prompt role assignment when users are created via native "Add User"
 *  - Show GOTS role column in Network Users list
 *
 * @package    PB_Split_Guide
 * @subpackage Roles
 * @since      0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBSG_Librarian_Manager {

    /**
     * Admin page slug.
     */
    const PAGE_SLUG = 'pbsg-manage-librarians';

    /**
     * Initialize hooks.
     */
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 1001 );
        add_action( 'network_admin_menu', array( __CLASS__, 'register_admin_menu' ), 1001 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_form_submissions' ) );

        // Track last login time
        add_action( 'wp_login', array( __CLASS__, 'record_last_login' ), 10, 2 );

        // Native Pressbooks integration: prompt to assign librarian role
        // when a user is created via the native Network Admin "Add User" form
        add_action( 'wpmu_new_user', array( __CLASS__, 'on_native_user_created' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_assign_librarian_role' ) );

        // Add GOTS role column to the Network Users list table
        add_filter( 'wpmu_users_columns', array( __CLASS__, 'add_role_column' ) );
        add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_role_column' ), 10, 3 );

        // Customize the new-user welcome email for librarians
        add_filter( 'wp_new_user_notification_email', array( __CLASS__, 'customize_librarian_welcome_email' ), 10, 3 );
    }

    /**
     * Register the "Manage Librarians" admin menu page.
     */
    public static function register_admin_menu() {
        add_menu_page(
            __( 'Manage Librarians', 'pb-split-guide' ),
            __( 'Manage Librarians', 'pb-split-guide' ),
            'pbsg_manage_librarians',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' ),
            'dashicons-groups',
            4
        );
    }

    /**
     * Enqueue CSS and JS on our admin page and role badge styles on Network Users.
     */
    public static function enqueue_assets( $hook ) {
        $plugin_url = plugin_dir_url( dirname( __FILE__ ) );

        // Load role badge styles on the Network Users list page
        if ( $hook === 'users.php' || $hook === 'users-network' ) {
            wp_enqueue_style(
                'pbsg-role-badges',
                $plugin_url . 'assets/admin/admin-librarians.css',
                array(),
                '1.1.0'
            );
            return;
        }

        if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
            return;
        }

        // Local fonts (UPEI Design System — no external CDN)
        wp_enqueue_style(
            'pbsg-fonts',
            $plugin_url . 'assets/admin/pbsg-fonts.css',
            array(),
            '1.0.0'
        );

        wp_enqueue_style(
            'pbsg-librarian-manager',
            $plugin_url . 'assets/admin/admin-librarians.css',
            array( 'pbsg-fonts' ),
            '1.1.0'
        );

        wp_enqueue_script(
            'pbsg-librarian-manager',
            $plugin_url . 'assets/admin/admin-librarians.js',
            array( 'jquery' ),
            '1.1.0',
            true
        );

        wp_localize_script( 'pbsg-librarian-manager', 'pbsgLibrarians', array(
            'confirmDeactivate' => __( 'Are you sure you want to deactivate this librarian? They will no longer be able to access the admin panel.', 'pb-split-guide' ),
        ) );
    }

    /**
     * Handle form submissions (register, edit, deactivate).
     */
    public static function handle_form_submissions() {
        if ( ! isset( $_POST['pbsg_librarian_action'] ) ) {
            return;
        }

        if ( ! current_user_can( 'pbsg_manage_librarians' ) ) {
            wp_die( __( 'You do not have permission to manage librarians.', 'pb-split-guide' ) );
        }

        $action = sanitize_text_field( $_POST['pbsg_librarian_action'] );

        switch ( $action ) {
            case 'register':
                self::handle_register();
                break;
            case 'deactivate':
                self::handle_deactivate();
                break;
            case 'reactivate':
                self::handle_reactivate();
                break;
            case 'assign_librarian_from_manage':
                self::handle_assign_librarian_from_manage();
                break;
        }
    }

    /**
     * Handle new librarian registration.
     */
    private static function handle_register() {
        if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'pbsg_register_librarian' ) ) {
            wp_die( __( 'Security check failed.', 'pb-split-guide' ) );
        }

        $username   = isset( $_POST['username'] ) ? sanitize_user( $_POST['username'] ) : '';
        $email      = isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '';
        $first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( $_POST['first_name'] ) : '';
        $last_name  = isset( $_POST['last_name'] ) ? sanitize_text_field( $_POST['last_name'] ) : '';

        // Validation
        if ( empty( $username ) ) {
            self::redirect_with_notice( 'error', __( 'Username is required.', 'pb-split-guide' ) );
            return;
        }

        if ( empty( $email ) || ! is_email( $email ) ) {
            self::redirect_with_notice( 'error', __( 'A valid email address is required.', 'pb-split-guide' ) );
            return;
        }

        if ( username_exists( $username ) ) {
            self::redirect_with_notice( 'error', __( 'That username already exists.', 'pb-split-guide' ) );
            return;
        }

        if ( email_exists( $email ) ) {
            self::redirect_with_notice( 'error', __( 'That email address is already registered.', 'pb-split-guide' ) );
            return;
        }

        // Always auto-generate password — librarian must set their own via the reset link
        $password = wp_generate_password( 16, true, true );

        $user_id = wp_insert_user( array(
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => $password,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => trim( $first_name . ' ' . $last_name ) ?: $username,
            'role'         => PBSG_Roles::LIBRARIAN_ROLE,
        ) );

        if ( is_wp_error( $user_id ) ) {
            self::redirect_with_notice( 'error', $user_id->get_error_message() );
            return;
        }

        // Always send credential email with password reset link
        wp_new_user_notification( $user_id, null, 'user' );

        self::redirect_with_notice( 'success', sprintf(
            /* translators: %s: username */
            __( 'Librarian %s registered successfully.', 'pb-split-guide' ),
            $username
        ) );
    }

    /**
     * Handle librarian deactivation.
     */
    private static function handle_deactivate() {
        if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'pbsg_deactivate_librarian' ) ) {
            wp_die( __( 'Security check failed.', 'pb-split-guide' ) );
        }

        $user_id     = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        $reassign_to = isset( $_POST['reassign_to'] ) ? absint( $_POST['reassign_to'] ) : 0;

        if ( ! $user_id ) {
            self::redirect_with_notice( 'error', __( 'Invalid user.', 'pb-split-guide' ) );
            return;
        }

        $user = get_userdata( $user_id );
        if ( ! $user || ! in_array( PBSG_Roles::LIBRARIAN_ROLE, $user->roles, true ) ) {
            self::redirect_with_notice( 'error', __( 'User is not a librarian.', 'pb-split-guide' ) );
            return;
        }

        // Reassign tutorials if a target user is specified
        if ( $reassign_to > 0 ) {
            global $wpdb;
            $wpdb->update(
                $wpdb->posts,
                array( 'post_author' => $reassign_to ),
                array(
                    'post_author' => $user_id,
                    'post_type'   => 'page',
                ),
                array( '%d' ),
                array( '%d', '%s' )
            );
            clean_post_cache( 0 ); // Flush post caches
        }

        // Set role to subscriber (no admin access)
        $user->set_role( 'subscriber' );

        // Mark as former librarian so we can show them in the deactivated list
        update_user_meta( $user_id, 'pbsg_deactivated', current_time( 'mysql' ) );

        self::redirect_with_notice( 'success', sprintf(
            /* translators: %s: display name */
            __( 'Librarian %s has been deactivated.', 'pb-split-guide' ),
            $user->display_name
        ) );
    }

    /**
     * Handle librarian reactivation — restore pbsg_librarian role.
     */
    private static function handle_reactivate() {
        if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'pbsg_reactivate_librarian' ) ) {
            wp_die( __( 'Security check failed.', 'pb-split-guide' ) );
        }

        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;

        if ( ! $user_id ) {
            self::redirect_with_notice( 'error', __( 'Invalid user.', 'pb-split-guide' ) );
            return;
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            self::redirect_with_notice( 'error', __( 'User not found.', 'pb-split-guide' ) );
            return;
        }

        // Restore librarian role
        $user->set_role( PBSG_Roles::LIBRARIAN_ROLE );

        // Remove deactivated marker
        delete_user_meta( $user_id, 'pbsg_deactivated' );

        self::redirect_with_notice( 'success', sprintf(
            /* translators: %s: display name */
            __( 'Librarian %s has been reactivated.', 'pb-split-guide' ),
            $user->display_name
        ) );
    }

    /**
     * Handle assigning the librarian role from the Manage panel fallback.
     *
     * Adds pbsg_librarian alongside existing roles (does not replace),
     * then redirects back to the Manage panel for that user.
     */
    private static function handle_assign_librarian_from_manage() {
        if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'pbsg_assign_librarian_from_manage' ) ) {
            wp_die( __( 'Security check failed.', 'pb-split-guide' ) );
        }

        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;

        if ( ! $user_id ) {
            self::redirect_with_notice( 'error', __( 'Invalid user.', 'pb-split-guide' ) );
            return;
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            self::redirect_with_notice( 'error', __( 'User not found.', 'pb-split-guide' ) );
            return;
        }

        // Add librarian role alongside existing roles (does not replace)
        $user->add_role( PBSG_Roles::LIBRARIAN_ROLE );

        // Redirect back to the Manage panel — role check now passes
        wp_safe_redirect( add_query_arg( [
            'page'       => self::PAGE_SLUG,
            'sub_action' => 'manage',
            'edit_id'    => $user_id,
            'notice'     => 'success',
            'msg'        => urlencode( sprintf(
                /* translators: %s: display name */
                __( '%s has been assigned the Librarian role.', 'pb-split-guide' ),
                $user->display_name
            ) ),
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Render the Manage Librarians page.
     */
    public static function render_page() {
        if ( ! current_user_can( 'pbsg_manage_librarians' ) ) {
            wp_die( __( 'You do not have permission to view this page.', 'pb-split-guide' ) );
        }

        $sub_action = isset( $_GET['sub_action'] ) ? sanitize_text_field( $_GET['sub_action'] ) : 'list';
        $edit_id    = isset( $_GET['edit_id'] ) ? absint( $_GET['edit_id'] ) : 0;

        // Get all librarians (active + deactivated) and promotable users
        $librarians  = self::get_librarians();
        $deactivated = self::get_deactivated_librarians();
        $promotable  = self::get_promotable_users();

        // Get notice from redirect
        $notice_type = isset( $_GET['notice_type'] ) ? sanitize_text_field( $_GET['notice_type'] ) : '';
        $notice_msg  = isset( $_GET['notice_msg'] ) ? sanitize_text_field( urldecode( $_GET['notice_msg'] ) ) : '';

        $template = plugin_dir_path( dirname( __FILE__ ) ) . 'templates/admin-manage-librarians.php';
        if ( file_exists( $template ) ) {
            include $template;
        }
    }

    /**
     * Get all users with the librarian role, with tutorial counts.
     *
     * @return array
     */
    public static function get_librarians(): array {
        $users = get_users( array(
            'role' => PBSG_Roles::LIBRARIAN_ROLE,
            'orderby' => 'display_name',
            'order'   => 'ASC',
        ) );

        $librarians = array();
        foreach ( $users as $user ) {
            $tutorial_count = count_user_posts( $user->ID, 'page', true );
            $last_login     = get_user_meta( $user->ID, 'pbsg_last_login', true );

            $librarians[] = array(
                'ID'              => $user->ID,
                'user_login'      => $user->user_login,
                'display_name'    => $user->display_name,
                'user_email'      => $user->user_email,
                'first_name'      => $user->first_name,
                'last_name'       => $user->last_name,
                'user_registered' => $user->user_registered,
                'tutorial_count'  => $tutorial_count,
                'last_login'      => $last_login ?: __( 'Never', 'pb-split-guide' ),
            );
        }

        return $librarians;
    }

    /**
     * Get deactivated (former) librarians — subscribers with the pbsg_deactivated meta.
     *
     * @return array
     */
    public static function get_deactivated_librarians(): array {
        $users = get_users( array(
            'role'       => 'subscriber',
            'meta_key'   => 'pbsg_deactivated',
            'orderby'    => 'display_name',
            'order'      => 'ASC',
        ) );

        $deactivated = array();
        foreach ( $users as $user ) {
            $deactivated_date = get_user_meta( $user->ID, 'pbsg_deactivated', true );

            $deactivated[] = array(
                'ID'              => $user->ID,
                'user_login'      => $user->user_login,
                'display_name'    => $user->display_name,
                'user_email'      => $user->user_email,
                'deactivated_on'  => $deactivated_date,
            );
        }

        return $deactivated;
    }

    /**
     * Get other librarians/admins for the reassignment dropdown.
     *
     * @param int $exclude_id User ID to exclude.
     * @return array
     */
    public static function get_reassignment_targets( int $exclude_id ): array {
        $targets = array();

        // Get librarians
        $librarians = get_users( array( 'role' => PBSG_Roles::LIBRARIAN_ROLE ) );
        foreach ( $librarians as $user ) {
            if ( $user->ID !== $exclude_id ) {
                $targets[] = array(
                    'ID'           => $user->ID,
                    'display_name' => $user->display_name,
                );
            }
        }

        // Get admins
        $admins = get_users( array( 'role' => 'administrator' ) );
        foreach ( $admins as $user ) {
            $targets[] = array(
                'ID'           => $user->ID,
                'display_name' => $user->display_name . ' (Admin)',
            );
        }

        return $targets;
    }

    /**
     * Get users eligible for promotion to librarian role.
     *
     * Returns all users who are NOT admins, NOT already librarians,
     * and NOT deactivated former librarians (subscribers with pbsg_deactivated meta).
     *
     * @return array
     */
    public static function get_promotable_users(): array {
        // Get all users on this site (exclude super admins via post-filter)
        $all_users = get_users( array(
            'orderby' => 'display_name',
            'order'   => 'ASC',
        ) );

        $promotable = array();
        foreach ( $all_users as $user ) {
            // Skip super admins
            if ( is_super_admin( $user->ID ) ) {
                continue;
            }

            // Skip administrators
            if ( in_array( 'administrator', $user->roles, true ) ) {
                continue;
            }

            // Skip existing librarians
            if ( in_array( PBSG_Roles::LIBRARIAN_ROLE, $user->roles, true ) ) {
                continue;
            }

            // Skip deactivated former librarians (they appear in the Deactivated section)
            $deactivated = get_user_meta( $user->ID, 'pbsg_deactivated', true );
            if ( ! empty( $deactivated ) ) {
                continue;
            }

            $promotable[] = array(
                'ID'           => $user->ID,
                'user_login'   => $user->user_login,
                'display_name' => $user->display_name,
                'user_email'   => $user->user_email,
                'roles'        => ! empty( $user->roles ) ? implode( ', ', array_map( 'ucfirst', $user->roles ) ) : __( 'None', 'pb-split-guide' ),
            );
        }

        return $promotable;
    }

    /**
     * Record last login time for a user.
     *
     * @param string  $user_login The user's login name.
     * @param WP_User $user       The user object.
     */
    public static function record_last_login( $user_login, $user ) {
        update_user_meta( $user->ID, 'pbsg_last_login', current_time( 'mysql' ) );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Native Pressbooks User Management Integration
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When a user is created via the native Network Admin "Add User" form,
     * store a transient so we can show an admin notice offering to assign
     * the librarian role.
     *
     * @param int $user_id The newly created user's ID.
     */
    public static function on_native_user_created( $user_id ) {
        if ( ! current_user_can( 'pbsg_manage_librarians' ) && ! is_super_admin() ) {
            return;
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }

        // Skip if user was already created with the librarian role
        // (i.e., via our custom registration form)
        if ( in_array( PBSG_Roles::LIBRARIAN_ROLE, $user->roles, true ) ) {
            return;
        }

        // Store a transient so the admin notice fires on the next page load
        set_transient(
            'pbsg_new_user_prompt_' . get_current_user_id(),
            array(
                'user_id'    => $user_id,
                'user_login' => $user->user_login,
                'user_email' => $user->user_email,
            ),
            300 // 5 minutes — enough for the next page load
        );
    }

    /**
     * Show an admin notice when a new user was just created via native form,
     * prompting the admin to assign the librarian role.
     *
     * Hooked early so the notice renders at the top of the next admin page.
     */
    public static function maybe_show_new_user_notice() {
        $transient_key = 'pbsg_new_user_prompt_' . get_current_user_id();
        $data = get_transient( $transient_key );

        if ( ! $data ) {
            return;
        }

        // Only show once
        delete_transient( $transient_key );

        $assign_url = wp_nonce_url(
            admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&pbsg_assign_role=1&user_id=' . $data['user_id'] ),
            'pbsg_assign_librarian_' . $data['user_id']
        );

        add_action( 'admin_notices', function () use ( $data, $assign_url ) {
            ?>
            <div class="notice notice-info is-dismissible">
                <p>
                    <?php
                    printf(
                        /* translators: 1: username, 2: email */
                        esc_html__( 'New user "%1$s" (%2$s) was created. Should they be a Librarian for Guide on the Side?', 'pb-split-guide' ),
                        esc_html( $data['user_login'] ),
                        esc_html( $data['user_email'] )
                    );
                    ?>
                    <a href="<?php echo esc_url( $assign_url ); ?>" class="button button-primary" style="margin-left: 8px;">
                        <?php esc_html_e( 'Assign Librarian Role', 'pb-split-guide' ); ?>
                    </a>
                </p>
            </div>
            <?php
        } );

        // Also fire on network admin notices for network admin context
        add_action( 'network_admin_notices', function () use ( $data, $assign_url ) {
            ?>
            <div class="notice notice-info is-dismissible">
                <p>
                    <?php
                    printf(
                        /* translators: 1: username, 2: email */
                        esc_html__( 'New user "%1$s" (%2$s) was created. Should they be a Librarian for Guide on the Side?', 'pb-split-guide' ),
                        esc_html( $data['user_login'] ),
                        esc_html( $data['user_email'] )
                    );
                    ?>
                    <a href="<?php echo esc_url( $assign_url ); ?>" class="button button-primary" style="margin-left: 8px;">
                        <?php esc_html_e( 'Assign Librarian Role', 'pb-split-guide' ); ?>
                    </a>
                </p>
            </div>
            <?php
        } );
    }

    /**
     * Handle the "Assign Librarian Role" action link from the admin notice.
     */
    public static function handle_assign_librarian_role() {
        if ( ! isset( $_GET['pbsg_assign_role'] ) || $_GET['pbsg_assign_role'] !== '1' ) {
            // Also check for new-user notice transients on every admin page load
            self::maybe_show_new_user_notice();
            return;
        }

        $user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;

        if ( ! $user_id ) {
            return;
        }

        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'pbsg_assign_librarian_' . $user_id ) ) {
            wp_die( __( 'Security check failed.', 'pb-split-guide' ) );
        }

        if ( ! current_user_can( 'pbsg_manage_librarians' ) && ! is_super_admin() ) {
            wp_die( __( 'You do not have permission to manage librarians.', 'pb-split-guide' ) );
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            self::redirect_with_notice( 'error', __( 'User not found.', 'pb-split-guide' ) );
            return;
        }

        // Assign the librarian role
        $user->set_role( PBSG_Roles::LIBRARIAN_ROLE );

        self::redirect_with_notice( 'success', sprintf(
            /* translators: %s: username */
            __( 'User %s has been assigned the Librarian role.', 'pb-split-guide' ),
            $user->user_login
        ) );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Network Users List — GOTS Role Column
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Add a "GOTS Role" column to the Network Admin Users list table.
     *
     * @param array $columns Existing columns.
     * @return array
     */
    public static function add_role_column( $columns ) {
        // Insert after the 'email' column
        $new_columns = array();
        foreach ( $columns as $key => $label ) {
            $new_columns[ $key ] = $label;
            if ( $key === 'email' ) {
                $new_columns['pbsg_role'] = __( 'GOTS Role', 'pb-split-guide' );
            }
        }
        return $new_columns;
    }

    /**
     * Render the "GOTS Role" column value for each user row.
     *
     * @param string $value      Default column output (empty for custom columns).
     * @param string $column_name The column being rendered.
     * @param int    $user_id    The user's ID.
     * @return string
     */
    public static function render_role_column( $value, $column_name, $user_id ) {
        if ( 'pbsg_role' !== $column_name ) {
            return $value;
        }

        if ( is_super_admin( $user_id ) ) {
            return '<span class="pbsg-role-badge pbsg-role-admin">' . esc_html__( 'Admin', 'pb-split-guide' ) . '</span>';
        }

        $user = get_userdata( $user_id );
        if ( $user && in_array( PBSG_Roles::LIBRARIAN_ROLE, $user->roles, true ) ) {
            return '<span class="pbsg-role-badge pbsg-role-librarian">' . esc_html__( 'Librarian', 'pb-split-guide' ) . '</span>';
        }

        return '<span class="pbsg-role-badge pbsg-role-none">&mdash;</span>';
    }

    // ─────────────────────────────────────────────────────────────────────
    // Custom Welcome Email for Librarians
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Customize the WordPress new-user notification email for librarian accounts.
     *
     * Replaces the generic WordPress welcome email with a Guide on the Side
     * specific message that explains the librarian role and what they can do.
     *
     * Hooked on: wp_new_user_notification_email (WP 4.9+)
     *
     * @param array   $email  {
     *     Email components.
     *     @type string $to      Recipient email address.
     *     @type string $subject Email subject.
     *     @type string $message Email body.
     *     @type string $headers Email headers.
     * }
     * @param WP_User $user    The new user object.
     * @param string  $blogname The site title.
     * @return array Modified email components.
     */
    public static function customize_librarian_welcome_email( $email, $user, $blogname ) {
        // Only customize for librarian accounts
        if ( ! in_array( PBSG_Roles::LIBRARIAN_ROLE, (array) $user->roles, true ) ) {
            return $email;
        }

        $first_name  = $user->first_name ?: $user->display_name;
        $login_url   = wp_login_url();
        $site_name   = $blogname;

        // Generate a fresh password reset key and URL
        $reset_key = get_password_reset_key( $user );
        if ( is_wp_error( $reset_key ) ) {
            // Fallback: direct them to the lost-password form
            $reset_url = wp_lostpassword_url();
        } else {
            $reset_url = network_site_url(
                "wp-login.php?action=rp&key=$reset_key&login=" . rawurlencode( $user->user_login ),
                'login'
            );
        }

        // Build the custom email subject
        $email['subject'] = sprintf(
            /* translators: %s: site name */
            __( '[%s] Your Guide on the Side Librarian Account', 'pb-split-guide' ),
            $site_name
        );

        // Build the custom email body
        $email['message'] = sprintf(
            __(
                "Hi %1\$s,\r\n"
                . "\r\n"
                . "A librarian account has been created for you on %2\$s — the Guide on the Side tutorial system for the library.\r\n"
                . "\r\n"
                . "Your username: %3\$s\r\n"
                . "Login page: %4\$s\r\n"
                . "\r\n"
                . "To get started, please set your password by visiting the link below:\r\n"
                . "%5\$s\r\n"
                . "\r\n"
                . "This link will expire in 24 hours. If it expires, you can request a new one from the login page using the \"Lost your password?\" link.\r\n"
                . "\r\n"
                . "Once logged in, you will be able to:\r\n"
                . "- Create and manage interactive library tutorials\r\n"
                . "- Add H5P quiz questions to guide students\r\n"
                . "- View tutorial analytics and student performance\r\n"
                . "\r\n"
                . "If you have any questions, please contact your library administrator.\r\n"
                . "\r\n"
                . "— %2\$s\r\n",
                'pb-split-guide'
            ),
            $first_name,           // %1$s
            $site_name,            // %2$s
            $user->user_login,     // %3$s
            $login_url,            // %4$s
            $reset_url             // %5$s
        );

        return $email;
    }

    /**
     * Redirect back to the manage librarians page with a notice.
     *
     * @param string $type    Notice type ('success' or 'error').
     * @param string $message Notice message.
     */
    private static function redirect_with_notice( string $type, string $message ) {
        wp_safe_redirect( add_query_arg(
            array(
                'page'        => self::PAGE_SLUG,
                'notice_type' => $type,
                'notice_msg'  => urlencode( $message ),
            ),
            admin_url( 'admin.php' )
        ) );
        exit;
    }
}
