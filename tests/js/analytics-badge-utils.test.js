/**
 * Unit tests for analytics badge utilities (Stretch Goal 5 — Dynamic Benchmarks).
 *
 * Tests:
 *  - getBadgeColor() with default and custom thresholds
 *  - getBadgeColorInverse() with default and custom thresholds
 *  - getNeedsAttentionFlags() with per-tutorial benchmarks
 *
 * @package PB_Split_Guide
 * @since   0.5.0
 */

const {
  getBadgeColor,
  getBadgeColorInverse,
  getNeedsAttentionFlags,
} = require('./helpers/analytics-badge-utils');

// =====================================================================
// getBadgeColor — higher-is-better metrics
// =====================================================================

describe('getBadgeColor', () => {

  describe('with default thresholds (green >= 70, amber >= 50)', () => {
    it('returns green for rate >= 70', () => {
      expect(getBadgeColor(70)).toBe('green');
      expect(getBadgeColor(100)).toBe('green');
      expect(getBadgeColor(85.5)).toBe('green');
    });

    it('returns amber for 50 <= rate < 70', () => {
      expect(getBadgeColor(50)).toBe('amber');
      expect(getBadgeColor(69.9)).toBe('amber');
      expect(getBadgeColor(55)).toBe('amber');
    });

    it('returns red for rate < 50', () => {
      expect(getBadgeColor(49.9)).toBe('red');
      expect(getBadgeColor(0)).toBe('red');
      expect(getBadgeColor(25)).toBe('red');
    });
  });

  describe('with custom thresholds', () => {
    it('uses custom green threshold', () => {
      expect(getBadgeColor(80, 80, 50)).toBe('green');
      expect(getBadgeColor(79, 80, 50)).toBe('amber');
    });

    it('uses custom amber threshold', () => {
      expect(getBadgeColor(40, 70, 40)).toBe('amber');
      expect(getBadgeColor(39, 70, 40)).toBe('red');
    });

    it('handles very low thresholds (easy tutorial)', () => {
      // Easy tutorial: green >= 30, amber >= 15
      expect(getBadgeColor(30, 30, 15)).toBe('green');
      expect(getBadgeColor(20, 30, 15)).toBe('amber');
      expect(getBadgeColor(10, 30, 15)).toBe('red');
    });

    it('handles very high thresholds (strict tutorial)', () => {
      // Strict: green >= 95, amber >= 85
      expect(getBadgeColor(90, 95, 85)).toBe('amber');
      expect(getBadgeColor(96, 95, 85)).toBe('green');
      expect(getBadgeColor(80, 95, 85)).toBe('red');
    });
  });

  describe('edge cases', () => {
    it('handles null/undefined rate as 0', () => {
      expect(getBadgeColor(null)).toBe('red');
      expect(getBadgeColor(undefined)).toBe('red');
      expect(getBadgeColor('')).toBe('red');
    });

    it('handles string rate by parsing it', () => {
      expect(getBadgeColor('75')).toBe('green');
      expect(getBadgeColor('55')).toBe('amber');
    });

    it('null thresholds fall back to defaults', () => {
      expect(getBadgeColor(70, null, null)).toBe('green');
      expect(getBadgeColor(50, undefined, undefined)).toBe('amber');
    });

    it('zero thresholds are valid (not treated as null)', () => {
      // green >= 0 means everything is green
      expect(getBadgeColor(0, 0, 0)).toBe('green');
    });
  });
});

// =====================================================================
// getBadgeColorInverse — lower-is-better metrics
// =====================================================================

describe('getBadgeColorInverse', () => {

  describe('with default thresholds (green <= 2, red > 10)', () => {
    it('returns green for value <= 2', () => {
      expect(getBadgeColorInverse(0)).toBe('green');
      expect(getBadgeColorInverse(1)).toBe('green');
      expect(getBadgeColorInverse(2)).toBe('green');
    });

    it('returns amber for 2 < value <= 10', () => {
      expect(getBadgeColorInverse(3)).toBe('amber');
      expect(getBadgeColorInverse(10)).toBe('amber');
      expect(getBadgeColorInverse(5.5)).toBe('amber');
    });

    it('returns red for value > 10', () => {
      expect(getBadgeColorInverse(11)).toBe('red');
      expect(getBadgeColorInverse(100)).toBe('red');
    });
  });

  describe('with custom thresholds', () => {
    it('uses custom low/high for give-up counts', () => {
      // Custom: green <= 5, amber <= 15, red > 15
      expect(getBadgeColorInverse(5, 5, 15)).toBe('green');
      expect(getBadgeColorInverse(10, 5, 15)).toBe('amber');
      expect(getBadgeColorInverse(16, 5, 15)).toBe('red');
    });

    it('uses custom thresholds for retries', () => {
      // Strict: green <= 1, red > 3
      expect(getBadgeColorInverse(1, 1, 3)).toBe('green');
      expect(getBadgeColorInverse(2, 1, 3)).toBe('amber');
      expect(getBadgeColorInverse(4, 1, 3)).toBe('red');
    });
  });

  describe('edge cases', () => {
    it('handles null/undefined value as 0', () => {
      expect(getBadgeColorInverse(null)).toBe('green');
      expect(getBadgeColorInverse(undefined)).toBe('green');
    });

    it('zero thresholds are valid', () => {
      expect(getBadgeColorInverse(0, 0, 0)).toBe('green');
      expect(getBadgeColorInverse(1, 0, 0)).toBe('red');
    });
  });
});

