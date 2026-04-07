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
            <input type="checkbox" class="pbsg-tutorial-checkbox"
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
                <span style="font-size:12px; color:#D4A017;">(<?php echo esc_html(ucfirst($item['status'])); ?>)</span>
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
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

  <?php endif; ?>
</div>
