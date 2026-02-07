<?php
/**
 * Block Class
 * 
 * Handles block management for tutorials
 * Supports: text blocks, embed blocks, quiz blocks, image blocks, divider blocks
 */

class Block {

    /**
     * Supported block types
     */
    private $supported_types = [
        'text',
        'embed',
        'quiz',
        'image',
        'divider',
    ];

    /**
     * Create a new block
     */
    public function create( $tutorial_id, $args ) {
        // Validate tutorial exists
        $tutorial = get_post( $tutorial_id );
        if ( ! $tutorial || $tutorial->post_type !== 'gots_tutorial' ) {
            return new WP_Error( 'invalid_tutorial', __( 'Tutorial not found', 'gots' ) );
        }

        // Validate block type
        $block_type = $args['type'] ?? null;
        if ( ! in_array( $block_type, $this->supported_types ) ) {
            return new WP_Error( 'invalid_type', __( 'Invalid block type', 'gots' ) );
        }

        // Validate required fields
        if ( ! isset( $args['page_id'] ) ) {
            return new WP_Error( 'missing_page', __( 'Page ID required', 'gots' ) );
        }

        // Create block post
        $block_post = wp_insert_post( [
            'post_type'   => 'gots_block',
            'post_title'  => sanitize_text_field( $args['title'] ?? 'Block' ),
            'post_parent' => $tutorial_id,
            'post_status' => 'publish',
        ] );

        if ( is_wp_error( $block_post ) ) {
            return $block_post;
        }

        // Save block metadata
        add_post_meta( $block_post, 'gots_block_type', $block_type );
        add_post_meta( $block_post, 'gots_page_id', sanitize_text_field( $args['page_id'] ) );
        add_post_meta( $block_post, 'gots_sequence', (int) ( $args['sequence'] ?? 0 ) );

        // Save type-specific content
        switch ( $block_type ) {
            case 'text':
                $this->save_text_block( $block_post, $args );
                break;
            case 'embed':
                $this->save_embed_block( $block_post, $args );
                break;
            case 'quiz':
                $this->save_quiz_block( $block_post, $args );
                break;
            case 'image':
                $this->save_image_block( $block_post, $args );
                break;
            case 'divider':
                $this->save_divider_block( $block_post, $args );
                break;
        }

        return $block_post;
    }

    /**
     * Get block by ID
     */
    public function get( $block_id ) {
        $block = get_post( $block_id );

        if ( ! $block || $block->post_type !== 'gots_block' ) {
            return false;
        }

        $block_type = get_post_meta( $block_id, 'gots_block_type', true );

        $data = [
            'id'         => $block->ID,
            'type'       => $block_type,
            'title'      => $block->post_title,
            'tutorial_id' => $block->post_parent,
            'page_id'    => get_post_meta( $block_id, 'gots_page_id', true ),
            'sequence'   => (int) get_post_meta( $block_id, 'gots_sequence', true ),
        ];

        // Add type-specific content
        switch ( $block_type ) {
            case 'text':
                $data['content'] = get_post_meta( $block_id, 'gots_text_content', true );
                break;
            case 'embed':
                $data['url'] = get_post_meta( $block_id, 'gots_embed_url', true );
                $data['embed_type'] = get_post_meta( $block_id, 'gots_embed_type', true );
                $data['domain_lock'] = get_post_meta( $block_id, 'gots_domain_lock', true );
                break;
            case 'quiz':
                $data['question_data'] = get_post_meta( $block_id, 'gots_quiz_data', true );
                break;
            case 'image':
                $data['image_url'] = get_post_meta( $block_id, 'gots_image_url', true );
                $data['image_alt'] = get_post_meta( $block_id, 'gots_image_alt', true );
                $data['image_caption'] = get_post_meta( $block_id, 'gots_image_caption', true );
                break;
        }

        return $data;
    }