// =====================================================================
// getNeedsAttentionFlags — per-tutorial attention flagging
// =====================================================================

describe('getNeedsAttentionFlags', () => {

  it('returns empty array when all tutorials pass default thresholds', () => {
    const tutorials = [
      { completion_rate: 80, avg_score: 70, benchmarks: {} },
      { completion_rate: 65, avg_score: 55, benchmarks: {} },
    ];
    const result = getNeedsAttentionFlags(tutorials);
    expect(result).toHaveLength(0);
  });

  it('flags tutorial below default completion threshold (60%)', () => {
    const tutorials = [
      { completion_rate: 55, avg_score: 70, benchmarks: {} },
    ];
    const result = getNeedsAttentionFlags(tutorials);
    expect(result).toHaveLength(1);
    expect(result[0].reasons).toContain('Completion rate below 60%');
  });

  it('flags tutorial below default score threshold (50%)', () => {
    const tutorials = [
      { completion_rate: 80, avg_score: 45, benchmarks: {} },
    ];
    const result = getNeedsAttentionFlags(tutorials);
    expect(result).toHaveLength(1);
    expect(result[0].reasons).toContain('Avg tutorial score below 50%');
  });

  it('flags both reasons when both thresholds fail', () => {
    const tutorials = [
      { completion_rate: 50, avg_score: 40, benchmarks: {} },
    ];
    const result = getNeedsAttentionFlags(tutorials);
    expect(result).toHaveLength(1);
    expect(result[0].reasons).toHaveLength(2);
  });

  describe('with per-tutorial custom benchmarks', () => {
    it('uses per-tutorial attention_completion threshold', () => {
      const tutorials = [
        {
          completion_rate: 55,
          avg_score: 70,
          benchmarks: { attention_completion: 50 }, // lenient threshold
        },
      ];
      // 55% >= 50% threshold → should NOT be flagged
      const result = getNeedsAttentionFlags(tutorials);
      expect(result).toHaveLength(0);
    });

    it('uses per-tutorial attention_score threshold', () => {
      const tutorials = [
        {
          completion_rate: 80,
          avg_score: 35,
          benchmarks: { attention_score: 30 }, // lenient for hard tutorial
        },
      ];
      // 35% >= 30% threshold → should NOT be flagged
      const result = getNeedsAttentionFlags(tutorials);
      expect(result).toHaveLength(0);
    });

    it('different tutorials can have different thresholds', () => {
      const tutorials = [
        {
          tutorial_name: 'Easy Tutorial',
          completion_rate: 55,
          avg_score: 60,
          benchmarks: { attention_completion: 70 }, // strict for easy tutorial
        },
        {
          tutorial_name: 'Hard Tutorial',
          completion_rate: 55,
          avg_score: 60,
          benchmarks: { attention_completion: 40 }, // lenient for hard tutorial
        },
      ];
      const result = getNeedsAttentionFlags(tutorials);
      // Easy tutorial flagged (55 < 70), hard tutorial NOT (55 >= 40)
      expect(result).toHaveLength(1);
      expect(result[0].tutorial.tutorial_name).toBe('Easy Tutorial');
    });

    it('includes the custom threshold value in the reason message', () => {
      const tutorials = [
        {
          completion_rate: 40,
          avg_score: 20,
          benchmarks: { attention_completion: 75, attention_score: 60 },
        },
      ];
      const result = getNeedsAttentionFlags(tutorials);
      expect(result[0].reasons[0]).toBe('Completion rate below 75%');
      expect(result[0].reasons[1]).toBe('Avg tutorial score below 60%');
    });
  });

  describe('edge cases', () => {
    it('handles empty tutorials array', () => {
      expect(getNeedsAttentionFlags([])).toHaveLength(0);
    });

    it('handles missing benchmarks property', () => {
      const tutorials = [
        { completion_rate: 55, avg_score: 45 },
      ];
      // Falls back to defaults (60, 50)
      const result = getNeedsAttentionFlags(tutorials);
      expect(result).toHaveLength(1);
    });

    it('handles zero values', () => {
      const tutorials = [
        { completion_rate: 0, avg_score: 0, benchmarks: {} },
      ];
      const result = getNeedsAttentionFlags(tutorials);
      expect(result).toHaveLength(1);
      expect(result[0].reasons).toHaveLength(2);
    });

    it('handles benchmark threshold of zero (everything passes)', () => {
      const tutorials = [
        {
          completion_rate: 0,
          avg_score: 0,
          benchmarks: { attention_completion: 0, attention_score: 0 },
        },
      ];
      // 0 is NOT < 0, so should not be flagged
      const result = getNeedsAttentionFlags(tutorials);
      expect(result).toHaveLength(0);
    });
  });
});
