'use strict';

/**
 * Small UI fixes found while screenshotting the 11 Nov 2026 build (parity: ScreenshotFixesNov11Test.php):
 * 1. Products › Price rules is the active bar item on a category's Price rules tab, "Pricing ▾" on Pricing settings;
 *    the "+ [ ] %" input stays on one line; the category tabs sit on one tidy row.
 * 2. Supplier thickness prices: inputs wide enough, the long legends written as typed (not Title Case).
 * 3. Suppliers list: the table scrolls inside the card, actions stacked.
 * 4. Associate portal › My commission: "How it works" and "How we set your commission" name the same currency
 *    (D-264: India always rupees, abroad the payout currency).
 * 5. Approvals: the bar's "E-mails" count is what this person may approve, as the page's chip.
 * Both UI templates share these views and the section bar.
 */
const bcrypt = require('bcrypt');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const navigation = require('../../src/services/navigation');
const associateRepository = require('../../src/repositories/associateRepository');
const associateCodeService = require('../../src/services/associateCodeService');
const associatePortalService = require('../../src/services/associatePortalService');
const associatePayoutService = require('../../src/services/associatePayoutService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

const ROOT = path.join(__dirname, '..', '..');
const read = (rel) => fs.readFileSync(path.join(ROOT, rel), 'utf8');
const PASSWORD = 'JestShotsNov11!';
const ASSOCIATE_PASSWORD = 'ShotsAssoc12345';
const csrfOf = (html) => html.match(/name="_csrf"\s+value="([^"]+)"/)[1];

