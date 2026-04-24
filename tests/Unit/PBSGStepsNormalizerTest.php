<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../web/app/plugins/pb-split-guide/includes/steps-normalizer.php';

final class PBSGStepsNormalizerTest extends TestCase
{
    public function test_migrates_legacy_url_to_new_fields(): void
    {
        $input = [
            ['url' => 'https://upei.ca/tutorial', 'h5p_id' => '10', 'title' => 'Test Step'],
        ];

        $out = PBSG_Steps_Normalizer::normalize($input);

        $this->assertCount(1, $out);
        $this->assertSame(10, $out[0]['h5p_id']);
        $this->assertSame('Test Step', $out[0]['title']);
        $this->assertSame('url', $out[0]['tutorial_type']);
        $this->assertSame('https://upei.ca/tutorial', $out[0]['tutorial_url']);
        $this->assertSame('https://upei.ca/tutorial', $out[0]['url']); // legacy retained
        $this->assertSame(0, $out[0]['tutorial_attachment_id']);
    }

    public function test_skips_empty_rows(): void
    {
        $input = [
            [],
            ['title' => '   '],
            ['tutorial_type' => ''],
        ];

        $out = PBSG_Steps_Normalizer::normalize($input);

        $this->assertCount(0, $out);
    }

    public function test_normalizes_invalid_type_to_file_when_attachment_present(): void
    {
        $input = [
            ['tutorial_type' => 'weird', 'tutorial_attachment_id' => 99],
        ];

        $out = PBSG_Steps_Normalizer::normalize($input);

        $this->assertCount(1, $out);
        $this->assertSame('file', $out[0]['tutorial_type']);
        $this->assertSame(99, $out[0]['tutorial_attachment_id']);
        $this->assertSame('', $out[0]['tutorial_url']);
        $this->assertSame('', $out[0]['url']);
    }

    public function test_file_without_attachment_falls_back_to_url_when_url_present(): void
    {
        $input = [
            [
                'tutorial_type' => 'file',
                'tutorial_attachment_id' => 0,
                'tutorial_url' => 'https://example.com/a.pdf',
            ],
        ];

        $out = PBSG_Steps_Normalizer::normalize($input);

        $this->assertCount(1, $out);
        $this->assertSame('url', $out[0]['tutorial_type']);
        $this->assertSame('https://example.com/a.pdf', $out[0]['tutorial_url']);
        $this->assertSame(0, $out[0]['tutorial_attachment_id']);
    }

    public function test_rejects_non_http_urls(): void
    {
        $input = [
            ['url' => 'javascript:alert(1)', 'h5p_id' => 1],
            ['tutorial_url' => 'ftp://example.com/file', 'tutorial_type' => 'url'],
        ];

        $out = PBSG_Steps_Normalizer::normalize($input);

        // both become empty tutorial -> but rows still have h5p_id so they remain
        $this->assertCount(1, $out);
        $this->assertSame('', $out[0]['tutorial_type']);
        $this->assertSame('', $out[0]['tutorial_url']);
    }

    // ═══════════════════════════════════════════════════════════
    //  Issue 2: Steps with quiz-only data must not be filtered out
    // ═══════════════════════════════════════════════════════════

    public function test_step_with_only_quiz_data_is_preserved(): void
    {
        $input = [
            [
                'title' => '',
                'h5p_id' => 0,
                'tutorial_type' => '',
                'tutorial_url' => '',
                'quiz' => [
                    'type' => 'multichoice',
                    'question' => 'What color is the sky?',
                    'answers' => [
                        ['text' => 'Blue', 'correct' => true],
                        ['text' => 'Red', 'correct' => false],
                    ],
                ],
            ],
        ];

        $result = PBSG_Steps_Normalizer::normalize($input);

        $this->assertCount(1, $result, 'Step with only quiz data must not be filtered out');
        $this->assertArrayHasKey('quiz', $result[0]);
        $this->assertSame('multichoice', $result[0]['quiz']['type']);
    }

