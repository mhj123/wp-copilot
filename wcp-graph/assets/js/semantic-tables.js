/**
 * Semantic tables: create/delete tables and columns (view config), and edit
 * cells (each cell value is an edge: row item — column predicate — object).
 * Cell editor: pick a suggestion to connect an entity; press Enter without
 * picking to store the text as a literal value.
 */
(function () {
    'use strict';

    if (typeof wcpGraphData === 'undefined') {
        return;
    }

    function api(path, options) {
        var url = wcpGraphData.restUrl +
            (wcpGraphData.restUrl.indexOf('?') !== -1 ? path.replace('?', '&') : path);
        options = options || {};
        options.headers = Object.assign({ 'X-WP-Nonce': wcpGraphData.nonce }, options.headers || {});
        return fetch(url, options).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok) {
                    throw new Error((data && data.message) || 'Request failed');
                }
                return data;
            });
        });
    }

    function post(path, params) {
        return api(path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(params).toString()
        });
    }

    document.querySelectorAll('.wcpg-tables').forEach(function (container) {
        var pageId = container.dataset.pageId;
        var headingId = container.dataset.headingId;
        var form = container.querySelector('.wcpg-table-form');
        var errorEl = form.querySelector('.wcpg-form-error');

        container.querySelector('.wcpg-add-table').addEventListener('click', function () {
            form.hidden = !form.hidden;
            if (!form.hidden) {
                form.querySelector('.wcpg-table-columns').focus();
            }
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            errorEl.textContent = '';
            post('/pages/' + pageId + '/tables', {
                heading_id: headingId,
                columns: form.querySelector('.wcpg-table-columns').value
            }).then(function () {
                window.location.reload();
            }).catch(function (error) {
                errorEl.textContent = error.message;
            });
        });

        container.addEventListener('click', function (event) {
            var tableWrap = event.target.closest('.wcpg-table-wrap');
            if (!tableWrap) {
                return;
            }
            var tableId = tableWrap.dataset.tableId;
            var base = '/pages/' + pageId + '/tables/' + tableId;

            if (event.target.closest('.wcpg-table-delete')) {
                if (window.confirm('Remove this table? The connections in it are kept.')) {
                    api(base, { method: 'DELETE' }).then(function () {
                        window.location.reload();
                    });
                }
            } else if (event.target.closest('.wcpg-col-add')) {
                var label = window.prompt('Column predicate (e.g. fulfiller):');
                if (label && label.trim()) {
                    post(base + '/columns', { label: label.trim() }).then(function () {
                        window.location.reload();
                    }).catch(function (error) {
                        window.alert(error.message);
                    });
                }
            } else if (event.target.closest('.wcpg-col-delete')) {
                var th = event.target.closest('th');
                api(base + '/columns/' + th.dataset.predicateId, { method: 'DELETE' }).then(function () {
                    window.location.reload();
                });
            } else if (event.target.closest('.wcpg-cell-add')) {
                openCellEditor(event.target.closest('.wcpg-cell'));
            } else if (event.target.closest('.wcpg-delete')) {
                var chip = event.target.closest('.wcpg-chip-wrap');
                api('/edges/' + chip.dataset.edgeId, { method: 'DELETE' }).then(function () {
                    chip.remove();
                });
            }
        });
    });

    function openCellEditor(cell) {
        if (cell.querySelector('.wcpg-cell-editor')) {
            cell.querySelector('.wcpg-cell-editor input').focus();
            return;
        }

        var editor = document.createElement('span');
        editor.className = 'wcpg-cell-editor';
        editor.innerHTML = '<input type="text" placeholder="entity or value…" autocomplete="off" />' +
            '<ul class="wcpg-suggestions" hidden></ul>';
        cell.insertBefore(editor, cell.querySelector('.wcpg-cell-add'));

        var input = editor.querySelector('input');
        var suggestions = editor.querySelector('.wcpg-suggestions');
        var searchTimer = null;
        input.focus();

        function close() {
            editor.remove();
        }

        function saveEdge(params) {
            return post('/edges', Object.assign({
                subject_id: cell.dataset.subjectId,
                predicate: cell.dataset.predicate
            }, params)).then(function (data) {
                insertChip(cell, data.edge);
                close();
            }).catch(function (error) {
                window.alert(error.message);
                input.focus();
            });
        }

        input.addEventListener('input', function () {
            clearTimeout(searchTimer);
            var query = input.value.trim();
            if (query.length < 2) {
                suggestions.hidden = true;
                return;
            }
            searchTimer = setTimeout(function () {
                api('/entities?q=' + encodeURIComponent(query)).then(function (data) {
                    suggestions.innerHTML = '';
                    data.entities.forEach(function (entity) {
                        if (String(entity.id) === cell.dataset.subjectId) {
                            return;
                        }
                        var li = document.createElement('li');
                        li.textContent = entity.title + ' (' + entity.type + ')';
                        li.addEventListener('mousedown', function (e) {
                            e.preventDefault();
                            saveEdge({ object_id: entity.id, object_value: '' });
                        });
                        suggestions.appendChild(li);
                    });
                    suggestions.hidden = suggestions.children.length === 0;
                }).catch(function () {
                    suggestions.hidden = true;
                });
            }, 250);
        });

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                if (input.value.trim()) {
                    saveEdge({ object_id: 0, object_value: input.value.trim() });
                }
            } else if (event.key === 'Escape') {
                close();
            }
        });

        input.addEventListener('blur', function () {
            setTimeout(function () {
                if (editor.isConnected && !editor.contains(document.activeElement)) {
                    close();
                }
            }, 200);
        });
    }

    function insertChip(cell, edge) {
        var wrap = document.createElement('span');
        wrap.className = 'wcpg-chip-wrap';
        wrap.dataset.edgeId = edge.id;

        if (edge.object_id) {
            var link = document.createElement('a');
            link.className = 'wcpg-chip';
            link.href = edge.object_url;
            link.textContent = edge.object_title;
            wrap.appendChild(link);
        } else {
            var literal = document.createElement('span');
            literal.className = 'wcpg-literal';
            literal.textContent = edge.object_value;
            wrap.appendChild(literal);
        }

        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'wcpg-delete';
        remove.title = 'Remove';
        remove.innerHTML = '&times;';
        wrap.appendChild(remove);

        cell.insertBefore(wrap, cell.querySelector('.wcpg-cell-add'));
    }
})();
