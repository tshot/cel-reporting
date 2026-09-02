/**
 * weight_report.js
 *
 * Client-side behaviour for the Weight Analysis report:
 *   1. Weight trajectory line chart (mean weight by day, per site)
 *   2. Growth velocity box plot (spread per site)
 *   3. Growth velocity histogram (frequency distribution, Intervention vs Control)
 *   4. Birth weight regain stacked bar (% by threshold per site)
 *   5. Per-patient table: live filter + column sort
 *
 * Globals injected by WeightAnalysisHtmlExporter before this script loads:
 *   window.WT_TRAJECTORY    — {day_index: {site_label: mean_g, ...}, ...}
 *   window.WT_SITE_LABELS   — ordered array of site display labels
 *   window.WT_SITE_CODES    — ordered array of site codes (parallel to WT_SITE_LABELS)
 *   window.WT_TRAJ_COLORS   — {site_code: hex_color}
 *   window.WT_VEL_BOX       — [{label, min, median, max, mean, std}, ...] (per site)
 *   window.WT_VEL_HIST      — [{velocity, arm}, ...] patient-level velocity array
 *   window.WT_REGAIN        — [{site, n, by7, by14, by21, by28, never}, ...]
 *
 * Chart.js must be loaded before this script.
 */
(function () {
    'use strict';

    var SITE_PALETTE = [
        '#1a5ea8', '#2e7d52', '#b35a00', '#7b1fa2',
        '#006064', '#c62828', '#37474f', '#00695c'
    ];

    /* ── Helpers ─────────────────────────────────────────────────────────── */
    function round1(v) { return Math.round(v * 10) / 10; }

    /* =========================================================
       1. Weight trajectory — line chart
       Day 0 = admission, 1-28 = daily, 29 = discharge
       ========================================================= */
    var trajEl = document.getElementById('chartTrajectory');
    if (trajEl && window.WT_TRAJECTORY && window.WT_SITE_CODES) {

        var days     = Object.keys(WT_TRAJECTORY).map(Number).sort(function (a, b) { return a - b; });
        var dayLabels = days.map(function (d) {
            if (d === 0)  return 'Adm';
            if (d === 29) return 'Dis';
            return 'D' + d;
        });

        // Build one dataset per site + one for All
        var trajDatasets = [];
        var allKeys = WT_SITE_CODES.concat(['All']);

        allKeys.forEach(function (code, idx) {
            var label  = (code === 'All') ? 'All (mean)' : (WT_SITE_LABELS[idx] || code);
            var color  = (code === 'All') ? '#1a2c3d'    : (SITE_PALETTE[idx % SITE_PALETTE.length]);
            var data   = days.map(function (d) {
                var pt = WT_TRAJECTORY[d];
                return (pt && pt[code] !== undefined) ? pt[code] : null;
            });

            trajDatasets.push({
                label: label,
                data: data,
                borderColor: color,
                backgroundColor: color,
                borderWidth: code === 'All' ? 2.5 : 1.5,
                borderDash: code === 'All' ? [5, 3] : [],
                pointRadius: 2,
                pointHoverRadius: 5,
                fill: false,
                spanGaps: true,
                tension: 0.3
            });
        });

        new Chart(trajEl, {
            type: 'line',
            data: { labels: dayLabels, datasets: trajDatasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return ctx.dataset.label + ': ' + ctx.raw + ' g';
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, title: { display: true, text: 'Day of stay' } },
                    y: { beginAtZero: false, title: { display: true, text: 'Mean weight (g)' } }
                }
            }
        });
    }

    /* =========================================================
       2. Growth velocity box plot (Mean +/- SD approximation)
       ========================================================= */
    var velBoxEl = document.getElementById('chartVelocityBox');
    if (velBoxEl && window.WT_VEL_BOX) {
        var vbLabels = WT_VEL_BOX.map(function (d) { return d.label; });
        var vbMin    = WT_VEL_BOX.map(function (d) { return d.min   !== null ? d.min   : 0; });
        var vbMax    = WT_VEL_BOX.map(function (d) { return d.max   !== null ? d.max   : 0; });
        var vbLow    = WT_VEL_BOX.map(function (d) {
            return (d.mean !== null && d.std !== null)
                ? round1(Math.max(-30, d.mean - d.std)) : null;
        });
        var vbHigh   = WT_VEL_BOX.map(function (d) {
            return (d.mean !== null && d.std !== null)
                ? round1(d.mean + d.std) : null;
        });
        var vbMed    = WT_VEL_BOX.map(function (d) { return d.median; });

        // Reference band: healthy range 15-20 g/kg/day
        var refPlugin = {
            id: 'velRefBand',
            beforeDraw: function (chart) {
                var ctx  = chart.ctx;
                var yAx  = chart.scales.y;
                var xAx  = chart.scales.x;
                var y15  = yAx.getPixelForValue(15);
                var y20  = yAx.getPixelForValue(20);
                ctx.save();
                ctx.fillStyle = 'rgba(46,125,82,0.08)';
                ctx.fillRect(xAx.left, Math.min(y15,y20), xAx.width, Math.abs(y20-y15));
                ctx.restore();
            }
        };

        new Chart(velBoxEl, {
            type: 'bar',
            plugins: [refPlugin],
            data: {
                labels: vbLabels,
                datasets: [
                    {
                        label: 'Range (min-max)',
                        data: WT_VEL_BOX.map(function (d, i) { return [vbMin[i], vbMax[i]]; }),
                        backgroundColor: 'rgba(148,163,184,0.2)',
                        borderColor: '#94a3b8', borderWidth: 1, borderSkipped: false
                    },
                    {
                        label: 'Mean +/- 1 SD',
                        data: WT_VEL_BOX.map(function (d, i) { return [vbLow[i], vbHigh[i]]; }),
                        backgroundColor: 'rgba(46,125,82,0.3)',
                        borderColor: '#2e7d52', borderWidth: 1.5, borderSkipped: false
                    },
                    {
                        label: 'Median',
                        data: vbMed,
                        type: 'line',
                        borderColor: '#1a5ea8', borderWidth: 2.5,
                        pointRadius: 5, pointBackgroundColor: '#1a5ea8', fill: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var d = WT_VEL_BOX[ctx.dataIndex];
                                if (ctx.datasetIndex === 0) return 'Range: ' + d.min + '\u2013' + d.max + ' g/kg/day';
                                if (ctx.datasetIndex === 1) return 'Mean ' + d.mean + ' \u00b1 SD ' + d.std
                                     + '  \u2192  [' + ctx.raw[0] + ', ' + ctx.raw[1] + '] g/kg/day';
                                if (ctx.datasetIndex === 2) return 'Median: ' + ctx.raw + ' g/kg/day';
                                return ctx.formattedValue;
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { title: { display: true, text: 'Growth velocity (g/kg/day)' } }
                }
            }
        });
    }

    /* =========================================================
       3. Growth velocity histogram
       Bins: every 5 g/kg/day from -30 to +40
       Intervention in blue, Control in green
       ========================================================= */
    var velHistEl = document.getElementById('chartVelocityHist');
    if (velHistEl && window.WT_VEL_HIST) {
        var BIN_MIN  = -30, BIN_MAX = 40, BIN_SIZE = 5;
        var nBins    = (BIN_MAX - BIN_MIN) / BIN_SIZE;
        var intBins  = new Array(nBins).fill(0);
        var ctrlBins = new Array(nBins).fill(0);
        var binLabels = [];

        for (var b = 0; b < nBins; b++) {
            var lo = BIN_MIN + b * BIN_SIZE;
            binLabels.push(lo + ' to ' + (lo + BIN_SIZE));
        }

        WT_VEL_HIST.forEach(function (p) {
            if (p.velocity === null) return;
            var idx = Math.floor((p.velocity - BIN_MIN) / BIN_SIZE);
            if (idx < 0) idx = 0;
            if (idx >= nBins) idx = nBins - 1;
            if (p.arm === 'Intervention') intBins[idx]++;
            else                          ctrlBins[idx]++;
        });

        new Chart(velHistEl, {
            type: 'bar',
            data: {
                labels: binLabels,
                datasets: [
                    { label: 'Intervention', data: intBins,  backgroundColor: 'rgba(26,94,168,0.7)' },
                    { label: 'Control',      data: ctrlBins, backgroundColor: 'rgba(46,125,82,0.7)' }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                    annotation: {}
                },
                scales: {
                    x: { stacked: false, grid: { display: false },
                         title: { display: true, text: 'Growth velocity (g/kg/day)' },
                         ticks: { maxRotation: 45, font: { size: 10 } } },
                    y: { beginAtZero: true, title: { display: true, text: 'Number of patients' } }
                }
            }
        });
    }

    /* =========================================================
       4. Birth weight regain — stacked bar by site
       Segments: by Day 7 / 14 / 21 / 28 / never regained
       ========================================================= */
    var regainEl = document.getElementById('chartRegain');
    if (regainEl && window.WT_REGAIN) {
        var regLabels = WT_REGAIN.map(function (d) { return d.site; });

        // Each segment = ADDITIONAL % not already counted in a shorter window
        // by7 = % by day 7
        // 7-14 = by14 - by7
        // 14-21 = by21 - by14
        // 21-28 = by28 - by21
        // never = never
        new Chart(regainEl, {
            type: 'bar',
            data: {
                labels: regLabels,
                datasets: [
                    {
                        label: 'By Day 7',
                        data: WT_REGAIN.map(function (d) { return d.by7; }),
                        backgroundColor: '#4ade80'
                    },
                    {
                        label: 'Day 8\u201314',
                        data: WT_REGAIN.map(function (d) { return round1(Math.max(0, d.by14 - d.by7)); }),
                        backgroundColor: '#60a5fa'
                    },
                    {
                        label: 'Day 15\u201321',
                        data: WT_REGAIN.map(function (d) { return round1(Math.max(0, d.by21 - d.by14)); }),
                        backgroundColor: '#fb923c'
                    },
                    {
                        label: 'Day 22\u201328',
                        data: WT_REGAIN.map(function (d) { return round1(Math.max(0, d.by28 - d.by21)); }),
                        backgroundColor: '#f87171'
                    },
                    {
                        label: 'Not regained',
                        data: WT_REGAIN.map(function (d) { return d.never; }),
                        backgroundColor: '#d1d5db'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                    datalabels: {
                        display: function (ctx) { return ctx.dataset.data[ctx.dataIndex] >= 5; },
                        color: '#fff',
                        font: { size: 10, weight: '600' },
                        formatter: function (v) { return v + '%'; },
                        anchor: 'center', align: 'center'
                    }
                },
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: { stacked: true, beginAtZero: true, max: 100,
                         title: { display: true, text: '% of patients' } }
                }
            }
        });
    }

    /* =========================================================
       5. Per-patient table filter
       ========================================================= */
    window.applyWtFilters = function () {
        var site   = document.getElementById('wtFilterSite')   ? document.getElementById('wtFilterSite').value.toLowerCase()   : '';
        var arm    = document.getElementById('wtFilterArm')    ? document.getElementById('wtFilterArm').value.toLowerCase()    : '';
        var sex    = document.getElementById('wtFilterSex')    ? document.getElementById('wtFilterSex').value.toLowerCase()    : '';
        var search = document.getElementById('wtFilterSearch') ? document.getElementById('wtFilterSearch').value.toLowerCase() : '';

        var rows = document.querySelectorAll('#wtPatientBody tr');
        var vis  = 0;
        rows.forEach(function (row) {
            var ok = (!site   || (row.dataset.site   || '').toLowerCase().includes(site))
                  && (!arm    || (row.dataset.arm    || '').toLowerCase().includes(arm))
                  && (!sex    || (row.dataset.sex    || '').toLowerCase() === sex)
                  && (!search || (row.dataset.id     || '').toLowerCase().includes(search));
            row.style.display = ok ? '' : 'none';
            if (ok) vis++;
        });
        var el = document.getElementById('wtFilterCount');
        if (el) el.textContent = vis + ' patients shown';
    };

    /* =========================================================
       6. Per-patient table sort
       ========================================================= */
    window.sortWtTable = function (colIdx) {
        var tbody = document.getElementById('wtPatientBody');
        if (!tbody) return;
        var rows = Array.from(tbody.querySelectorAll('tr'));
        var asc  = tbody.dataset.sortCol === String(colIdx) && tbody.dataset.sortDir === 'asc';
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

    // Initialise filter count
    if (document.getElementById('wtPatientBody')) {
        window.applyWtFilters();
    }

}());
