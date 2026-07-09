'use strict';

class Admin {
    static REFRESH_SECONDS = 30;
    static PAGE_SIZE = 25;

    static SELECTOR_ROW = 'tbody tr[data-raw]';
    static SELECTOR_AUTOREFRESH = '#autorefresh';
    static SELECTOR_CHARTDATA = '#chartdata';
    static SELECTOR_TABLE = '.tablewrap table';
    static SELECTOR_COUNTDOWN = '.countdown[data-reset]';

    static PALETTE = ['#3b6ef5', '#4ade80', '#fbbf24', '#f87171', '#a78bfa', '#22d3ee', '#f472b6', '#94a3b8', '#facc15', '#34d399'];
    static STATUS_COLORS = { '2xx': '#4ade80', '4xx': '#fbbf24', '5xx': '#f87171', other: '#94a3b8' };
    static GRID = '#21252e';
    static TICK = '#8a92a3';

    constructor() {
        this.refreshTimer = null;
        this.sort = { index: -1, dir: 0 };
        this.type = 'text';
        this.page = 1;
    }

    init() {
        this.bindRowClicks();
        this.bindAutoRefresh();
        this.bindCountdowns();
        this.bindTable();
        this.renderCharts();
    }

    // open the raw log when a row is clicked (ignore clicks on links, e.g. the filters)
    bindRowClicks() {
        document.querySelectorAll(Admin.SELECTOR_ROW).forEach($row => {
            $row.addEventListener('click', event => {
                if (event.target.closest('a')) {
                    return;
                }
                window.open($row.getAttribute('data-raw'), '_blank');
            });
        });
    }

    // auto-refresh toggle, persisted across reloads; reloads keep the current filters
    bindAutoRefresh() {
        this.$refresh = document.querySelector(Admin.SELECTOR_AUTOREFRESH);
        if (!this.$refresh) {
            return;
        }
        // auto-refresh is on by default on every load; the toggle only pauses it for the current view
        this.$refresh.checked = true;
        this.$refresh.addEventListener('change', () => this.applyAutoRefresh());
        this.applyAutoRefresh();
    }

    applyAutoRefresh() {
        if (this.refreshTimer) {
            clearTimeout(this.refreshTimer);
            this.refreshTimer = null;
        }
        if (this.$refresh.checked) {
            this.refreshTimer = setTimeout(() => location.reload(), Admin.REFRESH_SECONDS * 1000);
        }
    }

    // live "time until reset" tickers
    bindCountdowns() {
        let $els = document.querySelectorAll(Admin.SELECTOR_COUNTDOWN);
        if (!$els.length) {
            return;
        }
        let tick = () => {
            let now = Math.floor(Date.now() / 1000);
            $els.forEach($el => {
                $el.textContent = this.formatDuration(parseInt($el.getAttribute('data-reset'), 10) - now);
            });
        };
        tick();
        setInterval(tick, 1000);
    }

    formatDuration(seconds) {
        if (seconds <= 0) {
            return 'now';
        }
        let d = Math.floor(seconds / 86400);
        let h = Math.floor((seconds % 86400) / 3600);
        let m = Math.floor((seconds % 3600) / 60);
        let s = Math.floor(seconds % 60);
        let parts = [];
        if (d) {
            parts.push(d + 'd');
        }
        if (h || d) {
            parts.push(h + 'h');
        }
        if (m || h || d) {
            parts.push(m + 'm');
        }
        parts.push(s + 's');
        return parts.join(' ');
    }

    // sortable columns (click: desc -> asc -> default) + client-side pagination
    bindTable() {
        let $table = document.querySelector(Admin.SELECTOR_TABLE);
        if (!$table) {
            return;
        }
        this.$tbody = $table.querySelector('tbody');
        if (this.$tbody.querySelector('.empty')) {
            return;
        }
        this.originalRows = Array.from(this.$tbody.querySelectorAll('tr'));
        this.$pagination = document.getElementById('pagination');
        $table.querySelectorAll('th[data-sort]').forEach($th => {
            $th.addEventListener('click', () => this.onSort($th));
        });
        this.renderTable();
    }

    onSort($th) {
        let index = $th.cellIndex;
        if (this.sort.index !== index) {
            this.sort = { index: index, dir: -1 };
        } else if (this.sort.dir === -1) {
            this.sort.dir = 1;
        } else if (this.sort.dir === 1) {
            this.sort = { index: -1, dir: 0 };
        } else {
            this.sort = { index: index, dir: -1 };
        }
        this.type = $th.getAttribute('data-sort');
        document.querySelectorAll('th[data-sort] .arrow').forEach($arrow => ($arrow.textContent = ''));
        if (this.sort.dir !== 0) {
            $th.querySelector('.arrow').textContent = this.sort.dir === -1 ? ' ↓' : ' ↑';
        }
        this.page = 1;
        this.renderTable();
    }

