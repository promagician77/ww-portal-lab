# WordPress client portal lab

This lab is a working draft of the custom layer a WordPress coaching business needs on top of LearnDash and WooCommerce:

- **Finance tracker:** income, expenses, assets and liabilities. It works out net worth and monthly surplus, saves dated snapshots that are encrypted and append-only, and compares any two of them.
- **Programme access windows:** N weeks from the *agreed start date*, monthly renewals that restore access, and expiry that never deletes data.
- **Per-client resource library:** resources are assigned to a client without a course, and the files are protected.
- **One dashboard:** sections for finances, learning, coaching, resources and products, plus a coach view, an audit log, and POPIA export and erase.

It runs on **WordPress 7.1.4** with the SQLite Database Integration drop-in, so nothing beyond PHP and git is needed. All people and figures in it are fictional.

## Measured results (in `results/`)

| What | Result |
|---|---|
| Plugin test suite (real WordPress, real DB, real plugin code) | **29/29 tests, 132 assertions** |
| Live HTTP checks: browser logins as client, coach, admin and logged-out, with real cookies and nonces | **14/14** |
| Browser access simulator vs the PHP access engine, on generated cases | **2,021/2,021 agree** |
| Browser amount parser vs the PHP parser | **25/25 agree** |
| Saving one snapshot (encrypt + hash chain) | ~3 ms |
| `GET /me/summary` for a client with 520 weekly snapshots (10 years) | p50 ~5.2 ms, p95 ~6.3 ms |
| Nightly expiry sweep, 300 clients | ~0.6 s |
| Bug caught by the tests | negative amounts printed as "−R 400" couldn't be parsed back; now fixed and covered by a round-trip test |

Timings come from one run in a cloud sandbox on SQLite. Treat them as an order of magnitude, not as hosting benchmarks.

## Not measured

- **LearnDash adapter** (`class-wwp-lms.php`): written against LearnDash's documented functions (`ld_update_course_access`, `sfwd_lms_has_access`, `learndash_course_progress`) but not executed, because LearnDash is licensed. A stand-in adapter with the same interface ran instead.
- **WooCommerce, WooCommerce Subscriptions, Payfast and PayPal hooks** (`class-wwp-woo.php`): not installed here, so the hooks are thin calls into the tested access engine. They get exercised on staging with sandbox payments.
- **MySQL/MariaDB:** the queries are plain MySQL, but the timings above are SQLite.
- Elementor theme integration, load from many concurrent users on real hosting, and reminder emails (not built yet).

## Run it

```bash
bash tools/setup.sh          # clones WordPress 7.1.4 + SQLite plugin v3.1.3, installs, activates, seeds demo data (~10 s)
npm test                     # 29 tests -> results/test-results.json
npm run serve:wp             # http://127.0.0.1:8090/portal/   thandi / demo-pass-123 (active)
                             #                                 pieter / demo-pass-123 (expired)
                             #                                 lerato / demo-pass-123 (coach), admin / demo-admin-pass-123
npm i -D playwright && npx playwright install chromium
npm run shots                # screenshots + 14 HTTP checks -> shots/
npm run parity               # PHP vectors -> browser port check -> data/parity.json
```

Requirements: PHP 8.0+ with `pdo_sqlite` and `sodium`, git, and Node 20+ (for screenshots and parity only).

## Deploy the proposal site (Vercel)

The static site is in `site/`. `vercel.json` publishes that folder as-is (no build step) and sends `X-Robots-Tag: noindex, nofollow` on every response. The page also carries a robots `noindex` meta tag.

```bash
npx vercel deploy --prod
```

## Layout

```
plugin/ww-portal/          the WordPress plugin (≈1,700 lines of PHP) + tests/run.php + docs/HANDOVER.md
  includes/class-wwp-finance.php    snapshots: normalise, integer cents, encrypt, append-only, hash chain, compare
  includes/class-wwp-crypto.php     XChaCha20-Poly1305, key from wp-config, owner+date bound as additional data
  includes/class-wwp-access.php     access windows, merge, state, renewals, idempotent LMS sync, nightly sweep
  includes/class-wwp-lms.php        LMS adapter interface, LearnDash adapter, demo adapter
  includes/class-wwp-resources.php  resource CPT, per-client assignment, protected downloads
  includes/class-wwp-rest.php       /me/* (no user id accepted) and /coach/* (assigned + audited)
  includes/class-wwp-privacy.php    WordPress core export/erase integration
  includes/class-wwp-dashboard.php  dashboard, worksheet, coach view (shortcodes)
  includes/class-wwp-woo.php        WooCommerce + Subscriptions hooks (not executed here)
site/                      proposal site (static, Vercel-ready); engine.js = browser port used by the simulator
tools/                     setup, install, seed, router, screenshots, parity
results/                   raw outputs of the runs quoted above
```
