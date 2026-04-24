<?php
declare(strict_types=1);

/**
 * Factory for creating H5P content records from simplified quiz definitions.
 *
 * This is a thin adapter on top of H5P's own saveContent() API —
 * content created here is indistinguishable from content created
 * via the native H5P editor.
 */
final class PBSG_H5P_Factory
{
    /** Supported quiz types and their H5P library machine names. */
    private const LIBRARY_MAP = [
        'multichoice'  => 'H5P.MultiChoice',
        'blanks'       => 'H5P.Blanks',
        'singlechoice' => 'H5P.MultiChoice', // Issue 3: Uses MultiChoice with singlePoint=true
    ];

    /**
     * Public accessor for the library name corresponding to a quiz type.
     * Used by the main plugin to detect when a branch question's type has
     * changed (so the existing H5P content row needs to be replaced).
     *
     * @param string $type Quiz type ('multichoice', 'blanks', 'singlechoice').
     * @return string|null H5P library machine name, or null for unknown types.
     */
    public static function get_library_for_type(string $type): ?string
    {
        return self::LIBRARY_MAP[$type] ?? null;
    }

    /**
     * H5P display options disable bitmask (Issue 7a).
     * DISABLE_DOWNLOAD=2 + DISABLE_COPYRIGHT=8 = 10
     * Turns OFF: download button, copyright button.
     * NOTE: DISABLE_FRAME (1) must NOT be set — it breaks iframe rendering.
     * NOTE: DISABLE_EMBED (4) must NOT be set — our tutorial loads quizzes
     *       via h5p_embed endpoint, which checks this bit and refuses to serve.
     */
    private const DISABLE_FLAGS = 1;

    /**
     * Create an H5P content record from a simplified quiz definition.
     *
     * @param array  $quiz       Simplified quiz data (type, question, answers, etc.)
     * @param string $post_title Tutorial post title (for auto-naming).
     * @param int    $step_index Step number (1-based, for auto-naming).
     * @param string $step_title Optional custom step title.
     * @return int|\WP_Error     New H5P content ID or error.
     */
    public static function create(array $quiz, string $post_title = '', int $step_index = 0, string $step_title = ''): int|\WP_Error
    {
        $type = $quiz['type'] ?? '';

        if (!isset(self::LIBRARY_MAP[$type])) {
            return new \WP_Error('pbsg_invalid_quiz_type', "Unsupported quiz type: {$type}");
        }

        $library_name = self::LIBRARY_MAP[$type];
        $library = self::resolve_library($library_name);

        if (is_wp_error($library)) {
            return $library;
        }

        $params = self::build_params($type, $quiz);

        if (is_wp_error($params)) {
            return $params;
        }

        $h5p_title = self::generate_title($post_title, $step_index, $step_title);

        $core = self::get_h5p_core();

        if (is_wp_error($core)) {
            return $core;
        }

        $content = [
            'library'    => $library,
            'params'     => wp_json_encode($params),
            'metadata'   => [
                'title'   => $h5p_title,
                'license' => 'U',
            ],
            'disable'    => self::DISABLE_FLAGS,
        ];

        $content_id = $core->saveContent($content);

        if (!$content_id || $content_id < 1) {
            return new \WP_Error('pbsg_h5p_save_failed', 'H5P saveContent() returned no ID.');
        }

        // Critical: register library dependencies and generate filtered cache.
        // Without this, H5P can't load the required JS/CSS and shows "Content unavailable."
        self::filter_and_cache($core, (int) $content_id, $library, $params);

        return (int) $content_id;
    }

