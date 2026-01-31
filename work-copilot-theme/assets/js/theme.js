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

    // Show/hide general item form
    $(document).on('click', '#wcp-btn-add-item-general', function() {
        var $form = $('#wcp-create-item-form');
        var $container = $('#wcp-item-contexts');

        $form.slideToggle();

        // Load context tree if not already loaded and form is now visible
        if ($form.is(':visible') && $container.find('ul').length === 0) {
            loadContextTree($container);
        }
    });

    $(document).on('click', '#wcp-btn-cancel-general-item', function() {
        $('#wcp-create-item-form').slideUp();
        $('#wcp-create-item-form')[0].reset();
    });

    // Create ItemPost form submission
    $('#wcp-create-item-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $status = $('.wcp-form-status');
        var $submitBtn = $form.find('button[type="submit"]');

        // Get form data
        var title = $('#wcp-item-title').val();
        var content = $('#wcp-item-content').val();
        var itemType = $('#wcp-item-type').val();
        var priority = $('#wcp-item-priority').val();
        var tagsInput = $('#wcp-item-tags').val();
        var tags = tagsInput ? tagsInput.split(',').map(function(tag) {
            return tag.trim();
        }) : [];

        // Get selected contexts
        var contexts = [];
        $('#wcp-item-contexts input[name="contexts[]"]:checked').each(function() {
            contexts.push($(this).val());
        });

        if (contexts.length === 0) {
            $status.addClass('error').text('Please select at least one context.');
            return;
        }

        $submitBtn.prop('disabled', true).text('Creating...');
        $status.removeClass('success error').text('');

        // Create the item
        var data = {
            title: title,
            content: content,
            contexts: contexts,
            item_type: itemType,
            priority: priority,
            tags: tags
        };

        $.ajax({
            url: wcpThemeData.restUrl + '/items/create',
            method: 'POST',
            data: data,
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce);
            },
            success: function(response) {
                if (response.success) {
                    $status.addClass('success').text('Item created successfully!');
                    $form[0].reset();

                    // Reload context tree and page after 1 second
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    $status.addClass('error').text('Error: ' + response.message);
                    $submitBtn.prop('disabled', false).text('Create Item');
                }
            },
            error: function() {
                $status.addClass('error').text('Error creating item. Please try again.');
                $submitBtn.prop('disabled', false).text('Create Item');
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

    // Toggle Heading creation form
    $(document).on('click', '#wcp-btn-new-heading', function() {
        $('#wcp-create-heading-form').slideToggle();
    });

    $(document).on('click', '#wcp-btn-cancel-heading', function() {
        $('#wcp-create-heading-form').slideUp();
        $('#wcp-create-heading-form')[0].reset();
    });

    // Submit Heading creation form
    $(document).on('submit', '#wcp-create-heading-form', function(e) {
        e.preventDefault();

        var pageId = $(this).find('input[name="page_id"]').val();
        var title = $(this).find('input[name="title"]').val();
        var content = $(this).find('textarea[name="content"]').val();

        createHeading(pageId, title, content);
    });

    // Toggle Heading section (expand/collapse)
    $(document).on('click', '.wcp-heading-title', function() {
        var $section = $(this).closest('.wcp-heading-section');
        var $body = $section.find('.wcp-heading-body');
        var $icon = $section.find('.wcp-toggle-icon');

        $body.slideToggle();
        $icon.text($icon.text() === '▶' ? '▼' : '▶');
    });

    // Show/hide Heading item form
    $(document).on('click', '.wcp-btn-add-item', function() {
        var headingId = $(this).data('heading-id');
        var $form = $('.wcp-heading-item-form[data-heading-id="' + headingId + '"]');
        var $container = $form.find('.wcp-heading-contexts');
        var contextId = $form.data('context-id');

        // Toggle form
        $form.slideToggle();

        // Load context tree if not already loaded
        if ($form.is(':visible') && $container.find('ul').length === 0) {
            loadContextTree($container, contextId);
        }
    });

    $(document).on('click', '.wcp-btn-cancel-item', function() {
        var $form = $(this).closest('.wcp-heading-item-form');
        $form.slideUp();
        $form[0].reset();
    });

    // Submit Heading item form
    $(document).on('submit', '.wcp-heading-item-form', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $submitBtn = $form.find('button[type="submit"]');

        // Collect form data
        var data = {
            title: $form.find('input[name="title"]').val(),
            content: $form.find('textarea[name="content"]').val(),
            contexts: [],
            item_type: $form.find('select[name="item_type"]').val(),
            priority: $form.find('select[name="priority"]').val(),
            tags: $form.find('input[name="tags"]').val().split(',').map(function(t) { return t.trim(); }).filter(Boolean)
        };

        // Collect checked context checkboxes
        $form.find('.wcp-heading-contexts input[type="checkbox"]:checked').each(function() {
            data.contexts.push($(this).val());
        });

        if (data.contexts.length === 0) {
            alert('Please select at least one context.');
            return;
        }

        $submitBtn.prop('disabled', true).text('Creating...');

        // Submit via existing /items/create endpoint
        $.ajax({
            url: wcpThemeData.restUrl + '/items/create',
            method: 'POST',
            data: data,
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce);
            },
            success: function(response) {
                if (response.success) {
                    location.reload(); // Reload to show new item
                }
            },
            error: function(xhr) {
                alert('Error creating item: ' + (xhr.responseJSON?.message || 'Unknown error'));
                $submitBtn.prop('disabled', false).text('Create Item');
            }
        });
    });

});