    public function test_step_with_blanks_quiz_only_is_preserved(): void
    {
        $input = [
            [
                'title' => '',
                'h5p_id' => 0,
                'tutorial_type' => '',
                'quiz' => [
                    'type' => 'blanks',
                    'sentence' => 'The capital of Canada is *Ottawa*.',
                    'case_sensitive' => false,
                    'accept_typos' => true,
                ],
            ],
        ];

        $result = PBSG_Steps_Normalizer::normalize($input);

        $this->assertCount(1, $result, 'Step with blanks quiz data must not be filtered out');
        $this->assertSame('blanks', $result[0]['quiz']['type']);
    }

    public function test_step_with_singlechoice_quiz_only_is_preserved(): void
    {
        $input = [
            [
                'title' => '',
                'h5p_id' => 0,
                'tutorial_type' => '',
                'quiz' => [
                    'type' => 'singlechoice',
                    'question' => 'What is 2+2?',
                    'correct_answer' => '4',
                    'wrong_answers' => ['3', '5'],
                ],
            ],
        ];

        $result = PBSG_Steps_Normalizer::normalize($input);

        $this->assertCount(1, $result, 'Step with singlechoice quiz data must not be filtered out');
        $this->assertSame('singlechoice', $result[0]['quiz']['type']);
    }

    public function test_step_with_quiz_type_but_no_content_is_preserved(): void
    {
        // Quiz type is set but question/answers not filled yet — should still be kept
        $input = [
            [
                'title' => '',
                'h5p_id' => 0,
                'tutorial_type' => '',
                'quiz' => [
                    'type' => 'multichoice',
                    'question' => '',
                    'answers' => [],
                ],
            ],
        ];

        $result = PBSG_Steps_Normalizer::normalize($input);

        $this->assertCount(1, $result, 'Step with quiz type but empty content should still be kept');
    }

    public function test_step_with_empty_quiz_type_and_no_other_data_is_dropped(): void
    {
        $input = [
            [
                'title' => '',
                'h5p_id' => 0,
                'tutorial_type' => '',
                'quiz' => [
                    'type' => '',
                ],
            ],
        ];

        $result = PBSG_Steps_Normalizer::normalize($input);

        $this->assertCount(0, $result, 'Step with empty quiz type and no other data should be dropped');
    }

    public function test_twenty_plus_steps_with_quiz_data_all_survive(): void
    {
        $input = [];
        for ($i = 0; $i < 25; $i++) {
            $input[] = [
                'title' => '',
                'h5p_id' => 0,
                'tutorial_type' => '',
                'tutorial_url' => '',
                'quiz' => [
                    'type' => 'multichoice',
                    'question' => "Question $i",
                    'answers' => [
                        ['text' => 'A', 'correct' => true],
                        ['text' => 'B', 'correct' => false],
                    ],
                ],
            ];
        }

        $result = PBSG_Steps_Normalizer::normalize($input);

        $this->assertCount(25, $result, 'All 25 quiz-only steps must survive normalization');
        for ($i = 0; $i < 25; $i++) {
            $this->assertArrayHasKey('quiz', $result[$i], "Step $i missing quiz data");
            $this->assertSame('multichoice', $result[$i]['quiz']['type']);
        }
    }

    public function test_mixed_steps_with_and_without_quiz_preserve_correctly(): void
    {
        $input = [
            // Step with title only
            ['title' => 'Intro Step', 'h5p_id' => 0, 'tutorial_type' => ''],
            // Step with quiz only
            ['title' => '', 'h5p_id' => 0, 'tutorial_type' => '', 'quiz' => [
                'type' => 'multichoice', 'question' => 'Q1', 'answers' => [['text' => 'A', 'correct' => true]],
            ]],
            // Empty step — should be dropped
            ['title' => '', 'h5p_id' => 0, 'tutorial_type' => ''],
            // Step with URL resource
            ['title' => '', 'h5p_id' => 0, 'tutorial_type' => 'url', 'tutorial_url' => 'https://example.com'],
            // Step with quiz and title
            ['title' => 'Step 5', 'h5p_id' => 0, 'tutorial_type' => '', 'quiz' => [
                'type' => 'blanks', 'sentence' => '*Answer*',
            ]],
        ];

        $result = PBSG_Steps_Normalizer::normalize($input);

        $this->assertCount(4, $result, 'Should keep 4 of 5 steps (empty one dropped)');
        $this->assertSame('Intro Step', $result[0]['title']);
        $this->assertSame('multichoice', $result[1]['quiz']['type']);
        $this->assertSame('url', $result[2]['tutorial_type']);
        $this->assertSame('Step 5', $result[3]['title']);
    }

