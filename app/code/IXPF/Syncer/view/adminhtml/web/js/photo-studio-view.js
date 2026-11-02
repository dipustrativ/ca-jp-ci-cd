/**
 * Photo Studio job details: Readable / JSON switch per section, collapsible JSON tree,
 * flattened key/value view, and copy-to-clipboard for sections, nodes, paths and values.
 *
 * Everything is built with textContent — payload values are never parsed as HTML.
 */
define(['jquery'], function ($) {
    'use strict';

    var STORAGE_KEY = 'ixpfPhotoStudioViewMode',
        OPEN_DEPTH = 2;

    function el(tag, className, text) {
        var node = document.createElement(tag);

        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }

        return node;
    }

    function isContainer(value) {
        return value !== null && typeof value === 'object';
    }

    function childPath(path, key, parentIsArray) {
        if (parentIsArray) {
            return path + '[' + key + ']';
        }

        return /^[A-Za-z_$][\w$]*$/.test(key) ? (path ? path + '.' + key : key) : path + '["' + key + '"]';
    }

    function stringify(value) {
        return isContainer(value) ? JSON.stringify(value, null, 2) : String(value);
    }

    /* ---------- clipboard ---------- */

    var toastTimer;

    function toast(message) {
        var box = document.querySelector('[data-ps-toast]');

        if (!box) {
            return;
        }
        box.textContent = message;
        box.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            box.hidden = true;
        }, 1600);
    }

    function copy(text, label) {
        var done = function () {
                toast('Copied ' + (label || '') + (text.length > 60 ? '' : ': ' + text));
            },
            fallback = function () {
                var area = el('textarea');

                area.value = text;
                area.setAttribute('readonly', '');
                area.style.position = 'fixed';
                area.style.opacity = '0';
                document.body.appendChild(area);
                area.select();
                document.execCommand('copy');
                document.body.removeChild(area);
                done();
            };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, fallback);
        } else {
            fallback();
        }
    }

    /* ---------- JSON tree ---------- */

    function matches(key, value, needle) {
        if (String(key).toLowerCase().indexOf(needle) !== -1) {
            return true;
        }
        if (isContainer(value)) {
            return Object.keys(value).some(function (k) {
                return matches(k, value[k], needle);
            });
        }

        return String(value).toLowerCase().indexOf(needle) !== -1;
    }

    function primitiveNode(value) {
        var type = value === null ? 'null' : typeof value,
            text = type === 'string' ? '"' + value + '"' : String(value);

        return el('span', 'ps-json-' + type, text);
    }

    function renderNode(key, value, depth, path, needle) {
        var node = el('div', 'ps-node'),
            row = el('div', 'ps-node__row'),
            container = isContainer(value),
            isArray = Array.isArray(value),
            keys = container ? Object.keys(value) : [],
            open = needle ? true : depth < OPEN_DEPTH,
            toggle,
            children,
            copyBtn;

        if (container && keys.length) {
            toggle = el('button', 'ps-node__toggle', open ? '▾' : '▸');
            toggle.type = 'button';
            toggle.setAttribute('aria-expanded', String(open));
            row.appendChild(toggle);
        } else {
            row.appendChild(el('span', 'ps-node__spacer'));
        }

        if (key !== null) {
            var keyEl = el('span', 'ps-node__key', typeof key === 'number' ? String(key) : '"' + key + '"');

            keyEl.title = 'Click to copy path: ' + path;
            keyEl.setAttribute('data-ps-path', path);
            row.appendChild(keyEl);
            row.appendChild(el('span', 'ps-node__colon', ':'));
        }

        if (container) {
            row.appendChild(el('span', 'ps-json-bracket', isArray ? '[' : '{'));
            if (keys.length) {
                row.appendChild(el('span', 'ps-node__preview',
                    isArray ? keys.length + (keys.length === 1 ? ' item' : ' items')
                        : keys.length + (keys.length === 1 ? ' key' : ' keys')));
                row.appendChild(el('span', 'ps-json-bracket ps-node__close-inline', isArray ? ']' : '}'));
            } else {
                row.appendChild(el('span', 'ps-json-bracket', isArray ? ']' : '}'));
            }
        } else {
            row.appendChild(primitiveNode(value));
        }

        copyBtn = el('button', 'ps-node__copy', 'copy');
        copyBtn.type = 'button';
        copyBtn.title = container ? 'Copy this ' + (isArray ? 'array' : 'object') + ' as JSON' : 'Copy value';
        $(copyBtn).data('psValue', value);
        row.appendChild(copyBtn);
        node.appendChild(row);

        if (container && keys.length) {
            children = el('div', 'ps-node__children');
            keys.forEach(function (k) {
                var childKey = isArray ? Number(k) : k;

                if (!needle || matches(k, value[k], needle)) {
                    children.appendChild(renderNode(childKey, value[k], depth + 1, childPath(path, k, isArray), needle));
                }
            });
            children.appendChild(el('div', 'ps-json-bracket ps-node__close', isArray ? ']' : '}'));
            node.appendChild(children);
            if (!open) {
                node.classList.add('is-collapsed');
            }
        }

        return node;
    }

    function setOpen(node, open) {
        var toggle = node.querySelector(':scope > .ps-node__row > .ps-node__toggle');

        if (!toggle) {
            return;
        }
        node.classList.toggle('is-collapsed', !open);
        toggle.textContent = open ? '▾' : '▸';
        toggle.setAttribute('aria-expanded', String(open));
    }

    function buildTreePane(pane, data) {
        var tools = el('div', 'ps-json-tools'),
            expand = el('button', 'ps-btn ps-btn--small', 'Expand all'),
            collapse = el('button', 'ps-btn ps-btn--small', 'Collapse all'),
            filter = el('input', 'ps-filter'),
            tree = el('div', 'ps-tree'),
            draw = function () {
                var needle = filter.value.trim().toLowerCase();

                tree.textContent = '';
                if (needle && !matches('', data, needle)) {
                    tree.appendChild(el('div', 'ps-muted', 'No match.'));
                    return;
                }
                tree.appendChild(renderNode(null, data, 0, '', needle));
            };

        expand.type = collapse.type = 'button';
        filter.type = 'search';
        filter.placeholder = 'Filter keys / values…';
        tools.appendChild(expand);
        tools.appendChild(collapse);
        tools.appendChild(filter);
        pane.appendChild(tools);
        pane.appendChild(tree);

        expand.addEventListener('click', function () {
            tree.querySelectorAll('.ps-node').forEach(function (n) {
                setOpen(n, true);
            });
        });
        collapse.addEventListener('click', function () {
            tree.querySelectorAll('.ps-node').forEach(function (n, i) {
                setOpen(n, i === 0);
            });
        });
        filter.addEventListener('input', draw);
        draw();
    }

    /* ---------- flattened key/value ("without tree") ---------- */

    function flatten(value, path, out) {
        if (isContainer(value) && Object.keys(value).length) {
            Object.keys(value).forEach(function (k) {
                flatten(value[k], childPath(path, k, Array.isArray(value)), out);
            });
        } else {
            out.push([path || '(value)', value]);
        }

        return out;
    }

    function buildKv(list, data) {
        flatten(data, '', []).forEach(function (pair) {
            var row = el('div', 'ps-kv__row'),
                dt = el('dt', 'ps-kv__key', pair[0]),
                dd = el('dd', 'ps-kv__value'),
                value = pair[1];

            dt.title = 'Click to copy path';
            dt.setAttribute('data-ps-path', pair[0]);
            if (isContainer(value)) {
                dd.appendChild(el('span', 'ps-muted', Array.isArray(value) ? '[ ]' : '{ }'));
            } else {
                dd.appendChild(primitiveNode(value));
            }
            dd.setAttribute('data-ps-copy-text', isContainer(value) ? JSON.stringify(value) : String(value));
            dd.title = 'Click to copy value';
            row.appendChild(dt);
            row.appendChild(dd);
            list.appendChild(row);
        });
    }

    /* ---------- sections ---------- */

    function readData(section) {
        var script = section.querySelector('[data-ps-data]');

        try {
            return JSON.parse(script.textContent);
        } catch (e) {
            return script.textContent;
        }
    }

    function setMode(section, mode) {
        var jsonPane = section.querySelector('[data-ps-pane="json"]');

        if (mode === 'json' && !jsonPane.hasChildNodes()) {
            buildTreePane(jsonPane, readData(section));
        }
        section.querySelectorAll('[data-ps-pane]').forEach(function (pane) {
            pane.hidden = pane.getAttribute('data-ps-pane') !== mode;
        });
        section.querySelectorAll('.ps-section__head [data-ps-mode]').forEach(function (btn) {
            btn.classList.toggle('is-active', btn.getAttribute('data-ps-mode') === mode);
        });
    }

    function setGlobalMode(root, mode) {
        root.querySelectorAll('[data-ps-section]').forEach(function (section) {
            setMode(section, mode);
        });
        root.querySelectorAll('[data-ps-global] [data-ps-mode]').forEach(function (btn) {
            btn.classList.toggle('is-active', btn.getAttribute('data-ps-mode') === mode);
        });
        try {
            localStorage.setItem(STORAGE_KEY, mode);
        } catch (e) {
            // storage unavailable — mode just isn't remembered
        }
    }

    return function () {
        var root = document.querySelector('.ps-view'),
            saved;

        if (!root) {
            return;
        }

        root.querySelectorAll('[data-ps-section]').forEach(function (section) {
            var kv = section.querySelector('[data-ps-kv]');

            if (kv) {
                buildKv(kv, readData(section));
            }
        });

        $(root)
            .on('click', '.ps-section__head [data-ps-mode]', function () {
                setMode(this.closest('[data-ps-section]'), this.getAttribute('data-ps-mode'));
            })
            .on('click', '[data-ps-global] [data-ps-mode]', function () {
                setGlobalMode(root, this.getAttribute('data-ps-mode'));
            })
            .on('click', '[data-ps-copy]', function () {
                copy(JSON.stringify(readData(this.closest('[data-ps-section]')), null, 2), 'section JSON');
            })
            .on('click', '.ps-node__toggle', function () {
                var node = this.closest('.ps-node');

                setOpen(node, node.classList.contains('is-collapsed'));
            })
            .on('click', '.ps-node__preview', function () {
                setOpen(this.closest('.ps-node'), true);
            })
            .on('click', '.ps-node__copy', function () {
                var value = $(this).data('psValue');

                copy(stringify(value), Array.isArray(value) ? 'array' : isContainer(value) ? 'object' : 'value');
            })
            .on('click', '[data-ps-path]', function () {
                copy(this.getAttribute('data-ps-path'), 'path');
            })
            .on('click', '[data-ps-copy-text]', function () {
                copy(this.getAttribute('data-ps-copy-text'), 'value');
            });

        try {
            saved = localStorage.getItem(STORAGE_KEY);
        } catch (e) {
            saved = null;
        }
        if (saved === 'json') {
            setGlobalMode(root, 'json');
        }
    };
});