    /**
     * Update an existing H5P content record with new quiz data (Issue 5).
     *
     * @param int   $h5p_id  Existing H5P content ID.
     * @param array $quiz    Simplified quiz definition.
     * @param string $title  Optional title override.
     * @return int|\WP_Error The H5P content ID or error.
     */
    public static function update(int $h5p_id, array $quiz, string $title = ''): int|\WP_Error
    {
        $type = $quiz['type'] ?? '';

        if (!isset(self::LIBRARY_MAP[$type])) {
            return new \WP_Error('pbsg_invalid_quiz_type', "Unsupported quiz type: {$type}");
        }

        $library_name = self::LIBRARY_MAP[$type];
        $library = self::resolve_library($library_name);

        if (is_wp_error($library)) {
            return $library;
        }

        $params = self::build_params($type, $quiz);

        if (is_wp_error($params)) {
            return $params;
        }

        $core = self::get_h5p_core();

        if (is_wp_error($core)) {
            return $core;
        }

        // Fetch existing title if none provided
        if (!$title) {
            global $wpdb;
            $title = $wpdb->get_var($wpdb->prepare(
                "SELECT title FROM {$wpdb->prefix}h5p_contents WHERE id = %d",
                $h5p_id
            )) ?: 'Quiz';
        }

        $content = [
            'id'         => $h5p_id,  // Passing id triggers UPDATE instead of INSERT
            'library'    => $library,
            'params'     => wp_json_encode($params),
            'metadata'   => [
                'title'   => $title,
                'license' => 'U',
            ],
            'disable'    => self::DISABLE_FLAGS,
        ];

        try {
            $core->saveContent($content);

            // Re-register library dependencies and regenerate filtered cache
            self::filter_and_cache($core, $h5p_id, $library, $params);

            return $h5p_id;
        } catch (\Exception $e) {
            return new \WP_Error('pbsg_h5p_update_failed', $e->getMessage());
        }
    }

    /**
     * Reverse-map H5P parameters JSON back to our simplified quiz schema.
     *
     * @param string $library_name H5P library machine name (e.g. 'H5P.MultiChoice').
     * @param string $params_json  Raw parameters JSON from wp_h5p_contents.
     * @return array|null          Simplified quiz array, or null if unsupported.
     */
    public static function reverse(string $library_name, string $params_json): ?array
    {
        $params = json_decode($params_json, true);
        if (!is_array($params)) {
            return null;
        }

        // Strip version suffix (e.g. "H5P.MultiChoice 1.16" -> "H5P.MultiChoice")
        $base_name = preg_replace('/\s+\d+(\.\d+)*$/', '', $library_name) ?? $library_name;

        switch ($base_name) {
            case 'H5P.MultiChoice':
                // Check if this is actually a single-choice (singlePoint=true)
                $behaviour = $params['behaviour'] ?? [];
                if (!empty($behaviour['singlePoint'])) {
                    return self::reverse_singlechoice_from_mc($params);
                }
                return self::reverse_multichoice($params);

            case 'H5P.Blanks':
                return self::reverse_blanks($params);

            case 'H5P.SingleChoiceSet':
                // Legacy: old singlechoice content created before Issue 3
                return self::reverse_singlechoice_legacy($params);

            default:
                return null;
        }
    }

    /**
     * Check whether the H5P plugin is available and functional.
     */
    public static function is_h5p_available(): bool
    {
        return class_exists('H5P_Plugin')
            && method_exists('H5P_Plugin', 'get_instance');
    }

    // === Parameter Builders ===================================================

    private static function build_params(string $type, array $quiz)
    {
        switch ($type) {
            case 'multichoice':
                return self::build_multichoice_params($quiz);

            case 'blanks':
                return self::build_blanks_params($quiz);

            case 'singlechoice':
                return self::build_singlechoice_params($quiz);

            default:
                return new \WP_Error('pbsg_unknown_type', "No parameter builder for type: {$type}");
        }
    }

