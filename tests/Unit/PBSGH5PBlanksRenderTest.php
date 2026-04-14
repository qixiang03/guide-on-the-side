<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PBSG_H5P_Factory blanks rendering fix.
 *
 * Verifies that build_blanks_params wraps the sentence in <p> tags
 * (required by H5P.Blanks to parse *answer* markers) and that
 * reverse_blanks strips those tags when loading existing content.
 */
final class PBSGH5PBlanksRenderTest extends TestCase
{
    protected function setUp(): void
    {
        WPStubs::reset();
    }

    /**
     * @covers PBSG_H5P_Factory
     */
    public function test_build_blanks_params_wraps_sentence_in_paragraph_tags(): void
    {
        $ref = new ReflectionClass(PBSG_H5P_Factory::class);
        $method = $ref->getMethod('build_blanks_params');

        $quiz = ['type' => 'blanks', 'sentence' => 'The capital is *Ottawa*.'];
        $params = $method->invoke(null, $quiz);

        $this->assertIsArray($params['questions']);
        $this->assertSame('<p>The capital is *Ottawa*.</p>', $params['questions'][0]);
    }

    /**
     * @covers PBSG_H5P_Factory::reverse
     */
    public function test_reverse_blanks_strips_paragraph_tags(): void
    {
        $params = [
            'questions' => ['<p>The capital is *Ottawa*.</p>'],
            'behaviour' => ['caseSensitive' => true, 'acceptSpellingErrors' => false],
        ];

        $out = PBSG_H5P_Factory::reverse('H5P.Blanks', wp_json_encode($params));

        $this->assertIsArray($out);
        $this->assertSame('blanks', $out['type']);
        $this->assertSame('The capital is *Ottawa*.', $out['sentence']);
        $this->assertTrue($out['case_sensitive']);
        $this->assertFalse($out['accept_typos']);
    }
}