    // ═══════════════════════════════════════════════════════════
    //  Issue: $has_per_question_resource undefined on line 203
    // ═══════════════════════════════════════════════════════════

    public function test_per_question_branch_resource_mode_preserves_branch(): void
    {
        $input = [
            [
                'title' => 'Main Step',
                'tutorial_type' => 'url',
                'tutorial_url' => 'https://example.com',
                'h5p_id' => 1,
                'branch' => [
                    'mode' => 'mandatory',
                    'resource_mode' => 'per_question',
                    'trigger_attempts' => 1,
                    'tutorial_type' => '',
                    'tutorial_url' => '',
                    'tutorial_attachment_id' => 0,
                    'questions' => [
                        [
                            'type' => 'multichoice',
                            'question' => 'Branch Q1?',
                            'answers' => [
                                ['text' => 'A', 'correct' => true],
                                ['text' => 'B', 'correct' => false],
                            ],
                            'tutorial_type' => 'url',
                            'tutorial_url' => 'https://example.com/resource-a',
                            'tutorial_attachment_id' => 0,
                        ],
                        [
                            'type' => 'multichoice',
                            'question' => 'Branch Q2?',
                            'answers' => [
                                ['text' => 'C', 'correct' => true],
                                ['text' => 'D', 'correct' => false],
                            ],
                            'tutorial_type' => 'url',
                            'tutorial_url' => 'https://example.com/resource-b',
                            'tutorial_attachment_id' => 0,
                        ],
                    ],
                ],
            ],
        ];

        $out = PBSG_Steps_Normalizer::normalize($input);

        $this->assertCount(1, $out);
        $this->assertNotNull($out[0]['branch'], 'Branch should be preserved for per_question mode');
        $this->assertSame('per_question', $out[0]['branch']['resource_mode']);
        $this->assertCount(2, $out[0]['branch']['questions']);
        $this->assertSame('url', $out[0]['branch']['questions'][0]['tutorial_type']);
        $this->assertSame('https://example.com/resource-a', $out[0]['branch']['questions'][0]['tutorial_url']);
        $this->assertSame('url', $out[0]['branch']['questions'][1]['tutorial_type']);
        $this->assertSame('https://example.com/resource-b', $out[0]['branch']['questions'][1]['tutorial_url']);
    }

    public function test_per_question_branch_without_any_resources_strips_branch(): void
    {
        $input = [
            [
                'title' => 'Main Step',
                'tutorial_type' => 'url',
                'tutorial_url' => 'https://example.com',
                'h5p_id' => 1,
                'branch' => [
                    'mode' => 'mandatory',
                    'resource_mode' => 'per_question',
                    'trigger_attempts' => 1,
                    'tutorial_type' => '',
                    'tutorial_url' => '',
                    'tutorial_attachment_id' => 0,
                    'questions' => [
                        [
                            'type' => 'multichoice',
                            'question' => 'Branch Q1?',
                            'answers' => [
                                ['text' => 'A', 'correct' => true],
                                ['text' => 'B', 'correct' => false],
                            ],
                            'tutorial_type' => '',
                            'tutorial_url' => '',
                            'tutorial_attachment_id' => 0,
                        ],
                    ],
                ],
            ],
        ];

        $out = PBSG_Steps_Normalizer::normalize($input);

        $this->assertCount(1, $out);
        $this->assertNull($out[0]['branch'], 'Branch should be stripped when per_question has no resources');
    }

    // ═══════════════════════════════════════════════════════════
    //  instructions_html field + sanitize_rich_text()
    // ═══════════════════════════════════════════════════════════

