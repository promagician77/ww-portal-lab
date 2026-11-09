// Real screenshots + HTTP-level isolation checks against the local WordPress.
const { chromium } = require('playwright');
const fs = require('fs');
const BASE = 'http://127.0.0.1:8090', OUT = __dirname + '/../shots/';
const results = [];
async function session(b, user, w = 1280, h = 900, mobile = false) {
  const ctx = await b.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: mobile ? 2 : 1.5, isMobile: mobile, hasTouch: mobile });
  const p = await ctx.newPage(); p.errs = []; p.on('pageerror', e => p.errs.push(e.message));
  if (user) {
    await p.goto(BASE + '/wp-login.php'); await p.fill('#user_login', user);
    await p.fill('#user_pass', user === 'admin' ? 'demo-admin-pass-123' : 'demo-pass-123');
    await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
  }
  return { ctx, p };
}
async function shot(p, path, name, opts = {}) {
  await p.goto(BASE + path); await p.evaluate(() => document.fonts.ready); await p.waitForTimeout(120);
  const sw = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  await p.screenshot({ path: OUT + name, fullPage: opts.full !== false, clip: opts.clip });
  console.log(name, 'overflow', sw, p.errs.join(';') || 'ok');
}
async function api(p, path, method = 'GET', body) {
  return p.evaluate(async ({ BASE, path, method, body }) => {
    const nonce = await (await fetch(BASE + '/wp-admin/admin-ajax.php?action=rest-nonce')).text();
    const r = await fetch(BASE + path, { method, headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined });
    let j = null; try { j = await r.json(); } catch (e) {}
    return { status: r.status, json: j };
  }, { BASE, path, method, body });
}
function check(name, ok, detail) { results.push({ name, ok: !!ok, detail }); console.log(ok ? 'PASS' : 'FAIL', name, detail || ''); }

