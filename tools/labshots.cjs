const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch(); const out = process.argv[2] || '/tmp/lab';
  for (const [w, h, tag] of [[1440, 900, 'd'], [390, 844, 'm']]) {
    const p = await b.newPage({ viewport: { width: w, height: h }, deviceScaleFactor: 1 }); const errs = [];
    p.on('pageerror', e => errs.push(e.message)); p.on('console', m => m.type() === 'error' && errs.push(m.text()));
    await p.goto('http://127.0.0.1:8301/'); await p.waitForSelector('body[data-ready="1"]'); await p.evaluate(() => document.fonts.ready);
    for (const id of ['plan', 'dashboard', 'finance', 'access', 'payments', 'security', 'tests', 'handover']) {
      await p.click(`.tabs a[href="#${id}"]`); await p.waitForTimeout(150);
      await p.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 700) { window.scrollTo(0, y); await new Promise(r => setTimeout(r, 30)); } window.scrollTo(0, 0); });
      await p.waitForTimeout(200);
      const ov = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      await p.screenshot({ path: `${out}-${tag}-${id}.png`, fullPage: true });
      console.log(tag, id, 'overflow', ov);
    }
    console.log(tag, 'errors:', errs.join(' | ') || 'none'); await p.close();
  }
  await b.close();
})();