    sortedRows() {
        if (this.sort.dir === 0) {
            return this.originalRows;
        }
        let index = this.sort.index;
        let type = this.type;
        return this.originalRows.slice().sort((a, b) => {
            let av = this.cellValue(a, index, type);
            let bv = this.cellValue(b, index, type);
            let cmp = type === 'num' ? av - bv : String(av).localeCompare(String(bv));
            return this.sort.dir === -1 ? -cmp : cmp;
        });
    }

    cellValue($row, index, type) {
        let $cell = $row.cells[index];
        let text = $cell ? $cell.textContent.trim() : '';
        if (type === 'num') {
            let value = parseFloat(text.replace(/\./g, '').replace(',', '.'));
            return isNaN(value) ? -Infinity : value;
        }
        return text.toLowerCase();
    }

    renderTable() {
        let rows = this.sortedRows();
        let pages = Math.max(1, Math.ceil(rows.length / Admin.PAGE_SIZE));
        if (this.page > pages) {
            this.page = pages;
        }
        let start = (this.page - 1) * Admin.PAGE_SIZE;
        this.$tbody.replaceChildren(...rows.slice(start, start + Admin.PAGE_SIZE));
        this.renderPagination(pages);
    }

    renderPagination(pages) {
        if (!this.$pagination) {
            return;
        }
        this.$pagination.replaceChildren();
        if (pages <= 1) {
            return;
        }
        let addButton = (label, page, options = {}) => {
            let $button = document.createElement('button');
            $button.textContent = label;
            if (options.disabled) {
                $button.disabled = true;
            }
            if (options.active) {
                $button.classList.add('active');
            }
            if (!options.disabled && !options.active) {
                $button.addEventListener('click', () => {
                    this.page = page;
                    this.renderTable();
                });
            }
            this.$pagination.appendChild($button);
        };
        let addGap = () => {
            let $gap = document.createElement('span');
            $gap.textContent = '…';
            $gap.className = 'muted';
            this.$pagination.appendChild($gap);
        };

        addButton('‹ prev', this.page - 1, { disabled: this.page === 1 });
        let visible = [...new Set([1, 2, pages - 1, pages, this.page - 1, this.page, this.page + 1])]
            .filter(page => page >= 1 && page <= pages)
            .sort((a, b) => a - b);
        let last = 0;
        visible.forEach(page => {
            if (page - last > 1) {
                addGap();
            }
            addButton(String(page), page, { active: page === this.page });
            last = page;
        });
        addButton('next ›', this.page + 1, { disabled: this.page === pages });
    }

    renderCharts() {
        let $data = document.querySelector(Admin.SELECTOR_CHARTDATA);
        if (!$data || typeof Chart === 'undefined') {
            return;
        }
        let data = JSON.parse($data.textContent);

        Chart.defaults.color = Admin.TICK;
        Chart.defaults.font.family = 'Consolas, Menlo, Monaco, "SF Mono", monospace';
        this.axes = {
            x: { grid: { color: Admin.GRID }, ticks: { color: Admin.TICK, autoSkip: true, maxRotation: 0 } },
            y: { grid: { color: Admin.GRID }, ticks: { color: Admin.TICK, precision: 0 }, beginAtZero: true }
        };

        this.bar('chartHour', data.byHour.labels, data.byHour.data);
        this.doughnut('chartModel', data.byModel.labels, data.byModel.data);
        this.doughnut('chartStatus', data.byStatus.labels, data.byStatus.data, data.byStatus.labels.map($label => Admin.STATUS_COLORS[$label] || '#94a3b8'));
        this.hbar('chartGroup', data.byGroup.labels, data.byGroup.data);
    }

    bar(id, labels, values, color) {
        let $canvas = document.getElementById(id);
        if (!$canvas) {
            return;
        }
        new Chart($canvas, {
            type: 'bar',
            data: { labels, datasets: [{ data: values, backgroundColor: color || Admin.PALETTE[0], borderRadius: 4 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: this.axes }
        });
    }

    hbar(id, labels, values) {
        let $canvas = document.getElementById(id);
        if (!$canvas) {
            return;
        }
        new Chart($canvas, {
            type: 'bar',
            data: { labels, datasets: [{ data: values, backgroundColor: Admin.PALETTE[4], borderRadius: 4 }] },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { x: this.axes.y, y: { grid: { color: Admin.GRID }, ticks: { color: Admin.TICK } } }
            }
        });
    }

    doughnut(id, labels, values, colors) {
        let $canvas = document.getElementById(id);
        if (!$canvas) {
            return;
        }
        new Chart($canvas, {
            type: 'doughnut',
            data: { labels, datasets: [{ data: values, backgroundColor: colors || Admin.PALETTE, borderColor: '#171a21', borderWidth: 2 }] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'right', labels: { boxWidth: 12, padding: 8 } } }
            }
        });
    }
}

new Admin().init();
