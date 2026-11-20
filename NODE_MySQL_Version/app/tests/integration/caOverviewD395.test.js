'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const passwordHash = require('../../src/helpers/passwordHash');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const caOverviewService = require('../../src/services/caOverviewService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of CaOverviewD395Test.php. Owner decision D-395 (20 Nov 2026, mockup Option B):
 * the CA / Accounting home page shows "Needs attention" boxes first, then four section
 * cards, then the INR Settlement Register with a summary row and quick filters. Read only;
 * each box only for a person who may open the page it leads to.
 */
describe('CA / Accounting home — Option B (D-395)', () => {
  const ZOHO_ID_PREFIX = 'JEST-D395-';
  const PASSWORD = 'Ca-Overview-D395-1!';

  afterAll(async () => {
    await db.execute('DELETE FROM ca_expenses WHERE zoho_expense_id LIKE :p', { p: `${ZOHO_ID_PREFIX}%` });
    await db.pool.end();
  });

  function row(over) {
    return {
      order_id: 1, leg: 'advance', inr_actual: null, inr_estimated: null,
      forex_gain_loss: null, firc_pending: false, zoho_synced_at: null, ...over,
    };
  }

  it('summary counts and totals the rows shown', () => {
    const s = caOverviewService.registerSummary([
      row({ inr_actual: 100000.0, forex_gain_loss: 1500.0, zoho_synced_at: '2026-11-01 10:00:00' }),
      row({ inr_actual: 50000.5, forex_gain_loss: -500.25, firc_pending: true }),
      row({ inr_estimated: 84100.0 }),
    ]);
    expect(s.legs).toBe(3);
    expect(s.inr_credited).toBe(150000.5);
    expect(s.forex_net).toBe(999.75);
    expect(s.forex_known).toBe(true);
    expect(s.firc_pending).toBe(1);
    expect(s.not_in_zoho).toBe(1);
    expect(s.estimated).toBe(1);
  });

  it('shows no forex figure when no leg has one', () => {
    const s = caOverviewService.registerSummary([row({ inr_estimated: 100.0 })]);
    expect(s.forex_known).toBe(false);
    expect(s.inr_credited).toBe(0);
  });

  it('quick filters keep only their rows', () => {
    const rows = [
      row({ order_id: 1, inr_actual: 10.0, zoho_synced_at: '2026-11-01 10:00:00' }),
      row({ order_id: 2, inr_actual: 10.0, firc_pending: true }),
      row({ order_id: 3, inr_estimated: 10.0 }),
    ];
    const ids = (r) => r.map((x) => x.order_id);
    expect(ids(caOverviewService.filterRegister(rows, 'all'))).toEqual([1, 2, 3]);
    expect(ids(caOverviewService.filterRegister(rows, 'firc'))).toEqual([2]);
    expect(ids(caOverviewService.filterRegister(rows, 'zoho'))).toEqual([2]);
    expect(ids(caOverviewService.filterRegister(rows, 'estimated'))).toEqual([3]);
    expect(caOverviewService.normaliseFilter('anything-else')).toBe('all');
    expect(caOverviewService.normaliseFilter(null)).toBe('all');
    expect(caOverviewService.normaliseFilter('firc')).toBe('firc');
  });

  it('TDS is deposited by the 7th of the next month, March by 30 April', () => {
    expect(caOverviewService.tdsDueDate('2026-11')).toBe('2026-12-07');
    expect(caOverviewService.tdsDueDate('2026-12')).toBe('2027-01-07');
    expect(caOverviewService.tdsDueDate('2027-03')).toBe('2027-04-30');
    expect(caOverviewService.tdsDueDate('2027-04')).toBe('2027-05-07');
    expect(caOverviewService.shortDay('2026-12-07')).toBe('7 Dec 2026');
  });

  it('TDS for a month adds only the TDS-applicable expenses of that month', async () => {
    const ins = (id, date, applicable, tds) => db.execute(
      `INSERT INTO ca_expenses (zoho_expense_id, category, description, vendor_name, amount, currency_code, expense_date, is_tds_applicable, tds_amount)
       VALUES (:id, 'CHA', 'D-395 test', 'TEST vendor', 10000, 'INR', :date, :applicable, :tds)`,
      { id: ZOHO_ID_PREFIX + id, date, applicable, tds }
    );
    await ins('1', '2031-07-05', 1, 1000.0);
    await ins('2', '2031-07-20', 1, 250.5);
    await ins('3', '2031-07-21', 0, 999.0);
    await ins('4', '2031-08-01', 1, 777.0);

    const tds = await caOverviewService.tdsForMonth('2031-07-15');
    expect(tds.amount).toBe(1250.5);
    expect(tds.due).toBe('2031-08-07');
  });

  it('boxes appear only for those who may open their page', async () => {
    const summary = caOverviewService.registerSummary([]);
    const keys = (b) => b.map((x) => x.key);
    expect(keys(await caOverviewService.attention(summary, true, true, '2026-11-20'))).toEqual(['firc', 'bank', 'zoho', 'tds', 'benefits']);
    expect(keys(await caOverviewService.attention(summary, false, true, '2026-11-20'))).toEqual(['firc', 'bank', 'tds', 'benefits']);
    expect(keys(await caOverviewService.attention(summary, true, false, '2026-11-20'))).toEqual(['firc', 'zoho', 'benefits']);

    const tds = (await caOverviewService.attention(summary, false, true, '2026-11-20'))[2];
    expect(tds.label).toContain('deposit by 7 Dec 2026');
    expect(tds.href).toBe('/ca/tds-summary');
  });

  it('a toned box turns green when nothing is waiting', async () => {
    const boxes = await caOverviewService.attention({ firc_pending: 0, not_in_zoho: 3 }, true, false, '2026-11-20');
    const byKey = Object.fromEntries(boxes.map((b) => [b.key, b]));
    expect(byKey.firc.tone).toBe('ok');
    expect(byKey.zoho.tone).toBe('warn');
    expect(byKey.zoho.value).toBe('3');
  });

  async function createUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const hash = await passwordHash.hash(PASSWORD);
    const email = `jest-ca-d395-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest CA D395 User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash, role_id: role.id }
    );
    return { userId: result.insertId, email };
  }

  async function hub(email, path = '/ca') {
    const agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = (loginPage.text.match(/name="_csrf"\s+value="([^"]+)"/) || [])[1];
    const loginRes = await agent.post('/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });
    expect(loginRes.status).toBe(302);
    const res = await agent.get(path);
    expect(res.status).toBe(200);
    return res.text;
  }

  it('the Managing Director sees every box, the cards and the register summary', async () => {
    const { userId, email } = await createUser('Managing Director');
    const orderId = await createTestOrder(await createTestClient());
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 1000.0, '2026-01-10');
    await orderPaymentStatusRepository.markAdvanceCleared(orderId, '2026-01-15', userId);
    await orderPaymentStatusRepository.setLegExchangeRate(orderId, 'advance', 90.5, userId);
    await orderPaymentStatusRepository.setAdvanceInrActual(orderId, 91200.0, userId);

    const html = await hub(email);
    expect(html).toContain('Needs attention');
    for (const key of ['firc', 'bank', 'zoho', 'tds', 'benefits']) expect(html).toContain(`data-ca-box="${key}"`);
    for (const card of ['Revenue &amp; benefits', 'Expenses &amp; TDS', 'Bank &amp; reconciliation', 'Zoho Books &amp; year lock']) {
      expect(html).toContain(card);
    }
    expect(html).toContain('href="/ca/zoho-sync"');
    expect(html).toContain('id="register"');
    expect(html).toContain('data-ca-summary');
    expect(html).toContain('legs cleared');
    expect(html).toMatch(/class="btn-sm btn-secondary is-active" href="\/ca#register"/);
  });

  it('a filter shows only its legs and is marked active', async () => {
    const { userId, email } = await createUser('Managing Director');
    const orderId = await createTestOrder(await createTestClient());
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 1000.0, '2026-01-10');
    await orderPaymentStatusRepository.markAdvanceCleared(orderId, '2026-01-15', userId);
    await orderPaymentStatusRepository.setLegExchangeRate(orderId, 'advance', 77.7777, userId);

    const estimated = await hub(email, '/ca?show=estimated');
    expect(estimated).toContain('class="btn-sm btn-secondary is-active" href="/ca?show=estimated#register"');
    expect(estimated).toContain('77.7777');

    const notInZoho = await hub(email, '/ca?show=zoho');
    expect(notInZoho).not.toContain('77.7777');
  });

  it('the CA without Manage sees no Zoho box and no Zoho card', async () => {
    const { email } = await createUser('CA / Chartered Accountant');
    const html = await hub(email);
    expect(html).toContain('Needs attention');
    expect(html).not.toContain('data-ca-box="zoho"');
    expect(html).not.toContain('href="/ca/zoho-sync"');
    expect(html).not.toContain('href="/ca/fy-locks"');
    expect(html).toContain('data-ca-box="firc"');
    expect(html).toContain('data-ca-box="benefits"');
  });
});
