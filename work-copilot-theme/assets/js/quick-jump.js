/**
 * Quick-jump palette — Cmd/Ctrl+K to jump to a page.
 *
 * Two halves, deliberately separated:
 *
 *   1. window.WcpQuickJump.search() / .renderResults() are REUSABLE primitives.
 *      They take their inputs as arguments and read no global state, so a second
 *      typeahead (e.g. associating an item to a page) can call them against its
 *      own entries and its own <ul> without a rewrite.
 *
 *   2. The `jump` controller below is THIS feature's private wiring to
 *      #wcp-jump-modal. A second typeahead should build its own small controller
 *      and reuse the primitives above — not this.
 */

window.WcpQuickJump = (function () {
    'use strict';

    function norm(s) {
        return (s || '').toString().toLowerCase();
    }

    /**
     * Score one entry against a query. Lower is better; null means no match.
     *
     * Bands are spaced so they can't collide, and every tier is a plain
     * substring test — predictable beats clever at this corpus size (~70 pages),
     * and there's no typo tolerance to reason about.
     *
     *    0  title is exactly the query
     *   10  title starts with the query
     *   20  query appears in the title at a word boundary
     *   30  query appears anywhere in the title
     *   40  every query token appears somewhere in path + title
     *        (this is what makes "oss catalog" find
     *         Projects > One Stop Shop > Catalogue data)
     */
    function scoreEntry(entry, q) {
        var title = norm(entry.title);
        var idx = title.indexOf(q);

        if (title === q) { return 0; }
        if (idx === 0) { return 10; }
        if (idx > 0) {
            var prev = title.charAt(idx - 1);
            return /[\s\-_/:(,]/.test(prev) ? 20 : 30;
        }

        // Multi-token fallback across the full path.
        var tokens = q.split(/\s+/).filter(Boolean);
        if (tokens.length) {
            var hay = norm((entry.path || []).join(' ')) + ' ' + title;
            for (var i = 0; i < tokens.length; i++) {
                if (hay.indexOf(tokens[i]) === -1) { return null; }
            }
            return 40;
        }

        return null;
    }

    /**
     * @param {Array}  entries {id, title, path:[], url}
     * @param {string} query
     * @param {number} limit
     * @return {Array} matching entries, best first
     */
    function search(entries, query, limit) {
        entries = entries || [];
        limit = limit || 20;

        var q = norm(query).trim();
        if (!q) {
            // Empty query: the top of the tree, in sidebar order.
            return entries.slice(0, limit);
        }

        var scored = [];
        for (var i = 0; i < entries.length; i++) {
            var s = scoreEntry(entries[i], q);
            if (s !== null) {
                scored.push({ entry: entries[i], score: s, i: i });
            }
        }

        scored.sort(function (a, b) {
            if (a.score !== b.score) { return a.score - b.score; }
            // Deterministic tie-breaks, so results don't reshuffle as pages
            // are added: shallower first, then shorter title, then A-Z.
            var da = (a.entry.path || []).length;
            var db = (b.entry.path || []).length;
            if (da !== db) { return da - db; }
            var la = (a.entry.title || '').length;
            var lb = (b.entry.title || '').length;
            if (la !== lb) { return la - lb; }
            return (a.entry.title || '').localeCompare(b.entry.title || '');
        });

        return scored.slice(0, limit).map(function (r) { return r.entry; });
    }

    /**
     * Render results into any <ul>. Caller owns the container and the state.
     *
     * @param {jQuery} $ul
     * @param {Array}  results
     * @param {number} activeIndex
     */
    function renderResults($ul, results, activeIndex) {
        var $ = window.jQuery;
        $ul.empty();

        results.forEach(function (entry, i) {
            var $li = $('<li>', {
                'class': 'wcp-jump-result' + (i === activeIndex ? ' is-active' : ''),
                'role': 'option',
                'id': 'wcp-jump-result-' + i,
                'data-index': i,
                'aria-selected': i === activeIndex ? 'true' : 'false'
            });

            if (entry.path && entry.path.length) {
                $li.append($('<span class="wcp-jump-result-path">').text(entry.path.join(' › ')));
            }
            $li.append($('<span class="wcp-jump-result-title">').text(entry.title));
            $ul.append($li);
        });
    }

    return {
        search: search,
        scoreEntry: scoreEntry,
        renderResults: renderResults
    };
})();

jQuery(document).ready(function ($) {
    'use strict';

    var jump = {
        entries: [],
        results: [],
        activeIndex: -1,
        lastFocused: null,
        isOpen: false,

        $modal: null,
        $input: null,
        $results: null,
        $empty: null,

        init: function () {
            this.$modal = $('#wcp-jump-modal');
            if (!this.$modal.length) { return false; }

            this.$input = $('#wcp-jump-input');
            this.$results = $('#wcp-jump-results');
            this.$empty = this.$modal.find('.wcp-jump-empty');
            this.entries = (window.wcpThemeData && wcpThemeData.jumpPages) || [];
            return true;
        },

        open: function () {
            if (this.isOpen) { return; }
            this.lastFocused = document.activeElement;
            this.isOpen = true;
            this.$modal.show();
            this.$input.val('');
            this.render('');
            // Focus after show() so the caret actually lands in the field.
            this.$input.trigger('focus');
        },

        close: function () {
            if (!this.isOpen) { return; }
            this.isOpen = false;
            this.$modal.hide();
            this.$results.empty();
            // Send focus back where it came from, so Escape doesn't strand the user.
            if (this.lastFocused && typeof this.lastFocused.focus === 'function') {
                this.lastFocused.focus();
            }
            this.lastFocused = null;
        },

        render: function (query) {
            this.results = window.WcpQuickJump.search(this.entries, query, 20);
            this.activeIndex = this.results.length ? 0 : -1;
            this.paint();
            this.$empty.toggle(this.results.length === 0);
        },

        paint: function () {
            window.WcpQuickJump.renderResults(this.$results, this.results, this.activeIndex);
            this.$input.attr(
                'aria-activedescendant',
                this.activeIndex >= 0 ? 'wcp-jump-result-' + this.activeIndex : ''
            );
            this.scrollActiveIntoView();
        },

        scrollActiveIntoView: function () {
            if (this.activeIndex < 0) { return; }
            var el = this.$results.children().eq(this.activeIndex)[0];
            if (el && typeof el.scrollIntoView === 'function') {
                el.scrollIntoView({ block: 'nearest' });
            }
        },

        move: function (delta) {
            if (!this.results.length) { return; }
            var n = this.results.length;
            this.activeIndex = (this.activeIndex + delta + n) % n; // wraps
            this.paint();
        },

        go: function (index) {
            var entry = this.results[index];
            if (entry && entry.url) {
                window.location.href = entry.url;
            }
        }
    };

    if (!jump.init()) { return; }

    // Open. Fires even inside inputs: it's a deliberate modifier chord with no
    // typing conflict, and every inline editor in this theme saves on blur
    // rather than discarding, so opening mid-edit commits the edit.
    $(document).on('keydown', function (e) {
        var mod = e.metaKey || e.ctrlKey;
        if (mod && !e.altKey && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault();
            jump.isOpen ? jump.close() : jump.open();
        }
    });

    // Close/navigate. Bound at document level rather than on the input so it
    // still works if focus has moved out of the field.
    $(document).on('keydown', function (e) {
        if (!jump.isOpen) { return; }

        if (e.key === 'Escape') {
            e.preventDefault();
            jump.close();
        } else if (e.key === 'ArrowDown') {
            e.preventDefault();
            jump.move(1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            jump.move(-1);
        } else if (e.key === 'Enter') {
            if (jump.activeIndex >= 0) {
                e.preventDefault();
                jump.go(jump.activeIndex);
            }
        }
    });

    $(document).on('input', '#wcp-jump-input', function () {
        jump.render($(this).val());
    });

    $(document).on('click', '.wcp-jump-result', function () {
        jump.go(parseInt($(this).attr('data-index'), 10));
    });

    // Backdrop click closes — same idiom as the goal modal.
    $(document).on('click', '.wcp-jump-overlay', function (e) {
        if ($(e.target).hasClass('wcp-jump-overlay')) {
            jump.close();
        }
    });
});
