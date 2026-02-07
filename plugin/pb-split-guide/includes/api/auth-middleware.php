<?php
/**
 * Authentication & Authorization Middleware
 * 
 * Handles permission checks and authentication for API endpoints
 * File: includes/api/auth-middleware.php
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Authentication Middleware Class
 */
class GOTS_Auth_Middleware {

    /**
     * Check if user is librarian (can create tutorials)
     */
    public static function check_librarian_permission() {
        return current_user_can( 'edit_posts' );
    }

    /**
     * Check if user is tutorial author
     */
    public static function check_tutorial_author( $request ) {
        $tutorial_id = (int) $request['id'] ?? (int) $request['tutorial_id'] ?? 0;

        if ( ! $tutorial_id ) {
            return false;
        }

        $tutorial = get_post( $tutorial_id );

        if ( ! $tutorial || $tutorial->post_type !== 'gots_tutorial' ) {
            return false;
        }

        return current_user_can( 'edit_post', $tutorial_id );
    }

    /**
     * Check if user can view tutorial (published or author)
     */
    public static function check_tutorial_access( $request ) {
        $tutorial_id = (int) $request['id'] ?? 0;

        if ( ! $tutorial_id ) {
            return false;
        }

        $tutorial = get_post( $tutorial_id );

        if ( ! $tutorial || $tutorial->post_type !== 'gots_tutorial' ) {
            return false;
        }

        // Allow if published
        if ( $tutorial->post_status === 'publish' ) {
            return true;
        }

        // Allow if user is author
        return current_user_can( 'edit_post', $tutorial_id );
    }

    /**
     * Check if user can manage blocks (must be tutorial author)
     */
    public static function check_block_author( $request ) {
        $block_id = (int) $request['id'] ?? (int) $request['block_id'] ?? 0;

        if ( ! $block_id ) {
            return false;
        }

        $block = get_post( $block_id );

        if ( ! $block || $block->post_type !== 'gots_block' ) {
            return false;
        }

        // Check if user can edit parent tutorial
        return current_user_can( 'edit_post', $block->post_parent );
    }

    /**
     * Validate user authentication
     */
    public static function is_authenticated() {
        return is_user_logged_in();
    }

    /**
     * Get current user role
     */
    public static function get_user_role() {
        if ( ! is_user_logged_in() ) {
            return 'guest';
        }

        $user = wp_get_current_user();
        return $user->roles[0] ?? 'unknown';
    }

    /**
     * Check if user has specific capability
     */
    public static function user_can( $capability ) {
        return current_user_can( $capability );
    }

    /**
     * Log access attempt (for audit trail)
     */
    public static function log_access( $action, $tutorial_id, $result ) {
        $user_id = get_current_user_id();
        $timestamp = current_time( 'mysql' );
        
        // Store in post meta for audit trail
        add_post_meta( $tutorial_id, 'gots_access_log', [
            'user_id'   => $user_id,
            'action'    => sanitize_text_field( $action ),
            'result'    => $result ? 'success' : 'denied',
            'timestamp' => $timestamp,
            'ip_address' => self::get_client_ip(),
        ] );
    }

    /**
     * Get client IP address
     */
    private static function get_client_ip() {
        if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        }

        return sanitize_text_field( $ip );
    }
}

/**
 * RBAC (Role-Based Access Control) Handler
 */
class GOTS_RBAC {

    /**
     * Role Definitions
     */
    private static $roles = [
        'student'    => [
            'view_published_tutorials',
            'view_quiz_questions',
            'submit_quiz_answers',
            'track_own_progress',
        ],
        'librarian'  => [
            'create_tutorials',
            'edit_own_tutorials',
            'view_all_tutorials',
            'manage_blocks',
            'view_statistics',
        ],
        'admin'      => [
            'create_tutorials',
            'edit_all_tutorials',
            'delete_tutorials',
            'manage_users',
            'view_analytics',
            'manage_settings',
        ],
    ];

    /**
     * Check if role has permission
     */
    public static function has_permission( $role, $permission ) {
        if ( ! isset( self::$roles[ $role ] ) ) {
            return false;
        }

        return in_array( $permission, self::$roles[ $role ] );
    }

