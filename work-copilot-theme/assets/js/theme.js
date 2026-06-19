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

    // ── Dashboard / Structure tab switching ──────────────────────────
    var structureLoaded = false;

    function switchDashTab(tab) {
        $('.wcp-dash-tab').removeClass('active');
        $('.wcp-dash-tab[data-tab="' + tab + '"]').addClass('active');
        $('.wcp-dash-panel').hide();
        $('#wcp-dash-panel-' + tab).show();
        localStorage.setItem('wcp_home_tab', tab);
        if (tab === 'structure' && !structureLoaded && wcpThemeData.isLoggedIn) {
            structureLoaded = true;
            loadStructureTree();
        }
    }

    if ($('.wcp-dash-tab').length) {
        // Restore last-used tab
        var savedTab = localStorage.getItem('wcp_home_tab') || 'dashboard';
        switchDashTab(savedTab);

        $(document).on('click', '.wcp-dash-tab', function() {
            switchDashTab($(this).data('tab'));
        });
    } else if ($('#wcp-structure-tree').length && wcpThemeData.isLoggedIn) {
        // On non-homepage pages that embed the structure tree, load immediately
        if ($('#wcp-structure-tree').is(':visible')) {
            loadStructureTree();
            structureLoaded = true;
        }
    }

    // ==========================================================================
    // Dashboard — activity summary
    // ==========================================================================

    $(document).on('click', '#wcp-dash-summarise-btn', function() {
        var $btn  = $(this);
        var $area = $('#wcp-dash-activity-summary');
        var force = $btn.text().trim().indexOf('Refresh') !== -1;

        $btn.prop('disabled', true).text('Generating…');
        $area.html('<p class="wcp-dash-empty">Thinking…</p>');

        $.ajax({
            url: wcpThemeData.restUrl + '/dashboard/activity-summary',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ force: force }),
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce); },
            success: function(response) {
                $btn.prop('disabled', false).text('Refresh summary');
                if (response.success) {
                    var age = 'just now';
                    $area.html(
                        '<p class="wcp-dash-summary-text">' + response.summary.replace(/\n/g, '<br>') + '</p>'
                        + '<p class="wcp-dash-summary-meta">' + response.post_count + ' items created in the last 7 days</p>'
                    );
                    $('#wcp-dash-activity-card .wcp-dash-summary-age').text(age);
                } else {
                    $area.html('<p class="wcp-dash-empty" style="color:#c0392b;">' + (response.message || 'Failed to generate summary.') + '</p>');
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Refresh summary');
                $area.html('<p class="wcp-dash-empty" style="color:#c0392b;">Connection error — please try again.</p>');
            }
        });
    });

    // Calendar .ics upload
    $(document).on('change', '#wcp-cal-file-input', function() {
        var file = this.files[0];
        if (!file) return;
        var $status = $('#wcp-cal-upload-status');
        $status.text('Uploading…');
        var reader = new FileReader();
        reader.onload = function(e) {
            $.ajax({
                url: wcpThemeData.restUrl + '/calendar/import',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ ics_content: e.target.result }),
                beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce); },
                success: function(response) {
                    if (response.success) {
                        $status.text('Imported ' + response.events_imported + ' events.');
                        setTimeout(function() { location.reload(); }, 800);
                    } else {
                        $status.text('Import failed.');
                    }
                },
                error: function() { $status.text('Upload error — please try again.'); }
            });
        };
        reader.readAsText(file);
    });

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

    // Rapid entry: after adding an item the page reloads to render the new row.
    // Reopen the same quick-add form, keep the chosen type, and put the cursor
    // in the title so another item of the same type can be added straight away.
    (function resumeQuickAdd() {
        var raw;
        try { raw = sessionStorage.getItem('wcpQuickAddResume'); } catch (e) { return; }
        if (!raw) { return; }
        try { sessionStorage.removeItem('wcpQuickAddResume'); } catch (e) {}
        var state;
        try { state = JSON.parse(raw); } catch (e) { return; }
        if (!state || !state.contextId) { return; }

        var $form = $('.wcp-quick-item-form[data-context-id="' + state.contextId + '"]').first();
        if (!$form.length) { return; }
        $form.show();
        if (state.itemType) { $form.find('select[name="item_type"]').val(state.itemType); }
        var $title = $form.find('.wcp-quick-title');
        if ($title.length && $title[0].scrollIntoView) { $title[0].scrollIntoView({ block: 'center' }); }
        $title.focus();
    })();

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
        if ($form.find('input[name="pinned"]').is(':checked')) data.pinned = 'yes';

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
                    // Rapid entry: remember this form + chosen type so that after
                    // the reload we reopen it, ready for the next item.
                    try {
                        sessionStorage.setItem('wcpQuickAddResume', JSON.stringify({
                            contextId: String($form.data('context-id')),
                            itemType: $form.find('select[name="item_type"]').val() || ''
                        }));
                    } catch (e) {}
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
            } else if (filter === 'info') {
                show = type === 'info';
            } else if (filter === 'spec') {
                show = type === 'spec';
            } else {
                show = true;
            }
            $row.toggle(show);
        });
    });

    // Done tasks are hidden from the frontend UI — once a task is marked done,
    // drop its row immediately (it won't return on reload; it's filtered server-
    // side and remains accessible in WP Admin).
    function wcpRemoveDoneRow($row) {
        $row.stop(true, true).fadeOut(250, function() { $(this).remove(); });
    }

    // Deep-link from the dashboard: when the URL carries #wcp-item-<id>, scroll
    // to that item and flash it so the user can find and interact with it.
    function wcpFocusHashItem() {
        var m = (window.location.hash || '').match(/^#wcp-item-(\d+)$/);
        if (!m) { return; }
        var $row = $('#wcp-item-' + m[1]);
        if (!$row.length) { return; }
        $row.show(); // override any active filter so the target is visible
        $('html, body').animate({ scrollTop: $row.offset().top - 80 }, 350);
        $row.addClass('wcp-item-highlight');
        setTimeout(function() { $row.removeClass('wcp-item-highlight'); }, 2500);
    }
    wcpFocusHashItem();

    // Toggle description visibility
    $(document).on('click', '.wcp-toggle-descriptions', function() {
        var $btn = $(this);
        var showing = $btn.hasClass('active');
        $btn.toggleClass('active', !showing);
        $('.wcp-items-section').toggleClass('wcp-show-descriptions', !showing);
    });

    // Actions toggle — hide non-essential row controls
    var actionsHidden = localStorage.getItem('wcp_actions_hidden') === '1';
    if (actionsHidden) {
        $('.wcp-items-section').addClass('wcp-hide-actions');
        $('.wcp-toggle-actions').addClass('active');
    }
    $(document).on('click', '.wcp-toggle-actions', function() {
        actionsHidden = !actionsHidden;
        $(this).toggleClass('active', actionsHidden);
        $('.wcp-items-section').toggleClass('wcp-hide-actions', actionsHidden);
        localStorage.setItem('wcp_actions_hidden', actionsHidden ? '1' : '0');
    });

    // Heading collapse toggle
    var COLLAPSE_KEY = 'wcp_collapsed_headings_' + (window.wcpThemeData && wcpThemeData.pageId ? wcpThemeData.pageId : '0');

    function getCollapsedSet() {
        try { return new Set(JSON.parse(localStorage.getItem(COLLAPSE_KEY) || '[]')); }
        catch(e) { return new Set(); }
    }

    function saveCollapsedSet(set) {
        try { localStorage.setItem(COLLAPSE_KEY, JSON.stringify(Array.from(set))); }
        catch(e) {}
    }

    // Restore collapsed state on load
    getCollapsedSet().forEach(function(hid) {
        var $group = $('.wcp-heading-group[data-heading-id="' + hid + '"]');
        if ($group.length) {
            $group.addClass('wcp-heading-collapsed');
            $group.find('.wcp-heading-collapse-toggle').attr('aria-expanded', 'false');
        }
    });

    $(document).on('click', '.wcp-heading-collapse-toggle', function(e) {
        e.stopPropagation();
        var $btn   = $(this);
        var hid    = $btn.data('heading-id');
        var $group = $btn.closest('.wcp-heading-group');
        var collapsed = $group.hasClass('wcp-heading-collapsed');
        var set    = getCollapsedSet();

        if (collapsed) {
            $group.removeClass('wcp-heading-collapsed');
            $btn.attr('aria-expanded', 'true');
            set.delete(String(hid));
        } else {
            $group.addClass('wcp-heading-collapsed');
            $btn.attr('aria-expanded', 'false');
            set.add(String(hid));
        }
        saveCollapsedSet(set);
    });

    // Inline heading title edit — click title text to edit
    $(document).on('click', '.wcp-heading-title-text', function() {
        var $span  = $(this);
        var $input = $span.siblings('.wcp-heading-title-input');
        $span.hide();
        $input.show().focus().select();
    });

    function saveHeadingTitle($input) {
        var headingId = $input.data('heading-id');
        var title     = $input.val().trim();
        var $span     = $input.siblings('.wcp-heading-title-text');
        if (!title) { $input.hide(); $span.show(); return; }
        $span.text(title).show();
        $input.hide();
        $.ajax({
            url: wcpThemeData.restUrl + '/headings/' + headingId + '/update',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ title: title }),
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce); }
        });
    }

    $(document).on('blur', '.wcp-heading-title-input', function() { saveHeadingTitle($(this)); });
    $(document).on('keydown', '.wcp-heading-title-input', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); saveHeadingTitle($(this)); }
        if (e.key === 'Escape') { $(this).hide().siblings('.wcp-heading-title-text').show(); }
    });

    // Inline item description edit — click description to edit
    $(document).on('click', '.wcp-item-description', function() {
        var $span  = $(this);
        var itemId = $span.data('item-id');
        var text   = $span.text();
        var $ta    = $('<textarea class="wcp-item-description-edit wcp-form-control">')
                        .val(text).attr('rows', 3);
        $span.hide().after($ta);
        $ta.focus();

        function saveDesc() {
            var newVal = $ta.val().trim();
            $span.text(newVal).show();
            $ta.remove();
            if (newVal !== text) {
                updateItem(itemId, { content: newVal });
                text = newVal;
            }
        }

        $ta.on('blur', saveDesc);
        $ta.on('keydown', function(e) {
            if (e.key === 'Escape') { $ta.val(text); saveDesc(); }
        });
    });

    var PRIORITY_ORDER = { 'critical': 0, 'high': 1, 'medium': 2, 'low': 3, '': 4 };

    function sortListByPriority($list) {
        var $rows = $list.find('> .wcp-item-row').get();
        $rows.sort(function(a, b) {
            var pa = PRIORITY_ORDER[$(a).data('priority') || ''] ?? 4;
            var pb = PRIORITY_ORDER[$(b).data('priority') || ''] ?? 4;
            return pa - pb;
        });
        $.each($rows, function(i, row) { $list.append(row); });
    }

    $(document).on('click', '.wcp-sort-priority', function() {
        var $btn   = $(this);
        var active = $btn.hasClass('active');
        var scope  = $btn.data('scope');
        $btn.toggleClass('active', !active);
        if (!active) {
            if (scope === 'listing') {
                var listingId = $btn.data('listing-id');
                $('[data-listing-id="' + listingId + '"].wcp-dynamic-listing .wcp-items-list').each(function() {
                    sortListByPriority($(this));
                });
            } else {
                $('.wcp-items-section .wcp-items-list, .wcp-dynamic-listing .wcp-items-list').each(function() {
                    sortListByPriority($(this));
                });
            }
        } else {
            location.reload();
        }
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

    // Sort items by created date within each items-list container — oldest first
    // (ascending so the sort produces a visible change from the default newest-first WP order)
    function sortListByCreated($list) {
        var $rows = $list.find('> .wcp-item-row').get();
        $rows.sort(function(a, b) {
            var ca = parseInt($(a).data('created'), 10) || 0;
            var cb = parseInt($(b).data('created'), 10) || 0;
            return ca - cb;
        });
        $.each($rows, function(i, row) { $list.append(row); });
    }

    $(document).on('click', '.wcp-sort-created', function() {
        var $btn   = $(this);
        var active = $btn.hasClass('active');
        var scope  = $btn.data('scope');
        $btn.toggleClass('active', !active);
        if (!active) {
            if (scope === 'listing') {
                var listingId = $btn.data('listing-id');
                $('[data-listing-id="' + listingId + '"].wcp-dynamic-listing .wcp-items-list').each(function() {
                    sortListByCreated($(this));
                });
            } else {
                $('.wcp-items-section .wcp-items-list, .wcp-dynamic-listing .wcp-items-list').each(function() {
                    sortListByCreated($(this));
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
    // Open the quick-add form for the list a given item row sits in, focused —
    // so editing a title and pressing Enter flows straight into adding the next
    // item (same rapid-entry behaviour as the add form itself).
    function wcpOpenQuickAddForRow($row) {
        var contextId = $row.closest('.wcp-items-list').data('context-id');
        if (contextId == null || contextId === '') { return; }
        var $form = $('.wcp-quick-item-form[data-context-id="' + contextId + '"]').first();
        if (!$form.length) { return; }
        $form.show();
        $form.find('.wcp-quick-title').focus();
    }

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
            $(this).data('wcpEnter', true); // flag so blur opens the next add form
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
        var viaEnter = $input.data('wcpEnter');
        $input.removeData('wcpEnter');

        if (!newTitle || newTitle === $title.text()) {
            $input.val($title.text()).hide();
            $title.show();
            if (viaEnter) { wcpOpenQuickAddForRow($row); }
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
                if (viaEnter) { wcpOpenQuickAddForRow($row); }
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
        if (done) { wcpRemoveDoneRow($row); }
    });

    $(document).on('change', '.wcp-type-select', function() {
        var itemId = $(this).data('item-id');
        var type = $(this).val();
        updateItem(itemId, { item_type: type });
        var $row = $(this).closest('.wcp-item-row');
        var $statusSelect = $row.find('.wcp-status-select');
        var $specStatusSelect = $row.find('.wcp-spec-status-select');
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
        if (type === 'spec') {
            $specStatusSelect.show();
            // Default spec status to 'draft' if not already set
            if (!$specStatusSelect.val()) {
                $specStatusSelect.val('draft');
                $row.data('spec-status', 'draft');
                updateItem(itemId, { spec_status: 'draft' });
            }
        } else {
            $specStatusSelect.hide().val('');
            $row.data('spec-status', '');
            updateItem(itemId, { spec_status: '' });
        }
    });

    $(document).on('change', '.wcp-spec-status-select', function() {
        var itemId = $(this).data('item-id');
        var status = $(this).val();
        $(this).closest('.wcp-item-row').data('spec-status', status);
        updateItem(itemId, { spec_status: status });
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
        var prio   = $(this).val();
        $(this).closest('.wcp-item-row').data('priority', prio);
        updateItem(itemId, { priority: prio });
    });

    // Pin / unpin — reload so the item moves into or out of the pinned block.
    $(document).on('change', '.wcp-pin-checkbox', function() {
        var $cb    = $(this);
        var itemId = $cb.data('item-id');
        var pinned = $cb.is(':checked') ? 'yes' : 'no';
        $cb.closest('.wcp-item-row').toggleClass('wcp-pinned', pinned === 'yes');
        updateItem(itemId, { pinned: pinned }).always(function() { location.reload(); });
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
        if (done) { wcpRemoveDoneRow($row); }
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
    // Select mode — multi-select items → create goal from selected
    // ==========================================================================

    var selectMode = false;

    $(document).on('click', '#wcp-select-mode-btn', function() {
        selectMode = !selectMode;
        $(this).toggleClass('active', selectMode).text(selectMode ? 'done selecting' : 'select');
        $('.wcp-item-select-cb').toggle(selectMode);
        if (!selectMode) {
            $('.wcp-item-select-cb').prop('checked', false);
            $('#wcp-selection-bar').hide();
        } else {
            $('#wcp-selection-bar').show();
        }
    });

    $(document).on('change', '.wcp-item-select-cb', function() {
        var checked = $('.wcp-item-select-cb:checked');
        var n = checked.length;
        $('#wcp-selection-count').text(n + ' item' + (n !== 1 ? 's' : '') + ' selected');
        $('#wcp-goal-from-selected-btn').prop('disabled', n === 0);
        $('#wcp-delete-selected-btn').prop('disabled', n === 0);
    });

    $(document).on('click', '#wcp-selection-cancel-btn', function() {
        selectMode = false;
        $('#wcp-select-mode-btn').removeClass('active').text('select');
        $('.wcp-item-select-cb').hide().prop('checked', false);
        $('#wcp-selection-bar').hide();
    });

    $(document).on('click', '#wcp-delete-selected-btn', function() {
        var $checked = $('.wcp-item-select-cb:checked');
        var n = $checked.length;
        if (!n) return;
        if (!confirm('Delete ' + n + ' selected item' + (n !== 1 ? 's' : '') + '? This cannot be undone from here.')) return;

        $checked.each(function() {
            var itemId = $(this).data('item-id');
            var $row   = $(this).closest('.wcp-item-row');
            $.ajax({
                url: wcpThemeData.restUrl + '/items/' + itemId + '/delete',
                method: 'POST',
                beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce); },
                success: function(response) {
                    if (response && response.success) {
                        $row.fadeOut(200, function() { $(this).remove(); });
                    }
                }
            });
        });

        // Exit select mode (rows fade out as their requests return).
        $('#wcp-selection-cancel-btn').trigger('click');
    });

    $(document).on('click', '#wcp-goal-from-selected-btn', function() {
        var titles = $('.wcp-item-select-cb:checked').map(function() {
            return $(this).data('item-title');
        }).get();
        var description = 'Goal covering:\n' + titles.map(function(t) { return '- ' + t; }).join('\n');
        var pageId = $('#wcp-selection-bar').data('page-id');
        // Open goal modal with pre-filled description
        $('#wcp-goal-description').val(description);
        goalModal.open(pageId);
        // Exit select mode
        $('#wcp-selection-cancel-btn').trigger('click');
    });

    // ==========================================================================
    // Per-item AI actions
    // ==========================================================================

    // Render a {title, content} rewrite proposal with Accept/Dismiss — shared by
    // the "Improve phrasing" and "Freeform" item AI actions.
    function wcpRenderItemProposal($result, itemId, p) {
        var esc = function(s) { return $('<span>').text(s == null ? '' : s).html(); };
        $result.html(
            '<div class="wcp-item-ai-proposal">'
            + '<strong>' + esc(p.title) + '</strong>'
            + (p.content ? '<br><span style="color:#666;font-size:12px;">' + esc(p.content) + '</span>' : '')
            + '</div>'
            + '<div style="margin-top:6px;">'
            + '<button class="wcp-btn wcp-btn-primary wcp-btn-sm wcp-item-ai-accept" data-item-id="' + itemId + '" data-title="' + esc(p.title) + '" data-content="' + esc(p.content || '') + '">Accept</button>'
            + ' <button class="wcp-edit-link wcp-item-ai-dismiss">Dismiss</button>'
            + '</div>'
        );
    }

    $(document).on('click', '.wcp-item-ai-btn', function() {
        var $row   = $(this).closest('.wcp-item-row');
        var $panel = $row.find('.wcp-item-ai-panel');
        $panel.slideToggle(120);
        $row.find('.wcp-item-ai-result').hide().empty();
        $row.find('.wcp-item-ai-chip').removeClass('active');
    });

    $(document).on('click', '.wcp-item-ai-chip', function() {
        var $chip   = $(this);
        var action  = $chip.data('action');
        var $panel  = $chip.closest('.wcp-item-ai-panel');
        var itemId  = $panel.data('item-id');
        var $result = $panel.find('.wcp-item-ai-result');
        var $row    = $panel.closest('.wcp-item-row');

        $panel.find('.wcp-item-ai-chip').removeClass('active');
        $chip.addClass('active');

        if (action === 'to_goal') {
            // Pre-fill goal modal then close panel
            var pageId = $('input[name="page_id"]').first().val() || wcpThemeData && wcpThemeData.pageId;
            $.ajax({
                url: wcpThemeData.restUrl + '/items/' + itemId + '/ai',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ action: 'to_goal' }),
                beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce); },
                success: function(r) {
                    if (r.success) {
                        $('#wcp-goal-description').val(r.description);
                        goalModal.open(pageId);
                        $panel.slideUp(120);
                    }
                }
            });
            return;
        }

        if (action === 'delegate') {
            $result.show().html(
                '<div class="wcp-delegation-form">'
                + '<textarea class="wcp-delegate-instruction" rows="3" placeholder="Instruction for the Hermes agent…"></textarea>'
                + '<input type="file" class="wcp-delegate-files" multiple>'
                + '<div style="margin-top:6px;">'
                + '<button type="button" class="wcp-btn wcp-btn-primary wcp-btn-sm wcp-delegate-send" data-item-id="' + itemId + '">Delegate</button>'
                + ' <button type="button" class="wcp-edit-link wcp-item-ai-dismiss">Cancel</button>'
                + '</div></div>'
            );
            $result.find('.wcp-delegate-instruction').focus();
            return;
        }

        if (action === 'freeform') {
            // Collect a freeform instruction first, then run on demand.
            $result.show().html(
                '<div class="wcp-item-ai-freeform">'
                + '<textarea class="wcp-freeform-prompt" rows="2" placeholder="Tell the AI what to do — e.g. rephrase this more concisely…"></textarea>'
                + '<div style="margin-top:6px;">'
                + '<button type="button" class="wcp-btn wcp-btn-primary wcp-btn-sm wcp-freeform-run" data-item-id="' + itemId + '">Run</button>'
                + ' <button type="button" class="wcp-edit-link wcp-item-ai-dismiss">Cancel</button>'
                + '</div></div>'
            );
            $result.find('.wcp-freeform-prompt').focus();
            return;
        }

        $result.show().html('<em style="color:#aaa;font-size:12px;">Thinking…</em>');

        $.ajax({
            url: wcpThemeData.restUrl + '/items/' + itemId + '/ai',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ action: action }),
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce); },
            success: function(r) {
                if (!r.success) { $result.html('<em style="color:#c0392b;">Error</em>'); return; }

                if (action === 'improve_phrasing') {
                    wcpRenderItemProposal($result, itemId, r.proposal);
                } else if (action === 'suggest_subtasks') {
                    var items = r.subtasks.map(function(st) {
                        return '<li><label><input type="checkbox" class="wcp-ai-sub-cb" checked data-title="' + $('<span>').text(st).html() + '"> ' + $('<span>').text(st).html() + '</label></li>';
                    }).join('');
                    $result.html(
                        '<ul class="wcp-item-ai-subtask-list">' + items + '</ul>'
                        + '<button class="wcp-btn wcp-btn-primary wcp-btn-sm wcp-item-ai-add-subtasks" data-item-id="' + itemId + '">Add checked</button>'
                        + ' <button class="wcp-edit-link wcp-item-ai-dismiss">Dismiss</button>'
                    );
                } else if (action === 'action_plan') {
                    var html = '<ol class="wcp-action-plan-list">';
                    r.steps.forEach(function(step, i) {
                        html += '<li class="wcp-action-plan-step" data-index="' + i + '">'
                            + '<div class="wcp-ap-title-row">'
                            + '<input class="wcp-ap-title" type="text" value="' + $('<span>').text(step.title).html() + '">'
                            + '<button type="button" class="wcp-ap-remove wcp-edit-link">×</button>'
                            + '</div>'
                            + '<textarea class="wcp-ap-desc">' + $('<span>').text(step.description || '').html() + '</textarea>'
                            + '</li>';
                    });
                    html += '</ol>'
                        + '<div class="wcp-ap-actions">'
                        + '<button type="button" class="wcp-ap-add-step wcp-edit-link">+ add step</button>'
                        + '<button type="button" class="wcp-btn wcp-btn-primary wcp-btn-sm wcp-ap-accept" data-item-id="' + itemId + '">Add as subtasks</button>'
                        + '<button type="button" class="wcp-edit-link wcp-item-ai-dismiss">Dismiss</button>'
                        + '</div>';
                    $result.html(html);
                } else if (action === 'suggest_contexts') {
                    var names = r.context_names.map(function(n) { return '<li>' + $('<span>').text(n).html() + '</li>'; }).join('');
                    $result.html(
                        '<p style="font-size:12px;margin:0 0 6px;">Suggested associations:</p>'
                        + '<ul style="margin:0 0 8px;padding-left:16px;font-size:12px;">' + names + '</ul>'
                        + '<button class="wcp-btn wcp-btn-primary wcp-btn-sm wcp-item-ai-accept-contexts" data-item-id="' + itemId + '" data-ids="' + r.context_ids.join(',') + '">Apply</button>'
                        + ' <button class="wcp-edit-link wcp-item-ai-dismiss">Dismiss</button>'
                    );
                }
            },
            error: function() { $result.html('<em style="color:#c0392b;">Connection error</em>'); }
        });
    });

    // Action plan — add a blank step
    $(document).on('click', '.wcp-ap-add-step', function() {
        var $list = $(this).closest('.wcp-item-ai-result').find('.wcp-action-plan-list');
        var idx   = $list.find('.wcp-action-plan-step').length;
        $list.append(
            '<li class="wcp-action-plan-step" data-index="' + idx + '">'
            + '<div class="wcp-ap-title-row">'
            + '<input class="wcp-ap-title" type="text" placeholder="Step title…">'
            + '<button type="button" class="wcp-ap-remove wcp-edit-link">×</button>'
            + '</div>'
            + '<textarea class="wcp-ap-desc" placeholder="Brief detail or rationale…"></textarea>'
            + '</li>'
        );
        $list.find('.wcp-action-plan-step:last .wcp-ap-title').focus();
    });

    // Action plan — remove a step
    $(document).on('click', '.wcp-ap-remove', function() {
        $(this).closest('.wcp-action-plan-step').remove();
    });

    // Action plan — accept: add each step as a subtask
    $(document).on('click', '.wcp-ap-accept', function() {
        var $btn   = $(this);
        var itemId = $btn.data('item-id');
        var $steps = $btn.closest('.wcp-item-ai-result').find('.wcp-action-plan-step');
        var steps  = [];
        $steps.each(function() {
            var title = $(this).find('.wcp-ap-title').val().trim();
            if (title) steps.push(title);
        });
        if (!steps.length) return;

        $btn.prop('disabled', true).text('Adding…');

        // Add subtasks sequentially to preserve order
        function addNext(i) {
            if (i >= steps.length) {
                $btn.closest('.wcp-item-ai-panel').slideUp(120);
                location.reload();
                return;
            }
            $.ajax({
                url: wcpThemeData.restUrl + '/items/' + itemId + '/subtasks',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ title: steps[i] }),
                beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce); },
                success: function() { addNext(i + 1); },
                error: function() { addNext(i + 1); }
            });
        }
        addNext(0);
    });

    // Accept improved phrasing
    // Run a freeform item instruction and show the result as a rewrite proposal.
    $(document).on('click', '.wcp-freeform-run', function() {
        var $btn    = $(this);
        var itemId  = $btn.data('item-id');
        var $result = $btn.closest('.wcp-item-ai-result');
        var prompt  = $result.find('.wcp-freeform-prompt').val().trim();
        if (!prompt) { $result.find('.wcp-freeform-prompt').focus(); return; }

        $result.html('<em style="color:#aaa;font-size:12px;">Thinking…</em>');
        $.ajax({
            url: wcpThemeData.restUrl + '/items/' + itemId + '/ai',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ action: 'freeform', prompt: prompt }),
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce); },
            success: function(r) {
                if (!r.success || !r.proposal) { $result.html('<em style="color:#c0392b;">Error</em>'); return; }
                wcpRenderItemProposal($result, itemId, r.proposal);
            },
            error: function() { $result.html('<em style="color:#c0392b;">Error</em>'); }
        });
    });

    $(document).on('click', '.wcp-item-ai-accept', function() {
        var $btn  = $(this);
        var id    = $btn.data('item-id');
        var title = $btn.data('title');
        var content = $btn.data('content');
        updateItem(id, { title: title, content: content });
        var $row = $btn.closest('.wcp-item-row');
        $row.find('.wcp-item-title').text(title);
        $btn.closest('.wcp-item-ai-panel').slideUp(120);
    });

    // Add AI-suggested subtasks
    $(document).on('click', '.wcp-item-ai-add-subtasks', function() {
        var $btn   = $(this);
        var itemId = $btn.data('item-id');
        var $panel = $btn.closest('.wcp-item-ai-panel');
        $btn.find('.wcp-ai-sub-cb:checked').each(function() {
            var title = $(this).data('title');
            $.ajax({
                url: wcpThemeData.restUrl + '/items/' + itemId + '/subtasks',
                method: 'POST', contentType: 'application/json',
                data: JSON.stringify({ title: title }),
                beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce); }
            });
        });
        // Also collect from the actual checkboxes in the result
        $panel.find('.wcp-ai-sub-cb:checked').each(function() {
            var title = $(this).data('title');
            $.ajax({
                url: wcpThemeData.restUrl + '/items/' + itemId + '/subtasks',
                method: 'POST', contentType: 'application/json',
                data: JSON.stringify({ title: title }),
                beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce); }
            });
        });
        $panel.slideUp(120);
        location.reload();
    });

    // Apply suggested contexts
    $(document).on('click', '.wcp-item-ai-accept-contexts', function() {
        var $btn = $(this);
        var itemId = $btn.data('item-id');
        var ids = $btn.data('ids').toString().split(',').map(Number).filter(Boolean);
        updateItem(itemId, { contexts: ids });
        $btn.closest('.wcp-item-ai-panel').slideUp(120);
    });

    // Delegate to Hermes agent: submit instruction + files as multipart
    $(document).on('click', '.wcp-delegate-send', function() {
        var $btn    = $(this);
        var itemId  = $btn.data('item-id');
        var $result = $btn.closest('.wcp-item-ai-result');
        var instruction = $result.find('.wcp-delegate-instruction').val().trim();
        var fileInput   = $result.find('.wcp-delegate-files')[0];

        if (!instruction) {
            $result.find('.wcp-delegate-instruction').focus();
            return;
        }

        var fd = new FormData();
        fd.append('instruction', instruction);
        if (fileInput && fileInput.files) {
            for (var i = 0; i < fileInput.files.length; i++) {
                fd.append('files[]', fileInput.files[i]);
            }
        }

        $btn.prop('disabled', true).text('Delegating…');

        $.ajax({
            url: wcpThemeData.delegationRestUrl + '/items/' + itemId + '/delegate',
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce); },
            success: function(r) {
                if (!r.success) {
                    $result.html('<em style="color:#c0392b;">' + $('<span>').text(r.message || 'Error').html() + '</em>');
                    return;
                }
                var html = '<p style="font-size:12px;margin:0;">Delegated <strong>(pending)</strong> — ID ' + $('<span>').text(r.delegation.id).html() + '</p>';
                if (!r.telegram_sent) {
                    html += '<p style="font-size:12px;color:#b7791f;margin:4px 0 0;">Created, but Telegram notification failed: '
                        + $('<span>').text(r.telegram_error || 'unknown error').html() + '</p>';
                }
                $result.html(html);
                setTimeout(function() { location.reload(); }, 1500);
            },
            error: function(xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Connection error';
                $result.html('<em style="color:#c0392b;">' + $('<span>').text(msg).html() + '</em>');
            }
        });
    });

    // Answer an agent's clarification question
    $(document).on('click', '.wcp-delegation-answer-send', function() {
        var $btn         = $(this);
        var delegationId = $btn.data('delegation-id');
        var questionId   = $btn.data('question-id');
        var $question    = $btn.closest('.wcp-delegation-question');
        var answer       = $question.find('.wcp-delegation-answer-input').val().trim();

        if (!answer) {
            $question.find('.wcp-delegation-answer-input').focus();
            return;
        }

        $btn.prop('disabled', true).text('Sending…');

        $.ajax({
            url: wcpThemeData.delegationRestUrl + '/delegations/' + delegationId + '/answer',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ question_id: questionId, answer: answer }),
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce); },
            success: function() { location.reload(); },
            error: function() {
                $btn.prop('disabled', false).text('Send answer');
            }
        });
    });

    // Dismiss any AI result
    $(document).on('click', '.wcp-item-ai-dismiss', function() {
        var $panel = $(this).closest('.wcp-item-ai-panel');
        $panel.find('.wcp-item-ai-result').hide().empty();
        $panel.find('.wcp-item-ai-chip').removeClass('active');
    });

    // ==========================================================================
    // Inline context picker
    // ==========================================================================

    $(document).on('click', '.wcp-item-context-btn', function() {
        var itemId   = $(this).data('item-id');
        var $row     = $(this).closest('.wcp-item-row');
        var $panel   = $row.find('.wcp-item-context-panel');
        var $tree    = $panel.find('.wcp-item-context-tree');

        $panel.slideToggle(120);

        // Lazy-load tree on first open
        if ($panel.is(':hidden') || $tree.find('ul').length > 0) return;

        var preselectedIds = ($row.data('context-ids') || '').toString().split(',').map(Number).filter(Boolean);

        $.ajax({
            url: wcpThemeData.restUrl + '/contexts/tree',
            method: 'GET',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce); },
            success: function(response) {
                if (!response.success) return;
                $tree.html('<ul class="wcp-context-tree"></ul>');
                var $ul = $tree.find('ul');
                renderItemContextTree(response.tree, $ul, preselectedIds);
            }
        });
    });

    function renderItemContextTree(nodes, $container, preselectedIds) {
        nodes.forEach(function(node) {
            var $li = $('<li>');
            var $label = $('<label class="wcp-item-ctx-label">');
            var checked = preselectedIds.indexOf(node.term_id) !== -1;
            var $cb = $('<input type="checkbox">').val(node.term_id).prop('checked', checked);
            $label.append($cb).append($('<span>').text(' ' + node.name));
            $li.append($label);
            if (node.children && node.children.length) {
                var $ul = $('<ul>');
                $li.append($ul);
                renderItemContextTree(node.children, $ul, preselectedIds);
            }
            $container.append($li);
        });
    }

    // Auto-save context on checkbox change
    $(document).on('change', '.wcp-item-context-panel input[type="checkbox"]', function() {
        var $panel  = $(this).closest('.wcp-item-context-panel');
        var itemId  = $panel.data('item-id');
        var ids     = $panel.find('input:checked').map(function() { return parseInt($(this).val()); }).get();
        updateItem(itemId, { contexts: ids });
        // Update data attr for future opens
        $panel.closest('.wcp-item-row').data('context-ids', ids.join(','));
    });

    // ==========================================================================
    // Inline tag editor
    // ==========================================================================

    $(document).on('click', '.wcp-item-tag-btn', function() {
        $(this).closest('.wcp-item-row').find('.wcp-item-tag-panel').slideToggle(120);
    });

    function getItemTags($row) {
        return $row.find('.wcp-item-tag-pill').map(function() {
            return $(this).contents().filter(function() { return this.nodeType === 3; }).text().trim();
        }).get().filter(Boolean);
    }

    $(document).on('submit', '.wcp-item-tag-form', function(e) {
        e.preventDefault();
        var $form  = $(this);
        var $input = $form.find('.wcp-item-tag-input');
        var tag    = $input.val().trim();
        var itemId = $form.data('item-id');
        if (!tag) return;

        var $row   = $form.closest('.wcp-item-row');
        var $pills = $row.find('.wcp-item-tag-pills');
        var existing = getItemTags($row);
        if (existing.indexOf(tag) !== -1) { $input.val(''); return; }

        // Append pill
        var $pill = $('<span class="wcp-item-tag-pill">')
            .text(tag + ' ')
            .append($('<button type="button" class="wcp-item-tag-remove wcp-edit-link">').text('×').data('tag', tag).data('item-id', itemId));
        $pills.append($pill);
        $input.val('');

        var allTags = getItemTags($row);
        updateItem(itemId, { tags: allTags });
        $row.data('tags', allTags.join(','));
    });

    $(document).on('click', '.wcp-item-tag-remove', function() {
        var itemId = $(this).data('item-id');
        var $row   = $(this).closest('.wcp-item-row');
        $(this).closest('.wcp-item-tag-pill').remove();
        var allTags = getItemTags($row);
        updateItem(itemId, { tags: allTags });
        $row.data('tags', allTags.join(','));
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
    // Sidebar nav — collapsible subpages
    // ==========================================================================

    $(document).on('click', '.wcp-nav-toggle', function() {
        var $btn      = $(this);
        var $children = $btn.closest('li').find('> .wcp-nav-children');
        var open      = $children.is(':visible');
        $children.slideToggle(150);
        $btn.text(open ? '▸' : '▾').attr('aria-expanded', !open);
    });

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
