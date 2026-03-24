<?php
/**
 * Template: Manage Librarians admin page.
 *
 * Expected variables:
 * - $librarians   : array of active librarian data
 * - $deactivated  : array of deactivated (former) librarian data
 * - $sub_action   : string ('list' or 'manage')
 * - $edit_id      : int (user ID when editing)
 * - $notice_type  : string ('success' or 'error')
 * - $notice_msg   : string
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="wrap pbsg-librarians-wrap">

    <!-- Page Header -->
    <div class="pbsg-librarians-header">
        <div class="pbsg-librarians-header-left">
            <h1 class="pbsg-librarians-title">
                <?php esc_html_e( 'Manage Librarians', 'pb-split-guide' ); ?>
            </h1>
            <p class="pbsg-librarians-subtitle">
                <?php esc_html_e( 'Register, edit, and manage librarian accounts for Guide on the Side.', 'pb-split-guide' ); ?>
            </p>
        </div>
        <div class="pbsg-librarians-header-right">
            <button type="button" class="pbsg-btn pbsg-btn-primary" id="pbsg-toggle-register-form">
                + <?php esc_html_e( 'Register New Librarian', 'pb-split-guide' ); ?>
            </button>
        </div>
    </div>

    <!-- Admin Notices -->
    <?php if ( ! empty( $notice_msg ) ) : ?>
        <div class="notice notice-<?php echo $notice_type === 'success' ? 'success' : 'error'; ?> is-dismissible">
            <p><?php echo esc_html( $notice_msg ); ?></p>
        </div>
    <?php endif; ?>

    <!-- Registration Form (collapsible) -->
    <div class="pbsg-librarians-card pbsg-register-panel" id="pbsg-register-panel" style="display:none;">
        <h2 class="pbsg-card-title"><?php esc_html_e( 'Register New Librarian', 'pb-split-guide' ); ?></h2>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="pbsg-register-form">
            <?php wp_nonce_field( 'pbsg_register_librarian' ); ?>
            <input type="hidden" name="pbsg_librarian_action" value="register" />

            <div class="pbsg-form-grid">
                <div class="pbsg-form-field">
                    <label for="pbsg-reg-username"><?php esc_html_e( 'Username', 'pb-split-guide' ); ?> <span class="required">*</span></label>
                    <input type="text" id="pbsg-reg-username" name="username" required autocomplete="off" />
                </div>

                <div class="pbsg-form-field">
                    <label for="pbsg-reg-email"><?php esc_html_e( 'Email', 'pb-split-guide' ); ?> <span class="required">*</span></label>
                    <input type="email" id="pbsg-reg-email" name="email" required />
                </div>

                <div class="pbsg-form-field">
                    <label for="pbsg-reg-firstname"><?php esc_html_e( 'First Name', 'pb-split-guide' ); ?></label>
                    <input type="text" id="pbsg-reg-firstname" name="first_name" />
                </div>

                <div class="pbsg-form-field">
                    <label for="pbsg-reg-lastname"><?php esc_html_e( 'Last Name', 'pb-split-guide' ); ?></label>
                    <input type="text" id="pbsg-reg-lastname" name="last_name" />
                </div>

                <div class="pbsg-form-field pbsg-form-field--full">
                    <label for="pbsg-reg-password"><?php esc_html_e( 'Password', 'pb-split-guide' ); ?></label>
                    <input type="password" id="pbsg-reg-password" name="password" autocomplete="new-password" />
                    <p class="description"><?php esc_html_e( 'Leave blank to auto-generate and email credentials to the librarian.', 'pb-split-guide' ); ?></p>
                </div>

                <div class="pbsg-form-field pbsg-form-field--full">
                    <label class="pbsg-checkbox-label">
                        <input type="checkbox" name="send_email" value="1" checked />
                        <?php esc_html_e( 'Send login credentials via email', 'pb-split-guide' ); ?>
                    </label>
                </div>
            </div>

            <div class="pbsg-form-actions">
                <button type="submit" class="pbsg-btn pbsg-btn-primary">
                    <?php esc_html_e( 'Register Librarian', 'pb-split-guide' ); ?>
                </button>
                <button type="button" class="pbsg-btn pbsg-btn-secondary" id="pbsg-cancel-register">
                    <?php esc_html_e( 'Cancel', 'pb-split-guide' ); ?>
                </button>
            </div>
        </form>
    </div>

    <?php if ( $sub_action === 'manage' && $edit_id > 0 ) : ?>
        <?php
        $edit_user = get_userdata( $edit_id );
        if ( $edit_user && in_array( PBSG_Roles::LIBRARIAN_ROLE, $edit_user->roles, true ) ) :
            $reassignment_targets = PBSG_Librarian_Manager::get_reassignment_targets( $edit_id );
            $tutorial_count       = count_user_posts( $edit_id, 'page', true );
            $last_login           = get_user_meta( $edit_id, 'pbsg_last_login', true );
            // Build the native user-edit URL (network admin context)
            $native_edit_url = network_admin_url( 'user-edit.php?user_id=' . $edit_id );
        ?>

        <!-- Librarian Profile Overview + Actions -->
        <div class="pbsg-librarians-card pbsg-edit-panel">
            <div class="pbsg-profile-header">
                <div class="pbsg-profile-header-left">
                    <h2 class="pbsg-card-title">
                        <?php printf( esc_html__( 'Manage Librarian: %s', 'pb-split-guide' ), esc_html( $edit_user->display_name ) ); ?>
                    </h2>
                    <p class="pbsg-profile-meta">
                        <?php echo esc_html( $edit_user->user_login ); ?>
                        &middot;
                        <a href="mailto:<?php echo esc_attr( $edit_user->user_email ); ?>"><?php echo esc_html( $edit_user->user_email ); ?></a>
                        &middot;
                        <?php printf(
                            /* translators: %d: number of tutorials */
                            esc_html( _n( '%d tutorial', '%d tutorials', $tutorial_count, 'pb-split-guide' ) ),
                            $tutorial_count
                        ); ?>
                        &middot;
                        <?php printf(
                            /* translators: %s: last login date or "Never" */
                            esc_html__( 'Last login: %s', 'pb-split-guide' ),
                            $last_login ? esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last_login ) ) ) : esc_html__( 'Never', 'pb-split-guide' )
                        ); ?>
                    </p>
                </div>
                <div class="pbsg-profile-header-right">
                    <a href="<?php echo esc_url( $native_edit_url ); ?>" class="pbsg-btn pbsg-btn-primary">
                        <?php esc_html_e( 'Edit Full Profile', 'pb-split-guide' ); ?>
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PBSG_Librarian_Manager::PAGE_SLUG ) ); ?>" class="pbsg-btn pbsg-btn-secondary">
                        <?php esc_html_e( 'Back to List', 'pb-split-guide' ); ?>
                    </a>
                </div>
            </div>

            <p class="pbsg-profile-hint">
                <?php
                printf(
                    /* translators: %s: link to native edit screen */
                    esc_html__( 'To edit this librarian\'s name, email, password, contact info, or institution, use the %s.', 'pb-split-guide' ),
                    '<a href="' . esc_url( $native_edit_url ) . '">' . esc_html__( 'full profile editor', 'pb-split-guide' ) . '</a>'
                );
                ?>
            </p>

            <!-- Deactivate Section -->
            <div class="pbsg-deactivate-section">
                <h3 class="pbsg-deactivate-title"><?php esc_html_e( 'Deactivate Librarian', 'pb-split-guide' ); ?></h3>
                <p class="pbsg-deactivate-desc">
                    <?php esc_html_e( 'Deactivating removes this user\'s Librarian role and all Guide on the Side permissions. They will no longer be able to create or manage tutorials, view analytics, or access H5P content. Their WordPress account will remain as a Subscriber with no admin panel access.', 'pb-split-guide' ); ?>
                </p>

                <?php if ( ! empty( $reassignment_targets ) ) : ?>
                <p class="pbsg-deactivate-desc">
                    <?php esc_html_e( 'Optionally, you can transfer ownership of their tutorials to another user before deactivating.', 'pb-split-guide' ); ?>
                </p>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="pbsg-deactivate-form" id="pbsg-deactivate-form">
                    <?php wp_nonce_field( 'pbsg_deactivate_librarian' ); ?>
                    <input type="hidden" name="pbsg_librarian_action" value="deactivate" />
                    <input type="hidden" name="user_id" value="<?php echo esc_attr( $edit_id ); ?>" />

                    <?php if ( ! empty( $reassignment_targets ) ) : ?>
                    <div class="pbsg-form-field">
                        <label for="pbsg-reassign"><?php esc_html_e( 'Transfer tutorials to:', 'pb-split-guide' ); ?></label>
                        <select id="pbsg-reassign" name="reassign_to">
                            <option value="0"><?php esc_html_e( '— Keep current ownership —', 'pb-split-guide' ); ?></option>
                            <?php foreach ( $reassignment_targets as $target ) : ?>
                                <option value="<?php echo esc_attr( $target['ID'] ); ?>">
                                    <?php echo esc_html( $target['display_name'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="pbsg-btn pbsg-btn-danger" id="pbsg-deactivate-btn">
                        <?php esc_html_e( 'Deactivate Librarian', 'pb-split-guide' ); ?>
                    </button>
                </form>
            </div>

        </div>

        <?php else : ?>
            <div class="notice notice-error">
                <p><?php esc_html_e( 'Librarian not found or user is not a librarian.', 'pb-split-guide' ); ?></p>
            </div>
        <?php endif; ?>

    <?php endif; ?>

    <!-- Librarian List Table -->
    <div class="pbsg-librarians-card">
        <h2 class="pbsg-card-title">
            <?php esc_html_e( 'Registered Librarians', 'pb-split-guide' ); ?>
            <span class="pbsg-badge"><?php echo count( $librarians ); ?></span>
        </h2>

        <?php if ( empty( $librarians ) ) : ?>
            <div class="pbsg-empty-state">
                <p><?php esc_html_e( 'No librarians registered yet. Click "Register New Librarian" to add one.', 'pb-split-guide' ); ?></p>
            </div>
        <?php else : ?>
            <table class="pbsg-librarians-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Name', 'pb-split-guide' ); ?></th>
                        <th><?php esc_html_e( 'Email', 'pb-split-guide' ); ?></th>
                        <th><?php esc_html_e( 'Tutorials', 'pb-split-guide' ); ?></th>
                        <th><?php esc_html_e( 'Registered', 'pb-split-guide' ); ?></th>
                        <th><?php esc_html_e( 'Last Login', 'pb-split-guide' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'pb-split-guide' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $librarians as $lib ) : ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $lib['display_name'] ); ?></strong>
                            <br />
                            <span class="pbsg-username"><?php echo esc_html( $lib['user_login'] ); ?></span>
                        </td>
                        <td>
                            <a href="mailto:<?php echo esc_attr( $lib['user_email'] ); ?>">
                                <?php echo esc_html( $lib['user_email'] ); ?>
                            </a>
                        </td>
                        <td class="pbsg-count-cell">
                            <?php echo esc_html( $lib['tutorial_count'] ); ?>
                        </td>
                        <td>
                            <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $lib['user_registered'] ) ) ); ?>
                        </td>
                        <td>
                            <?php
                            if ( $lib['last_login'] === __( 'Never', 'pb-split-guide' ) ) {
                                echo '<span class="pbsg-never">' . esc_html( $lib['last_login'] ) . '</span>';
                            } else {
                                echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $lib['last_login'] ) ) );
                            }
                            ?>
                        </td>
                        <td class="pbsg-actions-cell">
                            <a href="<?php echo esc_url( network_admin_url( 'user-edit.php?user_id=' . $lib['ID'] ) ); ?>"
                               class="pbsg-btn pbsg-btn-sm pbsg-btn-secondary">
                                <?php esc_html_e( 'Edit Profile', 'pb-split-guide' ); ?>
                            </a>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PBSG_Librarian_Manager::PAGE_SLUG . '&sub_action=manage&edit_id=' . $lib['ID'] ) ); ?>"
                               class="pbsg-btn pbsg-btn-sm pbsg-btn-outline">
                                <?php esc_html_e( 'Manage', 'pb-split-guide' ); ?>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Deactivated Librarians -->
    <?php if ( ! empty( $deactivated ) ) : ?>
    <div class="pbsg-librarians-card">
        <h2 class="pbsg-card-title">
            <?php esc_html_e( 'Deactivated Librarians', 'pb-split-guide' ); ?>
            <span class="pbsg-badge pbsg-badge--muted"><?php echo count( $deactivated ); ?></span>
        </h2>

        <p class="pbsg-deactivate-desc" style="margin-top:-12px; margin-bottom:16px;">
            <?php esc_html_e( 'Former librarians whose access has been revoked. You can reactivate them to restore full Librarian permissions.', 'pb-split-guide' ); ?>
        </p>

        <table class="pbsg-librarians-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Name', 'pb-split-guide' ); ?></th>
                    <th><?php esc_html_e( 'Email', 'pb-split-guide' ); ?></th>
                    <th><?php esc_html_e( 'Deactivated', 'pb-split-guide' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'pb-split-guide' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $deactivated as $dlib ) : ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html( $dlib['display_name'] ); ?></strong>
                        <br />
                        <span class="pbsg-username"><?php echo esc_html( $dlib['user_login'] ); ?></span>
                    </td>
                    <td>
                        <a href="mailto:<?php echo esc_attr( $dlib['user_email'] ); ?>">
                            <?php echo esc_html( $dlib['user_email'] ); ?>
                        </a>
                    </td>
                    <td>
                        <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $dlib['deactivated_on'] ) ) ); ?>
                    </td>
                    <td class="pbsg-actions-cell">
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:inline;">
                            <?php wp_nonce_field( 'pbsg_reactivate_librarian' ); ?>
                            <input type="hidden" name="pbsg_librarian_action" value="reactivate" />
                            <input type="hidden" name="user_id" value="<?php echo esc_attr( $dlib['ID'] ); ?>" />
                            <button type="submit" class="pbsg-btn pbsg-btn-sm pbsg-btn-primary">
                                <?php esc_html_e( 'Reactivate', 'pb-split-guide' ); ?>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div>