    /**
     * Update block
     */
    public function update( $block_id, $args ) {
        $block = get_post( $block_id );

        if ( ! $block || $block->post_type !== 'gots_block' ) {
            return new WP_Error( 'invalid_block', __( 'Block not found', 'gots' ) );
        }

        $block_type = get_post_meta( $block_id, 'gots_block_type', true );

        // Update basic post data
        wp_update_post( [
            'ID'         => $block_id,
            'post_title' => sanitize_text_field( $args['title'] ?? $block->post_title ),
        ] );

        // Update type-specific content
        switch ( $block_type ) {
            case 'text':
                $this->save_text_block( $block_id, $args );
                break;
            case 'embed':
                $this->save_embed_block( $block_id, $args );
                break;
            case 'quiz':
                $this->save_quiz_block( $block_id, $args );
                break;
            case 'image':
                $this->save_image_block( $block_id, $args );
                break;
        }

        if ( isset( $args['sequence'] ) ) {
            update_post_meta( $block_id, 'gots_sequence', (int) $args['sequence'] );
        }

        return $block_id;
    }

    /**
     * Delete block
     */
    public function delete( $block_id ) {
        return wp_delete_post( $block_id, true );
    }

    /**
     * Get all blocks for a page
     */
    public function get_page_blocks( $tutorial_id, $page_id ) {
        $args = [
            'post_parent' => $tutorial_id,
            'post_type'   => 'gots_block',
            'meta_query'  => [
                [
                    'key'   => 'gots_page_id',
                    'value' => sanitize_text_field( $page_id ),
                ]
            ],
            'orderby'     => 'meta_value_num',
            'meta_key'    => 'gots_sequence',
            'order'       => 'ASC',
        ];

        $posts = get_posts( $args );
        $blocks = [];

        foreach ( $posts as $post ) {
            $blocks[] = $this->get( $post->ID );
        }

        return $blocks;
    }

    /**
     * Reorder blocks
     */
    public function reorder_blocks( $block_ids ) {
        foreach ( $block_ids as $sequence => $block_id ) {
            update_post_meta( (int) $block_id, 'gots_sequence', $sequence );
        }
        return true;
    }

    /**
     * Save text block content
     */
    private function save_text_block( $block_id, $args ) {
        $content = wp_kses_post( $args['content'] ?? '' );
        update_post_meta( $block_id, 'gots_text_content', $content );
    }

    /**
     * Save embed block content
     */
    private function save_embed_block( $block_id, $args ) {
        $url = esc_url( $args['url'] ?? '' );
        $embed_type = sanitize_text_field( $args['embed_type'] ?? 'iframe' );
        $domain_lock = sanitize_text_field( $args['domain_lock'] ?? '' );

        // Validate URL
        if ( ! $this->is_valid_url( $url ) ) {
            return new WP_Error( 'invalid_url', __( 'Invalid URL', 'gots' ) );
        }

        update_post_meta( $block_id, 'gots_embed_url', $url );
        update_post_meta( $block_id, 'gots_embed_type', $embed_type );
        update_post_meta( $block_id, 'gots_domain_lock', $domain_lock );

        return true;
    }

    /**
     * Save quiz block content
     */
    private function save_quiz_block( $block_id, $args ) {
        $question_data = [
            'type'                 => sanitize_text_field( $args['type'] ?? 'multiple_choice' ),
            'question_text'        => wp_kses_post( $args['question_text'] ?? '' ),
            'correct_answer'       => isset( $args['correct_answer'] ) ? $args['correct_answer'] : null,
            'correct_answers'      => $args['correct_answers'] ?? [],
            'options'              => array_map( 'sanitize_text_field', (array) ( $args['options'] ?? [] ) ),
            'feedback_correct'     => wp_kses_post( $args['feedback_correct'] ?? __( 'Correct!', 'gots' ) ),
            'feedback_incorrect'   => wp_kses_post( $args['feedback_incorrect'] ?? __( 'Incorrect. Try again.', 'gots' ) ),
            'max_attempts'         => (int) ( $args['max_attempts'] ?? 3 ),
            'case_sensitive'       => (bool) ( $args['case_sensitive'] ?? false ),
            'show_answer'          => (bool) ( $args['show_answer'] ?? false ),
        ];

        update_post_meta( $block_id, 'gots_quiz_data', $question_data );
        return true;
    }

