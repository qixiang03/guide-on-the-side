<?php
/**
 * Tutorial Class
 * 
 * Handles CRUD operations for tutorial posts
 */

class Tutorial {

    /**
     * Create a new tutorial
     */
    public function create( $args ) {
        $defaults = [
            'post_title'   => __( 'Untitled Tutorial', 'gots' ),
            'post_content' => '',
            'post_status'  => 'draft',
            'post_type'    => 'gots_tutorial',
            'post_author'  => get_current_user_id(),
        ];

        $post_args = wp_parse_args( $args, $defaults );

        // Sanitize input
        $post_args['post_title']   = sanitize_text_field( $post_args['post_title'] );
        $post_args['post_content'] = wp_kses_post( $post_args['post_content'] );

        $tutorial_id = wp_insert_post( $post_args );

        if ( is_wp_error( $tutorial_id ) ) {
            return $tutorial_id;
        }

        // Initialize tutorial metadata
        $this->init_metadata( $tutorial_id );

        return $tutorial_id;
    }

    /**
     * Get tutorial by ID
     */
    public function get( $tutorial_id ) {
        $tutorial = get_post( $tutorial_id );

        if ( ! $tutorial || $tutorial->post_type !== 'gots_tutorial' ) {
            return false;
        }

        return [
            'id'          => $tutorial->ID,
            'title'       => $tutorial->post_title,
            'content'     => $tutorial->post_content,
            'status'      => $tutorial->post_status,
            'author_id'   => $tutorial->post_author,
            'created_at'  => $tutorial->post_date,
            'modified_at' => $tutorial->post_modified,
            'pages'       => $this->get_pages( $tutorial->ID ),
            'metadata'    => get_post_meta( $tutorial->ID, 'gots_metadata', true ),
        ];
    }

    /**
     * Update tutorial
     */
    public function update( $tutorial_id, $args ) {
        $tutorial = get_post( $tutorial_id );

        if ( ! $tutorial || $tutorial->post_type !== 'gots_tutorial' ) {
            return new WP_Error( 'invalid_tutorial', __( 'Invalid tutorial', 'gots' ) );
        }

        $post_args = [
            'ID'           => $tutorial_id,
            'post_title'   => sanitize_text_field( $args['post_title'] ?? $tutorial->post_title ),
            'post_content' => wp_kses_post( $args['post_content'] ?? $tutorial->post_content ),
            'post_status'  => in_array( $args['post_status'] ?? '', [ 'draft', 'publish' ] ) ? $args['post_status'] : $tutorial->post_status,
        ];

        $result = wp_update_post( $post_args );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Update metadata if provided
        if ( isset( $args['metadata'] ) ) {
            update_post_meta( $tutorial_id, 'gots_metadata', $args['metadata'] );
        }

        return $tutorial_id;
    }

    /**
     * Delete tutorial
     */
    public function delete( $tutorial_id ) {
        return wp_delete_post( $tutorial_id, true );
    }

    /**
     * Autosave tutorial draft
     */
    public function autosave( $tutorial_id, $data ) {
        // Store autosave data in post meta with timestamp
        $autosave_data = [
            'content'     => wp_kses_post( $data['content'] ?? '' ),
            'pages'       => $data['pages'] ?? [],
            'timestamp'   => current_time( 'mysql' ),
        ];

        update_post_meta( $tutorial_id, 'gots_autosave', $autosave_data );

        return $tutorial_id;
    }

    /**
     * Initialize tutorial metadata
     */
    private function init_metadata( $tutorial_id ) {
        $metadata = [
            'template'           => 'default',
            'color_scheme'       => 'light',
            'layout'             => 'two-column',
            'enable_progress_bar' => true,
            'enable_certificates' => false,
            'custom_css'         => '',
        ];

        add_post_meta( $tutorial_id, 'gots_metadata', $metadata );
    }

    /**
     * Get pages for tutorial
     */
    private function get_pages( $tutorial_id ) {
        $pages_data = get_post_meta( $tutorial_id, 'gots_pages', true );
        return $pages_data ?: [];
    }

    /**
     * Get tutorial by slug
     */
    public function get_by_slug( $slug ) {
        $tutorial = get_page_by_path( $slug, OBJECT, 'gots_tutorial' );
        return $tutorial ? $this->get( $tutorial->ID ) : false;
    }

    /**
     * List all tutorials (with filters)
     */
    public function list_tutorials( $args = [] ) {
        $defaults = [
            'post_type'      => 'gots_tutorial',
            'post_status'    => [ 'publish', 'draft' ],
            'posts_per_page' => 20,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        $query_args = wp_parse_args( $args, $defaults );

        $query = new WP_Query( $query_args );

        $tutorials = [];
        foreach ( $query->posts as $tutorial ) {
            $tutorials[] = [
                'id'       => $tutorial->ID,
                'title'    => $tutorial->post_title,
                'status'   => $tutorial->post_status,
                'author'   => get_the_author_meta( 'display_name', $tutorial->post_author ),
                'date'     => $tutorial->post_date,
            ];
        }

        return [
            'tutorials'      => $tutorials,
            'total'          => $query->found_posts,
            'pages'          => $query->max_num_pages,
            'current_page'   => isset( $args['paged'] ) ? $args['paged'] : 1,
        ];
    }

    /**
     * Get tutorial statistics
     */
    public function get_stats( $tutorial_id ) {
        return [
            'total_views'    => (int) get_post_meta( $tutorial_id, 'gots_views', true ) ?: 0,
            'total_starts'   => (int) get_post_meta( $tutorial_id, 'gots_starts', true ) ?: 0,
            'total_completions' => (int) get_post_meta( $tutorial_id, 'gots_completions', true ) ?: 0,
            'average_completion_time' => (int) get_post_meta( $tutorial_id, 'gots_avg_time', true ) ?: 0,
        ];
    }

    /**
     * Track tutorial view
     */
    public function track_view( $tutorial_id ) {
        $views = (int) get_post_meta( $tutorial_id, 'gots_views', true ) ?: 0;
        update_post_meta( $tutorial_id, 'gots_views', ++$views );
    }

    /**
     * Check if tutorial is published
     */
    public function is_published( $tutorial_id ) {
        $tutorial = get_post( $tutorial_id );
        return $tutorial && $tutorial->post_status === 'publish';
    }

    /**
     * Publish tutorial
     */
    public function publish( $tutorial_id ) {
        return wp_update_post( [
            'ID'          => $tutorial_id,
            'post_status' => 'publish',
        ] );
    }

    /**
     * Unpublish tutorial
     */
    public function unpublish( $tutorial_id ) {
        return wp_update_post( [
            'ID'          => $tutorial_id,
            'post_status' => 'draft',
        ] );
    }
}

?>