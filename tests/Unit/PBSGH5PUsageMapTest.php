<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Helpers/MockWpdb.php';

/**
 * Unit tests for PBSG_H5P_Usage_Map transient-cached inverted index.
 */
class PBSGH5PUsageMapTest extends TestCase
{
    private MockWpdb $wpdb;

    protected function setUp(): void
    {
        WPStubs::reset();
        $this->wpdb = new MockWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        WPStubs::reset();
        unset($GLOBALS['wpdb']);
    }

    public function test_builds_map_from_postmeta_when_transient_cold(): void
    {
        WPStubs::$returns['transients'] = [];
        $this->wpdb->returns['get_results'] = [
            ['post_id' => 101, 'meta_value' => json_encode([
                ['h5p_id' => 42, 'title' => 'Step 1'],
                ['h5p_id' => 87, 'title' => 'Step 2'],
            ])],
            ['post_id' => 205, 'meta_value' => json_encode([
                ['h5p_id' => 42, 'title' => 'Step 1'],
                ['h5p_id' => 0, 'title' => 'Step 3'],
            ])],
        ];

        $map = PBSG_H5P_Usage_Map::get_map();

        $this->assertArrayHasKey(42, $map);
        $this->assertArrayHasKey(87, $map);
        $this->assertSame([101, 205], $map[42]);
        $this->assertSame([101], $map[87]);
        $this->assertArrayNotHasKey(0, $map);
        $this->assertTrue(WPStubs::wasCalled('set_transient'));
        $args = WPStubs::callArgs('set_transient', 0);
        $this->assertSame('pbsg_h5p_usage_map', $args[0]);
    }

    public function test_returns_cached_transient_when_warm(): void
    {
        $cached = [42 => [101, 205], 87 => [101]];
        WPStubs::$returns['transients'] = ['pbsg_h5p_usage_map' => $cached];

        $map = PBSG_H5P_Usage_Map::get_map();

        $this->assertSame($cached, $map);
        $this->assertEmpty($this->wpdb->queries);
    }

    public function test_count_returns_tutorial_count_for_h5p_id(): void
    {
        $cached = [42 => [101, 205, 310], 87 => [101]];
        WPStubs::$returns['transients'] = ['pbsg_h5p_usage_map' => $cached];

        $this->assertSame(3, PBSG_H5P_Usage_Map::count(42));
        $this->assertSame(1, PBSG_H5P_Usage_Map::count(87));
        $this->assertSame(0, PBSG_H5P_Usage_Map::count(999));
    }

    public function test_invalidate_deletes_transient(): void
    {
        PBSG_H5P_Usage_Map::invalidate();

        $this->assertTrue(WPStubs::wasCalled('delete_transient'));
        $args = WPStubs::callArgs('delete_transient', 0);
        $this->assertSame('pbsg_h5p_usage_map', $args[0]);
    }

    public function test_handles_empty_postmeta(): void
    {
        WPStubs::$returns['transients'] = [];
        $this->wpdb->returns['get_results'] = [];

        $map = PBSG_H5P_Usage_Map::get_map();

        $this->assertSame([], $map);
    }

    public function test_handles_malformed_json_in_postmeta(): void
    {
        WPStubs::$returns['transients'] = [];
        $this->wpdb->returns['get_results'] = [
            ['post_id' => 101, 'meta_value' => '{invalid json'],
            ['post_id' => 205, 'meta_value' => json_encode([
                ['h5p_id' => 42, 'title' => 'Step 1'],
            ])],
        ];

        $map = PBSG_H5P_Usage_Map::get_map();
        $this->assertSame([42 => [205]], $map);
    }
}
