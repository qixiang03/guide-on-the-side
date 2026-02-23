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
}