    private static function build_multichoice_params(array $quiz): array
    {
        $raw_q    = $quiz['question'] ?? '';
        $question = preg_match('/^\s*<(?:p|div|h[1-6])\b/i', $raw_q) ? $raw_q : '<p>' . $raw_q . '</p>';

        $answers = [];
        foreach (($quiz['answers'] ?? []) as $a) {
            $is_correct = !empty($a['correct']);
            $raw_text   = $a['text'] ?? '';
            $answer_text = preg_match('/^\s*<(?:p|div|h[1-6])\b/i', $raw_text) ? $raw_text : '<p>' . $raw_text . '</p>';
            $answers[] = [
                'text'            => $answer_text,
                'correct'         => $is_correct,
                'tipsAndFeedback' => [
                    'tip'               => '',
                    'chosenFeedback'    => $is_correct ? '<div>Correct!</div>' : '<div>Try again.</div>',
                    'notChosenFeedback' => '',
                ],
            ];
        }

        return [
            'question'        => $question,
            'answers'         => $answers,
            'overallFeedback' => [['from' => 0, 'to' => 100, 'feedback' => '']],
            'behaviour'       => [
                'enableRetry'               => true,
                'enableSolutionsButton'     => false,  // Issue 7b: OFF by default
                'enableCheckButton'         => true,
                'type'                      => 'auto',
                'singlePoint'               => false,
                'randomAnswers'             => true,
                'showSolutionsRequiresInput' => true,
                'confirmCheckDialog'        => false,
                'confirmRetryDialog'        => false,
                'autoCheck'                 => false,
                'passPercentage'            => 100,
            ],
            'UI' => self::multichoice_ui_strings(),
        ];
    }

    /**
     * Issue 3: Single Choice now builds H5P.MultiChoice with singlePoint=true.
     * Same answer structure as multichoice but exactly one correct answer.
     */
    private static function build_singlechoice_params(array $quiz): array
    {
        $raw_q    = $quiz['question'] ?? '';
        $question = preg_match('/^\s*<(?:p|div|h[1-6])\b/i', $raw_q) ? $raw_q : '<p>' . $raw_q . '</p>';
        $correct  = $quiz['correct_answer'] ?? '';
        $wrongs   = $quiz['wrong_answers'] ?? [];

        $answers = [];
        // Correct answer
        $correct_text = preg_match('/^\s*<(?:p|div|h[1-6])\b/i', $correct) ? $correct : '<p>' . $correct . '</p>';
        $answers[] = [
            'text'            => $correct_text,
            'correct'         => true,
            'tipsAndFeedback' => [
                'tip'               => '',
                'chosenFeedback'    => '<div>Correct!</div>',
                'notChosenFeedback' => '',
            ],
        ];
        // Wrong answers
        foreach ($wrongs as $w) {
            $wrong_text = preg_match('/^\s*<(?:p|div|h[1-6])\b/i', $w) ? $w : '<p>' . $w . '</p>';
            $answers[] = [
                'text'            => $wrong_text,
                'correct'         => false,
                'tipsAndFeedback' => [
                    'tip'               => '',
                    'chosenFeedback'    => '<div>Try again.</div>',
                    'notChosenFeedback' => '',
                ],
            ];
        }

        return [
            'question'        => $question,
            'answers'         => $answers,
            'overallFeedback' => [['from' => 0, 'to' => 100, 'feedback' => '']],
            'behaviour'       => [
                'enableRetry'               => true,
                'enableSolutionsButton'     => false,  // Issue 7b
                'enableCheckButton'         => true,
                'type'                      => 'auto',
                'singlePoint'               => true,   // KEY: forces single-answer mode
                'randomAnswers'             => true,
                'showSolutionsRequiresInput' => true,
                'confirmCheckDialog'        => false,
                'confirmRetryDialog'        => false,
                'autoCheck'                 => false,
                'passPercentage'            => 100,
            ],
            'UI' => self::multichoice_ui_strings(),
        ];
    }

