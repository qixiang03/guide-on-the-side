<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin My Tutorials page
 *
 * Expected variables before include:
 * - $tutorials : array of tutorial items
 */

$tutorials = isset($tutorials) && is_array($tutorials) ? $tutorials : [];
?>

<div class="wrap pbsg-admin-tutorials-page">
  <h1 style="margin-bottom:6px;">My Tutorials</h1>
  <p style="margin-top:0;color:#646970;">Browse all Guide on the Side tutorials.</p>

  <div class="nav-tab-wrapper" style="margin-bottom:20px;">
    <a href="#" class="nav-tab nav-tab-active">Overview</a>
  </div>

  <?php if (empty($tutorials)) : ?>
    <div class="notice notice-info inline">
      <p>No tutorials found.</p>
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
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

  <?php endif; ?>
</div>