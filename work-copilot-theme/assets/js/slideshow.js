/**
 * Slideshow view — full-screen, read-only presentation of a Page or Heading.
 *
 * Fetches pre-built slide data from the plugin (GET /pages/{id}/slideshow or
 * /headings/{id}/slideshow — see wcp_theme_get_page_slideshow_data() /
 * wcp_theme_get_heading_slideshow_data() in functions.php), then renders one
 * slide at a time. Arrow keys move between slides, Escape closes. Nothing on
 * a slide is interactive — checkboxes are static glyphs, not <input>s.
 *
 * Structured like quick-jump.js: a controller object with an `isOpen` guard on
 * the document keydown handler, so ArrowLeft/ArrowRight/Escape only act while
 * the overlay is actually open.
 */

window.WcpSlideshow = (function ($) {
    'use strict';

    var show = {
        isOpen: false,
        title: '',       // page/heading title — used for the export filename and the title in the file
        allSlides: [],   // full deck as served (heading + item slides)
        slides: [],      // what's shown: allSlides, minus item slides when the toggle is off
        itemsOn: true,
        index: 0,
        lastFocused: null,
        $overlay: null,

        openForPage: function (pageId) {
            fetchAndOpen('/pages/' + pageId + '/slideshow');
        },

        openForHeading: function (headingId) {
            fetchAndOpen('/headings/' + headingId + '/slideshow');
        },

        close: function () {
            if (!this.isOpen) { return; }
            this.isOpen = false;
            if (this.$overlay) {
                this.$overlay.remove();
                this.$overlay = null;
            }
            if (this.lastFocused && typeof this.lastFocused.focus === 'function') {
                this.lastFocused.focus();
            }
            this.lastFocused = null;
        },

        next: function () {
            if (this.index < this.slides.length - 1) {
                this.index++;
                this.render();
            }
        },

        prev: function () {
            if (this.index > 0) {
                this.index--;
                this.render();
            }
        },

        render: function () {
            if (!this.$overlay) {
                this.$overlay = $('<div class="wcp-slideshow-overlay"><div class="wcp-slide-column"></div><button type="button" class="wcp-slide-toggle"></button><button type="button" class="wcp-slide-export">Export .pptx</button><div class="wcp-slide-position"></div></div>');
                $('body').append(this.$overlay);
            }

            var slide = this.slides[this.index] || { title: '', body: null, bullets: [] };
            var $col = this.$overlay.find('.wcp-slide-column');
            $col.empty();

            if (slide.kind === 'item' && slide.kicker) {
                $col.append($('<div class="wcp-slide-kicker">').text(slide.kicker));
            }
            $col.append($('<h1 class="wcp-slide-title">').text(slide.title || ''));
            if (slide.empty) {
                $col.append($('<div class="wcp-slide-empty">').text('No description yet'));
            }

            if (slide.body) {
                $col.append($('<div class="wcp-slide-body">').html(wcpSlideshowMarkdown(slide.body)));
            }

            if (slide.bullets && slide.bullets.length) {
                var $ul = $('<ul class="wcp-slide-bullets">');
                slide.bullets.forEach(function (b) {
                    var checked = b.checked === true ? 'true' : (b.checked === false ? 'false' : 'none');
                    var $li = $('<li class="wcp-slide-bullet">')
                        .attr('data-level', b.level || 0)
                        .attr('data-checked', checked)
                        .css('--level', b.level || 0)
                        .text(b.text || '');
                    $ul.append($li);
                });
                $col.append($ul);
            }

            this.$overlay.scrollTop(0); // every slide starts at its top
            this.$overlay.find('.wcp-slide-position').text((this.index + 1) + ' / ' + this.slides.length);

            // The toggle only makes sense for decks that actually contain item slides.
            var hasItems = this.allSlides.some(function (s) { return s.kind === 'item'; });
            this.$overlay.find('.wcp-slide-toggle')
                .toggle(hasItems)
                .text('Item slides: ' + (this.itemsOn ? 'on' : 'off'))
                .attr('aria-pressed', this.itemsOn ? 'true' : 'false');
        },

        applyFilter: function () {
            var on = this.itemsOn;
            this.slides = this.allSlides.filter(function (s) { return on || s.kind !== 'item'; });
        },

        toggleItems: function () {
            var current = this.slides[this.index];
            this.itemsOn = !this.itemsOn;
            try { localStorage.setItem('wcp_slideshow_items', this.itemsOn ? '1' : '0'); } catch (e) {}
            this.applyFilter();
            var idx = this.slides.indexOf(current);
            if (idx < 0) {
                // Current slide was an item slide that's now hidden — fall back to its heading slide.
                idx = this.allSlides.indexOf(current);
                while (idx > 0 && this.allSlides[idx].kind === 'item') { idx--; }
                idx = Math.max(0, this.slides.indexOf(this.allSlides[idx]));
            }
            this.index = idx;
            this.render();
        }
    };

    function fetchAndOpen(path) {
        $.ajax({
            url: wcpThemeData.restUrl + path,
            method: 'GET',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcpThemeData.nonce);
            },
            success: function (resp) {
                show.title = (resp && resp.title) || '';
                show.allSlides = (resp && resp.slides) || [];
                try { show.itemsOn = localStorage.getItem('wcp_slideshow_items') !== '0'; } catch (e) { show.itemsOn = true; }
                show.applyFilter();
                if (!show.slides.length) { return; }
                show.lastFocused = document.activeElement;
                show.index = 0;
                show.isOpen = true;
                show.render();
            }
        });
    }

    // Same defensive pattern as theme.js:539-540 — feature-detect marked.js
    // rather than assuming it's present.
    function wcpSlideshowMarkdown(raw) {
        if (window.marked && typeof window.marked.parse === 'function') {
            return marked.parse(raw || '');
        }
        return $('<div>').text(raw || '').html();
    }

    // Load-bearing guard: only act on these keys while the slideshow is open,
    // so they can't collide with anything else bound at the document level.
    $(document).on('keydown', function (e) {
        if (!show.isOpen) { return; }

        if (e.key === 'Escape') {
            e.preventDefault();
            show.close();
        } else if (e.key === 'ArrowRight') {
            e.preventDefault();
            show.next();
        } else if (e.key === 'ArrowLeft') {
            e.preventDefault();
            show.prev();
        }
    });

    $(document).on('click', '#wcp-btn-page-slideshow', function () {
        show.openForPage($(this).data('page-id'));
    });

    $(document).on('click', '.wcp-heading-slideshow', function () {
        show.openForHeading($(this).data('heading-id'));
    });

    $(document).on('click', '.wcp-slide-toggle', function (e) {
        e.stopPropagation();
        show.toggleItems();
    });

    // ----------------------------------------------------------------------
    // Export to .pptx (client-side, PptxGenJS loaded on first use)
    // ----------------------------------------------------------------------
    // Exports exactly what the viewer is showing (so the "Item slides" toggle is
    // respected). Read-only: nothing is sent to the server or the AI.

    // marked inline tokens → PptxGenJS text runs (bold/italic/code/links kept, everything else as text).
    function inlineRuns(tokens, style) {
        var runs = [];
        (tokens || []).forEach(function (t) {
            if (t.type === 'strong') {
                runs = runs.concat(inlineRuns(t.tokens, $.extend({}, style, { bold: true })));
            } else if (t.type === 'em') {
                runs = runs.concat(inlineRuns(t.tokens, $.extend({}, style, { italic: true })));
            } else if (t.type === 'link') {
                runs = runs.concat(inlineRuns(t.tokens, $.extend({}, style, { color: '1F5FA8', underline: { style: 'sng' } })));
            } else if (t.type === 'codespan') {
                runs.push({ text: t.text, options: $.extend({}, style, { fontFace: 'Courier New' }) });
            } else if (t.type === 'br') {
                runs.push({ text: '\n', options: $.extend({}, style) });
            } else if (t.tokens && t.tokens.length) {
                runs = runs.concat(inlineRuns(t.tokens, style));
            } else if (t.type !== 'html') {
                runs.push({ text: t.text || t.raw || '', options: $.extend({}, style) });
            }
        });
        return runs;
    }

    // Emit one paragraph: paragraph-level options on the first run, breakLine on the last
    // (PptxGenJS reads paragraph options from the first run of a paragraph).
    function pushParagraph(out, runs, paraOpts) {
        if (!runs.length) { return; }
        runs[0].options = $.extend({}, runs[0].options, paraOpts);
        runs[runs.length - 1].options = $.extend({}, runs[runs.length - 1].options, { breakLine: true });
        Array.prototype.push.apply(out, runs);
    }

    function markdownToRuns(md, out, level) {
        if (!(window.marked && typeof window.marked.lexer === 'function')) {
            pushParagraph(out, [{ text: md, options: {} }], {});
            return;
        }
        marked.lexer(md || '').forEach(function (t) {
            if (t.type === 'heading') {
                pushParagraph(out, inlineRuns(t.tokens, { bold: true }), {});
            } else if (t.type === 'paragraph' || t.type === 'text') {
                pushParagraph(out, inlineRuns(t.tokens || [{ type: 'text', text: t.text }], {}), {});
            } else if (t.type === 'list') {
                listToRuns(t, out, level || 0);
            } else if (t.type === 'code') {
                pushParagraph(out, [{ text: t.text, options: { fontFace: 'Courier New' } }], {});
            } else if (t.type === 'blockquote') {
                markdownToRuns(t.text, out, level);
            }
        });
    }

    function listToRuns(list, out, level) {
        list.items.forEach(function (item) {
            var inline = [], nested = [];
            (item.tokens || []).forEach(function (t) {
                if (t.type === 'list') { nested.push(t); } else { inline = inline.concat(inlineRuns(t.tokens || [{ type: 'text', text: t.text || '' }], {})); }
            });
            pushParagraph(out, inline, { bullet: true, indentLevel: level });
            nested.forEach(function (n) { listToRuns(n, out, level + 1); });
        });
    }

    function slideToRuns(slide) {
        var out = [];
        if (slide.body) { markdownToRuns(slide.body, out, 0); }
        (slide.bullets || []).forEach(function (b) {
            var para = { indentLevel: b.level || 0 };
            para.bullet = b.checked === true ? { code: '2611' } : (b.checked === false ? { code: '2610' } : true);
            pushParagraph(out, [{ text: b.text || '', options: {} }], para);
        });
        if (!out.length && slide.empty) {
            pushParagraph(out, [{ text: 'No description yet', options: { italic: true, color: '999999' } }], {});
        }
        return out;
    }

    // Shrink text for crowded slides — PowerPoint only applies auto-fit once a slide is edited,
    // so choose a size up front from how much text there is.
    function fontSizeFor(runs) {
        var weight = 0;
        runs.forEach(function (r) { weight += (r.text || '').length + (r.options && r.options.breakLine ? 40 : 0); });
        return weight < 500 ? 20 : weight < 900 ? 17 : weight < 1400 ? 14 : weight < 2000 ? 12 : 10;
    }

    function buildPptx(PptxGenJS) {
        var pptx = new PptxGenJS();
        pptx.layout = 'LAYOUT_WIDE'; // 13.33 x 7.5 in
        pptx.title = show.title || 'Slides';

        show.slides.forEach(function (slide) {
            var s = pptx.addSlide();
            s.background = { color: 'FFFFFF' };
            if (slide.kind === 'item' && slide.kicker) {
                s.addText(String(slide.kicker).toUpperCase(), { x: 0.6, y: 0.3, w: 12.1, h: 0.3, fontSize: 12, color: '888888', charSpacing: 2 });
            }
            s.addText(slide.title || '', { x: 0.6, y: 0.6, w: 12.1, h: 0.9, fontSize: 32, bold: true, color: '14161A', valign: 'top' });
            var runs = slideToRuns(slide);
            if (runs.length) {
                s.addText(runs, { x: 0.6, y: 1.6, w: 12.1, h: 5.4, fontSize: fontSizeFor(runs), color: '222222', valign: 'top', paraSpaceAfter: 6, fit: 'shrink' });
            }
        });
        var name = (show.title || 'slides').replace(/[^\w\- ]+/g, '').trim().replace(/\s+/g, '-') || 'slides';
        return pptx.writeFile({ fileName: name + '.pptx' });
    }

    function loadPptxGen(cb) {
        if (window.PptxGenJS) { cb(window.PptxGenJS); return; }
        $.ajax({ url: wcpThemeData.pptxUrl, dataType: 'script', cache: true })
            .done(function () { cb(window.PptxGenJS); })
            .fail(function () { cb(null); });
    }

    $(document).on('click', '.wcp-slide-export', function (e) {
        e.stopPropagation();
        var $btn = $(this);
        if ($btn.prop('disabled') || !show.slides.length) { return; }
        $btn.prop('disabled', true).text('Exporting…');
        loadPptxGen(function (PptxGenJS) {
            var done = function (label) { $btn.prop('disabled', false).text(label); };
            if (!PptxGenJS) { done('Export failed — retry'); return; }
            try {
                $.when(buildPptx(PptxGenJS)).always(function () { done('Export .pptx'); });
            } catch (err) {
                if (window.console) { console.error('pptx export failed', err); }
                done('Export failed — retry');
            }
        });
    });

    // Click the backdrop (anywhere outside the slide column) to close —
    // same idiom as the goal modal / quick-jump overlay.
    $(document).on('click', '.wcp-slideshow-overlay', function (e) {
        if (e.target === this) {
            show.close();
        }
    });

    return show;
})(jQuery);
