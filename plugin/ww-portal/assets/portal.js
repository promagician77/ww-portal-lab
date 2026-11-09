/* Worksheet: add/remove lines and live totals. The form still posts without JS. */
(function () {
  function cents(text) {
    var s = String(text || '').replace(/[−–]/g, '-').trim(), neg = false;
    if (s.charAt(0) === '-') { neg = true; s = s.slice(1).trim(); }
    s = s.replace(/^(ZAR|R)\s*/i, '');
    if (s.charAt(0) === '-') { neg = !neg; s = s.slice(1); }
    s = s.replace(/[\s  ']/g, '');
    if (!/^[0-9.,]*$/.test(s) || s === '') return 0;
    var d = s.lastIndexOf('.'), c = s.lastIndexOf(','), dec = null;
    if (d > -1 && c > -1) dec = d > c ? '.' : ',';
    else if (c > -1) dec = (s.split(',').length === 2 && s.length - c - 1 <= 2) ? ',' : null;
    else if (d > -1) dec = (s.split('.').length === 2 && s.length - d - 1 <= 2) ? '.' : null;
    var i = s, f = '';
    if (dec) { var p = s.lastIndexOf(dec); i = s.slice(0, p); f = s.slice(p + 1); }
    var v = parseInt(i.replace(/[.,]/g, '') || '0', 10) * 100 + parseInt((f + '00').slice(0, 2), 10);
    return neg ? -v : v;
  }
  function fmt(c) {
    var n = Math.abs(Math.trunc(c / 100)).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    return (c < 0 ? '−' : '') + 'R ' + n;
  }
  document.querySelectorAll('[data-wwp-form]').forEach(function (form) {
    var tpl = form.querySelector('template[data-row]');
    function recalc() {
      var t = {};
      form.querySelectorAll('fieldset[data-sec]').forEach(function (fs) {
        var sec = fs.dataset.sec, sum = 0;
        fs.querySelectorAll('.wwp-row input:nth-of-type(2)').forEach(function (i) { sum += cents(i.value); });
        t[sec] = sum; var out = form.querySelector('[data-total="' + sec + '"]'); if (out) out.textContent = fmt(sum);
      });
      var nw = form.querySelector('[data-out="net_worth"]'), su = form.querySelector('[data-out="monthly_surplus"]');
      if (nw) nw.textContent = fmt((t.assets || 0) - (t.liabilities || 0));
      if (su) su.textContent = fmt((t.income || 0) - (t.expenses || 0));
    }
    form.addEventListener('input', recalc);
    form.addEventListener('click', function (e) {
      var add = e.target.closest('[data-add]');
      if (add) {
        var sec = add.dataset.add, row = tpl.content.firstElementChild.cloneNode(true), ins = row.querySelectorAll('input');
        ins[0].name = sec + '[label][]'; ins[1].name = sec + '[amount][]';
        add.parentNode.insertBefore(row, add); ins[0].focus();
      }
      var x = e.target.closest('.wwp-x');
      if (x) { x.parentNode.remove(); recalc(); }
    });
  });
})();
