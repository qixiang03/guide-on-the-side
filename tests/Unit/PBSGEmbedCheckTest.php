<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PBSG_Embed_Check — server-side URL embeddability detection.
 *
 * Verifies X-Frame-Options parsing, CSP frame-ancestors parsing,
 * document-type detection (URL path + Content-Type), and HEAD failure handling.
 *
 * @covers PBSG_Embed_Check
 */
final class PBSGEmbedCheckTest extends TestCase
{
    protected function setUp(): void
    {
        WPStubs::reset();
    }

    // ── Document URL detection via path extension ──

    public function test_detects_pdf_extension_as_document(): void
    {
        WPStubs::$returns['wp_remote_head'] = ['headers' => [], 'response' => ['code' => 200]];

        $result = PBSG_Embed_Check::check('https://example.com/papers/report.pdf');

        $this->assertTrue($result['is_document_url']);
    }

    public function test_detects_docx_extension_as_document(): void
    {
        WPStubs::$returns['wp_remote_head'] = ['headers' => [], 'response' => ['code' => 200]];

        $result = PBSG_Embed_Check::check('https://example.com/files/manual.docx');

        $this->assertTrue($result['is_document_url']);
    }

    public function test_detects_xlsx_extension_as_document(): void
    {
        WPStubs::$returns['wp_remote_head'] = ['headers' => [], 'response' => ['code' => 200]];

        $result = PBSG_Embed_Check::check('https://example.com/data/budget.xlsx');

        $this->assertTrue($result['is_document_url']);
    }

    public function test_detects_pptx_extension_as_document(): void
    {
        WPStubs::$returns['wp_remote_head'] = ['headers' => [], 'response' => ['code' => 200]];

        $result = PBSG_Embed_Check::check('https://example.com/slides/deck.pptx');

        $this->assertTrue($result['is_document_url']);
    }

    public function test_detects_csv_extension_as_document(): void
    {
        WPStubs::$returns['wp_remote_head'] = ['headers' => [], 'response' => ['code' => 200]];

        $result = PBSG_Embed_Check::check('https://example.com/export/data.csv');

        $this->assertTrue($result['is_document_url']);
    }

    public function test_html_url_is_not_document(): void
    {
        WPStubs::$returns['wp_remote_head'] = ['headers' => [], 'response' => ['code' => 200]];

        $result = PBSG_Embed_Check::check('https://example.com/page.html');

        $this->assertFalse($result['is_document_url']);
    }

    public function test_url_with_query_string_still_detects_extension(): void
    {
        WPStubs::$returns['wp_remote_head'] = ['headers' => [], 'response' => ['code' => 200]];

        $result = PBSG_Embed_Check::check('https://example.com/file.pdf?v=2&token=abc');

        $this->assertTrue($result['is_document_url']);
    }

    public function test_url_without_path_extension_is_not_document(): void
    {
        WPStubs::$returns['wp_remote_head'] = ['headers' => [], 'response' => ['code' => 200]];

        $result = PBSG_Embed_Check::check('https://example.com/api/documents/123');

        $this->assertFalse($result['is_document_url']);
    }

    // ── X-Frame-Options detection ──

    public function test_xfo_deny_marks_not_embeddable(): void
    {
        WPStubs::$returns['wp_remote_head'] = [
            'headers' => ['x-frame-options' => 'DENY'],
            'response' => ['code' => 200],
        ];

        $result = PBSG_Embed_Check::check('https://example.com/page');

        $this->assertFalse($result['embeddable']);
    }

    public function test_xfo_sameorigin_marks_not_embeddable(): void
    {
        WPStubs::$returns['wp_remote_head'] = [
            'headers' => ['x-frame-options' => 'SAMEORIGIN'],
            'response' => ['code' => 200],
        ];

        $result = PBSG_Embed_Check::check('https://example.com/page');

        $this->assertFalse($result['embeddable']);
    }

