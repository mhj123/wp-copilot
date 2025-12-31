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
        var $button = $(this);
        var postId = $(this).data('post-id');
        var title = $('#title').val();
        var content = $('#content').val() || (typeof tinymce !== 'undefined' && tinymce.get('content') ? tinymce.get('content').getContent() : '');

        if (!title && !content) {
            alert('Please enter a title and/or content first.');
            return;
        }

        $button.prop('disabled', true).text('Getting suggestions...');

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
                if (response.success && response.suggestions) {
                    var suggestions = response.suggestions;
                    var messages = [];

                    // Apply item_type
                    if (suggestions.item_type) {
                        $('input[name="tax_input[item_type][]"][value="' + suggestions.item_type + '"]').prop('checked', true);
                        messages.push('Item Type: ' + suggestions.item_type);
                    }

                    // Apply priority
                    if (suggestions.priority) {
                        $('input[name="tax_input[priority][]"][value="' + suggestions.priority + '"]').prop('checked', true);
                        messages.push('Priority: ' + suggestions.priority);
                    }

                    // Apply tags to WordPress tag field
                    if (suggestions.tags && suggestions.tags.length > 0) {
                        var tagString = suggestions.tags.join(', ');
                        $('#new-tag-post_tag').val(tagString);
                        messages.push('Tags: ' + tagString);

                        // Auto-add tags if tagBox is available
                        if (typeof tagBox !== 'undefined') {
                            suggestions.tags.forEach(function(tag) {
                                tagBox.flushTags($('#post_tag'), false);
                                $('#new-tag-post_tag').val(tag);
                                tagBox.flushTags($('#post_tag'), false);
                            });
                        }
                    }

                    $('#wcp-ai-suggestions').html(
                        '<div class="notice notice-success is-dismissible"><p><strong>AI suggestions applied!</strong><br>' +
                        messages.join('<br>') +
                        '</p></div>'
                    );
                } else {
                    $('#wcp-ai-suggestions').html(
                        '<div class="notice notice-error is-dismissible"><p>Error: ' + (response.message || 'Unknown error') + '</p></div>'
                    );
                }
            },
            error: function(xhr, status, error) {
                $('#wcp-ai-suggestions').html(
                    '<div class="notice notice-error is-dismissible"><p>Error: ' + error + '</p></div>'
                );
            },
            complete: function() {
                $button.prop('disabled', false).text('Suggest Tags');
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

    // Batch generate embeddings
    $('#wcp-batch-generate').on('click', function(e) {
        e.preventDefault();

        var $button = $(this);
        var $status = $('#wcp-batch-status');

        if (!confirm('Generate embeddings for all posts, pages, and headings without embeddings? This may take a few minutes and will consume OpenAI API credits.')) {
            return;
        }

        $button.prop('disabled', true).text('Generating...');
        $status.html('<span style="color: #2271b1;">Starting batch generation...</span>');

        var postTypes = ['post', 'page', 'wcp_heading'];
        var currentTypeIndex = 0;
        var offset = 0;
        var limit = 50;
        var totalProcessed = 0;
        var totalSuccess = 0;
        var totalErrors = 0;

        function processBatch() {
            var currentPostType = postTypes[currentTypeIndex];

            $.ajax({
                url: wcpData.restUrl + '/embeddings/batch',
                method: 'POST',
                data: {
                    post_type: currentPostType,
                    limit: limit,
                    offset: offset
                },
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wcpData.nonce);
                },
                success: function(response) {
                    if (response.success && response.results) {
                        totalProcessed += response.results.total;
                        totalSuccess += response.results.success;
                        totalErrors += response.results.errors;

                        var currentPostType = postTypes[currentTypeIndex];
                        $status.html(
                            '<span style="color: #2271b1;">' +
                            'Processing ' + currentPostType + 's... | ' +
                            'Total: ' + totalProcessed + ' | ' +
                            'Generated: ' + totalSuccess + ' | ' +
                            'Errors: ' + totalErrors +
                            '</span>'
                        );

                        // If we processed a full batch, there might be more of this type
                        if (response.results.total >= limit) {
                            offset += limit;
                            // Continue with next batch after a short delay
                            setTimeout(processBatch, 1000);
                        } else {
                            // Done with this post type, move to next
                            currentTypeIndex++;
                            offset = 0; // Reset offset for next post type

                            if (currentTypeIndex < postTypes.length) {
                                // Process next post type
                                setTimeout(processBatch, 1000);
                            } else {
                                // All done
                                $button.prop('disabled', false).text('Generate Missing Embeddings');
                                $status.html(
                                    '<span style="color: #2ecc71;">' +
                                    '✓ Complete! Generated ' + totalSuccess + ' embeddings' +
                                    (totalErrors > 0 ? ' (' + totalErrors + ' errors)' : '') +
                                    '</span>'
                                );
                                // Reload page after 2 seconds to update stats
                                setTimeout(function() {
                                    location.reload();
                                }, 2000);
                            }
                        }
                    } else {
                        $button.prop('disabled', false).text('Generate Missing Embeddings');
                        $status.html('<span style="color: #e74c3c;">Error: ' + (response.message || 'Unknown error') + '</span>');
                    }
                },
                error: function(xhr, status, error) {
                    $button.prop('disabled', false).text('Generate Missing Embeddings');
                    var message = 'Request failed';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        message = xhr.responseJSON.message;
                    }
                    $status.html('<span style="color: #e74c3c;">Error: ' + message + '</span>');
                }
            });
        }

        // Start the first batch
        processBatch();
    });

    // Initialize
    if ($('#wcp-context-tree').length) {
        loadContextTree();
        loadRecentItems();
    }

    // ========================================
    // Editor AI Assistant
    // ========================================

    var EditorAI = {
        currentResponse: null,

        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Prompt chips
            $(document).on('click', '.wcp-editor-chip', function() {
                var prompt = $(this).data('prompt');
                $('#wcp-editor-ai-prompt').val(prompt);
            });

            // Generate button
            $(document).on('click', '#wcp-editor-ai-generate', function() {
                EditorAI.generate();
            });

            // Enter key in prompt
            $(document).on('keydown', '#wcp-editor-ai-prompt', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    EditorAI.generate();
                }
            });

            // Insert into content
            $(document).on('click', '#wcp-editor-ai-insert', function() {
                EditorAI.insertContent();
            });

            // Discard response
            $(document).on('click', '#wcp-editor-ai-discard', function() {
                EditorAI.discardResponse();
            });

            // Save prompt
            $(document).on('click', '#wcp-editor-ai-save-prompt', function() {
                EditorAI.savePrompt();
            });
        },

        getEditorContent: function() {
            // Try Gutenberg first
            if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
                var blocks = wp.data.select('core/editor').getBlocks();
                if (blocks && blocks.length > 0) {
                    return wp.data.select('core/editor').getEditedPostContent();
                }
            }

            // Try Classic Editor (TinyMCE)
            if (typeof tinymce !== 'undefined' && tinymce.get('content')) {
                return tinymce.get('content').getContent();
            }

            // Fallback to textarea
            return $('#content').val() || '';
        },

        generate: function() {
            var prompt = $('#wcp-editor-ai-prompt').val().trim();
            if (!prompt) {
                return;
            }

            var postId = $('.wcp-editor-ai').data('post-id');
            var contextMode = $('#wcp-editor-context-mode').val();
            var draftContent = this.getEditorContent();

            // Show loading
            $('.wcp-editor-ai-loading').show();
            $('#wcp-editor-ai-generate').prop('disabled', true);
            $('.wcp-editor-ai-response').hide();

            $.ajax({
                url: wcpData.restUrl + '/ai/editor/expand',
                method: 'POST',
                data: {
                    prompt: prompt,
                    draft_content: draftContent,
                    post_id: postId,
                    context_mode: contextMode
                },
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wcpData.nonce);
                },
                success: function(response) {
                    $('.wcp-editor-ai-loading').hide();
                    $('#wcp-editor-ai-generate').prop('disabled', false);

                    if (response.success && response.result) {
                        EditorAI.currentResponse = response.result.content;
                        $('.wcp-editor-ai-response-content').text(response.result.content);
                        $('.wcp-editor-ai-response').show();
                    } else {
                        alert('Error: ' + (response.message || 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    $('.wcp-editor-ai-loading').hide();
                    $('#wcp-editor-ai-generate').prop('disabled', false);
                    alert('Error: ' + error);
                }
            });
        },

        insertContent: function() {
            if (!this.currentResponse) {
                return;
            }

            var content = this.currentResponse;

            // Try Gutenberg first
            if (typeof wp !== 'undefined' && wp.data && wp.data.dispatch('core/block-editor')) {
                // Create a new paragraph block with the content
                var block = wp.blocks.createBlock('core/paragraph', {
                    content: content
                });

                // Insert at the end
                wp.data.dispatch('core/block-editor').insertBlocks([block]);

                this.discardResponse();
                return;
            }

            // Try Classic Editor (TinyMCE)
            if (typeof tinymce !== 'undefined' && tinymce.get('content')) {
                var editor = tinymce.get('content');
                editor.execCommand('mceInsertContent', false, '<p>' + content.replace(/\n/g, '</p><p>') + '</p>');
                this.discardResponse();
                return;
            }

            // Fallback to textarea
            var $textarea = $('#content');
            if ($textarea.length) {
                var current = $textarea.val();
                $textarea.val(current + '\n\n' + content);
                this.discardResponse();
            }
        },

        discardResponse: function() {
            this.currentResponse = null;
            $('.wcp-editor-ai-response').hide();
            $('.wcp-editor-ai-response-content').text('');
        },

        savePrompt: function() {
            var prompt = $('#wcp-editor-ai-prompt').val().trim();
            if (!prompt) {
                return;
            }

            var label = window.prompt('Enter a short label for this prompt (max 20 chars):');
            if (!label) {
                return;
            }

            label = label.substring(0, 20);

            $.ajax({
                url: wcpData.restUrl + '/prompts',
                method: 'POST',
                data: {
                    label: label,
                    prompt: prompt
                },
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wcpData.nonce);
                },
                success: function(response) {
                    if (response.success) {
                        // Add new chip to UI
                        var $chip = $('<button type="button" class="wcp-editor-chip"></button>')
                            .text(label)
                            .data('prompt', prompt);
                        $('.wcp-editor-ai-chips').append($chip);
                        alert('Prompt saved!');
                    } else {
                        alert('Error: ' + (response.message || 'Failed to save'));
                    }
                }
            });
        }
    };

    // Initialize Editor AI if on post/page edit screen
    if ($('.wcp-editor-ai').length) {
        EditorAI.init();
    }

});
