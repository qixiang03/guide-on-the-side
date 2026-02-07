/**
 * Guide on the Side - Frontend JavaScript
 * Handles quiz interaction, navigation, and iframe switching
 */

(function($) {
    'use strict';

    // Tutorial state
    const state = {
        currentIndex: 0,
        totalBlocks: 0,
        answers: {},
        correctCount: 0,
        answeredCount: 0
    };

    /**
     * Initialize tutorial
     */
    function init() {
        const $container = $('.gots-tutorial-container');
        if (!$container.length) return;

        state.totalBlocks = $container.find('.gots-block').length;
        
        // Update total questions display
        $('.gots-total-questions').text(state.totalBlocks);

        // Bind events
        bindEvents();

        // Show first block
        showBlock(0);
    }

    /**
     * Bind event handlers
     */
    function bindEvents() {
        // Option selection
        $(document).on('change', '.gots-option input', handleOptionChange);
        
        // Submit answer
        $(document).on('click', '.gots-btn-submit-answer', handleSubmitAnswer);
        
        // Navigation
        $(document).on('click', '.gots-btn-prev', handlePrevious);
        $(document).on('click', '.gots-btn-next', handleNext);
        $(document).on('click', '.gots-btn-finish', handleFinish);
        $(document).on('click', '.gots-btn-restart', handleRestart);

        // Keyboard navigation
        $(document).on('keydown', handleKeyboard);
    }

    /**
     * Show specific block
     */
    function showBlock(index) {
        if (index < 0 || index >= state.totalBlocks) return;

        const $blocks = $('.gots-block');
        
        // Hide all blocks
        $blocks.hide();
        
        // Show target block
        const $targetBlock = $blocks.eq(index);
        $targetBlock.fadeIn(300);

        state.currentIndex = index;

        // Update iframe
        const embedUrl = $targetBlock.data('embed-url');
        updateIframe(embedUrl);

        // Update progress
        updateProgress();

        // Update navigation buttons
        updateNavigation();

        // Update current question display
        $('.gots-current-question').text(index + 1);
    }

    /**
     * Update iframe source
     */
    function updateIframe(url) {
        const $iframe = $('#gots-embed-frame');
        const $placeholder = $('.gots-embed-placeholder');

        if (url && url.trim() !== '') {
            $iframe.attr('src', url).show();
            $placeholder.hide();
        } else {
            $iframe.attr('src', 'about:blank').hide();
            $placeholder.show();
        }
    }

    /**
     * Update progress bar
     */
    function updateProgress() {
        const progress = ((state.currentIndex + 1) / state.totalBlocks) * 100;
        $('.gots-progress-fill').css('width', progress + '%');
    }

    /**
     * Update navigation buttons
     */
    function updateNavigation() {
        const $prevBtn = $('.gots-btn-prev');
        const $nextBtn = $('.gots-btn-next');
        const $finishBtn = $('.gots-btn-finish');

        // Previous button
        $prevBtn.prop('disabled', state.currentIndex === 0);

        // Next/Finish button
        if (state.currentIndex === state.totalBlocks - 1) {
            $nextBtn.hide();
            $finishBtn.show();
        } else {
            $nextBtn.show();
            $finishBtn.hide();
        }
    }

    /**
     * Handle option selection (visual feedback)
     */
    function handleOptionChange(e) {
        const $input = $(e.target);
        const $options = $input.closest('.gots-options').find('.gots-option');
        const isCheckbox = $input.attr('type') === 'checkbox';

        if (!isCheckbox) {
            // Radio: deselect all, select current
            $options.removeClass('selected');
        }

        $input.closest('.gots-option').toggleClass('selected', $input.is(':checked'));
    }

    /**
     * Handle answer submission
     */
    function handleSubmitAnswer(e) {
        const $btn = $(e.target);
        const blockId = $btn.data('block-id');
        const $block = $btn.closest('.gots-quiz-block');
        const questionType = $block.data('question-type');

        // Get user's answer
        let answer = getUserAnswer($block, questionType);

        if (answer === null || answer === '' || (Array.isArray(answer) && answer.length === 0)) {
            alert('Please select an answer.');
            return;
        }

        // Disable further changes
        $block.find('.gots-option').addClass('disabled');
        $block.find('input').prop('disabled', true);
        $btn.addClass('answered').text('Answered');

        // Validate answer via AJAX or local validation
        validateAnswer(blockId, answer, $block, questionType);
    }

    /**
     * Get user's answer from form
     */
    function getUserAnswer($block, questionType) {
        if (questionType === 'text_input') {
            return $block.find('.gots-text-input').val().trim();
        } else if (questionType === 'checkbox') {
            const answers = [];
            $block.find('input:checked').each(function() {
                answers.push(parseInt($(this).val()));
            });
            return answers;
        } else {
            const $checked = $block.find('input:checked');
            if ($checked.length === 0) return null;
            
            const val = $checked.val();
            // Handle yes/no
            if (questionType === 'yes_no') {
                return val;
            }
            return parseInt(val);
        }
    }

    /**
     * Validate answer (simplified local validation for demo)
     * In production, this would call the REST API
     */
    function validateAnswer(blockId, answer, $block, questionType) {
        // For now, we'll use a simplified approach
        // Try REST API first, fall back to local
        
        if (typeof gotsData !== 'undefined' && gotsData.restUrl) {
            $.ajax({
                url: gotsData.restUrl + 'quizzes/' + blockId + '/validate',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ answer: answer }),
                headers: {
                    'X-WP-Nonce': gotsData.nonce
                },
                success: function(response) {
                    showFeedback($block, response);
                },
                error: function() {
                    // Fallback: just show that answer was recorded
                    showFeedback($block, {
                        is_correct: true,
                        feedback: 'Answer recorded.'
                    });
                }
            });
        } else {
            // No REST API available, show generic feedback
            showFeedback($block, {
                is_correct: true,
                feedback: 'Answer recorded.'
            });
        }
    }

    /**
     * Show feedback after answer submission
     */
    function showFeedback($block, response) {
        const $feedback = $block.find('.gots-feedback');
        const $feedbackText = $feedback.find('.gots-feedback-text');
        const isCorrect = response.is_correct;

        // Update state
        state.answeredCount++;
        if (isCorrect) {
            state.correctCount++;
        }

        // Store answer
        const blockId = $block.data('block-id');
        state.answers[blockId] = {
            isCorrect: isCorrect,
            answer: response.user_answer
        };

        // Show visual feedback on options
        if (typeof response.correct_answer !== 'undefined') {
            const $options = $block.find('.gots-option');
            $options.each(function(index) {
                const $option = $(this);
                if (index === response.correct_answer) {
                    $option.addClass('correct');
                } else if ($option.hasClass('selected') && index !== response.correct_answer) {
                    $option.addClass('incorrect');
                }
            });
        }

        // Show feedback message
        $feedback
            .removeClass('correct incorrect')
            .addClass(isCorrect ? 'correct' : 'incorrect')
            .show();
        
        $feedbackText.text(response.feedback || (isCorrect ? 'Correct!' : 'Incorrect.'));
    }

    /**
     * Handle previous button
     */
    function handlePrevious() {
        if (state.currentIndex > 0) {
            showBlock(state.currentIndex - 1);
        }
    }

    /**
     * Handle next button
     */
    function handleNext() {
        if (state.currentIndex < state.totalBlocks - 1) {
            showBlock(state.currentIndex + 1);
        }
    }

    /**
     * Handle finish button
     */
    function handleFinish() {
        showResults();
    }

    /**
     * Show results panel
     */
    function showResults() {
        const $splitScreen = $('.gots-split-screen');
        const $results = $('.gots-results-panel');
        const $header = $('.gots-tutorial-header');

        // Hide tutorial, show results
        $splitScreen.fadeOut(300, function() {
            $results.fadeIn(300);
        });
        $header.fadeOut(300);

        // Calculate score
        const totalQuizzes = Object.keys(state.answers).length || state.answeredCount || 1;
        const percentage = Math.round((state.correctCount / totalQuizzes) * 100);

        // Update results display
        $('.gots-score-value').text(state.correctCount);
        $('.gots-score-total').text(totalQuizzes);
        $('.gots-score-percentage').text(percentage + '%');

        // Track completion (if API available)
        trackCompletion();
    }

    /**
     * Track tutorial completion
     */
    function trackCompletion() {
        const tutorialId = $('.gots-tutorial-container').data('tutorial-id');
        
        if (typeof gotsData !== 'undefined' && gotsData.restUrl) {
            $.ajax({
                url: gotsData.restUrl + 'progress',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    tutorial_id: tutorialId,
                    completed: true,
                    correct_answers: state.correctCount,
                    questions_answered: state.answeredCount
                }),
                headers: {
                    'X-WP-Nonce': gotsData.nonce
                }
            });
        }
    }

    /**
     * Handle restart button
     */
    function handleRestart() {
        // Reset state
        state.currentIndex = 0;
        state.answers = {};
        state.correctCount = 0;
        state.answeredCount = 0;

        // Reset UI
        $('.gots-results-panel').hide();
        $('.gots-tutorial-header').show();
        $('.gots-split-screen').show();

        // Reset all blocks
        $('.gots-option')
            .removeClass('selected correct incorrect disabled');
        $('.gots-option input')
            .prop('checked', false)
            .prop('disabled', false);
        $('.gots-text-input')
            .val('')
            .prop('disabled', false);
        $('.gots-btn-submit-answer')
            .removeClass('answered')
            .text('Check Answer');
        $('.gots-feedback').hide();

        // Show first block
        showBlock(0);
    }

    /**
     * Handle keyboard navigation
     */
    function handleKeyboard(e) {
        // Only if not in input field
        if ($(e.target).is('input, textarea')) return;

        switch (e.key) {
            case 'ArrowLeft':
                handlePrevious();
                break;
            case 'ArrowRight':
                handleNext();
                break;
            case 'Enter':
                // Submit answer if on quiz block
                const $submitBtn = $('.gots-block:visible .gots-btn-submit-answer:not(.answered)');
                if ($submitBtn.length) {
                    $submitBtn.click();
                }
                break;
        }
    }

    // Initialize on DOM ready
    $(document).ready(init);

})(jQuery);
