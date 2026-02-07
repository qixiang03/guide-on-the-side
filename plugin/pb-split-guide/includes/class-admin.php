<?php
/**
 * Plugin Name: GOTS Admin
 * Plugin URI: https://github.com/qixiang03/guide-on-the-side
 * Description: Admin interface for creating tutorials
 * Version: 0.5.0
 * Author: Team 8
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin Class
 * 
 * Handles admin interface for creating and managing tutorials
 */
class GOTS_Admin {

    /**
     * Initialize admin hooks
     */
    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'add_tutorial_meta_boxes' ] );
        add_action( 'save_post_gots_tutorial', [ $this, 'save_tutorial_meta' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
        add_action( 'wp_ajax_gots_add_block', [ $this, 'ajax_add_block' ] );
        add_action( 'wp_ajax_gots_delete_block', [ $this, 'ajax_delete_block' ] );
        add_action( 'wp_ajax_gots_reorder_blocks', [ $this, 'ajax_reorder_blocks' ] );
        add_action( 'wp_ajax_gots_get_block', [ $this, 'ajax_get_block' ] );
        add_action( 'wp_ajax_gots_update_block', [ $this, 'ajax_update_block' ] );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts( $hook ) {
        global $post;
        
        if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) {
            return;
        }
        
        if ( ! $post || $post->post_type !== 'gots_tutorial' ) {
            return;
        }

        wp_enqueue_style( 
            'gots-admin', 
            GOTS_PLUGIN_URL . 'assets/css/admin.css', 
            [], 
            GOTS_VERSION 
        );
        
        wp_enqueue_script( 
            'gots-admin', 
            GOTS_PLUGIN_URL . 'assets/js/admin.js', 
            [ 'jquery', 'jquery-ui-sortable' ], 
            GOTS_VERSION, 
            true 
        );

        wp_localize_script( 'gots-admin', 'gotsAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'gots_admin_nonce' ),
            'postId'  => $post->ID,
        ] );
    }

    /**
     * Add meta boxes to tutorial edit screen
     */
    public function add_tutorial_meta_boxes() {
        add_meta_box(
            'gots_blocks_meta_box',
            __( 'Tutorial Blocks (Questions & Content)', 'gots' ),
            [ $this, 'render_blocks_meta_box' ],
            'gots_tutorial',
            'normal',
            'high'
        );

        add_meta_box(
            'gots_settings_meta_box',
            __( 'Tutorial Settings', 'gots' ),
            [ $this, 'render_settings_meta_box' ],
            'gots_tutorial',
            'side',
            'default'
        );
    }

    /**
     * Render blocks meta box
     */
    public function render_blocks_meta_box( $post ) {
        wp_nonce_field( 'gots_save_blocks', 'gots_blocks_nonce' );
        
        // Get existing blocks
        $blocks = $this->get_tutorial_blocks( $post->ID );
        ?>
        <div class="gots-blocks-container">
            
            <div class="gots-block-list" id="gots-block-list">
                <?php if ( ! empty( $blocks ) ) : ?>
                    <?php foreach ( $blocks as $index => $block ) : ?>
                        <?php $this->render_block_item( $block, $index ); ?>
                    <?php endforeach; ?>
                <?php else : ?>
                    <p class="gots-no-blocks">No blocks yet. Add your first block below.</p>
                <?php endif; ?>
            </div>

            <div class="gots-add-block-section">
                <h4><?php _e( 'Add New Block', 'gots' ); ?></h4>
                
                <div class="gots-block-form" id="gots-add-block-form">
                    
                    <div class="gots-form-row">
                        <label><?php _e( 'Block Type', 'gots' ); ?></label>
                        <select id="gots-block-type" name="block_type">
                            <option value="quiz"><?php _e( 'Quiz Question (MCQ)', 'gots' ); ?></option>
                            <option value="text"><?php _e( 'Text/Instructions', 'gots' ); ?></option>
                        </select>
                    </div>

                    <div class="gots-form-row">
                        <label><?php _e( 'Title', 'gots' ); ?></label>
                        <input type="text" id="gots-block-title" name="block_title" placeholder="e.g., Question 1: Finding Articles">
                    </div>

                    <div class="gots-form-row">
                        <label><?php _e( 'Embed URL (Right Pane)', 'gots' ); ?></label>
                        <input type="url" id="gots-embed-url" name="embed_url" placeholder="https://library.upei.ca/databases">
                        <p class="description"><?php _e( 'URL to display in the right pane iframe', 'gots' ); ?></p>
                    </div>

                    <!-- Quiz-specific fields -->
                    <div class="gots-quiz-fields">
                        <div class="gots-form-row">
                            <label><?php _e( 'Question Text', 'gots' ); ?></label>
                            <textarea id="gots-question-text" name="question_text" rows="3" placeholder="What is the name of the database shown?"></textarea>
                        </div>

                        <div class="gots-form-row">
                            <label><?php _e( 'Answer Options (one per line)', 'gots' ); ?></label>
                            <textarea id="gots-options" name="options" rows="4" placeholder="OneSearch&#10;EBSCO&#10;ProQuest&#10;Google Scholar"></textarea>
                        </div>

                        <div class="gots-form-row">
                            <label><?php _e( 'Correct Answer (0-based index)', 'gots' ); ?></label>
                            <input type="number" id="gots-correct-answer" name="correct_answer" value="0" min="0">
                            <p class="description"><?php _e( '0 = first option, 1 = second option, etc.', 'gots' ); ?></p>
                        </div>

                        <div class="gots-form-row">
                            <label><?php _e( 'Feedback - Correct', 'gots' ); ?></label>
                            <input type="text" id="gots-feedback-correct" name="feedback_correct" placeholder="Correct! OneSearch is our primary discovery tool.">
                        </div>

                        <div class="gots-form-row">
                            <label><?php _e( 'Feedback - Incorrect', 'gots' ); ?></label>
                            <input type="text" id="gots-feedback-incorrect" name="feedback_incorrect" placeholder="Not quite. Look at the logo in the top left corner.">
                        </div>
                    </div>

                    <!-- Text-specific fields -->
                    <div class="gots-text-fields" style="display: none;">
                        <div class="gots-form-row">
                            <label><?php _e( 'Content', 'gots' ); ?></label>
                            <textarea id="gots-text-content" name="text_content" rows="5" placeholder="Enter instructions or information here..."></textarea>
                        </div>
                    </div>

                    <button type="button" class="button button-primary" id="gots-add-block-btn">
                        <?php _e( 'Add Block', 'gots' ); ?>
                    </button>
                </div>
            </div>

        </div>
        <?php
    }

    /**
     * Render individual block item in admin
     */
    private function render_block_item( $block, $index ) {
        $block_type = $block['type'];
        $embed_url = get_post_meta( $block['id'], 'gots_embed_url', true );
        ?>
        <div class="gots-block-item" data-block-id="<?php echo esc_attr( $block['id'] ); ?>">
            <div class="gots-block-header">
                <span class="gots-block-handle dashicons dashicons-move"></span>
                <span class="gots-block-type"><?php echo esc_html( strtoupper( $block_type ) ); ?></span>
                <span class="gots-block-title"><?php echo esc_html( $block['title'] ); ?></span>
                <div class="gots-block-actions">
                    <button type="button" class="gots-edit-block button-link" data-block-id="<?php echo esc_attr( $block['id'] ); ?>">
                        <?php _e( 'Edit', 'gots' ); ?>
                    </button>
                    <button type="button" class="gots-delete-block button-link-delete" data-block-id="<?php echo esc_attr( $block['id'] ); ?>">
                        <?php _e( 'Delete', 'gots' ); ?>
                    </button>
                </div>
            </div>
            <div class="gots-block-details">
                <?php if ( $embed_url ) : ?>
                    <p><strong><?php _e( 'Embed URL:', 'gots' ); ?></strong> <?php echo esc_url( $embed_url ); ?></p>
                <?php endif; ?>
                <?php if ( $block_type === 'quiz' && ! empty( $block['question_data'] ) ) : ?>
                    <p><strong><?php _e( 'Question:', 'gots' ); ?></strong> <?php echo esc_html( $block['question_data']['question_text'] ?? '' ); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render settings meta box
     */
    public function render_settings_meta_box( $post ) {
        $metadata = get_post_meta( $post->ID, 'gots_metadata', true ) ?: [];
        ?>
        <div class="gots-settings">
            <p>
                <label>
                    <input type="checkbox" name="gots_enable_progress" value="1" 
                        <?php checked( $metadata['enable_progress_bar'] ?? true ); ?>>
                    <?php _e( 'Show progress bar', 'gots' ); ?>
                </label>
            </p>
            <p>
                <label><?php _e( 'Color Scheme', 'gots' ); ?></label><br>
                <select name="gots_color_scheme">
                    <option value="light" <?php selected( $metadata['color_scheme'] ?? 'light', 'light' ); ?>><?php _e( 'Light', 'gots' ); ?></option>
                    <option value="dark" <?php selected( $metadata['color_scheme'] ?? 'light', 'dark' ); ?>><?php _e( 'Dark', 'gots' ); ?></option>
                </select>
            </p>
            <hr>
            <p>
                <strong><?php _e( 'Shortcode:', 'gots' ); ?></strong><br>
                <code>[gots_tutorial id="<?php echo $post->ID; ?>"]</code>
            </p>
            <p>
                <strong><?php _e( 'Direct URL:', 'gots' ); ?></strong><br>
                <a href="<?php echo get_permalink( $post->ID ); ?>" target="_blank">
                    <?php _e( 'View Tutorial', 'gots' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Save tutorial meta
     */
    public function save_tutorial_meta( $post_id ) {
        if ( ! isset( $_POST['gots_blocks_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( $_POST['gots_blocks_nonce'], 'gots_save_blocks' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Save settings
        $metadata = get_post_meta( $post_id, 'gots_metadata', true ) ?: [];
        $metadata['enable_progress_bar'] = isset( $_POST['gots_enable_progress'] );
        $metadata['color_scheme'] = sanitize_text_field( $_POST['gots_color_scheme'] ?? 'light' );
        update_post_meta( $post_id, 'gots_metadata', $metadata );
    }

    /**
     * AJAX: Add new block
     */
    public function ajax_add_block() {
        check_ajax_referer( 'gots_admin_nonce', 'nonce' );

        $post_id = (int) $_POST['post_id'];
        $block_type = sanitize_text_field( $_POST['block_type'] );
        $title = sanitize_text_field( $_POST['title'] );
        $embed_url = esc_url_raw( $_POST['embed_url'] );

        // Create block post
        $block_id = wp_insert_post( [
            'post_type'   => 'gots_block',
            'post_title'  => $title,
            'post_parent' => $post_id,
            'post_status' => 'publish',
        ] );

        if ( is_wp_error( $block_id ) ) {
            wp_send_json_error( [ 'message' => $block_id->get_error_message() ] );
        }

        // Get sequence number
        $existing_blocks = get_posts( [
            'post_parent' => $post_id,
            'post_type'   => 'gots_block',
            'numberposts' => -1,
        ] );
        $sequence = count( $existing_blocks );

        // Save block metadata
        add_post_meta( $block_id, 'gots_block_type', $block_type );
        add_post_meta( $block_id, 'gots_page_id', 'page_1' );
        add_post_meta( $block_id, 'gots_sequence', $sequence );
        add_post_meta( $block_id, 'gots_embed_url', $embed_url );

        // Save type-specific data
        if ( $block_type === 'quiz' ) {
            $options_raw = sanitize_textarea_field( $_POST['options'] ?? '' );
            $options = array_filter( array_map( 'trim', explode( "\n", $options_raw ) ) );

            $question_data = [
                'type'               => 'multiple_choice',
                'question_text'      => wp_kses_post( $_POST['question_text'] ?? '' ),
                'options'            => $options,
                'correct_answer'     => (int) ( $_POST['correct_answer'] ?? 0 ),
                'feedback_correct'   => sanitize_text_field( $_POST['feedback_correct'] ?? 'Correct!' ),
                'feedback_incorrect' => sanitize_text_field( $_POST['feedback_incorrect'] ?? 'Incorrect. Try again.' ),
                'max_attempts'       => 3,
            ];
            add_post_meta( $block_id, 'gots_quiz_data', $question_data );
        } elseif ( $block_type === 'text' ) {
            add_post_meta( $block_id, 'gots_text_content', wp_kses_post( $_POST['text_content'] ?? '' ) );
        }

        wp_send_json_success( [
            'block_id' => $block_id,
            'message'  => __( 'Block added successfully', 'gots' ),
            'html'     => $this->get_block_item_html( $block_id ),
        ] );
    }

    /**
     * Get block item HTML for AJAX response
     */
    private function get_block_item_html( $block_id ) {
        $block = [
            'id'    => $block_id,
            'type'  => get_post_meta( $block_id, 'gots_block_type', true ),
            'title' => get_the_title( $block_id ),
            'question_data' => get_post_meta( $block_id, 'gots_quiz_data', true ),
        ];

        ob_start();
        $this->render_block_item( $block, 0 );
        return ob_get_clean();
    }

    /**
     * AJAX: Delete block
     */
    public function ajax_delete_block() {
        check_ajax_referer( 'gots_admin_nonce', 'nonce' );

        $block_id = (int) $_POST['block_id'];
        
        $block = get_post( $block_id );
        if ( ! $block || $block->post_type !== 'gots_block' ) {
            wp_send_json_error( [ 'message' => __( 'Block not found', 'gots' ) ] );
        }

        wp_delete_post( $block_id, true );

        wp_send_json_success( [ 'message' => __( 'Block deleted', 'gots' ) ] );
    }

    /**
     * AJAX: Reorder blocks
     */
    public function ajax_reorder_blocks() {
        check_ajax_referer( 'gots_admin_nonce', 'nonce' );

        $block_ids = array_map( 'intval', $_POST['block_ids'] ?? [] );

        foreach ( $block_ids as $sequence => $block_id ) {
            update_post_meta( $block_id, 'gots_sequence', $sequence );
        }

        wp_send_json_success( [ 'message' => __( 'Blocks reordered', 'gots' ) ] );
    }

    /**
     * AJAX: Get block data for editing
     */
    public function ajax_get_block() {
        check_ajax_referer( 'gots_admin_nonce', 'nonce' );

        $block_id = (int) $_POST['block_id'];
        
        $block = get_post( $block_id );
        if ( ! $block || $block->post_type !== 'gots_block' ) {
            wp_send_json_error( [ 'message' => __( 'Block not found', 'gots' ) ] );
        }

        $block_type = get_post_meta( $block_id, 'gots_block_type', true );
        
        $data = [
            'id'        => $block_id,
            'type'      => $block_type,
            'title'     => $block->post_title,
            'embed_url' => get_post_meta( $block_id, 'gots_embed_url', true ),
        ];

        // Add type-specific data
        if ( $block_type === 'quiz' ) {
            $data['question_data'] = get_post_meta( $block_id, 'gots_quiz_data', true );
        } elseif ( $block_type === 'text' ) {
            $data['text_content'] = get_post_meta( $block_id, 'gots_text_content', true );
        }

        wp_send_json_success( $data );
    }

    /**
     * AJAX: Update block
     */
    public function ajax_update_block() {
        check_ajax_referer( 'gots_admin_nonce', 'nonce' );

        $block_id = (int) $_POST['block_id'];
        $block_type = sanitize_text_field( $_POST['block_type'] );
        $title = sanitize_text_field( $_POST['title'] );
        $embed_url = esc_url_raw( $_POST['embed_url'] );

        $block = get_post( $block_id );
        if ( ! $block || $block->post_type !== 'gots_block' ) {
            wp_send_json_error( [ 'message' => __( 'Block not found', 'gots' ) ] );
        }

        // Update post title
        wp_update_post( [
            'ID'         => $block_id,
            'post_title' => $title,
        ] );

        // Update metadata
        update_post_meta( $block_id, 'gots_block_type', $block_type );
        update_post_meta( $block_id, 'gots_embed_url', $embed_url );

        // Update type-specific data
        if ( $block_type === 'quiz' ) {
            $options_raw = sanitize_textarea_field( $_POST['options'] ?? '' );
            $options = array_filter( array_map( 'trim', explode( "\n", $options_raw ) ) );

            $question_data = [
                'type'               => 'multiple_choice',
                'question_text'      => wp_kses_post( $_POST['question_text'] ?? '' ),
                'options'            => array_values( $options ),
                'correct_answer'     => (int) ( $_POST['correct_answer'] ?? 0 ),
                'feedback_correct'   => sanitize_text_field( $_POST['feedback_correct'] ?? 'Correct!' ),
                'feedback_incorrect' => sanitize_text_field( $_POST['feedback_incorrect'] ?? 'Incorrect. Try again.' ),
                'max_attempts'       => 3,
            ];
            update_post_meta( $block_id, 'gots_quiz_data', $question_data );
            
            // Clean up text content if switching from text to quiz
            delete_post_meta( $block_id, 'gots_text_content' );
            
        } elseif ( $block_type === 'text' ) {
            update_post_meta( $block_id, 'gots_text_content', wp_kses_post( $_POST['text_content'] ?? '' ) );
            
            // Clean up quiz data if switching from quiz to text
            delete_post_meta( $block_id, 'gots_quiz_data' );
        }

        wp_send_json_success( [
            'block_id' => $block_id,
            'message'  => __( 'Block updated successfully', 'gots' ),
            'html'     => $this->get_block_item_html( $block_id ),
        ] );
    }

    /**
     * Get all blocks for a tutorial
     */
    private function get_tutorial_blocks( $tutorial_id ) {
        $args = [
            'post_parent' => $tutorial_id,
            'post_type'   => 'gots_block',
            'numberposts' => -1,
            'orderby'     => 'meta_value_num',
            'meta_key'    => 'gots_sequence',
            'order'       => 'ASC',
        ];

        $posts = get_posts( $args );
        $blocks = [];

        foreach ( $posts as $post ) {
            $blocks[] = [
                'id'            => $post->ID,
                'type'          => get_post_meta( $post->ID, 'gots_block_type', true ),
                'title'         => $post->post_title,
                'question_data' => get_post_meta( $post->ID, 'gots_quiz_data', true ),
            ];
        }

        return $blocks;
    }
}

// Initialize admin
new GOTS_Admin();
