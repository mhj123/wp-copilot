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
        currentProposals: [],
        currentBatchId: null,
        currentContentProposal: null,
        currentAction: 'chat',
        contextMode: 'page',
        selectedPages: [],
        pagesCache: null,
        selectedModel: 'claude-sonnet-4-6',
        thinkingBudget: 0,
        contextsFlat: null,

        /**
         * Initialize widget
         */
        init: function() {
            // Embedded (site-wide, e.g. the homepage "Chat" tab) instances have
            // no single page, so default to corpus context rather than 'page'.
            // The <select> already renders with 'corpus' pre-selected server-side
            // (ai-widget.php); this keeps the JS-side state in sync with it.
            if (wcpAiWidgetData.embedded) {
                this.contextMode = 'corpus';
            }
            this.bindEvents();
            this.initConversation();
            this.fetchActiveMission();
            this.fetchContexts();
        },

        /**
         * Fetch and flatten the context tree once, for the save-as-item picker.
         * Produces this.contextsFlat = [{term_id, name, path, ref_type, ref_id}].
         */
        fetchContexts: function() {
            $.ajax({
                url: wcpAiWidgetData.restUrl + '/contexts/tree',
                method: 'GET',
                headers: { 'X-WP-Nonce': wcpAiWidgetData.nonce },
                success: (response) => {
                    if (response && response.success) {
                        const flat = [];
                        const walk = (nodes, prefix) => {
                            (nodes || []).forEach((n) => {
                                const path = prefix ? prefix + ' › ' + n.name : n.name;
                                flat.push({
                                    term_id: n.term_id,
                                    name: n.name,
                                    path: path,
                                    ref_type: n.ref_type,
                                    ref_id: parseInt(n.ref_id, 10) || 0
                                });
                                walk(n.children, path);
                            });
                        };
                        walk(response.tree, '');
                        this.contextsFlat = flat;
                    }
                }
            });
        },

        /**
         * Term id of the current page's context, if resolvable from the tree.
         */
        currentPageContext: function() {
            if (!this.contextsFlat) { return null; }
            return this.contextsFlat.find(
                (c) => c.ref_type === 'page' && c.ref_id === wcpAiWidgetData.pageId
            ) || null;
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

            $(document).on('click', '.wcp-ai-expand', () => {
                this.toggleExpandWidget();
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

            // Model selector
            $(document).on('change', '#wcp-ai-model', (e) => {
                this.selectedModel = $(e.target).val();
                const isOpus = this.selectedModel === 'claude-opus-4-8';
                const $thinking = $('#wcp-ai-thinking');
                if (isOpus) {
                    $thinking.removeAttr('disabled');
                } else {
                    $thinking.val('0').attr('disabled', 'disabled');
                    this.thinkingBudget = 0;
                }
            });

            // Thinking selector
            $(document).on('change', '#wcp-ai-thinking', (e) => {
                this.thinkingBudget = parseInt($(e.target).val(), 10) || 0;
            });

            // Save assistant message as item
            $(document).on('click', '.wcp-ai-msgsave-open', (e) => {
                e.preventDefault();
                this.openSaveForm($(e.currentTarget).closest('.wcp-ai-message'));
            });

            $(document).on('click', '.wcp-ai-msgsave-cancel', (e) => {
                e.preventDefault();
                const $msg = $(e.currentTarget).closest('.wcp-ai-message');
                $msg.find('.wcp-ai-msgsave-form').remove();
                $msg.find('.wcp-ai-msgsave-open').show();
            });

            $(document).on('click', '.wcp-ai-msgsave-confirm', (e) => {
                e.preventDefault();
                this.submitSaveForm($(e.currentTarget).closest('.wcp-ai-message'));
            });

            // Save form: mode switch
            $(document).on('click', '.wcp-ai-msgsave-mode', (e) => {
                e.preventDefault();
                const $btn = $(e.currentTarget);
                const $form = $btn.closest('.wcp-ai-msgsave-form');
                $form.find('.wcp-ai-msgsave-mode').removeClass('active');
                $btn.addClass('active');
                const mode = $btn.data('mode');
                // Title/content only apply to single-item modes
                $form.find('.wcp-ai-msgsave-titlefield').toggle(mode !== 'multiple');
                $form.find('.wcp-ai-msgsave-multinote').toggle(mode === 'multiple');
            });

            // Save form: type change toggles conditional fields
            $(document).on('change', '.wcp-ai-msgsave-type', (e) => {
                const $form = $(e.currentTarget).closest('.wcp-ai-msgsave-form');
                this.updateSaveFormConditional($form);
            });

            // Save form: add a context chip from the dropdown
            $(document).on('change', '.wcp-ai-msgsave-ctx-add', (e) => {
                const $sel = $(e.currentTarget);
                const termId = parseInt($sel.val(), 10);
                if (!termId) { return; }
                const name = $sel.find('option:selected').text().trim();
                this.addChip($sel.closest('.wcp-ai-msgsave-ctx').find('.wcp-ai-msgsave-chips'), termId, name, 'ctx');
                $sel.val('');
            });

            // Save form: add a tag chip
            $(document).on('keydown', '.wcp-ai-msgsave-tag-input', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const $input = $(e.currentTarget);
                    const val = $input.val().trim();
                    if (val) {
                        this.addChip($input.closest('.wcp-ai-msgsave-tags').find('.wcp-ai-msgsave-chips'), val, val, 'tag');
                        $input.val('');
                    }
                }
            });

            // Save form: remove a chip
            $(document).on('click', '.wcp-ai-msgsave-chip-remove', (e) => {
                e.preventDefault();
                $(e.currentTarget).closest('.wcp-ai-msgsave-chip').remove();
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

            // Action chips — toggle exclusive selection
            $(document).on('click', '.wcp-ai-action-chip', (e) => {
                const $chip = $(e.currentTarget);
                const action = $chip.data('action');
                if (action === 'create_goal') {
                    // Delegate to the existing goal modal on the page
                    this.minimizeWidget();
                    $('#wcp-btn-new-goal').trigger('click');
                    return;
                }
                if (action === 'onboard') {
                    // Fires immediately — no user prompt needed
                    this.runOnboard();
                    return;
                }
                if (action === 'chat_qa') {
                    // "Ask anything" — reset to plain chat and let the user type.
                    $('.wcp-ai-action-chip').removeClass('active');
                    this.currentAction = 'chat_qa';
                    $('#wcp-ai-prompt').focus();
                    return;
                }
                if (action === 'import_document') {
                    // Needs a file, not a typed prompt — open the file picker
                    // instead of focusing the textarea.
                    $('.wcp-ai-action-chip').removeClass('active');
                    $('#wcp-ai-document-upload').trigger('click');
                    return;
                }
                if ($chip.hasClass('wcp-ai-action-chip--canned')) {
                    // Site-level canned-prompt chips (taxonomy outline, mission
                    // priorities, weekly summary) fire immediately with a fixed
                    // prompt — no textarea step needed.
                    this.runCannedAction(action, $chip.data('prompt'));
                    return;
                }
                if ($chip.hasClass('active')) {
                    $chip.removeClass('active');
                    this.currentAction = 'chat';
                } else {
                    $('.wcp-ai-action-chip').removeClass('active');
                    $chip.addClass('active');
                    this.currentAction = action;
                }
            });

            // Save-as-mission offer — rendered dynamically after onboard
            $(document).on('click', '.wcp-ai-save-mission-btn', () => {
                this.saveSuggestedMission();
            });

            // Document import — Markdown is split into structure; PDF is summarized into
            // a normal ItemPost proposal after server-side text extraction.
            $(document).on('change', '#wcp-ai-document-upload', (e) => {
                this.importDocument(e.target.files[0]);
                $(e.target).val(''); // allow re-selecting the same file next time
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

            // Content proposal actions
            $(document).on('click', '.wcp-ai-content-accept-btn', () => {
                this.acceptContentProposal();
            });

            $(document).on('click', '.wcp-ai-content-dismiss-btn', () => {
                this.dismissContentProposal();
            });

            // Proposal checkbox change
            $(document).on('change', '.wcp-proposal-checkbox input', (e) => {
                this.updateProposalSelection(e.target);
            });

            // Structure proposal: new-heading checkbox cascades to its child items.
            $(document).on('change', '.wcp-struct-heading-cb', (e) => {
                const $cb = $(e.target);
                const on = $cb.is(':checked');
                $cb.closest('.wcp-struct-group').find('.wcp-struct-item-cb')
                    .prop('disabled', !on).prop('checked', on);
            });
            $(document).on('click', '.wcp-struct-create', () => this.acceptStructure());
            $(document).on('click', '.wcp-struct-dismiss', () => this.dismissStructure());

            // Mission toggle
            $(document).on('click', '.wcp-mission-toggle', () => {
                $('.wcp-mission-content').slideToggle();
                $('.wcp-mission-toggle .dashicons').toggleClass('dashicons-arrow-down dashicons-arrow-up');
            });
        },

        /**
         * Set current action — kept for any legacy callers, now always 'auto'
         */
        setAction: function(action) {
            this.currentAction = 'auto';
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
                this.appendMessage(msg.role, msg.content, msg.metadata);
            });

            this.scrollToBottom();
        },

        /**
         * Append message to conversation.
         * metadata.source === 'hermes' marks a delegation-review reply, which
         * is stored with role 'assistant' (model-valid) but labelled "Hermes".
         */
        appendMessage: function(role, content, metadata) {
            const $container = $('.wcp-ai-messages');
            const isHermes = metadata && metadata.source === 'hermes';
            const className = isHermes ? 'wcp-ai-message-hermes' : 'wcp-ai-message-' + role;

            const $bubble = $('<div>').addClass('wcp-ai-message-content');
            if ((role === 'assistant' || isHermes) && typeof marked !== 'undefined') {
                $bubble.html(marked.parse(content));
            } else {
                $bubble.text(content);
            }

            const $message = $('<div>')
                .addClass('wcp-ai-message')
                .addClass(className)
                .append($bubble);

            if (isHermes) {
                $message.prepend($('<div>').addClass('wcp-ai-message-label').text('Hermes'));
            }

            // Assistant (and Hermes) messages can be saved into the corpus
            if (role === 'assistant' || isHermes) {
                $message.data('raw', content);
                $message.append(
                    $('<div>').addClass('wcp-ai-msgsave').append(
                        $('<a>')
                            .attr('href', '#')
                            .addClass('wcp-ai-msgsave-open')
                            .text('Save as item')
                    )
                );
            }

            $container.append($message);
            this.scrollToBottom();
        },

        /**
         * Suggest an item title from message text: first sentence, cleaned of
         * markdown punctuation, capped at 80 chars.
         */
        suggestTitle: function(text) {
            let t = String(text).replace(/[#*_`>\[\]|]/g, ' ').replace(/\s+/g, ' ').trim();
            const dot = t.indexOf('. ');
            if (dot > 20) {
                t = t.slice(0, dot);
            }
            if (t.length > 80) {
                t = t.slice(0, 77) + '…';
            }
            return t;
        },

        /**
         * Append a labelled chip (context or tag) to a chip container.
         * kind 'ctx' stores a numeric term id; kind 'tag' stores the name.
         */
        addChip: function($container, value, label, kind) {
            // Avoid duplicates
            let dup = false;
            $container.find('.wcp-ai-msgsave-chip').each(function() {
                if (String($(this).data('value')) === String(value)) { dup = true; }
            });
            if (dup) { return; }
            $container.append(
                $('<span>').addClass('wcp-ai-msgsave-chip').addClass('wcp-ai-msgsave-chip-' + kind)
                    .attr('data-value', value)
                    .attr('data-kind', kind)
                    .text(label + ' ')
                    .append($('<a>').attr('href', '#').addClass('wcp-ai-msgsave-chip-remove').html('&times;'))
            );
        },

        /**
         * Show/hide type-conditional fields (task status/due, spec status).
         */
        updateSaveFormConditional: function($form) {
            const type = $form.find('.wcp-ai-msgsave-type').val();
            $form.find('.wcp-ai-msgsave-taskfields').toggle(type === 'task');
            $form.find('.wcp-ai-msgsave-specfields').toggle(type === 'spec');
        },

        /**
         * Open the inline save form on a message
         */
        openSaveForm: function($msg) {
            if ($msg.find('.wcp-ai-msgsave-form').length) {
                return;
            }
            $msg.find('.wcp-ai-msgsave-open').hide();

            const raw = $msg.data('raw') || $msg.find('.wcp-ai-message-content').text();

            const opt = (val, label, sel) =>
                $('<option>').val(val).text(label).prop('selected', !!sel);

            // Mode selector
            const $modes = $('<div>').addClass('wcp-ai-msgsave-modes')
                .append($('<button>').attr('type', 'button').addClass('wcp-ai-msgsave-mode active').attr('data-mode', 'verbatim').text('Verbatim'))
                .append($('<button>').attr('type', 'button').addClass('wcp-ai-msgsave-mode').attr('data-mode', 'summary').text('AI summary'))
                .append($('<button>').attr('type', 'button').addClass('wcp-ai-msgsave-mode').attr('data-mode', 'multiple').text('Multiple items'));

            // Title (full width, labelled)
            const $titleField = $('<div>').addClass('wcp-ai-msgsave-titlefield')
                .append($('<label>').addClass('wcp-ai-msgsave-label').text('Item title'))
                .append($('<input>').attr('type', 'text').addClass('wcp-ai-msgsave-title')
                    .attr('placeholder', 'Item title').val(this.suggestTitle(raw)));
            const $multiNote = $('<div>').addClass('wcp-ai-msgsave-multinote')
                .text('Titles are generated per item.').hide();

            // Type (usual order); no default selection
            const $type = $('<select>').addClass('wcp-ai-msgsave-type')
                .append(opt('', 'type', true))
                .append(opt('task', 'task'))
                .append(opt('info', 'info'))
                .append(opt('learning', 'learning'))
                .append(opt('spec', 'spec'))
                .append(opt('memory', 'memory'));

            // Task-only: status + due date
            const $taskFields = $('<span>').addClass('wcp-ai-msgsave-taskfields').hide()
                .append($('<select>').addClass('wcp-ai-msgsave-status')
                    .append(opt('to-do', 'to do', true))
                    .append(opt('in-progress', 'in progress'))
                    .append(opt('done', 'done')))
                .append($('<input>').attr('type', 'date').addClass('wcp-ai-msgsave-due').attr('title', 'Due date'));

            // Spec-only: status
            const $specFields = $('<span>').addClass('wcp-ai-msgsave-specfields').hide()
                .append($('<select>').addClass('wcp-ai-msgsave-specstatus')
                    .append(opt('draft', 'draft', true))
                    .append(opt('review', 'review'))
                    .append(opt('final', 'final')));

            const $pinned = $('<label>').addClass('wcp-ai-msgsave-pin')
                .append($('<input>').attr('type', 'checkbox').addClass('wcp-ai-msgsave-pinned'))
                .append(document.createTextNode(' pin'));

            const $grid = $('<div>').addClass('wcp-ai-msgsave-grid')
                .append($type).append($taskFields).append($specFields).append($pinned);

            // Contexts
            const $ctxChips = $('<span>').addClass('wcp-ai-msgsave-chips');
            const $ctxAdd = $('<select>').addClass('wcp-ai-msgsave-ctx-add')
                .append(opt('', '+ context'));
            (this.contextsFlat || []).forEach((c) => {
                $ctxAdd.append($('<option>').val(c.term_id).text(c.path));
            });
            const $ctx = $('<div>').addClass('wcp-ai-msgsave-ctx')
                .append($('<label>').addClass('wcp-ai-msgsave-label').text('Contexts'))
                .append($ctxChips)
                .append($ctxAdd);

            // Tags
            const $tagChips = $('<span>').addClass('wcp-ai-msgsave-chips');
            const $tags = $('<div>').addClass('wcp-ai-msgsave-tags')
                .append($('<label>').addClass('wcp-ai-msgsave-label').text('Tags'))
                .append($tagChips)
                .append($('<input>').attr('type', 'text').addClass('wcp-ai-msgsave-tag-input')
                    .attr('placeholder', 'add tag + Enter').attr('autocomplete', 'off'));

            const $actions = $('<div>').addClass('wcp-ai-msgsave-actions')
                .append($('<button>').attr('type', 'button').addClass('wcp-ai-msgsave-confirm').text('Save'))
                .append($('<button>').attr('type', 'button').addClass('wcp-ai-msgsave-cancel').text('Cancel'));

            const $form = $('<div>').addClass('wcp-ai-msgsave-form')
                .append($modes)
                .append($titleField)
                .append($multiNote)
                .append($grid)
                .append($ctx)
                .append($tags)
                .append($actions);

            $msg.find('.wcp-ai-msgsave').append($form);

            // Pre-select the current page as a context chip
            const pageCtx = this.currentPageContext();
            if (pageCtx) {
                this.addChip($ctxChips, pageCtx.term_id, pageCtx.name, 'ctx');
            }

            $form.find('.wcp-ai-msgsave-title').trigger('focus');
        },

        /**
         * Submit the inline save form.
         * Note: this is a user-initiated save of visible AI output — the click
         * is the acceptance, so no proposal round-trip (still logged server-side).
         */
        submitSaveForm: function($msg) {
            const $form = $msg.find('.wcp-ai-msgsave-form');
            const mode = $form.find('.wcp-ai-msgsave-mode.active').data('mode') || 'verbatim';
            const title = $form.find('.wcp-ai-msgsave-title').val().trim();
            const itemType = $form.find('.wcp-ai-msgsave-type').val();
            const raw = $msg.data('raw') || $msg.find('.wcp-ai-message-content').text();

            if (mode === 'verbatim' && !title) {
                $form.find('.wcp-ai-msgsave-title').trigger('focus');
                return;
            }

            const contextIds = $form.find('.wcp-ai-msgsave-ctx .wcp-ai-msgsave-chip').map(function() {
                return parseInt($(this).data('value'), 10);
            }).get();
            const tags = $form.find('.wcp-ai-msgsave-tags .wcp-ai-msgsave-chip').map(function() {
                return $(this).data('value');
            }).get();

            const data = {
                mode: mode,
                title: title,
                content: raw,
                item_type: itemType,
                task_status: $form.find('.wcp-ai-msgsave-status').val(),
                spec_status: $form.find('.wcp-ai-msgsave-specstatus').val(),
                due_date: $form.find('.wcp-ai-msgsave-due').val(),
                pinned: $form.find('.wcp-ai-msgsave-pinned').is(':checked') ? '1' : '',
                model: this.selectedModel,
                page_id: wcpAiWidgetData.pageId,
                conversation_id: this.conversationId,
                context_ids: contextIds,
                tags: tags
            };

            $form.find('button').prop('disabled', true);
            if (mode !== 'verbatim') {
                $form.find('.wcp-ai-msgsave-confirm').text('Working…');
            }

            $.ajax({
                url: wcpAiWidgetData.restUrl + '/ai/messages/save-as-item',
                method: 'POST',
                beforeSend: (xhr) => {
                    xhr.setRequestHeader('X-WP-Nonce', wcpAiWidgetData.nonce);
                },
                data: data,
                success: (response) => {
                    if (response.success) {
                        const count = response.count || 1;
                        const $done = $('<span>').addClass('wcp-ai-msgsave-done')
                            .text(count > 1
                                ? ('Saved ' + count + ' items ')
                                : (response.item_type ? ('Saved as ' + response.item_type + ' ') : 'Saved '));
                        if (count === 1 && response.view_url) {
                            $done.append($('<a>').attr('href', response.view_url).attr('target', '_blank').text('view'));
                        }
                        $msg.find('.wcp-ai-msgsave').empty().append($done);
                    } else {
                        $form.find('button').prop('disabled', false);
                        $form.find('.wcp-ai-msgsave-confirm').text('Save');
                        this.showError(response.message || 'Failed to save item');
                    }
                },
                error: (xhr) => {
                    $form.find('button').prop('disabled', false);
                    $form.find('.wcp-ai-msgsave-confirm').text('Save');
                    const msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : xhr.statusText;
                    this.showError('Save failed: ' + msg);
                }
            });
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
            this.showLoading(true, this.buildLoadingMessage(this.currentAction));
            $('.wcp-ai-send-btn').prop('disabled', true);

            // Append user message immediately
            this.appendMessage('user', prompt);

            // Agent review — hand the selected context to Hermes instead of the
            // in-app AI. Hermes replies asynchronously; its feedback lands in
            // this conversation (labelled "Hermes") next time the widget opens.
            if (this.currentAction === 'agent_review') {
                this.sendAgentReview(prompt);
                return;
            }

            // Build request data
            const data = {
                action_type: this.currentAction,
                prompt: prompt,
                page_id: wcpAiWidgetData.pageId,
                conversation_id: this.conversationId,
                context_mode: this.contextMode,
                model: this.selectedModel,
                thinking_budget: this.thinkingBudget
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
                    // Reset action chip selection after each send
                    $('.wcp-ai-action-chip').removeClass('active');
                    this.currentAction = 'chat';

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
         * Send the selected context to Hermes for review.
         * Reuses the same context selection (mode + selected pages) gathered
         * for the in-app AI. Feedback is delivered later into this conversation.
         */
        sendAgentReview: function(instruction) {
            if (!wcpAiWidgetData.delegationEnabled) {
                this.showLoading(false);
                $('.wcp-ai-send-btn').prop('disabled', false);
                this.showError('Agent review is not enabled.');
                return;
            }

            const data = {
                conversation_id: this.conversationId,
                page_id: wcpAiWidgetData.pageId,
                context_mode: this.contextMode,
                instruction: instruction
            };

            if (this.contextMode === 'select') {
                data.selected_pages = this.selectedPages;
            }

            $.ajax({
                url: wcpAiWidgetData.delegationRestUrl + '/reviews',
                method: 'POST',
                beforeSend: (xhr) => {
                    xhr.setRequestHeader('X-WP-Nonce', wcpAiWidgetData.nonce);
                },
                data: data,
                success: (response) => {
                    this.showLoading(false);
                    $('.wcp-ai-send-btn').prop('disabled', false);
                    $('.wcp-ai-action-chip').removeClass('active');
                    this.currentAction = 'chat';

                    if (response.success) {
                        this.appendMessage('system', 'Sent to Hermes for review — feedback will appear here when ready.');
                    } else {
                        this.showError(response.message || 'Failed to send review to Hermes');
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
         * Import a document — markdown is read client-side and split into
         * headings/items; PDFs are uploaded to the REST API and sent to Claude
         * as native document blocks for a reviewed summary proposal. Both paths
         * reuse handleActionResult() for the returned proposal payload.
         */
        importDocument: function(file) {
            if (!file) { return; }

            const isPdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name);
            this.appendMessage('user', (isPdf ? 'Uploaded PDF: ' : 'Imported document: ') + file.name);

            if (isPdf) {
                if (!wcpAiWidgetData.pdfSummaryEnabled) {
                    this.showError('PDF summary import is disabled on this install.');
                    return;
                }
                this.showLoading(true, 'Uploading PDF and asking Claude to draft a summary item...');
                const data = new FormData();
                data.append('pdf', file);
                data.append('page_id', wcpAiWidgetData.pageId);
                data.append('conversation_id', this.conversationId || '');
                data.append('model', this.selectedModel);
                data.append('thinking_budget', this.thinkingBudget);

                $.ajax({
                    url: wcpAiWidgetData.restUrl + '/ai/documents/summarize-pdf',
                    method: 'POST',
                    beforeSend: (xhr) => { xhr.setRequestHeader('X-WP-Nonce', wcpAiWidgetData.nonce); },
                    data: data,
                    processData: false,
                    contentType: false,
                    success: (response) => {
                        this.showLoading(false);
                        this.currentAction = 'chat';
                        if (response.success) {
                            this.handleActionResult(response);
                        } else {
                            this.showError(response.message || 'Could not summarise PDF');
                        }
                    },
                    error: (xhr) => {
                        this.showLoading(false);
                        const msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : xhr.statusText;
                        this.showError('PDF import failed: ' + msg);
                    }
                });
                return;
            }

            this.showLoading(true, 'Reading your document and splitting it into headings and items...');

            const reader = new FileReader();
            reader.onload = (e) => {
                $.ajax({
                    url: wcpAiWidgetData.restUrl + '/ai/documents/split-markdown',
                    method: 'POST',
                    beforeSend: (xhr) => {
                        xhr.setRequestHeader('X-WP-Nonce', wcpAiWidgetData.nonce);
                    },
                    data: {
                        markdown_content: e.target.result,
                        page_id: wcpAiWidgetData.pageId,
                        conversation_id: this.conversationId
                    },
                    success: (response) => {
                        this.showLoading(false);
                        this.currentAction = 'chat';
                        if (response.success) {
                            this.handleActionResult(response);
                        } else {
                            this.showError(response.message || 'Could not import document');
                        }
                    },
                    error: (xhr) => {
                        this.showLoading(false);
                        this.showError('Connection error: ' + xhr.statusText);
                    }
                });
            };
            reader.onerror = () => {
                this.showLoading(false);
                this.showError('Could not read the file — please try again.');
            };
            reader.readAsText(file);
        },

        /**
         * Handle action result
         */
        handleActionResult: function(result) {
            if (result.outcome === 'onboard') {
                let message = result.message || '';
                // Safety net: PHP JSON parsing can fail if the AI embeds actual newlines
                // in string values. Try JSON.parse first; fall back to regex extraction.
                if (message.trim().startsWith('{') || message.trim().startsWith('```')) {
                    const clean = message.replace(/```json\s*/gi, '').replace(/```\s*/g, '').trim();
                    try {
                        const p = JSON.parse(clean);
                        if (p && p.greeting) {
                            message = p.greeting;
                            if (!result.suggested_mission && p.suggested_mission) {
                                result.suggested_mission = p.suggested_mission;
                            }
                        }
                    } catch (e) {
                        // JSON invalid (likely unescaped newlines) — extract with regex
                        const m = clean.match(/"greeting"\s*:\s*"((?:[^"\\]|\\[\s\S])*)"/);
                        if (m) {
                            message = m[1]
                                .replace(/\\n/g, '\n')
                                .replace(/\\t/g, '\t')
                                .replace(/\\"/g, '"')
                                .replace(/\\\\/g, '\\');
                        }
                        const ms = clean.match(/"suggested_mission"\s*:\s*"((?:[^"\\]|\\[\s\S])*)"/);
                        if (ms && !result.suggested_mission) {
                            result.suggested_mission = ms[1]
                                .replace(/\\n/g, '\n')
                                .replace(/\\"/g, '"');
                        }
                    }
                }
                this.appendMessage('assistant', message);
                if (result.suggested_mission) {
                    this.showMissionOffer(result.suggested_mission);
                }
                return;
            }
            if (result.outcome === 'chat') {
                this.appendMessage('assistant', result.message);
                // Automatic memory extraction disabled — it fired unprompted after
                // every reply, which read as forced/clunky. extractMemories() is
                // still here, just no longer auto-called; wire it to an explicit
                // user action (e.g. a "remember this" button) if this comes back.
            } else if (result.outcome === 'create_items') {
                this.currentBatchId = result.batch_id || null;
                this.showProposals(result.proposals);
            } else if (result.outcome === 'edit_items') {
                this.currentBatchId = result.batch_id || null;
                this.showEditProposals(result.proposals);
            } else if (result.outcome === 'create_structure') {
                this.currentBatchId = result.batch_id || null;
                this.showStructureProposal(result.plan || {});
            } else if (result.outcome === 'create_memories') {
                this.currentBatchId = result.batch_id || null;
                this.showMemoryProposals(result.proposals);
            } else if (result.outcome === 'content_proposal') {
                this.showContentProposal(result);
            }
        },

        showContentProposal: function(result) {
            this.currentContentProposal = result;
            const label = result.mode === 'rewrite' ? 'Proposed page rewrite' : 'Content to append';
            $('.wcp-ai-content-proposal-title').text(label);
            $('.wcp-ai-content-proposal-preview').html(
                $('<div class="wcp-content-proposal-text">').text(result.content)
            );
            $('.wcp-ai-content-proposal-panel').show();
        },

        acceptContentProposal: function() {
            if (!this.currentContentProposal) return;
            const proposal = this.currentContentProposal;
            const $btn = $('.wcp-ai-content-accept-btn');
            $btn.prop('disabled', true).text('Saving…');

            $.ajax({
                url: wcpAiWidgetData.restUrl + '/pages/' + wcpAiWidgetData.pageId + '/content/accept',
                method: 'POST',
                beforeSend: (xhr) => { xhr.setRequestHeader('X-WP-Nonce', wcpAiWidgetData.nonce); },
                data: { proposal_id: proposal.proposal_id },
                success: () => {
                    this.dismissContentProposal();
                    this.appendMessage('assistant', 'Page content updated. Reloading…');
                    setTimeout(() => window.location.reload(), 1200);
                },
                error: () => {
                    $btn.prop('disabled', false).text('Accept');
                    this.showError('Could not save — please try again.');
                }
            });
        },

        dismissContentProposal: function() {
            this.currentContentProposal = null;
            $('.wcp-ai-content-proposal-panel').hide();
            $('.wcp-ai-content-proposal-preview').empty();
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
            this.proposalMode = 'create';
            const $container = $('.wcp-ai-proposals');
            console.log('Proposal container found:', $container.length > 0);
            $container.empty();

            $('.wcp-ai-approval-title').text('Review AI Suggestions');
            $('.wcp-ai-approval-description').text('Select the items you want to create, then click Create Selected.');
            $('.wcp-ai-accept-label').text('Create Selected');

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
         * Show proposed title/description edits to existing items for
         * approval (supports multiple). Mirrors showProposals() but renders
         * a before/after diff and reuses the same approval panel, checkbox
         * markup, and accept/dismiss wiring — only the labels differ.
         */
        showEditProposals: function(proposals) {
            if (!proposals || proposals.length === 0) {
                return;
            }

            this.currentProposals = proposals;
            this.proposalMode = 'edit';
            const $container = $('.wcp-ai-proposals');
            $container.empty();

            $('.wcp-ai-approval-title').text('Review Proposed Edits');
            $('.wcp-ai-approval-description').text('Select the edits you want to apply, then click Apply Selected.');
            $('.wcp-ai-accept-label').text('Apply Selected');

            proposals.forEach((proposal) => {
                const orig = proposal.original || {};
                const next = proposal.item || {};
                const titleChanged = orig.title !== next.title;
                const contentChanged = (orig.content || '') !== (next.content || '');

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
                            )
                    );

                if (titleChanged) {
                    $proposalCard.append(
                        $('<div>').addClass('wcp-ai-proposal-diff')
                            .append($('<span>').addClass('wcp-ai-proposal-old').text(orig.title))
                            .append($('<h5>').text(next.title))
                    );
                } else {
                    $proposalCard.append($('<h5>').text(next.title));
                }

                if (contentChanged && orig.content) {
                    $proposalCard.append(
                        $('<div>').addClass('wcp-ai-proposal-content wcp-ai-proposal-old').text(orig.content)
                    );
                }
                if (next.content || contentChanged) {
                    $proposalCard.append(
                        $('<div>').addClass('wcp-ai-proposal-content').text(next.content || '(description cleared)')
                    );
                }

                $container.append($proposalCard);
            });

            this.updateProposalSelectedCount();
            $('.wcp-ai-approval-panel').slideDown();

            const itemWord = proposals.length === 1 ? 'item' : 'items';
            this.appendMessage('assistant', `I've proposed edits to ${proposals.length} ${itemWord}. Review and apply the ones you want.`);
        },

        /**
         * Render a structure proposal: new headings (with their child items),
         * "under <existing heading>" groups, and page-level items — each row a
         * checkbox. Unchecking a new heading disables its child items.
         */
        showStructureProposal: function(plan) {
            const esc = (s) => $('<span>').text(s == null ? '' : s).html();
            const $c = $('.wcp-ai-proposals');
            let html = '<div class="wcp-struct">';

            (plan.new_headings || []).forEach((h) => {
                html += '<div class="wcp-struct-group">';
                html += '<label class="wcp-struct-row wcp-struct-heading">'
                    + '<input type="checkbox" class="wcp-struct-heading-cb" data-ref="' + esc(h.ref) + '" data-proposal-id="' + esc(h.proposal_id) + '" checked> '
                    + '<span class="wcp-struct-badge">+ heading</span> ' + esc(h.title) + '</label>';
                (h.items || []).forEach((it) => {
                    html += '<label class="wcp-struct-row wcp-struct-item wcp-struct-child" data-ref="' + esc(h.ref) + '">'
                        + '<input type="checkbox" class="wcp-struct-item-cb" data-proposal-id="' + esc(it.proposal_id) + '" checked> '
                        + '<span class="wcp-struct-type">' + esc(it.item_type) + '</span> ' + esc(it.title) + '</label>';
                });
                html += '</div>';
            });

            (plan.existing_groups || []).forEach((g) => {
                html += '<div class="wcp-struct-group"><div class="wcp-struct-grouplabel">under ' + esc(g.title) + '</div>';
                (g.items || []).forEach((it) => {
                    html += '<label class="wcp-struct-row wcp-struct-item">'
                        + '<input type="checkbox" class="wcp-struct-item-cb" data-proposal-id="' + esc(it.proposal_id) + '" checked> '
                        + '<span class="wcp-struct-type">' + esc(it.item_type) + '</span> ' + esc(it.title) + '</label>';
                });
                html += '</div>';
            });

            if ((plan.page_items || []).length) {
                html += '<div class="wcp-struct-group"><div class="wcp-struct-grouplabel">page level</div>';
                plan.page_items.forEach((it) => {
                    html += '<label class="wcp-struct-row wcp-struct-item">'
                        + '<input type="checkbox" class="wcp-struct-item-cb" data-proposal-id="' + esc(it.proposal_id) + '" checked> '
                        + '<span class="wcp-struct-type">' + esc(it.item_type) + '</span> ' + esc(it.title) + '</label>';
                });
                html += '</div>';
            }

            html += '<div class="wcp-struct-actions">'
                + '<button type="button" class="button button-primary wcp-struct-create">Create selected</button> '
                + '<button type="button" class="button wcp-struct-dismiss">Dismiss</button>'
                + '</div></div>';

            $c.html(html);
            // This panel reuses the approval container but supplies its own buttons.
            $('.wcp-ai-approval-panel .wcp-ai-approval-actions').hide();
            $('.wcp-ai-approval-panel').slideDown();
            this.appendMessage('assistant', 'Proposed a structure update — review and create what you want.');
        },

        acceptStructure: function() {
            const headingIds = $('.wcp-struct-heading-cb:checked').map(function() { return $(this).data('proposal-id'); }).get();
            const itemIds = $('.wcp-struct-item-cb:checked').filter(function() { return !$(this).prop('disabled'); }).map(function() { return $(this).data('proposal-id'); }).get();
            if (!headingIds.length && !itemIds.length) { this.showError('Nothing selected.'); return; }

            const $btn = $('.wcp-struct-create');
            $btn.prop('disabled', true).text('Creating…');

            $.ajax({
                url: wcpAiWidgetData.restUrl + '/ai/structure/accept',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ batch_id: this.currentBatchId, heading_ids: headingIds, item_ids: itemIds }),
                beforeSend: (xhr) => { xhr.setRequestHeader('X-WP-Nonce', wcpAiWidgetData.nonce); },
                success: (r) => {
                    if (r.success) {
                        this.appendMessage('system', 'Created ' + r.created_headings + ' heading(s) and ' + r.created_items + ' item(s).');
                        this.dismissStructure();
                        setTimeout(() => location.reload(), 700);
                    } else {
                        $btn.prop('disabled', false).text('Create selected');
                        this.showError(r.message || 'Could not create structure.');
                    }
                },
                error: () => {
                    $btn.prop('disabled', false).text('Create selected');
                    this.showError('Connection error.');
                }
            });
        },

        dismissStructure: function() {
            $('.wcp-ai-proposals').empty();
            $('.wcp-ai-approval-panel').slideUp();
            $('.wcp-ai-approval-panel .wcp-ai-approval-actions').show();
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
                alert(this.proposalMode === 'edit' ? 'Please select at least one edit to apply.' : 'Please select at least one item to create.');
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

                        const createdCount = response.created_posts ? response.created_posts.length : 0;
                        const updatedCount = response.updated_posts ? response.updated_posts.length : 0;
                        this.appendMessage('system', response.message || 'Done.');

                        // Log debug info if present
                        if (response.debug) {
                            console.log('Debug info:', response.debug);
                        }

                        // Optionally reload page to show new/updated items
                        const totalCount = createdCount + updatedCount;
                        if (totalCount > 0) {
                            setTimeout(() => {
                                if (confirm(`${response.message} Would you like to reload the page to see the changes?`)) {
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
            $('#wcp-ai-widget').addClass('minimized').removeClass('closed expanded');
            $('body').removeClass('wcp-ai-panel-open wcp-ai-panel-expanded');
        },

        closeWidget: function() {
            $('#wcp-ai-widget').addClass('closed').removeClass('minimized expanded');
            $('body').removeClass('wcp-ai-panel-open wcp-ai-panel-expanded');
        },

        toggleExpandWidget: function() {
            const $widget = $('#wcp-ai-widget').toggleClass('expanded');
            const expanded = $widget.hasClass('expanded');
            $('body').toggleClass('wcp-ai-panel-expanded', expanded);
            $('.wcp-ai-expand')
                .attr('aria-label', expanded ? 'Collapse to side panel' : 'Expand to full screen')
                .find('.dashicons')
                .toggleClass('dashicons-fullscreen-alt', !expanded)
                .toggleClass('dashicons-fullscreen-exit-alt', expanded);
        },

        /**
         * Show loading state, optionally with a message describing what's
         * actually happening (defaults to the generic fallback text baked
         * into the template).
         */
        showLoading: function(show, message) {
            if (show) {
                $('.wcp-ai-loading-text').text(message || 'AI is thinking...');
                $('.wcp-ai-loading').show();
            } else {
                $('.wcp-ai-loading').hide();
            }
        },

        // Per-action_type verb phrase for the loading message. Anything not
        // listed here falls back to a generic "Working on it".
        AI_LOADING_VERBS: {
            chat: 'Reading',
            chat_qa: 'Reading',
            coaching: 'Reading',
            coaching_dialogue: 'Reading',
            web_search: 'Searching the web and reading',
            taxonomy_outline: 'Reading your site structure',
            mission_priorities: 'Reading recent items against your mission',
            weekly_summary: 'Reading items from the last 7 days',
            generate_structure: 'Reading and drafting headings and items',
            generate_headings: 'Reading and drafting headings',
            generate_items: 'Reading and drafting items',
            generate_pages: 'Drafting sub-pages',
            rewrite_content: 'Reading and rewriting page content',
            append_content: 'Reading and drafting content to append',
            edit_items: 'Reading and reviewing items',
            iterate_items: 'Reading and reworking items',
            spot_gaps: 'Reading items to spot gaps',
            fetch_posts: 'Fetching posts',
            fetch_structure: 'Reading page structure',
            auto: 'Figuring out the best way to help'
        },

        // Per-action_type item cap, mirroring the server-side constants in
        // class-ai-actions.php. Only actions with a meaningfully-informative
        // cap are listed; omitted actions don't get a constraint clause.
        AI_LOADING_ITEM_LIMITS: {
            expand_draft: 10
        },

        /**
         * Flatten this.pagesCache's nested {id, title, children} tree into a
         * flat id -> title lookup map. Cheap enough to rebuild on demand
         * rather than maintaining a second cache.
         */
        flattenPagesCache: function(pages, out) {
            out = out || {};
            (pages || []).forEach((page) => {
                out[page.id] = page.title;
                if (page.children && page.children.length) {
                    this.flattenPagesCache(page.children, out);
                }
            });
            return out;
        },

        /**
         * Build a short, honest description of what's about to happen for a
         * given action_type, using only data already available client-side
         * (this.contextMode, this.selectedPages, wcpAiWidgetData.pageName).
         * No live counts — those aren't known until the response returns.
         */
        buildLoadingMessage: function(actionType) {
            const verb = this.AI_LOADING_VERBS[actionType] || 'Working on it';
            let scope;

            if (this.contextMode === 'select') {
                const titleMap = this.flattenPagesCache(this.pagesCache);
                const titles = (this.selectedPages || [])
                    .map((sel) => titleMap[sel.page_id])
                    .filter(Boolean);
                scope = titles.length
                    ? 'from ' + this.selectedPages.length + ' selected page' + (this.selectedPages.length === 1 ? '' : 's') + ' (' + titles.join(', ') + ')'
                    : 'from ' + (this.selectedPages || []).length + ' selected page' + ((this.selectedPages || []).length === 1 ? '' : 's');
            } else if (this.contextMode === 'corpus') {
                scope = 'across your entire site';
            } else {
                scope = 'on this page' + (wcpAiWidgetData.pageName ? ' (' + wcpAiWidgetData.pageName + ')' : '');
            }

            let message = verb + ' ' + scope + '...';
            const limit = this.AI_LOADING_ITEM_LIMITS[actionType];
            if (limit) {
                message = verb + ' ' + scope + ' (up to ' + limit + ' items)...';
            }
            return message;
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
        },

        /**
         * Fire the onboard action immediately (no user prompt required)
         */
        runOnboard: function() {
            this.showLoading(true, 'Reviewing this page and drafting an intro...');
            $('.wcp-ai-action-chip').removeClass('active');

            $.ajax({
                url: wcpAiWidgetData.restUrl + '/ai/onboard',
                method: 'POST',
                beforeSend: (xhr) => {
                    xhr.setRequestHeader('X-WP-Nonce', wcpAiWidgetData.nonce);
                },
                data: {
                    page_id: wcpAiWidgetData.pageId,
                    conversation_id: this.conversationId
                },
                success: (response) => {
                    this.showLoading(false);
                    if (response.success) {
                        this.handleActionResult(response);
                    } else {
                        this.showError(response.message || 'Onboard failed');
                    }
                },
                error: (xhr) => {
                    this.showLoading(false);
                    this.showError('Connection error: ' + xhr.statusText);
                }
            });
        },

        /**
         * Fire a canned, fixed-prompt site-level action immediately (taxonomy
         * outline, mission priorities, weekly summary) — same request shape as
         * sendMessage(), just without the textarea step.
         */
        runCannedAction: function(actionType, promptText) {
            $('.wcp-ai-action-chip').removeClass('active');
            this.showLoading(true, this.buildLoadingMessage(actionType));
            this.appendMessage('user', promptText);

            const data = {
                action_type: actionType,
                prompt: promptText,
                page_id: wcpAiWidgetData.pageId,
                conversation_id: this.conversationId,
                context_mode: this.contextMode,
                model: this.selectedModel,
                thinking_budget: this.thinkingBudget
            };

            // Canned/chip actions previously ignored the user's page
            // selection in 'select' mode — only sendMessage() sent it. Match
            // that behavior so the loading message's claimed scope is accurate.
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
                    if (response.success) {
                        this.handleActionResult(response.result);
                    } else {
                        this.showError(response.message || 'Action failed');
                    }
                },
                error: (xhr) => {
                    this.showLoading(false);
                    this.showError('Connection error: ' + xhr.statusText);
                }
            });
        },

        /**
         * Show a mission-save offer after onboard suggests a mission
         */
        showMissionOffer: function(suggestedMission) {
            const $textarea = $('<textarea>')
                .addClass('wcp-ai-mission-offer-editor')
                .val(suggestedMission)
                .attr('rows', 4);

            const $offer = $('<div>').addClass('wcp-ai-mission-offer')
                .append(
                    $('<p>').addClass('wcp-ai-mission-offer-text')
                        .text('No AI mission is set for this page. Edit and save:'),
                    $textarea,
                    $('<div>').addClass('wcp-ai-mission-offer-actions')
                        .append(
                            $('<button>').addClass('wcp-ai-save-mission-btn button button-primary')
                                .text('Save as AI Mission'),
                            $('<button>').addClass('wcp-ai-dismiss-mission-btn button')
                                .text('Dismiss')
                        )
                );

            $('.wcp-ai-messages').append($offer);
            this.scrollToBottom();

            $(document).one('click', '.wcp-ai-dismiss-mission-btn', () => {
                $offer.remove();
            });
        },

        /**
         * Save the mission offer textarea value to the page
         */
        saveSuggestedMission: function() {
            const mission = $('.wcp-ai-mission-offer-editor').val().trim();
            if (!mission) return;
            const $btn = $('.wcp-ai-save-mission-btn');
            $btn.prop('disabled', true).text('Saving…');

            $.ajax({
                url: wcpAiWidgetData.restUrl + '/pages/' + wcpAiWidgetData.pageId + '/mission/append',
                method: 'POST',
                beforeSend: (xhr) => {
                    xhr.setRequestHeader('X-WP-Nonce', wcpAiWidgetData.nonce);
                },
                data: { text: mission },
                success: (response) => {
                    if (response.success) {
                        $('.wcp-ai-mission-offer').remove();
                        this.appendMessage('assistant', 'AI mission saved for this page.');
                        // Refresh mission indicator
                        this.fetchActiveMission();
                    } else {
                        $btn.prop('disabled', false).text('Save as AI Mission');
                        this.showError(response.message || 'Could not save mission');
                    }
                },
                error: () => {
                    $btn.prop('disabled', false).text('Save as AI Mission');
                    this.showError('Could not save — please try again.');
                }
            });
        }
    };

    // Exposed so other scripts (e.g. the page-level "AI actions" panel in
    // theme.js) can drive the widget — set an action, open it, and send a
    // prompt through the same conversation/approval pipeline — instead of
    // duplicating the request + proposal-rendering logic.
    window.WcpAIWidget = AIWidget;

    // Initialize on document ready
    $(document).ready(() => {
        AIWidget.init();
    });

})(jQuery);
