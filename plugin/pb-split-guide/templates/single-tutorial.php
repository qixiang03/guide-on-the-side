<?php
/**
 * Template for displaying single tutorial
 * 
 * This template renders the two-pane split-screen interface
 */

get_header();

while ( have_posts() ) :
    the_post();
    
    $tutorial_id = get_the_ID();
    
    // Get the frontend renderer
    if ( class_exists( 'GOTS_Frontend' ) ) {
        $frontend = new GOTS_Frontend();
        echo $frontend->render_tutorial( $tutorial_id );
    } else {
        // Fallback: render basic content
        ?>
        <article id="tutorial-<?php echo esc_attr( $tutorial_id ); ?>" class="gots-tutorial">
            <header>
                <h1><?php the_title(); ?></h1>
            </header>
            <div class="tutorial-content">
                <?php the_content(); ?>
            </div>
        </article>
        <?php
    }
    
endwhile;

get_footer();