    public function test_xfo_allowfrom_stays_embeddable(): void
    {
        WPStubs::$returns['wp_remote_head'] = [
            'headers' => ['x-frame-options' => 'ALLOW-FROM https://other.com'],
            'response' => ['code' => 200],
        ];

        $result = PBSG_Embed_Check::check('https://example.com/page');

        $this->assertTrue($result['embeddable']);
    }

    public function test_no_xfo_header_stays_embeddable(): void
    {
        WPStubs::$returns['wp_remote_head'] = [
            'headers' => [],
            'response' => ['code' => 200],
        ];

        $result = PBSG_Embed_Check::check('https://example.com/page');

        $this->assertTrue($result['embeddable']);
    }

    public function test_xfo_case_insensitive(): void
    {
        WPStubs::$returns['wp_remote_head'] = [
            'headers' => ['x-frame-options' => 'deny'],
            'response' => ['code' => 200],
        ];

        $result = PBSG_Embed_Check::check('https://example.com/page');

        $this->assertFalse($result['embeddable']);
    }

    // ── CSP frame-ancestors detection ──

    public function test_csp_frame_ancestors_self_marks_not_embeddable(): void
    {
        WPStubs::$returns['wp_remote_head'] = [
            'headers' => ['content-security-policy' => "frame-ancestors 'self'"],
            'response' => ['code' => 200],
        ];

        $result = PBSG_Embed_Check::check('https://example.com/page');

        $this->assertFalse($result['embeddable']);
    }

    public function test_csp_frame_ancestors_wildcard_stays_embeddable(): void
    {
        WPStubs::$returns['wp_remote_head'] = [
            'headers' => ['content-security-policy' => 'frame-ancestors *'],
            'response' => ['code' => 200],
        ];

        $result = PBSG_Embed_Check::check('https://example.com/page');

        $this->assertTrue($result['embeddable']);
    }

    public function test_csp_without_frame_ancestors_stays_embeddable(): void
    {
        WPStubs::$returns['wp_remote_head'] = [
            'headers' => ['content-security-policy' => "default-src 'self'"],
            'response' => ['code' => 200],
        ];

        $result = PBSG_Embed_Check::check('https://example.com/page');

        $this->assertTrue($result['embeddable']);
    }

    // ── Content-Type MIME detection ──

    public function test_content_type_pdf_marks_document(): void
    {
        WPStubs::$returns['wp_remote_head'] = [
            'headers' => ['content-type' => 'application/pdf'],
            'response' => ['code' => 200],
        ];

        $result = PBSG_Embed_Check::check('https://example.com/api/file');

        $this->assertTrue($result['is_document_url']);
    }

