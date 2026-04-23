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

    // ── Host deny-list (known iframe-blockers) ──

    public function test_default_deny_list_includes_libraryupei(): void
    {
        $result = PBSG_Embed_Check::check('https://libraryupei.ca/some-page');

        $this->assertFalse($result['embeddable']);
    }

    public function test_deny_list_skips_head_request(): void
    {
        WPStubs::$returns['wp_remote_head'] = [
            'headers' => ['x-frame-options' => 'ALLOWALL'],
            'response' => ['code' => 200],
        ];

        PBSG_Embed_Check::check('https://libraryupei.ca/page');

        $this->assertFalse(
            WPStubs::wasCalled('wp_remote_head'),
            'Host in deny-list must bypass the HEAD request entirely'
        );
    }

    public function test_filter_can_extend_deny_list(): void
    {
        WPStubs::$returns['filters'] = [
            'pbsg_embed_denied_hosts' => static function (array $hosts): array {
                $hosts[] = 'blocked.example.com';
                return $hosts;
            },
        ];

        $result = PBSG_Embed_Check::check('https://blocked.example.com/resource');

        $this->assertFalse($result['embeddable']);
    }

    public function test_non_denied_host_still_performs_head_check(): void
    {
        WPStubs::$returns['wp_remote_head'] = [
            'headers' => [],
            'response' => ['code' => 200],
        ];

        PBSG_Embed_Check::check('https://example.com/page');

        $this->assertTrue(WPStubs::wasCalled('wp_remote_head'));
    }

    // ── check_cached(): transient-backed re-check ──

    public function test_check_cached_stores_result_in_transient(): void
    {
        WPStubs::$returns['wp_remote_head'] = [
            'headers' => ['x-frame-options' => 'DENY'],
            'response' => ['code' => 200],
        ];

        $result = PBSG_Embed_Check::check_cached('https://example.com/blocked', 3600);

        $this->assertFalse($result['embeddable']);
        $this->assertTrue(
            WPStubs::wasCalled('set_transient'),
            'check_cached() must persist result in a transient'
        );
    }

    public function test_check_cached_returns_cached_without_http(): void
    {
        // Seed the transient cache — URL hash-based key.
        $url   = 'https://example.com/cached';
        $key   = 'pbsg_embed_v3_' . md5($url);
        WPStubs::$returns['transients'] = [
            $key => ['embeddable' => false, 'is_document_url' => false],
        ];
        WPStubs::$returns['wp_remote_head'] = [
            'headers' => [],
            'response' => ['code' => 200],
        ];

        $result = PBSG_Embed_Check::check_cached($url, 3600);

        $this->assertFalse($result['embeddable']);
        $this->assertFalse(
            WPStubs::wasCalled('wp_remote_head'),
            'Cached hit must not trigger an HTTP request'
        );
    }

    // ── resolve_flags(): view-time fallback for missing saved flags ──

    public function test_resolve_flags_ignores_saved_flags_and_always_checks(): void
    {
        // Saved flags are NOT trusted. They may have been written by a
        // prior broken check() (the (array)$headers CaseInsensitiveDictionary
        // bug) that cached `embeddable: true` for URLs whose servers actually
        // set X-Frame-Options. Transient caching keeps the perf cost bounded.
        WPStubs::$returns['wp_remote_head'] = [
            'headers' => ['x-frame-options' => 'DENY'],
            'response' => ['code' => 200],
        ];

        $result = PBSG_Embed_Check::resolve_flags(
            'https://example.com/page',
            true,   // stale saved_embeddable — must be ignored
            false
        );

        $this->assertFalse(
            $result['embeddable'],
            'Current upstream verdict (XFO: DENY) must override stale saved embeddable=true'
        );
        $this->assertTrue(
            WPStubs::wasCalled('wp_remote_head'),
            'resolve_flags must always consult check_cached (no saved-flag short-circuit)'
        );
    }

    public function test_resolve_flags_falls_back_to_check_for_missing_saved_flags_on_deny_list(): void
    {
        // Exact screenshot scenario: branch-question URL to libraryupei.ca,
        // saved meta predates the embed-check feature (no flags stored).
        // View-time resolution must mark it non-embeddable without any HEAD.
        WPStubs::$returns['wp_remote_head'] = [
            'headers' => ['x-frame-options' => 'ALLOWALL'],
            'response' => ['code' => 200],
        ];

        $result = PBSG_Embed_Check::resolve_flags(
            'https://libraryupei.ca/research',
            null,   // no saved flag
            null
        );

        $this->assertFalse(
            $result['embeddable'],
            'Missing saved flag for deny-listed host must resolve to non-embeddable at view time'
        );
        $this->assertFalse(
            WPStubs::wasCalled('wp_remote_head'),
            'Deny-list host resolves without HTTP even on view-time fallback'
        );
    }

    public function test_resolve_flags_empty_url_returns_optimistic_defaults(): void
    {
        $result = PBSG_Embed_Check::resolve_flags('', null, null);

        $this->assertTrue($result['embeddable']);
        $this->assertFalse($result['is_document_url']);
        $this->assertFalse(
            WPStubs::wasCalled('wp_remote_head'),
            'Empty URL must not trigger any network call'
        );
    }

    public function test_resolve_flags_denylist_overrides_saved_true_flag(): void
    {
        // Screenshot scenario: a tutorial was saved BEFORE libraryupei.ca was
        // added to DENY_HOSTS_DEFAULT, so its post meta carries a stale
        // `embeddable: true`. At view time, resolve_flags must still return
        // `embeddable: false` — the deny-list is a hard policy override that
        // admins can extend via the pbsg_embed_denied_hosts filter WITHOUT
        // requiring every tutorial to be re-saved.
        WPStubs::$returns['wp_remote_head'] = [
            'headers'  => ['x-frame-options' => 'ALLOWALL'],
            'response' => ['code' => 200],
        ];

        $result = PBSG_Embed_Check::resolve_flags(
            'https://libraryupei.ca/research',
            true,   // stale saved_embeddable (from before deny-list add)
            false   // stale saved_is_document_url
        );

        $this->assertFalse(
            $result['embeddable'],
            'Deny-list must override saved embeddable=true at view time'
        );
        $this->assertFalse(
            WPStubs::wasCalled('wp_remote_head'),
            'Deny-list host resolves without HTTP even when saved flags are present'
        );
    }

    public function test_resolve_flags_denylist_overrides_saved_true_flag_on_www_subdomain(): void
    {
        // Confirms host normalization matches the default list's www. variant.
        $result = PBSG_Embed_Check::resolve_flags(
            'https://www.libraryupei.ca/some-resource',
            true,
            false
        );

        $this->assertFalse(
            $result['embeddable'],
            'www.libraryupei.ca must also resolve as non-embeddable'
        );
    }

    public function test_resolve_flags_denylist_respects_runtime_filter(): void
    {
        // Admins extending the deny-list at runtime (e.g. a new database that
        // students report as broken) must take effect on ALREADY-saved tutorials
        // without requiring them to be re-saved.
        WPStubs::$returns['filters']['pbsg_embed_denied_hosts'] = function (array $hosts): array {
            $hosts[] = 'blocked-by-admin.example';
            return $hosts;
        };

        try {
            $result = PBSG_Embed_Check::resolve_flags(
                'https://blocked-by-admin.example/page',
                true,
                false
            );

            $this->assertFalse(
                $result['embeddable'],
                'Runtime-filter-added host must override saved flags at view time'
            );
        } finally {
            unset(WPStubs::$returns['filters']['pbsg_embed_denied_hosts']);
        }
    }

    public function test_resolve_flags_never_short_circuits_on_saved_true_when_upstream_blocks(): void
    {
        // Self-healing property: a URL whose save-time flag is `true` but
        // whose live XFO/CSP says DENY must resolve as non-embeddable on
        // every view. This is the primary protection against stale or
        // buggy save-time data.
        WPStubs::$returns['wp_remote_head'] = [
            'headers'  => ['x-frame-options' => 'DENY'],
            'response' => ['code' => 200],
        ];

        $result = PBSG_Embed_Check::resolve_flags(
            'https://ordinary-site.example/page',
            true,
            false
        );

        $this->assertFalse(
            $result['embeddable'],
            'Live XFO: DENY must take precedence over stale saved embeddable=true'
        );
    }

    // ── Generic failure detection: auth-gated, conditional-header, redirected sites ──
    //
    // These cases model enterprise-auth sites (SharePoint, Okta, generic SSO,
    // any site returning 4xx on anonymous HEAD) WITHOUT hardcoding host names.
    // For a student, "server refused the anonymous HEAD" is a strong signal
    // that the iframe can't render it either.

    public function test_head_4xx_marks_not_embeddable(): void
    {
        // Server returned 401/403/404 on HEAD — no framing headers, but the
        // resource is inaccessible to anonymous requests. Iframe can't work.
        WPStubs::$returns['wp_remote_head'] = [
            'headers'  => [],
            'response' => ['code' => 403],
        ];

        $result = PBSG_Embed_Check::check('https://example.com/auth-gated');

        $this->assertFalse(
            $result['embeddable'],
            '4xx HEAD must be treated as non-embeddable regardless of framing headers'
        );
    }

    public function test_head_5xx_marks_not_embeddable(): void
    {
        WPStubs::$returns['wp_remote_head'] = [
            'headers'  => [],
            'response' => ['code' => 502],
        ];

        $result = PBSG_Embed_Check::check('https://example.com/broken');

        $this->assertFalse(
            $result['embeddable'],
            '5xx HEAD must be treated as non-embeddable'
        );
    }

    public function test_head_405_method_not_allowed_triggers_get_fallback(): void
    {
        // Many servers reject HEAD but accept GET. Must not false-positive.
        WPStubs::$returns['wp_remote_head'] = [
            'headers'  => [],
            'response' => ['code' => 405],
        ];
        WPStubs::$returns['wp_remote_get'] = [
            'headers'  => ['x-frame-options' => 'DENY'],
            'response' => ['code' => 200],
        ];

        $result = PBSG_Embed_Check::check('https://example.com/head-rejected');

        $this->assertFalse($result['embeddable']);
        $this->assertTrue(
            WPStubs::wasCalled('wp_remote_get'),
            'HEAD 405 must fall back to GET to verify framing policy'
        );
    }

    public function test_head_ok_without_framing_headers_triggers_get_fallback(): void
    {
        // Models SharePoint/SSO case: HEAD returns 200 with no framing
        // headers (server only sets them on GET), but GET reveals XFO.
        WPStubs::$returns['wp_remote_head'] = [
            'headers'  => [],
            'response' => ['code' => 200],
        ];
        WPStubs::$returns['wp_remote_get'] = [
            'headers'  => ['x-frame-options' => 'SAMEORIGIN'],
            'response' => ['code' => 200],
        ];

        $result = PBSG_Embed_Check::check('https://example.com/conditional-headers');

        $this->assertFalse(
            $result['embeddable'],
            'HEAD 200 without framing headers must fall back to GET'
        );
        $this->assertTrue(
            WPStubs::wasCalled('wp_remote_get'),
            'GET fallback must run when HEAD is ambiguous'
        );
    }

    public function test_head_with_explicit_xfo_skips_get_fallback(): void
    {
        // HEAD already gave us a definitive answer — no need to double-probe.
        WPStubs::$returns['wp_remote_head'] = [
            'headers'  => ['x-frame-options' => 'DENY'],
            'response' => ['code' => 200],
        ];

        $result = PBSG_Embed_Check::check('https://example.com/explicit-xfo');

        $this->assertFalse($result['embeddable']);
        $this->assertFalse(
            WPStubs::wasCalled('wp_remote_get'),
            'Definitive HEAD result must not trigger redundant GET'
        );
    }

    public function test_head_ok_get_also_clean_stays_embeddable(): void
    {
        // Legitimate embeddable site: HEAD 200 no framing, GET 200 no framing.
        // Must NOT become a false positive.
        WPStubs::$returns['wp_remote_head'] = [
            'headers'  => [],
            'response' => ['code' => 200],
        ];
        WPStubs::$returns['wp_remote_get'] = [
            'headers'  => [],
            'response' => ['code' => 200],
        ];

        $result = PBSG_Embed_Check::check('https://example.com/legit-embeddable');

        $this->assertTrue(
            $result['embeddable'],
            'Sites clean on both HEAD and GET must stay embeddable (no false positive)'
        );
    }

    public function test_head_network_error_stays_optimistic(): void
    {
        // Genuine network failure (DNS, timeout). We cannot determine; stay
        // optimistic and let the client-side watchdog handle it. This preserves
        // prior behavior and avoids punishing transient upstream glitches.
        WPStubs::$returns['wp_remote_head'] = new WP_Error('http_request_failed', 'timeout');

        $result = PBSG_Embed_Check::check('https://example.com/unreachable');

        $this->assertTrue(
            $result['embeddable'],
            'WP_Error on HEAD must remain optimistic (caller-side watchdog covers it)'
        );
    }
}
