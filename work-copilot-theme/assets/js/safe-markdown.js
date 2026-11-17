/**
 * Safe Markdown rendering.
 *
 * Markdown here is not trusted input: it can be AI output, and AI output can
 * carry text lifted from web search results, PDFs and imported bookmarks.
 * marked passes raw HTML straight through, so everything it produces goes
 * through DOMPurify before it is allowed near .html().
 *
 * Fails closed: if either library is missing the text comes back HTML-escaped,
 * never as unsanitised markup.
 */
(function (window) {
    'use strict';

    function escapeHtml(text) {
        return String(text == null ? '' : text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    var hooked = false;

    function purifier() {
        var purify = window.DOMPurify;
        if (!purify || typeof purify.sanitize !== 'function') {
            return null;
        }
        if (!hooked) {
            // Rendered links come from untrusted text: never hand the opened
            // page a reference back to this (logged-in) window.
            purify.addHook('afterSanitizeAttributes', function (node) {
                if (node.tagName === 'A' && node.getAttribute('target')) {
                    node.setAttribute('rel', 'noopener noreferrer');
                }
            });
            hooked = true;
        }
        return purify;
    }

    /**
     * @param {string} raw Markdown source.
     * @return {string} Sanitised HTML, safe to pass to .html()/innerHTML.
     */
    window.wcpSafeMarkdown = function (raw) {
        var purify = purifier();
        if (!purify || !window.marked || typeof window.marked.parse !== 'function') {
            return escapeHtml(raw).replace(/\n/g, '<br>');
        }
        return purify.sanitize(window.marked.parse(String(raw == null ? '' : raw)), {
            USE_PROFILES: { html: true },
            FORBID_TAGS: ['style', 'form', 'input', 'button', 'textarea', 'select'],
            FORBID_ATTR: ['style']
        });
    };
})(window);
