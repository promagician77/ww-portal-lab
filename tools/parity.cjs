// Same inputs through the PHP plugin (vectors.json) and the browser port (site/engine.js).
const E = require('../site/engine.js'); const fs = require('fs');
const V = JSON.parse(fs.readFileSync(__dirname + '/../data/vectors.json', 'utf8'));
let a = 0, m = 0, bad = [];
for (const c of V.access) {
  const got = E.state(c.windows, c.today), want = c.want;
  const keys = Object.keys(want).filter(k => want[k] !== null);
  if (keys.every(k => got[k] === want[k])) a++; else bad.push({ c, got });
}
for (const c of V.money) { if (E.cents(c.in) === c.want) m++; else bad.push({ money: c.in, want: c.want, got: E.cents(c.in) }); }
const res = { access_cases: V.access.length, access_agree: a, money_cases: V.money.length, money_agree: m, mismatches: bad.slice(0, 5) };
fs.writeFileSync(__dirname + '/../data/parity.json', JSON.stringify(res, null, 1));
console.log(`access ${a}/${V.access.length}, money ${m}/${V.money.length}`, bad.length ? JSON.stringify(bad.slice(0, 3)) : '');
