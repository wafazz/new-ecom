// Dashboards (admin, staff, member). Data is embedded in the page (no loading flash) and refreshed via fetch.
(function () {
  'use strict';
  var Viz = window.Viz, h = Viz.h;
  var $ = function (id) { return document.getElementById(id); };
  var toast = function (m, t, o) { if (window.Portal) window.Portal.toast(m, t, o); };
  function store(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function stored(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function clear(n) { n.textContent = ''; return n; }
  function icon(name) {
    var P = {
      up: 'M12 5l7 12H5z', down: 'M12 19L5 7h14z', check: 'M5 12l5 5 9-10', dot: 'M12 8a4 4 0 100 8 4 4 0 000-8z',
      warn: 'M12 3l10 18H2zM12 10v5M12 18v.5', clock: 'M12 3a9 9 0 100 18 9 9 0 000-18zM12 7v5l3 2', info: 'M12 3a9 9 0 100 18 9 9 0 000-18zM12 11v6M12 7.5v.5',
      arrow: 'M5 12h14M13 6l6 6-6 6'
    };
    var s = Viz.svg('svg', { viewBox: '0 0 24 24', class: 'ico', 'aria-hidden': 'true' });
    Viz.svg('path', { d: P[name] }, s);
    return s;
  }

  // ---- shared pieces -------------------------------------------------------------------------
  function renderQueue(host, items, emptyText) {
    clear(host);
    if (!items.length) {
      var ok = h('div', 'q-empty'); ok.appendChild(icon('check')); ok.appendChild(h('span', null, emptyText || 'Nothing needs you right now.'));
      host.appendChild(ok); return;
    }
    items.forEach(function (it) {
      var a = h('a', 'q-item tone-' + it.tone); a.href = it.href;
      var ic = h('span', 'q-ico'); ic.appendChild(icon(it.tone === 'crit' ? 'warn' : it.tone === 'warn' ? 'clock' : 'info')); a.appendChild(ic);
      a.appendChild(h('strong', 'q-count', Viz.int(it.count)));
      var tx = h('span', 'q-text'); tx.appendChild(h('span', 'q-label', it.label)); if (it.hint) tx.appendChild(h('small', 'muted', it.hint)); a.appendChild(tx);
      a.appendChild(icon('arrow'));
      host.appendChild(a);
    });
  }
  function renderRecent(host, rows, now) {
    clear(host);
    if (!rows.length) { host.appendChild(h('p', 'muted empty', 'No orders yet. They will appear here as they come in.')); return; }
    var t = h('table', 'dash-table'), hd = h('thead'), hr = h('tr');
    ['Order', 'Customer', 'Status', 'Total', 'Placed'].forEach(function (c, i) { hr.appendChild(h('th', i === 3 ? 'num' : null, c)); });
    hd.appendChild(hr); t.appendChild(hd);
    var tb = h('tbody');
    rows.forEach(function (o) {
      var tr = h('tr', 'clickable'), td = h('td'), a = h('a', null, o.no); a.href = o.href; td.appendChild(a); tr.appendChild(td);
      var nm = h('td', 'cut', o.name); nm.title = o.name; tr.appendChild(nm);
      var st = h('td'); st.appendChild(h('span', 'badge st-' + o.status, o.status));
      if (!o.paid && o.status !== 'cancelled' && o.method.toLowerCase().indexOf('cash') < 0) st.appendChild(h('span', 'badge draft', 'unpaid'));
      tr.appendChild(st);
      tr.appendChild(h('td', 'num', Viz.money(o.total)));
      var when = h('td', 'muted', Viz.ago(o.ts, now)); when.title = new Date(o.ts * 1000).toLocaleString(); tr.appendChild(when);
      tr.addEventListener('click', function (e) { if (!e.target.closest('a')) location.href = o.href; });
      tb.appendChild(tr);
    });
    t.appendChild(tb); host.appendChild(t);
  }
  function renderStock(host, low) {
    clear(host);
    if (!low.count) { host.appendChild(h('p', 'muted empty', 'Every active product has more than 5 in stock.')); return; }
    var ul = h('ul', 'stock-list');
    low.rows.forEach(function (r) {
      var li = h('li'), a = h('a', null, r.label); a.href = r.href; li.appendChild(a);
      li.appendChild(h('span', 'badge ' + (r.stock <= 0 ? 'st-cancelled' : 'st-pending'), r.stock <= 0 ? 'sold out' : r.stock + ' left'));
      ul.appendChild(li);
    });
    host.appendChild(ul);
    if (low.count > low.rows.length) host.appendChild(h('p', 'muted more', '+ ' + (low.count - low.rows.length) + ' more'));
  }
  function delta(cur, prev, good) {
    var d = h('span', 'delta');
    if (!(prev > 0)) { d.classList.add('flat'); d.appendChild(h('span', null, cur > 0 ? 'no data in the previous period' : 'no change')); return d; }
    var pct = ((cur - prev) / prev) * 100;
    if (Math.abs(pct) < 0.5) { d.classList.add('flat'); d.appendChild(h('span', null, 'level with the previous period')); return d; }
    var up = pct > 0; d.classList.add((up === good) ? 'good' : 'bad');
    d.appendChild(icon(up ? 'up' : 'down'));
    d.appendChild(h('span', null, (up ? '+' : '−') + Math.abs(pct).toFixed(Math.abs(pct) >= 10 ? 0 : 1) + '%'));
    return d;
  }

  // ---- admin ----------------------------------------------------------------------------------
  function admin(root) {
    var st = { data: JSON.parse($('dash-data').textContent), metric: 'sales', tab: 'status', table: false, first: true, lastOrder: null };
    var endpoint = root.dataset.endpoint, busy = false;

    function fmtFor(m) { return m.money ? Viz.money : Viz.int; }
    function shiftIso(iso, days) {
      var d = new Date(iso + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + days); return d.toISOString().slice(0, 10);
    }

    function renderHero(d) {
      var s = d.metrics.sales, host = clear($('dash-hero'));
      host.appendChild(h('div', 'hero-label', 'Sales · ' + d.rangeLabel));
      var v = h('div', 'hero-value'); v.appendChild(h('span', 'cur', Viz.currency)); var num = h('span', 'num', Viz.amount(s.total)); v.appendChild(num); host.appendChild(v);
      if (st.first) { num.textContent = Viz.amount(0); Viz.countUp(num, s.total, Viz.amount); }
      var dl = delta(s.total, s.prevTotal, true); dl.appendChild(h('small', 'muted', ' vs previous ' + d.days + ' days')); host.appendChild(dl);
      var best = 0; s.cur.forEach(function (x, i) { if (x > s.cur[best]) best = i; });
      host.appendChild(h('p', 'hero-note muted', s.total > 0
        ? 'Best day ' + Viz.day(d.labels[best]) + ' · ' + Viz.money(s.cur[best]) + ' · excludes cancelled orders'
        : 'No sales in this period yet. Excludes cancelled orders.'));
    }
    function renderTiles(d) {
      var host = clear($('dash-tiles'));
      [['orders', true], ['aov', true], ['profit', true], ['customers', true]].forEach(function (p) {
        var m = d.metrics[p[0]], f = fmtFor(m), t = h('div', 'tile');
        t.appendChild(h('div', 'tile-label', m.label));
        var val = h('div', 'tile-value'), num = h('span', 'num', m.money ? Viz.amount(m.total) : Viz.int(m.total)), nf = m.money ? Viz.amount : Viz.int;
        if (m.money) val.appendChild(h('span', 'cur', Viz.currency));
        val.appendChild(num); t.appendChild(val);
        if (st.first) { num.textContent = nf(0); Viz.countUp(num, m.total, nf); }
        t.appendChild(delta(m.total, m.prevTotal, p[1]));
        var sp = h('div', 'tile-spark'); t.appendChild(sp); host.appendChild(t);
        requestAnimationFrame(function () { Viz.sparkline(sp, m.cur); });
      });
    }
    function renderChart(d) {
      var m = d.metrics[st.metric], f = fmtFor(m), host = clear($('dash-chart'));
      var prevDates = d.labels.map(function (x) { return shiftIso(x, -d.days); });
      var peak = 0; m.cur.forEach(function (x, i) { if (x > m.cur[peak]) peak = i; });
      $('dash-chart-title').textContent = m.label + ' per day';
      var leg = clear($('dash-legend'));
      [['var(--viz-1)', 'This period'], ['var(--viz-ctx)', 'Previous ' + d.days + ' days']].forEach(function (x) {
        var i = h('span', 'leg'); var k = h('i'); k.style.background = x[0]; i.appendChild(k); i.appendChild(h('span', null, x[1])); leg.appendChild(i);
      });
      if (st.table) {
        var rows = d.labels.map(function (l, i) { return [Viz.day(l), f(m.cur[i]), f(m.prev[i])]; }).reverse();
        Viz.table(host, ['Day', 'This period', 'Previous period'], rows); return;
      }
      Viz.timeChart(host, {
        labels: d.labels, format: f, axisFormat: Viz.compact, title: m.label + ' per day',
        summary: 'Total ' + f(m.total) + ' over ' + d.days + ' days, previous period ' + f(m.prevTotal) + '. Highest day ' + Viz.day(d.labels[peak]) + ', ' + f(m.cur[peak]) + '.',
        series: [{ name: 'Previous period', values: m.prev, dates: prevDates }, { name: 'This period', values: m.cur, primary: true }]
      });
    }
    function renderBreakdown(d) {
      var host = $('dash-bars');
      if (st.tab === 'status') {
        Viz.hbars(host, d.status.map(function (r) { return { label: r.label, value: r.value, href: r.href }; }), { valueName: 'Orders', empty: 'No orders in this period.' });
      } else if (st.tab === 'products') {
        Viz.hbars(host, d.products.map(function (r) { return { label: r.label, value: r.value, href: r.href, extra: [['Units sold', Viz.int(r.units)]] }; }), { format: Viz.money, valueName: 'Sales', empty: 'No sales in this period.' });
      } else {
        Viz.hbars(host, d.payments.map(function (r) { return { label: r.label, value: r.value, extra: [['Orders', Viz.int(r.orders)]] }; }), { format: Viz.money, valueName: 'Sales', empty: 'No sales in this period.' });
      }
    }
    function renderSetup(d) {
      var host = clear($('dash-setup')), left = d.setup.filter(function (x) { return !x.done; });
      host.hidden = left.length === 0;
      if (!left.length) return;
      host.appendChild(h('h2', null, 'Finish setting up'));
      host.appendChild(h('p', 'muted', (d.setup.length - left.length) + ' of ' + d.setup.length + ' done'));
      var ul = h('ul', 'setup-list');
      d.setup.forEach(function (x) {
        var li = h('li', x.done ? 'done' : ''); var ic = h('span', 'setup-ico'); if (x.done) ic.appendChild(icon('check')); li.appendChild(ic);
        if (x.done) li.appendChild(h('span', null, x.label)); else { var a = h('a', null, x.label); a.href = x.href; li.appendChild(a); }
        ul.appendChild(li);
      });
      host.appendChild(ul);
    }

    function render(d) {
      st.data = d;
      $('dash-sub').textContent = new Date().toLocaleDateString('en-MY', { weekday: 'long', day: 'numeric', month: 'long' }) + ' · updated ' + new Date().toLocaleTimeString('en-MY', { hour: '2-digit', minute: '2-digit' });
      renderQueue($('dash-queue'), d.attention, 'Nothing needs you right now. New work will show up here.');
      renderSetup(d); renderHero(d); renderTiles(d); renderChart(d); renderBreakdown(d);
      renderRecent($('dash-recent'), d.recent, d.now); renderStock($('dash-stock'), d.lowStock);
      Array.prototype.forEach.call(document.querySelectorAll('[data-range]'), function (b) { b.setAttribute('aria-pressed', String(Number(b.dataset.range) === d.days)); });
      if (d.recent.length) {
        if (st.lastOrder && d.recent[0].no !== st.lastOrder) toast('New order ' + d.recent[0].no + ' · ' + Viz.money(d.recent[0].total), 'info', { href: d.recent[0].href, label: 'Open' });
        st.lastOrder = d.recent[0].no;
      }
      st.first = false;
    }

    function load(days, quiet) {
      if (busy) return; busy = true; root.classList.add('is-loading');
      fetch(endpoint + '?range=' + days, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
        .then(function (d) { render(d); })
        .catch(function () { if (!quiet) toast('Could not refresh the dashboard. Showing the last data.', 'error'); })
        .then(function () { busy = false; root.classList.remove('is-loading'); });
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-range]'), function (b) {
      b.addEventListener('click', function () { var n = Number(b.dataset.range); store('dashRange', n); load(n); });
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-metric]'), function (b) {
      b.addEventListener('click', function () {
        st.metric = b.dataset.metric;
        Array.prototype.forEach.call(document.querySelectorAll('[data-metric]'), function (x) { x.setAttribute('aria-pressed', String(x === b)); });
        renderChart(st.data);
      });
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-tab]'), function (b) {
      b.addEventListener('click', function () {
        st.tab = b.dataset.tab;
        Array.prototype.forEach.call(document.querySelectorAll('[data-tab]'), function (x) { x.setAttribute('aria-pressed', String(x === b)); });
        renderBreakdown(st.data);
      });
    });
    var tv = $('dash-table-toggle');
    if (tv) tv.addEventListener('click', function () { st.table = !st.table; tv.setAttribute('aria-pressed', String(st.table)); tv.textContent = st.table ? 'Show chart' : 'Show table'; renderChart(st.data); });
    var rf = $('dash-refresh'); if (rf) rf.addEventListener('click', function () { load(st.data.days); });

    var rt; window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(function () { renderChart(st.data); Array.prototype.forEach.call(document.querySelectorAll('.tile-spark'), function () {}); }, 150); });
    render(st.data);
    var pref = Number(stored('dashRange')); if (pref && pref !== st.data.days && [7, 30, 90].indexOf(pref) >= 0) load(pref, true);
    setInterval(function () { if (!document.hidden) load(st.data.days, true); }, 60000);
  }

  // ---- staff ----------------------------------------------------------------------------------
  function staff(root) {
    var d = JSON.parse($('dash-data').textContent), endpoint = root.dataset.endpoint, last = null, busy = false;
    function render(x) {
      renderQueue($('dash-queue'), x.attention, x.canOrders ? 'You are all caught up.' : 'No work queues for your access yet.');
      if ($('dash-bars')) Viz.hbars($('dash-bars'), x.status, { valueName: 'Orders', empty: 'No orders in the last 30 days.' });
      if ($('dash-recent')) renderRecent($('dash-recent'), x.recent, x.now);
      if ($('dash-stock')) renderStock($('dash-stock'), x.lowStock);
      $('dash-sub').textContent = 'Updated ' + new Date().toLocaleTimeString('en-MY', { hour: '2-digit', minute: '2-digit' });
      if (x.recent.length) { if (last && x.recent[0].no !== last) toast('New order ' + x.recent[0].no, 'info', { href: x.recent[0].href, label: 'Open' }); last = x.recent[0].no; }
    }
    function load() {
      if (busy) return; busy = true; root.classList.add('is-loading');
      fetch(endpoint, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); }).then(render)
        .catch(function () { toast('Could not refresh. Showing the last data.', 'error'); })
        .then(function () { busy = false; root.classList.remove('is-loading'); });
    }
    var rf = $('dash-refresh'); if (rf) rf.addEventListener('click', load);
    render(d);
    setInterval(function () { if (!document.hidden) load(); }, 60000);
  }

  // ---- member -----------------------------------------------------------------------------------
  function member() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-count]'), function (n) {
      var v = Number(n.dataset.count), f = n.dataset.format === 'money' ? Viz.money : Viz.int;
      n.textContent = f(0); Viz.countUp(n, v, f);
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-spark]'), function (n) {
      var vals = JSON.parse(n.dataset.spark); requestAnimationFrame(function () { Viz.sparkline(n, vals, { height: 56 }); });
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-copy]'), function (b) {
      b.addEventListener('click', function () {
        var text = b.dataset.copy;
        (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () {
          toast('Link copied to your clipboard.', 'success');
        }).catch(function () {
          var i = document.getElementById(b.dataset.target); if (i) { i.focus(); i.select(); }
          toast('Press Ctrl/Cmd+C to copy the selected link.', 'info');
        });
      });
    });
    var stepper = document.querySelector('.stepper'); if (stepper) requestAnimationFrame(function () { stepper.classList.add('go'); });
  }

  var root = $('dash');
  if (root && root.dataset.mode === 'admin') admin(root);
  else if (root && root.dataset.mode === 'staff') staff(root);
  else if ($('member-dash')) member();
})();