    private static function build_blanks_params(array $quiz): array
    {
        $sentence = $quiz['sentence'] ?? '';
        $questions = ['<p>' . $sentence . '</p>'];

        return [
            'questions'       => $questions,
            'overallFeedback' => [['from' => 0, 'to' => 100, 'feedback' => '']],
            'showSolutions'   => 'Show solution',
            'tryAgain'        => 'Retry',
            'checkAnswer'     => 'Check',
            'submitAnswer'    => 'Submit',
            'notFilledOut'    => 'Please fill in all blanks to get feedback',
            'answerIsCorrect' => "':ans' is correct",
            'answerIsWrong'   => "':ans' is wrong",
            'answeredCorrectly'   => 'Filled in correctly',
            'answeredIncorrectly' => 'Filled in incorrectly',
            'solutionLabel'   => 'Correct answer:',
            'inputLabel'      => 'Blank input @num of @total',
            'inputHasTipLabel' => 'Tip available',
            'tipLabel'        => 'Tip',
            'behaviour'       => [
                'enableRetry'               => true,
                'enableSolutionsButton'     => false,  // Issue 7b: OFF by default
                'enableCheckButton'         => true,
                'autoCheck'                 => false,
                'caseSensitive'             => (bool) ($quiz['case_sensitive'] ?? true),
                'showSolutionsRequiresInput' => true,  // Issue 7c: require all fields
                'separateLines'             => false,
                'confirmCheckDialog'        => false,
                'confirmRetryDialog'        => false,
                'acceptSpellingErrors'      => (bool) ($quiz['accept_typos'] ?? false),
            ],
            'scoreBarLabel'         => 'You got :num out of :total points',
            'a11yCheck'             => 'Check the answers',
            'a11yShowSolution'      => 'Show the solution',
            'a11yRetry'             => 'Retry the task',
            'a11yCheckingModeHeader' => 'Checking Mode',
        ];
    }

    // === Reverse Mappers =====================================================

    private static function reverse_multichoice(array $params): array
    {
        $question = self::unwrap_p($params['question'] ?? '');

        $answers = [];
        foreach (($params['answers'] ?? []) as $a) {
            $answers[] = [
                'text'    => self::unwrap_p($a['text'] ?? ''),
                'correct' => !empty($a['correct']),
            ];
        }

        return [
            'type'     => 'multichoice',
            'question' => $question,
            'answers'  => $answers,
        ];
    }

    /**
     * Reverse MultiChoice with singlePoint=true back to singlechoice schema.
     */
    private static function reverse_singlechoice_from_mc(array $params): array
    {
        $question = self::unwrap_p($params['question'] ?? '');
        $correct  = '';
        $wrongs   = [];

        foreach (($params['answers'] ?? []) as $a) {
            $text = self::unwrap_p($a['text'] ?? '');
            if (!empty($a['correct'])) {
                $correct = $text;
            } else {
                $wrongs[] = $text;
            }
        }

        return [
            'type'           => 'singlechoice',
            'question'       => $question,
            'correct_answer' => $correct,
            'wrong_answers'  => $wrongs,
        ];
    }

    /**
     * Legacy reverse for old H5P.SingleChoiceSet content (before Issue 3).
     */
    private static function reverse_singlechoice_legacy(array $params): array
    {
        $choices = $params['choices'] ?? [];
        $first   = $choices[0] ?? [];

        $question       = self::unwrap_p($first['question'] ?? '');
        $all_answers    = $first['answers'] ?? [];
        $correct_answer = !empty($all_answers) ? self::unwrap_p($all_answers[0]) : '';

        $wrong_answers = [];
        for ($i = 1; $i < count($all_answers); $i++) {
            $wrong_answers[] = self::unwrap_p($all_answers[$i]);
        }

        return [
            'type'           => 'singlechoice',
            'question'       => $question,
            'correct_answer' => $correct_answer,
            'wrong_answers'  => $wrong_answers,
        ];
    }

    /**
     * Strip a single outer <p>…</p> wrapper while preserving inner HTML.
     * Used by reverse mappers to undo the <p> wrap added by build_*_params().
     * Does NOT strip_tags — inner HTML (e.g. <b>, <i>) is intentionally kept.
     *
     * @param string $text Raw text possibly wrapped in <p>…</p>.
     * @return string      Text with at most one outer <p> layer removed.
     */
    private static function unwrap_p(string $text): string
    {
        $text = trim($text);
        if (preg_match('/^<p>(.*)<\/p>$/s', $text, $m)) {
            return trim($m[1]);
        }
        return $text;
    }

    private static function reverse_blanks(array $params): array
    {
        $questions = $params['questions'] ?? [];
        $sentence  = is_array($questions) && !empty($questions) ? $questions[0] : '';
        $behaviour = $params['behaviour'] ?? [];

        return [
            'type'           => 'blanks',
            'sentence'       => strip_tags($sentence),
            'case_sensitive' => (bool) ($behaviour['caseSensitive'] ?? true),
            'accept_typos'   => (bool) ($behaviour['acceptSpellingErrors'] ?? false),
        ];
    }

