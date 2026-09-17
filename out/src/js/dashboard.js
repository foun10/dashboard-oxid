/*
 * foun10 admin dashboard: revenue trend chart (inline SVG, no library),
 * its table view, shared hover tooltips and admin-navigation sync.
 * Everything else on the page is rendered server-side.
 */
(function () {
    'use strict';

    var SVG_NS = 'http://www.w3.org/2000/svg';
    var tooltip = document.getElementById('f10d-tooltip');

    /* ---------- helpers ---------- */

    function svg(name, attrs, parent) {
        var el = document.createElementNS(SVG_NS, name);
        Object.keys(attrs || {}).forEach(function (key) {
            el.setAttribute(key, attrs[key]);
        });
        if (parent) {
            parent.appendChild(el);
        }
        return el;
    }

    function el(name, className, text) {
        var node = document.createElement(name);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    /** Parses the server's local "Y-m-dTH:i:s+hh:mm" without shifting it into the browser's time zone. */
    function localDate(iso) {
        var p = iso.slice(0, 19).split(/[-T:]/).map(Number);
        return new Date(p[0], p[1] - 1, p[2], p[3], p[4], p[5]);
    }

    /**
     * Appends the ▲/▼ year-on-year mark to a table cell. With previous null
     * (nothing to compare, or a still-running bucket) or an equal value an
     * empty slot of the same width is added, so the numbers stay aligned.
     */
    function appendDelta(cell, value, previous, tip) {
        if (previous === null || value === previous) {
            var blank = el('span', 'f10d-cell-delta');
            blank.setAttribute('aria-hidden', 'true');
            cell.appendChild(blank);
            return;
        }

        var up = value > previous;
        var mark = el('span', 'f10d-cell-delta ' + (up ? 'f10d-delta--good' : 'f10d-delta--bad'));
        mark.setAttribute('role', 'img');
        mark.setAttribute('aria-label', tip);
        mark.title = tip;
        mark.appendChild(el('span', 'f10d-delta-icon', up ? '▲' : '▼'));
        cell.appendChild(mark);
    }

    function niceStep(max, count) {
        var raw = max / count;
        var magnitude = Math.pow(10, Math.floor(Math.log10(raw)));
        var residual = raw / magnitude;
        var nice = residual > 5 ? 10 : residual > 2 ? 5 : residual > 1 ? 2 : 1;
        return nice * magnitude;
    }

    /* ---------- tooltip ---------- */

    function showTooltip(build, clientX, clientY) {
        tooltip.textContent = '';
        build(tooltip);
        tooltip.hidden = false;

        var rect = tooltip.getBoundingClientRect();
        var x = clientX + 14;
        var y = clientY + 14;
        if (x + rect.width > window.innerWidth - 8) {
            x = clientX - rect.width - 14;
        }
        if (y + rect.height > window.innerHeight - 8) {
            y = clientY - rect.height - 14;
        }
        tooltip.style.left = Math.max(8, x) + 'px';
        tooltip.style.top = Math.max(8, y) + 'px';
    }

    function hideTooltip() {
        tooltip.hidden = true;
    }

    function initSimpleTips() {
        document.querySelectorAll('[data-tip]').forEach(function (node) {
            var build = function (box) {
                box.appendChild(el('div', '', node.getAttribute('data-tip')));
            };
            node.addEventListener('pointermove', function (e) {
                showTooltip(build, e.clientX, e.clientY);
            });
            node.addEventListener('pointerleave', hideTooltip);
            node.addEventListener('focus', function () {
                var r = node.getBoundingClientRect();
                showTooltip(build, r.left, r.bottom);
            });
            node.addEventListener('blur', hideTooltip);
        });
    }

    /* ---------- admin navigation ---------- */

    // Delegated, so rows added by "show more" are covered too.
    function initNavLinks() {
        document.addEventListener('click', function (e) {
            var link = e.target.closest ? e.target.closest('a[data-nav]') : null;
            if (!link) {
                return;
            }
            try {
                top.navigation.adminnav._navExtExpActByName(link.getAttribute('data-nav'));
            } catch (err) {
                // navigation frame not available - the link still works
            }
        });
    }

    /* ---------- yearly comparison ---------- */

    var YEAR_SLOTS = 4;

    /** Top edge rounded (4px), baseline square - the bar mark spec. */
    function barPath(x, y, width, baseline) {
        var r = Math.min(4, width / 2, baseline - y);
        return 'M' + x + ' ' + baseline
            + 'V' + (y + r)
            + 'Q' + x + ' ' + y + ' ' + (x + r) + ' ' + y
            + 'H' + (x + width - r)
            + 'Q' + (x + width) + ' ' + y + ' ' + (x + width) + ' ' + (y + r)
            + 'V' + baseline + 'Z';
    }

    function initYearly() {
        var container = document.getElementById('f10d-yearly-chart');
        var chipBox = document.getElementById('f10d-year-chips');
        var source = document.getElementById('f10d-yearly-data');
        var tableTarget = document.getElementById('f10d-yearly-table');
        if (!container || !chipBox || !source) {
            return;
        }

        var data = JSON.parse(source.textContent);
        var labels = container.dataset;
        var money = new Intl.NumberFormat(data.locale, {style: 'currency', currency: data.currency, maximumFractionDigits: 0});
        var integer = new Intl.NumberFormat(data.locale);
        var monthShort = new Intl.DateTimeFormat(data.locale, {month: 'short'});
        var monthLong = new Intl.DateTimeFormat(data.locale, {month: 'long'});
        var monthNames = [];
        for (var m = 0; m < 12; m++) {
            monthNames.push({short: monthShort.format(new Date(2000, m, 1)), long: monthLong.format(new Date(2000, m, 1))});
        }

        var metric = 'revenue';
        // year -> colour slot (1..4). A year keeps its slot while selected,
        // so toggling other years never repaints it.
        var slots = {};
        data.years.slice(0, 3).forEach(function (year, i) {
            slots[year.year] = i + 1;
        });

        // true: each month shows the year's running total up to that month
        var cumulative = false;

        var byYear = {};
        data.years.forEach(function (year) {
            byYear[year.year] = year;
            var running = {orders: 0, revenue: 0};
            year.sums = year.months.map(function (month) {
                if (month === null) {
                    return null;
                }
                running = {orders: running.orders + month.orders, revenue: running.revenue + month.revenue};
                return running;
            });
        });

        function format(v) {
            return metric === 'orders' ? integer.format(v) : money.format(v);
        }

        /** The month's figures of a year - per month or running total. */
        function entryOf(year, index) {
            return (cumulative ? year.sums : year.months)[index];
        }

        function valueOf(year, index) {
            var entry = entryOf(year, index);
            return entry === null ? null : entry[metric];
        }

        /** Newest first - the order of the chips, the bars and the tooltip rows. */
        function selectedYears() {
            return Object.keys(slots).map(Number).sort(function (a, b) {
                return b - a;
            });
        }

        function freeSlot() {
            var used = Object.keys(slots).map(function (year) {
                return slots[year];
            });
            for (var s = 1; s <= YEAR_SLOTS; s++) {
                if (used.indexOf(s) === -1) {
                    return s;
                }
            }
            return 0;
        }

        /* chips = the legend and the year filter in one */
        var note = el('p', 'f10d-year-note');
        note.hidden = true;
        chipBox.after(note);

        function renderChips() {
            chipBox.textContent = '';
            chipBox.classList.toggle('is-full', !freeSlot());
            data.years.forEach(function (year) {
                var slot = slots[year.year];
                var chip = el('button', 'f10d-year-chip');
                chip.type = 'button';
                chip.setAttribute('aria-pressed', slot ? 'true' : 'false');
                var swatch = el('span', 'f10d-year-swatch');
                swatch.setAttribute('aria-hidden', 'true');
                if (slot) {
                    swatch.style.background = 'var(--year-' + slot + ')';
                }
                chip.appendChild(swatch);
                chip.appendChild(document.createTextNode(String(year.year)));
                chip.addEventListener('click', function () {
                    if (slots[year.year]) {
                        delete slots[year.year];
                    } else if (freeSlot()) {
                        slots[year.year] = freeSlot();
                    } else {
                        note.textContent = chipBox.getAttribute('data-label-max');
                        note.hidden = false;
                        return;
                    }
                    note.hidden = true;
                    renderChips();
                    render();
                    // keep keyboard focus on the chip that was toggled
                    var again = chipBox.children[data.years.indexOf(year)];
                    if (again) {
                        again.focus();
                    }
                });
                chipBox.appendChild(chip);
            });
        }

        function render() {
            container.textContent = '';
            hideTooltip();

            var width = container.clientWidth;
            var height = container.clientHeight;
            var years = selectedYears();
            if (!width || !height) {
                return;
            }

            var maxValue = 0;
            years.forEach(function (year) {
                for (var index = 0; index < 12; index++) {
                    maxValue = Math.max(maxValue, valueOf(byYear[year], index) || 0);
                }
            });
            var step = maxValue > 0 ? niceStep(maxValue, 4) : 1;
            if (metric === 'orders') {
                step = Math.max(1, Math.ceil(step));
            }
            var yMax = maxValue > 0 ? Math.ceil(maxValue / step) * step : 4;
            var ticks = [];
            for (var t = 0; t <= yMax + step / 2; t += step) {
                ticks.push(t);
            }

            var tickWidth = Math.max.apply(null, ticks.map(function (v) {
                return format(v).length;
            }));
            var margin = {top: 12, right: 8, bottom: 26, left: Math.min(110, 12 + tickWidth * 7)};
            var plotW = Math.max(10, width - margin.left - margin.right);
            var plotH = Math.max(10, height - margin.top - margin.bottom);
            var baseline = margin.top + plotH;
            var groupW = plotW / 12;
            var gap = 2;
            var n = Math.max(1, years.length);
            var barW = Math.min(24, (groupW * 0.72 - gap * (n - 1)) / n);

            var y = function (v) {
                return baseline - (v / yMax) * plotH;
            };

            var root = svg('svg', {viewBox: '0 0 ' + width + ' ' + height, role: 'img', 'aria-label': labels.labelTitle}, container);

            ticks.forEach(function (v) {
                svg('line', {
                    x1: margin.left, x2: margin.left + plotW, y1: y(v), y2: y(v),
                    stroke: v === 0 ? 'var(--axis)' : 'var(--grid)', 'stroke-width': 1, 'shape-rendering': 'crispEdges'
                }, root);
                svg('text', {x: margin.left - 8, y: y(v) + 4, 'text-anchor': 'end', class: 'f10d-axis-text'}, root)
                    .textContent = format(v);
            });

            var band = svg('rect', {y: margin.top, height: plotH, width: groupW, fill: 'var(--grid)', opacity: 0.35, visibility: 'hidden'}, root);

            var centerOf = function (month) {
                return margin.left + groupW * (month + 0.5);
            };

            for (var month = 0; month < 12; month++) {
                svg('text', {x: centerOf(month), y: height - 6, 'text-anchor': 'middle', class: 'f10d-axis-text'}, root)
                    .textContent = monthNames[month].short;
            }

            // Running totals read as lines (one per year), monthly values as bars.
            // Lines: oldest first, so the newest year is drawn on top.
            var hoverDots = [];
            if (cumulative) {
                years.slice().reverse().forEach(function (year) {
                    var path = '';
                    var last = null;
                    for (var index = 0; index < 12; index++) {
                        var value = valueOf(byYear[year], index);
                        if (value === null) {
                            break;
                        }
                        path += (path ? ' L' : 'M') + centerOf(index).toFixed(1) + ' ' + y(value).toFixed(1);
                        last = {x: centerOf(index), y: y(value)};
                    }
                    if (!last) {
                        return;
                    }
                    svg('path', {
                        d: path, fill: 'none', stroke: 'var(--year-' + slots[year] + ')', 'stroke-width': 2,
                        'stroke-linejoin': 'round', 'stroke-linecap': 'round'
                    }, root);
                    svg('circle', {
                        cx: last.x, cy: last.y, r: 4,
                        fill: 'var(--year-' + slots[year] + ')', stroke: 'var(--surface)', 'stroke-width': 2
                    }, root);
                });

                years.forEach(function (year) {
                    hoverDots.push({
                        year: year,
                        dot: svg('circle', {
                            r: 4, fill: 'var(--year-' + slots[year] + ')', stroke: 'var(--surface)',
                            'stroke-width': 2, visibility: 'hidden'
                        }, root)
                    });
                });
            } else {
                for (var group = 0; group < 12; group++) {
                    var left = centerOf(group) - (n * barW + (n - 1) * gap) / 2;
                    years.forEach(function (year, i) {
                        var value = valueOf(byYear[year], group);
                        if (value === null || value <= 0) {
                            return;
                        }
                        svg('path', {
                            d: barPath(left + i * (barW + gap), Math.min(y(value), baseline - 1), barW, baseline),
                            fill: 'var(--year-' + slots[year] + ')'
                        }, root);
                    });
                }
            }

            // One hover target per month; the tooltip compares all selected years.
            var overlay = svg('rect', {
                x: margin.left, y: margin.top, width: plotW, height: plotH,
                fill: 'transparent', tabindex: 0, 'aria-label': labels.labelTitle
            }, root);
            var active = data.currentMonth - 1;

            function show(month, clientX, clientY) {
                active = month;
                band.setAttribute('x', margin.left + groupW * month);
                band.setAttribute('visibility', 'visible');
                hoverDots.forEach(function (item) {
                    var value = valueOf(byYear[item.year], month);
                    item.dot.setAttribute('visibility', value === null ? 'hidden' : 'visible');
                    if (value !== null) {
                        item.dot.setAttribute('cx', centerOf(month));
                        item.dot.setAttribute('cy', y(value));
                    }
                });

                showTooltip(function (box) {
                    box.appendChild(el('div', 'f10d-tip-title',
                        (cumulative ? labels.labelCumulative + ' ' : '') + monthNames[month].long));
                    years.forEach(function (year) {
                        var entry = entryOf(byYear[year], month);
                        var row = el('div', 'f10d-tip-row');
                        var key = el('span', 'f10d-year-swatch');
                        key.style.background = 'var(--year-' + slots[year] + ')';
                        key.style.boxShadow = 'none';
                        row.appendChild(key);
                        row.appendChild(el('span', '', String(year)));
                        if (entry === null) {
                            row.appendChild(el('strong', '', '–'));
                        } else {
                            row.appendChild(el('strong', '', format(entry[metric])));
                            var extra = metric === 'orders'
                                ? money.format(entry.revenue)
                                : integer.format(entry.orders) + ' ' + labels.labelOrders;
                            if (year === data.currentYear && month === data.currentMonth - 1) {
                                extra += ' · ' + labels.labelToDate;
                            }
                            row.appendChild(el('span', '', extra));
                        }
                        box.appendChild(row);
                    });
                }, clientX, clientY);
            }

            function hide() {
                band.setAttribute('visibility', 'hidden');
                hoverDots.forEach(function (item) {
                    item.dot.setAttribute('visibility', 'hidden');
                });
                hideTooltip();
            }

            function monthAt(clientX) {
                var rect = root.getBoundingClientRect();
                var px = (clientX - rect.left) * (width / rect.width);
                return Math.max(0, Math.min(11, Math.floor((px - margin.left) / groupW)));
            }

            function showFromKeyboard(month) {
                var rect = root.getBoundingClientRect();
                show(month, rect.left + (margin.left + groupW * (month + 0.5)) * (rect.width / width), rect.top + margin.top);
            }

            overlay.addEventListener('pointermove', function (e) {
                show(monthAt(e.clientX), e.clientX, e.clientY);
            });
            overlay.addEventListener('pointerleave', hide);
            overlay.addEventListener('blur', hide);
            overlay.addEventListener('focus', function () {
                showFromKeyboard(active);
            });
            overlay.addEventListener('keydown', function (e) {
                if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
                    e.preventDefault();
                    showFromKeyboard(Math.max(0, Math.min(11, active + (e.key === 'ArrowRight' ? 1 : -1))));
                }
            });

            renderTable();
        }

        /* all years x months, current metric - also the accessible fallback */
        function renderTable() {
            if (!tableTarget) {
                return;
            }
            // best value per month column over all years (ties all count)
            var best = [];
            for (var i = 0; i < 12; i++) {
                best.push(0);
                data.years.forEach(function (year) {
                    best[i] = Math.max(best[i], valueOf(year, i) || 0);
                });
            }

            var table = el('table', 'f10d-table');
            table.createCaption().textContent = labels.labelBest;
            var head = table.createTHead().insertRow();
            head.appendChild(el('th', '', labels.labelYear));
            monthNames.forEach(function (name) {
                head.appendChild(el('th', 'f10d-num', name.short));
            });
            head.appendChild(el('th', 'f10d-num', labels.labelTotal));

            var body = table.createTBody();
            data.years.forEach(function (year) {
                var row = body.insertRow();
                row.insertCell().textContent = String(year.year);
                var previousYear = byYear[year.year - 1];
                year.months.forEach(function (month, index) {
                    var cell = row.insertCell();
                    var value = valueOf(year, index);
                    cell.className = 'f10d-num';
                    cell.textContent = month === null ? '' : format(value);
                    if (value !== null && value > 0 && value === best[index]) {
                        cell.className += ' f10d-best';
                    }

                    // vs. the same month a year earlier - not for the running
                    // month (only partly over) or without data for that year
                    if (value === null) {
                        return;
                    }
                    var running = year.year === data.currentYear && index === data.currentMonth - 1;
                    var previous = previousYear && !running ? valueOf(previousYear, index) || 0 : null;
                    appendDelta(cell, value, previous,
                        labels.labelPreviousYear + ' ' + (year.year - 1) + ': ' + (previous === null ? '' : format(previous)));
                });
                var total = row.insertCell();
                total.className = 'f10d-num f10d-total';
                total.textContent = format(year[metric]);
            });

            tableTarget.textContent = '';
            tableTarget.appendChild(table);
        }

        document.querySelectorAll('[data-yearly-metric]').forEach(function (button, i, all) {
            button.addEventListener('click', function () {
                metric = button.getAttribute('data-yearly-metric') === 'orders' ? 'orders' : 'revenue';
                all.forEach(function (other) {
                    other.classList.toggle('is-active', other === button);
                    other.setAttribute('aria-pressed', other === button ? 'true' : 'false');
                });
                render();
            });
        });

        var cumulativeToggle = document.getElementById('f10d-yearly-cumulative');
        if (cumulativeToggle) {
            // browsers may restore the checked state on reload
            cumulative = cumulativeToggle.checked;
            cumulativeToggle.addEventListener('change', function () {
                cumulative = cumulativeToggle.checked;
                render();
            });
        }

        renderChips();
        render();

        var timer;
        window.addEventListener('resize', function () {
            clearTimeout(timer);
            timer = setTimeout(render, 120);
        });
    }

    /* ---------- custom date range ---------- */

    function initRange() {
        var form = document.getElementById('f10d-range');
        if (!form) {
            return;
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var from = form.elements.from.value;
            var to = form.elements.to.value;
            if (!from || !to) {
                return;
            }
            // the server puts swapped dates in order and cuts the range to today
            window.location.href = form.getAttribute('data-url')
                + '&from=' + encodeURIComponent(from)
                + '&to=' + encodeURIComponent(to);
        });
    }

    /* ---------- top sellers: show more ---------- */

    function initTopSellers() {
        var button = document.getElementById('f10d-topsellers-more');
        var body = document.getElementById('f10d-topsellers');
        if (!button || !body) {
            return;
        }

        var label = button.textContent;

        button.addEventListener('click', function () {
            button.disabled = true;
            button.textContent = button.getAttribute('data-label-loading');

            var url = button.getAttribute('data-url') + '&offset=' + encodeURIComponent(button.getAttribute('data-offset'));

            fetch(url, {credentials: 'same-origin', headers: {Accept: 'application/json'}})
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.json();
                })
                .then(function (page) {
                    // Server-rendered rows of our own partial (DB text escaped there).
                    var holder = document.createElement('tbody');
                    holder.innerHTML = page.html;
                    while (holder.firstElementChild) {
                        body.appendChild(holder.firstElementChild);
                    }

                    button.setAttribute('data-offset', page.offset);
                    button.disabled = false;
                    button.textContent = label;
                    if (!page.hasMore) {
                        button.remove();
                    }
                })
                .catch(function () {
                    button.disabled = false;
                    button.textContent = button.getAttribute('data-label-error');
                });
        });
    }

    /* ---------- trend chart ---------- */

    function TrendChart(container, data) {
        var labels = container.dataset;
        var money = new Intl.NumberFormat(data.locale, {
            style: 'currency',
            currency: data.currency,
            maximumFractionDigits: 0
        });
        var integer = new Intl.NumberFormat(data.locale);
        var dayShort = new Intl.DateTimeFormat(data.locale, {day: '2-digit', month: '2-digit'});
        var dayLong = new Intl.DateTimeFormat(data.locale, {weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric'});
        var dateLong = new Intl.DateTimeFormat(data.locale, {day: '2-digit', month: '2-digit', year: 'numeric'});
        var monthShort = new Intl.DateTimeFormat(data.locale, {month: 'short', year: '2-digit'});
        var monthLong = new Intl.DateTimeFormat(data.locale, {month: 'long', year: 'numeric'});

        // 'revenue' (default) or 'orders' - switched by the buttons above the chart.
        var metric = 'revenue';
        // true: each point is the running total of the period so far.
        var cumulative = false;

        function pick(p, field) {
            return p[(cumulative ? 'sum' : 'bucket')][field];
        }

        function value(p) {
            return pick(p, metric);
        }

        function lastYearValue(p) {
            return pick(p, metric === 'orders' ? 'lastYearOrders' : 'lastYearRevenue');
        }

        function format(v) {
            return metric === 'orders' ? integer.format(v) : money.format(v);
        }

        // lastYear* is null for a calendar week 53 that last year did not have.
        var points = data.points.map(function (p) {
            return {
                week: p.week,
                running: !!p.running,
                start: localDate(p.start),
                end: localDate(p.end),
                lastYearStart: p.lastYearStart ? localDate(p.lastYearStart) : null,
                lastYearEnd: p.lastYearEnd ? localDate(p.lastYearEnd) : null,
                revenue: p.revenue,
                orders: p.orders,
                lastYearRevenue: p.lastYearRevenue,
                lastYearOrders: p.lastYearOrders
            };
        });

        // Per-bucket values and running totals side by side. The current
        // period's total stops at the current bucket (future stays null); a
        // missing last-year week adds nothing, so its total just carries on.
        var totals = {revenue: 0, orders: 0, lastYearRevenue: 0, lastYearOrders: 0};
        points.forEach(function (p) {
            p.bucket = {
                revenue: p.revenue,
                orders: p.orders,
                lastYearRevenue: p.lastYearRevenue,
                lastYearOrders: p.lastYearOrders
            };
            p.sum = {};
            Object.keys(totals).forEach(function (field) {
                totals[field] += p.bucket[field] || 0;
                p.sum[field] = totals[field];
            });
            if (p.revenue === null) {
                p.sum.revenue = null;
                p.sum.orders = null;
            }
        });

        function axisLabel(p) {
            if (data.bucket === 'hour') {
                return String(p.start.getHours()).padStart(2, '0') + ':00';
            }
            if (data.bucket === 'week') {
                return labels.labelWeek + ' ' + p.week;
            }
            if (data.bucket === 'month') {
                return monthShort.format(p.start);
            }
            return dayShort.format(p.start);
        }

        /** e.g. "KW 37 · 07.09.–13.09.2026", "Mo., 15.09.2026" or "Mo., 15.09.2026, 14:00" */
        function longLabel(p, start, end) {
            if (data.bucket === 'hour') {
                return dayLong.format(start) + ', ' + String(start.getHours()).padStart(2, '0') + ':00';
            }
            if (data.bucket === 'week') {
                return labels.labelWeek + ' ' + p.week + ' · ' + dayShort.format(start) + '–' + dateLong.format(end);
            }
            if (data.bucket === 'month') {
                return monthLong.format(start);
            }
            return dayLong.format(start);
        }

        function formatOrDash(v, formatter) {
            return v === null ? '–' : formatter.format(v);
        }

        function render() {
            container.textContent = '';
            hideTooltip();

            var width = container.clientWidth;
            var height = container.clientHeight;
            if (!width || !height || !points.length) {
                return;
            }

            var maxValue = 0;
            points.forEach(function (p) {
                maxValue = Math.max(maxValue, value(p) || 0, lastYearValue(p) || 0);
            });
            var step = maxValue > 0 ? niceStep(maxValue, 4) : 1;
            if (metric === 'orders') {
                // order counts are whole numbers - no 0.5 ticks
                step = Math.max(1, Math.ceil(step));
            }
            var yMax = maxValue > 0 ? Math.ceil(maxValue / step) * step : 4;

            var ticks = [];
            for (var t = 0; t <= yMax + step / 2; t += step) {
                ticks.push(t);
            }

            var tickWidth = Math.max.apply(null, ticks.map(function (v) {
                return format(v).length;
            }));
            var margin = {top: 12, right: 16, bottom: 26, left: Math.min(110, 12 + tickWidth * 7)};
            var plotW = Math.max(10, width - margin.left - margin.right);
            var plotH = Math.max(10, height - margin.top - margin.bottom);
            var n = points.length;

            var x = function (i) {
                return margin.left + (n > 1 ? (i / (n - 1)) * plotW : plotW / 2);
            };
            var y = function (v) {
                return margin.top + plotH - (v / yMax) * plotH;
            };

            var root = svg('svg', {
                viewBox: '0 0 ' + width + ' ' + height,
                role: 'img',
                'aria-label': labels.chartTitle
            }, container);

            // Grid + y ticks: hairline, recessive.
            ticks.forEach(function (v) {
                svg('line', {
                    x1: margin.left, x2: margin.left + plotW, y1: y(v), y2: y(v),
                    stroke: v === 0 ? 'var(--axis)' : 'var(--grid)', 'stroke-width': 1,
                    'shape-rendering': 'crispEdges'
                }, root);
                svg('text', {
                    x: margin.left - 8, y: y(v) + 4, 'text-anchor': 'end', class: 'f10d-axis-text'
                }, root).textContent = format(v);
            });

            // X labels, thinned so they never collide.
            var labelEvery = Math.max(1, Math.ceil(n / Math.max(1, Math.floor(plotW / 64))));
            points.forEach(function (p, i) {
                if (i % labelEvery !== 0) {
                    return;
                }
                var anchor = n > 1 && i === 0 ? 'start' : 'middle';
                svg('text', {
                    x: x(i), y: height - 6, 'text-anchor': anchor, class: 'f10d-axis-text'
                }, root).textContent = axisLabel(p);
            });

            // Last year: context line in gray, drawn first so the current period sits on top.
            // A week without last-year counterpart leaves a gap in the line.
            var lyPath = '';
            var lyDrawing = false;
            points.forEach(function (p, i) {
                if (lastYearValue(p) === null) {
                    lyDrawing = false;
                    return;
                }
                lyPath += (lyDrawing ? ' L' : ' M') + x(i).toFixed(1) + ' ' + y(lastYearValue(p)).toFixed(1);
                lyDrawing = true;
            });
            svg('path', {
                d: lyPath, fill: 'none', stroke: 'var(--context)', 'stroke-width': 2,
                'stroke-linejoin': 'round', 'stroke-linecap': 'round'
            }, root);

            // Current period: 10% area wash + 2px line, only up to the current bucket.
            var current = [];
            points.forEach(function (p, i) {
                if (value(p) !== null) {
                    current.push({i: i, v: value(p)});
                }
            });

            if (current.length) {
                var line = current.map(function (c, k) {
                    return (k ? 'L' : 'M') + x(c.i).toFixed(1) + ' ' + y(c.v).toFixed(1);
                }).join(' ');
                var last = current[current.length - 1];
                var area = line
                    + ' L' + x(last.i).toFixed(1) + ' ' + y(0)
                    + ' L' + x(current[0].i).toFixed(1) + ' ' + y(0) + ' Z';

                svg('path', {d: area, fill: 'var(--series-1)', 'fill-opacity': 0.1, stroke: 'none'}, root);
                svg('path', {
                    d: line, fill: 'none', stroke: 'var(--series-1)', 'stroke-width': 2,
                    'stroke-linejoin': 'round', 'stroke-linecap': 'round'
                }, root);
                svg('circle', {
                    cx: x(last.i), cy: y(last.v), r: 4,
                    fill: 'var(--series-1)', stroke: 'var(--surface)', 'stroke-width': 2
                }, root);
            }

            // Hover layer: crosshair snaps to the nearest bucket.
            var hover = svg('g', {visibility: 'hidden'}, root);
            var cross = svg('line', {
                y1: margin.top, y2: margin.top + plotH, stroke: 'var(--axis)', 'stroke-width': 1
            }, hover);
            var lyDot = svg('circle', {
                r: 4, fill: 'var(--context)', stroke: 'var(--surface)', 'stroke-width': 2
            }, hover);
            var curDot = svg('circle', {
                r: 4, fill: 'var(--series-1)', stroke: 'var(--surface)', 'stroke-width': 2
            }, hover);

            var overlay = svg('rect', {
                x: margin.left - 12, y: margin.top, width: plotW + 24, height: plotH,
                fill: 'transparent', tabindex: 0,
                'aria-label': labels.chartTitle
            }, root);

            var active = current.length ? current[current.length - 1].i : 0;

            function tooltipRow(box, color, value, label) {
                var row = el('div', 'f10d-tip-row');
                var key = el('span', 'f10d-key');
                key.style.background = color;
                row.appendChild(key);
                row.appendChild(el('strong', '', value));
                row.appendChild(el('span', '', label));
                box.appendChild(row);
            }

            // The charted metric leads the tooltip row; the other one follows as context.
            function secondary(revenue, orders) {
                return metric === 'orders'
                    ? money.format(revenue)
                    : integer.format(orders) + ' ' + labels.labelOrders;
            }

            function show(i, clientX, clientY) {
                active = i;
                var p = points[i];
                hover.setAttribute('visibility', 'visible');
                cross.setAttribute('x1', x(i));
                cross.setAttribute('x2', x(i));
                lyDot.setAttribute('visibility', lastYearValue(p) === null ? 'hidden' : 'visible');
                if (lastYearValue(p) !== null) {
                    lyDot.setAttribute('cx', x(i));
                    lyDot.setAttribute('cy', y(lastYearValue(p)));
                }
                curDot.setAttribute('visibility', value(p) === null ? 'hidden' : 'visible');
                if (value(p) !== null) {
                    curDot.setAttribute('cx', x(i));
                    curDot.setAttribute('cy', y(value(p)));
                }

                var prefix = cumulative ? labels.labelCumulative + ' ' : '';

                showTooltip(function (box) {
                    box.appendChild(el('div', 'f10d-tip-title', prefix + longLabel(p, p.start, p.end)));
                    if (value(p) !== null) {
                        tooltipRow(box, 'var(--series-1)', format(value(p)),
                            secondary(pick(p, 'revenue'), pick(p, 'orders')));
                    }
                    if (p.lastYearStart === null) {
                        // calendar week 53 that last year did not have
                        box.appendChild(el('div', 'f10d-tip-title', labels.labelLastYear + ' · –'));
                        return;
                    }
                    box.appendChild(el('div', 'f10d-tip-title',
                        labels.labelLastYear + ' · ' + prefix + longLabel(p, p.lastYearStart, p.lastYearEnd)));
                    tooltipRow(box, 'var(--context)', format(lastYearValue(p)),
                        secondary(pick(p, 'lastYearRevenue'), pick(p, 'lastYearOrders')));
                }, clientX, clientY);
            }

            function hide() {
                hover.setAttribute('visibility', 'hidden');
                hideTooltip();
            }

            function indexAt(clientX) {
                var box = root.getBoundingClientRect();
                var px = (clientX - box.left) * (width / box.width);
                var i = n > 1 ? Math.round(((px - margin.left) / plotW) * (n - 1)) : 0;
                return Math.max(0, Math.min(n - 1, i));
            }

            overlay.addEventListener('pointermove', function (e) {
                show(indexAt(e.clientX), e.clientX, e.clientY);
            });
            overlay.addEventListener('pointerleave', hide);
            overlay.addEventListener('blur', hide);

            function showFromKeyboard(i) {
                var box = root.getBoundingClientRect();
                show(i, box.left + x(i) * (box.width / width), box.top + margin.top);
            }

            overlay.addEventListener('focus', function () {
                showFromKeyboard(active);
            });
            overlay.addEventListener('keydown', function (e) {
                if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
                    e.preventDefault();
                    showFromKeyboard(Math.max(0, Math.min(n - 1, active + (e.key === 'ArrowRight' ? 1 : -1))));
                }
            });
        }

        function renderTable(target) {
            if (!target) {
                return;
            }
            var table = el('table', 'f10d-table');
            var head = table.createTHead().insertRow();
            [labels.labelDate, labels.labelRevenue, labels.labelOrders,
                labels.labelLastYear, labels.labelLastYear + ' – ' + labels.labelOrders].forEach(function (text, i) {
                var th = el('th', i ? 'f10d-num' : '', text);
                head.appendChild(th);
            });

            var body = table.createTBody();
            points.forEach(function (p) {
                var row = body.insertRow();
                [
                    longLabel(p, p.start, p.end),
                    formatOrDash(p.revenue, money),
                    formatOrDash(p.orders, integer),
                    formatOrDash(p.lastYearRevenue, money),
                    formatOrDash(p.lastYearOrders, integer)
                ].forEach(function (text, i) {
                    var cell = row.insertCell();
                    cell.textContent = text;
                    if (i) {
                        cell.className = 'f10d-num';
                    }
                });

                // ▲/▼ on this period's values vs. the same span last year -
                // not for buckets still to come or still running
                if (p.revenue === null) {
                    return;
                }
                var comparable = !p.running && p.lastYearStart !== null;
                var tip = labels.labelLastYear + (p.lastYearStart ? ' · ' + longLabel(p, p.lastYearStart, p.lastYearEnd) : '') + ': ';
                appendDelta(row.cells[1], p.revenue, comparable ? p.lastYearRevenue : null,
                    tip + formatOrDash(p.lastYearRevenue, money));
                appendDelta(row.cells[2], p.orders, comparable ? p.lastYearOrders : null,
                    tip + formatOrDash(p.lastYearOrders, integer));
            });

            target.textContent = '';
            target.appendChild(table);
        }

        this.render = render;
        this.setCumulative = function (enabled) {
            cumulative = !!enabled;
            render();
        };
        this.setMetric = function (name) {
            metric = name === 'orders' ? 'orders' : 'revenue';
            render();
        };
        this.renderTable = renderTable;
    }

    function initTrend() {
        var container = document.getElementById('f10d-trend');
        var source = document.getElementById('f10d-trend-data');
        if (!container || !source) {
            return;
        }

        var title = document.getElementById('f10d-trend-title');
        var buttons = document.querySelectorAll('[data-metric]');
        container.dataset.chartTitle = title ? title.textContent : '';

        var chart = new TrendChart(container, JSON.parse(source.textContent));
        chart.render();
        chart.renderTable(document.getElementById('f10d-trend-table'));

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                var name = button.getAttribute('data-metric');
                buttons.forEach(function (other) {
                    other.classList.toggle('is-active', other === button);
                    other.setAttribute('aria-pressed', other === button ? 'true' : 'false');
                });
                if (title) {
                    title.textContent = title.getAttribute(name === 'orders' ? 'data-title-orders' : 'data-title-revenue');
                    container.dataset.chartTitle = title.textContent;
                }
                chart.setMetric(name);
            });
        });

        var cumulativeToggle = document.getElementById('f10d-trend-cumulative');
        if (cumulativeToggle) {
            // browsers may restore the checked state on reload
            chart.setCumulative(cumulativeToggle.checked);
            cumulativeToggle.addEventListener('change', function () {
                chart.setCumulative(cumulativeToggle.checked);
            });
        }

        var timer;
        window.addEventListener('resize', function () {
            clearTimeout(timer);
            timer = setTimeout(chart.render, 120);
        });
    }

    initTrend();
    initSimpleTips();
    initNavLinks();
    initTopSellers();
    initRange();
    initYearly();
})();
