/**
 * section-export.js
 *
 * Section export page interactivity:
 *   - Colour pickers (header + section rows)
 *   - Column manager panel (show/hide + drag-to-reorder)
 *   - Copy as PNG image (via html2canvas — must be served locally)
 *   - Download standalone HTML (captures current DOM state including edits)
 *   - Copy table as TSV for Excel
 *
 * Expects globals injected by the PHP page:
 *   window.SECTION_HAS_COLUMNS  — boolean
 *   window.SECTION_COL_DEFS     — [{idx, label}, ...]
 *   window.SECTION_NAME         — string (e.g. 'screening')
 */
(function () {
    'use strict';

    var HAS_COLUMNS = window.SECTION_HAS_COLUMNS || false;
    var COL_DEFS    = window.SECTION_COL_DEFS    || [];
    var SECTION     = window.SECTION_NAME        || 'section';

    // ── Show column button only when applicable ───────────────────────────
    if (HAS_COLUMNS && COL_DEFS.length > 0) {
        var toggleBtn = document.getElementById('col-toggle-btn');
        if (toggleBtn) toggleBtn.style.display = '';
        buildColPanel();
    }

    // ── Colour pickers ────────────────────────────────────────────────────
    var hdrPicker = document.getElementById('hdr-color');
    var secPicker = document.getElementById('sec-color');

    if (hdrPicker) {
        hdrPicker.addEventListener('input', function () {
            document.querySelectorAll('.report-table thead th').forEach(function (el) {
                el.style.backgroundColor = hdrPicker.value;
            });
        });
    }

    if (secPicker) {
        secPicker.addEventListener('input', function () {
            document.querySelectorAll(
                '.report-table tr.section-main td, .report-table tr.section-sub td'
            ).forEach(function (el) {
                el.style.backgroundColor = secPicker.value;
            });
        });
    }

    // ── Column panel toggle ───────────────────────────────────────────────
    window.toggleColPanel = function () {
        var panel = document.getElementById('col-panel');
        var btn   = document.getElementById('col-toggle-btn');
        if (!panel) return;
        panel.classList.toggle('open');
        if (btn) btn.classList.toggle('active');
    };

    // ── Build column panel list ───────────────────────────────────────────
    function buildColPanel() {
        var list = document.getElementById('col-list');
        if (!list) return;
        list.innerHTML = '';
        COL_DEFS.forEach(function (col) {
            var item         = document.createElement('div');
            item.className   = 'col-item';
            item.draggable   = true;
            item.dataset.idx = col.idx;
            item.innerHTML   =
                '<span class="drag-handle">&#9776;</span>' +
                '<input type="checkbox" checked id="chk-' + col.idx + '">' +
                '<label class="col-label" for="chk-' + col.idx + '">' +
                    escHtml(col.label) +
                '</label>';
            attachDragEvents(item);
            list.appendChild(item);
        });
    }

    // ── Apply column visibility and order to the table ────────────────────
    window.applyColumns = function () {
        var items   = document.querySelectorAll('#col-list .col-item');
        var ordered = [];
        items.forEach(function (item) {
            ordered.push({
                idx:     parseInt(item.dataset.idx),
                visible: item.querySelector('input[type=checkbox]').checked
            });
        });

        document.querySelectorAll('.report-table').forEach(function (tbl) {
            tbl.querySelectorAll('tr').forEach(function (tr) {
                var cells     = Array.from(tr.querySelectorAll('th, td'));
                var dataCells = cells.slice(1);
                var cellMap   = {};
                dataCells.forEach(function (cell, i) { cellMap[i + 1] = cell; });
                dataCells.forEach(function (cell) { cell.remove(); });
                ordered.forEach(function (o) {
                    var cell = cellMap[o.idx];
                    if (!cell) return;
                    cell.style.display = o.visible ? '' : 'none';
                    tr.appendChild(cell);
                });
            });
        });
    };

    // ── Drag-and-drop reorder within col-list ─────────────────────────────
    var dragSrc = null;

    function attachDragEvents(item) {
        item.addEventListener('dragstart', function (e) {
            dragSrc = item;
            item.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });
        item.addEventListener('dragend', function () {
            item.classList.remove('dragging');
            document.querySelectorAll('.col-item').forEach(function (i) {
                i.classList.remove('drag-over');
            });
        });
        item.addEventListener('dragover', function (e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (item !== dragSrc) item.classList.add('drag-over');
        });
        item.addEventListener('dragleave', function () {
            item.classList.remove('drag-over');
        });
        item.addEventListener('drop', function (e) {
            e.preventDefault();
            item.classList.remove('drag-over');
            if (dragSrc && dragSrc !== item) {
                var list  = document.getElementById('col-list');
                var items = Array.from(list.children);
                var srcI  = items.indexOf(dragSrc);
                var tgtI  = items.indexOf(item);
                list.insertBefore(dragSrc, srcI < tgtI ? item.nextSibling : item);
            }
        });
    }

    // ── Copy section as PNG ───────────────────────────────────────────────
    // Requires html2canvas.min.js served from /assets/js/html2canvas.min.js
    window.copyImage = function () {
        var wrap = document.getElementById('section-wrap');

        if (typeof html2canvas === 'undefined') {
            alert('html2canvas is not loaded.\n\nAsk your server admin to download it:\n' +
                  'https://html2canvas.hertzen.com/dist/html2canvas.min.js\n' +
                  'and place it at: /assets/js/html2canvas.min.js');
            return;
        }

        html2canvas(wrap, { scale: 2, backgroundColor: '#ffffff' }).then(function (canvas) {
            canvas.toBlob(function (blob) {
                // Try modern Clipboard API (requires HTTPS or localhost)
                if (navigator.clipboard && window.ClipboardItem) {
                    navigator.clipboard
                        .write([new ClipboardItem({ 'image/png': blob })])
                        .then(function () {
                            alert('Image copied to clipboard — paste into PowerPoint.');
                        })
                        .catch(function () {
                            // HTTPS not available (e.g. plain HTTP internal server)
                            // Fall back to opening image in new tab
                            openImageFallback(canvas);
                        });
                } else {
                    openImageFallback(canvas);
                }
            });
        });
    };

    function openImageFallback(canvas) {
        var win = window.open('', '_blank');
        win.document.write(
            '<html><body style="margin:0;background:#888">' +
            '<p style="font-family:sans-serif;padding:8px;background:#fff;margin:0">' +
            'Clipboard not available on plain HTTP. Right-click the image and choose ' +
            '<strong>Copy Image</strong> or <strong>Save Image As</strong>.</p>' +
            '<img src="' + canvas.toDataURL('image/png') + '" style="display:block;max-width:100%">' +
            '</body></html>'
        );
    }

    // ── Download standalone HTML — captures current DOM state ────────────
    // Serialises the current page including any column changes and colour
    // picks already applied, then forces a download. Does NOT re-hit the
    // server, so what you see is exactly what you get in the file.
    window.downloadHtml = function () {
        // Collect current inline styles from header and section cells
        // so the downloaded file reflects colour picker changes
        var styles = collectAppliedStyles();

        var wrap    = document.getElementById('section-wrap');
        var toolbar = document.getElementById('export-toolbar');
        var panel   = document.getElementById('col-panel');

        // Temporarily hide UI chrome so it isn't included in the snapshot
        if (toolbar) toolbar.style.display = 'none';
        if (panel)   panel.style.display   = 'none';

        var html = '<!DOCTYPE html><html><head><meta charset="UTF-8">' +
                   '<title>' + escHtml(document.title) + '</title>' +
                   '<style>' + getInlineStyles() + styles + '</style>' +
                   '</head><body style="margin:20px;background:#fff">' +
                   wrap.innerHTML +
                   '</body></html>';

        // Restore UI
        if (toolbar) toolbar.style.display = '';
        if (panel)   panel.style.display   = '';

        var blob = new Blob([html], { type: 'text/html' });
        var a    = document.createElement('a');
        a.href     = URL.createObjectURL(blob);
        a.download = SECTION + '.html';
        a.click();
        URL.revokeObjectURL(a.href);
    };

    // Collect inline styles applied by colour pickers so they survive download
    function collectAppliedStyles() {
        var rules = [];
        document.querySelectorAll('.report-table thead th').forEach(function (el) {
            if (el.style.backgroundColor)
                rules.push('thead th { background-color: ' + el.style.backgroundColor + ' !important; }');
        });
        document.querySelectorAll('.report-table tr.section-main td, .report-table tr.section-sub td')
            .forEach(function (el) {
                if (el.style.backgroundColor) {
                    rules.push('.section-main td, .section-sub td { background-color: ' +
                               el.style.backgroundColor + ' !important; }');
                }
            });
        return rules.length ? rules[0] : '';  // first rule covers all (they're all the same colour)
    }

    // Get the report CSS already embedded in the page <style> tags
    function getInlineStyles() {
        var css = '';
        document.querySelectorAll('style').forEach(function (s) {
            // Skip the section-export UI styles — only keep the report table CSS
            if (s.textContent.indexOf('#export-toolbar') === -1) {
                css += s.textContent;
            }
        });
        return css;
    }

    // ── Copy table as TSV for Excel ───────────────────────────────────────
    window.copyTable = function () {
        var tables = document.querySelectorAll('.report-table');
        if (!tables.length) {
            alert('This section has no table to copy.\nUse Print / PDF or Copy Image instead.');
            return;
        }

        var lines = [];
        tables.forEach(function (tbl) {
            tbl.querySelectorAll('tr').forEach(function (tr) {
                var cells = Array.from(tr.querySelectorAll('th, td'))
                    .filter(function (c) { return c.style.display !== 'none'; })
                    .map(function (c) { return c.innerText.replace(/\n/g, ' ').trim(); });
                lines.push(cells.join('\t'));
            });
            lines.push('');
        });

        var tsv = lines.join('\n');

        // Try modern Clipboard API first (works on HTTPS / localhost)
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(tsv)
                .then(function () { alert('Table copied — paste into Excel.'); })
                .catch(function ()  { showTsvFallback(tsv); });
        } else {
            showTsvFallback(tsv);
        }
    };

    // Fallback for plain HTTP: show TSV in a textarea so user can Ctrl+A, Ctrl+C
    function showTsvFallback(tsv) {
        var win = window.open('', '_blank', 'width=700,height=500');
        win.document.write(
            '<html><head><title>Copy table data</title></head>' +
            '<body style="font-family:sans-serif;margin:16px">' +
            '<p><strong>Clipboard not available on plain HTTP.</strong><br>' +
            'Press <kbd>Ctrl+A</kbd> then <kbd>Ctrl+C</kbd> to copy, ' +
            'then paste into Excel.</p>' +
            '<textarea style="width:100%;height:380px;font-family:monospace;font-size:12px" ' +
            'id="tsv-area"></textarea>' +
            '<script>document.getElementById("tsv-area").value = ' +
            JSON.stringify(tsv) + ';' +
            'document.getElementById("tsv-area").select();<\/script>' +
            '</body></html>'
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────
    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

})();