    /**
     * Save image block content
     */
    private function save_image_block( $block_id, $args ) {
        $image_url = esc_url( $args['image_url'] ?? '' );
        $image_alt = sanitize_text_field( $args['image_alt'] ?? '' );
        $image_caption = wp_kses_post( $args['image_caption'] ?? '' );

        if ( ! $this->is_valid_image_url( $image_url ) ) {
            return new WP_Error( 'invalid_image', __( 'Invalid image URL', 'gots' ) );
        }

        update_post_meta( $block_id, 'gots_image_url', $image_url );
        update_post_meta( $block_id, 'gots_image_alt', $image_alt );
        update_post_meta( $block_id, 'gots_image_caption', $image_caption );

        return true;
    }

    /**
     * Save divider block content
     */
    private function save_divider_block( $block_id, $args ) {
        $divider_style = sanitize_text_field( $args['style'] ?? 'solid' );
        update_post_meta( $block_id, 'gots_divider_style', $divider_style );
        return true;
    }

    /**
     * Validate URL
     */
    private function is_valid_url( $url ) {
        return filter_var( $url, FILTER_VALIDATE_URL ) !== false;
    }

    /**
     * Validate image URL
     */
    private function is_valid_image_url( $url ) {
        $url = esc_url( $url );
        $extension = strtolower( pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
        $allowed_extensions = [ 'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp' ];

        return in_array( $extension, $allowed_extensions );
    }

    /**
     * Get block type
     */
    public function get_type( $block_id ) {
        return get_post_meta( $block_id, 'gots_block_type', true );
    }

    /**
     * Check if block exists
     */
    public function exists( $block_id ) {
        $block = get_post( $block_id );
        return $block && $block->post_type === 'gots_block';
    }

    /**
     * Duplicate block
     */
    public function duplicate( $block_id ) {
        $original = $this->get( $block_id );

        if ( ! $original ) {
            return new WP_Error( 'not_found', __( 'Block not found', 'gots' ) );
        }

        $new_block_id = wp_insert_post( [
            'post_type'   => 'gots_block',
            'post_title'  => $original['title'] . ' (Copy)',
            'post_parent' => $original['tutorial_id'],
            'post_status' => 'publish',
        ] );

        if ( is_wp_error( $new_block_id ) ) {
            return $new_block_id;
        }

        // Copy all metadata
        copy_post_meta( $block_id, $new_block_id );

        return $new_block_id;
    }

    /**
     * Get block statistics
     */
    public function get_stats( $block_id ) {
        $block_type = $this->get_type( $block_id );

        if ( $block_type === 'quiz' ) {
            $quiz = new Quiz();
            return $quiz->get_question_stats( $block_id );
        }

        return [
            'views'      => (int) get_post_meta( $block_id, 'gots_block_views', true ) ?: 0,
            'interactions' => (int) get_post_meta( $block_id, 'gots_interactions', true ) ?: 0,
        ];
    }

    /**
     * Track block view
     */
    public function track_view( $block_id ) {
        $views = (int) get_post_meta( $block_id, 'gots_block_views', true ) ?: 0;
        update_post_meta( $block_id, 'gots_block_views', ++$views );
    }
}

/**
 * Helper function to copy all post meta
 */
function copy_post_meta( $source_post_id, $dest_post_id ) {
    $meta = get_post_meta( $source_post_id );

    foreach ( $meta as $key => $values ) {
        // Skip internal WordPress meta
        if ( strpos( $key, '_' ) === 0 ) {
            continue;
        }

        foreach ( $values as $value ) {
            add_post_meta( $dest_post_id, $key, maybe_unserialize( $value ) );
        }
    }
}

?>