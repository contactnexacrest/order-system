'use strict';

const fs = require('fs');
const path = require('path');
const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const fxRateService = require('../../src/services/fxRateService');
const pricingService = require('../../src/services/pricingService');
const permissionService = require('../../src/services/permissionService');
const orderRepository = require('../../src/repositories/orderRepository');
const { createTestClient, createTestOrder, createTestDocument } = require('../support/fixtures');

/**
 * Port of FxRateBoxD340Test.php — owner decision D-340 (12 Nov 2026, "go with recommendation"):
 *  - Pricing settings: a "Rate used for prices right now" box at the top of the Exchange Rate
 *    fieldset — green (Automatic, fetched rate, no warning), orange (Automatic falling back to the
 *    manual rate, or any warning), blue (Manual) — with the facts, the warnings, the MD's OK for a
 *    big move, "Fetch today's rate now" and the history; badges on the cushion and manual-rate fields.
 *  - The order page: one line with the rate the prices were worked out at (live before the PI,
 *    fixed on the PI after), for price build-up viewers only.
 */
describe('D-340 exchange rate box and the order page rate line', () => {
  const PASSWORD = 'JestD340!1';
  const ROOT = path.join(__dirname, '..', '..');

  beforeEach(async () => {
    await db.execute('DELETE FROM fx_rates');
  });

  afterEach(async () => {
    await db.execute('DELETE FROM fx_rates');
    for (const [k, v] of Object.entries(pricingService.DEFAULTS)) {
      await db.execute('UPDATE pricing_settings SET setting_value = :v WHERE setting_key = :k', { k, v });
    }
  });

  afterAll(async () => {
    await db.pool.end();
  });

  const p2 = (n) => String(n).padStart(2, '0');
  const ymd = (d) => `${d.getFullYear()}-${p2(d.getMonth() + 1)}-${p2(d.getDate())}`;
  const today = () => ymd(new Date());
  const yesterday = () => { const d = new Date(); d.setDate(d.getDate() - 1); return ymd(d); };
  const day = (s) => { const [y, m, d] = s.split('-'); return `${Number(d)} ${['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][Number(m) - 1]} ${y}`; };

  async function rate(date, market, status, note = null) {
    await db.execute('INSERT INTO fx_rates (rate_date, market_rate, source, status, change_pct, note, fetched_at) VALUES (:d, :r, :s, :st, :c, :n, :t)', {
      d: date, r: market, s: market !== null ? 'Frankfurter (European Central Bank)' : null, st: status,
      c: status === 'waiting_md' ? '2.941' : null, n: note, t: `${date} 07:00:00`,
    });
  }

  async function setting(key, value) {
    await db.execute('UPDATE pricing_settings SET setting_value = :v WHERE setting_key = :k', { k: key, v: value });
  }

  async function page(roleName = 'Managing Director') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-d340-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    );
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });
    const res = await agent.get('/pricing/settings');
    return res.text.replace(/&#39;/g, "'").replace(/&quot;/g, '"');
  }

  /** The badge with this data-fx-when and text is shown (no hidden attribute). */
  const shown = (html, when, text) => html.includes(`data-fx-when="${when}">${text}<`);

  test('Automatic with today\'s rate is green', async () => {
    await rate(today(), 88.40, 'in_use');
    const fx = await fxRateService.status();
    expect([fx.state, fx.tone, fx.badge, fx.fetched, fx.cushion_line]).toEqual(['auto', 'green', 'Automatic · fetched today', `${day(today())}, 07:00`, '2.0% → ₹88.40 × 0.98']);
    expect(fx.manual_note).toBe('Manual rate ₹82.00 is not used — it is only the fall-back if no rate has ever been fetched.');
    const html = await page();
    for (const s of ['RATE USED FOR PRICES RIGHT NOW', 'fx-now fx-now-green', '₹86.63 per USD <span class="fx-now-badge">Automatic · fetched today</span>',
      '<span>Market rate</span><b>₹88.40</b>', '<span>Source</span><b>Frankfurter (European Central Bank)</b>', 'Why?',
      '<button type="submit" form="fx-fetch-form" class="btn-sm">Fetch today\'s rate now</button>', 'id="fx-fetch-form"',
      '<a class="btn-sm" href="#fx-history">History (last 30 fetches)</a>', '<details id="fx-history">', 'data-fx-has-market="1"']) {
      expect(html).toContain(s);
    }
    expect(shown(html, 'auto-market', 'not used now — fall-back only')).toBe(true);
    expect(shown(html, 'manual', 'not used in Manual')).toBe(false);
    expect(html).toMatch(/name="pricing_fx_rate"[^>]*data-fx-muted-when="auto-market" class="fx-unused"/);
    // The separate "Exchange Rate" block above the form is gone (its content is in the box).
    expect(html).not.toContain('<h2>Exchange Rate</h2>');
  });

  test('Automatic, never fetched: the manual rate is the fall-back, in orange', async () => {
    const fx = await fxRateService.status();
    expect([fx.state, fx.tone, fx.badge, fx.has_market]).toEqual(['fallback', 'orange', 'Manual fall-back', false]);
    const html = await page();
    for (const s of ['fx-now fx-now-orange', '₹82.00 per USD <span class="fx-now-badge">Manual fall-back</span>',
      '⚠ No market rate has been fetched yet — prices use the manual rate ₹82.00 until the first daily fetch.',
      'Check that the daily job runs (README › Scheduled jobs), or press "Fetch today\'s rate now".', 'data-fx-has-market="0"']) {
      expect(html).toContain(s);
    }
    expect(shown(html, 'auto-none', 'in use now')).toBe(true);
    expect(shown(html, 'auto-market', 'not used now — fall-back only')).toBe(false);
  });

  test('Automatic, today\'s fetch failed: the last rate stays, in orange', async () => {
    await rate(yesterday(), 88.16, 'in_use');
    await rate(today(), null, 'failed', 'Frankfurter (European Central Bank) did not answer');
    const fx = await fxRateService.status();
    expect([fx.state, fx.tone, fx.badge]).toEqual(['auto', 'orange', `Automatic · rate of ${day(yesterday())}`]);
    const html = await page();
    expect(html).toContain(`⚠ Today's rate could not be fetched (Frankfurter (European Central Bank) did not answer) — prices keep the rate of ${yesterday()}.`);
    expect(html).toContain('₹86.40 per USD');
    expect(shown(html, 'auto-market', 'not used now — fall-back only')).toBe(true);
  });

  test('Manual above the market is blue, with the comparison', async () => {
    await rate(today(), 88.40, 'in_use');
    await setting('fx_mode', 'manual');
    await setting('pricing_fx_rate', '93.0000');
    let fx = await fxRateService.status();
    expect([fx.state, fx.tone, fx.badge]).toEqual(['manual', 'blue', 'Manual']);
    expect(fx.compare).toBe(`For comparison, last market rate fetched: ₹88.40 (${day(today())}) — your rate is 5.2% above the market.`);
    expect(fx.above_note).toBe('Above the market means prices in USD come out lower than intended.');
    const html = await page();
    expect(html).toContain('fx-now fx-now-blue');
    expect(html).toContain('₹93.00 per USD <span class="fx-now-badge">Manual</span>');
    expect(shown(html, 'manual', 'not used in Manual')).toBe(true);
    expect(shown(html, 'manual', 'in use now')).toBe(true);
    expect(html).toMatch(/name="fx_cushion_pct"[^>]*data-fx-muted-when="manual" class="fx-unused"/);
    // Below the market: no warning.
    await setting('pricing_fx_rate', '86.0000');
    fx = await fxRateService.status();
    expect(fx.compare).toBe(`For comparison, last market rate fetched: ₹88.40 (${day(today())}) — your rate is 2.7% below the market.`);
    expect(fx.above_note).toBeNull();
  });

  test('the MD\'s decision on a big move sits in the box; the ED only looks', async () => {
    await rate(yesterday(), 88.40, 'in_use');
    await rate(today(), 91.00, 'waiting_md');
    const html = await page();
    expect(html).toContain('fx-now fx-now-orange');
    expect(html).toMatch(/<form method="post" action="\/pricing\/fx-rates\/\d+\/decide" id="fx-decide-form">/);
    expect(html).toContain('<input type="text" name="reason" form="fx-decide-form" required');
    expect(html).toContain('<button type="submit" form="fx-decide-form" name="decision" value="approve"');
    const ed = await page('Executive Director');
    expect(ed).toContain('RATE USED FOR PRICES RIGHT NOW');
    expect(ed).not.toContain('fx-fetch-form');
    expect(ed).not.toContain('fx-decide-form');
  });

  test('the order line before and after the Proforma Invoice', async () => {
    await rate(today(), 88.40, 'in_use');
    const orderId = await createTestOrder(await createTestClient());
    const order = await orderRepository.find(orderId);
    const live = { 1: { status: 'ok', line: { price_snapshot: null } } };
    expect(await fxRateService.orderLine(order, { 1: { status: 'unchecked', line: { price_snapshot: null } } })).toBeNull();
    expect(await fxRateService.orderLine(order, live)).toBe(`Prices worked out at ₹86.63 per USD (Automatic, ${day(today())}) — live: follows today's rate.`);
    await setting('fx_mode', 'manual');
    expect(await fxRateService.orderLine(order, live)).toBe('Prices worked out at ₹82.00 per USD (Manual) — live: follows today\'s rate.');
    await setting('fx_mode', 'auto');
    // After the PI: the rate fixed on the lines (price_snapshot), with the PI's date.
    await createTestDocument(orderId, 'PI');
    const fixed = { 1: { status: 'ok', line: { price_snapshot: JSON.stringify({ fx_rate: 85.5, floor: 1 }) } } };
    expect(await fxRateService.orderLine(order, fixed)).toBe(`Prices worked out at ₹85.50 per USD — fixed on the Proforma Invoice of ${day(today())} at ₹85.50 per USD; today's rate ₹86.63 does not change them.`);
    const same = { 1: { status: 'ok', line: { price_snapshot: JSON.stringify({ fx_rate: 86.632 }) } } };
    expect(await fxRateService.orderLine(order, same)).toBe(`Prices worked out at ₹86.63 per USD — fixed on the Proforma Invoice of ${day(today())} at ₹86.63 per USD.`);
    // Figures without a rate: the order's rate fixed with the PI.
    const line = await fxRateService.orderLine({ ...order, pricing_fx_rate_at_pi: '84.0000' }, { 1: { status: 'approved', line: { price_snapshot: '{"floor":1}' } } });
    expect(line.startsWith('Prices worked out at ₹84.00 per USD — fixed on the Proforma Invoice of ')).toBe(true);
  });

  test('only price build-up viewers see the order line', async () => {
    const can = async (roleName) => {
      const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
      return !!(await permissionService.load(0, role.id)).view_price_buildup;
    };
    expect(await can('Managing Director')).toBe(true);
    expect(await can('Executive Director')).toBe(true);
    expect(await can('Export Executive')).toBe(false);
    const view = fs.readFileSync(path.join(ROOT, 'views/orders/show.njk'), 'utf8');
    expect(view).toContain('{% if sees.selling and seesPriceBuildup and fxOrderLine %}<p class="price-rate-line small muted" id="price-rate">');
    const ctrl = fs.readFileSync(path.join(ROOT, 'src/controllers/ordersController.js'), 'utf8');
    expect(ctrl).toContain("fxOrderLine: seesPriceBuildup(req) ? await require('../services/fxRateService').orderLine(");
    // Never on documents (D-304).
    const walk = (dir) => fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? walk(path.join(dir, e.name)) : [path.join(dir, e.name)]));
    for (const f of walk(path.join(ROOT, 'templates'))) {
      expect(fs.readFileSync(f, 'utf8')).not.toContain('Prices worked out at');
    }
    expect(fs.readFileSync(path.join(ROOT, 'src/services/documentDataAssembler.js'), 'utf8')).not.toContain('fxRateService');
  });

  test('the badges follow the mode drop-down in the sitewide script', () => {
    const js = fs.readFileSync(path.join(ROOT, 'public/js/app.js'), 'utf8');
    expect(js).toContain("matches('select[data-fx-mode]')");
    expect(js).toContain("'auto-market' : 'auto-none'");
    expect(js).toContain("classList.toggle('fx-unused'");
  });
});
