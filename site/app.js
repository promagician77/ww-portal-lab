(function () {
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var E = window.WWPEngine;

  /* ---------- tabs (hash routed, no scroll jump) ---------- */
  var tabs = $$('.tabs a'), panels = $$('.panel');
  function show(id, focus) {
    if (!document.getElementById(id) || !$('.panel#' + id)) id = 'plan';
    panels.forEach(function (p) { p.hidden = p.id !== id; });
    tabs.forEach(function (t) { t.setAttribute('aria-selected', t.getAttribute('href') === '#' + id ? 'true' : 'false'); });
    var on = $('.tabs a[aria-selected=true]'); if (on && on.scrollIntoView) on.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    if (focus) { var top = $('.tabs').getBoundingClientRect().top + window.scrollY; if (window.scrollY > top) window.scrollTo(0, top); }
    if (id === 'access') drawSim();
  }
  tabs.forEach(function (t) {
    t.addEventListener('click', function (e) { e.preventDefault(); var id = t.getAttribute('href').slice(1); history.replaceState(null, '', '#' + id); show(id, true); });
  });
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[href^="#"]'); if (!a || a.closest('.tabs')) return;
    var id = a.getAttribute('href').slice(1); if ($('.panel#' + id)) { e.preventDefault(); history.replaceState(null, '', '#' + id); show(id, true); }
  });

  /* ---------- requirement map ---------- */
  var REQ = [
    ['Personal dashboard', [
      ['Secure client accounts', 'WordPress users + WooCommerce My Account', 'p'],
      ['Summary of finances, course progress and assigned resources', 'ww-portal dashboard', 'c'],
      ['Sections for finances, learning, coaching and products', 'ww-portal dashboard', 'c']]],
    ['Learning (LMS)', [
      ['Six-week courses with lessons, videos and worksheets', 'LearnDash course builder', 'p'],
      ['Track lesson and course progress', 'LearnDash', 'p'],
      ['Clients upload assignments for coach review', 'LearnDash assignments + approval', 'p'],
      ['Admins create courses easily', 'LearnDash drag-and-drop builder', 'p']]],
    ['Financial tracking', [
      ['Income, expenses, assets, liabilities', 'ww-portal worksheet', 'c'],
      ['Automatic net worth and monthly surplus', 'ww-portal, integer cents', 'c'],
      ['Dated snapshots, compared over time', 'ww-portal compare', 'c'],
      ['Latest figures on the dashboard', 'ww-portal', 'c'],
      ['Data entered in a course available outside it', 'one store for lesson and dashboard', 'c'],
      ['Past snapshots preserved', 'append-only + hash chain', 'c']]],
    ['Resources', [
      ['Assign resources to one client, without a course', 'ww-portal resources', 'c']]],
    ['Payments and access', [
      ['WooCommerce checkout, PayPal (+ Payfast for rand)', 'WooCommerce + gateways', 'p'],
      ['Access granted automatically on purchase', 'small WooCommerce hook into ww-portal', 'c'],
      ['10 weeks from the agreed programme start date', 'ww-portal access windows', 'c'],
      ['Optional monthly subscription to continue or restore', 'WooCommerce Subscriptions + ww-portal', 'c'],
      ['No loss of finance data or learning history on expiry', 'LearnDash deletion off + engine design', 'p'],
      ['Expired clients can still log in and buy', 'roles never change on expiry', 'p']]],
    ['Security and handover', [
      ['Protect financial data', 'encryption, isolation, audit log', 'c'],
      ['Training, documentation, handover', 'recorded sessions + admin guide', 's']]]
  ];
  var lab = { p: 'Plugin / setting', c: 'Custom', s: 'Service' };
  $('#req').innerHTML = REQ.map(function (g) {
    return '<div class="grp">' + g[0] + '</div>' + g[1].map(function (r) {
      return '<div class="r"><span>' + r[0] + '</span><span class="who ' + r[2] + '">' + lab[r[2]] + '</span><small>' + r[1] + '</small></div>';
    }).join('');
  }).join('');

  /* ---------- screenshots toggle ---------- */
  $$('.seg button').forEach(function (b) {
    b.addEventListener('click', function () {
      $$('.seg button').forEach(function (x) { x.classList.toggle('on', x === b); });
      $$('.shotwrap [data-for]').forEach(function (f) { f.hidden = f.getAttribute('data-for') !== b.getAttribute('data-shot'); });
    });
  });

  /* ---------- amount parser ---------- */
  function fmt(c, cents) {
    var a = Math.abs(c), r = Math.floor(a / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    return (c < 0 ? '−' : '') + 'R ' + r + (cents ? '.' + String(a % 100).padStart(2, '0') : '');
  }
  function parse() {
    var v = $('#amt').value, c = E.cents(v);
    $('#amt-out').innerHTML = c === null ? '<span style="color:var(--red)">Not an amount</span><small>The worksheet names the line: "expenses line 3: “' + v.replace(/[<>&]/g, '') + '” is not an amount"</small>'
      : fmt(c, true) + '<small>stored as ' + c.toLocaleString('en-US') + ' cents (integer)</small>';
  }
  ['R 12 500,50', '12,500.50', '1.234,56', '(2 500)', '−R 400', 'ZAR 45', 'R 12k'].forEach(function (s) {
    var b = document.createElement('button'); b.type = 'button'; b.textContent = s;
    b.addEventListener('click', function () { $('#amt').value = s; parse(); }); $('#amt-chips').appendChild(b);
  });
  $('#amt').addEventListener('input', parse); parse();

  /* ---------- access simulator ---------- */
  function addMonth(ymd, n) {
    var d = new Date(ymd + 'T00:00:00Z'), day = d.getUTCDate();
    d.setUTCDate(1); d.setUTCMonth(d.getUTCMonth() + n);
    var last = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 0)).getUTCDate();
    d.setUTCDate(Math.min(day, last)); return d.toISOString().slice(0, 10);
  }
  function diff(a, b) { return Math.round((Date.parse(b) - Date.parse(a)) / 864e5); }
  function nice(ymd) { var d = new Date(ymd + 'T00:00:00Z'); return d.getUTCDate() + ' ' + 'Jan Feb Mar Apr May Jun Jul Aug Sep Oct Nov Dec'.split(' ')[d.getUTCMonth()] + ' ' + d.getUTCFullYear(); }
  var sim = null;
  function model() {
    var buy = $('#s-buy').value, start = $('#s-start').value, weeks = Math.max(1, +$('#s-weeks').value || 10);
    var sub = $('#s-sub').value, months = Math.max(0, +$('#s-months').value || 0), grace = Math.max(0, +$('#s-grace').value || 0);
    if (!buy || !start) return null;
    if (start < buy) start = buy;
    var W = [{ w: [start, E.addDays(start, 7 * weeks)], made: buy }], pays = [];
    if (sub && months > 0) {
      for (var k = 0; k < months; k++) {
        var p = addMonth(sub, k), until = E.addDays(addMonth(sub, k + 1), grace);
        var cur = W.filter(function (x) { return x.made <= p; }).map(function (x) { return x.w; });
        var next = E.extendTo(cur, p, until);
        if (next.length > cur.length) W.push({ w: next[next.length - 1], made: p });
        pays.push(p);
      }
    }
    var from = E.addDays(buy, -7), endAll = W.reduce(function (m, x) { return x.w[1] > m ? x.w[1] : m; }, start);
    var to = E.addDays(endAll, 35); if (diff(from, to) < 200) to = E.addDays(from, 200);
    var days = [], n = diff(from, to);
    for (var i = 0; i <= n; i++) {
      var d = E.addDays(from, i);
      var ws = W.filter(function (x) { return x.made <= d; }).map(function (x) { return x.w; });
      var st = d < buy ? { status: 'none' } : E.state(ws, d);
      days.push({ d: d, st: st });
    }
    return { buy: buy, start: start, W: W, pays: pays, from: from, to: to, days: days, firstLast: E.addDays(W[0].w[1], -1), lastEnd: endAll };
  }
  function drawSim() {
    sim = model(); if (!sim) return;
    var n = sim.days.length, segs = [], cur = null;
    sim.days.forEach(function (x) { var s = x.st.status; if (!cur || cur.s !== s) { cur = { s: s, n: 0 }; segs.push(cur); } cur.n++; });
    var pct = function (d) { return (100 * diff(sim.from, d) / (n - 1)).toFixed(2) + '%'; };
    var marks = [['Paid', sim.buy, 0], ['Starts', sim.start, 1], ['Week ' + $('#s-weeks').value + ' ends', sim.firstLast, 0]];
    sim.pays.forEach(function (p, i) { marks.push([i ? '₢' : 'Subscribes', p, i ? 1 : 1]); });
    if (sim.pays.length) marks.push(['Paid through', E.addDays(sim.lastEnd, -1), 0]);
    marks = marks.filter(function (m) { return m[1] >= sim.from && m[1] <= sim.to; });
    $('#tl').innerHTML = '<div class="tl-bar">' + segs.map(function (s) { return '<span class="s-' + s.s + '" style="flex:' + s.n + '"></span>'; }).join('') + '</div>' +
      '<div class="tl-marks">' + marks.map(function (m) { return '<div class="tl-mark' + (m[2] ? ' alt' : '') + '" style="left:' + pct(m[1]) + '">' + (m[0] === '₢' ? 'Renewal' : m[0]) + '<br><small>' + nice(m[1]).replace(/ \d{4}$/, '') + '</small></div>'; }).join('') +
      '</div><div class="tl-cursor" id="tl-cur"></div>';
    var r = $('#s-day'); r.max = n - 1; if (+r.value > n - 1) r.value = Math.round((n - 1) * 0.6);
    dayCard();
  }
  function dayCard() {
    if (!sim) return;
    var i = +$('#s-day').value, x = sim.days[i], st = x.st, s = st.status;
    $('#tl-cur').style.left = (100 * i / (sim.days.length - 1)).toFixed(2) + '%';
    var course = { active: 'Open', upcoming: 'Not yet (starts ' + nice(st.starts_on || sim.start) + ')', expired: 'Locked', none: '—' }[s];
    var label = { active: 'Active, ' + st.days_left + ' day' + (st.days_left === 1 ? '' : 's') + ' left', upcoming: 'Paid, not started', expired: 'Ended ' + nice(st.last_day || sim.firstLast), none: 'Not a client yet' }[s];
    var data = s === 'none' ? '—' : 'All kept';
    var screen = { active: 'Dashboard + course', upcoming: 'Dashboard, start date shown', expired: 'Dashboard + renew button', none: '—' }[s];
    $('#day-card').innerHTML = '<div><dt>Date</dt><dd>' + nice(x.d) + '</dd></div><div><dt>Access</dt><dd class="st-' + s + '">' + label + '</dd></div><div><dt>Course</dt><dd>' + course + '</dd></div><div><dt>Finance data, progress, login</dt><dd>' + data + ' · ' + screen + '</dd></div>';
  }
  $$('.sim-controls input').forEach(function (i) { i.addEventListener('input', drawSim); });
  $('#s-day').addEventListener('input', dayCard);

  /* ---------- measured data ---------- */
  function load(url) { return fetch(url).then(function (r) { if (!r.ok) throw 0; return r.json(); }); }
  Promise.all([load('data/tests.json'), load('data/http.json'), load('data/parity.json')]).then(function (res) {
    var T = res[0], H = res[1], P = res[2];
    $('#env').textContent = 'Run ' + T.ran_at.slice(0, 16).replace('T', ' ') + ' UTC · WordPress ' + T.wordpress + ' · PHP ' + T.php + ' · ' + T.database + ' · LMS: ' + T.lms + '.';
    var p = T.perf;
    $('#perf').innerHTML = [
      ['Tests', T.passed + '/' + T.total], ['Save a snapshot', p.save_ms_avg + ' ms'], ['Dashboard API p50 / p95', p.summary_p50_ms + ' / ' + p.summary_p95_ms + ' ms'],
      ['Verify 520-row chain', p.verify_520_ms + ' ms'], ['Expiry sweep, 300 clients', Math.round(p.sweep_300_ms) + ' ms']
    ].map(function (x) { return '<div><dt>' + x[0] + '</dt><dd>' + x[1] + '</dd></div>'; }).join('');
    $('#test-table').innerHTML = T.results.map(function (r) {
      return '<div class="t"><span class="' + (r.ok ? 'ok' : 'bad') + '">' + (r.ok ? 'PASS' : 'FAIL') + '</span><span class="g">' + r.group + '</span><span>' + r.name + (r.detail ? '<small>' + r.detail.replace(/</g, '&lt;') + '</small>' : '') + '</span><span class="ms">' + r.ms + ' ms</span></div>';
    }).join('');
    $('#http').innerHTML = H.results.map(function (r) {
      return '<div class="c' + (r.ok ? '' : ' bad') + '"><b>' + (r.ok ? 'PASS' : 'FAIL') + '</b><span>' + r.name + '</span><small>' + (r.detail || '').replace(/</g, '&lt;') + '</small></div>';
    }).join('');
    var hp = H.results.filter(function (r) { return r.ok; }).length;
    $('[data-k=tests]').textContent = T.passed + '/' + T.total; $('[data-k=http]').textContent = hp + '/' + H.results.length;
    $('[data-k=parity]').textContent = P.access_agree.toLocaleString('en-US') + '/' + P.access_cases.toLocaleString('en-US');
    $('[data-k=p50]').textContent = p.summary_p50_ms + ' ms';
    document.body.setAttribute('data-ready', '1');
  }).catch(function () { document.body.setAttribute('data-ready', '1'); });

  show((location.hash || '#plan').slice(1), false);
})();
