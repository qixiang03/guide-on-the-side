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

        // Detect document extension from URL path
        $url_path = strtolower( parse_url( $url, PHP_URL_PATH ) ?: '' );
        if ( preg_match( self::DOC_EXTENSION_PATTERN, $url_path ) ) {
            $result['is_document_url'] = true;
        }

        // HEAD request to check framing headers
        $response = wp_remote_head( $url, [
            'timeout'     => 5,
            'redirection' => 3,
            'user-agent'  => 'Mozilla/5.0 (compatible; PBSplitGuide/1.0)',
        ] );

        if ( is_wp_error( $response ) ) {
            return $result;
        }

        $headers = wp_remote_retrieve_headers( $response );

        // Check X-Frame-Options
        $xfo = strtolower( (string) ( $headers['x-frame-options'] ?? '' ) );
        if ( $xfo === 'deny' || $xfo === 'sameorigin' ) {
            $result['embeddable'] = false;
        }

        // Check Content-Security-Policy frame-ancestors
        $csp = strtolower( (string) ( $headers['content-security-policy'] ?? '' ) );
        if ( preg_match( '/frame-ancestors\s/', $csp ) && strpos( $csp, '*' ) === false ) {
            $result['embeddable'] = false;
        }

        // Detect document MIME from Content-Type header
        $content_type = wp_remote_retrieve_header( $response, 'content-type' );
        if ( $content_type && preg_match( self::DOC_MIME_PATTERN, $content_type ) ) {
            $result['is_document_url'] = true;
        }

        return $result;
    }

    /**
     * Generate a Google Docs Viewer URL for office-type files.
     *
     * Returns empty string for non-office MIME types, PDF (browsers handle natively),
     * and local development domains.
     *
     * @param string $file_url The public URL of the file.
     * @param string $mime     The MIME type of the file.
     * @return string          Viewer URL, or empty string.
     */
    public static function viewer_url( string $file_url, string $mime ): string {
        if ( empty( $file_url ) || empty( $mime ) ) {
            return '';
        }

        // PDFs are rendered natively by browsers
        if ( stripos( $mime, 'pdf' ) !== false ) {
            return '';
        }

        // Only office MIME types
        if ( ! in_array( $mime, self::OFFICE_MIMES, true ) ) {
            return '';
        }

        // Skip local dev domains
        $host = parse_url( $file_url, PHP_URL_HOST ) ?: '';
        if ( self::is_local_host( $host ) ) {
            return '';
        }

        return 'https://docs.google.com/gview?url=' . rawurlencode( $file_url ) . '&embedded=true';
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
