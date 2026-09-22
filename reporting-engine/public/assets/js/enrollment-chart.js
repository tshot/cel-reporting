/**
 * enrollment-chart.js
 *
 * Chart.js initialisation for the base enrollment line chart
 * (WeeklyChartExporter and any other subclass of ChartExporter
 * that uses the simple single-view line layout).
 *
 * Data variables are injected by PHP as an inline <script> block
 * before this file loads — consistent with the pattern used by
 * monthly_enrollment_chart.js and section-export.js.
 *
 * Expected globals (set by ChartExporter::chartDataScript()):
 *   window.CHART_CANVAS_ID   — string  e.g. 'weeklyChart'
 *   window.CHART_X_LABELS    — array   e.g. ['Week 1', 'Week 2', ...]
 *   window.CHART_DATASETS    — array   Chart.js dataset objects
 *   window.CHART_TITLE       — string  e.g. 'Week-wise Site-wise Enrollment Trend'
 *   window.CHART_AXIS_LABEL  — string  e.g. 'Week'
 */

(function () {
    'use strict';

    // ── Read globals injected by PHP ──────────────────────────────────────────
    var canvasId  = window.CHART_CANVAS_ID  || 'enrollmentChart';
    var xLabels   = window.CHART_X_LABELS   || [];
    var datasets  = window.CHART_DATASETS   || [];
    var title     = window.CHART_TITLE      || '';
    var axisLabel = window.CHART_AXIS_LABEL || '';

    // ── Point label plugin — always-visible numbers above each data point ─────
    var pointLabelPlugin = {
        id: 'pointLabels',
        afterDatasetsDraw: function (chart) {
            var ctx = chart.ctx;
            chart.data.datasets.forEach(function (ds, i) {
                chart.getDatasetMeta(i).data.forEach(function (point, j) {
                    var val = ds.data[j];
                    if (!val) return;
                    ctx.save();
                    ctx.font         = 'bold 11px Arial';
                    ctx.fillStyle    = ds.borderColor;
                    ctx.textAlign    = 'center';
                    ctx.textBaseline = 'bottom';
                    ctx.fillText(val, point.x, point.y - 6);
                    ctx.restore();
                });
            });
        }
    };

    // ── Initialise chart ──────────────────────────────────────────────────────
    function init() {
        var canvas = document.getElementById(canvasId);
        if (!canvas) return;

        new Chart(canvas, {
            type: 'line',
            data: {
                labels:   xLabels,
                datasets: datasets
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text:    title,
                        font:    { size: 14 }
                    },
                    legend: { position: 'right' },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return ctx.dataset.label + ': ' + ctx.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        title: { display: true, text: axisLabel }
                    },
                    y: {
                        title:       { display: true, text: 'Number of Enrollments' },
                        beginAtZero: true,
                        ticks:       { stepSize: 1, precision: 0 }
                    },
                    // Total runs several times higher than any single site, so on a
                    // shared axis the site lines are squeezed into the bottom fifth
                    // of the plot. Its own axis on the right lets them use the full
                    // height. Both begin at zero so the two scales stay comparable.
                    yTotal: {
                        position:    'right',
                        title:       { display: true, text: 'Total (all sites)' },
                        // min must be forced: beginAtZero is advisory and Chart.js
                        // auto-fitted this axis to 24-32, which turned a 14%
                        // variation in the total into a full-height zigzag.
                        min:         0,
                        ticks:       { precision: 0 },
                        grid:        { drawOnChartArea: false }
                    }
                }
            },
            plugins: [pointLabelPlugin]
        });
    }

    // Run after DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

}());
