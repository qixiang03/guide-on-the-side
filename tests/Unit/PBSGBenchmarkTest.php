<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Benchmark / Performance Threshold system (Stretch Goal 5).
 *
 * Covers:
 *  - resolve_benchmarks() three-tier cascade (hardcoded → site option → per-tutorial meta)
 *  - sanitize_benchmark_defaults() input validation
 *  - sanitize_ratio() clamping
 *  - Admin vs. librarian independence (admin defaults do NOT overwrite per-tutorial)
 *
 * @package PB_Split_Guide
 * @since   0.5.0
 */
class PBSGBenchmarkTest extends TestCase
{
    protected function setUp(): void
    {
        WPStubs::reset();
    }

    protected function tearDown(): void
    {
        WPStubs::reset();
    }

    /* ===================================================================
       Constants & Fallbacks
       =================================================================== */

    public function test_benchmark_fallbacks_has_all_required_keys(): void
    {
        $expected_keys = [
            'completion_rate_green', 'completion_rate_amber',
            'score_green', 'score_amber',
            'correct_rate_green', 'correct_rate_amber',
            'giveup_low', 'giveup_high',
            'retries_low', 'retries_high',
            'attention_completion', 'attention_score',
        ];

        foreach ($expected_keys as $key) {
            $this->assertArrayHasKey(
                $key,
                PB_Split_Guide_Plugin::BENCHMARK_FALLBACKS,
                "Missing required benchmark key: {$key}"
            );
        }

        $this->assertCount(12, PB_Split_Guide_Plugin::BENCHMARK_FALLBACKS);
    }

    public function test_benchmark_fallback_values_are_sensible(): void
    {
        $fb = PB_Split_Guide_Plugin::BENCHMARK_FALLBACKS;

        // Green must be >= amber for rate metrics
        $this->assertGreaterThanOrEqual($fb['completion_rate_amber'], $fb['completion_rate_green']);
        $this->assertGreaterThanOrEqual($fb['score_amber'], $fb['score_green']);
        $this->assertGreaterThanOrEqual($fb['correct_rate_amber'], $fb['correct_rate_green']);

        // Low must be <= high for inverse metrics
        $this->assertLessThanOrEqual($fb['giveup_high'], $fb['giveup_low']);
        $this->assertLessThanOrEqual($fb['retries_high'], $fb['retries_low']);

        // All values non-negative
        foreach ($fb as $key => $val) {
            $this->assertGreaterThanOrEqual(0, $val, "Fallback {$key} must be non-negative");
        }
    }

    public function test_meta_benchmarks_constant_defined(): void
    {
        $this->assertSame('_pbsg_benchmarks', PB_Split_Guide_Plugin::META_BENCHMARKS);
    }

    public function test_option_benchmark_defaults_constant_defined(): void
    {
        $this->assertSame('pbsg_benchmark_defaults', PB_Split_Guide_Plugin::OPTION_BENCHMARK_DEFAULTS);
    }

    /* ===================================================================
       resolve_benchmarks() — Three-Tier Cascade
       =================================================================== */

    public function test_resolve_benchmarks_returns_hardcoded_fallbacks_when_no_option_and_no_meta(): void
    {
        // No site option set, no tutorial ID
        $result = PB_Split_Guide_Plugin::resolve_benchmarks(0);
        $this->assertSame(PB_Split_Guide_Plugin::BENCHMARK_FALLBACKS, $result);
    }

    public function test_resolve_benchmarks_returns_hardcoded_fallbacks_with_no_args(): void
    {
        $result = PB_Split_Guide_Plugin::resolve_benchmarks();
        $this->assertSame(PB_Split_Guide_Plugin::BENCHMARK_FALLBACKS, $result);
    }

