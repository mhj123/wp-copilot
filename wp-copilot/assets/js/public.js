jQuery(document).ready(function($) {

    // Frontend filtering
    $('.wcp-filter').on('change', function() {
        var filters = {};
        var pageId = $('.wcp-filters').data('page-id');

        $('.wcp-filter').each(function() {
            var filterType = $(this).data('filter');
            var value = $(this).val();
            if (value) {
                filters[filterType] = value;
            }
        });

        // Build query string
        var queryParams = $.param(filters);

        // Fetch filtered items
        $.ajax({
            url: wcpData.restUrl + '/contexts/' + pageId + '/items?' + queryParams,
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcpData.nonce);
            },
            success: function(response) {
                if (response.success) {
                    renderItems(response.items);
                }
            }
        });
    });

    function renderItems(items) {
        var $container = $('.wcp-items-list');
        $container.html('');

        if (items.length === 0) {
            $container.html('<p>No items found.</p>');
            return;
        }

        items.forEach(function(item) {
            var isPinned = item.pinned && item.pinned.includes('yes');
            var isAI = item.id; // Check if AI-generated (simplified)

            var $item = $('<div>').addClass('wcp-item');
            if (isPinned) {
                $item.addClass('wcp-pinned');
            }

            var $title = $('<h3>').html('<a href="' + item.view_url + '">' + item.title + '</a>');
            $item.append($title);

            if (item.item_type && item.item_type.length > 0) {
                var $badge = $('<span>').addClass('wcp-badge wcp-type-' + item.item_type[0]).text(item.item_type[0]);
                $item.append($badge);
            }

            if (item.priority && item.priority.length > 0) {
                var $badge = $('<span>').addClass('wcp-badge wcp-priority-' + item.priority[0]).text(item.priority[0]);
                $item.append($badge);
            }

            var $excerpt = $('<div>').addClass('wcp-excerpt').text(item.excerpt);
            $item.append($excerpt);

            var $meta = $('<div>').addClass('wcp-meta').text(item.date);
            $item.append($meta);

            $container.append($item);
        });
    }

});
