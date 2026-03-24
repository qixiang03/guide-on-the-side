<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin My Tutorials page
 *
 * Expected variables before include:
 * - $tutorials : array of tutorial items (includes post_id, title, link, edit_link, cover)
 */

$tutorials    = isset($tutorials) && is_array($tutorials) ? $tutorials : [];
$export_nonce = wp_create_nonce('pbsg_export_import');
$import_nonce = wp_create_nonce('pbsg_export_import');
?>

<div class="wrap pbsg-admin-tutorials-page">
  <h1 style="margin-bottom:6px;">My Tutorials</h1>

  <div class="nav-tab-wrapper" style="margin-bottom:20px;">
    <a href="#" class="nav-tab nav-tab-active">Overview</a>
  </div>

  <?php /* ── Import panel ───────────────────────────────────────────── */ ?>
  <div id="pbsg-import-panel" style="margin-bottom:20px; max-width:520px; background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:18px 20px;">
    <h2 style="margin-top:0; font-size:15px;">Import Tutorial</h2>
    <p style="color:#50575e; margin-bottom:12px; font-size:13px;">
      Upload a <code>.json</code> export file from another Guide on the Side server.
    </p>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <input type="file" id="pbsg-import-file" accept=".json" />
      <button type="button" class="button button-primary" id="pbsg-import-btn">Import</button>
    </div>
    <p id="pbsg-import-status" style="margin:10px 0 0; display:none;"></p>
  </div>

  <?php if (empty($tutorials)) : ?>
    <div class="notice notice-info inline">
      <p>No tutorials found. <a href="<?php echo esc_url(admin_url('admin.php?page=pbsg-new-tutorial')); ?>">Add your first tutorial.</a></p>
    </div>
  <?php else : ?>

    <style>
      .pbsg-admin-tutorials-grid{
        display:grid;
        grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));
        gap:20px;
        margin-top:18px;
      }

      .pbsg-admin-tutorial-card{
        background:#fff;
        border:1px solid #dcdcde;
        border-radius:8px;
        overflow:hidden;
        box-shadow:0 1px 2px rgba(0,0,0,.04);
      }

      .pbsg-admin-tutorial-thumb{
        display:block;
        width:100%;
        height:180px;
        background:#f0f0f1;
        overflow:hidden;
      }

      .pbsg-admin-tutorial-thumb img{
        width:100%;
        height:100%;
        object-fit:cover;
        display:block;
      }

      .pbsg-admin-tutorial-body{
        padding:18px;
      }

      .pbsg-admin-tutorial-title{
        margin:0 0 10px;
        font-size:20px;
        line-height:1.35;
      }

      .pbsg-admin-tutorial-title a{
        text-decoration:none;
      }

      .pbsg-admin-tutorial-title a:hover{
        text-decoration:underline;
      }

      .pbsg-admin-tutorial-meta{
        color:#646970;
        margin-bottom:14px;
      }

      .pbsg-admin-tutorial-actions{
        display:flex;
        gap:10px;
        flex-wrap:wrap;
      }
    </style>

    <div class="pbsg-admin-tutorials-grid">
      <?php foreach ($tutorials as $item) : ?>
        <div class="pbsg-admin-tutorial-card">
          <a class="pbsg-admin-tutorial-thumb" href="<?php echo esc_url($item['link']); ?>">
            <img
              src="<?php echo esc_url($item['cover']); ?>"
              alt="<?php echo esc_attr($item['title']); ?>"
            />
          </a>

          <div class="pbsg-admin-tutorial-body">
            <h2 class="pbsg-admin-tutorial-title">
              <a href="<?php echo esc_url($item['link']); ?>">
                <?php echo esc_html($item['title']); ?>
              </a>
            </h2>

            <div class="pbsg-admin-tutorial-meta">
              Tutorial
            </div>

            <div class="pbsg-admin-tutorial-actions">
              <a class="button button-primary" href="<?php echo esc_url($item['link']); ?>">
                Open Tutorial
              </a>

              <?php if (!empty($item['edit_link'])) : ?>
                <a class="button" href="<?php echo esc_url($item['edit_link']); ?>">
                  Edit
                </a>
              <?php endif; ?>

              <?php /* Export: plain form POST → AJAX handler streams file download */ ?>
              <form method="post"
                    action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
                    style="display:inline;">
                <input type="hidden" name="action"  value="pbsg_export_tutorial" />
                <input type="hidden" name="nonce"   value="<?php echo esc_attr($export_nonce); ?>" />
                <input type="hidden" name="post_id" value="<?php echo esc_attr($item['post_id']); ?>" />
                <button type="submit" class="button">Export</button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

  <?php endif; ?>
</div>

<script>
( function( $ ) {
  var ajaxUrl    = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
  var importNonce = <?php echo wp_json_encode( $import_nonce ); ?>;

  $( '#pbsg-import-btn' ).on( 'click', function() {
    var file = $( '#pbsg-import-file' ).prop( 'files' )[0];
    var $status = $( '#pbsg-import-status' );

    $status.hide().removeClass( 'notice-success notice-error' );

    if ( ! file ) {
      $status
        .addClass( 'notice notice-error inline' )
        .html( '<p>Please choose a <code>.json</code> export file first.</p>' )
        .show();
      return;
    }

    var fd = new FormData();
    fd.append( 'action', 'pbsg_import_tutorial' );
    fd.append( 'nonce', importNonce );
    fd.append( 'pbsg_import_file', file );

    var $btn = $( this ).prop( 'disabled', true ).text( 'Importing\u2026' );

    $.ajax( {
      url:         ajaxUrl,
      type:        'POST',
      data:        fd,
      processData: false,
      contentType: false,
    } )
    .done( function( res ) {
      if ( res && res.success ) {
        $status
          .addClass( 'notice notice-success inline' )
          .html(
            '<p>Tutorial <strong>' + $( '<span>' ).text( res.data.title ).html() + '</strong> imported successfully. ' +
            '<a href="' + $( '<span>' ).text( res.data.edit_url ).html() + '">Edit it now.</a></p>'
          )
          .show();
        $( '#pbsg-import-file' ).val( '' );
      } else {
        var msg = ( res && res.data && res.data.message ) ? res.data.message : 'Import failed.';
        $status
          .addClass( 'notice notice-error inline' )
          .html( '<p>' + $( '<span>' ).text( msg ).html() + '</p>' )
          .show();
      }
    } )
    .fail( function() {
      $status
        .addClass( 'notice notice-error inline' )
        .html( '<p>Request failed. Please try again.</p>' )
        .show();
    } )
    .always( function() {
      $btn.prop( 'disabled', false ).text( 'Import' );
    } );
  } );
} )( jQuery );
</script>