    public function test_resolve_benchmarks_applies_site_wide_defaults(): void
    {
        // Set a site-wide option that overrides some values
        $site_defaults = PB_Split_Guide_Plugin::BENCHMARK_FALLBACKS;
        $site_defaults['completion_rate_green'] = 80;
        $site_defaults['attention_completion']  = 45;

        WPStubs::$returns['get_option_' . PB_Split_Guide_Plugin::OPTION_BENCHMARK_DEFAULTS] =
            json_encode($site_defaults);

        $result = PB_Split_Guide_Plugin::resolve_benchmarks(0);

        $this->assertSame(80, $result['completion_rate_green']);
        $this->assertSame(45, $result['attention_completion']);
        // Other values should still match fallbacks
        $this->assertSame(
            PB_Split_Guide_Plugin::BENCHMARK_FALLBACKS['score_green'],
            $result['score_green']
        );
    }

    public function test_resolve_benchmarks_per_tutorial_overrides_site_default(): void
    {
        // Site default: completion_rate_green = 80
        $site_defaults = PB_Split_Guide_Plugin::BENCHMARK_FALLBACKS;
        $site_defaults['completion_rate_green'] = 80;
        WPStubs::$returns['get_option_' . PB_Split_Guide_Plugin::OPTION_BENCHMARK_DEFAULTS] =
            json_encode($site_defaults);

        // Per-tutorial override: completion_rate_green = 55 (it's a hard tutorial)
        $per_tutorial = ['completion_rate_green' => 55, 'attention_completion' => 30];
        WPStubs::$returns['get_post_meta'] = [
            PB_Split_Guide_Plugin::META_BENCHMARKS => json_encode($per_tutorial),
        ];

        $result = PB_Split_Guide_Plugin::resolve_benchmarks(42);

        // Per-tutorial wins over site default
        $this->assertSame(55, $result['completion_rate_green']);
        $this->assertSame(30, $result['attention_completion']);

        // Non-overridden keys still use site or hardcoded fallback
        $this->assertSame(
            PB_Split_Guide_Plugin::BENCHMARK_FALLBACKS['score_green'],
            $result['score_green']
        );
    }

    public function test_resolve_benchmarks_partial_per_tutorial_inherits_rest_from_site(): void
    {
        // Site default sets everything to custom values
        $site = PB_Split_Guide_Plugin::BENCHMARK_FALLBACKS;
        $site['score_green'] = 85;
        $site['score_amber'] = 60;
        $site['giveup_low']  = 5;
        WPStubs::$returns['get_option_' . PB_Split_Guide_Plugin::OPTION_BENCHMARK_DEFAULTS] =
            json_encode($site);

        // Tutorial only overrides score_green
        WPStubs::$returns['get_post_meta'] = [
            PB_Split_Guide_Plugin::META_BENCHMARKS => json_encode(['score_green' => 90]),
        ];

        $result = PB_Split_Guide_Plugin::resolve_benchmarks(99);

        $this->assertSame(90, $result['score_green']);   // per-tutorial
        $this->assertSame(60, $result['score_amber']);   // site default
        $this->assertSame(5,  $result['giveup_low']);    // site default
        $this->assertSame(
            PB_Split_Guide_Plugin::BENCHMARK_FALLBACKS['retries_high'],
            $result['retries_high']
        ); // hardcoded fallback (site didn't override this)
    }

    public function test_resolve_benchmarks_empty_per_tutorial_values_are_skipped(): void
    {
        // Per-tutorial meta has empty string values — should NOT override
        WPStubs::$returns['get_post_meta'] = [
            PB_Split_Guide_Plugin::META_BENCHMARKS => json_encode([
                'completion_rate_green' => '',
                'score_green'          => '',
            ]),
        ];

        $result = PB_Split_Guide_Plugin::resolve_benchmarks(10);

        // Should fall back to hardcoded since no site option and empty meta values
        $this->assertSame(
            PB_Split_Guide_Plugin::BENCHMARK_FALLBACKS['completion_rate_green'],
            $result['completion_rate_green']
        );
    }

