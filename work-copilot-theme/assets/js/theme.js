jQuery(document).ready(function($) {

    // Create ItemPost form submission
    $('#wcp-create-item-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $status = $('.wcp-form-status');
        var $submitBtn = $form.find('button[type="submit"]');

        // Get current page context
        var pageId = $form.find('input[name="page_id"]').val();

        // Get form data
        var title = $('#wcp-item-title').val();
        var content = $('#wcp-item-content').val();
        var itemType = $('#wcp-item-type').val();
        var priority = $('#wcp-item-priority').val();
        var tagsInput = $('#wcp-item-tags').val();
        var tags = tagsInput ? tagsInput.split(',').map(function(tag) {
            return tag.trim();
        }) : [];

        // Get context term for this page
        $.ajax({
            url: wcpThemeData.restUrl + '/contexts/tree',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce);
                $submitBtn.prop('disabled', true).text('Creating...');
                $status.removeClass('success error').text('');
            },
            success: function(response) {
                // Find context term ID for current page
                var contextTermId = findContextTermForPage(response.tree, pageId);

                if (!contextTermId) {
                    $status.addClass('error').text('Error: Could not find context for this page.');
                    $submitBtn.prop('disabled', false).text('Create Item');
                    return;
                }

                // Create the item
                createItem(contextTermId, title, content, itemType, priority, tags);
            },
            error: function() {
                $status.addClass('error').text('Error loading page context.');
                $submitBtn.prop('disabled', false).text('Create Item');
            }
        });

        function findContextTermForPage(tree, targetPageId) {
            for (var i = 0; i < tree.length; i++) {
                var node = tree[i];
                if (node.ref_type === 'page' && node.ref_id == targetPageId) {
                    return node.term_id;
                }
                if (node.children && node.children.length > 0) {
                    var found = findContextTermForPage(node.children, targetPageId);
                    if (found) return found;
                }
            }
            return null;
        }

        function createItem(contextTermId, title, content, itemType, priority, tags) {
            var data = {
                title: title,
                content: content,
                contexts: [contextTermId],
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

                        // Reload page after 1 second to show new item
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
        }
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

});