    // === Post-Save Processing =================================================

    /**
     * After saveContent(), call H5PCore::filterParameters() to:
     *  1. Resolve and save library dependencies (wp_h5p_contents_libraries)
     *  2. Generate the filtered/cached parameters
     *  3. Generate the content slug
     *
     * Without this step, H5P shows "Content unavailable" because it can't
     * load the required sub-library JS/CSS (H5P.Question, H5P.JoubelUI, etc.).
     */
    private static function filter_and_cache($core, int $content_id, array $library, $params): void
    {
        // Build the content object that filterParameters() expects
        $content = [
            'id'       => $content_id,
            'library'  => $library,
            'params'   => is_string($params) ? $params : wp_json_encode($params),
            'filtered' => '',  // Force regeneration
            'slug'     => '',  // Force regeneration
        ];

        try {
            $core->filterParameters($content);
        } catch (\Exception $e) {
            // Non-fatal: content is saved but may not render until next edit.
            error_log('PBSG: filterParameters() failed for H5P #' . $content_id . ': ' . $e->getMessage());
        }
    }

    // === Shared UI Strings ====================================================

    private static function multichoice_ui_strings(): array
    {
        return [
            'checkAnswerButton'  => 'Check',
            'submitAnswerButton' => 'Submit',
            'showSolutionButton' => 'Show solution',
            'tryAgainButton'     => 'Retry',
            'tipsLabel'          => 'Show tip',
            'scoreBarLabel'      => 'You got :num out of :total points',
            'tipAvailable'       => 'Tip available',
            'feedbackAvailable'  => 'Feedback available',
            'readFeedback'       => 'Read feedback',
            'wrongAnswer'        => 'Wrong answer',
            'correctAnswer'      => 'Correct answer',
            'shouldCheck'        => 'Should have been checked',
            'shouldNotCheck'     => 'Should not have been checked',
            'noInput'            => 'Please answer before viewing the solution',
            'a11yCheck'          => 'Check the answers',
            'a11yShowSolution'   => 'Show the solution',
            'a11yRetry'          => 'Retry the task',
        ];
    }

    // === Helpers ==============================================================

    private static function resolve_library(string $machine_name)
    {
        if (!self::is_h5p_available()) {
            return new \WP_Error(
                'pbsg_h5p_unavailable',
                'The H5P plugin is not active. Install and activate it to use inline quiz authoring.'
            );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'h5p_libraries';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, major_version, minor_version
             FROM {$table}
             WHERE name = %s
             ORDER BY major_version DESC, minor_version DESC
             LIMIT 1",
            $machine_name
        ), ARRAY_A);

        if (!$row) {
            return new \WP_Error(
                'pbsg_h5p_library_missing',
                "H5P library '{$machine_name}' is not installed. Please install it via H5P Content > Libraries."
            );
        }

        return [
            'libraryId'    => (int) $row['id'],
            'machineName'  => $row['name'],
            'majorVersion' => (int) $row['major_version'],
            'minorVersion' => (int) $row['minor_version'],
        ];
    }

    public static function get_h5p_core()
    {
        if (!self::is_h5p_available()) {
            return new \WP_Error('pbsg_h5p_unavailable', 'H5P plugin is not active.');
        }

        $plugin = \H5P_Plugin::get_instance();
        $core   = $plugin->get_h5p_instance('core');

        if (!$core) {
            return new \WP_Error('pbsg_h5p_core_failed', 'Could not get H5P core instance.');
        }

        return $core;
    }

    public static function generate_title(string $post_title, int $step_index, string $step_title): string
    {
        $post_title = trim($post_title);
        $step_title = trim($step_title);

        if ($post_title && $step_title) {
            return "{$post_title} — {$step_title}";
        }

        if ($post_title && $step_index > 0) {
            return "{$post_title} — Step {$step_index}";
        }

        if ($step_title) {
            return $step_title;
        }

        return 'Inline Quiz (Step ' . max(1, $step_index) . ')';
    }
}
