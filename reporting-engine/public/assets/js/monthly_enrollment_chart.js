// monthly_enrollment_chart.js
// Behaviour for the Monthly Enrollment Chart toggle view.
// Data variables (xLabels, stackedDs, lineDs, titleText, axisLabelText)
// are injected by PHP as an inline <script> block before this file loads.

// ── Point label plugin — always-visible numbers on line points ────────────────
const pointLabelPlugin = {
  id: 'pointLabels',
  afterDatasetsDraw(chart) {
    const ctx = chart.ctx;
    chart.data.datasets.forEach((ds, i) => {
      if (ds.type === 'bar') return;
      const meta = chart.getDatasetMeta(i);
      meta.data.forEach((point, j) => {
        const val = ds.data[j];
        if (!val) return;
        ctx.save();
        ctx.font         = 'bold 10px Arial';
        ctx.fillStyle    = ds.borderColor;
        ctx.textAlign    = 'center';
        ctx.textBaseline = 'bottom';
        ctx.fillText(val, point.x, point.y - 5);
        ctx.restore();
      });
    });
  }
};

// ── Bar label plugin — numbers always visible inside stacked segments ─────────
const barLabelPlugin = {
  id: 'barLabels',
  afterDatasetsDraw(chart) {
    const ctx = chart.ctx;
    chart.data.datasets.forEach((ds, i) => {
      if (ds.type === 'line') return;
      const meta = chart.getDatasetMeta(i);
      meta.data.forEach((bar, j) => {
        const val = ds.data[j];
        if (!val || val < 1) return;
        const { x, y, width, height } = bar.getProps(['x','y','width','height'], true);
        if (Math.abs(height) < 14) return;   // skip if segment too thin to fit label
        ctx.save();
        ctx.font         = 'bold 10px Arial';
        ctx.fillStyle    = '#fff';
        ctx.textAlign    = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(val, x, y + Math.abs(height) / 2);
        ctx.restore();
      });
    });
  }
};

// ── Grand total plugin — shows overall cumulative total in top-right of line chart ──
const grandTotalPlugin = {
  id: 'grandTotal',
  afterDraw(chart) {
    const cumulativeDs = chart.data.datasets.find(ds => ds.label === 'Cumulative');
    if (!cumulativeDs) return;

    // Grand total = last value of the cumulative line
    const data  = cumulativeDs.data.filter(v => v != null);
    const grand = data[data.length - 1];
    if (!grand) return;

    const { chartArea: { right, top }, ctx } = chart;
    ctx.save();
    ctx.font         = 'bold 13px Arial';
    ctx.fillStyle    = '#444444';
    ctx.textAlign    = 'right';
    ctx.textBaseline = 'top';
    ctx.fillText('Grand Total: ' + grand, right - 4, top + 4);
    ctx.restore();
  }
};
const stackedConfig = {
  type: 'bar',
  data: {
    labels:   xLabels,
    datasets: stackedDs
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index' },
    plugins: {
      title:  { display: true, text: titleText, font: { size: 14 } },
      legend: { position: 'bottom' },
    },
    scales: {
      x: {
        stacked: true,
        title: { display: true, text: axisLabelText }
      },
      yLeft: {
        stacked: true,
        type: 'linear',
        position: 'left',
        beginAtZero: true,
        title: { display: true, text: 'Monthly Enrolled' },
        ticks: { stepSize: 1, precision: 0 }
      },
      yRight: {
        type: 'linear',
        position: 'right',
        beginAtZero: true,
        title: { display: true, text: 'Cumulative Enrolled' },
        ticks: { stepSize: 1, precision: 0 },
        grid: { drawOnChartArea: false }
      }
    }
  },
  plugins: [barLabelPlugin, pointLabelPlugin]
};

// ── Site-wise line config ─────────────────────────────────────────────────────
const lineConfig = {
  type: 'line',
  data: {
    labels:   xLabels,
    datasets: lineDs
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index' },
    plugins: {
      title:  { display: true, text: titleText, font: { size: 14 } },
      legend: { position: 'right' },
      tooltip: {
        callbacks: {
          label: ctx => {
            const axis = ctx.dataset.yAxisID === 'yRight' ? ' (cumulative)' : '';
            return ctx.dataset.label + ': ' + ctx.parsed.y + axis;
          }
        }
      }
    },
    scales: {
      x: { title: { display: true, text: axisLabelText } },
      yLeft: {
        type:        'linear',
        position:    'left',
        beginAtZero: true,
        title:       { display: true, text: 'Number of Enrollments' },
        ticks:       { stepSize: 1, precision: 0 }
      },
      yRight: {
        type:        'linear',
        position:    'right',
        beginAtZero: true,
        title:       { display: true, text: 'Cumulative Enrolled' },
        ticks:       { stepSize: 1, precision: 0 },
        grid:        { drawOnChartArea: false }
      }
    }
  },
  plugins: [pointLabelPlugin]
};

// ── Chart instance — declared at top scope so switchView() can access it ─────
let chart = null;

function switchView(view)
{
  document.getElementById('btnStacked').classList.toggle('active', view === 'stacked');
  document.getElementById('btnLine').classList.toggle('active', view === 'line');

  // Destroy via Chart.getChart to ensure canvas is fully released
  const canvas = document.getElementById('monthlyChart');
  const existing = Chart.getChart(canvas);
  if (existing) existing.destroy();
  chart = null;

  if (view === 'stacked') {
    chart = new Chart(canvas, stackedConfig);
  } else {
    // Line view — register grandTotalPlugin inline on this instance
    chart = new Chart(canvas, {
      ...lineConfig,
      plugins: [...(lineConfig.plugins || []), grandTotalPlugin]
    });
  }
}

// ── Initialise once DOM is ready, using server-specified default view ────────
document.addEventListener('DOMContentLoaded', () => {
  const initView = (typeof defaultView !== 'undefined') ? defaultView : 'stacked';

  // Set correct active button
  document.getElementById('btnStacked').classList.toggle('active', initView === 'stacked');
  document.getElementById('btnLine').classList.toggle('active',    initView === 'line');

  const canvas = document.getElementById('monthlyChart');
  if (initView === 'line') {
    chart = new Chart(canvas, {
      ...lineConfig,
      plugins: [...(lineConfig.plugins || []), grandTotalPlugin]
    });
  } else {
    chart = new Chart(canvas, stackedConfig);
  }
});
