<?php
/**
 * REST API Endpoints Controller
 * 
 * Handles API endpoint logic and request/response processing
 * File: includes/api/endpoints.php
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Tutorial Endpoints Controller
 */
class GOTS_Tutorial_Endpoints {

    public static function create_tutorial( $request ) {
        $params = $request->get_json_params();
        
        // Check permission
        if ( ! current_user_can( 'edit_posts' ) ) {
            return rest_ensure_response( [
                'code'    => 'rest_forbidden',
                'message' => __( 'You do not have permission to create tutorials', 'gots' ),
            ], 403 );
        }

        $tutorial = new Tutorial();
        $result = $tutorial->create( $params );

        if ( is_wp_error( $result ) ) {
            return rest_ensure_response( [
                'code'    => 'tutorial_error',
                'message' => $result->get_error_message(),
            ], 400 );
        }

        return rest_ensure_response( [
            'success'     => true,
            'tutorial_id' => $result,
            'message'     => __( 'Tutorial created successfully', 'gots' ),
        ] );
    }

    public static function get_tutorial( $request ) {
        $tutorial_id = (int) $request['id'];
        
        $tutorial = new Tutorial();
        $data = $tutorial->get( $tutorial_id );

        if ( ! $data ) {
            return rest_ensure_response( [
                'code'    => 'not_found',
                'message' => __( 'Tutorial not found', 'gots' ),
            ], 404 );
        }

        // Check if tutorial is published or user is author
        $post = get_post( $tutorial_id );
        if ( $post->post_status !== 'publish' && ! current_user_can( 'edit_post', $tutorial_id ) ) {
            return rest_ensure_response( [
                'code'    => 'rest_forbidden',
                'message' => __( 'You do not have permission to view this tutorial', 'gots' ),
            ], 403 );
        }

        return rest_ensure_response( $data );
    }

    public static function update_tutorial( $request ) {
        $tutorial_id = (int) $request['id'];
        $params = $request->get_json_params();

        // Check permission
        if ( ! current_user_can( 'edit_post', $tutorial_id ) ) {
            return rest_ensure_response( [
                'code'    => 'rest_forbidden',
                'message' => __( 'You do not have permission to edit this tutorial', 'gots' ),
            ], 403 );
        }

        $tutorial = new Tutorial();
        $result = $tutorial->update( $tutorial_id, $params );

        if ( is_wp_error( $result ) ) {
            return rest_ensure_response( [
                'code'    => 'tutorial_error',
                'message' => $result->get_error_message(),
            ], 400 );
        }

        return rest_ensure_response( [
            'success' => true,
            'message' => __( 'Tutorial updated successfully', 'gots' ),
        ] );
    }
}

/**
 * Block Endpoints Controller
 */
class GOTS_Block_Endpoints {

    public static function add_block( $request ) {
        $tutorial_id = (int) $request['tutorial_id'];
        $params = $request->get_json_params();

        // Check permission
        if ( ! current_user_can( 'edit_post', $tutorial_id ) ) {
            return rest_ensure_response( [
                'code'    => 'rest_forbidden',
                'message' => __( 'You do not have permission to add blocks to this tutorial', 'gots' ),
            ], 403 );
        }

        $block = new Block();
        $result = $block->create( $tutorial_id, $params );

        if ( is_wp_error( $result ) ) {
            return rest_ensure_response( [
                'code'    => 'block_error',
                'message' => $result->get_error_message(),
            ], 400 );
        }

        return rest_ensure_response( [
            'success'  => true,
            'block_id' => $result,
            'message'  => __( 'Block added successfully', 'gots' ),
        ] );
    }

    public static function update_block( $request ) {
        $block_id = (int) $request['id'];
        $params = $request->get_json_params();

        $block_post = get_post( $block_id );
        if ( ! $block_post || $block_post->post_type !== 'gots_block' ) {
            return rest_ensure_response( [
                'code'    => 'not_found',
                'message' => __( 'Block not found', 'gots' ),
            ], 404 );
        }

        // Check permission on parent tutorial
        if ( ! current_user_can( 'edit_post', $block_post->post_parent ) ) {
            return rest_ensure_response( [
                'code'    => 'rest_forbidden',
                'message' => __( 'You do not have permission to edit this block', 'gots' ),
            ], 403 );
        }

        $block = new Block();
        $result = $block->update( $block_id, $params );

        if ( is_wp_error( $result ) ) {
            return rest_ensure_response( [
                'code'    => 'block_error',
                'message' => $result->get_error_message(),
            ], 400 );
        }

        return rest_ensure_response( [
            'success' => true,
            'message' => __( 'Block updated successfully', 'gots' ),
        ] );
    }

