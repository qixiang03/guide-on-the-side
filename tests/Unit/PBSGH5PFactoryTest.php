<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PBSG_H5P_Factory error paths, title helper, and reverse mappers.
 */
final class PBSGH5PFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        WPStubs::reset();
    }

    protected function tearDown(): void
    {
        WPStubs::reset();
    }

    /**
     * @covers PBSG_H5P_Factory::create
     */
    public function test_create_returns_wp_error_for_unknown_quiz_type(): void
    {
        $r = PBSG_H5P_Factory::create(['type' => 'not_a_real_type'], 'Post', 2, 'Step');

        $this->assertInstanceOf(WP_Error::class, $r);
        $this->assertSame('pbsg_invalid_quiz_type', $r->get_error_code());
    }

    /**
     * @covers PBSG_H5P_Factory::update
     */
    public function test_update_returns_wp_error_for_unknown_quiz_type(): void
    {
        $r = PBSG_H5P_Factory::update(10, ['type' => 'bad']);

        $this->assertInstanceOf(WP_Error::class, $r);
        $this->assertSame('pbsg_invalid_quiz_type', $r->get_error_code());
    }

    /**
     * @covers PBSG_H5P_Factory::is_h5p_available
     */
    public function test_is_h5p_available_is_boolean(): void
    {
        $this->assertIsBool(PBSG_H5P_Factory::is_h5p_available());
    }

    /**
     * @covers PBSG_H5P_Factory::reverse
     */
    public function test_reverse_multichoice_round_trip_shape(): void
    {
        $quizIn = [
            'type'      => 'multichoice',
            'question'  => 'Pick one',
            'answers'   => [
                ['text' => 'A', 'correct' => true],
                ['text' => 'B', 'correct' => false],
            ],
        ];

        $ref = new ReflectionClass(PBSG_H5P_Factory::class);
        $build = $ref->getMethod('build_params');
        $params = $build->invoke(null, 'multichoice', $quizIn);

        $json = wp_json_encode($params);
        $out = PBSG_H5P_Factory::reverse('H5P.MultiChoice', $json);

        $this->assertIsArray($out);
        $this->assertSame('multichoice', $out['type']);
        $this->assertSame('Pick one', $out['question']);
        $this->assertCount(2, $out['answers']);
        $this->assertTrue($out['answers'][0]['correct']);
        $this->assertFalse($out['answers'][1]['correct']);
    }

    /**
     * @covers PBSG_H5P_Factory::reverse
     */
    public function test_reverse_singlechoice_via_multichoice_single_point(): void
    {
        $ref = new ReflectionClass(PBSG_H5P_Factory::class);
        $build = $ref->getMethod('build_params');
        $quizIn = [
            'type'            => 'singlechoice',
            'question'        => 'Q?',
            'correct_answer'  => 'Yes',
            'wrong_answers'   => ['No'],
        ];
        $params = $build->invoke(null, 'singlechoice', $quizIn);
        $out = PBSG_H5P_Factory::reverse('H5P.MultiChoice', wp_json_encode($params));

        $this->assertIsArray($out);
        $this->assertSame('singlechoice', $out['type']);
        $this->assertSame('Q?', $out['question']);
        $this->assertSame('Yes', $out['correct_answer']);
        $this->assertSame(['No'], $out['wrong_answers']);
    }

    /**
     * @covers PBSG_H5P_Factory::reverse
     */
    public function test_reverse_blanks_preserves_sentence_and_flags(): void
    {
        $ref = new ReflectionClass(PBSG_H5P_Factory::class);
        $build = $ref->getMethod('build_params');
        $quizIn = [
            'type'           => 'blanks',
            'sentence'       => 'Hello *world*',
            'case_sensitive' => false,
            'accept_typos'   => true,
        ];
        $params = $build->invoke(null, 'blanks', $quizIn);
        $out = PBSG_H5P_Factory::reverse('H5P.Blanks', wp_json_encode($params));

        $this->assertIsArray($out);
        $this->assertSame('blanks', $out['type']);
        $this->assertSame('Hello *world*', $out['sentence']);
        $this->assertFalse($out['case_sensitive']);
        $this->assertTrue($out['accept_typos']);
    }

    /**
     * @covers PBSG_H5P_Factory::reverse
     */
    public function test_reverse_returns_null_for_invalid_json(): void
    {
        $this->assertNull(PBSG_H5P_Factory::reverse('H5P.MultiChoice', 'not-json'));
    }

    /**
     * @covers PBSG_H5P_Factory::reverse
     */
    public function test_reverse_returns_null_for_unsupported_library(): void
    {
        $this->assertNull(PBSG_H5P_Factory::reverse('H5P.Unknown', '{}'));
    }

    /**
     * Title generation (private helper).
     *
     * @covers \PBSG_H5P_Factory::generate_title
     */
    public function test_generate_title_prefers_post_and_step_titles(): void
    {
        $m = new ReflectionMethod(PBSG_H5P_Factory::class, 'generate_title');

        $this->assertSame('Course — Intro', $m->invoke(null, 'Course', 3, 'Intro'));
        $this->assertSame('Course — Step 2', $m->invoke(null, 'Course', 2, ''));
        $this->assertSame('Solo', $m->invoke(null, '', 0, 'Solo'));
        $this->assertSame('Inline Quiz (Step 1)', $m->invoke(null, '', 0, ''));
        $this->assertSame('Inline Quiz (Step 4)', $m->invoke(null, '', 4, ''));
    }

    /**
     * @covers PBSG_H5P_Factory::reverse
     */
    public function test_reverse_multichoice_preserves_html_in_question(): void
    {
        $ref = new ReflectionClass(PBSG_H5P_Factory::class);
        $build = $ref->getMethod('build_params');
        $quizIn = [
            'type'     => 'multichoice',
            'question' => 'Which are <b>Boolean operators</b>?',
            'answers'  => [
                ['text' => 'AND', 'correct' => true],
            ],
        ];
        $params = $build->invoke(null, 'multichoice', $quizIn);
        $out = PBSG_H5P_Factory::reverse('H5P.MultiChoice', wp_json_encode($params));

        $this->assertSame('Which are <b>Boolean operators</b>?', $out['question']);
    }

    /**
     * @covers PBSG_H5P_Factory::reverse
     */
    public function test_reverse_singlechoice_preserves_html_in_question(): void
    {
        $ref = new ReflectionClass(PBSG_H5P_Factory::class);
        $build = $ref->getMethod('build_params');
        $quizIn = [
            'type'           => 'singlechoice',
            'question'       => 'What does <i>peer-reviewed</i> mean?',
            'correct_answer' => 'Reviewed by experts',
            'wrong_answers'  => ['Not reviewed'],
        ];
        $params = $build->invoke(null, 'singlechoice', $quizIn);
        $out = PBSG_H5P_Factory::reverse('H5P.MultiChoice', wp_json_encode($params));

        $this->assertSame('What does <i>peer-reviewed</i> mean?', $out['question']);
        $this->assertSame('Reviewed by experts', $out['correct_answer']);
    }

    /**
     * @covers PBSG_H5P_Factory::build_params
     */
    public function test_build_multichoice_does_not_double_wrap_p_tags(): void
    {
        $ref = new ReflectionClass(PBSG_H5P_Factory::class);
        $build = $ref->getMethod('build_params');
        $quizIn = [
            'type'     => 'multichoice',
            'question' => '<p>Already wrapped</p>',
            'answers'  => [
                ['text' => '<p>Answer A</p>', 'correct' => true],
            ],
        ];
        $params = $build->invoke(null, 'multichoice', $quizIn);

        $this->assertSame('<p>Already wrapped</p>', $params['question']);
        $this->assertSame('<p>Answer A</p>', $params['answers'][0]['text']);
    }
}
