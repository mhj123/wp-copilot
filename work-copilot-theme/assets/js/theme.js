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
    // Homepage structure tree
    // ==========================================================================

    function loadStructureTree() {
        var $container = $('#wcp-structure-tree');
        if (!$container.length) return;

        $.ajax({
            url: wcpThemeData.restUrl + '/contexts/tree',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce);
            },
            success: function(response) {
                $container.empty();
                if (response.success && response.tree && response.tree.length) {
                    renderStructureTree(response.tree, $container);
                } else {
                    $container.html('<p class="wcp-tree-empty">No pages found. <a href="' + wcpThemeData.adminUrl + 'post-new.php?post_type=page">Create your first page</a>.</p>');
                }
            },
            error: function() {
                $container.html('<p class="wcp-error">Failed to load structure.</p>');
            }
        });
    }

    function renderStructureTree(nodes, $container, level) {
        level = level || 0;
        // Root level should only contain pages — orphaned heading terms have parent=0
        // in the taxonomy but belong under a page; skip them here.
        if (level === 0) {
            nodes = nodes.filter(function(n) { return n.ref_type === 'page'; });
        }
        var $ul = $('<ul class="wcp-tree-list">');

        nodes.forEach(function(node) {
            var hasChildren = node.children && node.children.length > 0;
            var $li = $('<li>').addClass('wcp-tree-node wcp-tree-node--' + node.ref_type);

            var $row = $('<div class="wcp-tree-row">');

            if (hasChildren) {
                var $toggle = $('<button class="wcp-tree-toggle" aria-expanded="false" aria-label="Expand">').html('<span class="wcp-tree-arrow">&#9654;</span>');
                $row.append($toggle);
            } else {
                $row.append($('<span class="wcp-tree-toggle-spacer">'));
            }

            if (node.ref_type === 'page') {
                var url = wcpThemeData.homeUrl + '/?page_id=' + node.ref_id;
                $row.append($('<a class="wcp-tree-label">').attr('href', url).text(node.name));
            } else {
                $row.append($('<span class="wcp-tree-label wcp-tree-label--heading">').text(node.name));
            }

            if (node.count > 0) {
                $row.append($('<span class="wcp-tree-count">').text(node.count));
            }

            $li.append($row);

            if (hasChildren) {
                var $children = $('<div class="wcp-tree-children">').hide();
                renderStructureTree(node.children, $children, level + 1);
                $li.append($children);

                $row.find('.wcp-tree-toggle').on('click', function() {
                    var expanded = $children.is(':visible');
                    $children.toggle();
                    $(this).attr('aria-expanded', !expanded)
                           .find('.wcp-tree-arrow').html(expanded ? '&#9654;' : '&#9660;');
                });
            }

            $ul.append($li);
        });

        $container.append($ul);
    }

    if ($('#wcp-structure-tree').length && wcpThemeData.isLoggedIn) {
        loadStructureTree();
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

    // Item filtering — All items / All tasks / Open tasks
    $(document).on('click', '.wcp-filter-btn', function() {
        var $btn = $(this);
        var filter = $btn.data('filter');
        $btn.siblings('.wcp-filter-btn').removeClass('active');
        $btn.addClass('active');

        $('.wcp-item-row').each(function() {
            var $row = $(this);
            var type   = $row.data('item-type');
            var status = $row.data('task-status');
            var show;
            if (filter === 'tasks') {
                show = type === 'task';
            } else if (filter === 'open') {
                show = type === 'task' && status !== 'done';
            } else {
                show = true;
            }
            $row.toggle(show);
        });
    });

    // Toggle description visibility
    $(document).on('click', '.wcp-toggle-descriptions', function() {
        var $btn = $(this);
        var showing = $btn.hasClass('active');
        $btn.toggleClass('active', !showing);
        $('.wcp-items-section').toggleClass('wcp-show-descriptions', !showing);
    });

    // Sort items by due date within each items-list container
    function sortListByDueDate($list) {
        var $rows = $list.find('> .wcp-item-row').get();
        $rows.sort(function(a, b) {
            var da = $(a).data('due-date') || '';
            var db = $(b).data('due-date') || '';
            // Items with a due date go first; among those, earliest first
            if (da && db) return da < db ? -1 : da > db ? 1 : 0;
            if (da) return -1;
            if (db) return 1;
            return 0;
        });
        $.each($rows, function(i, row) { $list.append(row); });
    }

    $(document).on('click', '.wcp-sort-due-date', function() {
        var $btn    = $(this);
        var active  = $btn.hasClass('active');
        var scope   = $btn.data('scope'); // 'listing' or undefined (page-wide)
        $btn.toggleClass('active', !active);
        if (!active) {
            if (scope === 'listing') {
                // Sort only within this dynamic listing
                var listingId = $btn.data('listing-id');
                $('[data-listing-id="' + listingId + '"].wcp-dynamic-listing .wcp-items-list').each(function() {
                    sortListByDueDate($(this));
                });
            } else {
                $('.wcp-items-section .wcp-items-list, .wcp-dynamic-listing .wcp-items-list').each(function() {
                    sortListByDueDate($(this));
                });
            }
        } else {
            location.reload();
        }
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
        $row.data('task-status', status);
    });

    $(document).on('change', '.wcp-type-select', function() {
        var itemId = $(this).data('item-id');
        var type = $(this).val();
        updateItem(itemId, { item_type: type });
        var $row = $(this).closest('.wcp-item-row');
        var $statusSelect = $row.find('.wcp-status-select');
        var $checkbox = $row.find('.wcp-task-checkbox');
        var $dueDate = $row.find('.wcp-due-date-input');
        $row.data('item-type', type);
        if (type === 'task') {
            $statusSelect.show();
            $checkbox.show();
            $dueDate.show();
            // Default status to 'to-do' if not already set
            if (!$statusSelect.val()) {
                $statusSelect.val('to-do');
                $row.data('task-status', 'to-do');
                updateItem(itemId, { task_status: 'to-do' });
            }
        } else {
            $statusSelect.hide().val('');
            $checkbox.hide().prop('checked', false);
            $dueDate.hide().val('');
            $row.removeClass('wcp-task-done');
            $row.data('task-status', '').data('due-date', '');
            updateItem(itemId, { task_status: '', due_date: '' });
        }
    });

    $(document).on('change', '.wcp-due-date-input', function() {
        var $input = $(this);
        var itemId = $input.data('item-id');
        var date   = $input.val(); // Y-m-d or ''
        $input.closest('.wcp-item-row').data('due-date', date);
        updateItem(itemId, { due_date: date });
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
        $row.data('task-status', status);
    });

    // Delete item
    $(document).on('click', '.wcp-heading-delete', function() {
        var $btn       = $(this);
        var headingId  = $btn.data('heading-id');
        var $group     = $btn.closest('.wcp-heading-group');

        if (!confirm('Delete this heading and all its items?')) return;

        $.ajax({
            url: wcpThemeData.restUrl + '/headings/' + headingId + '/delete',
            method: 'POST',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce); },
            success: function(response) {
                if (response.success) $group.fadeOut(200, function() { $(this).remove(); });
            },
            error: function() { alert('Could not delete heading — please try again.'); }
        });
    });

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
    // Subtasks
    // ==========================================================================

    // Toggle add-form
    $(document).on('click', '.wcp-subtask-add-btn', function() {
        var $section = $(this).closest('.wcp-item-row').find('.wcp-subtask-section');
        var $form = $section.find('.wcp-subtask-add-form');
        $form.slideToggle(120, function() {
            if ($form.is(':visible')) $form.find('.wcp-subtask-input').focus();
        });
    });

    $(document).on('click', '.wcp-subtask-add-cancel', function() {
        var $form = $(this).closest('.wcp-subtask-add-form');
        $form.slideUp(120);
        $form.find('.wcp-subtask-input').val('');
    });

    // Submit new subtask
    $(document).on('submit', '.wcp-subtask-add-form', function(e) {
        e.preventDefault();
        var $form   = $(this);
        var itemId  = $form.data('item-id');
        var title   = $form.find('.wcp-subtask-input').val().trim();
        var $submit = $form.find('button[type="submit"]');

        if (!title) return;
        $submit.prop('disabled', true);

        $.ajax({
            url: wcpThemeData.restUrl + '/items/' + itemId + '/subtasks',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ title: title }),
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce); },
            success: function(response) {
                if (!response.success) return;
                var st = response.subtask;
                var $list = $form.closest('.wcp-subtask-section').find('.wcp-subtask-list');
                if (!$list.length) {
                    $list = $('<ul class="wcp-subtask-list">').insertBefore($form);
                }
                var $li = $('<li class="wcp-subtask-row" data-subtask-id="' + st.id + '">'
                    + '<input type="checkbox" class="wcp-subtask-checkbox" data-item-id="' + itemId + '" data-subtask-id="' + st.id + '">'
                    + '<span class="wcp-subtask-title">' + $('<span>').text(st.title).html() + '</span>'
                    + '<button type="button" class="wcp-subtask-delete wcp-edit-link" data-item-id="' + itemId + '" data-subtask-id="' + st.id + '">\xd7</button>'
                    + '</li>');
                $list.append($li);
                $form.find('.wcp-subtask-input').val('');
                $submit.prop('disabled', false);
            },
            error: function() { $submit.prop('disabled', false); }
        });
    });

    // Toggle done
    $(document).on('change', '.wcp-subtask-checkbox', function() {
        var $cb        = $(this);
        var itemId     = $cb.data('item-id');
        var subtaskId  = $cb.data('subtask-id');
        var $row       = $cb.closest('.wcp-subtask-row');

        $.ajax({
            url: wcpThemeData.restUrl + '/items/' + itemId + '/subtasks/' + subtaskId + '/toggle',
            method: 'POST',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce); },
            success: function(response) {
                if (response.success) {
                    $row.toggleClass('wcp-subtask-done', response.done);
                    $cb.prop('checked', response.done);
                }
            },
            error: function() { $cb.prop('checked', !$cb.prop('checked')); } // revert on fail
        });
    });

    // Delete subtask
    $(document).on('click', '.wcp-subtask-delete', function() {
        var $btn      = $(this);
        var itemId    = $btn.data('item-id');
        var subtaskId = $btn.data('subtask-id');
        var $row      = $btn.closest('.wcp-subtask-row');

        $.ajax({
            url: wcpThemeData.restUrl + '/items/' + itemId + '/subtasks/' + subtaskId,
            method: 'DELETE',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce); },
            success: function(response) {
                if (response.success) $row.fadeOut(150, function() { $(this).remove(); });
            }
        });
    });

    // ==========================================================================
    // Subpage creation
    // ==========================================================================

    $(document).on('click', '#wcp-btn-new-subpage', function() {
        var $form = $('#wcp-create-subpage-form');
        $form.slideToggle(150, function() {
            if ($form.is(':visible')) $form.find('.wcp-quick-title').focus();
        });
    });

    $(document).on('click', '#wcp-btn-cancel-subpage', function() {
        var $form = $('#wcp-create-subpage-form');
        $form.slideUp(150);
        $form[0].reset();
        $form.find('.wcp-quick-status').text('');
    });

    $(document).on('submit', '#wcp-create-subpage-form', function(e) {
        e.preventDefault();
        var $form   = $(this);
        var $btn    = $form.find('button[type="submit"]');
        var $status = $form.find('.wcp-quick-status');
        var pageId  = $form.find('input[name="page_id"]').val();
        var title   = $form.find('input[name="title"]').val().trim();

        if (!title) return;

        $btn.prop('disabled', true).text('Creating...');
        $status.removeClass('error').text('');

        $.ajax({
            url: wcpThemeData.restUrl + '/pages/create',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ parent_id: parseInt(pageId, 10), title: title }),
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce);
            },
            success: function(response) {
                if (response.success && response.page_url) {
                    window.location.href = response.page_url;
                }
            },
            error: function(xhr) {
                $btn.prop('disabled', false).text('Create subpage');
                $status.addClass('error').text(xhr.responseJSON?.message || 'Error creating subpage.');
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

    // ==========================================================================
    // Page notes inline editor
    // ==========================================================================

    // ==========================================================================
    // Collapsible sections: page content + notes (state persisted in localStorage)
    // ==========================================================================

    function sectionKey(section, pageId) {
        return 'wcp_section_' + section + '_' + pageId;
    }

    function applySectionState(section, pageId, animate) {
        var stored = localStorage.getItem(sectionKey(section, pageId));
        var hidden  = stored === 'hidden';
        var $body   = $('[data-section="' + section + '"][data-page-id="' + pageId + '"]').find('.wcp-section-body');
        var $btn    = $('.wcp-toggle-section[data-section="' + section + '"][data-page-id="' + pageId + '"]');

        if (hidden) {
            animate ? $body.slideUp(150) : $body.hide();
            $btn.text('show');
        } else {
            animate ? $body.slideDown(150) : $body.show();
            $btn.text('hide');
        }
    }

    // Initialise on load (no animation)
    $('[data-section]').each(function() {
        var section = $(this).data('section');
        var pageId  = $(this).data('page-id');
        if (section && pageId) applySectionState(section, pageId, false);
    });

    $(document).on('click', '.wcp-toggle-section', function() {
        var section = $(this).data('section');
        var pageId  = $(this).data('page-id');
        var stored  = localStorage.getItem(sectionKey(section, pageId));
        localStorage.setItem(sectionKey(section, pageId), stored === 'hidden' ? 'visible' : 'hidden');
        applySectionState(section, pageId, true);
    });

    // ==========================================================================
    // Page notes inline editor
    // ==========================================================================

    $(document).on('click', '.wcp-page-notes-edit, .wcp-page-notes-placeholder', function() {
        var $wrap = $(this).closest('.wcp-page-notes-wrap');
        $wrap.find('.wcp-page-notes-display').hide();
        $wrap.find('.wcp-page-notes-editor').show();
        $wrap.find('.wcp-page-notes-textarea').focus();
    });

    $(document).on('click', '.wcp-page-notes-cancel', function() {
        var $wrap = $(this).closest('.wcp-page-notes-wrap');
        $wrap.find('.wcp-page-notes-editor').hide();
        $wrap.find('.wcp-page-notes-display').show();
        $wrap.find('.wcp-page-notes-status').text('');
    });

    $(document).on('click', '.wcp-page-notes-save', function() {
        var $wrap   = $(this).closest('.wcp-page-notes-wrap');
        var pageId  = $wrap.data('page-id');
        var notes   = $wrap.find('.wcp-page-notes-textarea').val();
        var $btn    = $(this);
        var $status = $wrap.find('.wcp-page-notes-status');

        $btn.prop('disabled', true).text('Saving…');

        $.ajax({
            url: wcpThemeData.restUrl + '/pages/' + pageId + '/notes',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ notes: notes }),
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce); },
            success: function(response) {
                var $display = $wrap.find('.wcp-page-notes-display');
                $display.html(
                    notes ? notes : '<span class="wcp-page-notes-placeholder">Add notes…</span>'
                );
                $display.toggleClass('wcp-page-notes-empty', !notes);
                $wrap.find('.wcp-page-notes-editor').hide();
                $display.show();
                $btn.prop('disabled', false).text('Save');
            },
            error: function() {
                $status.text('Could not save — please try again.');
                $btn.prop('disabled', false).text('Save');
            }
        });
    });

    // ==========================================================================
    // Heading / subpage / dynamic list creation
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
    // Dynamic listing creation
    // ==========================================================================

    $(document).on('click', '#wcp-btn-new-dynamic-listing', function() {
        var $form = $('#wcp-create-dynamic-listing-form');
        $form.slideToggle(150, function() {
            if ($form.is(':visible')) $form.find('.wcp-quick-title').focus();
        });
    });

    $(document).on('click', '#wcp-btn-cancel-dynamic-listing', function() {
        var $form = $('#wcp-create-dynamic-listing-form');
        $form.slideUp(150);
        $form[0].reset();
        $form.find('.wcp-quick-status').text('');
    });

    $(document).on('submit', '#wcp-create-dynamic-listing-form', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('button[type="submit"]');
        var $status = $form.find('.wcp-quick-status');
        var pageId = $form.find('[name="page_id"]').val();

        $btn.prop('disabled', true).text('Adding...');
        $status.text('');

        $.ajax({
            url: wcpThemeData.restUrl + '/pages/' + pageId + '/dynamic-listings',
            method: 'POST',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce); },
            contentType: 'application/json',
            data: JSON.stringify({
                title:          $form.find('[name="title"]').val().trim(),
                item_type:      $form.find('[name="item_type"]').val(),
                task_status:    $form.find('[name="task_status"]').val(),
                parent_page_id: parseInt($form.find('[name="parent_page_id"]').val()) || 0,
            }),
            success: function() {
                window.location.reload();
            },
            error: function() {
                $btn.prop('disabled', false).text('Add list');
                $status.text('Error — please try again.');
            }
        });
    });

    $(document).on('click', '.wcp-dynamic-listing-refresh', function() {
        var $btn      = $(this);
        var pageId    = $btn.data('page-id');
        var listingId = $btn.data('listing-id');
        var $listing  = $btn.closest('.wcp-dynamic-listing');
        var $items    = $listing.find('.wcp-dynamic-listing-items');
        var $empty    = $listing.find('.wcp-dynamic-listing-empty');

        $btn.text('[refreshing…]').prop('disabled', true);

        $.ajax({
            url: wcpThemeData.restUrl + '/pages/' + pageId + '/dynamic-listings/' + listingId + '/items',
            method: 'GET',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce); },
            success: function(response) {
                if (response.success) {
                    if (response.count > 0) {
                        if (!$items.length) {
                            $items = $('<div class="wcp-items-list wcp-dynamic-listing-items">').insertBefore($empty.length ? $empty : $listing.find('.wcp-add-heading-wrap'));
                        }
                        $items.html(response.html).show();
                        $empty.hide();
                    } else {
                        $items.hide();
                        if (!$empty.length) {
                            $('<p class="wcp-dynamic-listing-empty">No items match this query.</p>').insertAfter($listing.find('.wcp-dynamic-listing-title'));
                        } else {
                            $empty.show();
                        }
                    }
                }
                $btn.text('[refresh]').prop('disabled', false);
            },
            error: function() {
                $btn.text('[refresh]').prop('disabled', false);
            }
        });
    });

    $(document).on('click', '.wcp-dynamic-listing-delete', function() {
        var $btn = $(this);
        var pageId = $btn.data('page-id');
        var listingId = $btn.data('listing-id');
        if (!confirm('Remove this dynamic list?')) return;

        $.ajax({
            url: wcpThemeData.restUrl + '/pages/' + pageId + '/dynamic-listings/' + listingId,
            method: 'DELETE',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce); },
            success: function() {
                $btn.closest('.wcp-dynamic-listing').remove();
            },
            error: function() {
                alert('Could not remove listing — please try again.');
            }
        });
    });

    // ==========================================================================
    // Goal creation modal
    // ==========================================================================

    var goalModal = {
        pageId: null,
        actionId: null,

        open: function(pageId) {
            this.pageId = pageId;
            this.actionId = null;
            // Reset to step 1
            $('#wcp-goal-step-2').hide();
            $('#wcp-goal-step-1').show();
            $('#wcp-goal-description').val('');
            $('.wcp-goal-step1-status').hide().text('');
            $('#wcp-goal-modal').show();
            $('#wcp-goal-description').focus();
        },

        close: function() {
            $('#wcp-goal-modal').hide();
        },

        showStep2: function(data) {
            this.actionId = data.action_id;
            // Pre-fill title from the first sentence of understanding (user can edit)
            var understanding = data.understanding || '';
            var description = $('#wcp-goal-description').val().trim();
            // Derive a short title suggestion from the user's original description
            var titleSuggestion = description.length > 60 ? description.substring(0, 57) + '...' : description;
            $('#wcp-goal-title').val(titleSuggestion);
            $('#wcp-goal-understanding').val(understanding);

            // Render action item checklist
            var $list = $('#wcp-goal-action-items').empty();
            (data.action_items || []).forEach(function(item, idx) {
                var checkId = 'wcp-goal-item-' + idx;
                var $li = $('<li class="wcp-goal-item-row">');
                var $cb = $('<input type="checkbox">').attr('id', checkId).prop('checked', true)
                    .data('title', item.title).data('content', item.content || '');
                var $label = $('<label>').attr('for', checkId);
                var $title = $('<strong>').text(item.title);
                $label.append($title);
                if (item.content) {
                    $label.append($('<span class="wcp-goal-item-desc">').text(' — ' + item.content));
                }
                $li.append($cb).append($label);
                $list.append($li);
            });

            $('#wcp-goal-step-1').hide();
            $('.wcp-goal-step2-status').hide().text('');
            $('#wcp-goal-step-2').show();
        }
    };

    // Open modal when "new goal" is clicked
    $(document).on('click', '#wcp-btn-new-goal', function() {
        goalModal.open($(this).data('page-id'));
    });

    // Close modal
    $(document).on('click', '.wcp-goal-cancel', function() {
        goalModal.close();
    });

    // Close on overlay click
    $(document).on('click', '#wcp-goal-modal .wcp-modal-overlay', function(e) {
        if ($(e.target).hasClass('wcp-modal-overlay')) {
            goalModal.close();
        }
    });

    // Step 1: Plan with AI
    $(document).on('click', '#wcp-goal-plan-btn', function() {
        var description = $('#wcp-goal-description').val().trim();
        if (!description) {
            $('#wcp-goal-description').focus();
            return;
        }

        var $btn = $(this);
        var $status = $('.wcp-goal-step1-status');
        $btn.prop('disabled', true).text('Thinking...');
        $status.show().removeClass('error').text('Asking AI...');

        $.ajax({
            url: wcpThemeData.restUrl + '/ai/goals/plan',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                goal_description: description,
                page_id: goalModal.pageId
            }),
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce);
            },
            success: function(response) {
                $btn.prop('disabled', false).text('Plan with AI');
                $status.hide();
                if (response.success) {
                    goalModal.showStep2(response);
                } else {
                    $status.show().addClass('error').text('Unexpected response from AI.');
                }
            },
            error: function(xhr) {
                $btn.prop('disabled', false).text('Plan with AI');
                var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'AI request failed.';
                $status.show().addClass('error').text(msg);
            }
        });
    });

    // Step 2: Create Goal
    $(document).on('click', '#wcp-goal-create-btn', function() {
        var title = $('#wcp-goal-title').val().trim();
        var understanding = $('#wcp-goal-understanding').val().trim();

        if (!title) {
            $('#wcp-goal-title').focus();
            return;
        }

        // Collect checked action items
        var actionItems = [];
        $('#wcp-goal-action-items input[type="checkbox"]:checked').each(function() {
            actionItems.push({
                title:   $(this).data('title'),
                content: $(this).data('content') || ''
            });
        });

        var $btn = $(this);
        var $status = $('.wcp-goal-step2-status');
        $btn.prop('disabled', true).text('Creating...');
        $status.show().removeClass('error').text('Creating goal...');

        $.ajax({
            url: wcpThemeData.restUrl + '/goals/create',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                title:        title,
                description:  understanding,
                page_id:      goalModal.pageId,
                parent_id:    goalModal.pageId,
                parent_type:  'page',
                action_items: actionItems,
                action_id:    goalModal.actionId || ''
            }),
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce);
            },
            success: function(response) {
                if (response.success) {
                    goalModal.close();
                    location.reload();
                } else {
                    $btn.prop('disabled', false).text('Create Goal');
                    $status.show().addClass('error').text('Failed to create goal.');
                }
            },
            error: function(xhr) {
                $btn.prop('disabled', false).text('Create Goal');
                var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Create failed.';
                $status.show().addClass('error').text(msg);
            }
        });
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

    // Drag-to-reorder headings
    var $headingsSortable = document.getElementById('wcp-headings-sortable');
    if ($headingsSortable) {
        Sortable.create($headingsSortable, {
            handle: '.wcp-heading-drag-handle',
            animation: 150,
            ghostClass: 'wcp-drag-ghost',
            dragClass: 'wcp-dragging',
            onEnd: function(evt) {
                if (evt.oldIndex === evt.newIndex) return;
                var headingIds = Array.from(
                    $headingsSortable.querySelectorAll('.wcp-heading-group')
                ).map(function(el) { return parseInt(el.dataset.headingId, 10); });

                $.ajax({
                    url: wcpThemeData.restUrl + '/headings/reorder',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({ heading_ids: headingIds }),
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce);
                    }
                });
            }
        });
    }

});
