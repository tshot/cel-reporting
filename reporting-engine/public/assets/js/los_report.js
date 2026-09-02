/**
 * los_report.js
 *
 * Client-side behaviour for the Length of Stay report:
 *   - Chart initialisation (stacked bar, box plot, histogram)
 *   - Per-patient table: live filtering + column sort
 *
 * Expects these globals injected by LengthOfStayHtmlExporter:
 *   window.LOS_SITE_LABELS   — array of site display labels (same order as distribution)
 *   window.LOS_CHART_DIST    — array of {lt7, w7_14, w15_27, day28} per site
 *   window.LOS_CHART_BOX     — array of {label, min, median, max, mean, std} per site
 *   window.LOS_HIST_BUCKETS  — array[28] frequency counts, index 0 = day 1
 *
 * Chart.js must be loaded before this script.
 */
(function () {
    'use strict';

    /* ── Chart colours ──────────────────────────────────────────────────── */
    var BAND_COLORS = {
        lt7:    '#4ade80',
        w7_14:  '#60a5fa',
        w15_27: '#fb923c',
        day28:  '#f87171'
    };

    /* ── 1. Stacked bar — LOS distribution by site ──────────────────────── */
    if (window.ChartDataLabels) {
        Chart.register(ChartDataLabels);
    }
    var barEl = document.getElementById('chartStackedBar');
    if (barEl && window.LOS_SITE_LABELS && window.LOS_CHART_DIST) {
        new Chart(barEl, {
            type: 'bar',
            data: {
                labels: LOS_SITE_LABELS,
                datasets: [
                    {
                        label: '< 7 days',
                        data: LOS_CHART_DIST.map(function (d) { return d.lt7; }),
                        backgroundColor: BAND_COLORS.lt7
                    },
                    {
                        label: '7–14 days',
                        data: LOS_CHART_DIST.map(function (d) { return d.w7_14; }),
                        backgroundColor: BAND_COLORS.w7_14
                    },
                    {
                        label: '15–27 days',
                        data: LOS_CHART_DIST.map(function (d) { return d.w15_27; }),
                        backgroundColor: BAND_COLORS.w15_27
                    },
                    {
                        label: 'Day 28 (in hospital)',
                        data: LOS_CHART_DIST.map(function (d) { return d.day28; }),
                        backgroundColor: BAND_COLORS.day28
                    }
                ]
            },
            options: {
                plugins: {
                    legend: { display: false },
                    datalabels: {
                        display: function (ctx) { return ctx.dataset.data[ctx.dataIndex] > 0; },
                        color: '#fff',
                        font: { size: 11, weight: '600' },
                        formatter: function (value) { return value; },
                        anchor: 'center',
                        align: 'center'
                    }
                },
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        title: { display: true, text: 'Number of patients' }
                    }
                }
            }
        });
    }

    /* ── 2. Box plot approximation (min / mean±SD / max) ───────────────── */
    var boxEl = document.getElementById('chartBoxPlot');
    if (boxEl && window.LOS_CHART_BOX) {
        var boxLabels = LOS_CHART_BOX.map(function (d) { return d.label; });
        var boxMin    = LOS_CHART_BOX.map(function (d) { return d.min   !== null ? d.min   : 0; });
        var boxMax    = LOS_CHART_BOX.map(function (d) { return d.max   !== null ? d.max   : 0; });
        var boxLow    = LOS_CHART_BOX.map(function (d) {
            return (d.mean !== null && d.std !== null)
                ? Math.round(Math.max(0, d.mean - d.std) * 10) / 10
                : null;
        });
        var boxHigh   = LOS_CHART_BOX.map(function (d) {
            return (d.mean !== null && d.std !== null)
                ? Math.round((d.mean + d.std) * 10) / 10
                : null;
        });
        var boxMed    = LOS_CHART_BOX.map(function (d) { return d.median; });

        new Chart(boxEl, {
            type: 'bar',
            data: {
                labels: boxLabels,
                datasets: [
                    {
                        label: 'Range (min–max)',
                        data: LOS_CHART_BOX.map(function (d, i) {
                            return [boxMin[i], boxMax[i]];
                        }),
                        backgroundColor: 'rgba(148,163,184,0.2)',
                        borderColor: '#94a3b8',
                        borderWidth: 1,
                        borderSkipped: false
                    },
                    {
                        label: 'Mean ± 1 SD',
                        data: LOS_CHART_BOX.map(function (d, i) {
                            return [boxLow[i], boxHigh[i]];
                        }),
                        backgroundColor: 'rgba(96,165,250,0.35)',
                        borderColor: '#3b82f6',
                        borderWidth: 1.5,
                        borderSkipped: false
                    },
                    {
                        label: 'Median',
                        data: boxMed,
                        type: 'line',
                        borderColor: '#1a5ea8',
                        borderWidth: 2.5,
                        pointRadius: 5,
                        pointBackgroundColor: '#1a5ea8',
                        fill: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 12, font: { size: 11 } }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var d = LOS_CHART_BOX[ctx.dataIndex];
                                if (ctx.datasetIndex === 0) {
                                    return 'Range: ' + d.min + '\u2013' + d.max + ' days';
                                }
                                if (ctx.datasetIndex === 1) {
                                    return 'Mean ' + d.mean + ' \u00b1 SD ' + d.std
                                         + '  \u2192  [' + ctx.raw[0] + ', ' + ctx.raw[1] + '] days';
                                }
                                if (ctx.datasetIndex === 2) {
                                    return 'Median: ' + ctx.raw + ' days';
                                }
                                return ctx.formattedValue;
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'LOS (days, excl. Day-28)' }
                    }
                }
            }
        });
    }

    /* ── 3. Histogram — LOS frequency distribution ──────────────────────── */
    var histEl = document.getElementById('chartHistogram');
    if (histEl && window.LOS_HIST_BUCKETS) {
        new Chart(histEl, {
            type: 'bar',
            data: {
                labels: Array.from({ length: 28 }, function (_, i) { return 'Day ' + (i + 1); }),
                datasets: [{
                    label: 'Patients',
                    data: LOS_HIST_BUCKETS,
                    backgroundColor: LOS_HIST_BUCKETS.map(function (_, i) {
                        if (i < 6)  return BAND_COLORS.lt7;
                        if (i < 14) return BAND_COLORS.w7_14;
                        if (i < 27) return BAND_COLORS.w15_27;
                        return BAND_COLORS.day28;
                    }),
                    borderRadius: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: {
                        grid: { display: false },
                        title: { display: true, text: 'LOS (days)' }
                    },
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Number of patients' }
                    }
                }
            }
        });
    }

    /* ── 4. Per-patient table: filter ───────────────────────────────────── */
    window.applyLosFilters = function () {
        var site   = document.getElementById('filterSite')   ? document.getElementById('filterSite').value.toLowerCase()   : '';
        var arm    = document.getElementById('filterArm')    ? document.getElementById('filterArm').value.toLowerCase()    : '';
        var status = document.getElementById('filterStatus') ? document.getElementById('filterStatus').value               : '';
        var search = document.getElementById('filterSearch') ? document.getElementById('filterSearch').value.toLowerCase() : '';

        var rows    = document.querySelectorAll('#patientTableBody tr');
        var visible = 0;

        rows.forEach(function (row) {
            var rSite   = (row.dataset.site   || '').toLowerCase();
            var rArm    = (row.dataset.arm    || '').toLowerCase();
            var rStatus = (row.dataset.status || '');
            var rId     = (row.dataset.id     || '').toLowerCase();

            var ok = (!site   || rSite.includes(site))
                  && (!arm    || rArm.includes(arm))
                  && (!status || rStatus === status)
                  && (!search || rId.includes(search));

            row.style.display = ok ? '' : 'none';
            if (ok) visible++;
        });

        var countEl = document.getElementById('filterCount');
        if (countEl) countEl.textContent = visible + ' patients shown';
    };

    /* ── 5. Per-patient table: column sort ──────────────────────────────── */
    window.sortLosTable = function (colIdx) {
        var tbody = document.getElementById('patientTableBody');
        if (!tbody) return;

        var rows = Array.from(tbody.querySelectorAll('tr'));
        var asc  = tbody.dataset.sortCol === String(colIdx)
                && tbody.dataset.sortDir  === 'asc';

        rows.sort(function (a, b) {
            var va = a.cells[colIdx] ? a.cells[colIdx].textContent.trim() : '';
            var vb = b.cells[colIdx] ? b.cells[colIdx].textContent.trim() : '';
            var na = parseFloat(va), nb = parseFloat(vb);
            if (!isNaN(na) && !isNaN(nb)) return asc ? nb - na : na - nb;
            return asc ? vb.localeCompare(va) : va.localeCompare(vb);
        });

        rows.forEach(function (r) { tbody.appendChild(r); });
        tbody.dataset.sortCol = colIdx;
        tbody.dataset.sortDir = asc ? 'desc' : 'asc';
    };

    /* ── Initialise filter count on page load ───────────────────────────── */
    if (document.getElementById('patientTableBody')) {
        window.applyLosFilters();
    }

}());
