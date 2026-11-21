/**
 * Story map — a full-screen board for a Page or a Heading.
 *
 * Columns run left to right (a page's headings, or a heading's top-level
 * items), cards sit underneath, and horizontal release lines cut across every
 * column. Cards above a line are in that release; cards below the last line
 * are unscheduled.
 *
 * Data comes from the plugin (GET/POST /story-map/{page|heading}/{id} — see
 * WCP_Story_Map). Card order is the items' normal order and a card's column
 * is its normal filing, so dragging here changes the page view too. Release
 * lines are the only thing the map owns.
 *
 * Structured like slideshow.js: one controller object, an `isOpen` guard on
 * the document-level key handler, and delegated click handlers.
 */

window.WcpStoryMap = (function ($) {
    'use strict';

    var UNSCHEDULED = '';   // slice key for cards below the last release line
    var SAVE_DELAY  = 500;  // ms of quiet before a save is sent

    var map = {
        isOpen: false,
        dirty: false,        // something was saved this session — the page behind is stale
        lastFocused: null,
        $overlay: null,
        sortables: [],
        saveTimer: null,
        saving: false,
        saveQueued: false,

        scope: 'page',
        id: 0,
        title: '',
        columns: [],         // [{ key, title, url? }]
        items: {},           // id -> card data
        releases: [],        // [{ id, name }] top to bottom
        cells: {},           // sliceKey -> columnKey -> [item ids]

        hiddenTypes: {},     // type slug -> true when filtered out
        showSubitems: true,

        openForPage: function (pageId) { load('page', pageId); },
        openForHeading: function (headingId) { load('heading', headingId); },

        close: function () {
            if (!this.isOpen) { return; }
            var self = this;

            // Don't lose a drag made in the last half second.
            flushSave(function () {
                self.isOpen = false;
                destroySortables();
                if (self.$overlay) { self.$overlay.remove(); self.$overlay = null; }
                $('body').removeClass('wcp-sm-open');

                if (self.dirty) {
                    // Order and filing changed underneath the page view.
                    window.location.reload();
                    return;
                }
                if (self.lastFocused && typeof self.lastFocused.focus === 'function') {
                    self.lastFocused.focus();
                }
                self.lastFocused = null;
            });
        }
    };

    // ----------------------------------------------------------------------
    // Loading
    // ----------------------------------------------------------------------

    function load(scope, id) {
        $.ajax({
            url: wcpThemeData.restUrl + '/story-map/' + scope + '/' + id,
            method: 'GET',
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce); },
            success: function (resp) { open(resp || {}); },
            error: function (xhr) {
                var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Could not load the story map.';
                window.alert(msg);
            }
        });
    }

    function open(resp) {
        map.scope    = resp.scope === 'heading' ? 'heading' : 'page';
        map.id       = resp.id;
        map.title    = resp.title || '';
        map.releases = (resp.releases || []).map(function (r) { return { id: r.id, name: r.name }; });
        map.columns  = [];
        map.items    = {};
        map.cells    = {};
        map.dirty    = false;

        var assignments = resp.assignments || {};
        var known = {};
        map.releases.forEach(function (r) { known[r.id] = true; });

        sliceKeys().forEach(function (key) { map.cells[key] = {}; });

        (resp.columns || []).forEach(function (col) {
            map.columns.push({ key: col.key, title: col.title, url: col.url || '' });
            sliceKeys().forEach(function (key) { map.cells[key][col.key] = []; });

            (col.items || []).forEach(function (item) {
                map.items[item.id] = item;
                var release = assignments[item.id];
                var slice = (release && known[release]) ? release : UNSCHEDULED;
                map.cells[slice][col.key].push(item.id);
            });
        });

        try {
            map.hiddenTypes = JSON.parse(localStorage.getItem('wcp_storymap_hidden_types') || '{}') || {};
            map.showSubitems = localStorage.getItem('wcp_storymap_subitems') !== '0';
        } catch (e) {
            map.hiddenTypes = {};
            map.showSubitems = true;
        }

        map.lastFocused = document.activeElement;
        map.isOpen = true;
        $('body').addClass('wcp-sm-open');
        render();
    }

    /** Slice keys top to bottom: every release, then the unscheduled slice. */
    function sliceKeys() {
        return map.releases.map(function (r) { return r.id; }).concat([UNSCHEDULED]);
    }

    function typeOf(item) { return item.type || 'untyped'; }

    function isVisible(id) {
        var item = map.items[id];
        return !!item && !map.hiddenTypes[typeOf(item)];
    }

    // ----------------------------------------------------------------------
    // Rendering
    // ----------------------------------------------------------------------

    function render() {
        destroySortables();

        if (!map.$overlay) {
            map.$overlay = $('<div class="wcp-sm-overlay" role="dialog" aria-modal="true" aria-label="Story map">');
            // Pick up the section accent the page behind is using.
            var accent = getComputedStyle(document.querySelector('.wcp-theme-container') || document.body).getPropertyValue('--wcp-accent');
            if (accent && accent.trim()) { map.$overlay.css('--wcp-sm-accent', accent.trim()); }
            $('body').append(map.$overlay);
        }

        var scrollLeft = map.$overlay.find('.wcp-sm-scroll').scrollLeft() || 0;
        var scrollTop  = map.$overlay.find('.wcp-sm-scroll').scrollTop() || 0;
        var status     = map.$overlay.find('.wcp-sm-status').text();

        map.$overlay.empty().append(renderToolbar(status));

        var $scroll = $('<div class="wcp-sm-scroll">');
        if (!map.columns.length) {
            $scroll.append($('<p class="wcp-sm-empty-board">').text(
                map.scope === 'heading'
                    ? 'This heading has no items yet. Each top-level item becomes a column, with its sub-items as cards.'
                    : 'This page has no headings or items yet. Each heading becomes a column, with its items as cards.'
            ));
        } else {
            $scroll.append(renderBoard());
        }
        map.$overlay.append($scroll);
        $scroll.scrollLeft(scrollLeft).scrollTop(scrollTop);

        initSortables();
    }

    function renderToolbar(status) {
        var $bar = $('<header class="wcp-sm-toolbar">');

        $bar.append(
            $('<div class="wcp-sm-heading">')
                .append($('<span class="wcp-sm-kicker">').text('Story map'))
                .append($('<h2 class="wcp-sm-title">').text(map.title))
        );

        var $controls = $('<div class="wcp-sm-controls">');

        // Type filter: one chip per item type actually on the board.
        var types = {};
        Object.keys(map.items).forEach(function (id) { types[typeOf(map.items[id])] = true; });
        var typeList = Object.keys(types).sort();
        if (typeList.length > 1) {
            var $types = $('<div class="wcp-sm-chips" role="group" aria-label="Item types shown">');
            typeList.forEach(function (type) {
                var on = !map.hiddenTypes[type];
                $types.append(
                    $('<button type="button" class="wcp-sm-chip wcp-sm-type-toggle">')
                        .attr('data-type', type)
                        .attr('aria-pressed', on ? 'true' : 'false')
                        .text(type === 'untyped' ? 'no type' : type)
                );
            });
            $controls.append($types);
        }

        if (map.scope === 'page') {
            $controls.append(
                $('<button type="button" class="wcp-sm-chip wcp-sm-subitems-toggle">')
                    .attr('aria-pressed', map.showSubitems ? 'true' : 'false')
                    .text('sub-items')
            );
        }

        $controls.append($('<button type="button" class="wcp-sm-btn wcp-sm-add-release">').text('+ Release line'));
        $controls.append($('<span class="wcp-sm-status" role="status" aria-live="polite">').text(status || ''));
        $controls.append($('<button type="button" class="wcp-sm-btn wcp-sm-close">').text('Close'));

        return $bar.append($controls);
    }

    function renderBoard() {
        var $board = $('<div class="wcp-sm-board">').css('--wcp-sm-cols', map.columns.length);

        // Header row
        $board.append($('<div class="wcp-sm-corner">'));
        map.columns.forEach(function (col) {
            var count = 0;
            sliceKeys().forEach(function (key) { count += map.cells[key][col.key].filter(isVisible).length; });

            var $title = col.url
                ? $('<a class="wcp-sm-col-title" target="_blank" rel="noopener">').attr('href', col.url)
                : $('<span class="wcp-sm-col-title">');
            $board.append(
                $('<div class="wcp-sm-col-head">')
                    .toggleClass('is-page-column', col.key === 'page')
                    .append($title.text(col.title))
                    .append($('<span class="wcp-sm-col-count">').text(count))
            );
        });

        // One row per slice
        var keys = sliceKeys();
        keys.forEach(function (sliceKey, sliceIndex) {
            var isRelease = sliceKey !== UNSCHEDULED;
            var release = isRelease ? map.releases[sliceIndex] : null;

            var $gutter = $('<div class="wcp-sm-gutter">').toggleClass('has-line', isRelease);
            if (isRelease) {
                $gutter.append(
                    $('<div class="wcp-sm-release">').attr('data-release', release.id)
                        .append($('<button type="button" class="wcp-sm-release-name" title="Rename">').text(release.name))
                        .append(
                            $('<span class="wcp-sm-release-actions">')
                                .append(iconButton('wcp-sm-release-up', '↑', 'Move release up').prop('disabled', sliceIndex === 0))
                                .append(iconButton('wcp-sm-release-down', '↓', 'Move release down').prop('disabled', sliceIndex === map.releases.length - 1))
                                .append(iconButton('wcp-sm-release-delete', '×', 'Remove this release line'))
                        )
                );
            } else {
                $gutter.append($('<div class="wcp-sm-release wcp-sm-release-later">').text(map.releases.length ? 'Later' : 'All items'));
            }
            $board.append($gutter);

            map.columns.forEach(function (col) {
                var $cell = $('<div class="wcp-sm-cell">').toggleClass('has-line', isRelease);
                var $list = $('<div class="wcp-sm-list">').attr('data-slice', sliceKey).attr('data-col', col.key);

                map.cells[sliceKey][col.key].forEach(function (id) {
                    if (isVisible(id)) { $list.append(renderCard(map.items[id])); }
                });
                $cell.append($list);

                if (isRelease) {
                    // The line itself, in this column: nudge it past one card.
                    var above = map.cells[sliceKey][col.key].filter(isVisible).length;
                    var below = 0;
                    keys.slice(sliceIndex + 1).forEach(function (k) { below += map.cells[k][col.key].filter(isVisible).length; });
                    $cell.append(
                        $('<div class="wcp-sm-line-nudge">').attr('data-slice', sliceKey).attr('data-col', col.key)
                            .append(iconButton('wcp-sm-line-up', '▲', 'Move the ' + release.name + ' line up one card in ' + col.title).prop('disabled', !above))
                            .append(iconButton('wcp-sm-line-down', '▼', 'Move the ' + release.name + ' line down one card in ' + col.title).prop('disabled', !below))
                    );
                }
                $board.append($cell);
            });
        });

        return $board;
    }

    function iconButton(cls, glyph, label) {
        return $('<button type="button" class="wcp-sm-icon">').addClass(cls).attr('aria-label', label).attr('title', label).text(glyph);
    }

    function renderCard(item) {
        var $card = $('<article class="wcp-sm-card" tabindex="0">')
            .attr('data-id', item.id)
            .attr('data-type', typeOf(item))
            .attr('aria-label', item.title + '. Alt plus arrow keys move this card.');

        $card.append($('<div class="wcp-sm-card-title">').text(item.title || '(untitled)'));

        var $meta = $('<div class="wcp-sm-card-meta">');
        if (item.type) { $meta.append($('<span class="wcp-sm-tag">').text(item.type)); }
        if (item.priority === 'high') { $meta.append($('<span class="wcp-sm-tag wcp-sm-tag-high">').text('high')); }
        if (item.status && item.status !== 'to-do') { $meta.append($('<span class="wcp-sm-tag">').text(item.status.replace(/-/g, ' '))); }
        if (item.due) { $meta.append($('<span class="wcp-sm-tag">').text('due ' + item.due)); }
        if (item.pinned) { $meta.append($('<span class="wcp-sm-tag">').text('pinned')); }

        var children = item.children || [];
        if (children.length && !map.showSubitems) {
            $meta.append($('<span class="wcp-sm-tag wcp-sm-tag-quiet">').text(children.length + (children.length === 1 ? ' sub-item' : ' sub-items')));
        }
        if (item.url) {
            $meta.append($('<a class="wcp-sm-card-open" target="_blank" rel="noopener">').attr('href', item.url).attr('aria-label', 'Open ' + item.title).text('open'));
        }
        $card.append($meta);

        if (children.length && map.showSubitems) {
            var $ul = $('<ul class="wcp-sm-subitems">');
            children.forEach(function (child) { $ul.append($('<li>').text(child.title || '(untitled)')); });
            $card.append($ul);
        }

        return $card;
    }

    // ----------------------------------------------------------------------
    // Drag and drop
    // ----------------------------------------------------------------------

    function initSortables() {
        if (typeof Sortable === 'undefined' || !map.$overlay) { return; }

        map.$overlay.find('.wcp-sm-list').each(function () {
            map.sortables.push(new Sortable(this, {
                group: 'wcp-story-map',
                animation: 120,
                draggable: '.wcp-sm-card',
                filter: '.wcp-sm-card-open',
                preventOnFilter: false,
                ghostClass: 'wcp-sm-card-ghost',
                dragClass: 'wcp-sm-card-drag',
                emptyInsertThreshold: 24,
                onEnd: function () {
                    readCellsFromDom();
                    render();
                    scheduleSave();
                }
            }));
        });
    }

    function destroySortables() {
        map.sortables.forEach(function (s) { try { s.destroy(); } catch (e) {} });
        map.sortables = [];
    }

    /**
     * Rebuild map.cells from where the cards now sit. Cards that are filtered
     * out are not in the DOM, so each one is put back in its own cell right
     * after the visible card it used to follow.
     */
    function readCellsFromDom() {
        var next = {};

        map.$overlay.find('.wcp-sm-list').each(function () {
            var slice = String($(this).attr('data-slice'));
            var col   = String($(this).attr('data-col'));
            var visible = $(this).children('.wcp-sm-card').map(function () { return parseInt($(this).attr('data-id'), 10); }).get();

            next[slice] = next[slice] || {};
            next[slice][col] = mergeHidden(visible, map.cells[slice][col]);
        });

        map.cells = next;
    }

    function mergeHidden(visible, previous) {
        var result = visible.slice();
        var anchor = null;       // last visible id seen while walking the old order
        var inserted = {};       // anchor -> how many hidden cards already placed after it

        previous.forEach(function (id) {
            if (isVisible(id)) {
                anchor = id;
                return;
            }
            var at = anchor === null ? -1 : result.indexOf(anchor);
            var key = at === -1 ? 'start' : String(anchor);
            inserted[key] = inserted[key] || 0;
            result.splice(at + 1 + inserted[key], 0, id);
            inserted[key]++;
        });

        return result;
    }

    // ----------------------------------------------------------------------
    // Changes
    // ----------------------------------------------------------------------

    function changed() {
        render();
        scheduleSave();
    }

    /** Move a release line by one card in one column. */
    function nudgeLine(sliceKey, colKey, direction) {
        var keys  = sliceKeys();
        var below = keys[keys.indexOf(sliceKey) + 1];
        var upper = map.cells[sliceKey][colKey];
        var lower = map.cells[below][colKey];

        if (direction === 'up') {
            // Line rises: the last visible card above it drops below.
            for (var i = upper.length - 1; i >= 0; i--) {
                if (isVisible(upper[i])) {
                    lower.unshift.apply(lower, upper.splice(i));
                    return changed();
                }
            }
        } else {
            // Line falls: the next visible card beneath it joins the release.
            // "Beneath" is the nearest slice that has one — a release with no
            // cards in this column takes up no room, so the line passes it.
            var lowerKeys = keys.slice(keys.indexOf(sliceKey) + 1);
            for (var k = 0; k < lowerKeys.length; k++) {
                var candidates = map.cells[lowerKeys[k]][colKey];
                for (var j = 0; j < candidates.length; j++) {
                    if (isVisible(candidates[j])) {
                        upper.push.apply(upper, candidates.splice(0, j + 1));
                        return changed();
                    }
                }
            }
        }
    }

    function addRelease() {
        var id = 'r' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
        map.releases.push({ id: id, name: 'Release ' + (map.releases.length + 1) });
        map.cells[id] = {};
        map.columns.forEach(function (col) { map.cells[id][col.key] = []; });
        changed();

        // Straight into naming it.
        map.$overlay.find('.wcp-sm-release[data-release="' + id + '"] .wcp-sm-release-name').trigger('click');
    }

    function deleteRelease(id) {
        var keys  = sliceKeys();
        var below = keys[keys.indexOf(id) + 1];

        // The line goes; its cards stay, at the top of whatever was beneath it.
        map.columns.forEach(function (col) {
            map.cells[below][col.key] = map.cells[id][col.key].concat(map.cells[below][col.key]);
        });
        delete map.cells[id];
        map.releases = map.releases.filter(function (r) { return r.id !== id; });
        changed();
    }

    function moveRelease(id, delta) {
        var from = map.releases.findIndex(function (r) { return r.id === id; });
        var to = from + delta;
        if (from < 0 || to < 0 || to >= map.releases.length) { return; }
        var moved = map.releases.splice(from, 1)[0];
        map.releases.splice(to, 0, moved);
        changed();
        map.$overlay.find('.wcp-sm-release[data-release="' + id + '"] .' + (delta < 0 ? 'wcp-sm-release-up' : 'wcp-sm-release-down')).trigger('focus');
    }

    function startRename($name) {
        var id = $name.closest('.wcp-sm-release').attr('data-release');
        var release = map.releases.find(function (r) { return r.id === id; });
        if (!release) { return; }

        var $input = $('<input type="text" class="wcp-sm-release-input" maxlength="80" aria-label="Release name">').val(release.name);
        $name.replaceWith($input);
        $input.trigger('focus').trigger('select');

        var done = false;
        function finish(save) {
            if (done) { return; }
            done = true;
            var value = $.trim($input.val());
            if (save && value && value !== release.name) {
                release.name = value;
                changed();
            } else {
                render();
            }
        }
        $input.on('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); finish(true); }
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); finish(false); }
        }).on('blur', function () { finish(true); });
    }

    /**
     * Keyboard equivalent of dragging: Alt+↑/↓ within the column (crossing
     * release lines), Alt+←/→ to the neighbouring column.
     */
    function moveCardByKey(id, key) {
        var keys = sliceKeys();
        var slice = null, col = null;

        keys.forEach(function (s) {
            map.columns.forEach(function (c) {
                if (map.cells[s][c.key].indexOf(id) !== -1) { slice = s; col = c.key; }
            });
        });
        if (slice === null) { return; }

        var list  = map.cells[slice][col];
        var index = list.indexOf(id);
        var sliceIndex = keys.indexOf(slice);

        if (key === 'ArrowUp' || key === 'ArrowDown') {
            var step = key === 'ArrowUp' ? -1 : 1;
            // Skip over filtered-out neighbours so every press visibly moves the card.
            var target = index + step;
            while (target >= 0 && target < list.length && !isVisible(list[target])) { target += step; }

            if (target >= 0 && target < list.length) {
                list.splice(index, 1);
                list.splice(target, 0, id);
            } else if (step < 0 && sliceIndex > 0) {
                list.splice(index, 1);
                map.cells[keys[sliceIndex - 1]][col].push(id);
            } else if (step > 0 && sliceIndex < keys.length - 1) {
                list.splice(index, 1);
                map.cells[keys[sliceIndex + 1]][col].unshift(id);
            } else {
                return;
            }
        } else {
            var colIndex = map.columns.findIndex(function (c) { return c.key === col; });
            var nextCol = map.columns[colIndex + (key === 'ArrowLeft' ? -1 : 1)];
            if (!nextCol) { return; }
            list.splice(index, 1);
            var dest = map.cells[slice][nextCol.key];
            dest.splice(Math.min(index, dest.length), 0, id);
        }

        changed();
        map.$overlay.find('.wcp-sm-card[data-id="' + id + '"]').trigger('focus');
    }

    // ----------------------------------------------------------------------
    // Saving
    // ----------------------------------------------------------------------

    function setStatus(text, isError) {
        if (!map.$overlay) { return; }
        map.$overlay.find('.wcp-sm-status').text(text).toggleClass('is-error', !!isError);
    }

    function scheduleSave() {
        setStatus('Saving…');
        clearTimeout(map.saveTimer);
        map.saveTimer = setTimeout(function () { map.saveTimer = null; save(); }, SAVE_DELAY);
    }

    function payload() {
        var keys = sliceKeys();
        var assignments = {};
        var columns = map.columns.map(function (col) {
            var items = [];
            keys.forEach(function (slice) {
                map.cells[slice][col.key].forEach(function (id) {
                    items.push(id);
                    if (slice !== UNSCHEDULED) { assignments[id] = slice; }
                });
            });
            return { key: col.key, items: items };
        });

        return { releases: map.releases, columns: columns, assignments: assignments };
    }

    function save(done) {
        if (map.saving) {
            // One request at a time; the newest state goes out when it returns.
            map.saveQueued = true;
            if (done) { map.afterSave = done; }
            return;
        }
        map.saving = true;

        $.ajax({
            url: wcpThemeData.restUrl + '/story-map/' + map.scope + '/' + map.id,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload()),
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce); },
            success: function () {
                map.dirty = true;
                if (!map.saveQueued && !map.saveTimer) { setStatus('Saved'); }
            },
            error: function () {
                setStatus('Not saved — check your connection, then move a card to retry', true);
            },
            complete: function () {
                map.saving = false;
                if (map.saveQueued) {
                    map.saveQueued = false;
                    var after = map.afterSave;
                    map.afterSave = null;
                    save(after);
                    return;
                }
                if (done) { done(); }
            }
        });
    }

    /** Send any pending save now, then call back. */
    function flushSave(done) {
        if (map.saveTimer) {
            clearTimeout(map.saveTimer);
            map.saveTimer = null;
            save(done);
        } else if (map.saving) {
            map.afterSave = done;
            map.saveQueued = true;
        } else {
            done();
        }
    }

    // ----------------------------------------------------------------------
    // Events
    // ----------------------------------------------------------------------

    // Load-bearing guard, as in slideshow.js: only act while the map is open.
    $(document).on('keydown', function (e) {
        if (!map.isOpen) { return; }

        if (e.key === 'Escape') {
            if ($(e.target).is('.wcp-sm-release-input')) { return; }
            e.preventDefault();
            map.close();
            return;
        }

        if (e.altKey && /^Arrow(Up|Down|Left|Right)$/.test(e.key)) {
            var $card = $(e.target).closest('.wcp-sm-card');
            if ($card.length) {
                e.preventDefault();
                moveCardByKey(parseInt($card.attr('data-id'), 10), e.key);
            }
        }
    });

    $(document).on('click', '#wcp-btn-page-storymap', function () {
        map.openForPage($(this).data('page-id'));
    });
    $(document).on('click', '.wcp-heading-storymap', function () {
        map.openForHeading($(this).data('heading-id'));
    });

    $(document).on('click', '.wcp-sm-close', function () { map.close(); });
    $(document).on('click', '.wcp-sm-add-release', function () { addRelease(); });

    $(document).on('click', '.wcp-sm-release-name', function () { startRename($(this)); });
    $(document).on('click', '.wcp-sm-release-up', function () {
        moveRelease($(this).closest('.wcp-sm-release').attr('data-release'), -1);
    });
    $(document).on('click', '.wcp-sm-release-down', function () {
        moveRelease($(this).closest('.wcp-sm-release').attr('data-release'), 1);
    });
    $(document).on('click', '.wcp-sm-release-delete', function () {
        deleteRelease($(this).closest('.wcp-sm-release').attr('data-release'));
    });

    $(document).on('click', '.wcp-sm-line-up, .wcp-sm-line-down', function () {
        var $nudge = $(this).closest('.wcp-sm-line-nudge');
        nudgeLine(String($nudge.attr('data-slice')), String($nudge.attr('data-col')), $(this).hasClass('wcp-sm-line-up') ? 'up' : 'down');
    });

    $(document).on('click', '.wcp-sm-type-toggle', function () {
        var type = $(this).attr('data-type');
        if (map.hiddenTypes[type]) { delete map.hiddenTypes[type]; } else { map.hiddenTypes[type] = true; }
        try { localStorage.setItem('wcp_storymap_hidden_types', JSON.stringify(map.hiddenTypes)); } catch (e) {}
        render();
    });

    $(document).on('click', '.wcp-sm-subitems-toggle', function () {
        map.showSubitems = !map.showSubitems;
        try { localStorage.setItem('wcp_storymap_subitems', map.showSubitems ? '1' : '0'); } catch (e) {}
        render();
    });

    return map;
})(jQuery);
