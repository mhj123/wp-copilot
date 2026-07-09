/**
 * OpenBiografy admin interactions. All writes go through the REST API with
 * the logged-in user's nonce — these clicks ARE the human-in-the-loop.
 */
(function () {
    'use strict';

    var cfg = window.wcpoConfig || {};
    var stopRequested = false;

    function api(route, data, method) {
        method = method || 'POST';
        var url = cfg.root + route;
        var opts = {
            method: method,
            headers: { 'X-WP-Nonce': cfg.nonce }
        };
        if (method === 'POST') {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(data || {});
        } else if (data) {
            url += (url.indexOf('?') === -1 ? '?' : '&') + new URLSearchParams(data).toString();
        }
        return fetch(url, opts).then(function (res) {
            return res.json().then(function (json) {
                if (!res.ok) {
                    throw new Error(json && json.message ? json.message : 'Request failed (' + res.status + ')');
                }
                return json;
            });
        });
    }

    function progress(msg, isError) {
        var el = document.getElementById('wcpo-progress');
        if (el) {
            el.textContent = msg;
            el.classList.toggle('wcpo-progress-error', !!isError);
        }
    }

    function reloadSoon(delay) {
        setTimeout(function () { location.reload(); }, delay || 700);
    }

    function rowInputs(row, names) {
        var out = {};
        names.forEach(function (name) {
            var el = row.querySelector('[name="' + name + '"]');
            if (el) {
                out[name] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
            }
        });
        return out;
    }

    // ------------------------------------------------------------- Warnings

    var warningsBox = document.querySelector('[data-wcpo-warnings]');
    if (warningsBox && cfg.personId) {
        api('status', { person_id: cfg.personId }, 'GET').then(function (res) {
            if (!res.warnings || !res.warnings.length) { return; }
            var html = '<h3>⚠ ' + res.warnings.length + ' warnings</h3><ul>';
            res.warnings.forEach(function (w) {
                html += '<li>' + escapeHtml(w) + '</li>';
            });
            warningsBox.innerHTML = html + '</ul>';
            warningsBox.classList.add('wcpo-has-warnings');
        }).catch(function () { /* status is advisory */ });
    }

    function escapeHtml(s) {
        var div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }

    // ---------------------------------------------------------------- Person

    on('#wcpo-save-person', 'click', function (btn) {
        var form = document.getElementById('wcpo-person-form');
        var personId = parseInt(form.getAttribute('data-person'), 10);
        var data = rowInputs(form, ['name', 'birth_edtf', 'death_edtf', 'birth_place', 'death_place', 'occupation', 'context_note']);
        if (!data.name) { alert('Name required'); return; }
        if (personId) { data.person_id = personId; }
        busy(btn, api(personId ? 'update-person' : 'add-person', data).then(function () {
            reloadSoon(200);
        }));
    });

    // --------------------------------------------------------------- Sources

    on('#wcpo-add-urls', 'click', function (btn) {
        var textarea = document.getElementById('wcpo-urls');
        if (!textarea.value.trim()) { return; }
        busy(btn, api('add-sources', { person_id: cfg.personId, urls: textarea.value }).then(function (res) {
            var msg = res.created + ' added';
            if (res.skipped && res.skipped.length) { msg += ' — skipped: ' + res.skipped.join('; '); }
            progress(msg, res.skipped && res.skipped.length > 0);
            reloadSoon(res.skipped && res.skipped.length ? 2500 : 700);
        }));
    });

    on('#wcpo-upload-doc', 'click', function () {
        if (!window.wp || !wp.media) { alert('Media library unavailable'); return; }
        var frame = wp.media({ title: 'Add documents (PDF, TXT, MD)', multiple: true });
        frame.on('select', function () {
            var selection = frame.state().get('selection').toJSON();
            var chain = Promise.resolve();
            selection.forEach(function (att) {
                chain = chain.then(function () {
                    return api('add-document-source', { person_id: cfg.personId, attachment_id: att.id })
                        .catch(function (err) { progress(att.filename + ': ' + err.message, true); });
                });
            });
            chain.then(function () { reloadSoon(600); });
        });
        frame.open();
    });

    // Manual paste-text fallback for JS-rendered / paywalled pages.
    onAll('.wcpo-paste-text', 'click', function (btn) {
        var row = btn.closest('[data-wcpo-row]');
        if (row.querySelector('.wcpo-paste-area')) { return; }
        var wrap = document.createElement('div');
        wrap.className = 'wcpo-paste-area';
        wrap.innerHTML = '<textarea rows="6" style="width:100%" placeholder="Paste the page text here…"></textarea>'
            + '<button type="button" class="button button-primary">Save text</button>';
        row.querySelector('td').appendChild(wrap);
        wrap.querySelector('button').addEventListener('click', function () {
            var text = wrap.querySelector('textarea').value;
            if (!text.trim()) { return; }
            api('update-source', { source_id: parseInt(btn.getAttribute('data-source'), 10), text: text })
                .then(function () { reloadSoon(200); })
                .catch(function (err) { progress(err.message, true); });
        });
    });

    // Generic action buttons: retry / delete / accept-all.
    onAll('.wcpo-act', 'click', function (btn) {
        var confirmMsg = btn.getAttribute('data-confirm');
        if (confirmMsg && !window.confirm(confirmMsg)) { return; }
        var params = JSON.parse(btn.getAttribute('data-params') || '{}');
        busy(btn, api(btn.getAttribute('data-route'), params).then(function () {
            if (btn.getAttribute('data-removes') === 'group') {
                var group = btn.closest('[data-wcpo-group]');
                if (group) { group.remove(); return; }
            }
            var row = btn.closest('[data-wcpo-row]');
            if (row) { row.remove(); } else { reloadSoon(300); }
        }));
    });

    // ----------------------------------------------------------- Batch loops

    onAll('[data-wcpo-batch]', 'click', function (btn) {
        var route = btn.getAttribute('data-wcpo-batch');
        var stopBtn = document.getElementById('wcpo-stop');
        stopRequested = false;
        if (stopBtn) { stopBtn.style.display = ''; }
        btn.disabled = true;

        var i = 0;
        function step() {
            if (stopRequested || i >= cfg.batchSize) { return finish(); }
            i++;
            return api(route, { person_id: cfg.personId }).then(function (res) {
                if (res.done) {
                    progress('Done — nothing left to process.');
                    return finish(true);
                }
                var bits = [i + '/' + cfg.batchSize];
                if (res.source) { bits.push(res.source.cite_title || res.source.title || ''); }
                if (res.facts_created !== undefined) { bits.push(res.facts_created + ' facts'); }
                if (res.events_created !== undefined) { bits.push(res.events_created + ' events proposed'); }
                if (res.error) { bits.push('⚠ ' + res.error); }
                if (res.remaining !== undefined) { bits.push(res.remaining + ' left'); }
                progress(bits.join(' — '), !!res.error);
                return step();
            }).catch(function (err) {
                progress('⚠ ' + err.message, true);
                return finish();
            });
        }
        function finish(quiet) {
            btn.disabled = false;
            if (stopBtn) { stopBtn.style.display = 'none'; }
            reloadSoon(quiet ? 900 : 1600);
        }
        step();
    });

    on('#wcpo-stop', 'click', function () { stopRequested = true; });

    // ---------------------------------------------------------------- Export

    on('#wcpo-export', 'click', function (btn) {
        busy(btn, api('export-json', { person_id: cfg.personId }, 'GET').then(function (data) {
            var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'openbiografy-' + (data.person && data.person.slug ? data.person.slug : cfg.personId) + '.json';
            a.click();
            URL.revokeObjectURL(a.href);
        }));
    });

    // ----------------------------------------------------------- Fact review

    onAll('.wcpo-accept-fact', 'click', function (btn) {
        var row = btn.closest('[data-wcpo-row]');
        var data = rowInputs(row, ['claim', 'date_edtf', 'place', 'kind']);
        data.fact_id = parseInt(row.getAttribute('data-fact'), 10);
        busy(btn, api('accept-fact', data).then(function () { removeReviewRow(row); }));
    });

    onAll('.wcpo-dismiss-fact', 'click', function (btn) {
        var row = btn.closest('[data-wcpo-row]');
        var reason = window.prompt('Dismiss reason (optional):', '') || '';
        busy(btn, api('dismiss-fact', { fact_id: parseInt(row.getAttribute('data-fact'), 10), reason: reason })
            .then(function () { removeReviewRow(row); }));
    });

    function removeReviewRow(row) {
        var group = row.closest('[data-wcpo-group]');
        row.remove();
        if (group) {
            var left = group.querySelectorAll('[data-wcpo-row]').length;
            var count = group.querySelector('.wcpo-group-count');
            if (count) { count.textContent = left + ' proposed'; }
            if (!left) { group.remove(); }
        }
    }

    // ---------------------------------------------------------- Event review

    onAll('.wcpo-accept-event', 'click', function (btn) {
        var row = btn.closest('[data-wcpo-row]');
        var eventId = parseInt(row.getAttribute('data-event'), 10);
        var edits = rowInputs(row, ['title', 'description', 'date_edtf', 'place', 'kind']);
        edits.event_id = eventId;
        // Apply the (possibly edited) fields, then accept — two explicit human-authorised steps.
        busy(btn, api('edit-event', edits).then(function () {
            return api('accept-event', { event_id: eventId });
        }).then(function () { row.remove(); }));
    });

    onAll('.wcpo-dismiss-event', 'click', function (btn) {
        var row = btn.closest('[data-wcpo-row]');
        var reason = window.prompt('Dismiss reason (optional — member facts return to the consolidation pool):', '') || '';
        busy(btn, api('dismiss-event', { event_id: parseInt(row.getAttribute('data-event'), 10), reason: reason })
            .then(function () { row.remove(); }));
    });

    // -------------------------------------------------------------- Chapters

    on('#wcpo-create-chapter', 'click', function (btn) {
        var title = document.getElementById('wcpo-chapter-title').value;
        var period = document.getElementById('wcpo-chapter-period').value;
        if (!title.trim()) { return; }
        busy(btn, api('create-chapter', { person_id: cfg.personId, title: title, period_edtf: period })
            .then(function () { reloadSoon(200); }));
    });

    onAll('.wcpo-save-chapter', 'click', function (btn) {
        var card = btn.closest('[data-chapter]');
        var data = rowInputs(card, ['title', 'period_edtf', 'publish']);
        data.chapter_id = parseInt(card.getAttribute('data-chapter'), 10);
        busy(btn, api('update-chapter', data).then(function () { reloadSoon(200); }));
    });

    onAll('.wcpo-move-up, .wcpo-move-down', 'click', function (btn) {
        var card = btn.closest('[data-chapter]');
        var up = btn.classList.contains('wcpo-move-up');
        var sibling = up ? card.previousElementSibling : card.nextElementSibling;
        if (!sibling || !sibling.hasAttribute('data-chapter')) { return; }
        if (up) { sibling.before(card); } else { sibling.after(card); }
        var order = Array.prototype.map.call(document.querySelectorAll('[data-chapter]'), function (el) {
            return parseInt(el.getAttribute('data-chapter'), 10);
        });
        api('reorder-chapters', { person_id: cfg.personId, order: order }).then(function () { reloadSoon(150); });
    });

    onAll('.wcpo-draft-chapter', 'click', function (btn) {
        var card = btn.closest('[data-chapter]');
        progress('Drafting narrative…');
        busy(btn, api('draft-chapter', { chapter_id: parseInt(card.getAttribute('data-chapter'), 10) })
            .then(function () { reloadSoon(200); }));
    });

    onAll('.wcpo-accept-draft', 'click', function (btn) {
        var card = btn.closest('[data-chapter]');
        var text = card.querySelector('[name="draft"]').value;
        busy(btn, api('accept-draft', { chapter_id: parseInt(card.getAttribute('data-chapter'), 10), text: text })
            .then(function (res) {
                if (res.warnings && res.warnings.length) {
                    alert('Accepted with warnings:\n' + res.warnings.join('\n'));
                }
                reloadSoon(200);
            }));
    });

    onAll('.wcpo-dismiss-draft', 'click', function (btn) {
        var card = btn.closest('[data-chapter]');
        busy(btn, api('dismiss-draft', { chapter_id: parseInt(card.getAttribute('data-chapter'), 10) })
            .then(function () { reloadSoon(200); }));
    });

    // AI assignment suggestions: rendered as a pre-checked checklist; nothing
    // is written until the human clicks Apply.
    on('#wcpo-suggest', 'click', function (btn) {
        progress('Asking the model for chapter assignments…');
        busy(btn, api('suggest-assignments', { person_id: cfg.personId }).then(function (res) {
            progress('');
            var box = document.getElementById('wcpo-assignments');
            if (!res.assignments.length) {
                box.innerHTML = '<p>No assignments suggested.</p>';
                return;
            }
            var html = '<div class="wcpo-assign-box"><h3>Proposed assignments — untick any you disagree with</h3>';
            res.assignments.forEach(function (a, i) {
                html += '<label><input type="checkbox" checked data-i="' + i + '"> '
                    + escapeHtml(a.event_title || ('Event #' + a.event_id))
                    + ' → <strong>' + escapeHtml(a.chapter_title || ('Chapter #' + a.chapter_id)) + '</strong></label>';
            });
            html += '<p><button type="button" class="button button-primary" id="wcpo-apply-assignments">Apply selected</button></p></div>';
            box.innerHTML = html;
            document.getElementById('wcpo-apply-assignments').addEventListener('click', function () {
                var pairs = [];
                box.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
                    if (cb.checked) { pairs.push(res.assignments[parseInt(cb.getAttribute('data-i'), 10)]); }
                });
                api('apply-assignments', { pairs: pairs, action_id: res.action_id })
                    .then(function () { reloadSoon(200); })
                    .catch(function (err) { progress('⚠ ' + err.message, true); });
            });
        }));
    });

    // ---------------------------------------------------- EDTF hint (visual)

    onAll('.wcpo-edtf', 'blur', function (input) {
        var v = input.value.trim();
        var single = /^(\.\.)?$|^[0-9X]{4}(-\d{2}){0,2}[~?%]?$/i;
        var ok = v === '' || v.split('/').every(function (part) { return single.test(part.replace(/[~?%]/g, '')); });
        input.classList.toggle('wcpo-edtf-invalid', !ok);
    });

    // ----------------------------------------------------------------- Utils

    function on(selector, event, handler) {
        var el = document.querySelector(selector);
        if (el) {
            el.addEventListener(event, function (e) {
                e.preventDefault();
                handler(el, e);
            });
        }
    }

    function onAll(selector, event, handler) {
        document.querySelectorAll(selector).forEach(function (el) {
            el.addEventListener(event, function (e) {
                if (event === 'click') { e.preventDefault(); }
                handler(el, e);
            });
        });
    }

    function busy(btn, promise) {
        btn.disabled = true;
        promise.catch(function (err) {
            progress('⚠ ' + err.message, true);
            alert(err.message);
        }).finally(function () {
            btn.disabled = false;
        });
    }
})();
