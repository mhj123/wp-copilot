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
        currentProposal: null,
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
            $(document).on('change', '.wcp-ai-page-checkbox', (e) => {
                this.togglePageSelection(
                    parseInt($(e.target).val()),
                    $(e.target).is(':checked')
                );
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
                this.acceptProposal();
            });

            $(document).on('click', '.wcp-ai-dismiss-btn', () => {
                this.dismissProposal();
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

            // Update placeholder
            if (action === 'chat') {
                $('#wcp-ai-prompt').attr('placeholder', 'Ask a question about your work...');
            } else {
                $('#wcp-ai-prompt').attr('placeholder', 'Describe the item you want to create...');
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
         * Render page list
         */
        renderPageList: function(pages) {
            const $list = $('.wcp-ai-page-list');
            $list.empty();

            pages.forEach((page) => {
                const isChecked = this.selectedPages.includes(page.id);
                const breadcrumb = page.breadcrumb.length > 0
                    ? '<span class="breadcrumb">' + page.breadcrumb.join(' > ') + ' > </span>'
                    : '';

                $list.append(`
                    <label class="wcp-ai-page-item">
                        <input type="checkbox" class="wcp-ai-page-checkbox" value="${page.id}" ${isChecked ? 'checked' : ''}>
                        ${breadcrumb}${page.title}
                    </label>
                `);
            });
        },

        /**
         * Toggle page selection
         */
        togglePageSelection: function(pageId, selected) {
            if (selected && !this.selectedPages.includes(pageId)) {
                this.selectedPages.push(pageId);
            } else if (!selected) {
                this.selectedPages = this.selectedPages.filter(id => id !== pageId);
            }
            this.updateSelectedCount();
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

            const $message = $('<div>')
                .addClass('wcp-ai-message')
                .addClass(className)
                .append(
                    $('<div>')
                        .addClass('wcp-ai-message-content')
                        .text(content)
                );

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
            } else if (result.outcome === 'create_items') {
                // Generate items - show approval panel
                this.showProposals(result.proposals);
            }
        },

        /**
         * Show proposals for approval
         */
        showProposals: function(proposals) {
            if (!proposals || proposals.length === 0) {
                return;
            }

            this.currentProposal = proposals[0]; // For MVP, only handle single proposal

            const $container = $('.wcp-ai-proposals');
            $container.empty();

            const proposal = this.currentProposal;
            const item = proposal.item;

            const $proposalCard = $('<div>')
                .addClass('wcp-ai-proposal-card')
                .append(
                    $('<h5>').text(item.title),
                    $('<div>')
                        .addClass('wcp-ai-proposal-content')
                        .text(item.content),
                    item.item_type ? $('<div>')
                        .addClass('wcp-ai-proposal-meta')
                        .html('<strong>Type:</strong> ' + item.item_type) : ''
                );

            $container.append($proposalCard);
            $('.wcp-ai-approval-panel').slideDown();

            // Append system message
            this.appendMessage('assistant', 'I\'ve generated an item for your review. Please accept or dismiss it below.');
        },

        /**
         * Accept proposal
         */
        acceptProposal: function() {
            if (!this.currentProposal) {
                return;
            }

            const proposalId = this.currentProposal.proposal_id;

            $.ajax({
                url: wcpAiWidgetData.restUrl + '/ai/proposals/decide',
                method: 'POST',
                beforeSend: (xhr) => {
                    xhr.setRequestHeader('X-WP-Nonce', wcpAiWidgetData.nonce);
                },
                data: {
                    proposal_id: proposalId,
                    decision: 'accept'
                },
                success: (response) => {
                    if (response.success) {
                        $('.wcp-ai-approval-panel').slideUp();
                        this.currentProposal = null;

                        const postCount = response.created_posts ? response.created_posts.length : 0;
                        this.appendMessage('system', 'Item created successfully! (' + postCount + ' post(s) created)');

                        // Optionally reload page to show new item
                        setTimeout(() => {
                            if (confirm('Item created! Would you like to reload the page to see it?')) {
                                window.location.reload();
                            }
                        }, 1000);
                    } else {
                        this.showError(response.message || 'Failed to create item');
                    }
                },
                error: (xhr) => {
                    this.showError('Connection error: ' + xhr.statusText);
                }
            });
        },

        /**
         * Dismiss proposal
         */
        dismissProposal: function() {
            if (!this.currentProposal) {
                return;
            }

            const proposalId = this.currentProposal.proposal_id;

            $.ajax({
                url: wcpAiWidgetData.restUrl + '/ai/proposals/decide',
                method: 'POST',
                beforeSend: (xhr) => {
                    xhr.setRequestHeader('X-WP-Nonce', wcpAiWidgetData.nonce);
                },
                data: {
                    proposal_id: proposalId,
                    decision: 'dismiss'
                },
                success: (response) => {
                    if (response.success) {
                        $('.wcp-ai-approval-panel').slideUp();
                        this.currentProposal = null;
                        this.appendMessage('system', 'Proposal dismissed.');
                    } else {
                        this.showError(response.message || 'Failed to dismiss proposal');
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
        },

        minimizeWidget: function() {
            $('#wcp-ai-widget').addClass('minimized').removeClass('closed');
        },

        closeWidget: function() {
            $('#wcp-ai-widget').addClass('closed').removeClass('minimized');
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
        }
    };

    // Initialize on document ready
    $(document).ready(() => {
        AIWidget.init();
    });

})(jQuery);
