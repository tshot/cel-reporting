/**
 * weekly_meeting.js
 *
 * Weekly Meeting Report — client-side logic.
 *
 * Table is rendered server-side (PHP) for fast load and print fidelity.
 * This file handles only CSV download.
 *
 * Expects globals injected by WeeklyMeetingHtmlExporter:
 *   window.WM_BY_SITE   — { site_code: { metric: count } }
 *   window.WM_METRICS   — { metric: { label, section, role? } }
 *   window.WM_SITE_LBLS — { site_code: display_label }
 *   window.WM_COLUMNS   — [ 'Total', 'GSVM', ... ]  ordered columns
 */
(function () {
    'use strict';

    window.wmDownloadCsv = function () {
        var bySite  = window.WM_BY_SITE   || {};
        var metrics = window.WM_METRICS   || {};
        var lbls    = window.WM_SITE_LBLS || {};
        var cols    = window.WM_COLUMNS   || [];

        var header = ['Section', 'Metric'].concat(cols.map(function (c) {
            return c === 'Total' ? 'Total' : (lbls[c] || c);
        }));

        var rows = [header];
        for (var metric in metrics) {
            if (!metrics.hasOwnProperty(metric)) continue;
            var m   = metrics[metric];
            var row = [m.section || '', m.label || metric];
            cols.forEach(function (col) {
                row.push((bySite[col] || {})[metric] || 0);
            });
            rows.push(row);
        }

        var csv  = rows.map(function (r) {
            return r.map(function (v) {
                return '"' + String(v).replace(/"/g, '""') + '"';
            }).join(',');
        }).join('\r\n');

        var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        var a    = document.createElement('a');
        a.href     = URL.createObjectURL(blob);
        a.download = 'weekly_meeting.csv';
        a.click();
        URL.revokeObjectURL(a.href);
    };

}());
