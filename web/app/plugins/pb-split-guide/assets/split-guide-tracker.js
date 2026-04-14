/**
 * Split Guide Analytics Tracker (split-guide-tracker.js)
 *
 * Invisible client-side event dispatcher for Guide on the Side tutorials.
 * Runs on the student-facing tutorial page — NO UI, no banners, no consent popups.
 *
 * Privacy guarantees:
 *  - Session ID lives in JS memory ONLY (not cookies, not localStorage)
 *  - No PII collected — no names, emails, IPs stored
 *  - Dwell times accumulated in memory, flushed as aggregates
 *  - navigator.sendBeacon() on tab close — fire-and-forget
 *  - Server discards session context, stores only aggregate counters
 *
 * Events tracked:
 *  1. tutorial_view     — page load
 *  2. slide_view        — step navigation (prev/next), records dwell time
 *  3. quiz_attempt      — H5P xAPI postMessage (correct/incorrect, attempt #)
 *  4. quiz_giveup       — H5P xAPI "give up" event (conditional on H5P support)
 *  5. tutorial_complete  — final step reached
 *  6. session_flush     — tab close, sends accumulated dwell times via sendBeacon
 *
 * @package PB_Split_Guide
 * @since   1.0.0
 */

( function() {
    'use strict';

    // =========================================================================
    // Configuration (injected by wp_localize_script in the template)
    // =========================================================================

    if ( typeof window.pbsgTracker === 'undefined' ) {
        // Not on a tutorial page or localize data missing
        return;
    }

    const config = window.pbsgTracker;
    const AJAX_URL     = config.ajaxUrl;
    const TUTORIAL_ID  = parseInt( config.tutorialPageId, 10 );
    const TOTAL_STEPS  = parseInt( config.totalSteps, 10 );

    if ( ! TUTORIAL_ID || ! AJAX_URL ) {
        return;
    }

    // =========================================================================
    // Session State (in-memory only — dies on tab close)
    // =========================================================================

    const session = {
        startTime:      Date.now(),
        currentStep:    0,
        stepEnteredAt:  Date.now(),
        stepDwellTimes: {},      // { stepIndex: totalMilliseconds }
        quizAttempts:   {},      // { "h5p_id-q_index": attemptCount }
        completed:      false,
        viewRecorded:   false,
    };

    // =========================================================================
    // Event Sending
    // =========================================================================

    /**
     * Send an analytics event to the server via fetch().
     * Fire-and-forget — we don't block the UI waiting for a response.
     *
     * @param {string} eventType - One of the valid event types
     * @param {Object} data      - Additional event data
     */
    function sendEvent( eventType, data ) {
        const payload = Object.assign( {
            event_type:       eventType,
            tutorial_page_id: TUTORIAL_ID,
            touch_points:     navigator.maxTouchPoints || 0,
        }, data || {} );

        try {
            fetch( AJAX_URL + '?action=pbsg_track_event', {
                method:      'POST',
                headers:     { 'Content-Type': 'application/json' },
                body:        JSON.stringify( payload ),
                keepalive:   true,
                credentials: 'same-origin',
            } ).then( function( response ) {
                // Tutorial unpublished mid-session — redirect student
                if ( response.status === 410 ) {
                    window.location.href = '/?pbsg-error=410';
                }
            } ).catch( function() {
                // Silently fail — analytics should never break the tutorial
            } );
        } catch ( e ) {
            // Silently fail
        }
    }

    /**
     * Send data via navigator.sendBeacon (used on tab close).
     * sendBeacon is fire-and-forget and works even during page unload.
     */
    function sendBeaconEvent( eventType, data ) {
        const payload = Object.assign( {
            event_type:       eventType,
            tutorial_page_id: TUTORIAL_ID,
            touch_points:     navigator.maxTouchPoints || 0,
        }, data || {} );

        try {
            const blob = new Blob(
                [ JSON.stringify( payload ) ],
                { type: 'application/json' }
            );
            navigator.sendBeacon( AJAX_URL + '?action=pbsg_track_event', blob );
        } catch ( e ) {
            // Fallback to fetch with keepalive
            sendEvent( eventType, data );
        }
    }

    // =========================================================================
    // 1. TUTORIAL VIEW
    // =========================================================================

    function recordTutorialView() {
        if ( session.viewRecorded ) return;
        session.viewRecorded = true;
        sendEvent( 'tutorial_view' );
    }

    // =========================================================================
    // 2. SLIDE/STEP TRANSITIONS
    // =========================================================================

    /**
     * Record dwell time for the current step, then update current step.
     * Called when the user clicks Prev/Next.
     *
     * @param {number} newStepIndex - The step they're navigating TO
     */
    function recordStepTransition( newStepIndex ) {
        const now       = Date.now();
        const dwellMs   = now - session.stepEnteredAt;
        const dwellSecs = Math.round( dwellMs / 1000 );
        const prevStep  = session.currentStep;

        // Accumulate dwell time for the step they're LEAVING
        if ( ! session.stepDwellTimes[ prevStep ] ) {
            session.stepDwellTimes[ prevStep ] = 0;
        }
        session.stepDwellTimes[ prevStep ] += dwellSecs;

        // Send individual slide_view event
        sendEvent( 'slide_view', {
            step_index:         prevStep,
            dwell_time_seconds: dwellSecs,
        } );

        // Update session state
        session.currentStep   = newStepIndex;
        session.stepEnteredAt = now;

        // Check for tutorial completion (reached final step)
        if ( newStepIndex === TOTAL_STEPS - 1 && ! session.completed ) {
            session.completed = true;
            const totalTimeSecs = Math.round( ( now - session.startTime ) / 1000 );
            sendEvent( 'tutorial_complete', {
                total_time_seconds: totalTimeSecs,
            } );
        }
    }

    // =========================================================================
    // 3 & 4. H5P QUIZ EVENTS (xAPI via postMessage)
    // =========================================================================

    /**
     * Listen for H5P xAPI events via externalDispatcher inside the iframe.
     * H5P does NOT postMessage xAPI to parent — we must access the iframe directly.
     * Since the H5P embed is same-origin (admin-ajax.php), this works.
     */
    function initH5PListener() {
        var h5pFrame = document.getElementById( 'pbsgH5PFrame' );
        if ( ! h5pFrame ) return;

        function attachDispatcher() {
            try {
                var iw = h5pFrame.contentWindow;
                if ( iw && iw.H5P && iw.H5P.externalDispatcher ) {
                    iw.H5P.externalDispatcher.on( 'xAPI', function( event ) {
                        var statement = event.data && event.data.statement;
                        if ( ! statement || ! statement.verb ) return;

                        var verb     = statement.verb.id || '';
                        var verbName = verb.split( '/' ).pop();

                        if ( verbName === 'answered' || verbName === 'attempted' ) {
                            handleQuizAttempt( statement );
                        }

                        if ( statement.result &&
                             statement.result.completion === false &&
                             statement.result.response === '' ) {
                            handleQuizGiveup( statement );
                        }
                    } );
                    return true;
                }
            } catch ( e ) {}
            return false;
        }

        if ( ! attachDispatcher() ) {
            h5pFrame.addEventListener( 'load', function() {
                var attempts = 0;
                var poll = setInterval( function() {
                    if ( attachDispatcher() || ++attempts > 20 ) {
                        clearInterval( poll );
                    }
                }, 500 );
            } );
        }

        // Re-attach when step changes (iframe src changes)
        var observer = new MutationObserver( function( mutations ) {
            mutations.forEach( function( m ) {
                if ( m.type === 'attributes' && m.attributeName === 'src' ) {
                    h5pFrame.addEventListener( 'load', function onNav() {
                        h5pFrame.removeEventListener( 'load', onNav );
                        var attempts = 0;
                        var poll = setInterval( function() {
                            if ( attachDispatcher() || ++attempts > 20 ) {
                                clearInterval( poll );
                            }
                        }, 500 );
                    } );
                }
            } );
        } );
        observer.observe( h5pFrame, { attributes: true } );
    }

    /**
     * Process an H5P quiz attempt xAPI statement.
     */
    function handleQuizAttempt( statement ) {
        // Skip branch/remediation quiz attempts — they're not assessable content
        if ( typeof window.pbsgInBranch === 'function' && window.pbsgInBranch() ) {
            return;
        }
        var result = statement.result || {};
        var object = statement.object || {};

        // Extract H5P content ID from extensions (primary) or URL (fallback)
        var extensions = ( object.definition && object.definition.extensions ) || {};
        var h5pId = extensions['http://h5p.org/x-api/h5p-local-content-id'] || 0;
        if ( ! h5pId ) {
            var defExtensions = ( object.definition && object.definition.extensions ) || {};
        var h5pId = defExtensions['http://h5p.org/x-api/h5p-local-content-id'] || 0;
        if ( ! h5pId ) {
            var objectId = object.id || '';
            var h5pMatch = objectId.match( /[?&]id=(\d+)/ );
            h5pId = h5pMatch ? parseInt( h5pMatch[1], 10 ) : 0;
        }
        }

        // Question index (subContentId for multi-question sets, 0 for single)
        var qIndex = extensions['http://h5p.org/x-api/h5p-subContentId'] || 0;

        // Track attempt count per question
        var attemptKey = h5pId + '-' + qIndex;
        if ( ! session.quizAttempts[ attemptKey ] ) {
            session.quizAttempts[ attemptKey ] = 0;
        }
        session.quizAttempts[ attemptKey ]++;

        var isCorrect    = result.success === true;
        var attemptNum   = session.quizAttempts[ attemptKey ];
        var questionText = ( object.definition && object.definition.description )
            ? ( object.definition.description['en-US'] || object.definition.description['en'] || '' )
            : '';

        // Strip HTML tags from question text
        questionText = questionText.replace( /<[^>]+>/g, '' );

        sendEvent( 'quiz_attempt', {
            h5p_content_id:  h5pId,
            question_index:  typeof qIndex === 'string' ? 0 : parseInt( qIndex, 10 ),
            question_text:   questionText.substring( 0, 500 ),
            is_correct:      isCorrect,
            attempt_number:  attemptNum,
            time_seconds:    result.duration ? parseDuration( result.duration ) : 0,
        } );
    }

    /**
     * Handle H5P "give up" events.
     */
    function handleQuizGiveup( statement ) {
        // Skip branch/remediation quiz giveups — they're not assessable content
        if ( typeof window.pbsgInBranch === 'function' && window.pbsgInBranch() ) {
            return;
        }
        const object   = statement.object || {};
        const objectId = object.id || '';
        const h5pMatch = objectId.match( /h5p-(\d+)/ );
        const h5pId    = h5pMatch ? parseInt( h5pMatch[1], 10 ) : 0;

        const extensions = ( object.definition && object.definition.extensions ) || {};
        const qIndex    = extensions['http://h5p.org/x-api/h5p-subContentId'] || 0;

        sendEvent( 'quiz_giveup', {
            h5p_content_id: h5pId,
            question_index: typeof qIndex === 'string' ? 0 : parseInt( qIndex, 10 ),
        } );
    }

    /**
     * Parse ISO 8601 duration (PT1M30S) to seconds.
     */
    function parseDuration( iso ) {
        if ( typeof iso !== 'string' ) return 0;
        const match = iso.match( /PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?/ );
        if ( ! match ) return 0;
        return ( parseInt( match[1] || 0, 10 ) * 3600 ) +
               ( parseInt( match[2] || 0, 10 ) * 60 ) +
               Math.round( parseFloat( match[3] || 0 ) );
    }

    // =========================================================================
    // 6. SESSION FLUSH (tab close / navigate away)
    // =========================================================================

    function flushSession() {
        // Record dwell time for current step
        const now       = Date.now();
        const dwellMs   = now - session.stepEnteredAt;
        const dwellSecs = Math.round( dwellMs / 1000 );
        const curStep   = session.currentStep;

        if ( ! session.stepDwellTimes[ curStep ] ) {
            session.stepDwellTimes[ curStep ] = 0;
        }
        session.stepDwellTimes[ curStep ] += dwellSecs;

        const totalTimeSecs = Math.round( ( now - session.startTime ) / 1000 );

        sendBeaconEvent( 'session_flush', {
            step_dwell_times:   session.stepDwellTimes,
            total_time_seconds: totalTimeSecs,
        } );
    }

    // =========================================================================
    // HOOK INTO EXISTING SPLIT-GUIDE NAVIGATION
    // =========================================================================

    /**
     * Attach to the existing Prev/Next button click handlers.
     * The split-guide template uses inline JS with prevBtn/nextBtn references.
     * We observe step changes by watching the URL hash or the step indicator.
     */
    function hookNavigation() {
        // Strategy 1: MutationObserver on progress indicator.
        // split-guide.js render() updates #pbsgProgress with "Page: X of Y".
        var stepIndicator = document.getElementById( 'pbsgProgress' ) ||
                            document.getElementById( 'pbsgProgressLabel' );
        if ( stepIndicator ) {
            var observer = new MutationObserver( function() {
                var text  = stepIndicator.textContent || '';
                var match = text.match( /(\d+)\s*(?:\/|of)\s*(\d+)/ );
                if ( match ) {
                    var newStep = parseInt( match[1], 10 ) - 1;
                    if ( newStep !== session.currentStep ) {
                        recordStepTransition( newStep );
                    }
                }
            } );
            observer.observe( stepIndicator, { childList: true, characterData: true, subtree: true } );
        }

        // Strategy 2: Direct button interception (prev/next and menu items).
        document.addEventListener( 'click', function( e ) {
            var btn = e.target.closest( '#pbsgPrev, #pbsgNext, .pbsg-menu-item, [data-step-nav]' );
            if ( ! btn ) return;

            // Small delay to let the template's own handler update state
            setTimeout( function() {
                var progressEl = document.getElementById( 'pbsgProgress' ) ||
                                 document.getElementById( 'pbsgProgressLabel' );
                if ( progressEl ) {
                    var text  = progressEl.textContent || '';
                    var match = text.match( /(\d+)\s*(?:\/|of)\s*(\d+)/ );
                    if ( match ) {
                        var newStep = parseInt( match[1], 10 ) - 1;
                        if ( newStep !== session.currentStep ) {
                            recordStepTransition( newStep );
                        }
                    }
                }
            }, 50 );
        } );

        // Strategy 3: Listen for hashchange (fallback if template uses hashes)
        window.addEventListener( 'hashchange', function() {
            var hash      = window.location.hash;
            var stepMatch = hash.match( /step-(\d+)/ );
            if ( stepMatch ) {
                var newStep = parseInt( stepMatch[1], 10 ) - 1;
                if ( newStep !== session.currentStep ) {
                    recordStepTransition( newStep );
                }
            }
        } );
    }

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    function init() {
        // 1. Record initial tutorial view
        recordTutorialView();

        // 2. Hook into step navigation
        hookNavigation();

        // 3. Listen for H5P quiz events
        initH5PListener();

        // 4. Register session flush on tab close
        // Use both visibilitychange and beforeunload for maximum coverage
        document.addEventListener( 'visibilitychange', function() {
            if ( document.visibilityState === 'hidden' ) {
                flushSession();
            }
        } );

        window.addEventListener( 'beforeunload', function() {
            flushSession();
        } );

        // 5. Parse initial step from hash (if user lands on a specific step)
        const hash      = window.location.hash;
        const stepMatch = hash.match( /step-(\d+)/ );
        if ( stepMatch ) {
            session.currentStep  = parseInt( stepMatch[1], 10 ) - 1;
            session.stepEnteredAt = Date.now();
        }
    }

    // Wait for DOM ready
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }

} )();
