<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the branch sub-quiz → H5P conversion helpers.
 *
 * Covers:
 *  - PB_Split_Guide_Plugin::branch_question_to_quiz() — translates branch
 *    question source fields into the quiz schema PBSG_H5P_Factory understands.
 *  - PBSG_H5P_Factory::get_library_for_type() — maps quiz type to H5P library name.
 */
final class PBSGBranchH5PTest extends TestCase
{
    protected function setUp(): void
    {
        WPStubs::reset();
    }

    // ── branch_question_to_quiz: multichoice ────────────────────

    /**
     * @covers PB_Split_Guide_Plugin::branch_question_to_quiz
     */
    public function test_branch_multichoice_translates_correctly(): void
    {
        $bq = [
            'type'     => 'multichoice',
            'question' => 'Pick the correct answer',
            'answers'  => [
                ['text' => 'Option A', 'correct' => true],
                ['text' => 'Option B', 'correct' => false],
                ['text' => 'Option C', 'correct' => false],
            ],
        ];

        $quiz = self::callBranchQuestionToQuiz($bq);

        $this->assertIsArray($quiz);
        $this->assertSame('multichoice', $quiz['type']);
        $this->assertSame('Pick the correct answer', $quiz['question']);
        $this->assertCount(3, $quiz['answers']);
        $this->assertTrue($quiz['answers'][0]['correct']);
        $this->assertSame('Option A', $quiz['answers'][0]['text']);
        $this->assertFalse($quiz['answers'][1]['correct']);
        $this->assertFalse($quiz['answers'][2]['correct']);
    }

    // ── branch_question_to_quiz: singlechoice ───────────────────

    /**
     * @covers PB_Split_Guide_Plugin::branch_question_to_quiz
     */
    public function test_branch_singlechoice_translates_correctly(): void
    {
        $bq = [
            'type'           => 'singlechoice',
            'question'       => 'What colour is the sky?',
            'correct_answer' => 'Blue',
            'wrong_answers'  => ['Red', 'Green'],
        ];

        $quiz = self::callBranchQuestionToQuiz($bq);

        $this->assertIsArray($quiz);
        $this->assertSame('singlechoice', $quiz['type']);
        $this->assertSame('What colour is the sky?', $quiz['question']);
        $this->assertSame('Blue', $quiz['correct_answer']);
        $this->assertSame(['Red', 'Green'], $quiz['wrong_answers']);
    }

    // ── branch_question_to_quiz: blanks ─────────────────────────

    /**
     * @covers PB_Split_Guide_Plugin::branch_question_to_quiz
     */
    public function test_branch_blanks_translates_correctly(): void
    {
        $bq = [
            'type'           => 'blanks',
            'sentence'       => 'The capital of Canada is *Ottawa*.',
            'case_sensitive' => true,
            'accept_typos'   => false,
        ];

        $quiz = self::callBranchQuestionToQuiz($bq);

        $this->assertIsArray($quiz);
        $this->assertSame('blanks', $quiz['type']);
        $this->assertSame('The capital of Canada is *Ottawa*.', $quiz['sentence']);
        $this->assertTrue($quiz['case_sensitive']);
        $this->assertFalse($quiz['accept_typos']);
    }

    // ── branch_question_to_quiz: unsupported type ───────────────

    /**
     * @covers PB_Split_Guide_Plugin::branch_question_to_quiz
     */
    public function test_branch_unsupported_type_returns_null(): void
    {
        $this->assertNull(self::callBranchQuestionToQuiz(['type' => 'dragndrop']));
        $this->assertNull(self::callBranchQuestionToQuiz(['type' => '']));
        $this->assertNull(self::callBranchQuestionToQuiz([]));
    }

    // ── get_library_for_type: known types ───────────────────────

    /**
     * @covers PBSG_H5P_Factory::get_library_for_type
     */
    public function test_get_library_for_known_types(): void
    {
        $this->assertSame('H5P.MultiChoice', PBSG_H5P_Factory::get_library_for_type('multichoice'));
        $this->assertSame('H5P.MultiChoice', PBSG_H5P_Factory::get_library_for_type('singlechoice'));
        $this->assertSame('H5P.Blanks', PBSG_H5P_Factory::get_library_for_type('blanks'));
    }

    // ── get_library_for_type: unknown type ──────────────────────

    /**
     * @covers PBSG_H5P_Factory::get_library_for_type
     */
    public function test_get_library_for_unknown_type_returns_null(): void
    {
        $this->assertNull(PBSG_H5P_Factory::get_library_for_type('dragndrop'));
        $this->assertNull(PBSG_H5P_Factory::get_library_for_type(''));
    }

    // ── Helper: call private branch_question_to_quiz via reflection ──

    private static function callBranchQuestionToQuiz(array $bq): ?array
    {
        $ref = new ReflectionClass(PB_Split_Guide_Plugin::class);
        $method = $ref->getMethod('branch_question_to_quiz');
        return $method->invoke(null, $bq);
    }
}
