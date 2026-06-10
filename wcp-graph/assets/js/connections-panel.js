/**
 * Connections panel: add/remove edges, entity search for the object picker.
 * Plain JS, no dependencies; talks to wcp-graph/v1 with the wpGraphData nonce.
 */
(function () {
    'use strict';

    var panel = document.querySelector('.wcpg-panel');
    if (!panel || typeof wcpGraphData === 'undefined') {
        return;
    }

    var postId = parseInt(panel.dataset.postId, 10);
    var form = panel.querySelector('.wcpg-add-form');
    var toggle = panel.querySelector('.wcpg-add-toggle');
    var predicateInput = form.querySelector('.wcpg-input-predicate');
    var objectWrap = form.querySelector('.wcpg-object-entity');
    var objectInput = form.querySelector('.wcpg-input-object');
    var objectIdInput = form.querySelector('.wcpg-object-id');
    var suggestions = form.querySelector('.wcpg-suggestions');
    var literalInput = form.querySelector('.wcpg-input-literal');
    var literalCheckbox = form.querySelector('.wcpg-literal-checkbox');
    var errorEl = form.querySelector('.wcpg-form-error');
    var searchTimer = null;

    function api(path, options) {
        options = options || {};
        options.headers = Object.assign({ 'X-WP-Nonce': wcpGraphData.nonce }, options.headers || {});
        return fetch(wcpGraphData.restUrl + path, options).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok) {
                    throw new Error((data && data.message) || 'Request failed');
                }
                return data;
            });
        });
    }

    toggle.addEventListener('click', function () {
        form.hidden = !form.hidden;
        if (!form.hidden) {
            predicateInput.focus();
        }
    });

    literalCheckbox.addEventListener('change', function () {
        var literal = literalCheckbox.checked;
        objectWrap.hidden = literal;
        literalInput.hidden = !literal;
        objectIdInput.value = '';
        objectInput.value = '';
    });

    objectInput.addEventListener('input', function () {
        objectIdInput.value = '';
        clearTimeout(searchTimer);
        var query = objectInput.value.trim();
        if (query.length < 2) {
            suggestions.hidden = true;
            return;
        }
        searchTimer = setTimeout(function () {
            api('/entities?q=' + encodeURIComponent(query)).then(function (data) {
                suggestions.innerHTML = '';
                data.entities.forEach(function (entity) {
                    if (entity.id === postId) {
                        return;
                    }
                    var li = document.createElement('li');
                    li.textContent = entity.title + ' (' + entity.type + ')';
                    li.addEventListener('mousedown', function (event) {
                        event.preventDefault();
                        objectInput.value = entity.title;
                        objectIdInput.value = entity.id;
                        suggestions.hidden = true;
                    });
                    suggestions.appendChild(li);
                });
                suggestions.hidden = suggestions.children.length === 0;
            }).catch(function () {
                suggestions.hidden = true;
            });
        }, 250);
    });

    objectInput.addEventListener('blur', function () {
        setTimeout(function () { suggestions.hidden = true; }, 150);
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        errorEl.textContent = '';

        var literal = literalCheckbox.checked;
        if (!literal && !objectIdInput.value) {
            errorEl.textContent = 'Pick an entity from the suggestions, or tick "plain value".';
            return;
        }
        if (literal && !literalInput.value.trim()) {
            errorEl.textContent = 'Enter a value.';
            return;
        }

        var body = new URLSearchParams({
            subject_id: postId,
            predicate: predicateInput.value.trim(),
            object_id: literal ? 0 : objectIdInput.value,
            object_value: literal ? literalInput.value.trim() : ''
        });

        api('/edges', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function () {
            // Server-side render keeps inverse labels and ordering right;
            // a reload is the simplest way to stay consistent with it.
            window.location.reload();
        }).catch(function (error) {
            errorEl.textContent = error.message;
        });
    });

    panel.addEventListener('click', function (event) {
        var button = event.target.closest('.wcpg-delete');
        if (!button) {
            return;
        }
        var edge = button.closest('.wcpg-edge');
        api('/edges/' + edge.dataset.edgeId, { method: 'DELETE' }).then(function () {
            edge.remove();
        }).catch(function (error) {
            errorEl.textContent = error.message;
        });
    });
})();
