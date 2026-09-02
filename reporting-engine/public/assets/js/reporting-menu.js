/**
 * reporting-menu.js
 *
 * Two responsibilities:
 *
 * 1. Accordion — toggle open/closed state of each project panel.
 *
 * 2. Filter form helpers:
 *    a. Month picker — selecting a month fills date_from (1st) and
 *       date_to (last day) automatically.
 *    b. Site multi-select — collapses <select multiple> into a hidden
 *       comma-separated field on submit so the URL reads sites=GSVM,JSS.
 */
(function () {
    'use strict';

    // ── 1. Accordion ──────────────────────────────────────────────────────────

    document.querySelectorAll('.accordion-header').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var panel  = btn.closest('.accordion-panel');
            var isOpen = panel.classList.contains('accordion-open');

            // Close all panels
            document.querySelectorAll('.accordion-panel').forEach(function (p) {
                p.classList.remove('accordion-open');
                p.querySelector('.accordion-header').setAttribute('aria-expanded', 'false');
            });

            // Open this one if it was closed
            if (!isOpen) {
                panel.classList.add('accordion-open');
                btn.setAttribute('aria-expanded', 'true');
            }
        });
    });

    // ── 2a. Month picker ──────────────────────────────────────────────────────
    // For each project filter form that has a month picker, wire it up so
    // selecting a month fills the From and To date fields automatically.

    document.querySelectorAll('[id^="month-picker-"]').forEach(function (picker) {
        var proj   = picker.id.replace('month-picker-', '');
        var from   = document.getElementById('date-from-' + proj);
        var to     = document.getElementById('date-to-'   + proj);

        if (!from || !to) return;

        picker.addEventListener('change', function () {
            var val = picker.value;   // "YYYY-MM"
            if (!val) return;

            var parts = val.split('-');
            var year  = parseInt(parts[0], 10);
            var month = parseInt(parts[1], 10);

            // First day of month
            var firstDay = year + '-' + pad(month) + '-01';

            // Last day of month
            var lastDate = new Date(year, month, 0);   // day 0 of next month = last of this
            var lastDay  = year + '-' + pad(month) + '-' + pad(lastDate.getDate());

            from.value = firstDay;
            to.value   = lastDay;
        });
    });

    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    // ── 2b. Site multi-select → hidden comma field ────────────────────────────

    document.querySelectorAll('[id^="filter-form-"]').forEach(function (form) {
        var proj   = form.id.replace('filter-form-', '');
        var sel    = document.getElementById('sites-select-' + proj);
        var hidden = document.getElementById('sites-hidden-'  + proj);

        if (!sel || !hidden) return;

        form.addEventListener('submit', function (e) {
            // ── Client-side date range validation ─────────────────────────
            var fromInput = document.getElementById('date-from-' + proj);
            var toInput   = document.getElementById('date-to-'   + proj);

            if (fromInput && toInput && fromInput.value && toInput.value)
            {
                if (fromInput.value > toInput.value)
                {
                    e.preventDefault();
                    alert('Date error: "From" (' + fromInput.value + ') must not be later than "To" (' + toInput.value + ').');
                    fromInput.focus();
                    return;
                }
            }

            // ── Collapse multi-select sites ───────────────────────────────
            hidden.value = Array.from(sel.selectedOptions)
                               .map(function (o) { return o.value; })
                               .join(',');
            sel.name = '';
        });
    });

})();