describe('11 Nov 2026 screenshot fixes', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function user(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-shots-${crypto.randomBytes(4).toString('hex')}@email.com`;
    const id = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Shots User', :email, NULL, :hash, :r, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), r: role.id }
    )).insertId;
    return { id, email };
  }

  async function signedIn(u) {
    const agent = request.agent(app);
    const csrf = csrfOf((await agent.get('/login')).text);
    await agent.post('/login').type('form').send({ _csrf: csrf, email: u.email, password: PASSWORD });
    return agent;
  }

  const activeLabels = (bar) => bar.items.filter((i) => i.active).map((i) => i.label);

  it('1. the Products bar marks Price rules on a category\'s rules tab and Pricing on Pricing settings', () => {
    const all = () => true;
    const flags = { approver: true, mod_associates: true };
    expect(activeLabels(navigation.build(all, flags, {}, '/products/categories/5', { tab: 'rules' }).bar)).toEqual(['Price rules']);
    expect(activeLabels(navigation.build(all, flags, {}, '/products/categories/5', { tab: 'general' }).bar)).toEqual(['Categories']);
    expect(activeLabels(navigation.build(all, flags, {}, '/products/categories/5', {}).bar)).toEqual(['Categories']);
    expect(activeLabels(navigation.build(all, flags, {}, '/products/categories', {}).bar)).toEqual(['Categories']);
    const pricing = navigation.build(all, flags, {}, '/pricing/settings', {}).bar;
    expect(activeLabels(pricing)).toEqual(['Pricing']);
    expect(pricing.items.find((i) => i.label === 'Pricing').children.filter((c) => c.active).map((c) => c.label))
      .toEqual(['Pricing settings (margin, buffer, exchange rate)']);
    // The drop-down's summary is drawn like the other bar items (the sitewide "▸ button" look skips it), so its gold underline shows.
    expect(read('views/partials/section_nav.njk')).toContain('<details class="section-nav-details">');
    const css = read('public/css/app.css');
    expect(css).toContain(':not(.dept-switch):not(.section-nav-details) > summary{');
    expect(css).toContain('.section-nav-drop.active > details > summary{font-weight:700;border-bottom-color:var(--gold);}');
  });

  it('1. the price-rule input stays on one line and the category tabs are one row', () => {
    const rules = read('views/products/_price_rules.njk');
    expect(rules).toContain('<td class="rule-cell">{% if t.base %}base{% else %}+ <input type="text"');
    expect(rules).toContain('class="rule-input"');
    expect(rules).not.toContain('style="width:6em"');
    const css = read('public/css/app.css');
    expect(css).toContain('.tab-pills{display:flex;flex-wrap:wrap;gap:0.5rem;margin:0.75rem 0 1.5rem;}');
    expect(css).toContain('td.rule-cell{white-space:nowrap;}');
    expect(css).toContain('td.rule-cell input.rule-input{display:inline-block;width:6em;');
  });

  it('2. thickness prices are wide enough and the long legends keep their sentence case', () => {
    const css = read('public/css/app.css');
    expect(css).toContain('.field-grid > fieldset.full{grid-column:1 / -1;}');
    expect(css).toContain('.thickness-grid input.grid-price{min-width:14ch;}');
    expect(css).toContain('legend.legend-sentence{text-transform:none;}');
    // The global legend style (capitalised short titles) is left as it is.
    expect(css).toContain('legend{padding:0 0.5rem;font-weight:700;color:var(--navy);text-transform:capitalize;}');
    for (const view of ['views/products/supplier_price_edit.njk', 'views/products/market_prices.njk']) {
      expect(read(view)).toContain('<legend class="legend-sentence">Prices by thickness (₹) — enter any one; the others are worked out. Change any of them if the supplier quoted it.</legend>');
    }
    expect(read('views/products/_cha_transport.njk')).toContain('<legend class="legend-sentence">CHA + transport (one price per container)</legend>');
  });

  it('3. the Suppliers table scrolls inside its card with the actions stacked', async () => {
    const page = (await (await signedIn(await user('Managing Director'))).get('/suppliers')).text;
    expect(page).toContain('<div class="table-scroll">\n    <table class="list suppliers-table">');
    expect(page).toContain('<div class="row-actions">');
    const css = read('public/css/app.css');
    expect(css).toContain('.suppliers-table .row-actions{display:flex;flex-direction:column;align-items:flex-start;gap:4px;}');
  });

  describe('4. My commission: one currency in both texts (D-264)', () => {
    let staffId;
    beforeAll(async () => {
      staffId = (await user('Admin')).id;
    });

    async function associate(extra) {
      const tag = crypto.randomBytes(3).toString('hex').toUpperCase();
      const id = await associateRepository.create({ name: `Shots Associate ${tag}`, email: `associate-${tag.toLowerCase()}@email.com`, status: 'active', portal_enabled: 1, ...extra }, staffId);
      await db.execute('UPDATE associates SET portal_enabled = 1, currency_code = :c WHERE id = :id', { id, c: extra.currency_code });
      await associateCodeService.issue(id, `S${tag}${String(id).slice(-2)}`);
      return associateRepository.find(id);
    }

    async function portal(a) {
      const agent = request.agent(app);
      const token = await associatePortalService.issueToken(Number(a.id), 'set_password', 60);
      const csrf = csrfOf((await agent.get(`/associate/set-password/${token}`)).text);
      await agent.post(`/associate/set-password/${token}`).type('form').send({ _csrf: csrf, new_password: ASSOCIATE_PASSWORD, confirm_password: ASSOCIATE_PASSWORD });
      const loginCsrf = csrfOf((await agent.get('/associate/login')).text);
      await agent.post('/associate/login').type('form').send({ _csrf: loginCsrf, email: a.email, password: ASSOCIATE_PASSWORD });
      const codePage = (await agent.get('/associate/login/code')).text;
      const code = codePage.match(/your login code is (\d{6})/)[1];
      await agent.post('/associate/login/code').type('form').send({ _csrf: csrfOf(codePage), code });
      return agent;
    }

    it('the paid-in currency: India always rupees, abroad the chosen currency (USD when none)', () => {
      expect(associatePayoutService.payCurrency({ based_in: 'india', currency_code: 'USD' })).toBe('INR');
      expect(associatePayoutService.payCurrency({ based_in: 'india', currency_code: 'INR' })).toBe('INR');
      expect(associatePayoutService.payCurrency({ based_in: 'abroad', currency_code: 'EUR' })).toBe('EUR');
      expect(associatePayoutService.payCurrency({ based_in: 'abroad', currency_code: '' })).toBe('USD');
      expect(associatePayoutService.payCurrency({ based_in: 'abroad', currency_code: 'INR' })).toBe('USD');
    });

    it('an associate in India (even with an old USD record) reads "rupees" in both places', async () => {
      const a = await associate({ based_in: 'india', country: 'India', currency_code: 'USD' });
      const page = (await (await portal(a)).get('/associate/commission')).text.replace(/&#39;/g, "'");
      expect(page).toContain('in Indian rupees to your verified bank account.</li>');
      expect(page).not.toContain('in US dollars to your verified bank account');
      expect(page).toContain("we turn it into rupees at the rate the bank used for the buyer's payment. We pay you that rupee amount.");
    });

    it('an associate abroad paid in euros reads "euros" in both places, never rupees', async () => {
      const a = await associate({ based_in: 'abroad', country: 'Germany', currency_code: 'EUR' });
      const page = (await (await portal(a)).get('/associate/commission')).text.replace(/&#39;/g, "'");
      expect(page).toContain('in euros to your verified bank account.</li>');
      expect(page).toContain('we set the amount in euros. We pay you exactly that amount in euros into your checked bank account.');
      expect(page).not.toContain('rupee');
    });
  });

  it('5. the Approvals bar counts the e-mails this person may approve — the same number as the page\'s chip', async () => {
    const md = await user('Managing Director');
    const sales = await user('Export Executive');
    const orderId = await createTestOrder(await createTestClient());
    const ids = [];
    // One e-mail waiting for Level-2 approval (counted), one already approved and waiting to go out (not an approval).
    ids.push((await db.execute(`INSERT INTO email_log (order_id, template_key, recipient_email, subject, body_snapshot, status, requested_by)
       VALUES (:o, 'test', 'buyer@email.com', 'Jest shots pending', 'Body', 'pending_approval', :u)`, { o: orderId, u: sales.id })).insertId);
    ids.push((await db.execute(`INSERT INTO email_log (order_id, template_key, recipient_email, subject, body_snapshot, status, requested_by, scheduled_at)
       VALUES (:o, 'test', 'buyer@email.com', 'Jest shots approved', 'Body', 'approved', :u, DATE_ADD(NOW(), INTERVAL 7 DAY))`, { o: orderId, u: sales.id })).insertId);
    try {
      const page = (await (await signedIn(md)).get('/approvals')).text;
      const chip = Number(page.match(/href="\/approvals\?tab=emails">E-mails (\d+)<\/a>/)[1]);
      const bar = page.match(/<a href="\/email-approvals"[^>]*>E-mails(?: <span class="nav-badge">(\d+)<\/span>)?<\/a>/);
      expect(bar).not.toBeNull();
      expect(chip).toBeGreaterThanOrEqual(1);
      expect(Number(bar[1] || 0)).toBe(chip);
    } finally {
      await db.execute(`UPDATE email_log SET status = 'cancelled' WHERE id IN (${ids.map(Number).join(',')})`);
    }
  });
});
