<?php
declare(strict_types=1);

/**
 * PBSG_Embed_Check — Server-side URL embeddability detection.
 *
 * Performs a HEAD request to determine whether a URL can be embedded
 * in an iframe (X-Frame-Options / CSP frame-ancestors) and whether
 * it points to a document (extension / Content-Type).
 *
 * @package PB_Split_Guide
 * @since   0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PBSG_Embed_Check {

    /**
     * Document extensions detected from URL path.
     */
    private const DOC_EXTENSION_PATTERN = '/\.(pdf|docx?|xlsx?|pptx?|csv|tiff?)$/i';

    /**
     * Content-Type patterns that indicate a document.
     */
    private const DOC_MIME_PATTERN = '/(pdf|msword|officedocument|ms-excel|ms-powerpoint|csv)/i';

    /**
     * Office MIME types eligible for Google Docs Viewer.
     */
    private const OFFICE_MIMES = [
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/csv',
    ];

    /**
     * Hostnames that are known to provide embeddable players.
     * Their watch/landing pages block iframes (X-Frame-Options: SAMEORIGIN),
     * but their embed endpoints work fine. Skip the HEAD request for these.
     */
    private const KNOWN_EMBEDDABLE_HOSTS = [
        'youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com',
        'youtu.be',
        'vimeo.com', 'player.vimeo.com',
        'dailymotion.com', 'www.dailymotion.com',
        'ted.com', 'www.ted.com', 'embed.ted.com',
    ];

    /**
     * Hostnames known to refuse iframe embedding in ways a HEAD request
     * cannot reliably detect (e.g. server answers HEAD with relaxed headers
     * but blocks the actual GET, or blocks only student networks).
     *
     * Extendable via the `pbsg_embed_denied_hosts` filter so site admins can
     * add known-bad databases without editing plugin code.
     *
     * Any match immediately marks the URL as non-embeddable → the frontend
     * promotes it to the popup-window fallback tier. No HEAD request is made.
     */
    private const DENY_HOSTS_DEFAULT = [
        'libraryupei.ca',
        'www.libraryupei.ca',
    ];

    /**
     * Transient key prefix for view-time cached embeddability results.
     *
     * Bumped to v3 after fixing the header-read bug. Previous implementation
     * did `(array) wp_remote_retrieve_headers(...)` which produces garbage
     * keys from WordPress's CaseInsensitiveDictionary — every X-Frame-Options
     * and CSP lookup silently returned empty, and every non-4xx/5xx URL was
     * classified as embeddable. Incrementing this prefix orphans entries
     * written by the broken check.
     */
    private const CACHE_PREFIX = 'pbsg_embed_v3_';

    /**
     * Check whether a URL is embeddable and whether it is a document.
     *
     * @param string $url The URL to check.
     * @return array{embeddable: bool, is_document_url: bool}
     */
    public static function check( string $url ): array {
        $result = [
            'embeddable'      => true,
            'is_document_url' => false,
        ];

        // Known video platforms: their watch pages block framing but their
        // embed endpoints work. Trust them without a HEAD request.
        $host = strtolower( parse_url( $url, PHP_URL_HOST ) ?: '' );
        if ( in_array( $host, self::KNOWN_EMBEDDABLE_HOSTS, true ) ) {
            return $result;
        }

        // Host deny-list: known iframe-blockers where HEAD would lie.
        // Skip HTTP entirely — push straight to popup fallback.
        if ( in_array( $host, self::get_denied_hosts(), true ) ) {
            $result['embeddable'] = false;
            return $result;
        }

        // Detect document extension from URL path
        $url_path = strtolower( parse_url( $url, PHP_URL_PATH ) ?: '' );
        if ( preg_match( self::DOC_EXTENSION_PATTERN, $url_path ) ) {
            $result['is_document_url'] = true;
        }

        // ── HEAD probe ──
        // Cheap first. Many servers expose X-Frame-Options / CSP here.
        $head = wp_remote_head( $url, [
            'timeout'     => 5,
            'redirection' => 3,
            'user-agent'  => 'Mozilla/5.0 (compatible; PBSplitGuide/1.0)',
        ] );

        if ( is_wp_error( $head ) ) {
            // Network-level failure (DNS, timeout). Cannot determine; stay
            // optimistic and let the client-side 8s watchdog handle runtime
            // failures. Punishing transient upstream glitches would cause
            // long-tail false positives on otherwise-embeddable content.
            return $result;
        }

        $head_status = (int) wp_remote_retrieve_response_code( $head );
        self::detect_document_mime_from_response( $head, $result );

        $head_verdict = self::evaluate_framing_from_response( $head );
        if ( $head_verdict === false ) {
            // Explicit block on HEAD — definitive, no GET needed.
            $result['embeddable'] = false;
            return $result;
        }
        // Note: we DELIBERATELY do NOT short-circuit on an explicit-allow
        // HEAD verdict. A server can send ALLOW-FROM / ALLOWALL headers on
        // HEAD while still returning 4xx/5xx on GET (e.g. login-gated
        // resources, deleted pages, upstream 502s). We always fall through
        // to the GET probe so a non-2xx response body is treated as
        // non-embeddable regardless of what the HEAD headers claimed.

        // ── GET probe ──
        // Triggered for ALL paths that don't have a definitive HEAD block:
        //   • HEAD returned 2xx with no framing headers,
        //   • HEAD returned 2xx with explicit-allow framing headers,
        //   • HEAD returned a non-2xx status (4xx/5xx), which by itself is
        //     a strong "iframe can't render this" signal, but we still GET
        //     to see if the server merely rejects HEAD (e.g. 405) while
        //     accepting GET.
        //
        // This is the generic path that catches auth-gated enterprise
        // sites (SharePoint, Okta-protected pages, Workspace apps, etc.)
        // WITHOUT hardcoding any hosts: if anonymous anyone-can-iframe
        // rendering isn't possible, the server will tell us one way or
        // another.
        $get = wp_remote_get( $url, [
            'timeout'     => 8,
            'redirection' => 5,
            'user-agent'  => 'Mozilla/5.0 (compatible; PBSplitGuide/1.0)',
        ] );

        if ( is_wp_error( $get ) ) {
            // GET network failure. Tie-break on HEAD status:
            //   • HEAD was 2xx → no block seen anywhere; stay optimistic.
            //   • HEAD was 4xx/5xx → server already refused the anonymous
            //     request; treat as non-embeddable.
            if ( $head_status >= 400 ) {
                $result['embeddable'] = false;
            }
            return $result;
        }

        $get_status = (int) wp_remote_retrieve_response_code( $get );
        self::detect_document_mime_from_response( $get, $result );

        $get_verdict = self::evaluate_framing_from_response( $get );
        if ( $get_verdict === false ) {
            $result['embeddable'] = false;
            return $result;
        }

        // Non-2xx GET (after following up to 5 redirects) — the resource is
        // not served to anonymous requests, so the iframe can't render it.
        if ( $get_status >= 400 ) {
            $result['embeddable'] = false;
            return $result;
        }

        // Both HEAD and GET returned 2xx with no blocking signals.
        return $result;
    }

    /**
     * Evaluate XFO + CSP frame-ancestors on a wp_remote_* response.
     *
     * IMPORTANT: Uses wp_remote_retrieve_header() rather than
     * (array) wp_remote_retrieve_headers(). The latter returns a
     * WpOrg\Requests\Utility\CaseInsensitiveDictionary whose (array)-cast
     * keys are PHP-internal property slots ("\0*\0data"), NOT header names
     * — so raw array-key lookups silently return empty in production,
     * even though they would work in a plain-array unit-test stub.
     *
     * @param array|\WP_Error $response wp_remote_head/get response.
     * @return bool|null True = explicitly allowed, false = explicitly blocked,
     *                   null = ambiguous / no framing signal at all.
     */
    private static function evaluate_framing_from_response( $response ): ?bool {
        $xfo = strtolower( (string) wp_remote_retrieve_header( $response, 'x-frame-options' ) );
        if ( $xfo === 'deny' || $xfo === 'sameorigin' ) {
            return false;
        }

        $csp = strtolower( (string) wp_remote_retrieve_header( $response, 'content-security-policy' ) );
        if ( preg_match( '/frame-ancestors\s/', $csp ) ) {
            if ( strpos( $csp, '*' ) !== false ) {
                return true;
            }
            return false;
        }

        // Non-blocking XFO variants (ALLOW-FROM, ALLOWALL) count as an
        // explicit "allowed" signal.
        if ( $xfo !== '' ) {
            return true;
        }

        return null;
    }

    /**
     * Inspect the response's Content-Type and set is_document_url when it
     * looks like a document. Mutates $result in place.
     *
     * Uses wp_remote_retrieve_header() for the same case-insensitivity
     * reason documented on evaluate_framing_from_response().
     *
     * @param array|\WP_Error $response
     * @param array           $result
     */
    private static function detect_document_mime_from_response( $response, array &$result ): void {
        $ctype = (string) wp_remote_retrieve_header( $response, 'content-type' );
        if ( $ctype && preg_match( self::DOC_MIME_PATTERN, $ctype ) ) {
            $result['is_document_url'] = true;
        }
    }

    /**
     * Office file extensions that should use Google Docs Viewer.
     * Used as a fallback when the server MIME detector returns a generic type
     * (e.g. application/zip or application/octet-stream) for .docx/.xlsx/.pptx.
     */
    private const OFFICE_EXTENSIONS = '/\.(docx?|xlsx?|pptx?|csv)$/i';

    /**
     * Generate a Google Docs Viewer URL for office-type files.
     *
     * Returns empty string for PDFs (browsers handle natively), non-office files,
     * and local development domains.
     *
     * Checks both MIME type and file extension so that servers whose PHP fileinfo
     * extension returns application/zip or application/octet-stream for .docx/.xlsx
     * still get the viewer URL.
     *
     * @param string $file_url The public URL of the file.
     * @param string $mime     The MIME type of the file.
     * @return string          Viewer URL, or empty string.
     */
    public static function viewer_url( string $file_url, string $mime ): string {
        if ( empty( $file_url ) ) {
            return '';
        }

        // PDFs are rendered natively by browsers
        if ( stripos( $mime, 'pdf' ) !== false ) {
            return '';
        }

        $url_path       = parse_url( $file_url, PHP_URL_PATH ) ?: '';
        $is_office_mime = in_array( $mime, self::OFFICE_MIMES, true );
        $is_office_ext  = (bool) preg_match( self::OFFICE_EXTENSIONS, $url_path );

        if ( ! $is_office_mime && ! $is_office_ext ) {
            return '';
        }

        return 'https://docs.google.com/viewerng/viewer?url=' . rawurlencode( $file_url ) . '&embedded=true';
    }

    /**
     * Resolve the current deny-list (defaults + filter additions).
     *
     * Site admins can extend the list via the `pbsg_embed_denied_hosts` filter:
     *
     *     add_filter('pbsg_embed_denied_hosts', function (array $hosts): array {
     *         $hosts[] = 'some-database.example.com';
     *         return $hosts;
     *     });
     *
     * @return array<int, string> Lowercase hostnames.
     */
    public static function get_denied_hosts(): array {
        $hosts = self::DENY_HOSTS_DEFAULT;

        if ( function_exists( 'apply_filters' ) ) {
            $filtered = apply_filters( 'pbsg_embed_denied_hosts', $hosts );
            if ( is_array( $filtered ) ) {
                $hosts = $filtered;
            }
        }

        return array_values( array_unique( array_map( 'strtolower', array_filter(
            $hosts,
            static fn( $h ): bool => is_string( $h ) && $h !== ''
        ) ) ) );
    }

    /**
     * Transient-cached variant of {@see check()}. Re-runs the embeddability
     * probe at most once per TTL per URL, so frontend page-loads can refresh
     * the verdict without hammering the upstream on every view.
     *
     * Useful when the save-time check was a false positive (HEAD succeeded but
     * live traffic is blocked) — the first cache miss after publish picks up
     * the correct answer and is reused for subsequent views.
     *
     * @param string $url The URL to check.
     * @param int    $ttl Cache lifetime in seconds. Default 3600 (1 hour).
     * @return array{embeddable: bool, is_document_url: bool}
     */
    public static function check_cached( string $url, int $ttl = 3600 ): array {
        $key = self::CACHE_PREFIX . md5( $url );

        if ( function_exists( 'get_transient' ) ) {
            $cached = get_transient( $key );
            if ( is_array( $cached ) && isset( $cached['embeddable'], $cached['is_document_url'] ) ) {
                return [
                    'embeddable'      => (bool) $cached['embeddable'],
                    'is_document_url' => (bool) $cached['is_document_url'],
                ];
            }
        }

        $result = self::check( $url );

        if ( function_exists( 'set_transient' ) ) {
            set_transient( $key, $result, max( 60, $ttl ) );
        }

        return $result;
    }

    /**
     * Resolve the effective embeddability flags for a tutorial URL at view
     * time, defending against missing save-time data.
     *
     * This is the critical defense-in-depth layer: older tutorials saved
     * before the embed-check feature existed have no `embeddable` /
     * `is_document_url` keys in their meta. The student-side renderer
     * defaults missing flags to `embeddable: true`, which means a URL
     * like https://libraryupei.ca/… (known to refuse iframes) would take
     * the iframe render path and show the browser's "refused to connect"
     * error instead of the popup fallback card.
     *
     * For deny-listed hosts this fallback is free (no HTTP). For unknown
     * hosts the HEAD result is cached in a transient, so subsequent
     * page-loads reuse the verdict without re-probing the upstream.
     *
     * @param string    $url                     The tutorial URL. Empty → optimistic defaults.
     * @param bool|null $saved_embeddable        Saved flag from meta, or null if absent.
     * @param bool|null $saved_is_document_url   Saved flag from meta, or null if absent.
     * @return array{embeddable: bool, is_document_url: bool}
     */
    public static function resolve_flags(
        string $url,
        ?bool $saved_embeddable,
        ?bool $saved_is_document_url
    ): array {
        // No URL to probe — keep optimistic defaults so non-URL tutorials
        // (files, YouTube IDs, empty) don't trigger spurious checks.
        if ( $url === '' ) {
            return [
                'embeddable'      => true,
                'is_document_url' => false,
            ];
        }

        // Saved flags are INTENTIONALLY NOT short-circuited here. Prior
        // implementations of check() had a header-read bug that cached
        // `embeddable: true` into post_meta for URLs whose servers use
        // X-Frame-Options or CSP frame-ancestors. Trusting those saved
        // flags means the student sees a blank "refused to connect"
        // iframe with no popup fallback — the exact class of failure we
        // are trying to eliminate.
        //
        // Instead always consult check_cached(). The transient cache
        // keeps per-view cost bounded: first miss triggers HEAD+GET,
        // subsequent misses across all students reuse the cached verdict
        // for up to 1 hour. save_meta() warms the cache at publish-time
        // via check_cached() so the first student view is also cached.
        return self::check_cached( $url );
    }

    /**
     * Check if a hostname is a local development domain.
     *
     * @param string $host Hostname to check.
     * @return bool
     */
    private static function is_local_host( string $host ): bool {
        if ( in_array( $host, [ 'localhost', '127.0.0.1' ], true ) ) {
            return true;
        }
        if ( str_ends_with( $host, '.test' ) || str_ends_with( $host, '.local' ) ) {
            return true;
        }
        return false;
    }
}