(async () => {
  const b = await chromium.launch();
  // --- Thandi (active) ---
  let { ctx, p } = await session(b, 'thandi');
  await shot(p, '/portal/', 'dash-active.png');
  await shot(p, '/money-reset/week-2/', 'lesson-form.png');
  // End-to-end: change two numbers inside the lesson worksheet, save, then read them on the dashboard.
  const before = (await api(p, '/wp-json/wwp/v1/me/summary')).json.finance.latest;
  const inputs = p.locator('fieldset[data-sec="assets"] .wwp-row');
  await inputs.nth(0).locator('input').nth(1).fill('R 24 950,50');
  await p.locator('fieldset[data-sec="liabilities"] .wwp-row').nth(0).locator('input').nth(1).fill('11 400');
  await p.waitForTimeout(80);
  const box = await p.evaluate(() => { const a = document.querySelector('fieldset[data-sec="assets"]').getBoundingClientRect(), l = document.querySelector('.wwp-live').getBoundingClientRect(), f = document.querySelector('.wwp-form').getBoundingClientRect();
    return { x: f.left - 8, y: a.top + scrollY - 8, width: f.width + 16, height: l.bottom - a.top + 16 }; });
  await p.screenshot({ path: OUT + 'lesson-live.png', fullPage: true, clip: box });
  await Promise.all([p.waitForNavigation(), p.click('.wwp-live .wwp-btn')]);
  const sv = await p.evaluate(() => { const a = document.querySelector('.wwp-saved').getBoundingClientRect(), f = document.querySelector('.wwp-form').getBoundingClientRect(), h = document.querySelector('.wwp-form header').getBoundingClientRect();
    return { x: f.left - 8, y: f.top + scrollY - 8, width: f.width + 16, height: h.bottom - f.top + 20 }; });
  await p.screenshot({ path: OUT + 'lesson-saved.png', fullPage: true, clip: sv });
  const after = (await api(p, '/wp-json/wwp/v1/me/summary')).json.finance.latest;
  check('Lesson worksheet save appears as dashboard latest', after.id > before.id && after.source === 'course:money-reset/week-2' && after.totals.net_worth - before.totals.net_worth === (255050 + 150000), `net worth ${before.totals.net_worth} → ${after.totals.net_worth} cents, source ${after.source}`);
  await shot(p, '/portal/', 'dash-after.png');
  await p.setViewportSize({ width: 1280, height: 900 });
  // Isolation over real HTTP with real cookies.
  const own = await api(p, '/wp-json/wwp/v1/me/snapshots?user_id=5');
  check('/me/snapshots ignores ?user_id= and returns only own rows', own.status === 200 && own.json.length === 6, `status ${own.status}, rows ${own.json.length}`);
  const peek = await api(p, '/wp-json/wwp/v1/coach/clients/5/snapshots');
  check('Client calling coach route for another client gets 403', peek.status === 403, `status ${peek.status}`);
  const res = await api(p, '/wp-json/wwp/v1/me/resources');
  check('Client sees only assigned resources', res.status === 200 && res.json.length === 3 && !res.json.some(r => r.title === 'School fees sinking fund'), res.json.map(r => r.title).join(' | '));
  const direct = await p.request.get(BASE + '/wp-content/uploads/wwp-private/school-fees-sinking-fund.pdf');
  check('Direct URL to a private file is refused', direct.status() === 403, `status ${direct.status()}`);
  await p.goto(BASE + '/portal/');
  const dlHref = await p.evaluate(() => [...document.querySelectorAll('.wwp-res a[href*="wwp_download"]')].find(a => a.textContent.includes('Debt')).href);
  const xlsxHref = await p.evaluate(() => [...document.querySelectorAll('.wwp-res a[href*="wwp_download"]')].find(a => a.textContent.includes('Emergency')).href);
  const good = await p.request.get(dlHref);
  check('Assigned client downloads through the signed link', good.status() === 200 && (await good.body()).slice(0, 4).toString() === '%PDF', `status ${good.status()}, ${good.headers()['content-disposition']}`);
  const forged = await p.request.get(dlHref.replace(/r=\d+/, 'r=' + (await p.evaluate(() => 0)) + '999'));
  check('Forged resource id in the link is refused', forged.status() === 403, `status ${forged.status()}`);
  await ctx.close();
  // Mobile
  ({ ctx, p } = await session(b, 'thandi', 390, 844, true));
  await shot(p, '/portal/', 'dash-mobile.png');
  await shot(p, '/money-reset/week-2/', 'lesson-mobile.png');
  await ctx.close();
  // --- Pieter (expired) ---
  ({ ctx, p } = await session(b, 'pieter'));
  check('Expired client can still log in and lands on the portal', p.url().endsWith('/portal/'), p.url());
  await p.goto(BASE + '/wp-admin/'); check('Clients are kept out of wp-admin', p.url().endsWith('/portal/'), p.url());
  await shot(p, '/portal/', 'dash-expired.png');
  const ps = await api(p, '/wp-json/wwp/v1/me/summary');
  check('Expired client still sees full finance history and progress', ps.json.finance.series.length === 3 && ps.json.programmes[0].courses[0].completed === 10 && ps.json.programmes[0].state.status === 'expired', `snapshots ${ps.json.finance.series.length}, lessons done ${ps.json.programmes[0].courses[0].completed}`);
  const pdl = await p.request.get(xlsxHref);
  check("Another client's download link is refused (resource not assigned to them)", pdl.status() === 403, `status ${pdl.status()}`);
  await ctx.close();
  // --- Coach ---
  ({ ctx, p } = await session(b, 'lerato'));
  await shot(p, '/coach/', 'coach.png');
  await shot(p, '/coach/?wwp_client=4', 'coach-client.png');
  const own2 = await api(p, '/wp-json/wwp/v1/coach/clients/4/snapshots');
  check('Coach reads an assigned client (audited)', own2.status === 200 && own2.json.length === 6, `status ${own2.status}`);
  const other = await api(p, '/wp-json/wwp/v1/coach/clients/8/snapshots');
  check("Coach is refused another coach's client", other.status === 403, `status ${other.status}`);
  await ctx.close();
  // --- Logged out ---
  ({ ctx, p } = await session(b, null));
  const anon = await p.request.get(BASE + '/wp-json/wwp/v1/me/snapshots');
  check('Logged-out API call gets 401', anon.status() === 401, `status ${anon.status()}`);
  await ctx.close();
  // --- Admin ---
  ({ ctx, p } = await session(b, 'admin', 1360, 900));
  await shot(p, '/wp-admin/admin.php?page=wwp-portal', 'admin-access.png');
  await shot(p, '/wp-admin/admin.php?page=wwp-audit', 'admin-audit.png', { full: false });
  await p.goto(BASE + '/wp-admin/edit.php?post_type=wwp_resource');
  const edit = await p.evaluate(() => [...document.querySelectorAll('a.row-title')].find(a => a.textContent.includes('Debt'))?.href);
  await shot(p, edit.replace(BASE, ''), 'admin-resource.png', { full: false });
  await ctx.close();
  await b.close();
  fs.writeFileSync(OUT + 'http-checks.json', JSON.stringify({ ran_at: new Date().toISOString(), results }, null, 1));
  console.log(results.filter(r => r.ok).length + '/' + results.length + ' HTTP checks passed');
})();
