<?php
/**
 * Quiz Class
 * 
 * Handles quiz question validation and answer checking
 */

class Quiz {

    /**
     * Validate a quiz answer
     */
    public function validate_answer( $block_id, $data ) {
        $block = get_post( $block_id );

        if ( ! $block || $block->post_type !== 'gots_block' ) {
            return [
                'is_correct' => false,
                'message'    => __( 'Block not found', 'gots' ),
            ];
        }

        $question_data = get_post_meta( $block_id, 'gots_quiz_data', true );

        if ( ! $question_data ) {
            return [
                'is_correct' => false,
                'message'    => __( 'Question data not found', 'gots' ),
            ];
        }

        $user_answer = $data['answer'] ?? null;
        $question_type = $question_data['type'] ?? null;

        // Route to appropriate validation method
        switch ( $question_type ) {
            case 'multiple_choice':
                return $this->validate_multiple_choice( $user_answer, $question_data );
            case 'yes_no':
                return $this->validate_yes_no( $user_answer, $question_data );
            case 'checkbox':
                return $this->validate_checkbox( $user_answer, $question_data );
            case 'text_input':
                return $this->validate_text_input( $user_answer, $question_data );
            default:
                return [
                    'is_correct' => false,
                    'message'    => __( 'Unknown question type', 'gots' ),
                ];
        }
    }

    /**
     * Validate multiple choice answer
     */
    private function validate_multiple_choice( $user_answer, $question_data ) {
        $correct_answer = $question_data['correct_answer'] ?? null;
        $is_correct = (int) $user_answer === (int) $correct_answer;

        return [
            'is_correct'       => $is_correct,
            'feedback'         => $is_correct 
                ? ( $question_data['feedback_correct'] ?? __( 'Correct!', 'gots' ) )
                : ( $question_data['feedback_incorrect'] ?? __( 'Incorrect. Try again.', 'gots' ) ),
            'correct_answer'   => $correct_answer,
            'user_answer'      => $user_answer,
        ];
    }

    /**
     * Validate yes/no answer
     */
    private function validate_yes_no( $user_answer, $question_data ) {
        $correct_answer = $question_data['correct_answer'] ?? null;
        $user_answer = strtolower( $user_answer ) === 'yes' ? 1 : 0;
        $correct_answer = $correct_answer ? 1 : 0;

        $is_correct = $user_answer === $correct_answer;

        return [
            'is_correct'       => $is_correct,
            'feedback'         => $is_correct 
                ? ( $question_data['feedback_correct'] ?? __( 'Correct!', 'gots' ) )
                : ( $question_data['feedback_incorrect'] ?? __( 'Incorrect. Try again.', 'gots' ) ),
            'correct_answer'   => $correct_answer ? 'yes' : 'no',
            'user_answer'      => $user_answer ? 'yes' : 'no',
        ];
    }

    /**
     * Validate checkbox answer (multiple selections)
     */
    private function validate_checkbox( $user_answers, $question_data ) {
        $correct_answers = $question_data['correct_answers'] ?? [];

        // Ensure arrays
        $user_answers = is_array( $user_answers ) ? $user_answers : (array) $user_answers;
        $correct_answers = array_map( 'intval', (array) $correct_answers );
        $user_answers = array_map( 'intval', $user_answers );

        // Sort for comparison
        sort( $user_answers );
        sort( $correct_answers );

        $is_correct = $user_answers === $correct_answers;

        return [
            'is_correct'       => $is_correct,
            'feedback'         => $is_correct 
                ? ( $question_data['feedback_correct'] ?? __( 'Correct!', 'gots' ) )
                : ( $question_data['feedback_incorrect'] ?? __( 'Incorrect. Try again.', 'gots' ) ),
            'correct_answers'  => $correct_answers,
            'user_answers'     => $user_answers,
        ];
    }

    /**
     * Validate text input answer
     */
    private function validate_text_input( $user_answer, $question_data ) {
        $correct_answers = (array) ( $question_data['correct_answers'] ?? [] );
        $case_sensitive = (bool) ( $question_data['case_sensitive'] ?? false );

        $user_answer = sanitize_text_field( $user_answer );

        if ( ! $case_sensitive ) {
            $user_answer = strtolower( $user_answer );
            $correct_answers = array_map( 'strtolower', $correct_answers );
        }

        $is_correct = in_array( $user_answer, $correct_answers, true );

        return [
            'is_correct'       => $is_correct,
            'feedback'         => $is_correct 
                ? ( $question_data['feedback_correct'] ?? __( 'Correct!', 'gots' ) )
                : ( $question_data['feedback_incorrect'] ?? __( 'Incorrect. Try again.', 'gots' ) ),
            'correct_answers'  => $question_data['show_answer'] ?? false ? $correct_answers : [],
            'user_answer'      => $user_answer,
        ];
    }

    /**
     * Get question data
     */
    public function get_question( $block_id ) {
        return get_post_meta( $block_id, 'gots_quiz_data', true );
    }

    /**
     * Save question data
     */
    public function save_question( $block_id, $question_data ) {
        return update_post_meta( $block_id, 'gots_quiz_data', $question_data );
    }

    /**
     * Create a new quiz block
     */
    public function create_quiz_block( $tutorial_id, $question_data ) {
        $block = wp_insert_post( [
            'post_type'   => 'gots_block',
            'post_title'  => $question_data['question_text'] ?? 'Question',
            'post_status' => 'publish',
            'post_parent' => $tutorial_id,
        ] );

        if ( is_wp_error( $block ) ) {
            return $block;
        }

        // Save question metadata
        add_post_meta( $block, 'gots_block_type', 'quiz' );
        add_post_meta( $block, 'gots_quiz_data', $question_data );

        return $block;
    }

    /**
     * Get all questions for a tutorial
     */
    public function get_tutorial_questions( $tutorial_id ) {
        $args = [
            'post_parent' => $tutorial_id,
            'post_type'   => 'gots_block',
            'meta_key'    => 'gots_block_type',
            'meta_value'  => 'quiz',
        ];

        $blocks = get_posts( $args );
        $questions = [];

        foreach ( $blocks as $block ) {
            $question_data = get_post_meta( $block->ID, 'gots_quiz_data', true );
            $questions[] = array_merge( [ 'block_id' => $block->ID ], $question_data );
        }

        return $questions;
    }

    /**
     * Get attempt statistics
     */
    public function get_question_stats( $block_id ) {
        $total_attempts = (int) get_post_meta( $block_id, 'gots_attempts', true ) ?: 0;
        $correct_attempts = (int) get_post_meta( $block_id, 'gots_correct', true ) ?: 0;

        return [
            'total_attempts'     => $total_attempts,
            'correct_attempts'   => $correct_attempts,
            'accuracy_percentage' => $total_attempts > 0 ? round( ( $correct_attempts / $total_attempts ) * 100, 2 ) : 0,
        ];
    }

    /**
     * Track attempt
     */
    public function track_attempt( $block_id, $is_correct ) {
        $total_attempts = (int) get_post_meta( $block_id, 'gots_attempts', true ) ?: 0;
        update_post_meta( $block_id, 'gots_attempts', ++$total_attempts );

        if ( $is_correct ) {
            $correct_attempts = (int) get_post_meta( $block_id, 'gots_correct', true ) ?: 0;
            update_post_meta( $block_id, 'gots_correct', ++$correct_attempts );
        }
    }
}

?>