<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin My Tutorials page
 *
 * Expected variables before include:
 * - $tutorials         : array of tutorial items
 * - $current_tab       : 'recent' or 'owned'
 * - $is_admin          : bool — whether current user is admin
 * - $transfer_enabled  : bool — whether transfer actions should show
 */

$tutorials        = isset($tutorials) && is_array($tutorials) ? $tutorials : [];
$current_tab      = isset($current_tab) ? $current_tab : 'recent';
$is_admin         = isset($is_admin) ? (bool) $is_admin : false;
$transfer_enabled = isset($transfer_enabled) ? (bool) $transfer_enabled : false;
$base_url         = admin_url('admin.php?page=pbsg-my-tutorials');
$export_nonce     = wp_create_nonce('pbsg_export_import');
$import_nonce     = wp_create_nonce('pbsg_export_import');
?>

<div class="wrap pbsg-admin-tutorials-page">
  <h1 class="wp-heading-inline" style="margin-bottom:6px;">My Tutorials</h1>
  <a href="<?php echo esc_url(admin_url('admin.php?page=pbsg-new-tutorial')); ?>" class="page-title-action">Add Tutorial</a>
  <hr class="wp-header-end">

  <div class="nav-tab-wrapper" style="margin-bottom:20px;">
    <?php if ($is_admin) : ?>
      <a href="<?php echo esc_url(remove_query_arg('tab', $base_url)); ?>"
         class="nav-tab nav-tab-active">
        All Tutorials
      </a>
    <?php else : ?>
      <a href="<?php echo esc_url(add_query_arg('tab', 'recent', $base_url)); ?>"
         class="nav-tab <?php echo $current_tab === 'recent' ? 'nav-tab-active' : ''; ?>">
        Recently Worked On
      </a>
      <a href="<?php echo esc_url(add_query_arg('tab', 'owned', $base_url)); ?>"
         class="nav-tab <?php echo $current_tab === 'owned' ? 'nav-tab-active' : ''; ?>">
        My Tutorials
      </a>
    <?php endif; ?>
  </div>

  <?php /* ── Import panel ───────────────────────────────────────────── */ ?>
  <div id="pbsg-import-panel" style="margin-bottom:20px; max-width:520px; background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:18px 20px;">
    <h2 style="margin-top:0; font-size:15px;">Import Tutorial</h2>
    <p style="color:#50575e; margin-bottom:12px; font-size:13px;">
      Upload a <code>.json</code> export file from another Guide on the Side server.
    </p>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <input type="file" aria-label="Import File" id="pbsg-import-file" accept=".json" />
      <button type="button" class="button button-primary" id="pbsg-import-btn">Import</button>
    </div>
    <p id="pbsg-import-status" style="margin:10px 0 0; display:none;"></p>
  </div>

  <?php if (empty($tutorials)) : ?>
    <div class="notice notice-info inline">
      <p>
        <?php
        if ($current_tab === 'owned') {
          esc_html_e('You don\'t own any tutorials yet.', 'pb-split-guide');
        } else {
          esc_html_e('No tutorials found.', 'pb-split-guide');
        }
        ?>
        <a href="<?php echo esc_url(admin_url('admin.php?page=pbsg-new-tutorial')); ?>">
          <?php esc_html_e('Add your first tutorial.', 'pb-split-guide'); ?>
        </a>
      </p>
    </div>
  <?php else : ?>

    <?php if ($transfer_enabled) : ?>
    <div style="margin-bottom:14px;">
      <label>
        <input type="checkbox" id="pbsg-select-all-tutorials" />
        Select all
      </label>
      <button type="button" class="button" id="pbsg-bulk-transfer" style="margin-left:10px;" disabled>
        Transfer Selected
      </button>
    </div>
    <?php endif; ?>

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
        display:flex;
        align-items:center;
        gap:8px;
        flex-wrap:wrap;
      }

      .pbsg-admin-tutorial-actions{
        display:flex;
        gap:10px;
        flex-wrap:wrap;
        align-items:center;
      }
    </style>

    <div class="pbsg-admin-tutorials-grid">
      <?php foreach ($tutorials as $item) : ?>
        <div class="pbsg-admin-tutorial-card"
             data-post-id="<?php echo esc_attr($item['id']); ?>"
             data-post-title="<?php echo esc_attr($item['title']); ?>">

          <div class="pbsg-admin-tutorial-thumb" style="position:relative;">
            <?php if ($transfer_enabled && $item['is_owner']) : ?>
            <input type="checkbox" aria-label="Select Tutorial Checkbox" class="pbsg-tutorial-checkbox"
                   value="<?php echo esc_attr($item['id']); ?>"
                   data-title="<?php echo esc_attr($item['title']); ?>"
                   style="position:absolute; top:8px; right:8px; z-index:2; width:18px; height:18px; cursor:pointer;" />
            <?php endif; ?>
            <a href="<?php echo esc_url($item['link']); ?>" style="display:block; width:100%; height:100%;">
              <img
                src="<?php echo esc_url($item['cover']); ?>"
                alt="<?php echo esc_attr($item['title']); ?>"
              />
            </a>
          </div>

          <div class="pbsg-admin-tutorial-body">
            <h2 class="pbsg-admin-tutorial-title">
              <a href="<?php echo esc_url($item['link']); ?>">
                <?php echo esc_html($item['title']); ?>
              </a>
            </h2>

            <div class="pbsg-admin-tutorial-meta">
              <span class="pbsg-owner-badge <?php echo $item['is_owner'] ? 'pbsg-owner-badge--self' : ''; ?>">
                <?php echo esc_html($item['owner_name']); ?>
              </span>
              <?php if ($item['status'] !== 'publish') : ?>
                <span style="font-size:12px;">(<?php echo esc_html(ucfirst($item['status'])); ?>)</span>
              <?php endif; ?>
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

              <?php if ($transfer_enabled && $item['is_owner']) : ?>
                <button type="button" class="button pbsg-transfer-single"
                        data-post-id="<?php echo esc_attr($item['id']); ?>"
                        data-post-title="<?php echo esc_attr($item['title']); ?>">
                  Transfer
                </button>
              <?php endif; ?>

              <?php /* Export: plain form POST → AJAX handler streams file download */ ?>
              <form method="post"
                    action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
                    style="display:inline;">
                <input type="hidden" name="action"  value="pbsg_export_tutorial" />
                <input type="hidden" name="nonce"   value="<?php echo esc_attr($export_nonce); ?>" />
                <input type="hidden" name="post_id" value="<?php echo esc_attr($item['id']); ?>" />
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
  var ajaxUrl     = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
  var importNonce = <?php echo wp_json_encode( $import_nonce ); ?>;

  $( '#pbsg-import-btn' ).on( 'click', function() {
    var file    = $( '#pbsg-import-file' ).prop( 'files' )[0];
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
