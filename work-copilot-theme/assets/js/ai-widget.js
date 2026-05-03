/**
 * AI Widget JavaScript
 *
 * Handles conversation UI, AJAX calls, and approval flow
 * Updated: Action buttons, context modes, prompt chips
 */

(function($) {
    'use strict';

    const AIWidget = {
        conversationId: null,
        currentProposals: [], // Array of proposals for multi-item support
        currentBatchId: null, // Batch ID for grouping proposals
        currentAction: 'chat', // 'chat' or 'generate'
        contextMode: 'page', // 'page', 'corpus', or 'select'
        selectedPages: [],
        pagesCache: null,

        /**
         * Initialize widget
         */
        init: function() {
            this.bindEvents();
            this.initConversation();
            this.fetchActiveMission();
        },

        /**
         * Bind UI events
         */
        bindEvents: function() {
            // Toggle widget
            $(document).on('click', '.wcp-ai-toggle', () => {
                this.openWidget();
            });

            $(document).on('click', '.wcp-ai-minimize', () => {
                this.minimizeWidget();
            });

            $(document).on('click', '.wcp-ai-close', () => {
                this.closeWidget();
            });

            // Action buttons
            $(document).on('click', '.wcp-ai-action-btn', (e) => {
                const action = $(e.currentTarget).data('action');
                this.setAction(action);
            });

            // Context mode change
            $(document).on('change', '#wcp-ai-context-mode', (e) => {
                this.setContextMode($(e.target).val());
            });

            // Page search
            $(document).on('input', '#wcp-ai-page-search', (e) => {
                this.searchPages($(e.target).val());
            });

            // Page selection
            $(document).on('change', '.wcp-page-checkbox input', (e) => {
                this.togglePageSelection(
                    parseInt($(e.target).val()),
                    $(e.target).is(':checked')
                );
            });

            // Tree toggle
            $(document).on('click', '.wcp-tree-toggle', (e) => {
                const $toggle = $(e.target);
                const $item = $toggle.closest('.wcp-page-tree-item');
                const expanded = $toggle.data('expanded');

                if (expanded) {
                    $item.find('> ul').slideUp();
                    $toggle.text('▶').data('expanded', false);
                } else {
                    $item.find('> ul').slideDown();
                    $toggle.text('▼').data('expanded', true);
                }
            });

            // Page options change
            $(document).on('change', '.wcp-page-options input', (e) => {
                const $item = $(e.target).closest('.wcp-page-tree-item');
                const pageId = parseInt($item.data('page-id'));
                const option = $(e.target).hasClass('include-children') ? 'include_children' : 'include_items';
                const checked = $(e.target).is(':checked');

                this.updatePageOption(pageId, option, checked);
            });

            // Prompt chips
            $(document).on('click', '.wcp-ai-chip', (e) => {
                const prompt = $(e.currentTarget).data('prompt');
                $('#wcp-ai-prompt').val(prompt);
            });

            // Send message
            $(document).on('click', '#wcp-ai-send', () => {
                this.sendMessage();
            });

            // Enter key in textarea
            $(document).on('keydown', '#wcp-ai-prompt', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.sendMessage();
                }
            });

            // Save prompt
            $(document).on('click', '#wcp-ai-save-prompt', () => {
                this.showSavePromptModal();
            });

            $(document).on('click', '#wcp-ai-save-confirm', () => {
                this.savePrompt();
            });

            $(document).on('click', '#wcp-ai-save-cancel', () => {
                this.hideSavePromptModal();
            });

            // Approval actions
            $(document).on('click', '.wcp-ai-accept-btn', () => {
                this.acceptProposals();
            });

            $(document).on('click', '.wcp-ai-dismiss-btn', () => {
                this.dismissProposals();
            });

            // Proposal checkbox change
            $(document).on('change', '.wcp-proposal-checkbox input', (e) => {
                this.updateProposalSelection(e.target);
            });

            // Mission toggle
            $(document).on('click', '.wcp-mission-toggle', () => {
                $('.wcp-mission-content').slideToggle();
                $('.wcp-mission-toggle .dashicons').toggleClass('dashicons-arrow-down dashicons-arrow-up');
            });
        },

        /**
         * Set current action (chat or generate)
         */
        setAction: function(action) {
            this.currentAction = action;

            // Update button states
            $('.wcp-ai-action-btn').removeClass('active');
            $(`.wcp-ai-action-btn[data-action="${action}"]`).addClass('active');

            // Show/hide item count wrapper
            if (action === 'generate') {
                $('.wcp-ai-item-count-wrapper').css('display', 'flex');
                $('#wcp-ai-prompt').attr('placeholder', 'Describe the items you want to create...');
            } else {
                $('.wcp-ai-item-count-wrapper').css('display', 'none');
                $('#wcp-ai-prompt').attr('placeholder', 'Ask a question about your work...');
            }
        },

        /**
         * Set context mode
         */
        setContextMode: function(mode) {
            this.contextMode = mode;

            // Show/hide page picker
            if (mode === 'select') {
                $('.wcp-ai-page-picker').slideDown();
                this.loadPages();
            } else {
                $('.wcp-ai-page-picker').slideUp();
                this.selectedPages = [];
                this.updateSelectedCount();
            }
        },

        /**
         * Load pages for picker
         */
        loadPages: function(search = '') {
            if (this.pagesCache && !search) {
                this.renderPageList(this.pagesCache);
                return;
            }

            $.ajax({
                url: wcpAiWidgetData.restUrl + '/pages/list',
                method: 'GET',
                beforeSend: (xhr) => {
                    xhr.setRequestHeader('X-WP-Nonce', wcpAiWidgetData.nonce);
                },
                data: { search: search },
                success: (response) => {
                    if (response.success) {
                        if (!search) {
                            this.pagesCache = response.pages;
                        }
                        this.renderPageList(response.pages);
                    }
                }
            });
        },

        /**
         * Search pages
         */
        searchPages: function(query) {
            clearTimeout(this.searchTimeout);
            this.searchTimeout = setTimeout(() => {
                this.loadPages(query);
            }, 300);
        },

        /**
         * Render page list (hierarchical tree)
         */
        renderPageList: function(pages) {
            const $list = $('.wcp-ai-page-list');
            $list.empty();
            $list.append(this.renderPageTree(pages, 0));
        },

        /**
         * Render hierarchical page tree
         */
        renderPageTree: function(pages, level = 0) {
            let html = '<ul class="wcp-page-tree' + (level === 0 ? ' root' : '') + '">';

            pages.forEach(page => {
                const hasChildren = page.children && page.children.length > 0;
                const isSelected = this.isPageSelected(page.id);

                html += '<li class="wcp-page-tree-item" data-page-id="' + page.id + '">';

                if (hasChildren) {
                    html += '<span class="wcp-tree-toggle" data-expanded="false">▶</span>';
                } else {
                    html += '<span class="wcp-tree-spacer"></span>';
                }

                html += '<label class="wcp-page-checkbox">';
                html += '<input type="checkbox" value="' + page.id + '"' + (isSelected ? ' checked' : '') + '>';
                html += this.escapeHtml(page.title);
                html += '</label>';

                html += '<div class="wcp-page-options" style="display:' + (isSelected ? 'block' : 'none') + ';">';
                html += '<label><input type="checkbox" class="include-children"' + (this.getPageOption(page.id, 'include_children') ? ' checked' : '') + '> Include sub-pages</label>';
                html += '<label><input type="checkbox" class="include-items"' + (this.getPageOption(page.id, 'include_items') ? ' checked' : '') + '> Include items</label>';
                html += '</div>';

                if (hasChildren) {
                    html += this.renderPageTree(page.children, level + 1);
                }

                html += '</li>';
            });

            html += '</ul>';
            return html;
        },

        /**
         * Check if page is selected
         */
        isPageSelected: function(pageId) {
            return this.selectedPages.some(p => p.page_id === pageId);
        },

        /**
         * Get page option value
         */
        getPageOption: function(pageId, option) {
            const page = this.selectedPages.find(p => p.page_id === pageId);
            return page && page[option];
        },

        /**
         * Toggle page selection
         */
        togglePageSelection: function(pageId, selected) {
            const $item = $('.wcp-page-tree-item[data-page-id="' + pageId + '"]');
            const $options = $item.find('> .wcp-page-options');

            if (selected) {
                if (!this.isPageSelected(pageId)) {
                    this.selectedPages.push({
                        page_id: pageId,
                        include_children: false,
                        include_items: true
                    });
                }
                $options.slideDown();
            } else {
                this.selectedPages = this.selectedPages.filter(p => p.page_id !== pageId);
                $options.slideUp();
            }
            this.updateSelectedCount();
        },

        /**
         * Update page option
         */
        updatePageOption: function(pageId, option, value) {
            const page = this.selectedPages.find(p => p.page_id === pageId);
            if (page) {
                page[option] = value;
            }
        },

        /**
         * Update selected pages count
         */
        updateSelectedCount: function() {
            $('.wcp-ai-selected-count').text(this.selectedPages.length);
        },

        /**
         * Show save prompt modal
         */
        showSavePromptModal: function() {
            const prompt = $('#wcp-ai-prompt').val().trim();
            if (!prompt) {
                return;
            }
            $('#wcp-ai-save-label').val('');
            $('#wcp-ai-save-modal').fadeIn(200);
        },

        /**
         * Hide save prompt modal
         */
        hideSavePromptModal: function() {
            $('#wcp-ai-save-modal').fadeOut(200);
        },

        /**
         * Save prompt as chip
         */
        savePrompt: function() {
            const label = $('#wcp-ai-save-label').val().trim();
            const prompt = $('#wcp-ai-prompt').val().trim();

            if (!label || !prompt) {
                return;
            }

            $.ajax({
                url: wcpAiWidgetData.restUrl + '/prompts',
                method: 'POST',
                beforeSend: (xhr) => {
                    xhr.setRequestHeader('X-WP-Nonce', wcpAiWidgetData.nonce);
                },
                data: { label: label, prompt: prompt },
                success: (response) => {
                    if (response.success) {
                        this.hideSavePromptModal();
                        this.refreshPromptChips(response.prompts);
                    } else {
                        alert(response.message || 'Failed to save prompt');
                    }
                },
                error: () => {
                    alert('Failed to save prompt');
                }
            });
        },

        /**
         * Refresh prompt chips
         */
        refreshPromptChips: function(prompts) {
            const $container = $('.wcp-ai-prompt-chips');
            $container.empty();

            prompts.forEach((prompt) => {
                $container.append(`
                    <button type="button" class="wcp-ai-chip" data-prompt="${this.escapeHtml(prompt.prompt)}">
                        ${this.escapeHtml(prompt.label)}
                    </button>
                `);
            });
        },

        /**
         * Initialize conversation
         */
        initConversation: function() {
            $.ajax({
                url: wcpAiWidgetData.restUrl + '/ai/conversations/init',
                method: 'POST',
                beforeSend: (xhr) => {
                    xhr.setRequestHeader('X-WP-Nonce', wcpAiWidgetData.nonce);
                },
                data: {
                    page_id: wcpAiWidgetData.pageId
                },
                success: (response) => {
                    if (response.success) {
                        this.conversationId = response.conversation_id;
                        this.loadMessages(response.messages);
                    } else {
                        this.showError(response.message || 'Failed to initialize conversation');
                    }
                },
                error: (xhr) => {
                    this.showError('Connection error: ' + xhr.statusText);
                }
            });
        },

        /**
         * Load messages into conversation view
         */
        loadMessages: function(messages) {
            const $container = $('.wcp-ai-messages');
            $container.empty();

            if (messages.length === 0) {
                $container.append(
                    '<div class="wcp-ai-message wcp-ai-message-system">' +
                    '<div class="wcp-ai-message-content">' +
                    'Start a conversation by asking a question or selecting a prompt chip.' +
                    '</div></div>'
                );
                return;
            }

            messages.forEach((msg) => {
                this.appendMessage(msg.role, msg.content);
            });

            this.scrollToBottom();
        },

        /**
         * Append message to conversation
         */
        appendMessage: function(role, content) {
            const $container = $('.wcp-ai-messages');
            const className = 'wcp-ai-message-' + role;

            const $bubble = $('<div>').addClass('wcp-ai-message-content');
            if (role === 'assistant' && typeof marked !== 'undefined') {
                $bubble.html(marked.parse(content));
            } else {
                $bubble.text(content);
            }

            const $message = $('<div>')
                .addClass('wcp-ai-message')
                .addClass(className)
                .append($bubble);

            $container.append($message);
            this.scrollToBottom();
        },

        /**
         * Send message to AI
         */
        sendMessage: function() {
            const prompt = $('#wcp-ai-prompt').val().trim();

            if (!prompt) {
                return;
            }

            // Clear input and show loading
            $('#wcp-ai-prompt').val('');
            this.showLoading(true);
            $('.wcp-ai-send-btn').prop('disabled', true);

            // Append user message immediately
            this.appendMessage('user', prompt);

            // Build request data
            const data = {
                action_type: this.currentAction,
                prompt: prompt,
                page_id: wcpAiWidgetData.pageId,
                conversation_id: this.conversationId,
                context_mode: this.contextMode
            };

            if (this.contextMode === 'select') {
                data.selected_pages = this.selectedPages;
            }

            // Add item count for generate action
            if (this.currentAction === 'generate') {
                const itemCount = parseInt($('#wcp-ai-item-count').val()) || 0;
                if (itemCount > 0) {
                    data.item_count = itemCount;
                }
            }

            $.ajax({
                url: wcpAiWidgetData.restUrl + '/ai/actions/execute',
                method: 'POST',
                beforeSend: (xhr) => {
                    xhr.setRequestHeader('X-WP-Nonce', wcpAiWidgetData.nonce);
                },
                data: data,
                success: (response) => {
                    this.showLoading(false);
                    $('.wcp-ai-send-btn').prop('disabled', false);

                    if (response.success) {
                        this.handleActionResult(response.result);
                    } else {
                        this.showError(response.message || 'Action failed');
                    }
                },
                error: (xhr) => {
                    this.showLoading(false);
                    $('.wcp-ai-send-btn').prop('disabled', false);
                    this.showError('Connection error: ' + xhr.statusText);
                }
            });
        },

        /**
         * Handle action result
         */
        handleActionResult: function(result) {
            if (result.outcome === 'chat') {
                // Chat response - just append message
                this.appendMessage('assistant', result.message);

                // Auto-extract memories for chat actions
                if (this.currentAction === 'chat' && this.conversationId) {
                    this.extractMemories();
                }
            } else if (result.outcome === 'create_items') {
                // Generate items - show approval panel
                this.currentBatchId = result.batch_id || null;
                this.showProposals(result.proposals);
            } else if (result.outcome === 'create_memories') {
                // Memory proposals - show approval panel
                this.currentBatchId = result.batch_id || null;
                this.showMemoryProposals(result.proposals);
            }
        },

        /**
         * Show proposals for approval (supports multiple)
         */
        showProposals: function(proposals) {
            console.log('showProposals called with:', proposals);

            if (!proposals || proposals.length === 0) {
                console.log('No proposals to show');
                return;
            }

            this.currentProposals = proposals;
            const $container = $('.wcp-ai-proposals');
            console.log('Proposal container found:', $container.length > 0);
            $container.empty();

            proposals.forEach((proposal, index) => {
                const item = proposal.item;
                const $proposalCard = $('<div>')
                    .addClass('wcp-ai-proposal-card selected')
                    .attr('data-proposal-id', proposal.proposal_id)
                    .append(
                        $('<label>')
                            .addClass('wcp-proposal-checkbox')
                            .append(
                                $('<input>')
                                    .attr('type', 'checkbox')
                                    .prop('checked', true)
                                    .val(proposal.proposal_id)
                            ),
                        $('<h5>').text(item.title),
                        $('<div>')
                            .addClass('wcp-ai-proposal-content')
                            .text(item.content),
                        item.item_type ? $('<div>')
                            .addClass('wcp-ai-proposal-meta')
                            .html('<strong>Type:</strong> ' + item.item_type) : ''
                    );

                $container.append($proposalCard);
            });

            // Update selected count
            this.updateProposalSelectedCount();

            $('.wcp-ai-approval-panel').slideDown();

            // Append system message
            const itemWord = proposals.length === 1 ? 'item' : 'items';
            this.appendMessage('assistant', `I've generated ${proposals.length} ${itemWord} for your review. Select the ones you want to create.`);
        },

        /**
         * Update proposal checkbox selection
         */
        updateProposalSelection: function(checkbox) {
            const $card = $(checkbox).closest('.wcp-ai-proposal-card');
            if ($(checkbox).is(':checked')) {
                $card.addClass('selected');
            } else {
                $card.removeClass('selected');
            }
            this.updateProposalSelectedCount();
        },

        /**
         * Update the selected count in the accept button
         */
        updateProposalSelectedCount: function() {
            const count = $('.wcp-proposal-checkbox input:checked').length;
            $('.wcp-ai-approval-actions .wcp-ai-selected-count').text(count);
        },

        /**
         * Get selected proposal IDs
         */
        getSelectedProposalIds: function() {
            const ids = [];
            $('.wcp-proposal-checkbox input:checked').each(function() {
                ids.push($(this).val());
            });
            return ids;
        },

        /**
         * Accept selected proposals
         */
        acceptProposals: function() {
            const selectedIds = this.getSelectedProposalIds();
            console.log('Selected IDs:', selectedIds);
            console.log('Current batch ID:', this.currentBatchId);
            console.log('Current proposals:', this.currentProposals);

            if (selectedIds.length === 0) {
                alert('Please select at least one item to create.');
                return;
            }

            const data = {
                decision: 'accept',
                selected_proposal_ids: selectedIds
            };

            // Use batch_id if available, otherwise fall back to single proposal
            if (this.currentBatchId) {
                data.batch_id = this.currentBatchId;
            } else if (this.currentProposals.length > 0) {
                data.proposal_id = this.currentProposals[0].proposal_id;
            }

            console.log('Sending data:', data);

            $.ajax({
                url: wcpAiWidgetData.restUrl + '/ai/proposals/decide',
                method: 'POST',
                beforeSend: (xhr) => {
                    xhr.setRequestHeader('X-WP-Nonce', wcpAiWidgetData.nonce);
                },
                data: data,
                success: (response) => {
                    if (response.success) {
                        $('.wcp-ai-approval-panel').slideUp();
                        this.currentProposals = [];
                        this.currentBatchId = null;

                        const postCount = response.created_posts ? response.created_posts.length : 0;
                        const itemWord = postCount === 1 ? 'item' : 'items';
                        this.appendMessage('system', `${postCount} ${itemWord} created successfully!`);

                        // Log debug info if present
                        if (response.debug) {
                            console.log('Debug info:', response.debug);
                        }

                        // Optionally reload page to show new items
                        if (postCount > 0) {
                            setTimeout(() => {
                                if (confirm(`${postCount} ${itemWord} created! Would you like to reload the page to see them?`)) {
                                    window.location.reload();
                                }
                            }, 1000);
                        }
                    } else {
                        this.showError(response.message || 'Failed to create items');
                    }
                },
                error: (xhr) => {
                    this.showError('Connection error: ' + xhr.statusText);
                }
            });
        },

        /**
         * Dismiss all proposals
         */
        dismissProposals: function() {
            const data = {
                decision: 'dismiss'
            };

            // Use batch_id if available, otherwise fall back to single proposal
            if (this.currentBatchId) {
                data.batch_id = this.currentBatchId;
            } else if (this.currentProposals.length > 0) {
                data.proposal_id = this.currentProposals[0].proposal_id;
            }

            $.ajax({
                url: wcpAiWidgetData.restUrl + '/ai/proposals/decide',
                method: 'POST',
                beforeSend: (xhr) => {
                    xhr.setRequestHeader('X-WP-Nonce', wcpAiWidgetData.nonce);
                },
                data: data,
                success: (response) => {
                    if (response.success) {
                        $('.wcp-ai-approval-panel').slideUp();
                        this.currentProposals = [];
                        this.currentBatchId = null;
                        this.appendMessage('system', 'All proposals dismissed.');
                    } else {
                        this.showError(response.message || 'Failed to dismiss proposals');
                    }
                },
                error: (xhr) => {
                    this.showError('Connection error: ' + xhr.statusText);
                }
            });
        },

        /**
         * Widget controls
         */
        openWidget: function() {
            $('#wcp-ai-widget').removeClass('minimized closed');
            $('body').addClass('wcp-ai-panel-open');
        },

        minimizeWidget: function() {
            $('#wcp-ai-widget').addClass('minimized').removeClass('closed');
            $('body').removeClass('wcp-ai-panel-open');
        },

        closeWidget: function() {
            $('#wcp-ai-widget').addClass('closed').removeClass('minimized');
            $('body').removeClass('wcp-ai-panel-open');
        },

        /**
         * Show loading state
         */
        showLoading: function(show) {
            if (show) {
                $('.wcp-ai-loading').show();
            } else {
                $('.wcp-ai-loading').hide();
            }
        },

        /**
         * Show error message
         */
        showError: function(message) {
            this.appendMessage('system', 'Error: ' + message);
        },

        /**
         * Scroll conversation to bottom
         */
        scrollToBottom: function() {
            const $conversation = $('.wcp-ai-conversation');
            $conversation.scrollTop($conversation[0].scrollHeight);
        },

        /**
         * Escape HTML
         */
        escapeHtml: function(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        /**
         * Fetch active mission for current page
         */
        fetchActiveMission: function() {
            $.ajax({
                url: wcpAiWidgetData.restUrl + '/mission/active',
                method: 'GET',
                headers: { 'X-WP-Nonce': wcpAiWidgetData.nonce },
                data: {
                    page_id: wcpAiWidgetData.currentPageId
                },
                success: (response) => {
                    if (response.success) {
                        $('.wcp-mission-source').text(response.source);
                        $('.wcp-mission-text').text(response.mission_text || 'No mission defined');
                    }
                },
                error: () => {
                    $('.wcp-mission-source').text('Error loading');
                }
            });
        },

        /**
         * Extract memories from current conversation
         */
        extractMemories: function() {
            $.ajax({
                url: wcpAiWidgetData.restUrl + '/ai/memories/extract',
                method: 'POST',
                headers: { 'X-WP-Nonce': wcpAiWidgetData.nonce },
                data: {
                    conversation_id: this.conversationId
                },
                success: (response) => {
                    if (response.success && response.outcome === 'create_memories' && response.proposals && response.proposals.length > 0) {
                        this.currentBatchId = response.batch_id;
                        this.showMemoryProposals(response.proposals);
                    }
                    // Silently ignore if no memories found
                }
            });
        },

        /**
         * Show memory proposals for approval
         */
        showMemoryProposals: function(proposals) {
            this.currentProposals = proposals;

            const $container = $('.wcp-ai-proposals');
            $container.empty();

            proposals.forEach(proposal => {
                const memory = proposal.memory;
                const $card = $('<div>')
                    .addClass('wcp-ai-proposal-card wcp-memory-proposal selected')
                    .attr('data-proposal-id', proposal.proposal_id)
                    .append(
                        $('<label>').addClass('wcp-proposal-checkbox').append(
                            $('<input>').attr('type', 'checkbox').prop('checked', true).val(proposal.proposal_id)
                        ),
                        $('<h5>').text(memory.title),
                        $('<div>').addClass('wcp-ai-proposal-content').text(memory.content),
                        $('<div>').addClass('wcp-ai-proposal-meta')
                            .html('<strong>Type:</strong> ' + memory.type + ' | <strong>Confidence:</strong> ' + memory.confidence + '%')
                    );

                $container.append($card);
            });

            $('.wcp-ai-approval-panel').slideDown();
            this.appendMessage('assistant', 'I extracted ' + proposals.length + ' memory(s) from our conversation. Review and accept the ones you want to keep.');
        }
    };

    // Initialize on document ready
    $(document).ready(() => {
        AIWidget.init();
    });

})(jQuery);
