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

    // Store completion timestamp if not already stored
    $existing = get_user_meta($user_id, $meta_key, true);
    if (empty($existing)) {
      update_user_meta($user_id, $meta_key, current_time('timestamp'));
      $existing = get_user_meta($user_id, $meta_key, true);
    }

    wp_send_json_success([
      'tutorial_id' => $page_id,
      'completed_ts' => (int)$existing,
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

    // Optional name override
    $name = isset($_GET['name']) ? sanitize_text_field(wp_unslash($_GET['name'])) : '';
    $name = trim($name);

    if ($name === '') {
      $u = get_userdata($user_id);
      $name = $u ? $u->display_name : 'Student';
    }

    $tutorial_title = get_the_title($page_id);
    $date_str = date_i18n(get_option('date_format'), $completed_ts);

    self::output_pdf($name, $tutorial_title, $date_str, $page_id);
    exit;
  }

  private static function output_pdf($student_name, $tutorial_title, $date_str, $tutorial_id) {
    if (!class_exists('TCPDF')) {
      wp_die('TCPDF not installed. Run: composer require tecnickcom/tcpdf', 'Server Error', ['response' => 500]);
    }

    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('PB Split Guide');
    $pdf->SetAuthor('UPEI Library');
    $pdf->SetTitle('Certificate of Completion');
    $pdf->SetMargins(20, 20, 20);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();

    // Simple layout (you can brand later)
    $pdf->SetFont('helvetica', 'B', 30);
    $pdf->Cell(0, 16, 'Certificate of Completion', 0, 1, 'C');

    $pdf->Ln(8);
    $pdf->SetFont('helvetica', '', 16);
    $pdf->MultiCell(0, 10, 'This certifies that', 0, 'C', false, 1);

    $pdf->SetFont('helvetica', 'B', 22);
    $pdf->MultiCell(0, 12, $student_name, 0, 'C', false, 1);

    $pdf->Ln(2);
    $pdf->SetFont('helvetica', '', 16);
    $pdf->MultiCell(0, 10, 'has successfully completed the tutorial:', 0, 'C', false, 1);

    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->MultiCell(0, 12, $tutorial_title, 0, 'C', false, 1);

    $pdf->Ln(6);
    $pdf->SetFont('helvetica', '', 14);
    $pdf->MultiCell(0, 8, 'Completion date: ' . $date_str, 0, 'C', false, 1);

    $pdf->Ln(10);
    $pdf->SetFont('helvetica', '', 12);
    $pdf->MultiCell(0, 7, 'UPEI Library – Guide on the Side', 0, 'C', false, 1);

    $safe = sanitize_title($tutorial_title);
    $filename = "certificate-{$safe}-{$tutorial_id}.pdf";

    nocache_headers();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');

    echo $pdf->Output($filename, 'S');
  }
}