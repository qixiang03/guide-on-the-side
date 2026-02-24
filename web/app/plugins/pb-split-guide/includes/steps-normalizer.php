<?php
declare(strict_types=1);

/**
 * Normalize PB Split Guide "steps" data.
 * Pure PHP (no WordPress runtime required) so it can be unit-tested easily.
 */
final class PBSG_Steps_Normalizer
{
    /**
     * @param mixed $steps Decoded JSON (expected array of step arrays)
     * @return array<int, array<string, mixed>>
     */
    public static function normalize($steps): array
    {
        if (!is_array($steps)) {
            return [];
        }

        $clean = [];

        foreach ($steps as $s) {
            if (!is_array($s)) {
                continue;
            }

            $h5p_id = isset($s['h5p_id']) ? (int)$s['h5p_id'] : 0;
            $title  = isset($s['title']) ? self::sanitize_text((string)$s['title']) : '';

            // Backward compatible: older data used "url"
            $legacy_url = isset($s['url']) ? self::sanitize_url((string)$s['url']) : '';

            $tutorial_type = isset($s['tutorial_type']) ? self::sanitize_key((string)$s['tutorial_type']) : '';
            $tutorial_url  = isset($s['tutorial_url']) ? self::sanitize_url((string)$s['tutorial_url']) : '';
            $tutorial_attachment_id = isset($s['tutorial_attachment_id']) ? (int)$s['tutorial_attachment_id'] : 0;
            if ($tutorial_attachment_id < 0) $tutorial_attachment_id = 0;

            // If old "url" exists and new fields empty, migrate it
            if (!$tutorial_type && !$tutorial_url && $legacy_url) {
                $tutorial_type = 'url';
                $tutorial_url  = $legacy_url;
            }

            // Normalize type
            if (!in_array($tutorial_type, ['url', 'file'], true)) {
                $tutorial_type = ($tutorial_attachment_id > 0)
                    ? 'file'
                    : (($tutorial_url || $legacy_url) ? 'url' : '');
            }

            // If file type, keep only attachment_id (url derived later)
            if ($tutorial_type === 'file') {
                if ($tutorial_attachment_id <= 0) {
                    // If attachment id missing, fallback to url (if present)
                    if ($tutorial_url) {
                        $tutorial_type = 'url';
                    } else {
                        $tutorial_type = '';
                    }
                }
            }

            // If url type, ensure we store url
            if ($tutorial_type === 'url') {
                if (!$tutorial_url && $legacy_url) $tutorial_url = $legacy_url;
                if (!$tutorial_url) $tutorial_type = '';
            }

            // Skip empty rows
            $has_any = ($h5p_id > 0) || ($title !== '') || ($tutorial_type !== '');
            if (!$has_any) continue;

            $clean[] = [
                'title' => $title,
                'h5p_id' => $h5p_id,

                // New fields
                'tutorial_type' => $tutorial_type,
                'tutorial_url'  => $tutorial_type === 'url' ? $tutorial_url : '',
                'tutorial_attachment_id' => $tutorial_type === 'file' ? $tutorial_attachment_id : 0,

                // Legacy key (optional)
                'url' => $tutorial_type === 'url' ? $tutorial_url : '',
            ];
        }

        return $clean;
    }

    private static function sanitize_text(string $text): string
    {
        $text = trim($text);
        return preg_replace('/\s+/', ' ', $text) ?? '';
    }

    private static function sanitize_key(string $key): string
    {
        $key = strtolower(trim($key));
        return preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '';
    }

    private static function sanitize_url(string $url): string
    {
        $url = trim($url);
        if ($url === '') return '';
        return preg_match('#^https?://#i', $url) ? $url : '';
    }
}