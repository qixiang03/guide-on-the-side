/**
 * Extracted badge utility functions from analytics-dashboard.js
 * for unit testing. These mirror the exact implementations in the
 * production IIFE — keep them in sync.
 */

/**
 * Badge color for rate metrics (higher is better).
 * @param {number} rate       - The metric value (0–100)
 * @param {number} greenMin   - Green threshold (>= this = green). Default 70.
 * @param {number} amberMin   - Amber threshold (>= this = amber). Default 50.
 */
function getBadgeColor( rate, greenMin, amberMin ) {
    rate = parseFloat( rate ) || 0;
    greenMin = ( greenMin !== undefined && greenMin !== null ) ? greenMin : 70;
    amberMin = ( amberMin !== undefined && amberMin !== null ) ? amberMin : 50;
    if ( rate >= greenMin ) return 'green';
    if ( rate >= amberMin ) return 'amber';
    return 'red';
}

/**
 * Badge color for metrics where lower is better (e.g. give-ups, retries).
 * @param {number} value          - The metric value
 * @param {number} lowThreshold   - Green threshold (<= this = green). Default 2.
 * @param {number} highThreshold  - Red threshold (> this = red). Default 10.
 */
function getBadgeColorInverse( value, lowThreshold, highThreshold ) {
    value = parseFloat( value ) || 0;
    lowThreshold  = ( lowThreshold  !== undefined && lowThreshold  !== null ) ? lowThreshold  : 2;
    highThreshold = ( highThreshold !== undefined && highThreshold !== null ) ? highThreshold : 10;
    if ( value <= lowThreshold ) return 'green';
    if ( value <= highThreshold ) return 'amber';
    return 'red';
}

/**
 * Determine which tutorials need attention, using per-tutorial benchmarks.
 * Returns an array of flagged tutorial objects with reasons.
 *
 * @param {Array} tutorials - Array of tutorial data objects, each with
 *                            completion_rate, avg_score, and benchmarks.
 * @returns {Array} flagged tutorials with { tutorial, reasons } entries
 */
function getNeedsAttentionFlags( tutorials ) {
    const flagged = [];
    tutorials.forEach( function( t ) {
        const rate  = parseFloat( t.completion_rate ) || 0;
        const score = parseFloat( t.avg_score ) || 0;
        const b     = t.benchmarks || {};
        const compAmber  = ( b.completion_rate_amber !== undefined ) ? b.completion_rate_amber : 50;
        const scoreAmber = ( b.score_amber !== undefined ) ? b.score_amber : 50;

        const reasons = [];
        if ( rate < compAmber ) reasons.push( 'Completion rate in red zone (below ' + compAmber + '%)' );
        if ( score < scoreAmber ) reasons.push( 'Avg tutorial score in red zone (below ' + scoreAmber + '%)' );

        if ( reasons.length > 0 ) {
            flagged.push( { tutorial: t, reasons: reasons } );
        }
    } );
    return flagged;
}

module.exports = { getBadgeColor, getBadgeColorInverse, getNeedsAttentionFlags };
