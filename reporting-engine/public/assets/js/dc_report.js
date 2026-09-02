/**
 * dc_report.js
 *
 * Data Collector Performance Report — client-side logic.
 *
 * Expects these globals injected by DataCollectorHtmlExporter:
 *   window.DC_BY_DC        — metric => { dc_id => count, Total => count }
 *   window.DC_METRIC_META  — metric => { label, section, role, subsection, denominator }
 *   window.DC_LIST         — dc_id  => { site, total }
 *   window.DC_WT_HIST      — dc_id  => { bin_label => count }
 *   window.DC_WT_BINS      — ordered array of bin label strings e.g. ['500-504', ...]
 *   window.DC_SITE_LABELS  — site_code => display label
 *
 * Chart.js must be loaded before this script.
 */
(function () {
    'use strict';

    /* ── Colour palette ────────────────────────────────────────────────── */
    var COLORS = [
        '#1565c0','#2e7d52','#c62828','#f57f17','#6a1b9a',
        '#00838f','#558b2f','#4527a0','#d84315','#ad1457',
        '#37474f','#00695c','#0277bd','#4e342e','#1b5e20',
    ];

    var exclChart  = null;
    var wtChart    = null;
    var freqChart  = null;

    /* ── Public API ────────────────────────────────────────────────────── */

    // Reload the page with updated date_from/date_to query params.
    // The backend re-runs the aggregator with the new date range.
    window.dcApplyDateFilter = function () {
        var from = document.getElementById('dateFrom');
        var to   = document.getElementById('dateTo');
        var url  = new URL(window.location.href);
        if (from && from.value) url.searchParams.set('date_from', from.value);
        else                    url.searchParams.delete('date_from');
        if (to && to.value)     url.searchParams.set('date_to', to.value);
        else                    url.searchParams.delete('date_to');
        window.location.href = url.toString();
    };

    window.dcRefresh = function () {
        var dcs = getVisibleDcs();
        buildTable(dcs);
        buildExclChart(dcs);
        buildWtChart(dcs);
        buildFreqTable(dcs);
        buildFreqChart(dcs);
        buildCompletionTable(dcs);
    };

    // Change size of all chart containers and resize charts
    window.dcSetChartSize = function (sizeClass) {
        var sizes = ['dc-chart-sm','dc-chart-md','dc-chart-lg','dc-chart-xl','dc-chart-full'];
        document.querySelectorAll('.dc-chart-wrap').forEach(function (el) {
            sizes.forEach(function (s) { el.classList.remove(s); });
            el.classList.add(sizeClass);
        });
        // Trigger Chart.js resize on all active charts
        [exclChart, wtChart, freqChart].forEach(function (c) {
            if (c) c.resize();
        });
    };

    window.dcDownloadCsv = function () {
        var dcs  = getVisibleDcs();
        var meta = window.DC_METRIC_META || {};
        var byDc = window.DC_BY_DC       || {};
        var rows = [['Metric', 'Total'].concat(dcs)];
        for (var metric in meta) {
            if (!meta.hasOwnProperty(metric)) continue;
            var counts = byDc[metric] || {};
            var row    = [meta[metric].label, counts.Total || 0];
            dcs.forEach(function (dc) { row.push(counts[dc] || 0); });
            rows.push(row);
        }
        var csv  = rows.map(function (r) {
            return r.map(function (v) {
                return '"' + String(v).replace(/"/g, '""') + '"';
            }).join(',');
        }).join('\r\n');
        var blob = new Blob([csv], { type: 'text/csv' });
        var a    = document.createElement('a');
        a.href     = URL.createObjectURL(blob);
        a.download = 'dc_prescreening.csv';
        a.click();
    };

    /* ── Helpers ───────────────────────────────────────────────────────── */
    function getVisibleDcs() {
        var siteEl = document.getElementById('siteFilter');
        var site   = siteEl ? siteEl.value : '';
        var dcList = window.DC_LIST || {};
        return Object.keys(dcList).filter(function (dc) {
            return !site || dcList[dc].site === site;
        });
    }

    function color(i, alpha) {
        var hex = COLORS[i % COLORS.length];
        return hex + (alpha || 'cc');
    }

    /* ── Table ─────────────────────────────────────────────────────────── */
    function buildTable(dcs) {
        var thead   = document.getElementById('excl-thead');
        var tbody   = document.getElementById('excl-tbody');
        if (!thead || !tbody) return;

        var dcList     = window.DC_LIST        || {};
        var byDc       = window.DC_BY_DC       || {};
        var meta       = window.DC_METRIC_META || {};
        var siteLabels = window.DC_SITE_LABELS || {};

        /* Group DCs by site (preserving DC_LIST order) */
        var bySite = {};
        var siteOrder = [];
        dcs.forEach(function (dc) {
            var site = (dcList[dc] || {}).site || 'Unknown';
            if (!bySite[site]) { bySite[site] = []; siteOrder.push(site); }
            bySite[site].push(dc);
        });

        /* Compute site totals from babies_prescreened counts */
        var prescreenedCounts = (byDc['babies_prescreened'] || {});
        var grandTotal = prescreenedCounts['Total'] || 0;

        var siteTotals = {};
        siteOrder.forEach(function (site) {
            var t = 0;
            bySite[site].forEach(function (dc) { t += prescreenedCounts[dc] || 0; });
            siteTotals[site] = t;
        });

        /* Row 1 — Total header + site headers with site N total */
        var r1 = '<tr><th rowspan="2" class="dc-metric-col">Exclusion Criterion</th>';
        r1 += '<th rowspan="2" class="dc-col-hdr dc-total-col">Total<br><small>N=' + grandTotal + '</small></th>';
        siteOrder.forEach(function (site) {
            var lbl  = (siteLabels[site] || site) + '<br><small>N=' + siteTotals[site] + '</small>';
            var span = bySite[site].length;
            r1 += '<th colspan="' + span + '" class="dc-site-hdr">' + lbl + '</th>';
        });
        r1 += '</tr>';

        /* Row 2 — DC names only (N is on the site header) */
        var r2 = '<tr>';
        dcs.forEach(function (dc) {
            r2 += '<th class="dc-col-hdr" title="' + esc(dc) + '">' + esc(dc) + '</th>';
        });
        r2 += '</tr>';
        thead.innerHTML = r1 + r2;

        /* Body — Total column uses sum across visible DCs, not the global Total key */
        var prescreened = byDc['babies_prescreened'] || {};
        var visibleTotal = 0;
        dcs.forEach(function (dc) { visibleTotal += prescreened[dc] || 0; });

        var html = '';
        for (var metric in meta) {
            if (!meta.hasOwnProperty(metric)) continue;
            var m      = meta[metric];
            var counts = byDc[metric] || {};
            var isPrim = m.role === 'primary';
            var cls    = isPrim ? 'dc-primary' : '';

            /* Compute visible total for this metric */
            var metricVisTotal = 0;
            dcs.forEach(function (dc) { metricVisTotal += counts[dc] || 0; });
            var totDen = visibleTotal;
            var totPct = (!isPrim && totDen > 0)
                ? '<br><small>' + (metricVisTotal / totDen * 100).toFixed(1) + '%</small>'
                : '';

            html += '<tr class="' + cls + '"><td class="dc-metric-label">' + esc(m.label) + '</td>';
            /* Total column — sum of visible DCs */
            html += '<td class="dc-total-val">' + metricVisTotal + totPct + '</td>';
            dcs.forEach(function (dc) {
                var v   = counts[dc]  || 0;
                var den = prescreened[dc] || 0;
                var pct = (!isPrim && den > 0)
                    ? '<br><small>' + (v / den * 100).toFixed(1) + '%</small>'
                    : '';
                html += '<td>' + v + pct + '</td>';
            });
            html += '</tr>';
        }
        tbody.innerHTML = html;
    }

    /* ── Exclusion bar chart ────────────────────────────────────────────── */
    function buildExclChart(dcs) {
        var canvas = document.getElementById('excl-chart');
        if (!canvas) return;
        if (exclChart) { exclChart.destroy(); exclChart = null; }

        var byDc       = window.DC_BY_DC       || {};
        var meta       = window.DC_METRIC_META || {};
        var dcList     = window.DC_LIST        || {};

        /* Exclusion criteria only — not primary rows */
        var exclMetrics = [];
        for (var k in meta) {
            if (meta.hasOwnProperty(k) && !meta[k].role && meta[k].section === 'PreScreened') {
                exclMetrics.push([k, meta[k]]);
            }
        }

        var labels   = exclMetrics.map(function (e) { return e[1].label; });
        var datasets = dcs.map(function (dc, i) {
            return {
                label:           dc,
                data:            exclMetrics.map(function (e) { return (byDc[e[0]] || {})[dc] || 0; }),
                backgroundColor: color(i, 'cc'),
                borderColor:     COLORS[i % COLORS.length],
                borderWidth:     1,
            };
        });

        var prescreened = byDc['babies_prescreened'] || {};
        exclChart = new Chart(canvas, {
            type: 'bar',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } },
                    tooltip: {
                        callbacks: {
                            afterLabel: function (ctx) {
                                var dc  = dcs[ctx.datasetIndex];
                                var den = prescreened[dc] || 0;
                                if (!den) return '';
                                return (ctx.raw / den * 100).toFixed(1) + '% of N=' + den;
                            },
                        },
                    },
                },
                scales: {
                    x: { ticks: { font: { size: 10 } } },
                    y: { beginAtZero: true, title: { display: true, text: 'Count' } },
                },
            },
        });
    }

    /* ── Birth weight histogram ─────────────────────────────────────────── */
    function buildWtChart(dcs) {
        var canvas = document.getElementById('wt-chart');
        if (!canvas) return;
        if (wtChart) { wtChart.destroy(); wtChart = null; }

        var wtHist = window.DC_WT_HIST || {};
        var wtBins = window.DC_WT_BINS || [];

        /* Only DCs with weight data */
        var activeDcs = dcs.filter(function (dc) {
            return wtHist[dc] && Object.keys(wtHist[dc]).length > 0;
        });
        if (!activeDcs.length) return;

        var datasets = activeDcs.map(function (dc, i) {
            return {
                label:           dc,
                data:            wtBins.map(function (bin) { return (wtHist[dc] || {})[bin] || 0; }),
                backgroundColor: color(i, 'aa'),
                borderColor:     COLORS[i % COLORS.length],
                borderWidth:     1,
            };
        });

        wtChart = new Chart(canvas, {
            type: 'bar',
            data: { labels: wtBins, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } },
                },
                scales: {
                    x: {
                        title: { display: true, text: 'NICU Admission Weight (grams)' },
                        ticks: { maxRotation: 90, font: { size: 9 } },
                    },
                    y: { beginAtZero: true, title: { display: true, text: 'Count' } },
                },
            },
        });
    }

    /* ── Utility ────────────────────────────────────────────────────────── */
    function esc(str) {
        return String(str)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    /* ── Field frequency table ─────────────────────────────────────────── */
    function buildFreqTable(dcs) {
        var thead = document.getElementById('freq-thead');
        var tbody = document.getElementById('freq-tbody');
        if (!thead || !tbody) return;

        var fieldFreq  = window.DC_FIELD_FREQ  || {};
        var freqFields = window.DC_FREQ_FIELDS || {};
        var dcList     = window.DC_LIST        || {};
        var siteLabels = window.DC_SITE_LABELS || {};

        // Populate field selector on first build
        var selector = document.getElementById('freqFieldSelector');
        if (selector && selector.options.length === 0) {
            for (var f in freqFields) {
                if (!freqFields.hasOwnProperty(f)) continue;
                var opt = document.createElement('option');
                opt.value = f; opt.textContent = freqFields[f];
                selector.appendChild(opt);
            }
        }
        var selectedField = selector ? selector.value : Object.keys(freqFields)[0];
        if (!selectedField) return;

        // Collect all values seen across all DCs for this field
        var allValues = {};
        ['Total'].concat(dcs).forEach(function (dc) {
            var vals = ((fieldFreq[dc] || {})[selectedField]) || {};
            for (var v in vals) { if (vals.hasOwnProperty(v)) allValues[v] = true; }
        });
        // Sort: non-blank values alphabetically, (blank) last
        var valueOrder = Object.keys(allValues).filter(function (v) { return v !== '(blank)'; }).sort();
        if (allValues['(blank)']) valueOrder.push('(blank)');

        // Build DC groups for header
        var bySite = {}, siteOrder = [];
        dcs.forEach(function (dc) {
            var site = (dcList[dc] || {}).site || 'Unknown';
            if (!bySite[site]) { bySite[site] = []; siteOrder.push(site); }
            bySite[site].push(dc);
        });

        var r1 = '<tr><th rowspan="2" class="dc-metric-col">Value</th>';
        r1 += '<th rowspan="2" class="dc-col-hdr dc-total-col">Total</th>';
        siteOrder.forEach(function (site) {
            var lbl  = siteLabels[site] || site;
            r1 += '<th colspan="' + bySite[site].length + '" class="dc-site-hdr">' + esc(lbl) + '</th>';
        });
        r1 += '</tr><tr>';
        dcs.forEach(function (dc) { r1 += '<th class="dc-col-hdr">' + esc(dc) + '</th>'; });
        r1 += '</tr>';
        thead.innerHTML = r1;

        var prescreened = (window.DC_BY_DC || {})['babies_prescreened'] || {};
        var html = '';
        valueOrder.forEach(function (val) {
            var isBlank = val === '(blank)';
            var cls     = isBlank ? 'dc-blank-row' : '';
            var totalN  = ((fieldFreq['Total'] || {})[selectedField] || {})[val] || 0;
            var totalD  = prescreened['Total'] || 1;
            var totPct  = (totalN / totalD * 100).toFixed(1);

            html += '<tr class="' + cls + '"><td class="dc-metric-label">' + esc(val) + '</td>';
            html += '<td class="dc-total-val">' + totalN + '<br><small>' + totPct + '%</small></td>';

            dcs.forEach(function (dc) {
                var n   = ((fieldFreq[dc] || {})[selectedField] || {})[val] || 0;
                var den = prescreened[dc] || 1;
                var pct = (n / den * 100).toFixed(1);
                // Flag if >15 percentage points from total
                var diff = Math.abs(parseFloat(pct) - parseFloat(totPct));
                var flag = (diff > 15 && den >= 10) ? ' dc-flag' : '';
                html += '<td class="' + flag.trim() + '">' + n + '<br><small>' + pct + '%</small></td>';
            });
            html += '</tr>';
        });
        tbody.innerHTML = html;

        buildFreqChart(dcs, selectedField, valueOrder);
    }

    /* ── Field frequency chart ──────────────────────────────────────────── */
    function buildFreqChart(dcs, field, valueOrder) {
        var canvas = document.getElementById('freq-chart');
        if (!canvas) return;
        if (freqChart) { freqChart.destroy(); freqChart = null; }

        var selectedField = field;
        if (!selectedField) {
            var sel = document.getElementById('freqFieldSelector');
            selectedField = sel ? sel.value : null;
        }
        if (!selectedField) return;

        var fieldFreq = window.DC_FIELD_FREQ || {};
        var vals      = valueOrder || [];
        if (!vals.length) {
            var allV = {};
            dcs.concat(['Total']).forEach(function (dc) {
                var fv = ((fieldFreq[dc] || {})[selectedField]) || {};
                for (var v in fv) { if (fv.hasOwnProperty(v)) allV[v] = true; }
            });
            vals = Object.keys(allV).filter(function (v) { return v !== '(blank)'; }).sort();
            if (allV['(blank)']) vals.push('(blank)');
        }

        var prescreened = (window.DC_BY_DC || {})['babies_prescreened'] || {};
        var datasets = dcs.map(function (dc, i) {
            var den = prescreened[dc] || 1;
            return {
                label:           dc,
                data:            vals.map(function (v) {
                    var n = ((fieldFreq[dc] || {})[selectedField] || {})[v] || 0;
                    return parseFloat((n / den * 100).toFixed(1));
                }),
                backgroundColor: COLORS[i % COLORS.length] + 'cc',
                borderColor:     COLORS[i % COLORS.length],
                borderWidth:     1,
            };
        });

        var freqFields = window.DC_FREQ_FIELDS || {};
        freqChart = new Chart(canvas, {
            type: 'bar',
            data: { labels: vals, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } },
                    title: {
                        display: true,
                        text: '% distribution — ' + (freqFields[selectedField] || selectedField),
                    },
                },
                scales: {
                    x: { ticks: { font: { size: 11 } } },
                    y: { beginAtZero: true, title: { display: true, text: '% of babies prescreened' } },
                },
            },
        });
    }

    /* ── Completion rate table ──────────────────────────────────────────── */
    function buildCompletionTable(dcs) {
        var thead = document.getElementById('comp-thead');
        var tbody = document.getElementById('comp-tbody');
        if (!thead || !tbody) return;

        var completion  = window.DC_COMPLETION  || {};
        var expFields   = window.DC_EXP_FIELDS  || [];
        var dcList      = window.DC_LIST        || {};
        var siteLabels  = window.DC_SITE_LABELS || {};

        // Header
        var bySite = {}, siteOrder = [];
        dcs.forEach(function (dc) {
            var site = (dcList[dc] || {}).site || 'Unknown';
            if (!bySite[site]) { bySite[site] = []; siteOrder.push(site); }
            bySite[site].push(dc);
        });

        var r1 = '<tr><th rowspan="2" class="dc-metric-col">Field</th>';
        r1 += '<th rowspan="2" class="dc-col-hdr dc-total-col">Total</th>';
        siteOrder.forEach(function (site) {
            r1 += '<th colspan="' + bySite[site].length + '" class="dc-site-hdr">'
                + esc(siteLabels[site] || site) + '</th>';
        });
        r1 += '</tr><tr>';
        dcs.forEach(function (dc) { r1 += '<th class="dc-col-hdr">' + esc(dc) + '</th>'; });
        r1 += '</tr>';
        thead.innerHTML = r1;

        // Body — one row per expected field
        var html = '';
        expFields.forEach(function (field) {
            var totData = (completion['Total'] || {})[field] || {};
            var totFill = totData.filled || 0;
            var totN    = totData.total  || 1;
            var totPct  = (totFill / totN * 100).toFixed(1);

            html += '<tr><td class="dc-metric-label">' + esc(field) + '</td>';
            html += '<td class="dc-total-val">' + totPct + '%</td>';

            dcs.forEach(function (dc) {
                var d    = (completion[dc] || {})[field] || {};
                var fill = d.filled || 0;
                var n    = d.total  || 1;
                var pct  = (fill / n * 100).toFixed(1);
                // Flag if completion is more than 10pp below site average
                var diff = parseFloat(totPct) - parseFloat(pct);
                var flag = (diff > 10 && n >= 10) ? 'dc-flag' : '';
                html += '<td class="' + flag + '">' + pct + '%</td>';
            });
            html += '</tr>';
        });
        tbody.innerHTML = html;
    }

    /* ── Init ───────────────────────────────────────────────────────────── */
    document.addEventListener('DOMContentLoaded', function () {
        var bin  = window.DC_WT_BIN || 50;
        var hint = document.getElementById('wt-bin-hint');
        if (hint) hint.textContent = '500–1800g range, bin = ' + bin + 'g. Only babies where weight was recorded.';
        window.dcRefresh();
    });

}());
