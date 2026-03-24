<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Source-contract tests for Sprint 7 template/export/import logic.
 */
final class PBSGTemplateExportImportSourceTest extends TestCase
{
    private string $templateManagerSource;
    private string $exportImportSource;

    protected function setUp(): void
    {
        $this->templateManagerSource = (string) file_get_contents(
            dirname(__DIR__, 2) . '/web/app/plugins/pb-split-guide/includes/class-pbsg-template-manager.php'
        );
        $this->exportImportSource = (string) file_get_contents(
            dirname(__DIR__, 2) . '/web/app/plugins/pb-split-guide/includes/class-pbsg-export-import.php'
        );
    }

    public function test_template_token_placeholders_are_present_in_create_from_template(): void
    {
        $tokens = [
            '{{TUTORIAL_TITLE}}',
            '{{AUTHOR_NAME}}',
            '{{CURRENT_DATE}}',
            '{{LIBRARY_CATALOG_URL}}',
        ];

        foreach ($tokens as $token) {
            $this->assertStringContainsString($token, $this->templateManagerSource);
        }

        $this->assertStringContainsString('str_replace( array_keys( $tokens ), array_values( $tokens ), $steps_json )', $this->templateManagerSource);
        $this->assertStringContainsString('str_replace( array_keys( $tokens ), array_values( $tokens ), $header_note )', $this->templateManagerSource);
    }

    public function test_export_contract_contains_version_and_core_package_keys(): void
    {
        $this->assertSame('1.0', PBSG_Export_Import::EXPORT_VERSION);

        $keys = [
            "'pbsg_version' => self::EXPORT_VERSION",
            "'exported_at'  => gmdate( 'c' )",
            "'title'",
            "'post_content'",
            "'header_note'",
            "'cover_id'",
            "'steps'",
            "'attachments'",
        ];

        foreach ($keys as $key) {
            $this->assertStringContainsString($key, $this->exportImportSource);
        }
    }

    public function test_export_import_source_contains_attachment_token_mapping_contract(): void
    {
        $this->assertStringContainsString("'att_' . \$taid", $this->exportImportSource);
        $this->assertStringContainsString("'att_' . \$baid", $this->exportImportSource);
        $this->assertStringContainsString("'att_' . \$att['original_id']", $this->exportImportSource);
        $this->assertStringContainsString('PBSG_Steps_Normalizer::normalize( $steps )', $this->exportImportSource);
    }

    public function test_import_validation_messages_are_present(): void
    {
        $this->assertStringContainsString('You do not have permission to import tutorials.', $this->exportImportSource);
        $this->assertStringContainsString('Upload failed (error code', $this->exportImportSource);
        $this->assertStringContainsString('Invalid export file. Make sure you are uploading a Guide on the Side .json export.', $this->exportImportSource);
    }
}
