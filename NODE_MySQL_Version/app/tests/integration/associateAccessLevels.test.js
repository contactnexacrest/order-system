'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const associateRepository = require('../../src/repositories/associateRepository');
const associatePortalService = require('../../src/services/associatePortalService');
const associateService = require('../../src/services/associateService');
const associateAccess = require('../../src/services/associateAccess');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of AssociateAccessLevelsTest.php. D-261 (owner approval 6 Nov 2026, mockups mock13):
 * associate portal access levels. Starter / Quoting / Full from the MD's matrix, Custom per
 * associate; hidden features refused by the server (lock page, 403); the FOB value and the
 * commission % taken out on the server; MD-only changes with a reason, logged; the "Ready for
 * Quoting?" suggestion after 3 completed, fully paid orders.
 */
describe('Associate portal access levels (D-261)', () => {
  const PASSWORD = 'JestAccess!123';
  const ASSOCIATE_PASSWORD = 'Access1234567';
  const csrfOf = (html) => html.match(/name="_csrf"\s+value="([^"]+)"/)[1];
  let md;
  let admin;

  afterAll(async () => {
    await db.pool.end();
  });

  async function user(roleName, superAdmin = 0) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    return (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled, is_super_admin)
       VALUES (:name, :email, NULL, :hash, :r, 1, 0, 0, :s)`,
      { name: `Jest ${roleName}`, email: `jest-access-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`, hash: await bcrypt.hash(PASSWORD, 4), r: role.id, s: superAdmin },
    )).insertId;
  }

  beforeEach(async () => {
    md = await user('Managing Director');
    admin = await user('Admin', 1);
  });

  afterEach(async () => {
    // Back to the recommended defaults for every later test.
    for (const [level, fs] of Object.entries(associateAccess.defaultMatrix())) {
      for (const [f, on] of Object.entries(fs)) {
        if (f !== 'profile') await db.execute('UPDATE associate_access_level_settings SET allowed = :v WHERE level = :l AND feature_key = :f', { v: on ? 1 : 0, l: level, f });
      }
    }
  });

  async function associate(level) {
    const id = await associateRepository.create({ name: `Access Assoc ${crypto.randomBytes(3).toString('hex')}`, based_in: 'india', currency_code: 'INR',
      email: `access-${crypto.randomBytes(4).toString('hex')}@assoc.test`, status: 'active', portal_enabled: 1 }, admin);
    await db.execute('UPDATE associates SET portal_enabled = 1, access_level = :l WHERE id = :id', { l: level, id });
    return associateRepository.find(id);
  }

  /** An order of the associate's client with a goods line of FOB 12,345.67. */
  async function order(a) {
    const client = await createTestClient();
    await db.execute('UPDATE clients SET associate_id = :a WHERE id = :c', { a: Number(a.id), c: client });
    const orderId = await createTestOrder(client);
    await db.execute("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 123.4567, 12345.67)", { o: orderId });
    return orderId;
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

  async function staff(userId) {
    const agent = request.agent(app);
    const csrf = csrfOf((await agent.get('/login')).text);
    const email = (await db.queryOne('SELECT email FROM users WHERE id = :id', { id: userId })).email;
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });
    return agent;
  }

  /** Posts from `page`; returns the flash the next page shows. */
  async function post(agent, page, url, fields = {}) {
    const csrf = csrfOf((await agent.get(page)).text);
    const res = await agent.post(url).type('form').send({ _csrf: csrf, ...fields });
    const next = res.headers.location ? (await agent.get(res.headers.location.split('#')[0])).text : '';
    const m = next.match(/class="alert alert-(?:success|error)"[^>]*>([^<]*)</);
    return m ? m[1].trim().replace(/&#39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&') : null;
  }

  it('each level opens only its features and the server refuses the rest', async () => {
    const paths = [['/associate/clients', 'clients'], ['/associate/orders', 'orders'], ['/associate/catalogue', 'catalogue'],
      ['/associate/commission', 'commission'], ['/associate/commission?format=csv', 'commission_statement'], ['/associate/messages', 'messages']];
    const expected = {
      starter: { clients: true, orders: true, catalogue: false, commission: true, commission_statement: false, messages: true },
      quoting: { clients: true, orders: true, catalogue: true, commission: true, commission_statement: true, messages: true },
      full: { clients: true, orders: true, catalogue: true, commission: true, commission_statement: true, messages: true },
    };
    for (const [level, allowed] of Object.entries(expected)) {
      const agent = await portal(await associate(level));
      for (const [p, feature] of paths) {
        const r = await agent.get(p);
        if (allowed[feature]) {
          expect([level, p, r.status]).not.toEqual([level, p, 403]);
          expect(r.text).not.toContain('This page is not open for your account');
        } else {
          expect([level, p, r.status]).toEqual([level, p, 403]);
          expect(r.text).toContain('This page is not open for your account');
        }
      }
      const home = (await agent.get('/associate')).text;
      expect([level, home.includes('href="/associate/catalogue"')]).toEqual([level, allowed.catalogue]);
    }
    const s = await portal(await associate('starter'));
    const csrf = csrfOf((await s.get('/associate')).text);
    expect((await s.post('/associate/catalogue/1/price').type('form').send({ _csrf: csrf })).status).toBe(403);
  });

  it('a Starter sees no FOB value or rate until the matrix shows it', async () => {
    const a = await associate('starter');
    const o = await order(a);
    const agent = await portal(a);
    const list = (await agent.get('/associate/orders')).text;
    expect(list).not.toContain('12,345.67');
    expect(list).toContain('The order value (FOB) is not shown');
    expect((await agent.get(`/associate/orders/${o}`)).text).not.toContain('12,345.67');
    const filtered = associateAccess.filterOrder(await associateAccess.features(a), { fob_value: 12345.67, commission: { level: 'Level 2 — 5%', rate: 5, detail: null } });
    expect(filtered.fob_value).toBeNull();
    expect(filtered.commission.level).toBeNull();

    const mdAgent = await staff(md);
    const ticks = {};
    for (const [level, fs] of Object.entries(await associateAccess.matrix())) ticks[level] = Object.keys(fs).filter((k) => fs[k]);
    ticks.starter.push('fob_value');
    const csrf = csrfOf((await mdAgent.get('/associates/access-levels')).text);
    const body = new URLSearchParams({ _csrf: csrf, reason: 'Starter associates may now see the FOB value', action: 'save' });
    for (const [level, list2] of Object.entries(ticks)) for (const f of list2) body.append(`tick[${level}][]`, f);
    await mdAgent.post('/associates/access-levels').type('form').send(body.toString());
    expect((await agent.get('/associate/orders')).text).toContain('12,345.67');
    expect((await associateAccess.history(null, 1))[0].new_value).toBe('Starter: Show FOB order value on');

    const q = await associate('quoting');
    await order(q);
    expect((await (await portal(q)).get('/associate/orders')).text).toContain('12,345.67');
  });

  it('a Custom associate has their own ticks', async () => {
    const a = await associate('starter');
    const mdUser = await db.queryOne('SELECT * FROM users WHERE id = :id', { id: md });
    expect(await associateAccess.setLevel(a, 'custom', ['clients', 'catalogue', 'get_price'], 'Trial: may quote but not see orders', mdUser)).toBeNull();
    const now = await associateRepository.find(Number(a.id));
    expect(now.access_level).toBe('custom');
    const agent = await portal(now);
    expect((await agent.get('/associate/catalogue')).status).not.toBe(403);
    expect((await agent.get('/associate/orders')).status).toBe(403);
    expect((await agent.get('/associate/messages')).status).toBe(403);
    const h = (await associateAccess.history(Number(a.id), 1))[0];
    expect(h.old_value).toBe('Starter');
    expect(h.new_value).toBe('Custom: My clients, Catalogue (no prices), Get price / quoting');
    expect(h.reason).toBe('Trial: may quote but not see orders');
  });

  it('existing associates stay Full; a new one added in the app starts on Starter', async () => {
    const id = await associateRepository.create({ name: `Old Assoc ${crypto.randomBytes(3).toString('hex')}`, based_in: 'india', currency_code: 'INR', status: 'active' }, admin);
    expect((await associateRepository.find(id)).access_level).toBe('full');
    const agent = await staff(admin);
    const name = `New Assoc ${crypto.randomBytes(3).toString('hex')}`;
    await post(agent, '/associates/new', '/associates', { name, based_in: 'india', currency_code: 'INR', email: `new-${crypto.randomBytes(3).toString('hex')}@assoc.test`,
      phone: '+91-0000000000', commission_mode: 'ladder' });
    expect((await db.queryOne('SELECT access_level FROM associates WHERE name = :n', { n: name })).access_level).toBe('starter');
  });

  it('only the MD changes access, and a reason is needed', async () => {
    const a = await associate('starter');
    const adminAgent = await staff(admin);
    expect(await post(adminAgent, `/associates/${a.id}`, `/associates/${a.id}/access`, { access_level: 'full', reason: 'Promote them to the full portal' }))
      .toBe("Only the Managing Director can change an associate's portal access.");
    expect((await associateRepository.find(Number(a.id))).access_level).toBe('starter');
    expect(await post(adminAgent, '/associates/access-levels', '/associates/access-levels', { 'tick[starter][]': 'clients', reason: 'Admin tries to change the matrix', action: 'save' }))
      .toBe('Only the Managing Director can change the level settings.');

    const mdAgent = await staff(md);
    expect(await post(mdAgent, `/associates/${a.id}`, `/associates/${a.id}/access`, { access_level: 'quoting', reason: 'short' }))
      .toBe('Give the reason for the change (at least 10 characters).');
    expect(await post(mdAgent, `/associates/${a.id}`, `/associates/${a.id}/access`, { access_level: 'quoting', reason: '3 orders completed and paid — may now quote' }))
      .toMatch(/^Portal access changed\./);
    expect((await associateRepository.find(Number(a.id))).access_level).toBe('quoting');
    const page = (await mdAgent.get(`/associates/${a.id}`)).text;
    expect(page).toContain('id="portal-access"');
    expect(page).toContain('3 orders completed and paid — may now quote');
    expect((await mdAgent.get('/associates/access-levels')).text).toContain('Associate access levels');
  });

  it('notice amounts and the rate are hidden where the level does not show them', async () => {
    const s = await associate('starter');
    expect(await associateAccess.maskNotice(s, 'Your price request PR-1 has a price: quote USD 1,234.50, level 5%.'))
      .toBe('Your price request PR-1 has a price: quote [amount in your portal], level [rate in your agreement].');
    const f = await associate('full');
    expect(await associateAccess.maskNotice(f, 'Commission ₹6,281.71 at 7.5%.')).toBe('Commission ₹6,281.71 at 7.5%.');
    await associateService.notify(s, 'message', 'Order value USD 9,999.00 noted.', null, null, null, null, false);
    const m = await db.queryOne('SELECT message FROM associate_events WHERE associate_id = :a ORDER BY id DESC LIMIT 1', { a: Number(s.id) });
    expect(m.message).toBe('Order value [amount in your portal] noted.');
  });

  it('the MD is asked about Quoting after three completed, fully paid orders', async () => {
    let a = await associate('starter');
    for (let i = 0; i < 3; i += 1) {
      const o = await order(a);
      await db.execute("UPDATE orders SET status = 'complete' WHERE id = :o", { o });
      await db.execute(`INSERT INTO order_payment_status (order_id, advance_amount, advance_cleared_at) VALUES (:o, 1000, NOW())
        ON DUPLICATE KEY UPDATE advance_amount = 1000, advance_cleared_at = NOW(), balance_amount = NULL, freight_amount = NULL`, { o });
      a = await associateRepository.find(Number(a.id));
      expect([i, await associateAccess.suggestUpgrade(a)]).toEqual([i, i === 2]);
    }
    expect((await associateAccess.upgradeSuggestions()).map((u) => u.id)).toContain(Number(a.id));
    const mdAgent = await staff(md);
    expect((await mdAgent.get(`/associates/${a.id}`)).text).toContain('Ready for the Quoting level?');
    await post(mdAgent, `/associates/${a.id}`, `/associates/${a.id}/access/not-yet`);
    expect(await associateAccess.suggestUpgrade(await associateRepository.find(Number(a.id)))).toBe(false);
  });
});
