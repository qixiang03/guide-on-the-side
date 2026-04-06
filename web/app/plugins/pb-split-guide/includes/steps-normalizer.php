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

                       // ── Branch / sub-tutorial fields (new structure) ──
            $branch = null;

            if (isset($s['branch']) && is_array($s['branch'])) {
                $raw_branch = $s['branch'];

                $branch_mode = isset($raw_branch['mode']) ? self::sanitize_key((string)$raw_branch['mode']) : 'optional';
                if (!in_array($branch_mode, ['optional', 'mandatory'], true)) {
                    $branch_mode = 'optional';
                }

                $branch_resource_mode = isset($raw_branch['resource_mode'])
                    ? self::sanitize_key((string)$raw_branch['resource_mode'])
                    : 'main';

                if (!in_array($branch_resource_mode, ['main', 'shared', 'per_question'], true)) {
                    $branch_resource_mode = 'main';
                }

                $branch_trigger_attempts = 1;

                $questions = [];
                if (isset($raw_branch['questions']) && is_array($raw_branch['questions'])) {
                    foreach ($raw_branch['questions'] as $q) {
                        if (!is_array($q)) continue;

                        $quiz_type = self::sanitize_key($q['type'] ?? '');
                        $clean_q = ['type' => $quiz_type];

                        switch ($quiz_type) {
                            case 'multichoice':
                                $clean_q['question'] = self::sanitize_text($q['question'] ?? '');
                                $clean_q['answers']  = self::sanitize_mc_answers($q['answers'] ?? []);
                                break;

                            case 'blanks':
                                $clean_q['sentence'] = self::sanitize_blanks_sentence($q['sentence'] ?? '');
                                $clean_q['case_sensitive'] = (bool) ($q['case_sensitive'] ?? false);
                                $clean_q['accept_typos'] = (bool) ($q['accept_typos'] ?? false);
                                break;

                            case 'singlechoice':
                                $clean_q['question'] = self::sanitize_text($q['question'] ?? '');
                                $clean_q['correct_answer'] = self::sanitize_text($q['correct_answer'] ?? '');
                                $clean_q['wrong_answers'] = array_map(
                                    [self::class, 'sanitize_text'],
                                    is_array($q['wrong_answers'] ?? null) ? $q['wrong_answers'] : []
                                );
                                break;

                            default:
                                $clean_q = null;
                                break;
                        }

                        if ($clean_q) {
                            $q_tutorial_type = isset($q['tutorial_type']) ? self::sanitize_key((string)$q['tutorial_type']) : '';
                            $q_tutorial_url  = isset($q['tutorial_url']) ? self::sanitize_url((string)$q['tutorial_url']) : '';
                            $q_tutorial_attachment_id = isset($q['tutorial_attachment_id']) ? (int)$q['tutorial_attachment_id'] : 0;
                            if ($q_tutorial_attachment_id < 0) $q_tutorial_attachment_id = 0;

                            if (!in_array($q_tutorial_type, ['url', 'file'], true)) {
                                $q_tutorial_type = ($q_tutorial_attachment_id > 0)
                                    ? 'file'
                                    : ($q_tutorial_url ? 'url' : '');
                            }

                            if ($q_tutorial_type === 'file' && $q_tutorial_attachment_id <= 0) {
                                $q_tutorial_type = $q_tutorial_url ? 'url' : '';
                            }

                            if ($q_tutorial_type === 'url' && !$q_tutorial_url) {
                                $q_tutorial_type = '';
                            }

                            $clean_q['tutorial_type'] = $q_tutorial_type;
                            $clean_q['tutorial_url'] = $q_tutorial_type === 'url' ? $q_tutorial_url : '';
                            $clean_q['tutorial_attachment_id'] = $q_tutorial_type === 'file' ? $q_tutorial_attachment_id : 0;
                            $clean_q['tutorial_file_name'] = $q_tutorial_type === 'file'
                                ? self::sanitize_text((string)($q['tutorial_file_name'] ?? ''))
                                : '';
                            $clean_q['tutorial_file_url'] = $q_tutorial_type === 'file'
                                ? self::sanitize_url((string)($q['tutorial_file_url'] ?? ''))
                                : '';
                        }

                        $valid = false;

                        switch ($quiz_type) {
                        case 'multichoice':
                            $valid = !empty($clean_q['question']) && !empty($clean_q['answers']);
                            break;

                        case 'blanks':
                            $valid = !empty($clean_q['sentence']);
                            break;

                        case 'singlechoice':
                            $valid = !empty($clean_q['question']) && !empty($clean_q['correct_answer']);
                            break;
                        }

                        if ($clean_q && $valid) {
                        $questions[] = $clean_q;
                        }
                    }
                }

                $branch_tutorial_type = isset($raw_branch['tutorial_type']) ? self::sanitize_key((string)$raw_branch['tutorial_type']) : '';
                $branch_tutorial_url  = isset($raw_branch['tutorial_url']) ? self::sanitize_url((string)$raw_branch['tutorial_url']) : '';
                $branch_tutorial_attachment_id = isset($raw_branch['tutorial_attachment_id']) ? (int)$raw_branch['tutorial_attachment_id'] : 0;
                if ($branch_tutorial_attachment_id < 0) $branch_tutorial_attachment_id = 0;

                if (!in_array($branch_tutorial_type, ['url', 'file'], true)) {
                    $branch_tutorial_type = ($branch_tutorial_attachment_id > 0)
                        ? 'file'
                        : ($branch_tutorial_url ? 'url' : '');
                }

                if ($branch_tutorial_type === 'file' && $branch_tutorial_attachment_id <= 0) {
                    $branch_tutorial_type = $branch_tutorial_url ? 'url' : '';
                }

                if ($branch_tutorial_type === 'url' && !$branch_tutorial_url) {
                    $branch_tutorial_type = '';
                }

                $has_shared_branch_resource = ($branch_tutorial_type !== '');

                $has_per_question_resource = false;
                foreach ($questions as $q) {
                    if (!empty($q['tutorial_type'])) {
                        $has_per_question_resource = true;
                        break;
                    }
                }

                if (
                    !empty($questions) &&
                    (
                        $branch_resource_mode === 'main' ||
                        ($branch_resource_mode === 'shared' && $has_shared_branch_resource) ||
                        ($branch_resource_mode === 'per_question' && $has_per_question_resource)
                    )
                ) {
                    $branch = [
                        'mode' => $branch_mode,
                        'resource_mode' => $branch_resource_mode,
                        'trigger_attempts' => $branch_trigger_attempts,
                        'questions' => $questions,
                        'tutorial_type' => $branch_tutorial_type,
                        'tutorial_url' => $branch_tutorial_type === 'url' ? $branch_tutorial_url : '',
                        'tutorial_attachment_id' => $branch_tutorial_type === 'file' ? $branch_tutorial_attachment_id : 0,
                        'tutorial_file_name' => $branch_tutorial_type === 'file'
                            ? self::sanitize_text((string)($raw_branch['tutorial_file_name'] ?? ''))
                            : '',

                        'tutorial_file_url' => $branch_tutorial_type === 'file'
                            ? self::sanitize_url((string)($raw_branch['tutorial_file_url'] ?? ''))
                            : '',
                    ];
                }
            }

            // Pre-check: does this step have quiz data? (peek at raw data before full normalization)
            $raw_quiz = isset($s['quiz']) && is_array($s['quiz']) ? $s['quiz'] : null;
            $has_quiz = $raw_quiz && !empty(self::sanitize_key($raw_quiz['type'] ?? ''));

            // Skip empty rows
            $has_any = ($h5p_id > 0) || ($title !== '') || ($tutorial_type !== '') || $has_quiz || ($branch !== null);
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

                // Branch / sub-tutorial fields
                'branch' => $branch,
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