    public function test_resolve_benchmarks_malformed_site_option_falls_back_gracefully(): void
    {
        WPStubs::$returns['get_option_' . PB_Split_Guide_Plugin::OPTION_BENCHMARK_DEFAULTS] =
            'not valid json {{{}}}';

        $result = PB_Split_Guide_Plugin::resolve_benchmarks(0);
        $this->assertSame(PB_Split_Guide_Plugin::BENCHMARK_FALLBACKS, $result);
    }

    public function test_resolve_benchmarks_malformed_per_tutorial_meta_falls_back(): void
    {
        WPStubs::$returns['get_post_meta'] = [
            PB_Split_Guide_Plugin::META_BENCHMARKS => 'broken json',
        ];

        $result = PB_Split_Guide_Plugin::resolve_benchmarks(42);
        $this->assertSame(PB_Split_Guide_Plugin::BENCHMARK_FALLBACKS, $result);
    }

    public function test_resolve_benchmarks_empty_meta_string_uses_site_default(): void
    {
        $site = PB_Split_Guide_Plugin::BENCHMARK_FALLBACKS;
        $site['retries_low'] = 1;
        WPStubs::$returns['get_option_' . PB_Split_Guide_Plugin::OPTION_BENCHMARK_DEFAULTS] =
            json_encode($site);

        // Empty string meta = no override
        WPStubs::$returns['get_post_meta'] = [
            PB_Split_Guide_Plugin::META_BENCHMARKS => '',
        ];

        $result = PB_Split_Guide_Plugin::resolve_benchmarks(42);
        $this->assertSame(1, $result['retries_low']);
    }

    /* ===================================================================
       Admin ≠ Librarian independence
       =================================================================== */

    public function test_admin_default_does_not_overwrite_librarian_override(): void
    {
        // Librarian set completion_rate_green = 40 for a hard tutorial
        WPStubs::$returns['get_post_meta'] = [
            PB_Split_Guide_Plugin::META_BENCHMARKS => json_encode(['completion_rate_green' => 40]),
        ];

        // Admin later changes site default to 90
        WPStubs::$returns['get_option_' . PB_Split_Guide_Plugin::OPTION_BENCHMARK_DEFAULTS] =
            json_encode(array_merge(PB_Split_Guide_Plugin::BENCHMARK_FALLBACKS, ['completion_rate_green' => 90]));

        $result = PB_Split_Guide_Plugin::resolve_benchmarks(42);

        // Librarian's 40 wins, not admin's 90
        $this->assertSame(40, $result['completion_rate_green']);
    }

    /* ===================================================================
       sanitize_benchmark_defaults()
       =================================================================== */

    public function test_sanitize_benchmark_defaults_returns_valid_json(): void
    {
        $plugin = new PB_Split_Guide_Plugin();
        $input  = json_encode(['completion_rate_green' => 80, 'score_amber' => 45]);
        $result = $plugin->sanitize_benchmark_defaults($input);

        $this->assertIsString($result);
        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertSame(80, $decoded['completion_rate_green']);
        $this->assertSame(45, $decoded['score_amber']);
    }

    public function test_sanitize_benchmark_defaults_fills_missing_keys_with_fallbacks(): void
    {
        $plugin = new PB_Split_Guide_Plugin();
        $input  = json_encode(['completion_rate_green' => 80]); // Only one key
        $result = json_decode($plugin->sanitize_benchmark_defaults($input), true);

        // All 12 keys must be present
        $this->assertCount(12, $result);
        $this->assertSame(80, $result['completion_rate_green']);
        // Missing keys filled with fallback
        $this->assertSame(
            PB_Split_Guide_Plugin::BENCHMARK_FALLBACKS['score_green'],
            $result['score_green']
        );
    }

