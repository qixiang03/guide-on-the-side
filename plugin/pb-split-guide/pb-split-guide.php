<?php
/**
 * Plugin Name: Guide on the Side - Interactive Tutorial Plugin
 * Plugin URI: https://github.com/qixiang03/guide-on-the-side
 * Description: Split-screen tutorial system for UPEI Library resources
 * Version: 0.5.0
 * Author: Team 8
 * Author URI: https://github.com/qixiang03/guide-on-the-side
 * License: GPL-2.0+
 * Text Domain: gots
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'GOTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GOTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'GOTS_VERSION', '0.5.0' );
define( 'GOTS_API_NAMESPACE', 'gots/v1' );

/**
 * Main Plugin Class
 */
class Guide_On_The_Side {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Get single instance
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Load dependencies on plugins_loaded
        add_action( 'plugins_loaded', [ $this, 'load_dependencies' ] );
        
        // Register post types and taxonomies
        add_action( 'init', [ $this, 'register_post_types' ] );
        add_action( 'init', [ $this, 'register_taxonomies' ] );
        
        // REST API routes
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
        
        // Enqueue assets
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        
        // Activation hook
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
    }

    /**
     * Load plugin dependencies
     */
    public function load_dependencies() {
        // Core classes
        require_once GOTS_PLUGIN_DIR . 'includes/class-tutorial.php';
        require_once GOTS_PLUGIN_DIR . 'includes/class-block.php';
        require_once GOTS_PLUGIN_DIR . 'includes/class-quiz.php';
        
        // Frontend
        if ( file_exists( GOTS_PLUGIN_DIR . 'includes/class-frontend.php' ) ) {
            require_once GOTS_PLUGIN_DIR . 'includes/class-frontend.php';
        }
        
        // Admin
        if ( is_admin() && file_exists( GOTS_PLUGIN_DIR . 'includes/class-admin.php' ) ) {
            require_once GOTS_PLUGIN_DIR . 'includes/class-admin.php';
        }
        
        // API (optional - load if exists)
        if ( file_exists( GOTS_PLUGIN_DIR . 'includes/api/endpoints.php' ) ) {
            require_once GOTS_PLUGIN_DIR . 'includes/api/endpoints.php';
        }
        if ( file_exists( GOTS_PLUGIN_DIR . 'includes/api/auth-middleware.php' ) ) {
            require_once GOTS_PLUGIN_DIR . 'includes/api/auth-middleware.php';
        }
    }

    /**
     * Register custom post types
     */
    public function register_post_types() {
        // Tutorial post type
        register_post_type( 'gots_tutorial', [
            'labels' => [
                'name'               => __( 'Tutorials', 'gots' ),
                'singular_name'      => __( 'Tutorial', 'gots' ),
                'add_new'            => __( 'Add New Tutorial', 'gots' ),
                'add_new_item'       => __( 'Add New Tutorial', 'gots' ),
                'edit_item'          => __( 'Edit Tutorial', 'gots' ),
                'new_item'           => __( 'New Tutorial', 'gots' ),
                'view_item'          => __( 'View Tutorial', 'gots' ),
                'search_items'       => __( 'Search Tutorials', 'gots' ),
                'not_found'          => __( 'No tutorials found', 'gots' ),
                'not_found_in_trash' => __( 'No tutorials found in trash', 'gots' ),
                'menu_name'          => __( 'Library Tutorials', 'gots' ),
            ],
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_rest'       => true,
            'menu_icon'          => 'dashicons-welcome-learn-more',
            'supports'           => [ 'title', 'editor', 'author', 'revisions', 'thumbnail' ],
            'has_archive'        => true,
            'taxonomies'         => [ 'gots_subject', 'gots_level' ],
            'rewrite'            => [ 'slug' => 'tutorial' ],
            'rest_base'          => 'tutorials',
            'capability_type'    => 'post',
        ] );

        // Block post type (child of tutorial)
        register_post_type( 'gots_block', [
            'labels' => [
                'name'          => __( 'Tutorial Blocks', 'gots' ),
                'singular_name' => __( 'Block', 'gots' ),
            ],
            'public'       => false,
            'show_ui'      => false,
            'show_in_rest' => true,
            'supports'     => [ 'title' ],
            'rest_base'    => 'blocks',
        ] );
    }

    /**
     * Register taxonomies
     */
    public function register_taxonomies() {
        // Subject taxonomy
        register_taxonomy( 'gots_subject', 'gots_tutorial', [
            'labels' => [
                'name'          => __( 'Subjects', 'gots' ),
                'singular_name' => __( 'Subject', 'gots' ),
                'search_items'  => __( 'Search Subjects', 'gots' ),
                'all_items'     => __( 'All Subjects', 'gots' ),
                'edit_item'     => __( 'Edit Subject', 'gots' ),
                'add_new_item'  => __( 'Add New Subject', 'gots' ),
            ],
            'public'       => true,
            'hierarchical' => true,
            'show_in_rest' => true,
            'rewrite'      => [ 'slug' => 'subject' ],
        ] );

        // Difficulty level taxonomy
        register_taxonomy( 'gots_level', 'gots_tutorial', [
            'labels' => [
                'name'          => __( 'Difficulty Levels', 'gots' ),
                'singular_name' => __( 'Level', 'gots' ),
                'search_items'  => __( 'Search Levels', 'gots' ),
                'all_items'     => __( 'All Levels', 'gots' ),
            ],
            'public'       => true,
            'hierarchical' => false,
            'show_in_rest' => true,
            'rewrite'      => [ 'slug' => 'level' ],
        ] );
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        // Tutorial endpoints
        register_rest_route( GOTS_API_NAMESPACE, '/tutorials', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_tutorials' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( GOTS_API_NAMESPACE, '/tutorials', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'create_tutorial' ],
            'permission_callback' => [ $this, 'check_edit_permission' ],
        ] );

        register_rest_route( GOTS_API_NAMESPACE, '/tutorials/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_tutorial' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( GOTS_API_NAMESPACE, '/tutorials/(?P<id>\d+)', [
            'methods'             => 'PUT',
            'callback'            => [ $this, 'update_tutorial' ],
            'permission_callback' => [ $this, 'check_edit_permission' ],
        ] );

        // Block endpoints
        register_rest_route( GOTS_API_NAMESPACE, '/tutorials/(?P<tutorial_id>\d+)/blocks', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'add_block' ],
            'permission_callback' => [ $this, 'check_edit_permission' ],
        ] );

        // Quiz validation endpoint
        register_rest_route( GOTS_API_NAMESPACE, '/quizzes/(?P<block_id>\d+)/validate', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'validate_quiz_answer' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /**
     * Permission check for editing
     */
    public function check_edit_permission() {
        return current_user_can( 'edit_posts' );
    }

    /**
     * REST: Get all tutorials
     */
    public function get_tutorials( $request ) {
        $tutorial = new Tutorial();
        $result = $tutorial->list_tutorials( $request->get_params() );
        return rest_ensure_response( $result );
    }

    /**
     * REST: Create tutorial
     */
    public function create_tutorial( $request ) {
        $params = $request->get_json_params();
        $tutorial = new Tutorial();
        $result = $tutorial->create( $params );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => $result->get_error_message(),
            ], 400 );
        }

        return rest_ensure_response( [
            'success'     => true,
            'tutorial_id' => $result,
            'message'     => __( 'Tutorial created successfully', 'gots' ),
        ] );
    }

    /**
     * REST: Get single tutorial
     */
    public function get_tutorial( $request ) {
        $tutorial_id = (int) $request['id'];
        $tutorial = new Tutorial();
        $data = $tutorial->get( $tutorial_id );

        if ( ! $data ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => __( 'Tutorial not found', 'gots' ),
            ], 404 );
        }

        return rest_ensure_response( $data );
    }

    /**
     * REST: Update tutorial
     */
    public function update_tutorial( $request ) {
        $tutorial_id = (int) $request['id'];
        $params = $request->get_json_params();
        $tutorial = new Tutorial();
        $result = $tutorial->update( $tutorial_id, $params );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => $result->get_error_message(),
            ], 400 );
        }

        return rest_ensure_response( [
            'success' => true,
            'message' => __( 'Tutorial updated successfully', 'gots' ),
        ] );
    }

    /**
     * REST: Add block to tutorial
     */
    public function add_block( $request ) {
        $tutorial_id = (int) $request['tutorial_id'];
        $params = $request->get_json_params();
        
        $block = new Block();
        $result = $block->create( $tutorial_id, $params );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => $result->get_error_message(),
            ], 400 );
        }

        return rest_ensure_response( [
            'success'  => true,
            'block_id' => $result,
            'message'  => __( 'Block added successfully', 'gots' ),
        ] );
    }

    /**
     * REST: Validate quiz answer
     */
    public function validate_quiz_answer( $request ) {
        $block_id = (int) $request['block_id'];
        $params = $request->get_json_params();

        $quiz = new Quiz();
        $result = $quiz->validate_answer( $block_id, $params );

        // Track attempt
        if ( isset( $result['is_correct'] ) ) {
            $quiz->track_attempt( $block_id, $result['is_correct'] );
        }

        return rest_ensure_response( $result );
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueue_assets() {
        if ( is_singular( 'gots_tutorial' ) ) {
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
     * Enqueue admin assets
     */
    public function enqueue_admin_assets( $hook ) {
        global $post;
        
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ] ) ) {
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
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Register post types
        $this->register_post_types();
        $this->register_taxonomies();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
}

// Initialize plugin
Guide_On_The_Side::get_instance();
