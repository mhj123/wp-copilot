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
        slides: [],
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
                this.$overlay = $('<div class="wcp-slideshow-overlay"><div class="wcp-slide-column"></div><div class="wcp-slide-position"></div></div>');
                $('body').append(this.$overlay);
            }

            var slide = this.slides[this.index] || { title: '', body: null, bullets: [] };
            var $col = this.$overlay.find('.wcp-slide-column');
            $col.empty();

            $col.append($('<h1 class="wcp-slide-title">').text(slide.title || ''));

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

            this.$overlay.find('.wcp-slide-position').text((this.index + 1) + ' / ' + this.slides.length);
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
                show.slides = (resp && resp.slides) || [];
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

    // Click the backdrop (anywhere outside the slide column) to close —
    // same idiom as the goal modal / quick-jump overlay.
    $(document).on('click', '.wcp-slideshow-overlay', function (e) {
        if (e.target === this) {
            show.close();
        }
    });

    return show;
})(jQuery);
