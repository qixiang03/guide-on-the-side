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

    // =========================================================================
    // INIT — Load data for the current view
    // =========================================================================

    function init() {
        const view       = $wrap.data( 'view' ) || 'overview';
        const tutorialId = $wrap.data( 'tutorial-id' ) || 0;
        const h5pId      = $wrap.data( 'h5p-id' ) || 0;
        const qIndex     = $wrap.data( 'q-index' ) || 0;

        loadView( view, tutorialId, h5pId, qIndex );

        // Bind filter button
        $( '#pbsg-apply-filters' ).on( 'click', function() {
            loadView( view, tutorialId, h5pId, qIndex );
        } );

        // Bind refresh
        $( '#pbsg-refresh-btn' ).on( 'click', function() {
            loadView( view, tutorialId, h5pId, qIndex );
        } );
    }

    function loadView( view, tutorialId, h5pId, qIndex ) {
        showLoading();

        const params = {
            action:    'pbsg_get_analytics',
            view:      view,
            date_from: $( '#pbsg-date-from' ).val(),
            date_to:   $( '#pbsg-date-to' ).val(),
        };

        if ( view === 'overview' ) {
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

        if ( ! tutorials.length ) {
            showEmpty();
            return;
        }

        // KPIs
        renderKPIs( [
            { label: 'Total Views', value: formatNumber( totals.total_views ), color: 'green' },
            { label: 'Total Completions', value: formatNumber( totals.total_completions ), color: 'blue' },
            { label: 'Avg Completion Rate', value: totals.avg_completion + '%', color: 'amber' },
            { label: 'Avg Quiz Score', value: totals.avg_score + '%', color: 'red' },
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
        html += '<a class="pbsg-card-action" href="' + config.exportUrl + '&type=overview">↓ Export CSV</a>';
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
        const questions  = data.questions || [];

        // KPIs for this tutorial
        renderKPIs( [
            { label: 'Total Views', value: formatNumber( stats.view_count ), color: 'green' },
            { label: 'Completions', value: formatNumber( stats.completion_count ), color: 'blue' },
            { label: 'Completion Rate', value: stats.completion_rate + '%', color: 'amber' },
            { label: 'Avg Time', value: formatTime( stats.avg_time_seconds ), color: 'red' },
        ] );

        // Update export button
        $( '#pbsg-export-btn' ).attr( 'href', config.exportUrl + '&type=questions&tutorial_id=' + stats.tutorial_page_id );

        let html = '<div class="pbsg-grid-2">';

        // Daily views bar chart
        html += '<div class="pbsg-card">';
        html += '<div class="pbsg-card-header">Daily Views (Last 14 Days)</div>';
        html += renderDailyBars( dailyViews );
        html += '</div>';

        // Completion funnel
        html += '<div class="pbsg-card">';
        html += '<div class="pbsg-card-header">Completion Funnel</div>';
        html += renderCompletionFunnel( stepDwell, stats.view_count );
        html += '</div>';

        html += '</div>'; // end grid

        // Dwell time table
        html += '<div class="pbsg-card">';
        html += '<div class="pbsg-card-header">Step Dwell Time</div>';
        html += renderDwellTable( stepDwell );
        html += '</div>';

        // Questions table
        if ( questions.length ) {
            html += '<div class="pbsg-card">';
            html += '<div class="pbsg-card-header">';
            html += 'Quiz Questions';
            html += '<a class="pbsg-card-action" href="' + config.exportUrl + '&type=questions&tutorial_id=' + stats.tutorial_page_id + '">↓ Export CSV</a>';
            html += '</div>';
            html += renderQuestionsTable( questions, stats.tutorial_page_id );
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
        const dist = q.attempt_distribution || {};

        renderKPIs( [
            { label: 'Total Attempts', value: formatNumber( q.total_attempts ), color: 'green' },
            { label: 'Correct Rate', value: q.correct_rate + '%', color: getBadgeColor( q.correct_rate ) },
            { label: 'Give-ups', value: formatNumber( q.giveup_count ), color: 'red' },
            { label: 'Avg Time', value: formatTime( q.avg_time_seconds ), color: 'blue' },
        ] );

        let html = '<div class="pbsg-grid-2">';

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
            const x = padL + i * scaleX;
            const yv = padT + chartH - ( d.views || 0 ) * scaleY;
            const yc = padT + chartH - ( d.completions || 0 ) * scaleY;
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
            grid += '<text x="' + ( padL - 8 ) + '" y="' + ( y + 4 ) + '" text-anchor="end" font-size="10" fill="#888" font-family="Roboto Condensed">' + val + '</text>';
        }

        // X-axis labels (show every Nth for readability)
        let xLabels = '';
        const step = Math.max( 1, Math.ceil( data.length / 8 ) );
        data.forEach( ( d, i ) => {
            if ( i % step === 0 || i === data.length - 1 ) {
                const x  = padL + i * scaleX;
                const dt = d.stat_date || '';
                const label = dt.substring( 5 ); // MM-DD
                xLabels += '<text x="' + x + '" y="' + ( H - 5 ) + '" text-anchor="middle" font-size="9" fill="#888" font-family="Roboto Condensed">' + label + '</text>';
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
        svg += '<text x="' + cx + '" y="' + ( cy - 6 ) + '" text-anchor="middle" font-size="18" font-weight="700" font-family="Lusitana" fill="#333">' + formatNumber( total ) + '</text>';
        svg += '<text x="' + cx + '" y="' + ( cy + 10 ) + '" text-anchor="middle" font-size="10" fill="#888" font-family="Roboto Condensed">TOTAL VIEWS</text>';
        svg += '</svg>';

        // Legend
        svg += '<div style="margin-top:12px;">';
        devices.forEach( d => {
            const pct = total > 0 ? Math.round( parseInt( d.views, 10 ) / total * 100 ) : 0;
            const color = colors[ d.device_type ] || '#999';
            svg += '<div style="display:inline-flex;align-items:center;gap:6px;margin:4px 10px;font-size:12px;">';
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
        html += '<th>Completion Rate</th><th>Avg Score</th><th>Trend</th>';
        html += '</tr></thead><tbody>';

        tutorials.forEach( t => {
            const rate  = parseFloat( t.completion_rate ) || 0;
            const score = parseFloat( t.avg_score ) || 0;
            const adminUrl = config.ajaxUrl.replace( 'admin-ajax.php', 'admin.php' );

            html += '<tr>';
            html += '<td><a class="pbsg-tutorial-link" href="' + adminUrl + '?page=pbsg-analytics&tab=tutorial&tutorial_id=' + t.tutorial_page_id + '">' + escapeHtml( t.tutorial_name || 'Tutorial #' + t.tutorial_page_id ) + '</a></td>';
            html += '<td>' + formatNumber( t.view_count ) + '</td>';
            html += '<td>' + formatNumber( t.completion_count ) + '</td>';
            html += '<td><span class="pbsg-badge ' + getBadgeColor( rate ) + '">' + rate + '%</span></td>';
            html += '<td><span class="pbsg-badge ' + getBadgeColor( score ) + '">' + score + '%</span></td>';
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
            return rate < 60 || score < 50;
        } );

        if ( ! flagged.length ) {
            return '<p style="color:#888;font-size:13px;padding:10px 0;">All tutorials are performing within acceptable thresholds. 👍</p>';
        }

        let html = '';
        flagged.forEach( t => {
            const rate  = parseFloat( t.completion_rate ) || 0;
            const score = parseFloat( t.avg_score ) || 0;
            const reasons = [];
            if ( rate < 60 ) reasons.push( 'Completion rate below 60%' );
            if ( score < 50 ) reasons.push( 'Avg quiz score below 50%' );

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
    function renderCompletionFunnel( stepDwell, totalViews ) {
        const keys = Object.keys( stepDwell ).sort( ( a, b ) => {
            const ia = parseInt( a.replace( 'step_', '' ), 10 );
            const ib = parseInt( b.replace( 'step_', '' ), 10 );
            return ia - ib;
        } );

        if ( ! keys.length ) return '<p style="color:#888;padding:20px;">No step data yet</p>';

        const maxViews = Math.max( totalViews || 1, ...keys.map( k => stepDwell[k].views || 0 ) );

        let html = '';
        keys.forEach( ( key, i ) => {
            const views = stepDwell[ key ].views || 0;
            const pct   = Math.round( ( views / maxViews ) * 100 );
            const label = 'Step ' + ( parseInt( key.replace( 'step_', '' ), 10 ) + 1 );

            // Color gradient: green → amber → red
            const ratio = keys.length > 1 ? i / ( keys.length - 1 ) : 0;
            const color = ratio < 0.5 ? '#517E1B' : ( ratio < 0.8 ? '#C4820B' : '#8C2004' );

            html += '<div class="pbsg-funnel-step">';
            html += '<span class="pbsg-funnel-label">' + label + '</span>';
            html += '<div class="pbsg-funnel-bar" style="width:' + Math.max( 10, pct ) + '%;background:' + color + ';">' + pct + '%</div>';
            html += '<span class="pbsg-funnel-count">' + views + ' views</span>';
            html += '</div>';
        } );

        return html;
    }

    /**
     * Step dwell time table.
     */
    function renderDwellTable( stepDwell ) {
        const keys = Object.keys( stepDwell ).sort( ( a, b ) => {
            return parseInt( a.replace( 'step_', '' ), 10 ) - parseInt( b.replace( 'step_', '' ), 10 );
        } );

        if ( ! keys.length ) return '<p style="color:#888;">No dwell data yet</p>';

        let html = '<table class="pbsg-data-table">';
        html += '<thead><tr><th>Step</th><th>Views</th><th>Avg Dwell Time</th></tr></thead>';
        html += '<tbody>';

        keys.forEach( key => {
            const d = stepDwell[ key ];
            const stepNum = parseInt( key.replace( 'step_', '' ), 10 ) + 1;
            const avgSecs = d.avg_dwell_secs || 0;

            // Color code: <30s low, 30-120s med, >120s high
            const dwellClass = avgSecs < 30 ? 'pbsg-dwell-low' : ( avgSecs < 120 ? 'pbsg-dwell-med' : 'pbsg-dwell-high' );

            html += '<tr>';
            html += '<td>Step ' + stepNum + '</td>';
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
    function renderQuestionsTable( questions, tutorialId ) {
        let html = '<table class="pbsg-data-table">';
        html += '<thead><tr>';
        html += '<th>#</th><th>Question</th><th>Attempts</th>';
        html += '<th>Correct Rate</th><th>Give-ups</th><th>Avg Attempts</th>';
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
            html += '<td><span class="pbsg-badge ' + getBadgeColor( rate ) + '">' + rate + '%</span></td>';
            html += '<td>' + ( q.giveup_count || 0 ) + '</td>';
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

        svg += '<text x="' + cx + '" y="' + ( cy - 4 ) + '" text-anchor="middle" font-size="16" font-weight="700" font-family="Lusitana" fill="#333">' + total + '</text>';
        svg += '<text x="' + cx + '" y="' + ( cy + 10 ) + '" text-anchor="middle" font-size="9" fill="#888" font-family="Roboto Condensed">TOTAL</text>';
        svg += '</svg>';

        // Legend
        svg += '<div style="margin-top:10px;">';
        segments.forEach( seg => {
            const pct = Math.round( seg.val / total * 100 );
            svg += '<div style="display:inline-flex;align-items:center;gap:5px;margin:3px 8px;font-size:11px;">';
            svg += '<span style="width:10px;height:10px;border-radius:2px;background:' + seg.color + ';display:inline-block;"></span>';
            svg += seg.label + ' <strong>' + seg.val + '</strong> (' + pct + '%)';
            svg += '</div>';
        } );
        svg += '</div></div>';

        return svg;
    }

    // =========================================================================
    // UI HELPERS
    // =========================================================================

    function renderKPIs( items ) {
        let html = '';
        items.forEach( item => {
            html += '<div class="pbsg-stat-box ' + item.color + '">';
            html += '<div class="pbsg-stat-label">' + item.label + '</div>';
            html += '<div class="pbsg-stat-value">' + item.value + '</div>';
            html += '</div>';
        } );
        $( '#pbsg-stats-row' ).html( html ).show();
    }

    function showLoading() {
        $( '#pbsg-loading' ).show();
        $( '#pbsg-empty-state' ).hide();
        $( '#pbsg-stats-row' ).hide();
        $( '#pbsg-main-content' ).hide();
    }

    function hideLoading() {
        $( '#pbsg-loading' ).hide();
    }

    function showEmpty() {
        $( '#pbsg-loading' ).hide();
        $( '#pbsg-empty-state' ).show();
        $( '#pbsg-stats-row' ).hide();
        $( '#pbsg-main-content' ).hide();
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

    function getBadgeColor( rate ) {
        rate = parseFloat( rate ) || 0;
        if ( rate >= 70 ) return 'green';
        if ( rate >= 50 ) return 'amber';
        return 'red';
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
