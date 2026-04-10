<?php
if (!defined('ABSPATH')) exit;

class PBSG_Certificate {

  const NONCE_ACTION = 'pbsg_certificate';
  const META_PREFIX  = 'pbsg_completed_'; // user meta: pbsg_completed_{page_id} => timestamp

  public static function init() {
    // Logged-in only
    add_action('wp_ajax_pbsg_mark_completed', [__CLASS__, 'ajax_mark_completed']);
    add_action('wp_ajax_pbsg_download_certificate', [__CLASS__, 'ajax_download_certificate']);
  }

  private static function is_valid_tutorial_page($page_id) {
    if (!$page_id) return false;
    $template = get_post_meta($page_id, '_wp_page_template', true);
    return ($template === PB_Split_Guide_Plugin::TEMPLATE_SLUG);
  }

  public static function ajax_mark_completed() {
    if (!is_user_logged_in()) {
      wp_send_json_error(['message' => 'Not logged in'], 401);
    }

    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
      wp_send_json_error(['message' => 'Bad nonce'], 403);
    }

    $page_id = isset($_POST['tutorial_id']) ? absint($_POST['tutorial_id']) : 0;
    if (!$page_id || !self::is_valid_tutorial_page($page_id)) {
      wp_send_json_error(['message' => 'Invalid tutorial'], 400);
    }

    $user_id = get_current_user_id();
    $meta_key = self::META_PREFIX . $page_id;

    // Always store the latest completion timestamp
    $completed_ts = current_time('timestamp');
    update_user_meta($user_id, $meta_key, $completed_ts);

    wp_send_json_success([
      'tutorial_id'  => $page_id,
      'completed_ts' => (int) $completed_ts,
    ]);
  }

  public static function ajax_download_certificate() {
    if (!is_user_logged_in()) {
      wp_die('Not logged in', 'Unauthorized', ['response' => 401]);
    }

    $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
      wp_die('Bad nonce', 'Forbidden', ['response' => 403]);
    }

    $page_id = isset($_GET['tutorial_id']) ? absint($_GET['tutorial_id']) : 0;
    if (!$page_id || !self::is_valid_tutorial_page($page_id)) {
      wp_die('Invalid tutorial', 'Bad Request', ['response' => 400]);
    }

    $user_id = get_current_user_id();
    $meta_key = self::META_PREFIX . $page_id;
    $completed_ts = get_user_meta($user_id, $meta_key, true);

    if (empty($completed_ts)) {
      // Don’t allow certificate unless completed
      wp_die('Tutorial not completed yet', 'Forbidden', ['response' => 403]);
    }

    $completed_ts = is_numeric($completed_ts) ? (int)$completed_ts : strtotime($completed_ts);
    if (!$completed_ts) $completed_ts = current_time('timestamp');

    $name = isset($_GET['name']) ? sanitize_text_field(wp_unslash($_GET['name'])) : '';
    $name = trim($name);

    if ($name === '') {
      wp_die('Student name is required', 'Bad Request', ['response' => 400]);
    }

    $final_score = isset($_GET['final_score']) ? floatval($_GET['final_score']) : 0;
    if ($final_score < 0) $final_score = 0;
    if ($final_score > 100) $final_score = 100;

    $tutorial_title = get_the_title($page_id);
    $date_str = date_i18n(get_option('date_format'), $completed_ts);

    self::output_pdf($name, $tutorial_title, $date_str, $page_id, $final_score);
    exit;
  }
  private static function output_pdf($student_name, $tutorial_title, $date_str, $tutorial_id, $final_score = 0) {
    if (!class_exists('TCPDF')) {
      wp_die('TCPDF not installed. Run: composer require tecnickcom/tcpdf', 'Server Error', ['response' => 500]);
    }

    $pdf = new TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
    $pdf->SetCreator('PB Split Guide');
    $pdf->SetAuthor('Guide on the Side');
    $pdf->SetTitle('Certificate of Completion');
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(false, 0);
    $pdf->AddPage();

    $pageWidth  = $pdf->getPageWidth();
    $pageHeight = $pdf->getPageHeight();

    // Background
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect(0, 0, $pageWidth, $pageHeight, 'F');

    // Decorative double border
    $pdf->SetDrawColor(220, 220, 220);
    $pdf->SetLineWidth(0.5);
    $pdf->Rect(6, 6, $pageWidth - 12, $pageHeight - 12);

    $pdf->SetDrawColor(235, 235, 235);
    $pdf->SetLineWidth(0.3);
    $pdf->Rect(14, 14, $pageWidth - 28, $pageHeight - 28);

    // Logo
    $logo_path = plugin_dir_path(dirname(__FILE__)) . 'assets/images/logo.png';
    if (file_exists($logo_path)) {
      // centered logo near the top
      $logo_width  = 115;
      $logo_height = 42;
      $logo_x = ($pageWidth - $logo_width) / 2;
      $pdf->Image($logo_path, $logo_x, 22, $logo_width, $logo_height, 'PNG', '', '', true, 300, '', false, false, 0, false, false, false);
    }

    // Main title
    $pdf->SetTextColor(40, 40, 40);
    $pdf->SetFont('times', '', 26);
    $pdf->SetXY(20, 84);
    $pdf->Cell($pageWidth - 40, 10, 'Certificate of Completion', 0, 1, 'C');

    // Subtitle
    $pdf->SetFont('times', 'I', 14);
    $pdf->SetXY(20, 104);
    $pdf->Cell($pageWidth - 40, 8, 'This is to certify that', 0, 1, 'C');

    // Student name
    $pdf->SetFont('times', 'B', 28);
    $pdf->SetXY(25, 118);
    $pdf->MultiCell($pageWidth - 50, 14, $student_name, 0, 'C', false, 1);

    // Completion text
    $pdf->SetFont('times', 'I', 14);
    $pdf->SetXY(20, 146);
    $pdf->Cell($pageWidth - 40, 8, 'has successfully completed the tutorial', 0, 1, 'C');

    // Tutorial title
    $pdf->SetFont('times', 'B', 22);
    $pdf->SetXY(24, 160);
    $pdf->MultiCell($pageWidth - 48, 14, $tutorial_title, 0, 'C', false, 1);

    // Final score
    $pdf->SetFont('times', '', 15);
    $pdf->SetXY(20, 198);
    $pdf->Cell($pageWidth - 40, 8, 'Final Score: ' . number_format((float) $final_score, 2) . '%', 0, 1, 'C');

    // Completion date
    $pdf->SetTextColor(120, 70, 20);
    $pdf->SetFont('times', 'I', 14);
    $pdf->SetXY(20, 214);
    $pdf->Cell($pageWidth - 40, 8, $date_str, 0, 1, 'C');

    // Footer
    $pdf->SetTextColor(160, 90, 40);
    $pdf->SetFont('times', 'I', 10);
    $pdf->SetXY(20, $pageHeight - 22);
    $pdf->Cell($pageWidth - 40, 6, 'Guide on the Side', 0, 1, 'C');

    $safe = sanitize_title($tutorial_title);
    $filename = "certificate-{$safe}-{$tutorial_id}.pdf";

    nocache_headers();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');

    echo $pdf->Output($filename, 'S');
  }
  
}