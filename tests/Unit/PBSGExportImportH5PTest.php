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
        $this->assertSame('1.1', PBSG_Export_Import::EXPORT_VERSION);
        $this->assertInstanceOf(FakeH5PCore::class, $GLOBALS['H5P_Plugin']->get_h5p_instance('core'));
    }

    public function test_export_includes_h5p_contents_entry_per_referenced_quiz(): void
    {
        $this->primeExportFixtures(
            postId: 100,
            stepsJson: wp_json_encode([
                ['title' => 'Q1', 'h5p_id' => 42],
                ['title' => 'Q2', 'h5p_id' => 43],
            ]),
            h5pRows: [
                42 => $this->h5pRow(42, 'H5P.MultiChoice', 1, 16, '{"question":"A?"}'),
                43 => $this->h5pRow(43, 'H5P.Blanks',      1, 14, '{"text":"___ capital"}'),
            ],
        );

        $json = $this->captureExport(100);

        $this->assertCount(2, $json['h5p_contents']);
        $ids = array_column($json['h5p_contents'], 'original_id');
        $this->assertEqualsCanonicalizing([42, 43], $ids);
    }

    public function test_export_deduplicates_shared_quiz(): void
    {
        $this->primeExportFixtures(
            postId: 101,
            stepsJson: wp_json_encode([
                ['title' => 'A', 'h5p_id' => 42],
                ['title' => 'B', 'h5p_id' => 42],
            ]),
            h5pRows: [ 42 => $this->h5pRow(42, 'H5P.MultiChoice', 1, 16, '{"q":"?"}') ],
        );

        $json = $this->captureExport(101);

        $this->assertCount(1, $json['h5p_contents']);
        $this->assertSame(42, $json['h5p_contents'][0]['original_id']);
    }

    public function test_export_records_library_name_major_minor_not_numeric_id(): void
    {
        $this->primeExportFixtures(
            postId: 102,
            stepsJson: wp_json_encode([['title' => 'X', 'h5p_id' => 7]]),
            h5pRows: [ 7 => $this->h5pRow(7, 'H5P.MultiChoice', 1, 16, '{"q":"?"}', disable: 3) ],
        );

        $json = $this->captureExport(102);

        $entry = $json['h5p_contents'][0];
        $this->assertSame('H5P.MultiChoice', $entry['library']['name']);
        $this->assertSame(1,  $entry['library']['major_version']);
        $this->assertSame(16, $entry['library']['minor_version']);
        $this->assertArrayNotHasKey('library_id', $entry);
        $this->assertSame(3, $entry['disable']);
        $this->assertSame('{"q":"?"}', $entry['parameters']);
    }

    public function test_import_aborts_when_h5p_contents_present_but_plugin_inactive(): void
    {
        unset($GLOBALS['H5P_Plugin']);
        WPStubs::$returns['class_exists'] = ['H5P_Plugin' => false];
        WPStubs::$returns['current_user_can'] = true;

        $package = [
            'pbsg_version'  => PBSG_Export_Import::EXPORT_VERSION,
            'title'         => 'With quiz',
            'header_note'   => '', 'post_content' => '', 'steps' => [], 'attachments' => [],
            'h5p_contents'  => [[
                'original_id' => 1, 'title' => 'Q', 'parameters' => '{}', 'disable' => 1,
                'library' => ['name' => 'H5P.MultiChoice', 'major_version' => 1, 'minor_version' => 16],
            ]],
        ];
        $tmp = tempnam(sys_get_temp_dir(), 'pbsgexp');
        file_put_contents($tmp, wp_json_encode($package));
        $_FILES['pbsg_import_file'] = ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp];

        try { PBSG_Export_Import::handle_import(); } catch (WPDieException $e) {}
        @unlink($tmp);

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
        $msg = WPStubs::callArgs('wp_send_json_error', 0)[0]['message'];
        $this->assertStringContainsString('H5P plugin', $msg);
        $this->assertSame(0, $this->wpdb->insert_id, 'no DB writes should happen before preflight');
    }

    public function test_import_aborts_when_library_missing_listing_all_misses(): void
    {
        $this->declareH5PPluginAlias();
        WPStubs::$returns['current_user_can'] = true;
        $this->wpdb->returns['h5p_library_resolutions'] = [
            'H5P.MultiChoice|1|16' => 9001,
        ];

        $package = $this->packageWithH5PContents([
            ['name' => 'H5P.MultiChoice',  'major_version' => 1, 'minor_version' => 16],
            ['name' => 'H5P.Blanks',       'major_version' => 1, 'minor_version' => 14],
            ['name' => 'H5P.DragQuestion', 'major_version' => 1, 'minor_version' => 14],
        ]);
        $this->runImportWithPackage($package);

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
        $msg = WPStubs::callArgs('wp_send_json_error', 0)[0]['message'];
        $this->assertStringContainsString('H5P.Blanks 1.14', $msg);
        $this->assertStringContainsString('H5P.DragQuestion 1.14', $msg);
        $this->assertStringNotContainsString('H5P.MultiChoice', $msg);
        $this->assertSame([], $this->fakeCore->saveContentCalls, 'no saveContent calls before full resolution');
    }

    public function test_import_proceeds_when_all_libraries_resolve(): void
    {
        $this->declareH5PPluginAlias();
        WPStubs::$returns['current_user_can'] = true;
        WPStubs::$returns['get_current_user_id'] = 1;
        WPStubs::$returns['wp_insert_post'] = 5555;
        $this->wpdb->returns['h5p_library_resolutions'] = [
            'H5P.MultiChoice|1|16' => 9001,
        ];
        $this->fakeCore->saveContentReturns = [777];

        $package = $this->packageWithH5PContents([
            ['name' => 'H5P.MultiChoice', 'major_version' => 1, 'minor_version' => 16],
        ]);
        $this->runImportWithPackage($package);

        $this->assertFalse(WPStubs::wasCalled('wp_send_json_error'));
    }

    public function test_import_calls_saveContent_with_resolved_library_and_verbatim_parameters(): void
    {
        $this->declareH5PPluginAlias();
        WPStubs::$returns['current_user_can'] = true;
        WPStubs::$returns['get_current_user_id'] = 1;
        WPStubs::$returns['wp_insert_post'] = 4242;
        $this->wpdb->returns['h5p_library_resolutions'] = [
            'H5P.MultiChoice|1|16' => 9001,
        ];
        $this->fakeCore->saveContentReturns = [111];

        $package = $this->packageWithH5PContents([
            ['name' => 'H5P.MultiChoice', 'major_version' => 1, 'minor_version' => 16],
        ]);
        $package['h5p_contents'][0]['parameters'] = '{"question":"<em>A?</em>","answers":[{"text":"X"}]}';
        $this->runImportWithPackage($package);

        $this->assertCount(1, $this->fakeCore->saveContentCalls);
        $call = $this->fakeCore->saveContentCalls[0];
        $this->assertSame(9001, $call['library']['libraryId']);
        $this->assertSame('H5P.MultiChoice', $call['library']['name']);
        $this->assertSame(1, $call['library']['majorVersion']);
        $this->assertSame(16, $call['library']['minorVersion']);
        $this->assertSame('{"question":"<em>A?</em>","answers":[{"text":"X"}]}', $call['parameters']);
        $this->assertSame(1, $call['disable']);
    }

    public function test_import_aborts_with_orphan_warning_on_saveContent_error(): void
    {
        $this->declareH5PPluginAlias();
        WPStubs::$returns['current_user_can'] = true;
        $this->wpdb->returns['h5p_library_resolutions'] = [
            'H5P.MultiChoice|1|16' => 9001,
        ];
        $this->fakeCore->failNext = true;

        $package = $this->packageWithH5PContents([
            ['name' => 'H5P.MultiChoice', 'major_version' => 1, 'minor_version' => 16],
        ]);
        $this->runImportWithPackage($package);

        $this->assertTrue(WPStubs::wasCalled('wp_send_json_error'));
        $msg = WPStubs::callArgs('wp_send_json_error', 0)[0]['message'];
        $this->assertStringContainsString('simulated saveContent failure', $msg);
    }

    public function test_import_remaps_step_h5p_id_tokens_to_new_ids(): void
    {
        $this->declareH5PPluginAlias();
        WPStubs::$returns['current_user_can'] = true;
        WPStubs::$returns['get_current_user_id'] = 1;
        WPStubs::$returns['wp_insert_post'] = 6000;
        $this->wpdb->returns['h5p_library_resolutions'] = [
            'H5P.MultiChoice|1|16' => 9001,
            'H5P.Blanks|1|14'      => 9002,
        ];
        $this->fakeCore->saveContentReturns = [501, 502];

        $package = $this->packageWithH5PContents([
            ['name' => 'H5P.MultiChoice', 'major_version' => 1, 'minor_version' => 16],
            ['name' => 'H5P.Blanks',      'major_version' => 1, 'minor_version' => 14],
        ]);
        $package['steps'] = [
            ['title' => 'A', 'h5p_id' => 'h5p_1'],
            ['title' => 'B', 'h5p_id' => 'h5p_2'],
            ['title' => 'C', 'h5p_id' => 0],
        ];
        $this->runImportWithPackage($package);

        $this->assertTrue(WPStubs::wasCalled('wp_insert_post'));
        $postarr = WPStubs::callArgs('wp_insert_post', 0)[0];
        $steps = json_decode($postarr['meta_input']['_pbsg_steps_json'], true);
        $this->assertSame(501, $steps[0]['h5p_id']);
        $this->assertSame(502, $steps[1]['h5p_id']);
        $this->assertSame(0,   $steps[2]['h5p_id']);
    }

    public function test_export_tokenizes_step_h5p_id_integers(): void
    {
        $this->primeExportFixtures(
            postId: 103,
            stepsJson: wp_json_encode([
                ['title' => 'One',  'h5p_id' => 42],
                ['title' => 'Two',  'h5p_id' => 43],
                ['title' => 'Text', 'h5p_id' => 0],
            ]),
            h5pRows: [
                42 => $this->h5pRow(42, 'H5P.MultiChoice', 1, 16, '{}'),
                43 => $this->h5pRow(43, 'H5P.Blanks',      1, 14, '{}'),
            ],
        );

        $json = $this->captureExport(103);

        $this->assertSame('h5p_42', $json['steps'][0]['h5p_id']);
        $this->assertSame('h5p_43', $json['steps'][1]['h5p_id']);
        $this->assertSame(0,        $json['steps'][2]['h5p_id']);
    }

    /* ---------- helpers ---------- */

    /** @param array<int, array<string,mixed>> $h5pRows keyed by content id */
    private function primeExportFixtures(int $postId, string $stepsJson, array $h5pRows): void
    {
        $_POST['nonce'] = 'n';
        $_POST['post_id'] = (string) $postId;

        // The WPStubs::current_user_can stub returns the preset value directly
        // (it does not consult a resolver), so set it to true for the happy path.
        WPStubs::$returns['current_user_can'] = true;
        WPStubs::$returns['current_user_can_resolver'] = static fn (): bool => true;

        WPStubs::$returns['get_post'] = (object) [
            'ID' => $postId, 'post_title' => "Tutorial {$postId}", 'post_content' => '',
        ];

        // get_post_meta stub uses a flat key -> value map, not nested by post_id.
        WPStubs::$returns['get_post_meta'] = [
            '_pbsg_steps_json'     => $stepsJson,
            '_pbsg_header_note'    => '',
            '_pbsg_cover_image_id' => 0,
        ];

        $this->wpdb->returns['h5p_content_rows'] = $h5pRows;
    }

    /** @return array<string,mixed> */
    private function captureExport(int $postId): array
    {
        // Swallow the "headers already sent" warning that the real header()
        // emits in a CLI/PHPUnit context — the JSON body is still echoed
        // before exit/die, and that's what we're validating.
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
        try {
            PBSG_Export_Import::handle_export();
        } catch (WPDieException $e) {
        } finally {
            $raw = ob_get_clean();
            restore_error_handler();
        }

        $decoded = json_decode((string) $raw, true);
        $this->assertIsArray($decoded, 'export output must be valid JSON');
        return $decoded;
    }

    private function declareH5PPluginAlias(): void
    {
        if (!class_exists('H5P_Plugin', false)) {
            class_alias(FakeH5PPlugin::class, 'H5P_Plugin');
        }
    }

    /** @param list<array{name:string,major_version:int,minor_version:int}> $libraries */
    private function packageWithH5PContents(array $libraries): array
    {
        $h5p = [];
        foreach ($libraries as $i => $lib) {
            $h5p[] = [
                'original_id' => $i + 1,
                'title'       => 'Q' . ($i + 1),
                'parameters'  => '{"q":"?"}',
                'disable'     => 1,
                'library'     => $lib,
            ];
        }
        return [
            'pbsg_version' => PBSG_Export_Import::EXPORT_VERSION,
            'title'        => 'T',
            'header_note'  => '',
            'post_content' => '',
            'steps'        => [],
            'attachments'  => [],
            'h5p_contents' => $h5p,
        ];
    }

    private function runImportWithPackage(array $package): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pbsgexp');
        file_put_contents($tmp, wp_json_encode($package));
        $_FILES['pbsg_import_file'] = ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp];
        try { PBSG_Export_Import::handle_import(); } catch (WPDieException $e) {}
        @unlink($tmp);
    }

    /** @return array<string,mixed> */
    private function h5pRow(int $id, string $libName, int $major, int $minor, string $parameters, int $disable = 1): array
    {
        return [
            'id'            => $id,
            'title'         => "Quiz {$id}",
            'parameters'    => $parameters,
            'disable'       => $disable,
            'library_name'  => $libName,
            'major_version' => $major,
            'minor_version' => $minor,
        ];
    }
}
