/**
 * emol_coverage.js
 * Filter and sort for the per-baby detail table.
 */
(function () {
    'use strict';

    window.applyCovFilters = function () {
        var site    = document.getElementById('covFilterSite')    ? document.getElementById('covFilterSite').value.toLowerCase()    : '';
        var full    = document.getElementById('covFilterFull')    ? document.getElementById('covFilterFull').value                  : '';
        var stopped = document.getElementById('covFilterStopped') ? document.getElementById('covFilterStopped').value              : '';
        var search  = document.getElementById('covFilterSearch')  ? document.getElementById('covFilterSearch').value.toLowerCase() : '';

        var rows = document.querySelectorAll('#covBabyBody tr');
        var vis  = 0;
        rows.forEach(function (row) {
            var ok = (!site    || (row.dataset.site    || '').includes(site))
                  && (!full    || (row.dataset.full    || '') === full)
                  && (!stopped || (row.dataset.stopped || '') === stopped)
                  && (!search  || (row.dataset.id      || '').includes(search));
            row.style.display = ok ? '' : 'none';
            if (ok) vis++;
        });
        var el = document.getElementById('covFilterCount');
        if (el) el.textContent = vis + ' babies shown';
    };

    window.sortCovTable = function (colIdx) {
        var tbody = document.getElementById('covBabyBody');
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

    if (document.getElementById('covBabyBody')) {
        window.applyCovFilters();
    }
}());
