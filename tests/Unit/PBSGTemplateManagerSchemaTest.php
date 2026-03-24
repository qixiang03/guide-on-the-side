<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Schema/source tests for PBSG_Template_Manager.
 *
 * Mirrors the source-inspection style used by PBSGAnalyticsSchemaTest to
 * avoid dbDelta runtime coupling in lightweight test environments.
 */
final class PBSGTemplateManagerSchemaTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/web/app/plugins/pb-split-guide/includes/class-pbsg-template-manager.php'
        );
    }

    public function test_constants_are_defined(): void
    {
        $this->assertSame('pbsg_tutorial_templates', PBSG_Template_Manager::TABLE);
        $this->assertSame('1.0', PBSG_Template_Manager::DB_VER);
        $this->assertSame('pbsg_templates_db_version', PBSG_Template_Manager::OPT_VER);
    }

    public function test_create_tables_source_has_expected_columns(): void
    {
        $columns = [
            'id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
            'name        VARCHAR(200)    NOT NULL',
            'description TEXT',
            'category    VARCHAR(100)    DEFAULT',
            'is_system   TINYINT(1)      DEFAULT 0',
            'steps_json  LONGTEXT        NOT NULL DEFAULT',
            'header_note VARCHAR(500)    DEFAULT',
            'created_by  BIGINT UNSIGNED DEFAULT 0',
            'created_at  DATETIME        DEFAULT CURRENT_TIMESTAMP',
            'PRIMARY KEY (id)',
        ];

        foreach ($columns as $col) {
            $this->assertStringContainsString($col, $this->source, "Missing schema fragment: {$col}");
        }
    }

    public function test_create_tables_source_uses_dbdelta_and_updates_version(): void
    {
        $this->assertStringContainsString("dbDelta( \$sql );", $this->source);
        $this->assertStringContainsString("update_option( self::OPT_VER, self::DB_VER );", $this->source);
    }

    public function test_seed_defaults_source_contains_builtin_default_template(): void
    {
        $this->assertStringContainsString("name = 'Split Guide (Default)'", $this->source);
        $this->assertStringContainsString("'is_system'   => 1", $this->source);
    }
}
