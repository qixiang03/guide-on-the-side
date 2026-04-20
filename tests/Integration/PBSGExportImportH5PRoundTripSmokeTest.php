<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Unit/Helpers/MockWpdb.php';
require_once __DIR__ . '/Helpers/FakeH5PCore.php';

/**
 * Integration smoke: export a v1.1 H5P-portable package then feed the same
 * JSON back into the importer. Verifies the full wiring — JSON round-trip,
 * token-in/token-out, preflight + library resolution + saveContent + remap
 * all execute in order.
 */
final class PBSGExportImportH5PRoundTripSmokeTest extends TestCase
{
    private array $postBackup;
    private array $filesBackup;
    private ?object $globalsBackup;

    protected function setUp(): void
    {
        WPStubs::reset();
        $this->globalsBackup = $GLOBALS['H5P_Plugin'] ?? null;
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

    public function test_export_then_import_round_trips_an_h5p_tutorial(): void
    {
        // --- EXPORT PHASE ---
        WPStubs::reset();
        $wpdb = new MockWpdb();
        $wpdb->prefix = 'wp_';
        $GLOBALS['wpdb'] = $wpdb;

        $core = new FakeH5PCore();
        $GLOBALS['H5P_Plugin'] = new FakeH5PPlugin($core);
        if (!class_exists('H5P_Plugin', false)) {
            class_alias(FakeH5PPlugin::class, 'H5P_Plugin');
        }

        $steps = [['title' => 'Q', 'h5p_id' => 42]];
        WPStubs::$returns['current_user_can'] = true;
        WPStubs::$returns['current_user_can_resolver'] = static fn (): bool => true;
        WPStubs::$returns['get_post'] = (object) [
            'ID' => 100, 'post_title' => 'Round Trip', 'post_content' => '',
        ];
        WPStubs::$returns['get_post_meta'] = [
            '_pbsg_steps_json'     => wp_json_encode($steps),
            '_pbsg_header_note'    => '',
            '_pbsg_cover_image_id' => 0,
        ];
        $wpdb->returns['h5p_content_rows'] = [
            42 => [
                'id' => 42, 'title' => 'Q', 'parameters' => '{"question":"A?"}', 'disable' => 1,
                'library_name' => 'H5P.MultiChoice', 'major_version' => 1, 'minor_version' => 16,
            ],
        ];
        $_POST['nonce'] = 'n'; $_POST['post_id'] = '100';

        set_error_handler(static function (int $severity, string $message): bool {
            if (strpos($message, 'Cannot modify header information') !== false) {
                return true;
            }
            if (strpos($message, 'headers already sent') !== false) {
                return true;
            }
            return false;
        });
        ob_start();
        try { PBSG_Export_Import::handle_export(); } catch (WPDieException $e) {}
        $json = ob_get_clean();
        restore_error_handler();
        $package = json_decode((string) $json, true);
        $this->assertIsArray($package, 'export output must be valid JSON');

        $this->assertSame('1.1', $package['pbsg_version']);
        $this->assertSame('h5p_42', $package['steps'][0]['h5p_id']);
        $this->assertCount(1, $package['h5p_contents']);

        // --- IMPORT PHASE ---
        WPStubs::reset();
        $wpdb->reset();
        $wpdb->returns['h5p_library_resolutions'] = ['H5P.MultiChoice|1|16' => 9001];
        $core->saveContentCalls = [];
        $core->saveContentReturns = [501];

        WPStubs::$returns['current_user_can'] = true;
        WPStubs::$returns['get_current_user_id'] = 1;
        WPStubs::$returns['wp_insert_post'] = 8888;

        $tmp = tempnam(sys_get_temp_dir(), 'pbsgrt');
        file_put_contents($tmp, $json);
        $_FILES['pbsg_import_file'] = ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp];
        try { PBSG_Export_Import::handle_import(); } catch (WPDieException $e) {}
        @unlink($tmp);

        $this->assertCount(1, $core->saveContentCalls);
        $this->assertSame('{"question":"A?"}', $core->saveContentCalls[0]['parameters']);

        $postarr = WPStubs::callArgs('wp_insert_post', 0)[0];
        $importedSteps = json_decode($postarr['meta_input']['_pbsg_steps_json'], true);
        $this->assertSame(501, $importedSteps[0]['h5p_id']);
    }
}
