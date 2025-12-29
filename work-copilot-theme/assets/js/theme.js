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

});
