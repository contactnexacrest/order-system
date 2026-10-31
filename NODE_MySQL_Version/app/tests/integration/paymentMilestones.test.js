'use strict';

// Capture the rendered HTML instead of launching a browser.
const renderedHtml = [];
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn(async (html) => {
    renderedHtml.push(html);
    return Buffer.from('%PDF-fake');
  }),
  closeBrowser: jest.fn(),
}));

const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const { todayIst } = require('../../src/helpers/dbDateTime');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const paymentMilestoneRepository = require('../../src/repositories/paymentMilestoneRepository');
const ordersController = require('../../src/controllers/ordersController');
const paymentMilestoneController = require('../../src/controllers/paymentMilestoneController');
const caSyncService = require('../../src/services/caSyncService');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const documentGenerationService = require('../../src/services/documentGenerationService');
const moneyVisibility = require('../../src/services/moneyVisibility');
const stageGateService = require('../../src/services/stageGateService');
const zohoBooksService = require('../../src/services/zohoBooksService');
const { createTestClient, createTestOrder, createTestDocument } = require('../support/fixtures');

/**
 * Port of PaymentMilestoneTest.php. Owner decision D-215 (5a, confirmed
 * twice): an order can have its own payment schedule — the advance first,
 * the balance last, and milestones in between. The PI prints one bullet and
 * the OC one row per milestone; each milestone is its own payment
 * (remittance with bank reference and a description built from the facts,
 * cleared, pushed to Zoho Books); the balance is cleared only after every
 * milestone. An order without milestones works exactly as before.
 */
