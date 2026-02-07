/**
 * Guide on the Side - Admin JavaScript
 * Handles block creation, editing, deletion, and reordering in admin
 */

(function($) {
    'use strict';

    /**
     * Initialize admin functionality
     */
    function init() {
        bindEvents();
        initSortable();
        toggleBlockTypeFields();
    }

    /**
     * Bind event handlers
     */
    function bindEvents() {
        // Block type change (for add form)
        $('#gots-block-type').on('change', toggleBlockTypeFields);

        // Add block
        $('#gots-add-block-btn').on('click', handleAddBlock);

        // Delete block
        $(document).on('click', '.gots-delete-block', handleDeleteBlock);

        // Edit block - open modal
        $(document).on('click', '.gots-edit-block', handleEditBlock);

        // Save edit
        $(document).on('click', '#gots-save-edit-btn', handleSaveEdit);

        // Cancel edit
        $(document).on('click', '#gots-cancel-edit-btn', closeEditModal);
        $(document).on('click', '.gots-modal-overlay', function(e) {
            if ($(e.target).hasClass('gots-modal-overlay')) {
                closeEditModal();
            }
        });

        // Block type change in edit modal
        $(document).on('change', '#gots-edit-block-type', toggleEditBlockTypeFields);
    }

    /**
     * Initialize sortable for block reordering
     */
    function initSortable() {
        $('#gots-block-list').sortable({
            handle: '.gots-block-handle',
            placeholder: 'gots-block-item ui-sortable-placeholder',
            update: function(event, ui) {
                reorderBlocks();
            }
        });
    }

    /**
     * Toggle quiz/text fields based on block type (add form)
     */
    function toggleBlockTypeFields() {
        const blockType = $('#gots-block-type').val();
        
        if (blockType === 'quiz') {
            $('.gots-quiz-fields').show();
            $('.gots-text-fields').hide();
        } else if (blockType === 'text') {
            $('.gots-quiz-fields').hide();
            $('.gots-text-fields').show();
        }
    }

    /**
     * Toggle quiz/text fields in edit modal
     */
    function toggleEditBlockTypeFields() {
        const blockType = $('#gots-edit-block-type').val();
        
        if (blockType === 'quiz') {
            $('.gots-edit-quiz-fields').show();
            $('.gots-edit-text-fields').hide();
        } else if (blockType === 'text') {
            $('.gots-edit-quiz-fields').hide();
            $('.gots-edit-text-fields').show();
        }
    }

    /**
     * Handle edit block - open modal with data
     */
    function handleEditBlock(e) {
        e.preventDefault();

        const $item = $(e.target).closest('.gots-block-item');
        const blockId = $item.data('block-id');

        // Show loading
        $item.addClass('gots-loading');

        // Fetch block data
        $.post(gotsAdmin.ajaxUrl, {
            action: 'gots_get_block',
            nonce: gotsAdmin.nonce,
            block_id: blockId
        }, function(response) {
            $item.removeClass('gots-loading');

            if (response.success) {
                openEditModal(response.data);
            } else {
                alert('Error: ' + (response.data.message || 'Could not load block data'));
            }
        }).fail(function() {
            $item.removeClass('gots-loading');
            alert('Server error. Please try again.');
        });
    }

    /**
     * Open edit modal with block data
     */
    function openEditModal(block) {
        const questionData = block.question_data || {};
        let options = questionData.options || [];
        
        // Ensure options is an array
        if (!Array.isArray(options)) {
            if (typeof options === 'object') {
                options = Object.values(options);
            } else if (typeof options === 'string') {
                options = options.split('\n');
            } else {
                options = [];
            }
        }
        
        const optionsText = options.join('\n');
        
        // Create modal HTML
        const modalHtml = `
            <div class="gots-modal-overlay">
                <div class="gots-modal">
                    <div class="gots-modal-header">
                        <h3>Edit Block</h3>
                        <button type="button" class="gots-modal-close" id="gots-cancel-edit-btn">&times;</button>
                    </div>
                    <div class="gots-modal-body">
                        <input type="hidden" id="gots-edit-block-id" value="${block.id}">
                        
                        <div class="gots-form-row">
                            <label>Block Type</label>
                            <select id="gots-edit-block-type">
                                <option value="quiz" ${block.type === 'quiz' ? 'selected' : ''}>Quiz Question (MCQ)</option>
                                <option value="text" ${block.type === 'text' ? 'selected' : ''}>Text/Instructions</option>
                            </select>
                        </div>

                        <div class="gots-form-row">
                            <label>Title</label>
                            <input type="text" id="gots-edit-block-title" value="${escapeHtml(block.title || '')}">
                        </div>

                        <div class="gots-form-row">
                            <label>Embed URL (Right Pane)</label>
                            <input type="url" id="gots-edit-embed-url" value="${escapeHtml(block.embed_url || '')}">
                            <p class="description">Use embed format for YouTube: https://www.youtube.com/embed/VIDEO_ID</p>
                        </div>

                        <!-- Quiz fields -->
                        <div class="gots-edit-quiz-fields" style="${block.type === 'quiz' ? '' : 'display:none;'}">
                            <div class="gots-form-row">
                                <label>Question Text</label>
                                <textarea id="gots-edit-question-text" rows="3">${escapeHtml(questionData.question_text || '')}</textarea>
                            </div>

                            <div class="gots-form-row">
                                <label>Answer Options (one per line)</label>
                                <textarea id="gots-edit-options" rows="4">${escapeHtml(optionsText)}</textarea>
                            </div>

                            <div class="gots-form-row">
                                <label>Correct Answer (0-based index)</label>
                                <input type="number" id="gots-edit-correct-answer" value="${questionData.correct_answer || 0}" min="0">
                                <p class="description">0 = first option, 1 = second option, etc.</p>
                            </div>

                            <div class="gots-form-row">
                                <label>Feedback - Correct</label>
                                <input type="text" id="gots-edit-feedback-correct" value="${escapeHtml(questionData.feedback_correct || '')}">
                            </div>

                            <div class="gots-form-row">
                                <label>Feedback - Incorrect</label>
                                <input type="text" id="gots-edit-feedback-incorrect" value="${escapeHtml(questionData.feedback_incorrect || '')}">
                            </div>
                        </div>

                        <!-- Text fields -->
                        <div class="gots-edit-text-fields" style="${block.type === 'text' ? '' : 'display:none;'}">
                            <div class="gots-form-row">
                                <label>Content</label>
                                <textarea id="gots-edit-text-content" rows="5">${escapeHtml(block.text_content || '')}</textarea>
                            </div>
                        </div>
                    </div>
                    <div class="gots-modal-footer">
                        <button type="button" class="button" id="gots-cancel-edit-btn">Cancel</button>
                        <button type="button" class="button button-primary" id="gots-save-edit-btn">Save Changes</button>
                    </div>
                </div>
            </div>
        `;

        // Append and show modal
        $('body').append(modalHtml);
        
        // Prevent body scroll
        $('body').addClass('gots-modal-open');
    }

    /**
     * Close edit modal
     */
    function closeEditModal() {
        $('.gots-modal-overlay').remove();
        $('body').removeClass('gots-modal-open');
    }

    /**
     * Handle save edit
     */
    function handleSaveEdit(e) {
        e.preventDefault();

        const $btn = $('#gots-save-edit-btn');
        const blockId = $('#gots-edit-block-id').val();
        const blockType = $('#gots-edit-block-type').val();

        // Gather data
        const data = {
            action: 'gots_update_block',
            nonce: gotsAdmin.nonce,
            block_id: blockId,
            block_type: blockType,
            title: $('#gots-edit-block-title').val(),
            embed_url: $('#gots-edit-embed-url').val(),
        };

        // Add type-specific data
        if (blockType === 'quiz') {
            data.question_text = $('#gots-edit-question-text').val();
            data.options = $('#gots-edit-options').val();
            data.correct_answer = $('#gots-edit-correct-answer').val();
            data.feedback_correct = $('#gots-edit-feedback-correct').val();
            data.feedback_incorrect = $('#gots-edit-feedback-incorrect').val();
        } else if (blockType === 'text') {
            data.text_content = $('#gots-edit-text-content').val();
        }

        // Validate
        if (!data.title) {
            alert('Please enter a block title.');
            return;
        }

        // Submit
        $btn.prop('disabled', true).text('Saving...');

        $.post(gotsAdmin.ajaxUrl, data, function(response) {
            if (response.success) {
                // Update block item in list
                const $item = $(`.gots-block-item[data-block-id="${blockId}"]`);
                $item.replaceWith(response.data.html);

                // Close modal
                closeEditModal();

                // Show success
                showMessage('Block updated successfully!', 'success');
            } else {
                alert('Error: ' + (response.data.message || 'Could not update block'));
                $btn.prop('disabled', false).text('Save Changes');
            }
        }).fail(function() {
            alert('Server error. Please try again.');
            $btn.prop('disabled', false).text('Save Changes');
        });
    }

    /**
     * Handle add block
     */
    function handleAddBlock() {
        const $form = $('#gots-add-block-form');
        const $btn = $('#gots-add-block-btn');
        const blockType = $('#gots-block-type').val();

        // Gather form data
        const data = {
            action: 'gots_add_block',
            nonce: gotsAdmin.nonce,
            post_id: gotsAdmin.postId,
            block_type: blockType,
            title: $('#gots-block-title').val(),
            embed_url: $('#gots-embed-url').val(),
        };

        // Add type-specific data
        if (blockType === 'quiz') {
            data.question_text = $('#gots-question-text').val();
            data.options = $('#gots-options').val();
            data.correct_answer = $('#gots-correct-answer').val();
            data.feedback_correct = $('#gots-feedback-correct').val();
            data.feedback_incorrect = $('#gots-feedback-incorrect').val();
        } else if (blockType === 'text') {
            data.text_content = $('#gots-text-content').val();
        }

        // Validate
        if (!data.title) {
            alert('Please enter a block title.');
            return;
        }

        if (blockType === 'quiz' && !data.question_text) {
            alert('Please enter a question.');
            return;
        }

        // Submit
        $btn.prop('disabled', true).text('Adding...');
        $form.addClass('gots-loading');

        $.post(gotsAdmin.ajaxUrl, data, function(response) {
            if (response.success) {
                // Add block to list
                const $list = $('#gots-block-list');
                
                // Remove "no blocks" message if present
                $list.find('.gots-no-blocks').remove();
                
                // Append new block
                $list.append(response.data.html);

                // Clear form
                clearForm();

                // Show success
                showMessage('Block added successfully!', 'success');
            } else {
                alert('Error: ' + (response.data.message || 'Could not add block'));
            }
        }).fail(function() {
            alert('Server error. Please try again.');
        }).always(function() {
            $btn.prop('disabled', false).text('Add Block');
            $form.removeClass('gots-loading');
        });
    }

    /**
     * Handle delete block
     */
    function handleDeleteBlock(e) {
        e.preventDefault();

        if (!confirm('Are you sure you want to delete this block?')) {
            return;
        }

        const $item = $(e.target).closest('.gots-block-item');
        const blockId = $item.data('block-id');

        $item.addClass('gots-loading');

        $.post(gotsAdmin.ajaxUrl, {
            action: 'gots_delete_block',
            nonce: gotsAdmin.nonce,
            block_id: blockId
        }, function(response) {
            if (response.success) {
                $item.slideUp(300, function() {
                    $(this).remove();
                    
                    // Show "no blocks" message if list is empty
                    if ($('#gots-block-list').children().length === 0) {
                        $('#gots-block-list').html('<p class="gots-no-blocks">No blocks yet. Add your first block below.</p>');
                    }
                });
            } else {
                alert('Error: ' + (response.data.message || 'Could not delete block'));
                $item.removeClass('gots-loading');
            }
        }).fail(function() {
            alert('Server error. Please try again.');
            $item.removeClass('gots-loading');
        });
    }

    /**
     * Reorder blocks via AJAX
     */
    function reorderBlocks() {
        const blockIds = [];
        
        $('#gots-block-list .gots-block-item').each(function() {
            blockIds.push($(this).data('block-id'));
        });

        $.post(gotsAdmin.ajaxUrl, {
            action: 'gots_reorder_blocks',
            nonce: gotsAdmin.nonce,
            block_ids: blockIds
        });
    }

    /**
     * Clear form after adding block
     */
    function clearForm() {
        $('#gots-block-title').val('');
        $('#gots-embed-url').val('');
        $('#gots-question-text').val('');
        $('#gots-options').val('');
        $('#gots-correct-answer').val('0');
        $('#gots-feedback-correct').val('');
        $('#gots-feedback-incorrect').val('');
        $('#gots-text-content').val('');
    }

    /**
     * Show temporary message
     */
    function showMessage(text, type) {
        const $msg = $('<div class="gots-message gots-message-' + type + '">' + text + '</div>');
        
        $('.gots-add-block-section').prepend($msg);
        
        setTimeout(function() {
            $msg.fadeOut(300, function() {
                $(this).remove();
            });
        }, 3000);
    }

    /**
     * Escape HTML for safe insertion
     */
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initialize on DOM ready
    $(document).ready(init);

})(jQuery);