    public function test_content_type_officedocument_marks_document(): void
    {
        WPStubs::$returns['wp_remote_head'] = [
            'headers' => ['content-type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'response' => ['code' => 200],
        ];

        $result = PBSG_Embed_Check::check('https://example.com/api/doc');

        $this->assertTrue($result['is_document_url']);
    }

    public function test_content_type_html_not_document(): void
    {
        WPStubs::$returns['wp_remote_head'] = [
            'headers' => ['content-type' => 'text/html; charset=utf-8'],
            'response' => ['code' => 200],
        ];

        $result = PBSG_Embed_Check::check('https://example.com/page');

        $this->assertFalse($result['is_document_url']);
    }

    // ── HEAD failure handling ──

    public function test_head_failure_returns_optimistic_defaults(): void
    {
        WPStubs::$returns['wp_remote_head'] = new WP_Error('http_request_failed', 'Connection timed out');

        $result = PBSG_Embed_Check::check('https://unreachable.example.com/page');

        $this->assertTrue($result['embeddable']);
        $this->assertFalse($result['is_document_url']);
    }

    // ── Combined scenarios ──

    public function test_xfo_deny_with_pdf_extension(): void
    {
        WPStubs::$returns['wp_remote_head'] = [
            'headers' => ['x-frame-options' => 'DENY'],
            'response' => ['code' => 200],
        ];

        $result = PBSG_Embed_Check::check('https://example.com/report.pdf');

        $this->assertFalse($result['embeddable']);
        $this->assertTrue($result['is_document_url']);
    }

    public function test_both_xfo_and_csp_blocking(): void
    {
        WPStubs::$returns['wp_remote_head'] = [
            'headers' => [
                'x-frame-options' => 'DENY',
                'content-security-policy' => "frame-ancestors 'none'",
            ],
            'response' => ['code' => 200],
        ];

        $result = PBSG_Embed_Check::check('https://example.com/page');

        $this->assertFalse($result['embeddable']);
    }

    // ── Viewer URL generation ──

    public function test_viewer_url_for_office_mime(): void
    {
        $url = PBSG_Embed_Check::viewer_url(
            'https://example.com/uploads/report.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        );

        $this->assertStringStartsWith('https://docs.google.com/viewerng/viewer?url=', $url);
        $this->assertStringContainsString(rawurlencode('https://example.com/uploads/report.docx'), $url);
        $this->assertStringContainsString('&embedded=true', $url);
    }

    public function test_viewer_url_for_pdf_mime(): void
    {
        $url = PBSG_Embed_Check::viewer_url(
            'https://example.com/uploads/doc.pdf',
            'application/pdf'
        );

        // PDFs are rendered natively by browsers — no viewer needed
        $this->assertEmpty($url);
    }

    public function test_viewer_url_includes_localhost(): void
    {
        $url = PBSG_Embed_Check::viewer_url(
            'http://localhost/uploads/report.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        );

        $this->assertStringContainsString('docs.google.com/viewerng/viewer', $url);
        $this->assertStringContainsString(rawurlencode('http://localhost/uploads/report.docx'), $url);
    }

    public function test_viewer_url_includes_test_domain(): void
    {
        $url = PBSG_Embed_Check::viewer_url(
            'http://pressbooks.test/uploads/report.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        );

        $this->assertStringContainsString('docs.google.com/viewerng/viewer', $url);
        $this->assertStringContainsString(rawurlencode('http://pressbooks.test/uploads/report.docx'), $url);
    }

    public function test_viewer_url_includes_local_domain(): void
    {
        $url = PBSG_Embed_Check::viewer_url(
            'http://mysite.local/uploads/report.xlsx',
            'application/vnd.ms-excel'
        );

        $this->assertStringContainsString('docs.google.com/viewerng/viewer', $url);
        $this->assertStringContainsString(rawurlencode('http://mysite.local/uploads/report.xlsx'), $url);
    }

    public function test_viewer_url_skips_non_office_mime(): void
    {
        $url = PBSG_Embed_Check::viewer_url(
            'https://example.com/image.png',
            'image/png'
        );

        $this->assertEmpty($url);
    }

    public function test_viewer_url_for_csv(): void
    {
        $url = PBSG_Embed_Check::viewer_url(
            'https://example.com/data.csv',
            'text/csv'
        );

        $this->assertStringStartsWith('https://docs.google.com/viewerng/viewer?url=', $url);
    }

    public function test_viewer_url_for_pptx(): void
    {
        $url = PBSG_Embed_Check::viewer_url(
            'https://example.com/slides.pptx',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation'
        );

        $this->assertStringStartsWith('https://docs.google.com/viewerng/viewer?url=', $url);
    }

    public function test_check_returns_embeddable_for_youtube_watch_url(): void
    {
        $result = PBSG_Embed_Check::check('https://www.youtube.com/watch?v=uJ235iTBkh0');

        $this->assertTrue($result['embeddable']);
        $this->assertFalse($result['is_document_url']);
    }

    public function test_check_returns_embeddable_for_youtu_be_shortlink(): void
    {
        $result = PBSG_Embed_Check::check('https://youtu.be/uJ235iTBkh0');

        $this->assertTrue($result['embeddable']);
    }

    public function test_check_returns_embeddable_for_vimeo(): void
    {
        $result = PBSG_Embed_Check::check('https://vimeo.com/123456789');

        $this->assertTrue($result['embeddable']);
    }

    public function test_check_returns_embeddable_for_ted(): void
    {
        $result = PBSG_Embed_Check::check('https://www.ted.com/talks/some_talk');

        $this->assertTrue($result['embeddable']);
    }
}
