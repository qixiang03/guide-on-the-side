<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Template Picker page — shown when the librarian clicks "Add Tutorial".
 * Replaces the default post-new.php for pages.
 */
$picker_nonce = wp_create_nonce( 'pbsg_template_picker' );
$cancel_url   = admin_url( 'edit.php?post_type=page' );
?>

<div class="wrap" id="pbsg-new-tutorial-page">
  <h1>New Tutorial</h1>
  <p style="font-size:14px; color:#50575e;">Choose a starting point, give your tutorial a title, then click <strong>Create Tutorial</strong>.</p>

  <div id="pbsg-tpl-loading" style="padding:16px 0; color:#646970;">
    Loading templates&hellip;
  </div>

  <div id="pbsg-tpl-grid" style="display:none; margin-top:4px;"></div>

  <div id="pbsg-create-form" style="display:none; margin-top:28px; max-width:520px;">
    <hr style="margin-bottom:24px;" />
    <label for="pbsg-new-title" style="font-weight:600; font-size:14px; display:block; margin-bottom:6px;">
      Tutorial title
    </label>
    <input
      type="text"
      id="pbsg-new-title"
      class="large-text"
      placeholder="Enter a title for your tutorial"
      style="width:100%; font-size:15px; padding:6px 10px;"
    />
    <p id="pbsg-create-error" style="color:#d63638; margin:8px 0 0; display:none;"></p>
    <p style="margin-top:16px;">
      <button type="button" class="button button-primary button-large" id="pbsg-create-btn">
        Create Tutorial
      </button>
      <a href="<?php echo esc_url( $cancel_url ); ?>"
         class="button button-large"
         style="margin-left:10px;">
        Cancel
      </a>
    </p>
  </div>
</div>

<style>
  #pbsg-new-tutorial-page {
    max-width: 860px;
  }

  .pbsg-tpl-grid {
    display: grid;
    grid-template-columns: repeat( auto-fill, minmax( 220px, 1fr ) );
    gap: 14px;
    margin-top: 8px;
  }

  .pbsg-tpl-card {
    background: #fff;
    border: 2px solid #dcdcde;
    border-radius: 8px;
    padding: 18px 16px;
    cursor: pointer;
    transition: border-color .12s, box-shadow .12s;
    user-select: none;
  }

  .pbsg-tpl-card:hover {
    border-color: #2271b1;
  }

  .pbsg-tpl-card.pbsg-selected {
    border-color: #2271b1;
    box-shadow: 0 0 0 3px rgba( 34, 113, 177, .18 );
  }

  .pbsg-tpl-card .pbsg-tpl-name {
    font-size: 14px;
    font-weight: 600;
    margin: 0 0 6px;
    line-height: 1.4;
  }

  .pbsg-tpl-card .pbsg-tpl-desc {
    font-size: 12px;
    color: #646970;
    margin: 0 0 8px;
    line-height: 1.45;
  }

  .pbsg-tpl-badge {
    display: inline-block;
    background: #2271b1;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    border-radius: 3px;
    padding: 1px 6px;
    margin-left: 5px;
    vertical-align: middle;
    text-transform: uppercase;
    letter-spacing: .04em;
  }

  .pbsg-tpl-card .pbsg-tpl-cat {
    font-size: 11px;
    margin: 0;
  }
</style>

<script>
( function( $ ) {
  var selectedId = null;
  var ajaxUrl    = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
  var nonce      = <?php echo wp_json_encode( $picker_nonce ); ?>;

  function escHtml( str ) {
    return $( '<span>' ).text( str ).html();
  }

  function renderTemplates( templates ) {
    var html = '<div class="pbsg-tpl-grid">';

    // "Start from scratch" is always first (template_id = 0)
    html += '<div class="pbsg-tpl-card" data-tpl-id="0">'
          + '<p class="pbsg-tpl-name">Start from scratch</p>'
          + '<p class="pbsg-tpl-desc">Create an empty tutorial with no steps pre-filled.</p>'
          + '</div>';

    $.each( templates, function( _, tpl ) {
      var badge = ( parseInt( tpl.is_system ) === 1 )
        ? '<span class="pbsg-tpl-badge">Built-in</span>'
        : '';

      html += '<div class="pbsg-tpl-card" data-tpl-id="' + parseInt( tpl.id ) + '">';
      html += '<p class="pbsg-tpl-name">' + escHtml( tpl.name ) + badge + '</p>';

      if ( tpl.description ) {
        html += '<p class="pbsg-tpl-desc">' + escHtml( tpl.description ) + '</p>';
      }

      if ( tpl.category ) {
        html += '<p class="pbsg-tpl-cat">' + escHtml( tpl.category ) + '</p>';
      }

      html += '</div>';
    } );

    html += '</div>';

    $( '#pbsg-tpl-grid' ).html( html ).show();
    $( '#pbsg-tpl-loading' ).hide();
    $( '#pbsg-create-form' ).show();
  }

  // Load templates
  $.post( ajaxUrl, { action: 'pbsg_get_templates', nonce: nonce } )
    .done( function( res ) {
      if ( res && res.success ) {
        renderTemplates( res.data.templates || [] );
      } else {
        $( '#pbsg-tpl-loading' ).text( 'Could not load templates.' );
      }
    } )
    .fail( function() {
      $( '#pbsg-tpl-loading' ).text( 'Request failed. Please refresh the page.' );
    } );

  // Select a card
  $( document ).on( 'click', '.pbsg-tpl-card', function() {
    $( '.pbsg-tpl-card' ).removeClass( 'pbsg-selected' );
    $( this ).addClass( 'pbsg-selected' );
    selectedId = parseInt( $( this ).data( 'tpl-id' ) );
    $( '#pbsg-new-title' ).trigger( 'focus' );
  } );

  // Create button
  $( '#pbsg-create-btn' ).on( 'click', function() {
    var title = $.trim( $( '#pbsg-new-title' ).val() );
    var $err  = $( '#pbsg-create-error' );

    $err.hide();

    if ( ! title ) {
      $err.text( 'Please enter a title for your tutorial.' ).show();
      $( '#pbsg-new-title' ).trigger( 'focus' );
      return;
    }

    if ( selectedId === null ) {
      $err.text( 'Please choose a starting point above.' ).show();
      return;
    }

    var $btn = $( this ).prop( 'disabled', true ).text( 'Creating\u2026' );

    $.post( ajaxUrl, {
      action:      'pbsg_create_from_template',
      nonce:       nonce,
      template_id: selectedId,
      title:       title,
    } )
    .done( function( res ) {
      if ( res && res.success && res.data.edit_url ) {
        window.location.href = res.data.edit_url;
      } else {
        var msg = ( res && res.data && res.data.message ) ? res.data.message : 'Could not create tutorial.';
        $err.text( msg ).show();
        $btn.prop( 'disabled', false ).text( 'Create Tutorial' );
      }
    } )
    .fail( function() {
      $err.text( 'Request failed. Please try again.' ).show();
      $btn.prop( 'disabled', false ).text( 'Create Tutorial' );
    } );
  } );

  // Enter key in title field = submit
  $( '#pbsg-new-title' ).on( 'keydown', function( e ) {
    if ( e.key === 'Enter' ) {
      e.preventDefault();
      $( '#pbsg-create-btn' ).trigger( 'click' );
    }
  } );

} )( jQuery );
</script>
