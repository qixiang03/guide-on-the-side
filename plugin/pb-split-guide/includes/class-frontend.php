<?php
/**
 * Plugin Name: GOTS Frontend
 * Plugin URI: https://github.com/qixiang03/guide-on-the-side
 * Description: Frontend rendering for two-pane tutorials
 * Version: 0.5.0
 * Author: Team 8
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Frontend Class
 * 
 * Handles rendering of two-pane tutorial interface
 */
class GOTS_Frontend {

    /**
     * Initialize frontend hooks
     */
    public function __construct() {
        add_shortcode( 'gots_tutorial', [ $this, 'render_tutorial_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
        add_filter( 'single_template', [ $this, 'load_tutorial_template' ] );
        add_filter( 'the_content', [ $this, 'render_tutorial_content' ], 20 );
    }

    /**
     * Render tutorial content via the_content filter
     */
    public function render_tutorial_content( $content ) {
        if ( ! is_singular( 'gots_tutorial' ) || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }
        
        global $post;
        return $this->render_tutorial( $post->ID );
    }

    /**
     * Enqueue frontend CSS and JS
     */
    public function enqueue_frontend_assets() {
        if ( is_singular( 'gots_tutorial' ) || has_shortcode( get_post()->post_content ?? '', 'gots_tutorial' ) ) {
            wp_enqueue_style( 
                'gots-frontend', 
                GOTS_PLUGIN_URL . 'assets/css/frontend.css', 
                [], 
                GOTS_VERSION 
            );
            wp_enqueue_script( 
                'gots-frontend', 
                GOTS_PLUGIN_URL . 'assets/js/frontend.js', 
                [ 'jquery' ], 
                GOTS_VERSION, 
                true 
            );
            wp_localize_script( 'gots-frontend', 'gotsData', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'restUrl' => rest_url( 'gots/v1/' ),
                'nonce'   => wp_create_nonce( 'wp_rest' ),
            ] );
        }
    }

    /**
     * Load custom template for tutorial post type
     */
    public function load_tutorial_template( $template ) {
        if ( is_singular( 'gots_tutorial' ) ) {
            $custom_template = GOTS_PLUGIN_DIR . 'templates/single-tutorial.php';
            if ( file_exists( $custom_template ) ) {
                return $custom_template;
            }
        }
        return $template;
    }

    /**
     * Render tutorial shortcode
     * Usage: [gots_tutorial id="123"]
     */
    public function render_tutorial_shortcode( $atts ) {
        $atts = shortcode_atts( [
            'id' => 0,
        ], $atts, 'gots_tutorial' );

        $tutorial_id = (int) $atts['id'];

        if ( ! $tutorial_id ) {
            return '<p class="gots-error">Tutorial ID required.</p>';
        }

        $tutorial = get_post( $tutorial_id );

        if ( ! $tutorial || $tutorial->post_type !== 'gots_tutorial' ) {
            return '<p class="gots-error">Tutorial not found.</p>';
        }

        return $this->render_tutorial( $tutorial_id );
    }

    /**
     * Render the two-pane tutorial interface
     */
    public function render_tutorial( $tutorial_id ) {
        $tutorial = get_post( $tutorial_id );
        $pages = get_post_meta( $tutorial_id, 'gots_pages', true ) ?: [];
        $metadata = get_post_meta( $tutorial_id, 'gots_metadata', true ) ?: [];
        
        // Get all blocks for this tutorial
        $blocks = $this->get_tutorial_blocks( $tutorial_id );

        ob_start();
        ?>
        <div class="gots-tutorial-container" data-tutorial-id="<?php echo esc_attr( $tutorial_id ); ?>">
            
            <!-- Tutorial Header -->
            <header class="gots-tutorial-header">
                <h1 class="gots-tutorial-title"><?php echo esc_html( $tutorial->post_title ); ?></h1>
                <div class="gots-progress-bar">
                    <div class="gots-progress-fill" style="width: 0%;"></div>
                </div>
                <span class="gots-progress-text">Question <span class="gots-current-question">1</span> of <span class="gots-total-questions"><?php echo count( $blocks ); ?></span></span>
            </header>

            <!-- Two-Pane Layout -->
            <div class="gots-split-screen">
                
                <!-- Left Pane: Instructions & Quiz -->
                <div class="gots-left-pane">
                    <div class="gots-instruction-panel">
                        <?php if ( ! empty( $blocks ) ) : ?>
                            <?php foreach ( $blocks as $index => $block ) : ?>
                                <div class="gots-block" 
                                     data-block-id="<?php echo esc_attr( $block['id'] ); ?>"
                                     data-block-index="<?php echo esc_attr( $index ); ?>"
                                     data-embed-url="<?php echo esc_attr( $block['embed_url'] ?? '' ); ?>"
                                     style="<?php echo $index === 0 ? '' : 'display: none;'; ?>">
                                    
                                    <?php echo $this->render_block( $block ); ?>
                                    
                                </div>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <p>No content available for this tutorial.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Navigation -->
                    <div class="gots-navigation">
                        <button class="gots-btn gots-btn-prev" disabled>← Previous</button>
                        <button class="gots-btn gots-btn-next">Next →</button>
                        <button class="gots-btn gots-btn-finish" style="display: none;">Finish Tutorial</button>
                    </div>
                </div>

                <!-- Right Pane: Embedded Content -->
                <div class="gots-right-pane">
                    <div class="gots-embed-container">
                        <?php 
                        $first_embed = '';
                        foreach ( $blocks as $block ) {
                            if ( ! empty( $block['embed_url'] ) ) {
                                $first_embed = $block['embed_url'];
                                break;
                            }
                        }
                        ?>
                        <iframe 
                            id="gots-embed-frame" 
                            src="<?php echo esc_url( $first_embed ); ?>"
                            frameborder="0"
                            allowfullscreen>
                        </iframe>
                        <div class="gots-embed-placeholder" style="<?php echo $first_embed ? 'display: none;' : ''; ?>">
                            <p>Select a question to load the related resource.</p>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Results Panel (hidden initially) -->
            <div class="gots-results-panel" style="display: none;">
                <h2>Tutorial Complete!</h2>
                <div class="gots-score">
                    <span class="gots-score-value">0</span>/<span class="gots-score-total">0</span> correct
                </div>
                <div class="gots-score-percentage">0%</div>
                <button class="gots-btn gots-btn-restart">Try Again</button>
            </div>

        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render individual block
     */
    private function render_block( $block ) {
        $html = '';
        
        switch ( $block['type'] ) {
            case 'text':
                $html = $this->render_text_block( $block );
                break;
            case 'quiz':
                $html = $this->render_quiz_block( $block );
                break;
            case 'image':
                $html = $this->render_image_block( $block );
                break;
            default:
                $html = '<p>Unknown block type.</p>';
        }

        return $html;
    }

    /**
     * Render text block
     */
    private function render_text_block( $block ) {
        ob_start();
        ?>
        <div class="gots-text-block">
            <?php if ( ! empty( $block['title'] ) ) : ?>
                <h3><?php echo esc_html( $block['title'] ); ?></h3>
            <?php endif; ?>
            <div class="gots-text-content">
                <?php echo wp_kses_post( $block['content'] ?? '' ); ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render quiz block (MCQ)
     */
    private function render_quiz_block( $block ) {
        $question_data = $block['question_data'] ?? [];
        $question_type = $question_data['type'] ?? 'multiple_choice';
        $options = $question_data['options'] ?? [];

        ob_start();
        ?>
        <div class="gots-quiz-block" data-block-id="<?php echo esc_attr( $block['id'] ); ?>" data-question-type="<?php echo esc_attr( $question_type ); ?>">
            
            <?php if ( ! empty( $block['title'] ) ) : ?>
                <h3 class="gots-question-title"><?php echo esc_html( $block['title'] ); ?></h3>
            <?php endif; ?>
            
            <div class="gots-question-text">
                <?php echo wp_kses_post( $question_data['question_text'] ?? '' ); ?>
            </div>

            <div class="gots-options">
                <?php if ( $question_type === 'multiple_choice' || $question_type === 'checkbox' ) : ?>
                    <?php foreach ( $options as $index => $option ) : ?>
                        <label class="gots-option">
                            <input 
                                type="<?php echo $question_type === 'checkbox' ? 'checkbox' : 'radio'; ?>" 
                                name="answer_<?php echo esc_attr( $block['id'] ); ?>" 
                                value="<?php echo esc_attr( $index ); ?>">
                            <span class="gots-option-text"><?php echo esc_html( $option ); ?></span>
                            <span class="gots-option-indicator"></span>
                        </label>
                    <?php endforeach; ?>
                
                <?php elseif ( $question_type === 'yes_no' ) : ?>
                    <label class="gots-option">
                        <input type="radio" name="answer_<?php echo esc_attr( $block['id'] ); ?>" value="yes">
                        <span class="gots-option-text">Yes</span>
                        <span class="gots-option-indicator"></span>
                    </label>
                    <label class="gots-option">
                        <input type="radio" name="answer_<?php echo esc_attr( $block['id'] ); ?>" value="no">
                        <span class="gots-option-text">No</span>
                        <span class="gots-option-indicator"></span>
                    </label>
                
                <?php elseif ( $question_type === 'text_input' ) : ?>
                    <input type="text" class="gots-text-input" name="answer_<?php echo esc_attr( $block['id'] ); ?>" placeholder="Type your answer...">
                <?php endif; ?>
            </div>

            <button class="gots-btn gots-btn-submit-answer" data-block-id="<?php echo esc_attr( $block['id'] ); ?>">
                Check Answer
            </button>

            <div class="gots-feedback" style="display: none;">
                <p class="gots-feedback-text"></p>
            </div>

        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render image block
     */
    private function render_image_block( $block ) {
        ob_start();
        ?>
        <div class="gots-image-block">
            <?php if ( ! empty( $block['title'] ) ) : ?>
                <h3><?php echo esc_html( $block['title'] ); ?></h3>
            <?php endif; ?>
            <?php if ( ! empty( $block['image_url'] ) ) : ?>
                <img src="<?php echo esc_url( $block['image_url'] ); ?>" 
                     alt="<?php echo esc_attr( $block['image_alt'] ?? '' ); ?>">
            <?php endif; ?>
            <?php if ( ! empty( $block['image_caption'] ) ) : ?>
                <p class="gots-image-caption"><?php echo esc_html( $block['image_caption'] ); ?></p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
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
            $block_type = get_post_meta( $post->ID, 'gots_block_type', true );
            
            $block = [
                'id'        => $post->ID,
                'type'      => $block_type,
                'title'     => $post->post_title,
                'sequence'  => (int) get_post_meta( $post->ID, 'gots_sequence', true ),
                'embed_url' => get_post_meta( $post->ID, 'gots_embed_url', true ),
            ];

            // Add type-specific data
            switch ( $block_type ) {
                case 'text':
                    $block['content'] = get_post_meta( $post->ID, 'gots_text_content', true );
                    break;
                case 'quiz':
                    $block['question_data'] = get_post_meta( $post->ID, 'gots_quiz_data', true );
                    break;
                case 'image':
                    $block['image_url'] = get_post_meta( $post->ID, 'gots_image_url', true );
                    $block['image_alt'] = get_post_meta( $post->ID, 'gots_image_alt', true );
                    $block['image_caption'] = get_post_meta( $post->ID, 'gots_image_caption', true );
                    break;
            }

            $blocks[] = $block;
        }

        return $blocks;
    }
}

// Initialize frontend
new GOTS_Frontend();