describe('Payment milestones (D-215, 5a)', () => {
  const DUE = 'on shipment — within 3 Calendar Days of receiving the scanned Bill of Lading';
  let orderId;
  let userId;

  afterAll(async () => {
    await db.pool.end();
  });

  beforeEach(async () => {
    renderedHtml.length = 0;
    orderId = await createTestOrder(await createTestClient(), 'FOB', 'Extended Terms — Before Shipment');
    await orderProductRepository.add(orderId, 1, 'Black granite headstone', '60x30', 'Polished', '100', false, 'PCS', '45.00');
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    userId = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-ms-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    )).insertId;
  });

  async function call(method, body, params = {}) {
    const req = { params: { id: String(orderId), ...params }, body, user: { id: userId }, session: {}, permissions: { [moneyVisibility.PERMISSIONS[moneyVisibility.SELLING]]: true } };
    const res = { redirect: () => {}, status: () => ({ send: () => {} }) };
    await paymentMilestoneController[method](req, res);
    const all = flash.pull(req);
    return all.length ? all[all.length - 1].message : '';
  }

  async function schedule() {
    expect(await call('saveSchedule', { milestone_pct: ['50', ''], milestone_due: [`${DUE}.`, ''] })).toMatch(/^Payment schedule saved:/);
  }

  it('checks the schedule and sets the balance', async () => {
    expect(await call('saveSchedule', { milestone_pct: ['70'], milestone_due: [DUE] }))
      .toBe('Advance 30% + milestones 70% leave nothing for the balance — together they must stay below 100%.');
    expect(await call('saveSchedule', { milestone_pct: [''], milestone_due: [DUE] })).toBe('Milestone 1 %: enter its share of the FOB value.');
    expect(await call('saveSchedule', { milestone_pct: ['50'], milestone_due: ['soon'] })).toMatch(/^Milestone 1: say when it is due/);

    expect(await call('saveSchedule', { milestone_pct: ['50', ''], milestone_due: [`${DUE}.`, ''] }))
      .toBe(`Payment schedule saved: 30% advance · 50% ${DUE} · 20% balance. Regenerate the Proforma Invoice so the buyer sees it.`);
    expect(parseFloat((await orderRepository.find(orderId)).balance_pct)).toBe(20);
    expect((await paymentMilestoneRepository.forOrder(orderId)).map((m) => [Number(m.seq), m.pct, m.due_text])).toEqual([[2, '50.00', DUE]]);

    expect(await call('saveSchedule', { milestone_pct: [''], milestone_due: [''] }))
      .toBe('Payment schedule back to advance and balance: 30% advance · 70% balance. Regenerate the Proforma Invoice so the buyer sees it.');
    expect(parseFloat((await orderRepository.find(orderId)).balance_pct)).toBe(70);

    await schedule();
    await db.execute('UPDATE order_payment_status SET advance_cleared_at = NOW() WHERE order_id = :o', { o: orderId });
    expect(await call('saveSchedule', { milestone_pct: [''], milestone_due: [''] }))
      .toBe('The payment schedule is fixed once the advance is cleared — change it with a payment terms amendment.');
  });

  it('shows each milestone on the PI and OC', async () => {
    await schedule();
    const pi = await documentDataAssembler.assemble(orderId, 'PI');
    expect(pi.financial.advance_amount).toBe('1,350.00');
    expect(pi.financial.milestones.map((m) => [m.pct, m.due_text, m.amount])).toEqual([['50', DUE, '2,250.00']]);
    expect(pi.financial.balance_pct).toBe('20');
    expect(pi.financial.balance_amount).toBe('900.00');

    const result = await documentGenerationService.generate(orderId, 'PI', userId, null, true, true);
    const text = renderedHtml.join('\n').replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').replace(/&#8226;/g, '•').replace(/\s+/g, ' ');
    expect(text).toContain('30% Advance Payment on FOB Value against this document.');
    expect(text).toContain(`50% Payment on FOB Value ${DUE}.`);
    expect(text).toContain('50% Amount: USD 2,250.00');
    expect(text).toContain('20% Balance Payment on FOB Value');
    expect(text).toContain('20% Balance Amount: USD 900.00');
    expect(result.docx_file_id).toBeTruthy();

    const oc = fs.readFileSync(path.join(__dirname, '..', '..', 'templates', 'OC', 'order_confirmation.njk'), 'utf8');
    expect(oc).toContain('<tr><td class="k">{{ m.pct }}% Payment Due ({{ order.currency_code }}) *</td><td class="v">{{ m.amount }}');
  });

  // Owner confirmed twice (1 Nov 2026): the CI shows each milestone and asks only for what is left.
  it('counts the milestones paid on the Commercial Invoice', async () => {
    await schedule();
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 1350, '2026-10-20');
    const m = (await paymentMilestoneRepository.forOrder(orderId))[0];
    const paymentActionService = require('../../src/services/paymentActionService');
    let order = await orderRepository.find(orderId);
    expect(await paymentActionService.amountDue(order, 'balance')).toBe(3150);
    await paymentMilestoneRepository.recordReceived(Number(m.id), 2250, '2026-10-28', 84.1, 'UTR778899', null, userId);
    order = await orderRepository.find(orderId);
    expect(await paymentActionService.amountDue(order, 'balance')).toBe(900);
    expect(orderPaymentStatusRepository.balanceDue(4500, 30, await orderPaymentStatusRepository.find(orderId), await paymentMilestoneRepository.plannedTotal(orderId, 4500))).toBe(900);

    const ci = await documentDataAssembler.assemble(orderId, 'CI');
    expect(ci.payment_status.balance_amount).toBe('900.00');
    expect(ci.financial.subtotal_paid_with_milestones).toBe('3,600.00');

    const result = await documentGenerationService.generate(orderId, 'CI', userId, null, true, true);
    const text = renderedHtml.join('\n').replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').replace(/&#8730;/g, '√').replace(/\s+/g, ' ');
    expect(text).toContain('Milestone 2 (50% on shipment) — RECEIVED');
    expect(text).toContain('USD 2,250.00');
    expect(text).toContain('Received: 28 October 2026 · bank ref UTR778899');
    expect(text).toContain('USD 3,600.00');
    expect(text).toContain('Advance + milestones');
    expect(text).toContain('USD 900.00');
    expect(text).toContain('= FOB Value minus advance and milestones received');
    expect(text).toContain('1,350.00 + 2,250.00 + 900.00 = 4,500.00');
    expect(result.docx_file_id).toBeTruthy();
  });

  it('prints an order without milestones as before', async () => {
    const pi = await documentDataAssembler.assemble(orderId, 'PI');
    expect(pi.financial.milestones).toEqual([]);
    expect(pi.financial.balance_amount).toBe('3,150.00');
  });

  it('makes each milestone its own payment, cleared before the balance', async () => {
    await schedule();
    const m = (await paymentMilestoneRepository.forOrder(orderId))[0];
    const body = { amount: '2250', exchange_rate: '84.1', bank_reference: 'UTR778899', received_on: todayIst() };
    expect(await call('record', body, { milestoneId: String(m.id) })).toBe('Generate the Proforma Invoice first — the buyer pays each milestone against it.');

    const pi = await documentGenerationService.generate(orderId, 'PI', userId, null, false, true);
    const piRef = (await db.queryOne('SELECT document_reference FROM documents WHERE id = :id', { id: pi.document_id })).document_reference;
    expect(await call('record', { ...body, bank_reference: '' }, { milestoneId: String(m.id) }))
      .toBe("The bank reference is required (UTR / SWIFT / bank reference as on the bank's advice).");
    expect(await call('clear', {}, { milestoneId: String(m.id) })).toBe('Record the milestone 2 remittance before marking it cleared.');

    const order = await orderRepository.find(orderId);
    const expected = `Milestone 2 (50% ${DUE}) received from ${order.company_legal_name} for order ${order.buyer_inquiry_ref}`
      + ` · USD 2,250.00 at ₹84.10 · against Proforma Invoice ${piRef} · bank ref UTR778899`;
    expect(await call('record', body, { milestoneId: String(m.id) })).toBe(`Recorded: ${expected}. Mark it cleared once your bank confirms receipt.`);
    expect(await call('record', body, { milestoneId: String(m.id) })).toBe('Milestone 2 remittance is already recorded — nothing changed.');

    // The balance waits for every milestone.
    for (let stage = 1; stage <= 7; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, null);
    }
    await orderPaymentStatusRepository.recordBalanceReceived(orderId, 900.0, todayIst());
    await createTestDocument(orderId, 'CI', 'approved');
    const req = { params: { id: String(orderId) }, body: {}, user: { id: userId }, session: {} };
    await ordersController.clearBalancePayment(req, { redirect: () => {} });
    const msgs = flash.pull(req);
    expect(msgs[msgs.length - 1].message).toBe(`Clear payment milestone 2 (50% ${DUE}) before the balance.`);
    expect((await orderPaymentStatusRepository.find(orderId)).balance_cleared_at).toBeNull();

    expect(await call('clear', { cleared_on: todayIst() }, { milestoneId: String(m.id) })).toBe('Milestone 2 cleared. It goes to Zoho Books with the next revenue sync.');
    const req2 = { params: { id: String(orderId) }, body: {}, user: { id: userId }, session: {} };
    await ordersController.clearBalancePayment(req2, { redirect: () => {} });
    expect((await orderPaymentStatusRepository.find(orderId)).balance_cleared_at).not.toBeNull();
  });

  it('sends a cleared milestone to Zoho Books with its description', async () => {
    await schedule();
    await db.execute("UPDATE orders SET company_legal_name = CONCAT('OLD-', company_legal_name) WHERE company_legal_name LIKE 'TEST-%'");
    await db.execute("UPDATE orders SET company_legal_name = 'TEST-Milestone Buyer' WHERE id = :o", { o: orderId });
    const m = (await paymentMilestoneRepository.forOrder(orderId))[0];
    await paymentMilestoneRepository.recordReceived(Number(m.id), 2250, todayIst(), 84.1, 'UTR778899', null, userId);
    await paymentMilestoneRepository.markCleared(Number(m.id), todayIst(), userId);

    process.env.ZOHO_INTEGRATION_ENABLED = 'true';
    process.env.ZOHO_ENVIRONMENT = 'test';
    for (const [key, value] of Object.entries({
      zoho_books_enabled: '1', zoho_books_client_id: 'fake-client', zoho_books_client_secret: 'fake-secret',
      zoho_books_refresh_token: 'fake-refresh', zoho_books_organization_id: '600', zoho_books_deposit_account_id: 'SBI-1',
    })) {
      await companySettingsRepository.set(key, value);
    }
    const payments = [];
    zohoBooksService.setTransport((method, url, payload) => {
      const p = new URL(url).pathname;
      const json = (body, status = 200) => ({ status, body: JSON.stringify(body) });
      if (p === '/oauth/v2/token') return json({ access_token: 'fake-access' });
      if (p.startsWith('/books/v3/organizations/')) return json({ code: 0, organization: { organization_id: '600', is_registered_for_gst: false } });
      if (p === '/books/v3/contacts' && method === 'GET') return json({ code: 0, contacts: [] });
      if (p === '/books/v3/contacts' && method === 'POST') return json({ code: 0, contact: { contact_id: 'CT-1', contact_name: payload.contact_name, currency_code: 'USD' } }, 201);
      if (p.startsWith('/books/v3/contacts/')) return json({ code: 0, contact: { contact_id: 'CT-1', currency_code: 'USD' } });
      if (p === '/books/v3/settings/currencies') return json({ code: 0, currencies: [{ currency_id: 'C-USD', currency_code: 'USD' }] });
      if (p === '/books/v3/customerpayments' && method === 'POST') {
        payments.push(payload);
        return json({ code: 0, payment: { payment_id: `PAY-${payments.length}` } }, 201);
      }
      return json({ code: 0, customerpayments: [], invoices: [], contacts: [] });
    });
    try {
      await caSyncService.syncPendingRevenue('manual', userId);
    } finally {
      zohoBooksService.setTransport(null);
      delete process.env.ZOHO_INTEGRATION_ENABLED;
      delete process.env.ZOHO_ENVIRONMENT;
      await companySettingsRepository.set('zoho_books_enabled', '0');
    }

    const mine = payments.filter((p) => String(p.reference_number || '').endsWith('-m2'));
    expect(mine).toHaveLength(1);
    expect(Number(mine[0].amount)).toBe(2250);
    const order = await orderRepository.find(orderId);
    expect(mine[0].description).toBe(`Milestone 2 (50% ${DUE}) received from TEST-Milestone Buyer for order ${order.buyer_inquiry_ref} · USD 2,250.00 at ₹84.10 · bank ref UTR778899`);
    expect((await paymentMilestoneRepository.find(Number(m.id))).zoho_reference).toBe('PAY-1');
  });
});
