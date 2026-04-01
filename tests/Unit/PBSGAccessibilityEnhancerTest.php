<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Lightweight tests for Pressbooks_Accessibility_Enhancer export hooks (no full WP admin bootstrap).
 */
final class PBSGAccessibilityEnhancerTest extends TestCase
{
    public function test_add_pdf_accessibility_appends_print_link_rules(): void
    {
        $ref  = new ReflectionClass(Pressbooks_Accessibility_Enhancer::class);
        $inst = $ref->newInstanceWithoutConstructor();

        $css  = 'body { margin: 0; }';
        $out  = $inst->add_pdf_accessibility($css);

        $this->assertStringContainsString($css, $out);
        $this->assertStringContainsString('text-decoration: underline', $out);
    }

    public function test_add_epub_accessibility_returns_input_unchanged(): void
    {
        $ref  = new ReflectionClass(Pressbooks_Accessibility_Enhancer::class);
        $inst = $ref->newInstanceWithoutConstructor();

        $this->assertSame('epub-base{}', $inst->add_epub_accessibility('epub-base{}'));
    }
}
