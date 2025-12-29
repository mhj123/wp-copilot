jQuery(document).ready(function($) {

    // Load context tree
    function loadContextTree() {
        $.ajax({
            url: wcpData.restUrl + '/contexts/tree',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcpData.nonce);
            },
            success: function(response) {
                if (response.success) {
                    renderContextTree(response.tree);
                    populateContextSelector(response.tree);
                }
            }
        });
    }

    function renderContextTree(tree, container, level) {
        container = container || $('#wcp-context-tree');
        level = level || 0;

        if (level === 0) {
            container.html('<ul class="wcp-tree"></ul>');
            container = container.find('ul');
        }

        tree.forEach(function(node) {
            var $li = $('<li>');
            var $link = $('<a>')
                .attr('href', '#')
                .text(node.name + ' (' + node.count + ')')
                .data('term-id', node.term_id)
                .addClass('wcp-tree-node');

            $li.append($link);

            if (node.children && node.children.length > 0) {
                var $ul = $('<ul>');
                $li.append($ul);
                renderContextTree(node.children, $ul, level + 1);
            }

            container.append($li);
        });
    }

    function populateContextSelector(tree, container, level) {
        container = container || $('#wcp-context-selector');
        level = level || 0;

        if (level === 0) {
            container.html('');
        }

        tree.forEach(function(node) {
            var indent = '&nbsp;'.repeat(level * 4);
            var $checkbox = $('<label>')
                .html(indent + '<input type="checkbox" name="contexts[]" value="' + node.term_id + '"> ' + node.name)
                .css('display', 'block');

            container.append($checkbox);

            if (node.children && node.children.length > 0) {
                populateContextSelector(node.children, container, level + 1);
            }
        });
    }

    // Load recent items
    function loadRecentItems() {
        // Simplified: just show a list
        $('#wcp-recent-items').html('<p>Loading recent items...</p>');
    }

    // Quick create form
    $('#wcp-quick-create').on('submit', function(e) {
        e.preventDefault();

        var contexts = [];
        $('#wcp-context-selector input:checked').each(function() {
            contexts.push($(this).val());
        });

        var data = {
            title: $('#wcp-quick-title').val(),
            content: $('#wcp-quick-content').val(),
            contexts: contexts,
            item_type: $('#wcp-item-type').val(),
            priority: $('#wcp-priority').val()
        };

        $.ajax({
            url: wcpData.restUrl + '/items/create',
            method: 'POST',
            data: data,
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcpData.nonce);
            },
            success: function(response) {
                if (response.success) {
                    alert('Item created successfully!');
                    $('#wcp-quick-create')[0].reset();
                    loadRecentItems();
                } else {
                    alert('Error: ' + response.message);
                }
            }
        });
    });

    // AI suggest tags button
    $('#wcp-ai-suggest').on('click', function(e) {
        e.preventDefault();

        var title = $('#wcp-quick-title').val();
        var content = $('#wcp-quick-content').val();

        if (!title && !content) {
            alert('Please enter a title and/or content first.');
            return;
        }

        $.ajax({
            url: wcpData.restUrl + '/ai/suggest-tags',
            method: 'POST',
            data: {
                title: title,
                content: content
            },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcpData.nonce);
            },
            success: function(response) {
                if (response.success) {
                    // Apply suggestions
                    if (response.suggestions.item_type) {
                        $('#wcp-item-type').val(response.suggestions.item_type);
                    }
                    if (response.suggestions.priority) {
                        $('#wcp-priority').val(response.suggestions.priority);
                    }
                    alert('AI suggestions applied!');
                }
            }
        });
    });

    // AI suggest tags in post editor
    $('.wcp-ai-suggest-tags').on('click', function() {
        var postId = $(this).data('post-id');
        var title = $('#title').val();
        var content = $('#content').val() || wp.editor.getContent('content');

        $.ajax({
            url: wcpData.restUrl + '/ai/suggest-tags',
            method: 'POST',
            data: {
                title: title,
                content: content
            },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcpData.nonce);
            },
            success: function(response) {
                if (response.success) {
                    $('#wcp-ai-suggestions').html(
                        '<div class="notice notice-info"><p>AI suggestions ready! (Mock data)</p></div>'
                    );
                }
            }
        });
    });

    // Page AI chat
    $('.wcp-ai-chat').on('click', function() {
        var postId = $(this).data('post-id');
        var prompt = $(this).data('prompt');

        $.ajax({
            url: wcpData.restUrl + '/ai/page-chat',
            method: 'POST',
            data: {
                page_id: postId,
                prompt: prompt
            },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcpData.nonce);
            },
            success: function(response) {
                if (response.success) {
                    $('#wcp-ai-response').html(
                        '<div class="notice notice-success"><p>' + response.response.message + '</p></div>'
                    );
                }
            }
        });
    });

    // Page AI coaching
    $('.wcp-ai-coaching').on('click', function() {
        var postId = $(this).data('post-id');
        var type = $(this).data('type');

        $.ajax({
            url: wcpData.restUrl + '/ai/coaching',
            method: 'POST',
            data: {
                context_id: postId,
                prompt_type: type
            },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcpData.nonce);
            },
            success: function(response) {
                if (response.success) {
                    renderAICandidates(response.action_id, response.candidate_items);
                }
            }
        });
    });

    // Render AI candidate items for acceptance
    function renderAICandidates(actionId, candidates) {
        var html = '<div class="wcp-ai-candidates">';
        html += '<h4>AI-Generated Suggestions (Accept or Dismiss):</h4>';

        candidates.forEach(function(candidate, index) {
            html += '<div class="wcp-candidate" data-index="' + index + '">';
            html += '<h5>' + candidate.title + '</h5>';
            html += '<p>' + candidate.content + '</p>';
            html += '<label><input type="checkbox" class="wcp-accept-candidate" data-index="' + index + '"> Accept</label>';
            html += '</div>';
        });

        html += '<button class="button button-primary wcp-submit-decisions" data-action-id="' + actionId + '">Submit Decisions</button>';
        html += '<button class="button wcp-dismiss-all">Dismiss All</button>';
        html += '</div>';

        $('#wcp-ai-response').html(html);

        // Store candidates for later
        $('#wcp-ai-response').data('candidates', candidates);
    }

    // Submit AI decisions
    $(document).on('click', '.wcp-submit-decisions', function() {
        var actionId = $(this).data('action-id');
        var candidates = $('#wcp-ai-response').data('candidates');
        var accepted = [];
        var dismissed = [];

        $('.wcp-accept-candidate').each(function() {
            var index = $(this).data('index');
            if ($(this).is(':checked')) {
                accepted.push(candidates[index]);
            } else {
                dismissed.push(index);
            }
        });

        $.ajax({
            url: wcpData.restUrl + '/ai/' + actionId + '/decide',
            method: 'POST',
            data: {
                accepted: accepted,
                dismissed: dismissed
            },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcpData.nonce);
            },
            success: function(response) {
                if (response.success) {
                    alert('Decisions saved! ' + response.created_posts.length + ' items created.');
                    $('#wcp-ai-response').html('<p>Thank you! Your decisions have been saved.</p>');
                }
            }
        });
    });

    // Dismiss all
    $(document).on('click', '.wcp-dismiss-all', function() {
        var actionId = $('.wcp-submit-decisions').data('action-id');

        $.ajax({
            url: wcpData.restUrl + '/ai/' + actionId + '/decide',
            method: 'POST',
            data: {
                accepted: [],
                dismissed: ['all']
            },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcpData.nonce);
            },
            success: function(response) {
                $('#wcp-ai-response').html('<p>All suggestions dismissed.</p>');
            }
        });
    });

    // Initialize
    if ($('#wcp-context-tree').length) {
        loadContextTree();
        loadRecentItems();
    }

});