    /**
     * Get all permissions for role
     */
    public static function get_role_permissions( $role ) {
        return self::$roles[ $role ] ?? [];
    }

    /**
     * Get user's effective role
     */
    public static function get_user_role() {
        $user = wp_get_current_user();

        if ( ! $user->ID ) {
            return 'guest';
        }

        // Map WordPress roles to our roles
        if ( in_array( 'administrator', (array) $user->roles ) ) {
            return 'admin';
        } elseif ( in_array( 'editor', (array) $user->roles ) ) {
            return 'librarian';
        } else {
            return 'student';
        }
    }

    /**
     * Check if user can perform action
     */
    public static function can_perform( $action ) {
        $user_role = self::get_user_role();
        return self::has_permission( $user_role, $action );
    }
}

/**
 * Rate Limiting Handler
 */
class GOTS_Rate_Limiter {

    private static $limit_prefix = 'gots_rate_limit_';
    private static $requests_per_minute = 30;

    /**
     * Check if request is within rate limit
     */
    public static function is_rate_limited( $user_id ) {
        $cache_key = self::$limit_prefix . $user_id;
        $requests = get_transient( $cache_key ) ?: 0;

        if ( $requests >= self::$requests_per_minute ) {
            return true;
        }

        // Increment request counter
        set_transient( $cache_key, $requests + 1, MINUTE_IN_SECONDS );

        return false;
    }

    /**
     * Get remaining requests for user
     */
    public static function get_remaining_requests( $user_id ) {
        $cache_key = self::$limit_prefix . $user_id;
        $requests = get_transient( $cache_key ) ?: 0;

        return max( 0, self::$requests_per_minute - $requests );
    }

    /**
     * Reset rate limit for user
     */
    public static function reset_limit( $user_id ) {
        $cache_key = self::$limit_prefix . $user_id;
        delete_transient( $cache_key );
    }
}

/**
 * Input Validation Handler
 */
class GOTS_Input_Validator {

    /**
     * Validate tutorial data
     */
    public static function validate_tutorial( $data ) {
        $errors = [];

        // Title is required
        if ( empty( $data['post_title'] ) ) {
            $errors[] = __( 'Tutorial title is required', 'gots' );
        }

        // Title length check
        if ( strlen( $data['post_title'] ?? '' ) > 200 ) {
            $errors[] = __( 'Tutorial title must be less than 200 characters', 'gots' );
        }

        // Status must be valid
        if ( isset( $data['post_status'] ) && ! in_array( $data['post_status'], [ 'draft', 'publish' ] ) ) {
            $errors[] = __( 'Invalid post status', 'gots' );
        }

        return $errors;
    }

    /**
     * Validate block data
     */
    public static function validate_block( $data ) {
        $errors = [];

        // Type is required
        if ( empty( $data['type'] ) ) {
            $errors[] = __( 'Block type is required', 'gots' );
        }

        // Page ID is required
        if ( empty( $data['page_id'] ) ) {
            $errors[] = __( 'Page ID is required', 'gots' );
        }

        // Type must be valid
        $valid_types = [ 'text', 'embed', 'quiz', 'image', 'divider' ];
        if ( isset( $data['type'] ) && ! in_array( $data['type'], $valid_types ) ) {
            $errors[] = __( 'Invalid block type', 'gots' );
        }

        return $errors;
    }

    /**
     * Validate quiz answer data
     */
    public static function validate_answer( $data ) {
        $errors = [];

        if ( ! isset( $data['answer'] ) ) {
            $errors[] = __( 'Answer is required', 'gots' );
        }

        return $errors;
    }

    /**
     * Sanitize string for database
     */
    public static function sanitize_string( $value ) {
        return sanitize_text_field( $value );
    }

    /**
     * Sanitize HTML for database
     */
    public static function sanitize_html( $value ) {
        return wp_kses_post( $value );
    }

    /**
     * Sanitize URL
     */
    public static function sanitize_url( $value ) {
        return esc_url( $value );
    }
}

?>
