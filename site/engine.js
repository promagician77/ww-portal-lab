/* Browser port of two pieces of the WordPress plugin, used by the simulator on this page.
   Parity-tested against the PHP originals (tools/parity.cjs): same inputs, same answers. */
(function (root) {
  function addDays(ymd, n) {
    var d = new Date(ymd + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + n);
    return d.toISOString().slice(0, 10);
  }
  function daysBetween(a, b) { return Math.round((Date.parse(b + 'T00:00:00Z') - Date.parse(a + 'T00:00:00Z')) / 864e5); }
  // Port of WWP_Access::state(). Windows are [starts_on, ends_on).
  function state(windows, today) {
    var ws = windows.slice().sort(function (x, y) { return x[0] < y[0] ? -1 : x[0] > y[0] ? 1 : 0; });
    if (!ws.length) return { status: 'none' };
    var spans = [];
    ws.forEach(function (w) {
      var n = spans.length;
      if (n && w[0] <= spans[n - 1][1]) { if (w[1] > spans[n - 1][1]) spans[n - 1][1] = w[1]; }
      else spans.push([w[0], w[1]]);
    });
    for (var i = 0; i < spans.length; i++) {
      var s = spans[i];
      if (s[0] <= today && today < s[1]) return { status: 'active', starts_on: s[0], ends_on: s[1], last_day: addDays(s[1], -1), days_left: daysBetween(today, s[1]) };
    }
    for (i = 0; i < spans.length; i++) if (spans[i][0] > today) return { status: 'upcoming', starts_on: spans[i][0], ends_on: spans[i][1], last_day: addDays(spans[i][1], -1) };
    var last = spans[spans.length - 1];
    return { status: 'expired', starts_on: last[0], ends_on: last[1], last_day: addDays(last[1], -1), days_left: 0 };
  }
  // Port of WWP_Access::extend_to(): renewals start where active access ends, else today.
  function extendTo(windows, today, until) {
    var st = state(windows, today), start = st.status === 'active' ? st.ends_on : today;
    if (until <= start) return windows;
    return windows.concat([[start, until]]);
  }
  // Port of WWP_Money::parse() (also what the worksheet uses for live totals).
  function cents(text) {
    var s = String(text || '').replace(/[−–]/g, '-').trim(), neg = false;
    if (s.charAt(0) === '(' && s.slice(-1) === ')') { s = s.slice(1, -1); neg = true; }
    if (s.charAt(0) === '-') { neg = !neg; s = s.slice(1).trim(); }
    s = s.replace(/^(ZAR|R)\s*/i, '');
    if (s.charAt(0) === '-') { neg = !neg; s = s.slice(1); }
    s = s.replace(/[\s  ']/g, '');
    if (s === '') return 0;
    if (!/^[0-9.,]+$/.test(s)) return null;
    var d = s.lastIndexOf('.'), c = s.lastIndexOf(','), dec = null;
    if (d > -1 && c > -1) dec = d > c ? '.' : ',';
    else if (c > -1) dec = (s.split(',').length === 2 && s.length - c - 1 <= 2) ? ',' : null;
    else if (d > -1) dec = (s.split('.').length === 2 && s.length - d - 1 <= 2) ? '.' : null;
    var i = s, f = '';
    if (dec) { var p = s.lastIndexOf(dec); i = s.slice(0, p); f = s.slice(p + 1); }
    i = i.replace(/[.,]/g, '') || '0';
    if (i.length > 13) return null;
    var v = parseInt(i, 10) * 100 + parseInt((f + '00').slice(0, 2), 10);
    return neg ? -v : v;
  }
  var api = { addDays: addDays, state: state, extendTo: extendTo, cents: cents };
  root.WWPEngine = api;
  if (typeof module !== 'undefined') module.exports = api;
})(typeof window !== 'undefined' ? window : globalThis);