    public function test_sanitize_benchmark_defaults_clamps_negative_to_zero(): void
    {
        $plugin = new PB_Split_Guide_Plugin();
        $input  = json_encode(['completion_rate_green' => -10, 'giveup_low' => -5]);
        $result = json_decode($plugin->sanitize_benchmark_defaults($input), true);

        $this->assertSame(0, $result['completion_rate_green']);
        $this->assertSame(0, $result['giveup_low']);
    }

    public function test_sanitize_benchmark_defaults_handles_non_json_input(): void
    {
        $plugin = new PB_Split_Guide_Plugin();
        $result = json_decode($plugin->sanitize_benchmark_defaults('not json'), true);

        // Should return all fallbacks
        $this->assertSame(PB_Split_Guide_Plugin::BENCHMARK_FALLBACKS, $result);
    }

    public function test_sanitize_benchmark_defaults_handles_empty_string(): void
    {
        $plugin = new PB_Split_Guide_Plugin();
        $result = json_decode($plugin->sanitize_benchmark_defaults(''), true);

        $this->assertSame(PB_Split_Guide_Plugin::BENCHMARK_FALLBACKS, $result);
    }

    public function test_sanitize_benchmark_defaults_converts_floats_to_int(): void
    {
        $plugin = new PB_Split_Guide_Plugin();
        $input  = json_encode(['completion_rate_green' => 72.9]);
        $result = json_decode($plugin->sanitize_benchmark_defaults($input), true);

        $this->assertSame(72, $result['completion_rate_green']);
    }

    public function test_sanitize_benchmark_defaults_ignores_unknown_keys(): void
    {
        $plugin = new PB_Split_Guide_Plugin();
        $input  = json_encode(['unknown_key' => 999, 'completion_rate_green' => 80]);
        $result = json_decode($plugin->sanitize_benchmark_defaults($input), true);

        $this->assertArrayNotHasKey('unknown_key', $result);
        $this->assertCount(12, $result);
    }

    /* ===================================================================
       sanitize_ratio()
       =================================================================== */

    public function test_sanitize_ratio_clamps_below_min(): void
    {
        $plugin = new PB_Split_Guide_Plugin();
        $this->assertSame(PB_Split_Guide_Plugin::RATIO_MIN, $plugin->sanitize_ratio(5));
    }

    public function test_sanitize_ratio_clamps_above_max(): void
    {
        $plugin = new PB_Split_Guide_Plugin();
        $this->assertSame(PB_Split_Guide_Plugin::RATIO_MAX, $plugin->sanitize_ratio(90));
    }

    public function test_sanitize_ratio_passes_valid_value(): void
    {
        $plugin = new PB_Split_Guide_Plugin();
        $this->assertSame(35, $plugin->sanitize_ratio(35));
    }

    public function test_sanitize_ratio_handles_negative(): void
    {
        $plugin = new PB_Split_Guide_Plugin();
        // absint(-25) = 25, which is within range
        $this->assertSame(25, $plugin->sanitize_ratio(-25));
    }

    public function test_sanitize_ratio_handles_string_input(): void
    {
        $plugin = new PB_Split_Guide_Plugin();
        $this->assertSame(30, $plugin->sanitize_ratio('30'));
    }

    /* ===================================================================
       resolve_benchmarks() — all 12 keys always present
       =================================================================== */

    public function test_resolve_benchmarks_always_returns_all_12_keys(): void
    {
        // Even with partial site option and partial per-tutorial meta
        WPStubs::$returns['get_option_' . PB_Split_Guide_Plugin::OPTION_BENCHMARK_DEFAULTS] =
            json_encode(['score_green' => 99]);
        WPStubs::$returns['get_post_meta'] = [
            PB_Split_Guide_Plugin::META_BENCHMARKS => json_encode(['giveup_low' => 1]),
        ];

        $result = PB_Split_Guide_Plugin::resolve_benchmarks(42);

        $this->assertCount(12, $result);
        foreach (array_keys(PB_Split_Guide_Plugin::BENCHMARK_FALLBACKS) as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }
}
