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

            // Pre-check: does this step have quiz data? (peek at raw data before full normalization)
            $raw_quiz = isset($s['quiz']) && is_array($s['quiz']) ? $s['quiz'] : null;
            $has_quiz = $raw_quiz && !empty(self::sanitize_key($raw_quiz['type'] ?? ''));

            // Skip empty rows
            $has_any = ($h5p_id > 0) || ($title !== '') || ($tutorial_type !== '') || $has_quiz;
            if (!$has_any) continue;

            $clean_step = [
                'title' => $title,
                'h5p_id' => $h5p_id,

                // New fields
                'tutorial_type' => $tutorial_type,
                'tutorial_url'  => $tutorial_type === 'url' ? $tutorial_url : '',
                'tutorial_attachment_id' => $tutorial_type === 'file' ? $tutorial_attachment_id : 0,

                // Legacy key (optional)
                'url' => $tutorial_type === 'url' ? $tutorial_url : '',
            ];

            // Preserve quiz data for H5P creation (consumed by save_meta, not stored long-term)
            $quiz = isset($s['quiz']) && is_array($s['quiz']) ? $s['quiz'] : null;
            if ($quiz) {
                $quiz_type = self::sanitize_key($quiz['type'] ?? '');
                $clean_quiz = ['type' => $quiz_type];

                switch ($quiz_type) {
                    case 'multichoice':
                        $clean_quiz['question'] = self::sanitize_text($quiz['question'] ?? '');
                        $clean_quiz['answers']  = self::sanitize_mc_answers($quiz['answers'] ?? []);
                        break;

                    case 'blanks':
                        $clean_quiz['sentence']       = self::sanitize_blanks_sentence($quiz['sentence'] ?? '');
                        $clean_quiz['case_sensitive']  = (bool) ($quiz['case_sensitive'] ?? true);
                        $clean_quiz['accept_typos']    = (bool) ($quiz['accept_typos'] ?? false);
                        break;

                    case 'singlechoice':
                        $clean_quiz['question']       = self::sanitize_text($quiz['question'] ?? '');
                        $clean_quiz['correct_answer'] = self::sanitize_text($quiz['correct_answer'] ?? '');
                        $clean_quiz['wrong_answers']  = array_map(
                            [self::class, 'sanitize_text'],
                            is_array($quiz['wrong_answers'] ?? null) ? $quiz['wrong_answers'] : []
                        );
                        break;

                    default:
                        $clean_quiz = null;
                        break;
                }

                if ($clean_quiz && !empty($clean_quiz['type'])) {
                    $clean_step['quiz'] = $clean_quiz;
                }
            }

            $clean[] = $clean_step;
        }

        return $clean;
    }

    /**
     * Sanitize multiple-choice answers array.
     *
     * @param mixed $answers
     * @return array
     */
    private static function sanitize_mc_answers($answers): array
    {
        if (!is_array($answers)) {
            return [];
        }

        $clean = [];
        foreach ($answers as $a) {
            if (!is_array($a)) continue;
            $text = self::sanitize_text($a['text'] ?? '');
            if ($text === '') continue;
            $clean[] = [
                'text'    => $text,
                'correct' => !empty($a['correct']),
            ];
        }
        return $clean;
    }

    /**
     * Sanitize a fill-in-the-blanks sentence.
     * Preserves *asterisk* markers but strips dangerous content.
     *
     * @param string $sentence
     * @return string
     */
    private static function sanitize_blanks_sentence(string $sentence): string
    {
        $sentence = trim($sentence);
        // Strip HTML tags but preserve asterisks, slashes, colons (used for blanks syntax)
        $sentence = strip_tags($sentence);
        return $sentence;
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