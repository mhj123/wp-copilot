jQuery(document).ready(function($) {

    // Load context tree for the form
    function loadContextTree($container, preselectedContextId) {
        $container = $container || $('#wcp-item-contexts');
        var currentPageId = $('input[name="page_id"]').val();

        $.ajax({
            url: wcpThemeData.restUrl + '/contexts/tree',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce);
            },
            success: function(response) {
                if (response.success && response.tree) {
                    renderContextTree(response.tree, $container, currentPageId, preselectedContextId);
                } else {
                    $container.html('<p class="wcp-error">Failed to load contexts.</p>');
                }
            },
            error: function() {
                $container.html('<p class="wcp-error">Failed to load contexts.</p>');
            }
        });
    }

    function renderContextTree(tree, $container, currentPageId, preselectedContextId, level) {
        level = level || 0;

        if (level === 0) {
            $container.html('<ul class="wcp-context-tree"></ul>');
            $container = $container.find('ul');
        }

        tree.forEach(function(node) {
            var $li = $('<li>');

            // Check if this node corresponds to the current page or preselected context
            var isCurrentPage = (node.ref_type === 'page' && node.ref_id == currentPageId);
            var isPreselected = preselectedContextId && (node.term_id == preselectedContextId);

            var $label = $('<label>');
            var $checkbox = $('<input type="checkbox" name="contexts[]">')
                .val(node.term_id)
                .prop('checked', isCurrentPage || isPreselected); // Pre-select current page or heading

            var $name = $('<span class="context-name">').text(node.name);
            var $count = $('<span class="context-count">').text('(' + node.count + ')');

            $label.append($checkbox).append($name).append($count);
            $li.append($label);

            if (node.children && node.children.length > 0) {
                var $ul = $('<ul>');
                $li.append($ul);
                renderContextTree(node.children, $ul, currentPageId, preselectedContextId, level + 1);
            }

            $container.append($li);
        });
    }

    // ==========================================================================
    // Quick-add item forms (page-level and per-heading)
    // ==========================================================================

    // Toggle quick-add form visibility
    $(document).on('click', '.wcp-btn-quick-add-item', function() {
        var $wrap = $(this).closest('.wcp-quick-add-wrap');
        var $form = $wrap.find('.wcp-quick-item-form');
        $form.slideToggle(150, function() {
            if ($form.is(':visible')) {
                $form.find('.wcp-quick-title').focus();
                // Lazy-load the context tree (once per form)
                var $ctx = $form.find('.wcp-form-contexts');
                if ($ctx.find('ul').length === 0) {
                    loadContextTree($ctx, $form.data('context-id'));
                }
            }
        });
    });

    // Toggle the page-association tree inside a quick-add form
    $(document).on('click', '.wcp-toggle-form-contexts', function() {
        $(this).next('.wcp-form-contexts').slideToggle(150);
    });

    // Cancel quick-add
    $(document).on('click', '.wcp-btn-cancel-quick', function() {
        var $form = $(this).closest('.wcp-quick-item-form');
        $form.slideUp(150)[0].reset();
        $form.find('.wcp-quick-status').text('');
    });

    // Submit quick-add item form
    $(document).on('submit', '.wcp-quick-item-form', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('button[type="submit"]');
        var $status = $form.find('.wcp-quick-status');
        var contextId = $form.data('context-id');
        var title = $form.find('input[name="title"]').val().trim();

        if (!title) return;

        var tagsRaw = $form.find('input[name="tags"]').val().trim();
        var tags = tagsRaw ? tagsRaw.split(',').map(function(t) { return t.trim(); }).filter(Boolean) : [];

        // Collect checked contexts from the page-association tree; fall back to primary context
        var checkedContexts = $form.find('.wcp-form-contexts input[type="checkbox"]:checked').map(function() {
            return parseInt($(this).val(), 10);
        }).get();
        var contexts = checkedContexts.length > 0 ? checkedContexts : [parseInt(contextId, 10)];

        var data = {
            title: title,
            contexts: contexts,
            item_type: $form.find('select[name="item_type"]').val(),
            priority: $form.find('select[name="priority"]').val()
        };
        if (tags.length) data.tags = tags;

        $btn.prop('disabled', true).text('Adding...');
        $status.removeClass('error').text('');

        $.ajax({
            url: wcpThemeData.restUrl + '/items/create',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(data),
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce);
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    $status.addClass('error').text(response.message || 'Error');
                    $btn.prop('disabled', false).text('Add');
                }
            },
            error: function(xhr) {
                var msg = 'Error';
                if (xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
                else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.status) msg += ' ' + xhr.responseJSON.data.status;
                console.error('Quick-add error:', xhr.status, xhr.responseText);
                $status.addClass('error').text(msg);
                $btn.prop('disabled', false).text('Add');
            }
        });
    });

    // Filtering items
    $('#wcp-filter-type, #wcp-filter-priority').on('change', function() {
        var itemType = $('#wcp-filter-type').val();
        var priority = $('#wcp-filter-priority').val();

        // Simple client-side filtering
        $('.wcp-item').each(function() {
            var $item = $(this);
            var show = true;

            if (itemType) {
                var hasType = $item.find('.wcp-type-' + itemType).length > 0;
                if (!hasType) show = false;
            }

            if (priority) {
                var hasPriority = $item.find('.wcp-priority-' + priority).length > 0;
                if (!hasPriority) show = false;
            }

            if (show) {
                $item.show();
            } else {
                $item.hide();
            }
        });
    });

    // Toggle semantic search panel
    $('#wcp-toggle-search').on('click', function() {
        var $panel = $('#wcp-search-panel');
        var $button = $(this);

        if ($panel.is(':visible')) {
            $panel.slideUp(300);
            $button.removeClass('active');
        } else {
            $panel.slideDown(300);
            $button.addClass('active');
            $('#wcp-semantic-search-input').focus();
        }
    });

    // Semantic search functionality
    function performSemanticSearch() {
        var query = $('#wcp-semantic-search-input').val().trim();
        var $resultsContainer = $('#wcp-search-results');
        var $searchBtn = $('#wcp-semantic-search-btn');

        if (!query) {
            $resultsContainer.html('<p class="wcp-search-empty">Please enter a search query.</p>');
            return;
        }

        $searchBtn.prop('disabled', true).text('Searching...');
        $resultsContainer.html('<p class="wcp-search-loading">Searching your notes...</p>');

        $.ajax({
            url: wcpThemeData.restUrl + '/search/semantic',
            method: 'POST',
            data: {
                query: query,
                limit: 10
            },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce);
            },
            success: function(response) {
                if (response.success && response.results && response.results.length > 0) {
                    renderSearchResults(response.results, query);
                } else if (response.success && response.results.length === 0) {
                    $resultsContainer.html(
                        '<div class="wcp-search-empty">' +
                        '<p>No results found for "' + escapeHtml(query) + '"</p>' +
                        '<p class="wcp-search-hint">Try different keywords or phrases.</p>' +
                        '</div>'
                    );
                } else {
                    $resultsContainer.html(
                        '<div class="wcp-search-error">' +
                        '<p>Error: ' + (response.message || 'Unknown error') + '</p>' +
                        '</div>'
                    );
                }
            },
            error: function(xhr, status, error) {
                var message = 'Search failed. Please try again.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    message = xhr.responseJSON.message;
                }
                $resultsContainer.html(
                    '<div class="wcp-search-error"><p>' + escapeHtml(message) + '</p></div>'
                );
            },
            complete: function() {
                $searchBtn.prop('disabled', false).text('Search');
            }
        });
    }

    function renderSearchResults(results, query) {
        var html = '<div class="wcp-search-results-header">';
        html += '<h3>Found ' + results.length + ' result' + (results.length !== 1 ? 's' : '') + ' for "' + escapeHtml(query) + '"</h3>';
        html += '</div>';

        html += '<div class="wcp-search-results-list">';

        results.forEach(function(result) {
            var similarityPercent = Math.round(result.similarity * 100);
            var relevanceClass = similarityPercent >= 80 ? 'high' : (similarityPercent >= 60 ? 'medium' : 'low');

            html += '<article class="wcp-search-result wcp-relevance-' + relevanceClass + '">';
            html += '<div class="wcp-search-result-header">';
            html += '<h4><a href="' + result.view_url + '">' + escapeHtml(result.title) + '</a></h4>';
            html += '<span class="wcp-search-relevance" title="Relevance score">' + similarityPercent + '% match</span>';
            html += '</div>';

            if (result.content) {
                html += '<div class="wcp-search-result-content">' + escapeHtml(result.content) + '</div>';
            }

            html += '<div class="wcp-search-result-meta">';
            if (result.contexts && result.contexts.length > 0) {
                html += '<span class="wcp-search-context">📁 ' + escapeHtml(result.contexts.join(', ')) + '</span>';
            }
            html += '<a href="' + result.edit_url + '" class="wcp-search-edit-link">Edit →</a>';
            html += '</div>';

            html += '</article>';
        });

        html += '</div>';

        $('#wcp-search-results').html(html);
    }

    function escapeHtml(text) {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    // Semantic search button click
    $('#wcp-semantic-search-btn').on('click', function() {
        performSemanticSearch();
    });

    // Semantic search on Enter key
    $('#wcp-semantic-search-input').on('keypress', function(e) {
        if (e.which === 13) { // Enter key
            e.preventDefault();
            performSemanticSearch();
        }
    });

    // ==========================================================================
    // Inline Item Editing
    // ==========================================================================

    function updateItem(itemId, data) {
        return $.ajax({
            url: wcpThemeData.restUrl + '/items/' + itemId + '/update',
            method: 'POST',
            data: data,
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce);
            }
        });
    }

    // Click title to edit inline
    $(document).on('click', '.wcp-item-title', function() {
        var $title = $(this);
        var $row = $title.closest('.wcp-item-row');
        var $input = $row.find('.wcp-item-title-input');

        $title.hide();
        $input.show().focus().select();
    });

    // Save on Enter, cancel on Escape
    $(document).on('keydown', '.wcp-item-title-input', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $(this).blur();
        } else if (e.which === 27) {
            var $input = $(this);
            var $row = $input.closest('.wcp-item-row');
            var $title = $row.find('.wcp-item-title');
            $input.val($title.text()).hide();
            $title.show();
        }
    });

    // Save on blur
    $(document).on('blur', '.wcp-item-title-input', function() {
        var $input = $(this);
        var $row = $input.closest('.wcp-item-row');
        var $title = $row.find('.wcp-item-title');
        var itemId = $row.data('item-id');
        var newTitle = $input.val().trim();

        if (!newTitle || newTitle === $title.text()) {
            $input.val($title.text()).hide();
            $title.show();
            return;
        }

        updateItem(itemId, { title: newTitle })
            .done(function(response) {
                if (response.success) {
                    $title.text(newTitle);
                }
            })
            .always(function() {
                $input.hide();
                $title.show();
            });
    });

    // Dropdown changes — save immediately
    $(document).on('change', '.wcp-task-checkbox', function() {
        var itemId = $(this).data('item-id');
        var done = $(this).is(':checked');
        var status = done ? 'done' : 'to-do';
        var $row = $(this).closest('.wcp-item-row');
        updateItem(itemId, { task_status: status });
        $row.find('.wcp-status-select').val(status);
        $row.toggleClass('wcp-task-done', done);
    });

    $(document).on('change', '.wcp-type-select', function() {
        var itemId = $(this).data('item-id');
        var type = $(this).val();
        updateItem(itemId, { item_type: type });
        var $row = $(this).closest('.wcp-item-row');
        var $statusSelect = $row.find('.wcp-status-select');
        var $checkbox = $row.find('.wcp-task-checkbox');
        if (type === 'task') {
            $statusSelect.show();
            $checkbox.show();
        } else {
            $statusSelect.hide().val('');
            $checkbox.hide().prop('checked', false);
            $row.removeClass('wcp-task-done');
            updateItem(itemId, { task_status: '' });
        }
    });

    $(document).on('change', '.wcp-priority-select', function() {
        var itemId = $(this).data('item-id');
        updateItem(itemId, { priority: $(this).val() });
    });

    $(document).on('change', '.wcp-status-select', function() {
        var itemId = $(this).data('item-id');
        var status = $(this).val();
        var $row = $(this).closest('.wcp-item-row');
        updateItem(itemId, { task_status: status });
        var done = status === 'done';
        $row.find('.wcp-task-checkbox').prop('checked', done);
        $row.toggleClass('wcp-task-done', done);
    });

    // Delete item
    $(document).on('click', '.wcp-item-delete', function() {
        var $btn = $(this);
        var $row = $btn.closest('.wcp-item-row');
        var itemId = $btn.data('item-id');

        if (!confirm('Delete this item?')) return;

        $.ajax({
            url: wcpThemeData.restUrl + '/items/' + itemId + '/delete',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce);
            },
            success: function(response) {
                if (response.success) {
                    $row.fadeOut(200, function() { $(this).remove(); });
                }
            }
        });
    });

    // ==========================================================================
    // Heading Management
    // ==========================================================================

    // Create Heading
    function createHeading(pageId, title, content) {
        $.ajax({
            url: wcpThemeData.restUrl + '/headings/create',
            method: 'POST',
            data: {
                parent_id: pageId,
                parent_type: 'page',
                title: title,
                content: content
            },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce);
            },
            success: function(response) {
                if (response.success) {
                    location.reload(); // Reload to show new heading
                }
            },
            error: function(xhr) {
                alert('Error creating heading: ' + (xhr.responseJSON?.message || 'Unknown error'));
            }
        });
    }

    // ==========================================================================
    // Heading creation form
    // ==========================================================================

    $(document).on('click', '#wcp-btn-new-heading', function() {
        var $form = $('#wcp-create-heading-form');
        $form.slideToggle(150, function() {
            if ($form.is(':visible')) $form.find('.wcp-quick-title').focus();
        });
    });

    $(document).on('click', '#wcp-btn-cancel-heading', function() {
        var $form = $('#wcp-create-heading-form');
        $form.slideUp(150);
        $form[0].reset();
        $form.find('.wcp-quick-status').text('');
    });

    $(document).on('submit', '#wcp-create-heading-form', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('button[type="submit"]');
        var $status = $form.find('.wcp-quick-status');
        var pageId = $form.find('input[name="page_id"]').val();
        var title = $form.find('input[name="title"]').val().trim();

        if (!title) return;

        $btn.prop('disabled', true).text('Creating...');
        $status.removeClass('error').text('');

        createHeading(pageId, title, '');
    });

    // ==========================================================================
    // Drag-to-reorder items
    // ==========================================================================

    function saveItemOrder(lists) {
        $.ajax({
            url: wcpThemeData.restUrl + '/items/reorder',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ lists: lists }),
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce);
            },
            error: function(xhr) {
                console.error('Reorder failed:', xhr.responseText);
            }
        });
    }

    function getListState(el) {
        return {
            context_id: parseInt(el.dataset.contextId, 10),
            item_ids: Array.from(el.querySelectorAll('.wcp-item-row')).map(function(row) {
                return parseInt(row.dataset.itemId, 10);
            })
        };
    }

    document.querySelectorAll('.wcp-items-list').forEach(function(list) {
        Sortable.create(list, {
            group: 'wcp-items',
            handle: '.wcp-drag-handle',
            animation: 150,
            ghostClass: 'wcp-drag-ghost',
            dragClass: 'wcp-dragging',
            onEnd: function(evt) {
                // Skip if dropped back in the same position
                if (evt.from === evt.to && evt.oldIndex === evt.newIndex) return;

                var lists = [];
                var seen = new Set();

                [evt.from, evt.to].forEach(function(el) {
                    if (!seen.has(el)) {
                        seen.add(el);
                        lists.push(getListState(el));
                    }
                });

                saveItemOrder(lists);
            }
        });
    });

});