    public function test_instructions_html_preserved_through_normalize(): void
    {
        $input = [
            [
                'title' => 'Step with rich text',
                'h5p_id' => 0,
                'tutorial_type' => 'url',
                'tutorial_url' => 'https://example.com',
                'instructions_html' => '<p>Learn about <b>databases</b> and <span style="color:red">resources</span>.</p>',
            ],
        ];

        $out = PBSG_Steps_Normalizer::normalize($input);

        $this->assertCount(1, $out);
        $this->assertArrayHasKey('instructions_html', $out[0]);
        $this->assertStringContainsString('<b>databases</b>', $out[0]['instructions_html']);
        $this->assertStringContainsString('<span', $out[0]['instructions_html']);
    }

    public function test_instructions_html_strips_unsafe_tags(): void
    {
        $input = [
            [
                'title' => 'Step with script',
                'h5p_id' => 0,
                'tutorial_type' => 'url',
                'tutorial_url' => 'https://example.com',
                'instructions_html' => '<p>Safe text</p><script>alert("xss")</script>',
            ],
        ];

        $out = PBSG_Steps_Normalizer::normalize($input);

        $this->assertCount(1, $out);
        $this->assertStringNotContainsString('<script>', $out[0]['instructions_html']);
        $this->assertStringNotContainsString('alert', $out[0]['instructions_html']);
        $this->assertStringContainsString('Safe text', $out[0]['instructions_html']);
    }

    public function test_instructions_html_defaults_to_empty_string(): void
    {
        $input = [
            [
                'title' => 'Step without instructions_html',
                'h5p_id' => 0,
                'tutorial_type' => 'url',
                'tutorial_url' => 'https://example.com',
            ],
        ];

        $out = PBSG_Steps_Normalizer::normalize($input);

        $this->assertCount(1, $out);
        $this->assertArrayHasKey('instructions_html', $out[0]);
        $this->assertSame('', $out[0]['instructions_html']);
    }

    public function test_quiz_question_preserves_html_after_sanitize_rich_text(): void
    {
        $input = [
            [
                'title' => '',
                'h5p_id' => 0,
                'tutorial_type' => '',
                'quiz' => [
                    'type' => 'multichoice',
                    'question' => 'What is <b>HTML</b>?',
                    'answers' => [
                        ['text' => 'A markup language', 'correct' => true],
                        ['text' => 'A scripting language', 'correct' => false],
                    ],
                ],
            ],
        ];

        $out = PBSG_Steps_Normalizer::normalize($input);

        $this->assertCount(1, $out);
        $this->assertStringContainsString('<b>HTML</b>', $out[0]['quiz']['question']);
    }

    public function test_quiz_question_strips_script_tags(): void
    {
        $input = [
            [
                'title' => '',
                'h5p_id' => 0,
                'tutorial_type' => '',
                'quiz' => [
                    'type' => 'multichoice',
                    'question' => 'Safe <script>evil()</script> question?',
                    'answers' => [
                        ['text' => 'Answer', 'correct' => true],
                    ],
                ],
            ],
        ];

        $out = PBSG_Steps_Normalizer::normalize($input);

        $this->assertCount(1, $out);
        $this->assertStringNotContainsString('<script>', $out[0]['quiz']['question']);
        $this->assertStringNotContainsString('evil()', $out[0]['quiz']['question']);
        $this->assertStringContainsString('Safe', $out[0]['quiz']['question']);
    }

    public function test_blanks_sentence_still_strips_html(): void
    {
        // Regression: blanks sentence must NOT preserve HTML (uses strip_tags, not wp_kses_post)
        $input = [
            [
                'title' => '',
                'h5p_id' => 0,
                'tutorial_type' => '',
                'quiz' => [
                    'type' => 'blanks',
                    'sentence' => 'The answer is <b>*bold*</b>.',
                    'case_sensitive' => false,
                    'accept_typos' => false,
                ],
            ],
        ];

        $out = PBSG_Steps_Normalizer::normalize($input);

        $this->assertCount(1, $out);
        $this->assertStringNotContainsString('<b>', $out[0]['quiz']['sentence']);
        $this->assertStringContainsString('*bold*', $out[0]['quiz']['sentence']);
    }
}