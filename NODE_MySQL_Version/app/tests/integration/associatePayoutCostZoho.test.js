'use strict';

jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn(async () => Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const crypto = require('crypto');
const db = require('../../src/config/db');
const associateRepository = require('../../src/repositories/associateRepository');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const incentiveRepository = require('../../src/repositories/incentiveRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const associatePayoutService = require('../../src/services/associatePayoutService');
const caSyncService = require('../../src/services/caSyncService');
const zohoBooksService = require('../../src/services/zohoBooksService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of AssociatePayoutCostZohoTest.php — owner decision D-264 (7 Nov 2026, "yes add the payout
 * difference to zoho"): one Books journal per payout — Associate payout costs debited, the bank
 * credited (a saving the other way round), notes = the description the app builds (rule D-212);
 * sent once, updated on a change, skipped while the Zoho account is not chosen. Mocked Zoho only.
 */
describe('Associate payout costs to Zoho Books (D-264)', () => {
  let adminId;
  let journals;
  let calls;

  beforeAll(async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    adminId = (await db.execute(
      "INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled) VALUES ('Jest Test User', :e, NULL, 'x', :r, 1, 0, 0)",
      { e: `jest-apc-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`, r: role.id }
    )).insertId;
  });

  beforeEach(async () => {
    journals = {};
    calls = [];
    for (const [k, v] of Object.entries({ associate_commission_due_days: '30', associate_claim_hold: '1', associate_payout_run: 'monthly', associate_payout_day: '5',
      associate_counting_threshold_inr: '500000', associate_abroad_charges: 'OUR', associate_abroad_min_payout_usd: '100', zoho_payout_costs_account_id: '' })) {
      await incentiveRepository.setSetting(k, v, adminId);
    }
  });

  afterEach(async () => {
    zohoBooksService.setTransport(null);
    delete process.env.ZOHO_INTEGRATION_ENABLED;
    delete process.env.ZOHO_ENVIRONMENT;
    delete process.env.ZOHO_EINVOICE_ENABLED;
    await companySettingsRepository.set('zoho_books_enabled', '0');
    await incentiveRepository.setSetting('zoho_payout_costs_account_id', '', adminId);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  /** The D-264 mockup order, paid abroad: USD 1,250.00 fixed at ₹1,04,312.50; ₹1,05,125.00 debited + ₹1,850.00 charges. */
  async function paidAbroad(buyer = 'TEST-Payout Buyer') {
    const level = Number((await db.queryOne("SELECT id FROM commission_levels WHERE name = 'Level 3'")).id);
    const a = Number((await db.execute("INSERT INTO associates (name, status, commission_mode, commission_level_id, based_in, currency_code) VALUES (:n, 'active', 'fixed', :l, 'abroad', 'USD')",
      { n: `Demo Associate ${crypto.randomBytes(3).toString('hex')}`, l: level })).insertId);
    const client = await createTestClient();
    await db.execute("UPDATE clients SET associate_id = :a, associate_registered_on = '2026-09-01', company_legal_name = :b WHERE id = :c", { a, b: buyer, c: client });
    const orderId = await createTestOrder(client, 'FOB', 'Standard — New Buyer', 'USD');
    await db.execute("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value, line_type) VALUES (:o, 1, 'Granite', 100, 'CBM', 178.5714, 17857.14, 'goods')", { o: orderId });
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
    await db.execute("UPDATE order_payment_status SET advance_amount = 5000.00, advance_cleared_at = '2026-10-15 10:00:00', balance_amount = 12857.14, balance_cleared_at = '2026-11-12 11:00:00' WHERE order_id = :o", { o: orderId });
    await db.execute("INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, inr_credited, bank_reference) VALUES (:o, 'advance', 5000.00, '2026-10-15', 83.10, 415500.00, 'FIRC2610150003'), (:o2, 'balance', 12857.14, '2026-11-12', 83.59, 1074678.33, 'FIRC2611120007')", { o: orderId, o2: orderId });
    await associatePayoutService.autoFix(orderId);
    const id = Number((await db.queryOne("SELECT id FROM incentive_payouts WHERE order_id = :o AND payee_kind = 'associate' AND clawback_of_payout_id IS NULL", { o: orderId })).id);
    await db.execute("UPDATE incentive_payouts SET status = 'paid', paid_on = '2026-12-05', paid_inr = 104312.50, tds_inr = 0, paid_reference = 'FT2612050001' WHERE id = :id", { id });
    await associatePayoutService.recordPaymentCosts(id, 105125.0, 1850.0);
    return [await incentiveRepository.findPayout(id), orderId, a];
  }

  async function fakeZoho() {
    process.env.ZOHO_INTEGRATION_ENABLED = 'true';
    process.env.ZOHO_ENVIRONMENT = 'test';
    process.env.ZOHO_EINVOICE_ENABLED = 'false';
    for (const [k, v] of Object.entries({ zoho_books_enabled: '1', zoho_books_client_id: 'fake-client', zoho_books_client_secret: 'fake-secret', zoho_books_refresh_token: 'fake-refresh', zoho_books_organization_id: '600', zoho_books_deposit_account_id: 'SBI-1' })) {
      await companySettingsRepository.set(k, v);
    }
    zohoBooksService.setTransport((method, url, payload) => {
      const p = new URL(url).pathname;
      const json = (body, status = 200) => ({ status, body: JSON.stringify(body) });
      if (p.startsWith('/books/v3/journals')) calls.push(`${method} ${p}`);
      if (p === '/oauth/v2/token') return json({ access_token: 'fake-access' });
      if (p === '/books/v3/journals' && method === 'POST') {
        const id = `JN-${Object.keys(journals).length + 1}`;
        journals[id] = payload;
        return json({ code: 0, journal: { journal_id: id } }, 201);
      }
      if (p.startsWith('/books/v3/journals/') && method === 'PUT') {
        const id = p.slice('/books/v3/journals/'.length);
        journals[id] = payload;
        return json({ code: 0, journal: { journal_id: id } });
      }
      return json({ code: 5, message: `fake Zoho has no ${method} ${p}` }, 400);
    });
    // The test database is shared by the whole run: earlier tests' payout costs are not this test's.
    for (const p of await caSyncService.payoutCostsToSync(null)) {
      await db.execute("UPDATE incentive_payouts SET zoho_cost_journal_id = 'OLD', zoho_cost_hash = :h WHERE id = :id", { h: caSyncService.payoutCostHash(p), id: p.id });
    }
  }

  it('books payout costs against the bank, and a saving the other way round', async () => {
    let [p, orderId, a] = await paidAbroad();
    // Live Zoho check 7 Nov 2026: in Books the order goes by its buyer inquiry ref, as on the commission bill.
    const ref = (await orderRepository.find(orderId)).buyer_inquiry_ref;
    const name = (await associateRepository.find(a)).name;
    const description = `Associate commission payout — ${ref} — ${name} — USD 1,250.00 sent, SWIFT ref FT2612050001; exchange difference ₹812.50 and transfer charges ₹1,850.00`;
    expect(await zohoBooksService.payoutCostJournalPayload(p, 'AC-APC', 'SBI-1', null, true)).toEqual({
      journal_date: '2026-12-05',
      reference_number: `${ref}-APC-${p.id}`,
      notes: `TEST- ${description}`,
      line_items: [
        { account_id: 'AC-APC', debit_or_credit: 'debit', amount: 2662.5, description },
        { account_id: 'SBI-1', debit_or_credit: 'credit', amount: 2662.5, description: 'Associate payout costs paid from the bank' },
      ],
    });

    // The rupee came out cheaper: ₹1,02,000.00 debited + ₹1,850.00 charges against ₹1,04,312.50 → a saving of ₹462.50.
    await associatePayoutService.recordPaymentCosts(Number(p.id), 102000.0, 1850.0);
    p = await incentiveRepository.findPayout(Number(p.id));
    expect(String(p.payout_difference_inr)).toBe('-462.50');
    const j = await zohoBooksService.payoutCostJournalPayload(p, 'AC-APC', 'SBI-1');
    expect(j.line_items).toEqual([
      { account_id: 'AC-APC', debit_or_credit: 'credit', amount: 462.5, description: `Associate commission payout — ${ref} — ${name} — USD 1,250.00 sent, SWIFT ref FT2612050001; exchange difference ₹-2,312.50 and transfer charges ₹1,850.00` },
      { account_id: 'SBI-1', debit_or_credit: 'debit', amount: 462.5, description: 'Associate payout cost saving (the rupee came out cheaper)' },
    ]);
    expect(j.notes.startsWith('Associate commission payout')).toBe(true); // no TEST- prefix for a buyer that is not test data
  });

  it('the commission payment to an associate abroad carries its SWIFT reference', async () => {
    // Live Zoho check 7 Nov 2026: the vendor payment of an associate paid abroad said "UTR <SWIFT ref>".
    const [, orderId] = await paidAbroad();
    const o = await orderRepository.find(orderId);
    const c = await zohoBooksService.associateCommission({ order_id: orderId, buyer_inquiry_ref: o.buyer_inquiry_ref, buyer_name: o.company_legal_name });
    expect(c.payment_description).toContain('gross ₹104,312.50, no TDS, net paid ₹104,312.50 · SWIFT ref FT2612050001 · against bill ');
    expect(c.payment_description).not.toContain('UTR');
  });

  it('is skipped with a clear note while the Zoho account is not chosen', async () => {
    await fakeZoho();
    const [p] = await paidAbroad();
    expect(await caSyncService.syncPayoutCosts('manual', adminId)).toEqual({ journals: 0, failed: 0, skipped: 1 });
    expect(calls).toEqual([]); // nothing sent to Zoho
    const row = await db.queryOne("SELECT message FROM zoho_sync_log WHERE sync_type = 'associate_payout_cost' ORDER BY id DESC LIMIT 1");
    expect(row.message).toBe(`${caSyncService.PAYOUT_COSTS_UNMAPPED} (1 waiting.)`);
    expect((await incentiveRepository.findPayout(Number(p.id))).zoho_cost_journal_id).toBeNull();
  });

  it('is sent once, then updated, never twice', async () => {
    await fakeZoho();
    const [p, orderId] = await paidAbroad();
    await incentiveRepository.setSetting('zoho_payout_costs_account_id', 'AC-APC', adminId);

    expect(await caSyncService.syncPayoutCosts('manual', adminId)).toEqual({ journals: 1, failed: 0, skipped: 0 });
    expect((await incentiveRepository.findPayout(Number(p.id))).zoho_cost_journal_id).toBe('JN-1');
    expect(journals['JN-1']).toEqual(await zohoBooksService.payoutCostJournalPayload(p, 'AC-APC', 'SBI-1', null, true)); // the exact journal (rule D-212 notes)
    expect(calls).toEqual(['GET /books/v3/journals', 'POST /books/v3/journals']); // looked for an earlier one first, then posted once

    expect(await caSyncService.syncPayoutCosts('manual', adminId)).toEqual({ journals: 0, failed: 0, skipped: 1 }); // sent once

    // Finance corrects the SWIFT reference: the same journal is updated, never a second one.
    await db.execute("UPDATE incentive_payouts SET paid_reference = 'FT2612050009' WHERE id = :id", { id: p.id });
    expect(await caSyncService.syncPayoutCosts('manual', adminId)).toEqual({ journals: 1, failed: 0, skipped: 0 });
    expect(calls[calls.length - 1]).toBe('PUT /books/v3/journals/JN-1');
    expect(Object.keys(journals)).toHaveLength(1);
    expect(journals['JN-1'].notes).toContain('SWIFT ref FT2612050009');
    const log = await db.queryOne("SELECT message FROM zoho_sync_log WHERE sync_type = 'associate_payout_cost' AND status = 'success' ORDER BY id DESC LIMIT 1");
    expect(log.message.startsWith(`Updated as Zoho Books journal: Associate commission payout — ${(await orderRepository.find(orderId)).order_reference}`)).toBe(true);
  });

  it('sends nothing for a payout without a difference', async () => {
    await fakeZoho();
    const [p] = await paidAbroad();
    await db.execute('UPDATE incentive_payouts SET payout_difference_inr = 0 WHERE id = :id', { id: p.id });
    await incentiveRepository.setSetting('zoho_payout_costs_account_id', 'AC-APC', adminId);
    expect(await caSyncService.syncPayoutCosts('manual', adminId)).toEqual({ journals: 0, failed: 0, skipped: 1 });
    expect(journals).toEqual({});
  });
});