    public static function delete_block( $request ) {
        $block_id = (int) $request['id'];

        $block_post = get_post( $block_id );
        if ( ! $block_post || $block_post->post_type !== 'gots_block' ) {
            return rest_ensure_response( [
                'code'    => 'not_found',
                'message' => __( 'Block not found', 'gots' ),
            ], 404 );
        }

        // Check permission
        if ( ! current_user_can( 'delete_post', $block_id ) ) {
            return rest_ensure_response( [
                'code'    => 'rest_forbidden',
                'message' => __( 'You do not have permission to delete this block', 'gots' ),
            ], 403 );
        }

        $block = new Block();
        $block->delete( $block_id );

        return rest_ensure_response( [
            'success' => true,
            'message' => __( 'Block deleted successfully', 'gots' ),
        ] );
    }
}

/**
 * Quiz Endpoints Controller
 */
class GOTS_Quiz_Endpoints {

    public static function validate_answer( $request ) {
        $block_id = (int) $request['id'];
        $params = $request->get_json_params();

        $quiz = new Quiz();
        $result = $quiz->validate_answer( $block_id, $params );

        if ( isset( $result['code'] ) && 'not_found' === $result['code'] ) {
            return rest_ensure_response( $result, 404 );
        }

        // Track attempt for analytics
        $quiz->track_attempt( $block_id, $result['is_correct'] );

        return rest_ensure_response( $result );
    }

    public static function get_question( $request ) {
        $block_id = (int) $request['id'];

        $quiz = new Quiz();
        $question = $quiz->get_question( $block_id );

        if ( ! $question ) {
            return rest_ensure_response( [
                'code'    => 'not_found',
                'message' => __( 'Question not found', 'gots' ),
            ], 404 );
        }

        return rest_ensure_response( $question );
    }

    public static function get_question_stats( $request ) {
        $block_id = (int) $request['id'];

        $quiz = new Quiz();
        $stats = $quiz->get_question_stats( $block_id );

        if ( ! $stats ) {
            return rest_ensure_response( [
                'code'    => 'not_found',
                'message' => __( 'Question not found', 'gots' ),
            ], 404 );
        }

        return rest_ensure_response( $stats );
    }
}

/**
 * Progress Endpoints Controller
 */
class GOTS_Progress_Endpoints {

    public static function save_progress( $request ) {
        $params = $request->get_json_params();

        // Generate session key for progress tracking
        $session_key = 'gots_progress_' . wp_generate_uuid4();
        
        // Store progress in transients (expires in 1 hour)
        $progress_data = [
            'tutorial_id'         => (int) ( $params['tutorial_id'] ?? 0 ),
            'current_page'        => sanitize_text_field( $params['current_page'] ?? '' ),
            'current_block'       => (int) ( $params['current_block'] ?? 0 ),
            'questions_answered'  => (int) ( $params['questions_answered'] ?? 0 ),
            'correct_answers'     => (int) ( $params['correct_answers'] ?? 0 ),
            'timestamp'           => current_time( 'mysql' ),
        ];

        set_transient( $session_key, $progress_data, HOUR_IN_SECONDS );

        return rest_ensure_response( [
            'success'     => true,
            'session_key' => $session_key,
            'message'     => __( 'Progress saved', 'gots' ),
        ] );
    }

    public static function get_progress( $request ) {
        $session_key = sanitize_text_field( $request['session_key'] ?? '' );

        if ( ! $session_key ) {
            return rest_ensure_response( [
                'code'    => 'missing_key',
                'message' => __( 'Session key required', 'gots' ),
            ], 400 );
        }

        $progress = get_transient( $session_key );

        if ( ! $progress ) {
            return rest_ensure_response( [
                'code'    => 'not_found',
                'message' => __( 'Progress not found or expired', 'gots' ),
            ], 404 );
        }

        return rest_ensure_response( $progress );
    }
}

/**
 * Autosave Endpoints Controller
 */
class GOTS_Autosave_Endpoints {

    public static function autosave_tutorial( $request ) {
        $tutorial_id = (int) $request['id'];
        $params = $request->get_json_params();

        // Check permission
        if ( ! current_user_can( 'edit_post', $tutorial_id ) ) {
            return rest_ensure_response( [
                'code'    => 'rest_forbidden',
                'message' => __( 'You do not have permission to autosave this tutorial', 'gots' ),
            ], 403 );
        }

        $tutorial = new Tutorial();
        $result = $tutorial->autosave( $tutorial_id, $params );

        if ( is_wp_error( $result ) ) {
            return rest_ensure_response( [
                'code'    => 'autosave_error',
                'message' => $result->get_error_message(),
            ], 400 );
        }

        return rest_ensure_response( [
            'success'  => true,
            'message'  => __( 'Autosaved', 'gots' ),
            'timestamp' => current_time( 'mysql' ),
        ] );
    }
}

?>
