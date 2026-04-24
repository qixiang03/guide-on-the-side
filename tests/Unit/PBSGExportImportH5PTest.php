<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Helpers/MockWpdb.php';
require_once __DIR__ . '/../Integration/Helpers/FakeH5PCore.php';

/**
 * Unit tests for H5P content portability in PBSG_Export_Import (v1.1 schema).
 * Export: embeds wp_h5p_contents row + library identity per referenced quiz.
 * Import: recreates rows via H5PCore::saveContent() and remaps step h5p_id tokens.
 */
final class PBSGExportImportH5PTest extends TestCase
{
    private MockWpdb $wpdb;
    private FakeH5PCore $fakeCore;
    private array $postBackup;
    private array $filesBackup;
    private ?object $globalsBackup;

    protected function setUp(): void
    {
        WPStubs::reset();
        $this->wpdb = new MockWpdb();
        $this->wpdb->prefix = 'wp_';
        $GLOBALS['wpdb'] = $this->wpdb;

        $this->fakeCore = new FakeH5PCore();
        $this->globalsBackup = $GLOBALS['H5P_Plugin'] ?? null;
        $GLOBALS['H5P_Plugin'] = new FakeH5PPlugin($this->fakeCore);

        $this->postBackup = $_POST;
        $this->filesBackup = $_FILES ?? [];
        $_POST = [];
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        WPStubs::reset();
        unset($GLOBALS['wpdb']);
        if ($this->globalsBackup === null) {
            unset($GLOBALS['H5P_Plugin']);
        } else {
            $GLOBALS['H5P_Plugin'] = $this->globalsBackup;
        }
        $_POST = $this->postBackup;
        $_FILES = $this->filesBackup;
    }

    public function test_harness_bootstraps(): void
    {
        $this->assertSame('1.0', PBSG_Export_Import::EXPORT_VERSION);
        $this->assertInstanceOf(FakeH5PCore::class, $GLOBALS['H5P_Plugin']->get_h5p_instance('core'));
    }
}
