/**
 * Analytics Dashboard JS — Renders the 3 dashboard views.
 *
 * All charts are pure CSS/inline SVG (no Chart.js) to guarantee rendering
 * in all WordPress admin environments without CDN dependency.
 *
 * Views:
 *   A. Overview — KPIs, trend chart, device breakdown, tutorial table
 *   B. Tutorial Detail — daily views, completion funnel, dwell times, questions
 *   C. Question Drill-Down — attempt distribution, outcome doughnut, correct rate trend
 *
 * @package PB_Split_Guide
 * @since   1.0.0
 */

( function( $ ) {
    'use strict';

    if ( typeof pbsgAnalytics === 'undefined' ) return;

    const config = pbsgAnalytics;
    const $wrap  = $( '#pbsg-dashboard-content' );

    // Compare view state
    let compareIds = [];

    // =========================================================================
    // INIT — Load data for the current view
    // =========================================================================

    function init() {
        const view       = $wrap.data( 'view' ) || 'overview';
        const tutorialId = $wrap.data( 'tutorial-id' ) || 0;
        const h5pId      = $wrap.data( 'h5p-id' ) || 0;
        const qIndex     = $wrap.data( 'q-index' ) || 0;

        // Initialize compareIds from URL if on compare view
        if ( view === 'compare' ) {
            const urlParams = new URLSearchParams( window.location.search );
            const idsParam  = urlParams.get( 'ids' ) || '';
            compareIds = idsParam ? idsParam.split( ',' ).map( Number ).filter( Boolean ) : [];

            // Hide KPI stats row for compare view (device filter already absent in PHP for compare)
            $( '#pbsg-stats-row' ).hide();
        }

        loadView( view, tutorialId, h5pId, qIndex );

        // Bind filter button
        $( '#pbsg-apply-filters' ).on( 'click', function() {
            loadView( view, tutorialId, h5pId, qIndex );
        } );

        // Bind refresh
        $( '#pbsg-refresh-btn' ).on( 'click', function() {
            loadView( view, tutorialId, h5pId, qIndex );
        } );

        // Compare view event delegation
        $( document ).on( 'change', '.pbsg-compare-select', function() {
            const colIndex  = parseInt( $( this ).data( 'col' ), 10 );
            const newId     = parseInt( $( this ).val(), 10 );
            if ( newId ) {
                addTutorial( colIndex, newId );
            }
        } );

        $( document ).on( 'click', '.pbsg-col-change-btn', function() {
            const $col = $( this ).closest( '.col-tutorial' );
            $col.addClass( 'swapping' );
            $col.find( '.col-swap-select' ).focus();
        } );

        $( document ).on( 'blur', '.col-swap-select', function() {
            $( this ).closest( '.col-tutorial' ).removeClass( 'swapping' );
        } );

        $( document ).on( 'click', '.pbsg-col-remove', function() {
            const colIndex = parseInt( $( this ).data( 'col' ), 10 );
            removeTutorial( colIndex );
        } );
    }

    function loadView( view, tutorialId, h5pId, qIndex ) {
        if ( view === 'compare' ) {
            if ( compareIds.length === 0 ) {
                hideLoading();
                renderComparison( { tutorials: {}, date_scope: {} } );
                return;
            }
            loadComparisonData();
            return;
        }

        showLoading();

        const params = {
            action:    'pbsg_get_analytics',
            view:      view,
            date_from: $( '#pbsg-date-from' ).val(),
            date_to:   $( '#pbsg-date-to' ).val(),
        };

        if ( view === 'overview' || view === 'tutorial' ) {
            params.device = $( '#pbsg-device-filter' ).val() || '';
        }
        if ( tutorialId ) params.tutorial_id = tutorialId;
        if ( h5pId )      params.h5p_id      = h5pId;
        if ( view === 'question' ) params.q_index = qIndex;

        $.get( config.ajaxUrl, params )
            .done( function( response ) {
                if ( response.success && response.data ) {
                    hideLoading();
                    switch ( view ) {
                        case 'overview':  renderOverview( response.data ); break;
                        case 'tutorial':  renderTutorialDetail( response.data ); break;
                        case 'question':  renderQuestionDetail( response.data ); break;
                    }
                } else {
                    showEmpty();
                }
            } )
            .fail( function() {
                showEmpty();
            } );
    }

    // =========================================================================
    // VIEW A — OVERVIEW DASHBOARD
    // =========================================================================

    function renderOverview( data ) {
        const totals    = data.totals || {};
        const tutorials = data.tutorials || [];
        const trend     = data.daily_trend || [];
        const devices   = data.device_breakdown || [];
        const bench     = data.benchmarks || {};

        if ( ! tutorials.length ) {
            showEmpty();
            return;
        }

        // KPIs (use site-wide benchmarks for overview aggregates)
        renderKPIs( [
            { label: 'Total Views', value: formatNumber( totals.total_views ), color: 'blue' },
            { label: 'Total Completions', value: formatNumber( totals.total_completions ), color: 'blue' },
            { label: 'Avg Completion Rate', value: totals.avg_completion + '%', color: getBadgeColor( totals.avg_completion, bench.completion_rate_green, bench.completion_rate_amber ) },
            { label: 'Avg Tutorial Score', value: totals.avg_score + '%', color: getBadgeColor( totals.avg_score, bench.score_green, bench.score_amber ), badge: 'all-time' },
        ] );

        // Main content grid
        let html = '<div class="pbsg-grid-sidebar">';

        // Left column — trend chart + tutorial table
        html += '<div>';

        // Trend chart card
        html += '<div class="pbsg-card">';
        html += '<div class="pbsg-card-header">Views & Completions Over Time</div>';
        html += renderTrendSVG( trend );
        html += '</div>';

        // Tutorial table card
        html += '<div class="pbsg-card">';
        html += '<div class="pbsg-card-header">';
        html += 'All Tutorials';
        html += '<a class="pbsg-card-action" href="' + getExportUrl( 'overview' ) + '">↓ Export CSV</a>';
        html += '</div>';
        html += renderTutorialTable( tutorials, trend );
        html += '</div>';

        html += '</div>'; // end left col

        // Right column — device breakdown + needs attention
        html += '<div>';

        // Device breakdown doughnut
        html += '<div class="pbsg-card">';
        html += '<div class="pbsg-card-header">Device Breakdown</div>';
        html += renderDeviceDoughnut( devices );
        html += '</div>';

        // Needs attention
        html += '<div class="pbsg-card">';
        html += '<div class="pbsg-card-header">⚠ Needs Attention</div>';
        html += renderNeedsAttention( tutorials );
        html += '</div>';

        html += '</div>'; // end right col
        html += '</div>'; // end grid

        $( '#pbsg-main-content' ).html( html ).show();
    }

    // =========================================================================
    // VIEW B — TUTORIAL DETAIL
    // =========================================================================

    function renderTutorialDetail( data ) {
        if ( data.error ) { showEmpty(); return; }

        const stats      = data.stats || {};
        const dailyViews = data.daily_views || [];
        const stepDwell  = data.step_dwell || {};
        const stepNames  = data.step_names || {};
        const questions  = data.questions || [];
        const deviceNote = data.device_note || '';
        const bench      = data.benchmarks || {};

        // KPIs for this tutorial (use per-tutorial benchmarks)
        renderKPIs( [
            { label: 'Total Views', value: formatNumber( stats.view_count ), color: 'blue' },
            { label: 'Completions', value: formatNumber( stats.completion_count ), color: 'blue' },
            { label: 'Completion Rate', value: stats.completion_rate + '%', color: getBadgeColor( stats.completion_rate, bench.completion_rate_green, bench.completion_rate_amber ) },
            { label: 'Avg Time', value: formatTime( stats.avg_time_seconds ), color: 'blue' },
        ] );

        // Update export button
        $( '#pbsg-export-btn' ).attr( 'href', getExportUrl( 'questions', stats.tutorial_page_id ) );

        const dateScope = data.date_scope || {};
        const scopeLabel = dateScope.date_from && dateScope.date_to
            ? dateScope.date_from + ' to ' + dateScope.date_to
            : '';

        let html = '';

        // Show device context notice if the detail view received a device filter
        if ( deviceNote ) {
            html += '<div class="pbsg-annotation pbsg-annotation-info"><strong>Device filter active:</strong> ' + escapeHtml( deviceNote ) + '</div>';
        }

        html += '<div class="pbsg-grid-2">';

        // Daily views bar chart
        html += '<div class="pbsg-card">';
        html += '<div class="pbsg-card-header">Daily Views' + ( scopeLabel ? ' <span class="pbsg-date-scope">(' + scopeLabel + ')</span>' : '' ) + '</div>';
        html += renderDailyBars( dailyViews );
        html += '</div>';

        // Completion funnel
        html += '<div class="pbsg-card">';
        html += '<div class="pbsg-card-header">Completion Funnel</div>';
        html += renderCompletionFunnel( stepDwell, stats.view_count, stepNames );
        html += '</div>';

        html += '</div>'; // end grid

        // Dwell time table
        html += '<div class="pbsg-card">';
        html += '<div class="pbsg-card-header">Step Dwell Time</div>';
        html += renderDwellTable( stepDwell, stepNames );
        html += '</div>';

        // Questions table
        if ( questions.length ) {
            html += '<div class="pbsg-card">';
            html += '<div class="pbsg-card-header">';
            html += 'Quiz Questions <span class="pbsg-alltime-badge">all-time</span>';
            html += '<a class="pbsg-card-action" href="' + getExportUrl( 'questions', stats.tutorial_page_id ) + '">↓ Export CSV</a>';
            html += '</div>';
            html += renderQuestionsTable( questions, stats.tutorial_page_id, bench );
            html += '</div>';
        }

        $( '#pbsg-main-content' ).html( html ).show();
    }

    // =========================================================================
    // VIEW C — QUESTION DRILL-DOWN
    // =========================================================================

    function renderQuestionDetail( data ) {
        if ( data.error ) { showEmpty(); return; }

        const q = data;
        const dist  = q.attempt_distribution || {};
        const retry = q.retry_stats || {};
        const bench = q.benchmarks || {};

        $( '#pbsg-export-btn' ).attr( 'href',
            getExportUrl( 'question_detail', q.tutorial_page_id, q.h5p_content_id, q.question_index ) );

        renderKPIs( [
            { label: 'Total Attempts', value: formatNumber( q.total_attempts ), color: 'blue', badge: 'all-time' },
            { label: 'Correct Rate', value: q.correct_rate + '%', color: getBadgeColor( q.correct_rate, bench.correct_rate_green, bench.correct_rate_amber ), badge: 'all-time' },
            { label: 'Give-ups', value: formatNumber( q.giveup_count ), color: getBadgeColorInverse( q.giveup_count, bench.giveup_low, bench.giveup_high ), badge: 'all-time' },
            { label: 'Avg Time', value: formatTime( q.avg_time_seconds ), color: 'blue', badge: 'all-time' },
            { label: 'Total Retries', value: formatNumber( retry.total_retries || 0 ), color: 'blue', badge: 'all-time' },
            { label: 'Max Retries', value: formatNumber( retry.max_retries_single_session || 0 ), color: getBadgeColorInverse( retry.max_retries_single_session || 0, bench.retries_low, bench.retries_high ), badge: 'all-time' },
        ] );

        let html = '<div class="pbsg-annotation"><strong>Note</strong><br>Question statistics are aggregated across all time. The date range filter does not apply to this view.</div>';
        html += '<div class="pbsg-grid-2">';

        // Attempt distribution bar chart
        html += '<div class="pbsg-card">';
        html += '<div class="pbsg-card-header">Attempt Distribution</div>';
        html += renderAttemptDistribution( dist );
        html += '</div>';

        // Outcome doughnut
        html += '<div class="pbsg-card">';
        html += '<div class="pbsg-card-header">Outcome Breakdown</div>';
        html += renderOutcomeDoughnut( q );
        html += '</div>';

        html += '</div>';

        // Question info
        if ( q.question_text ) {
            html += '<div class="pbsg-annotation">';
            html += '<strong>Question Text</strong><br>';
            html += escapeHtml( q.question_text );
            html += '</div>';
        }

        $( '#pbsg-main-content' ).html( html ).show();
    }

    // =========================================================================
    // CHART RENDERERS — Pure CSS/SVG
    // =========================================================================

    /**
     * SVG area chart for Views & Completions trend.
     */
    function renderTrendSVG( data ) {
        if ( ! data.length ) return '<p style="text-align:center;color:#888;padding:40px;">No trend data available</p>';

        const W = 600, H = 200, padL = 50, padR = 15, padT = 15, padB = 30;
        const chartW = W - padL - padR;
        const chartH = H - padT - padB;

        const maxVal  = Math.max( 1, ...data.map( d => Math.max( d.views || 0, d.completions || 0 ) ) );
        const scaleX  = chartW / Math.max( 1, data.length - 1 );
        const scaleY  = chartH / maxVal;

        let viewsPoints = '', compPoints = '';
        let viewsFill = '', compFill = '';

        data.forEach( ( d, i ) => {
            const x  = padL + i * scaleX;
            const v  = d.views || 0;
            const c  = Math.min( d.completions || 0, v ); // clamp: completions <= views
            const yv = padT + chartH - v * scaleY;
            const yc = padT + chartH - c * scaleY;
            viewsPoints += x + ',' + yv + ' ';
            compPoints  += x + ',' + yc + ' ';
        } );

        viewsFill = padL + ',' + ( padT + chartH ) + ' ' + viewsPoints + ( padL + ( data.length - 1 ) * scaleX ) + ',' + ( padT + chartH );
        compFill  = padL + ',' + ( padT + chartH ) + ' ' + compPoints + ( padL + ( data.length - 1 ) * scaleX ) + ',' + ( padT + chartH );

        // Grid lines
        let grid = '';
        const gridCount = 5;
        for ( let i = 0; i <= gridCount; i++ ) {
            const y   = padT + ( chartH / gridCount ) * i;
            const val = Math.round( maxVal - ( maxVal / gridCount ) * i );
            grid += '<line x1="' + padL + '" y1="' + y + '" x2="' + ( W - padR ) + '" y2="' + y + '" stroke="#E0E0E0" stroke-width="1"/>';
            grid += '<text x="' + ( padL - 8 ) + '" y="' + ( y + 4 ) + '" text-anchor="end" font-size="11" fill="#888" font-family="Roboto Condensed">' + val + '</text>';
        }

        // X-axis labels (show every Nth for readability)
        let xLabels = '';
        const step = Math.max( 1, Math.ceil( data.length / 8 ) );
        data.forEach( ( d, i ) => {
            if ( i % step === 0 || i === data.length - 1 ) {
                const x  = padL + i * scaleX;
                const dt = d.stat_date || '';
                const label = dt.substring( 5 ); // MM-DD
                xLabels += '<text x="' + x + '" y="' + ( H - 5 ) + '" text-anchor="middle" font-size="10" fill="#888" font-family="Roboto Condensed">' + label + '</text>';
            }
        } );

        let legend = '<div class="pbsg-chart-legend">';
        legend += '<span class="pbsg-chart-legend-item"><span class="pbsg-chart-legend-dot" style="background:#517E1B;"></span> Views</span>';
        legend += '<span class="pbsg-chart-legend-item"><span class="pbsg-chart-legend-dot" style="background:#8C2004;"></span> Completions</span>';
        legend += '</div>';

        let svg = '<div class="pbsg-chart-area" style="height:220px;">';
        svg += legend;
        svg += '<svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="xMidYMid meet">';
        svg += grid + xLabels;
        svg += '<polygon points="' + viewsFill + '" fill="#517E1B" opacity="0.15"/>';
        svg += '<polyline points="' + viewsPoints + '" fill="none" stroke="#517E1B" stroke-width="2.5"/>';
        svg += '<polygon points="' + compFill + '" fill="#8C2004" opacity="0.1"/>';
        svg += '<polyline points="' + compPoints + '" fill="none" stroke="#8C2004" stroke-width="2" stroke-dasharray="6,3"/>';
        svg += '</svg></div>';

        return svg;
    }

    /**
     * SVG doughnut chart for device breakdown.
     */
    function renderDeviceDoughnut( devices ) {
        if ( ! devices.length ) return '<p style="text-align:center;color:#888;padding:20px;">No data</p>';

        const total  = devices.reduce( ( s, d ) => s + parseInt( d.views, 10 ), 0 );
        const colors = { desktop: '#517E1B', tablet: '#2E6B9E', mobile: '#C4820B' };
        const R = 60, cx = 80, cy = 80, circumference = 2 * Math.PI * R;
        let offset = 0;

        let svg = '<div style="text-align:center;">';
        svg += '<svg viewBox="0 0 160 160" width="160" height="160" style="display:inline-block;">';

        devices.forEach( d => {
            const pct    = total > 0 ? parseInt( d.views, 10 ) / total : 0;
            const dash   = pct * circumference;
            const color  = colors[ d.device_type ] || '#999';
            svg += '<circle cx="' + cx + '" cy="' + cy + '" r="' + R + '" fill="none" stroke="' + color + '" stroke-width="20"';
            svg += ' stroke-dasharray="' + dash + ' ' + ( circumference - dash ) + '"';
            svg += ' stroke-dashoffset="' + ( -offset ) + '"';
            svg += ' transform="rotate(-90 ' + cx + ' ' + cy + ')"/>';
            offset += dash;
        } );

        // Center text
        svg += '<text x="' + cx + '" y="' + ( cy - 6 ) + '" text-anchor="middle" font-size="20" font-weight="700" font-family="Lusitana" fill="#333">' + formatNumber( total ) + '</text>';
        svg += '<text x="' + cx + '" y="' + ( cy + 10 ) + '" text-anchor="middle" font-size="11" fill="#888" font-family="Roboto Condensed">TOTAL VIEWS</text>';
        svg += '</svg>';

        // Legend
        svg += '<div style="margin-top:12px;">';
        devices.forEach( d => {
            const pct = total > 0 ? Math.round( parseInt( d.views, 10 ) / total * 100 ) : 0;
            const color = colors[ d.device_type ] || '#999';
            svg += '<div style="display:inline-flex;align-items:center;gap:6px;margin:4px 10px;font-size:13px;">';
            svg += '<span style="width:10px;height:10px;border-radius:2px;background:' + color + ';display:inline-block;"></span>';
            svg += '<span style="text-transform:capitalize;">' + d.device_type + '</span>';
            svg += '<strong>' + pct + '%</strong>';
            svg += '</div>';
        } );
        svg += '</div></div>';

        return svg;
    }

    /**
     * Tutorial table with sparklines.
     */
    function renderTutorialTable( tutorials, trend ) {
        let html = '<table class="pbsg-data-table">';
        html += '<thead><tr>';
        html += '<th>Tutorial</th><th>Views</th><th>Completions</th>';
        html += '<th>Completion Rate</th><th>Avg Tutorial Score <span class="pbsg-alltime-badge">all-time</span></th><th>Trend</th>';
        html += '</tr></thead><tbody>';

        tutorials.forEach( t => {
            const rate  = parseFloat( t.completion_rate ) || 0;
            const score = parseFloat( t.avg_score ) || 0;
            const b     = t.benchmarks || {};
            const adminUrl = config.ajaxUrl.replace( 'admin-ajax.php', 'admin.php' );

            html += '<tr>';
            html += '<td><a class="pbsg-tutorial-link" href="' + adminUrl + '?page=pbsg-analytics&tab=tutorial&tutorial_id=' + t.tutorial_page_id + '">' + escapeHtml( t.tutorial_name || 'Tutorial #' + t.tutorial_page_id ) + '</a></td>';
            html += '<td>' + formatNumber( t.view_count ) + '</td>';
            html += '<td>' + formatNumber( t.completion_count ) + '</td>';
            html += '<td><span class="pbsg-badge ' + getBadgeColor( rate, b.completion_rate_green, b.completion_rate_amber ) + '">' + rate + '%</span></td>';
            html += '<td><span class="pbsg-badge ' + getBadgeColor( score, b.score_green, b.score_amber ) + '">' + score + '%</span></td>';
            html += '<td>' + renderSparkline( trend, 8 ) + '</td>';
            html += '</tr>';
        } );

        html += '</tbody></table>';
        return html;
    }

    /**
     * "Needs Attention" flagged items.
     */
    function renderNeedsAttention( tutorials ) {
        const flagged = tutorials.filter( t => {
            const rate  = parseFloat( t.completion_rate ) || 0;
            const score = parseFloat( t.avg_score ) || 0;
            const b     = t.benchmarks || {};
            const attComp  = ( b.attention_completion !== undefined ) ? b.attention_completion : 60;
            const attScore = ( b.attention_score !== undefined ) ? b.attention_score : 50;
            return rate < attComp || score < attScore;
        } );

        if ( ! flagged.length ) {
            return '<p style="color:#888;font-size:14px;padding:10px 0;">All tutorials are performing within acceptable thresholds. 👍</p>';
        }

        let html = '';
        flagged.forEach( t => {
            const rate  = parseFloat( t.completion_rate ) || 0;
            const score = parseFloat( t.avg_score ) || 0;
            const b     = t.benchmarks || {};
            const attComp  = ( b.attention_completion !== undefined ) ? b.attention_completion : 60;
            const attScore = ( b.attention_score !== undefined ) ? b.attention_score : 50;
            const reasons = [];
            if ( rate < attComp ) reasons.push( 'Completion rate below ' + attComp + '%' );
            if ( score < attScore ) reasons.push( 'Avg tutorial score below ' + attScore + '%' );

            const adminUrl = config.ajaxUrl.replace( 'admin-ajax.php', 'admin.php' );

            html += '<div class="pbsg-attention-item">';
            html += '<div>';
            html += '<a class="pbsg-tutorial-link" href="' + adminUrl + '?page=pbsg-analytics&tab=tutorial&tutorial_id=' + t.tutorial_page_id + '">';
            html += escapeHtml( t.tutorial_name || 'Tutorial' ) + '</a>';
            html += '<div class="pbsg-attention-reason">' + reasons.join( ' · ' ) + '</div>';
            html += '</div>';
            html += '<span class="pbsg-badge red">⚠</span>';
            html += '</div>';
        } );

        return html;
    }

    /**
     * CSS sparkline (mini bar chart).
     */
    function renderSparkline( data, barCount ) {
        const recent = data.slice( -barCount );
        if ( ! recent.length ) return '<span style="color:#ccc;">—</span>';

        const max = Math.max( 1, ...recent.map( d => d.views || 0 ) );
        let html = '<span class="pbsg-sparkline">';
        recent.forEach( d => {
            const h = Math.max( 2, Math.round( ( ( d.views || 0 ) / max ) * 22 ) );
            html += '<span class="bar" style="height:' + h + 'px;"></span>';
        } );
        html += '</span>';
        return html;
    }

    /**
     * CSS vertical bar chart for daily views.
     */
    function renderDailyBars( data ) {
        if ( ! data.length ) return '<p style="text-align:center;color:#888;padding:40px;">No daily data yet</p>';

        const maxViews = Math.max( 1, ...data.map( d => d.views || 0 ) );
        const barH     = 150;

        let html = '<div class="pbsg-bar-chart" style="height:' + ( barH + 40 ) + 'px;">';
        data.forEach( d => {
            const h = Math.max( 2, Math.round( ( ( d.views || 0 ) / maxViews ) * barH ) );
            const dateStr = ( d.stat_date || '' ).substring( 5 ); // MM-DD
            html += '<div class="pbsg-bar-col">';
            html += '<div class="pbsg-bar" style="height:' + h + 'px;">';
            html += '<span class="pbsg-bar-val">' + ( d.views || 0 ) + '</span>';
            html += '</div>';
            html += '<span class="pbsg-bar-label">' + dateStr + '</span>';
            html += '</div>';
        } );
        html += '</div>';

        return html;
    }

    /**
     * Completion funnel — horizontal bars.
     */
    function renderCompletionFunnel( stepDwell, totalViews, stepNames ) {
        const keys = Object.keys( stepDwell ).sort( ( a, b ) => {
            const ia = parseInt( a.replace( 'step_', '' ), 10 );
            const ib = parseInt( b.replace( 'step_', '' ), 10 );
            return ia - ib;
        } );

        if ( ! keys.length ) return '<p style="color:#888;padding:20px;">No step data yet</p>';

        // Funnel baseline: Step 1 views = 100%. Each subsequent step is
        // the fraction of users who reached it relative to Step 1.
        const baselineViews = stepDwell[ keys[0] ].views || 1;
        const names = stepNames || {};

        let html = '';
        keys.forEach( ( key, i ) => {
            const views   = stepDwell[ key ].views || 0;
            const pct     = Math.round( ( views / baselineViews ) * 100 );
            const stepNum = parseInt( key.replace( 'step_', '' ), 10 ) + 1;
            const name    = names[ key ] || '';
            const label   = name ? stepNum + '. ' + name : 'Step ' + stepNum;

            // Color gradient: green → amber → red based on dropout
            const ratio = keys.length > 1 ? i / ( keys.length - 1 ) : 0;
            const color = ratio < 0.5 ? '#517E1B' : ( ratio < 0.8 ? '#C4820B' : '#8C2004' );

            html += '<div class="pbsg-funnel-step">';
            html += '<span class="pbsg-funnel-label">' + label + '</span>';
            html += '<div class="pbsg-funnel-track"><div class="pbsg-funnel-bar" style="width:' + Math.max( 10, pct ) + '%;background:' + color + ';">' + pct + '%</div></div>';
            html += '<span class="pbsg-funnel-count">' + views + ' views</span>';
            html += '</div>';
        } );

        return html;
    }

    /**
     * Step dwell time table.
     */
    function renderDwellTable( stepDwell, stepNames ) {
        const keys = Object.keys( stepDwell ).sort( ( a, b ) => {
            return parseInt( a.replace( 'step_', '' ), 10 ) - parseInt( b.replace( 'step_', '' ), 10 );
        } );

        if ( ! keys.length ) return '<p style="color:#888;">No dwell data yet</p>';

        const names = stepNames || {};

        let html = '<table class="pbsg-data-table">';
        html += '<thead><tr><th>Step</th><th>Views</th><th>Avg Dwell Time</th></tr></thead>';
        html += '<tbody>';

        keys.forEach( key => {
            const d = stepDwell[ key ];
            const stepNum = parseInt( key.replace( 'step_', '' ), 10 ) + 1;
            const name    = names[ key ] || '';
            const label   = name ? stepNum + '. ' + name : 'Step ' + stepNum;
            const avgSecs = d.avg_dwell_secs || 0;

            // Color code: <30s low, 30-120s med, >120s high
            const dwellClass = avgSecs < 30 ? 'pbsg-dwell-low' : ( avgSecs < 120 ? 'pbsg-dwell-med' : 'pbsg-dwell-high' );

            html += '<tr>';
            html += '<td>' + label + '</td>';
            html += '<td>' + ( d.views || 0 ) + '</td>';
            html += '<td><span class="' + dwellClass + '">' + formatTime( avgSecs ) + '</span></td>';
            html += '</tr>';
        } );

        html += '</tbody></table>';
        return html;
    }

    /**
     * Questions table for tutorial detail view.
     */
    function renderQuestionsTable( questions, tutorialId, bench ) {
        bench = bench || {};
        let html = '<table class="pbsg-data-table">';
        html += '<thead><tr>';
        html += '<th>#</th><th>Question</th><th>Attempts</th>';
        html += '<th>Correct Rate</th><th>Give-ups</th><th>Retries</th><th>Avg Attempts</th>';
        html += '</tr></thead><tbody>';

        const adminUrl = config.ajaxUrl.replace( 'admin-ajax.php', 'admin.php' );

        questions.forEach( ( q, i ) => {
            const rate = parseFloat( q.correct_rate ) || 0;
            const link = adminUrl + '?page=pbsg-analytics&tab=question&tutorial_id=' + tutorialId +
                         '&h5p_id=' + q.h5p_content_id + '&q_index=' + q.question_index;

            html += '<tr>';
            html += '<td>' + ( i + 1 ) + '</td>';
            html += '<td><a class="pbsg-tutorial-link" href="' + link + '">' + escapeHtml( q.question_text || 'Q' + ( i + 1 ) ) + '</a></td>';
            html += '<td>' + formatNumber( q.total_attempts ) + '</td>';
            html += '<td><span class="pbsg-badge ' + getBadgeColor( rate, bench.correct_rate_green, bench.correct_rate_amber ) + '">' + rate + '%</span></td>';
            html += '<td>' + ( q.giveup_count || 0 ) + '</td>';
            html += '<td>' + ( q.total_retries || 0 ) + '</td>';
            html += '<td>' + ( q.avg_attempts || '—' ) + '</td>';
            html += '</tr>';
        } );

        html += '</tbody></table>';
        return html;
    }

    /**
     * Attempt distribution vertical bar chart.
     */
    function renderAttemptDistribution( dist ) {
        const items = [
            { label: '1st Try', val: dist.first_attempt_correct || 0, color: '#517E1B' },
            { label: '2nd Try', val: dist.second_attempt_correct || 0, color: '#6fa22d' },
            { label: '3rd+', val: dist.third_plus_correct || 0, color: '#C4820B' },
            { label: 'Gave Up', val: dist.giveups || 0, color: '#8C2004' },
        ];

        const max = Math.max( 1, ...items.map( i => i.val ) );
        const barH = 140;

        let html = '<div class="pbsg-bar-chart" style="height:' + ( barH + 40 ) + 'px;">';
        items.forEach( item => {
            const h = Math.max( 2, Math.round( ( item.val / max ) * barH ) );
            html += '<div class="pbsg-bar-col">';
            html += '<div class="pbsg-bar" style="height:' + h + 'px;background:' + item.color + ';">';
            html += '<span class="pbsg-bar-val">' + item.val + '</span>';
            html += '</div>';
            html += '<span class="pbsg-bar-label">' + item.label + '</span>';
            html += '</div>';
        } );
        html += '</div>';

        return html;
    }

    /**
     * SVG doughnut for question outcome breakdown.
     */
    function renderOutcomeDoughnut( q ) {
        const correct   = parseInt( q.correct_count, 10 ) || 0;
        const incorrect = parseInt( q.incorrect_count, 10 ) || 0;
        const giveups   = parseInt( q.giveup_count, 10 ) || 0;
        const total     = correct + incorrect + giveups;

        if ( ! total ) return '<p style="text-align:center;color:#888;padding:20px;">No data</p>';

        const segments = [
            { val: correct,   color: '#517E1B', label: 'Correct' },
            { val: incorrect, color: '#8C2004', label: 'Incorrect' },
            { val: giveups,   color: '#888',    label: 'Gave Up' },
        ];

        const R = 55, cx = 70, cy = 70;
        const circ = 2 * Math.PI * R;
        let offset = 0;

        let svg = '<div style="text-align:center;">';
        svg += '<svg viewBox="0 0 140 140" width="140" height="140" style="display:inline-block;">';

        segments.forEach( seg => {
            if ( seg.val === 0 ) return;
            const dash = ( seg.val / total ) * circ;
            svg += '<circle cx="' + cx + '" cy="' + cy + '" r="' + R + '" fill="none" stroke="' + seg.color + '" stroke-width="18"';
            svg += ' stroke-dasharray="' + dash + ' ' + ( circ - dash ) + '"';
            svg += ' stroke-dashoffset="' + ( -offset ) + '"';
            svg += ' transform="rotate(-90 ' + cx + ' ' + cy + ')"/>';
            offset += dash;
        } );

        svg += '<text x="' + cx + '" y="' + ( cy - 4 ) + '" text-anchor="middle" font-size="18" font-weight="700" font-family="Lusitana" fill="#333">' + total + '</text>';
        svg += '<text x="' + cx + '" y="' + ( cy + 10 ) + '" text-anchor="middle" font-size="10" fill="#888" font-family="Roboto Condensed">TOTAL</text>';
        svg += '</svg>';

        // Legend
        svg += '<div style="margin-top:10px;">';
        segments.forEach( seg => {
            const pct = Math.round( seg.val / total * 100 );
            svg += '<div style="display:inline-flex;align-items:center;gap:5px;margin:3px 8px;font-size:12px;">';
            svg += '<span style="width:10px;height:10px;border-radius:2px;background:' + seg.color + ';display:inline-block;"></span>';
            svg += seg.label + ' <strong>' + seg.val + '</strong> (' + pct + '%)';
            svg += '</div>';
        } );
        svg += '</div></div>';

        return svg;
    }

    // =========================================================================
    // VIEW D — COMPARE TUTORIALS
    // =========================================================================

    function loadComparisonData() {
        showLoading();

        const params = {
            action:    'pbsg_get_analytics',
            view:      'compare',
            ids:       compareIds.join( ',' ),
            date_from: $( '#pbsg-date-from' ).val(),
            date_to:   $( '#pbsg-date-to' ).val(),
            device:    $( '#pbsg-device-filter' ).val() || '',
        };

        $.get( config.ajaxUrl, params )
            .done( function( response ) {
                if ( response.success && response.data ) {
                    hideLoading();
                    renderComparison( response.data );
                    updateCompareUrl();
                } else {
                    showEmpty();
                }
            } )
            .fail( function() {
                showEmpty();
            } );
    }

    function addTutorial( colIndex, tutorialId ) {
        // Fill the slot or push
        while ( compareIds.length <= colIndex ) {
            compareIds.push( 0 );
        }
        compareIds[ colIndex ] = tutorialId;
        // Remove empty trailing slots
        while ( compareIds.length && !compareIds[ compareIds.length - 1 ] ) {
            compareIds.pop();
        }
        loadComparisonData();
    }

    function removeTutorial( colIndex ) {
        compareIds.splice( colIndex, 1 );
        renderComparison( lastComparisonData || { tutorials: {}, date_scope: {} } );
        if ( compareIds.length ) {
            loadComparisonData();
        } else {
            updateCompareUrl();
        }
    }

    function updateCompareUrl() {
        const url = new URL( window.location );
        if ( compareIds.length ) {
            url.searchParams.set( 'ids', compareIds.join( ',' ) );
        } else {
            url.searchParams.delete( 'ids' );
        }
        history.replaceState( null, '', url );

        // Update export button
        let exportUrl = config.exportUrl + '&type=compare';
        if ( compareIds.length ) exportUrl += '&ids=' + compareIds.join( ',' );
        const dateFrom = $( '#pbsg-date-from' ).val();
        const dateTo   = $( '#pbsg-date-to' ).val();
        if ( dateFrom ) exportUrl += '&date_from=' + encodeURIComponent( dateFrom );
        if ( dateTo )   exportUrl += '&date_to=' + encodeURIComponent( dateTo );
        $( '#pbsg-export-btn' ).attr( 'href', exportUrl );
    }

    let lastComparisonData = null;

    function renderComparison( data ) {
        lastComparisonData = data;
        const tutorials = data.tutorials || {};
        const cols = 3; // Always show 3 slots

        // Hide KPI stats row for compare view
        $( '#pbsg-stats-row' ).hide();

        // Set CSS variable for grid columns
        let html = '<div class="pbsg-compare-table" style="--compare-cols: ' + cols + ';">';

        // === HEADER ===
        html += '<div class="pbsg-compare-head">';
        html += '<div class="col-label">Metric</div>';

        for ( let i = 0; i < cols; i++ ) {
            const tid = compareIds[ i ] || 0;
            const tData = tid ? tutorials[ tid ] : null;

            if ( tData ) {
                html += '<div class="col-tutorial col-filled" data-col="' + i + '">';
                html += '<button type="button" class="pbsg-col-remove" data-col="' + i + '" title="Remove">✕</button>';
                html += '<div class="col-name">' + escapeHtml( tData.name ) + '</div>';
                html += '<div class="col-meta">' + escapeHtml( tData.meta ) + '</div>';
                html += '<button type="button" class="pbsg-col-change-btn" data-col="' + i + '">Change ▾</button>';
                html += '<select class="col-swap-select pbsg-compare-select" data-col="' + i + '">';
                html += buildTutorialOptions( i, tid );
                html += '</select>';
                html += '</div>';
            } else {
                html += '<div class="col-tutorial col-empty" data-col="' + i + '">';
                html += '<div class="col-empty-prompt">';
                html += '<div class="empty-plus">＋</div>';
                html += '<div class="empty-label">Add Tutorial</div>';
                html += '</div>';
                html += '<select class="pbsg-compare-select col-select" data-col="' + i + '">';
                html += buildTutorialOptions( i, 0 );
                html += '</select>';
                html += '</div>';
            }
        }
        html += '</div>'; // end header

        // Only render data sections if we have at least 1 tutorial selected
        const tids = compareIds.filter( Boolean );
        if ( tids.length > 0 ) {

            // === KPI SECTION ===
            html += renderCompareSection( 'Key Performance Indicators', [
                { label: 'Views',           key: 'views',           unit: '',  higher: true },
                { label: 'Completions',     key: 'completions',     unit: '',  higher: true },
                { label: 'Completion Rate', key: 'completion_rate', unit: '%', higher: true },
                { label: 'Avg Time',        key: 'avg_time_seconds',unit: 's', higher: false, format: 'time' },
            ], tutorials, cols );

            // === COMPLETION FUNNEL SECTION ===
            html += '<div class="pbsg-compare-section">';
            html += '<div class="pbsg-compare-section-title">Completion Funnel</div>';

            // Find max steps across all tutorials
            let maxSteps = 0;
            tids.forEach( function( tid ) {
                const t = tutorials[ tid ];
                if ( t && t.funnel ) maxSteps = Math.max( maxSteps, t.funnel.length );
            } );

            // Pre-compute each tutorial's Step 1 views as its funnel baseline
            const funnelBaselines = {};
            tids.forEach( function( tid ) {
                const t = tutorials[ tid ];
                if ( t && t.funnel && t.funnel[0] ) {
                    funnelBaselines[ tid ] = t.funnel[0].views || 1;
                }
            } );

            if ( maxSteps > 0 ) {
                for ( let s = 0; s < maxSteps; s++ ) {
                    html += '<div class="pbsg-compare-row">';
                    html += '<div class="row-label">Step ' + ( s + 1 ) + '</div>';

                    for ( let i = 0; i < cols; i++ ) {
                        const tid = compareIds[ i ] || 0;
                        const t   = tid ? tutorials[ tid ] : null;
                        if ( t && t.funnel && t.funnel[ s ] ) {
                            const views    = t.funnel[ s ].views;
                            const baseline = funnelBaselines[ tid ] || 1;
                            const pct      = Math.round( views / baseline * 100 );
                            html += '<div class="row-value">';
                            html += '<div class="funnel-step">';
                            html += '<div class="funnel-step-bar" style="width:' + Math.max( 5, pct ) + '%;"></div>';
                            html += '</div>';
                            html += '<span class="metric-unit">' + views + ' views (' + pct + '%)</span>';
                            html += '</div>';
                        } else {
                            html += '<div class="row-value"><span class="metric-unit">—</span></div>';
                        }
                    }
                    html += '</div>';
                }
            } else {
                html += '<div class="pbsg-compare-row"><div class="row-label" style="grid-column:1/-1;color:#888;">No funnel data available</div></div>';
            }
            html += '</div>'; // end funnel section

            // === QUESTION PERFORMANCE SECTION ===
            html += renderCompareSection( 'Question Performance <span class="pbsg-alltime-badge">all-time</span>', [
                { label: 'Avg Score',           key: 'avg_score',          unit: '%', higher: true },
                { label: 'First Attempt Rate',  key: 'first_attempt_rate', unit: '%', higher: true },
                { label: 'Avg Attempts/Q',      key: 'avg_attempts',       unit: '',  higher: false },
                { label: 'Give-up Rate',        key: 'giveup_rate',        unit: '%', higher: false },
            ], tutorials, cols );

            // Hardest question row
            html += '<div class="pbsg-compare-section" style="border-top:none;margin-top:-12px;">';
            html += '<div class="pbsg-compare-row">';
            html += '<div class="row-label">Hardest Question</div>';
            for ( let i = 0; i < cols; i++ ) {
                const tid = compareIds[ i ] || 0;
                const t   = tid ? tutorials[ tid ] : null;
                if ( t && t.hardest_question ) {
                    const hq = t.hardest_question;
                    html += '<div class="row-value">';
                    html += '<div class="metric-big concern">' + hq.correct_rate + '%</div>';
                    html += '<div class="metric-unit">' + escapeHtml( hq.question_text || 'Q' + ( hq.question_index + 1 ) ) + '</div>';
                    html += '</div>';
                } else {
                    html += '<div class="row-value"><span class="metric-unit">—</span></div>';
                }
            }
            html += '</div></div>';

            // === DEVICE BREAKDOWN SECTION ===
            html += '<div class="pbsg-compare-section">';
            html += '<div class="pbsg-compare-section-title">Device Breakdown</div>';

            [ 'desktop', 'tablet', 'mobile' ].forEach( function( deviceType ) {
                html += '<div class="pbsg-compare-row">';
                html += '<div class="row-label" style="text-transform:capitalize;">' + deviceType + '</div>';

                // Find winner for this device
                let bestVal = -1, bestIdx = -1;
                for ( let i = 0; i < cols; i++ ) {
                    const tid = compareIds[ i ] || 0;
                    const t   = tid ? tutorials[ tid ] : null;
                    if ( t && t.devices && t.devices[ deviceType ] > bestVal ) {
                        bestVal = t.devices[ deviceType ];
                        bestIdx = i;
                    }
                }

                for ( let i = 0; i < cols; i++ ) {
                    const tid = compareIds[ i ] || 0;
                    const t   = tid ? tutorials[ tid ] : null;
                    if ( t && t.devices ) {
                        const pct = t.devices[ deviceType ] || 0;
                        const winClass = ( tids.length > 1 && i === bestIdx && bestVal > 0 ) ? ' winner' : '';
                        html += '<div class="row-value' + winClass + '">';
                        html += '<div class="mini-bar"><div class="mini-bar-fill" style="width:' + pct + '%;"></div></div>';
                        html += '<span class="metric-unit">' + pct + '%</span>';
                        html += '</div>';
                    } else {
                        html += '<div class="row-value"><span class="metric-unit">—</span></div>';
                    }
                }
                html += '</div>';
            } );
            html += '</div>'; // end device section

        } // end if tids.length > 0

        // === FOOTER ===
        if ( tids.length > 0 ) {
            html += '<div class="pbsg-compare-actions">';
            let exportUrl = config.exportUrl + '&type=compare&ids=' + compareIds.join( ',' );
            const dateFrom = $( '#pbsg-date-from' ).val();
            const dateTo   = $( '#pbsg-date-to' ).val();
            if ( dateFrom ) exportUrl += '&date_from=' + encodeURIComponent( dateFrom );
            if ( dateTo )   exportUrl += '&date_to=' + encodeURIComponent( dateTo );
            html += '<a href="' + exportUrl + '" class="button pbsg-btn pbsg-btn-primary pbsg-btn-sm">↓ Export Comparison CSV</a>';
            html += '</div>';
        }

        html += '</div>'; // end compare-table

        $( '#pbsg-main-content' ).html( html ).show();
    }

    function renderCompareSection( title, metrics, tutorials, cols ) {
        const tids = compareIds.filter( Boolean );
        let html = '<div class="pbsg-compare-section">';
        html += '<div class="pbsg-compare-section-title">' + title + '</div>';

        metrics.forEach( function( m ) {
            html += '<div class="pbsg-compare-row">';
            html += '<div class="row-label">' + m.label + '</div>';

            // Find winner
            let bestVal = null, bestIdx = -1;
            for ( let i = 0; i < cols; i++ ) {
                const tid = compareIds[ i ] || 0;
                const t   = tid ? tutorials[ tid ] : null;
                if ( t ) {
                    const val = parseFloat( t[ m.key ] ) || 0;
                    if ( bestVal === null ||
                         ( m.higher && val > bestVal ) ||
                         ( !m.higher && val < bestVal ) ) {
                        bestVal = val;
                        bestIdx = i;
                    }
                }
            }

            for ( let i = 0; i < cols; i++ ) {
                const tid = compareIds[ i ] || 0;
                const t   = tid ? tutorials[ tid ] : null;
                if ( t ) {
                    const val      = parseFloat( t[ m.key ] ) || 0;
                    const winClass = ( tids.length > 1 && i === bestIdx ) ? ' winner' : '';
                    const concern  = ( !m.higher && val > 50 ) || ( m.higher && val < 30 ) ? ' concern' : '';
                    const display  = m.format === 'time' ? formatTime( val ) : val + m.unit;

                    html += '<div class="row-value' + winClass + '">';
                    html += '<div class="metric-big' + concern + '">' + display + '</div>';
                    if ( m.unit && m.format !== 'time' ) {
                        html += '<div class="metric-unit">' + m.label + '</div>';
                    }
                    // Add mini progress bar for percentage metrics
                    if ( m.unit === '%' ) {
                        html += '<div class="mini-bar"><div class="mini-bar-fill" style="width:' + Math.min( val, 100 ) + '%;"></div></div>';
                    }
                    html += '</div>';
                } else {
                    html += '<div class="row-value"><span class="metric-unit">—</span></div>';
                }
            }
            html += '</div>';
        } );

        html += '</div>';
        return html;
    }

    function buildTutorialSelect( colIndex, selectedId ) {
        const list = config.tutorials || [];
        let html = '<select class="pbsg-compare-select col-select" data-col="' + colIndex + '">';
        html += '<option value="">Select tutorial…</option>';
        list.forEach( function( t ) {
            // Don't show tutorials already selected in other slots
            const alreadyUsed = compareIds.indexOf( t.id ) !== -1 && t.id !== selectedId;
            if ( !alreadyUsed ) {
                const sel = t.id === selectedId ? ' selected' : '';
                if (t.title.length == 0) {
                    html += '<option value="' + t.id + '"' + sel + '>' + '(no title)' + '</option>';
                } else {
                    html += '<option value="' + t.id + '"' + sel + '>' + escapeHtml( t.title ) + '</option>';
                }
            }
        } );
        html += '</select>';
        return html;
    }

    function buildTutorialOptions( colIndex, selectedId ) {
        const list = config.tutorials || [];
        let html = '<option value="">Select tutorial…</option>';
        list.forEach( function( t ) {
            const alreadyUsed = compareIds.indexOf( t.id ) !== -1 && t.id !== selectedId;
            if ( !alreadyUsed ) {
                const sel = t.id === selectedId ? ' selected' : '';
                if (t.title.length == 0) {
                    html += '<option value="' + t.id + '"' + sel + '>' + '(no title)' + '</option>';
                } else {
                    html += '<option value="' + t.id + '"' + sel + '>' + escapeHtml( t.title ) + '</option>';
                }            
            }
        } );
        return html;
    }

    // =========================================================================
    // UI HELPERS
    // =========================================================================

    function renderKPIs( items ) {
        let html = '';
        items.forEach( item => {
            html += '<div class="pbsg-stat-box ' + item.color + '">';
            html += '<div class="pbsg-stat-label">' + item.label;
            if ( item.badge ) {
                html += ' <span class="pbsg-alltime-badge">' + escapeHtml( item.badge ) + '</span>';
            }
            html += '</div>';
            html += '<div class="pbsg-stat-value">' + item.value + '</div>';
            html += '</div>';
        } );
        $( '#pbsg-stats-row' ).html( html ).show();
    }

    function showLoading() {
        $( '#pbsg-loading' ).show();
        $( '#pbsg-empty-state' ).hide();
        // Dim existing KPI cards and content instead of hiding — prevents layout jump
        // and signals staleness while new data loads
        $( '#pbsg-stats-row' ).addClass( 'pbsg-stale' );
        $( '#pbsg-main-content' ).addClass( 'pbsg-stale' );
    }

    function hideLoading() {
        $( '#pbsg-loading' ).hide();
        $( '#pbsg-stats-row' ).removeClass( 'pbsg-stale' );
        $( '#pbsg-main-content' ).removeClass( 'pbsg-stale' );
    }

    function showEmpty() {
        $( '#pbsg-loading' ).hide();
        $( '#pbsg-empty-state' ).show();
        $( '#pbsg-stats-row' ).removeClass( 'pbsg-stale' ).hide();
        $( '#pbsg-main-content' ).removeClass( 'pbsg-stale' ).hide();
    }

    // =========================================================================
    // FORMATTERS
    // =========================================================================

    function formatNumber( n ) {
        n = parseInt( n, 10 ) || 0;
        return n.toLocaleString();
    }

    function formatTime( seconds ) {
        seconds = parseInt( seconds, 10 ) || 0;
        if ( seconds < 60 ) return seconds + 's';
        const m = Math.floor( seconds / 60 );
        const s = seconds % 60;
        return m + 'm ' + ( s < 10 ? '0' : '' ) + s + 's';
    }

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

    function getExportUrl( type, tutorialId, h5pId, qIndex ) {
        let url = config.exportUrl + '&type=' + type;
        const dateFrom = $( '#pbsg-date-from' ).val();
        const dateTo   = $( '#pbsg-date-to' ).val();
        if ( dateFrom ) url += '&date_from=' + encodeURIComponent( dateFrom );
        if ( dateTo )   url += '&date_to='   + encodeURIComponent( dateTo );
        if ( tutorialId ) url += '&tutorial_id=' + tutorialId;
        if ( h5pId )      url += '&h5p_id=' + h5pId;
        if ( qIndex !== undefined && qIndex !== null ) url += '&q_index=' + qIndex;
        return url;
    }

    function escapeHtml( str ) {
        const div = document.createElement( 'div' );
        div.textContent = str || '';
        return div.innerHTML;
    }

    // =========================================================================
    // DOM READY
    // =========================================================================

    $( document ).ready( init );

} )( jQuery );
