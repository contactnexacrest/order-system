'use strict';

const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const caExportBenefitRepository = require('../../src/repositories/caExportBenefitRepository');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const orderCostEntryRepository = require('../../src/repositories/orderCostEntryRepository');
const orderLcDetailRepository = require('../../src/repositories/orderLcDetailRepository');
const orderFinancialsController = require('../../src/controllers/orderFinancialsController');
const caSyncService = require('../../src/services/caSyncService');
const orderZohoMatchService = require('../../src/services/orderZohoMatchService');
const zohoBooksService = require('../../src/services/zohoBooksService');
const { createTestClient, createTestOrder, createTestDocument } = require('../support/fixtures');

/**
 * Port of ZohoBooksStage4Test.php. Owner decisions 2026-10-29: the order's
 * real paid cost entries go to Zoho Books as expenses (updated when
 * corrected, removed when removed), the money the bank pays under an LC as
 * a customer payment applied to the CI with its bank charges, received
 * export benefits as journals (Duty Drawback into the bank, RoDTEP /
 * RoSCTL as e-scrips), and the per-order "App vs Zoho" check. Every
 * request goes to a fake Zoho.
 */
describe('Zoho Books: order costs, LC receipt, export benefits and the App vs Zoho check', () => {
  let requests;
  let contacts;
  let invoices;
  let payments;
  let expenses;
  let journals;
  let userId;
  const accounts = [
    { account_id: 'AC-INS', account_name: 'Insurance Expense' },
    { account_id: 'AC-COMM', account_name: 'Commission Expense' },
    { account_id: 'AC-OTHER', account_name: 'Other Expenses' },
    { account_id: 'AC-INSP', account_name: 'Inspection Charges' },
    { account_id: 'AC-CARD', account_name: 'Company Card' },
    { account_id: 'AC-INCOME', account_name: 'Export Incentive Income' },
    { account_id: 'AC-SCRIP', account_name: 'Export Incentive e-Scrips' },
  ];

  function invoiceBalance(invoiceId) {
    let paid = 0;
    for (const payment of Object.values(payments)) {
      for (const applied of payment.invoices || []) paid += applied.invoice_id === invoiceId ? applied.amount_applied : 0;
    }
    return Math.round((invoices[invoiceId].total - paid) * 100) / 100;
  }

  function fakeZoho(method, url, payload) {
    requests.push({ method, url, payload });
    const u = new URL(url);
    const p = u.pathname;
    const q = (k) => u.searchParams.get(k);
    const json = (body, status = 200) => ({ status, body: JSON.stringify(body) });
    let m;

    if (p === '/oauth/v2/token') return json({ access_token: 'fake-access' });
    if ((m = p.match(/^\/books\/v3\/organizations\/(\d+)$/))) return json({ code: 0, organization: { organization_id: m[1], is_registered_for_gst: false } });
    if (p === '/books/v3/settings/currencies') return json({ code: 0, currencies: [{ currency_id: 'C-USD', currency_code: 'USD' }, { currency_id: 'C-INR', currency_code: 'INR' }] });
    if (p === '/books/v3/chartofaccounts') return json({ code: 0, chartofaccounts: accounts });
    if (p === '/books/v3/contacts' && method === 'GET') return json({ code: 0, contacts: Object.values(contacts).filter((c) => c.contact_name === q('contact_name')) });
    if (p === '/books/v3/contacts' && method === 'POST') {
      const id = `CT-${Object.keys(contacts).length + 1}`;
      contacts[id] = { contact_id: id, contact_name: payload.contact_name, currency_code: 'USD' };
      return json({ code: 0, contact: contacts[id] }, 201);
    }
    if ((m = p.match(/^\/books\/v3\/contacts\/(.+)$/))) return json({ code: 0, contact: contacts[m[1]] });
    if (p === '/books/v3/invoices' && method === 'GET') {
      const rows = Object.values(invoices).filter((i) => (q('invoice_number') === null || i.invoice_number === q('invoice_number')) && (q('customer_id') === null || i.customer_id === q('customer_id')));
      return json({ code: 0, invoices: rows.map((i) => ({ ...i, balance: invoiceBalance(i.invoice_id) })) });
    }
    if (p === '/books/v3/invoices' && method === 'POST') {
      const id = `INV-${Object.keys(invoices).length + 1}`;
      const total = payload.line_items.reduce((sum, line) => sum + Math.round(line.quantity * line.rate * 100) / 100, 0);
      invoices[id] = { invoice_id: id, invoice_number: payload.invoice_number, customer_id: payload.customer_id, reference_number: payload.reference_number, status: 'draft', total, currency_code: 'USD' };
      return json({ code: 0, invoice: invoices[id] }, 201);
    }
    if ((m = p.match(/^\/books\/v3\/invoices\/([^/]+)\/status\/sent$/))) {
      invoices[m[1]].status = 'sent';
      return json({ code: 0 });
    }
    if (p === '/books/v3/customerpayments' && method === 'GET') {
      return json({ code: 0, customerpayments: Object.entries(payments).map(([id, pay]) => ({ payment_id: id, amount: pay.amount, reference_number: pay.reference_number, customer_id: pay.customer_id })) });
    }
    if (p === '/books/v3/customerpayments' && method === 'POST') {
      const id = `PAY-${Object.keys(payments).length + 1}`;
      payments[id] = payload;
      return json({ code: 0, payment: { payment_id: id } }, 201);
    }
    if ((m = p.match(/^\/books\/v3\/customerpayments\/(.+)$/)) && method === 'PUT') {
      payments[m[1]] = payload;
      return json({ code: 0, payment: { payment_id: m[1] } });
    }
    for (const [plural, store, one, prefix, amountKey] of [['expenses', expenses, 'expense', 'EX-', 'amount'], ['journals', journals, 'journal', 'JN-', null]]) {
      if (p === `/books/v3/${plural}` && method === 'POST') {
        const id = `${prefix}${Object.keys(store).length + 1}`;
        store[id] = { ...payload, [`${one}_id`]: id };
        return json({ code: 0, [one]: { [`${one}_id`]: id } }, 201);
      }
      if ((m = p.match(new RegExp(`^/books/v3/${plural}/(.+)$`)))) {
        if (!store[m[1]]) return json({ code: 1002, message: 'Record does not exist.' }, 404);
        if (method === 'DELETE') {
          delete store[m[1]];
          return json({ code: 0 });
        }
        if (method === 'PUT') store[m[1]] = { ...payload, [`${one}_id`]: m[1] };
        const record = { ...store[m[1]] };
        record.total = amountKey !== null ? record[amountKey] : record.line_items.reduce((sum, l) => sum + (l.debit_or_credit === 'debit' ? Number(l.amount) : 0), 0);
        return json({ code: 0, [one]: record });
      }
    }
    return json({ code: 5, message: `fake Zoho has no ${method} ${p}` }, 400);
  }

  const requestsTo = (method, prefix) => requests.filter((r) => r.method === method && new URL(r.url).pathname.startsWith(prefix));
  const messages = async (orderId, type) => (await db.query('SELECT message FROM zoho_sync_log WHERE entity_id = :id AND sync_type = :t ORDER BY id', { id: orderId, t: type })).map((r) => r.message);
  const lastMessage = async (orderId, type) => (await messages(orderId, type)).pop() || '';
  const fakeReq = (params, body) => ({ params, body, query: {}, session: {}, user: { id: userId, name: 'Jest Admin' }, permissions: { ca_fy_lock_override: true } });
  const fakeRes = () => ({ redirect() {}, status() { return this; }, send() {} });

  // A TEST- CIF order in USD paid by LC: two products (FOB 1,275.00), customs rate 84.40.
  async function createOrder() {
    const clientId = await createTestClient();
    await db.execute('UPDATE clients SET company_legal_name = :name WHERE id = :id', { name: 'TEST-Stage4 Buyer', id: clientId });
    const orderId = await createTestOrder(clientId, 'CIF');
    await db.execute("UPDATE orders SET buyer_inquiry_ref = :ref, payment_method = 'LC' WHERE id = :id", { ref: `NX-${orderId}`, id: orderId });
    const insert = 'INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value, hs_code) VALUES (:o, :l, :d, :q, :u, :p, :v, :h)';
    await db.execute(insert, { o: orderId, l: 1, d: 'Granite slab', q: 100, u: 'SQM', p: 10, v: 1000, h: '68022310' });
    await db.execute(insert, { o: orderId, l: 2, d: 'Sandstone tile', q: 50, u: 'SQM', p: 5.5, v: 275, h: '68029900' });
    await db.execute("INSERT INTO order_shipping (order_id, shipping_bill_no, shipping_bill_date, customs_exchange_rate) VALUES (:id, 'TEST-SB-4', '2026-10-27', 84.4)", { id: orderId });
    await db.execute("INSERT INTO order_lc_details (order_id, lc_number, lc_amount, lc_confirmed_at) VALUES (:id, 'TEST-LC-4', 1275, '2026-10-10 10:00:00')", { id: orderId });
    return orderId;
  }

  async function approveCi(orderId) {
    const id = await createTestDocument(orderId, 'CI', 'approved');
    await db.execute("UPDATE documents SET document_reference = :ref, document_date = '2026-10-28' WHERE id = :id", { ref: `TEST-SC/CI/${orderId}`, id });
  }

  beforeEach(async () => {
    requests = [];
    contacts = {};
    invoices = {};
    payments = {};
    expenses = {};
    journals = {};
    process.env.ZOHO_INTEGRATION_ENABLED = 'true';
    process.env.ZOHO_ENVIRONMENT = 'test';
    process.env.ZOHO_EINVOICE_ENABLED = 'false';
    for (const [key, value] of Object.entries({
      zoho_books_enabled: '1',
      zoho_books_client_id: 'fake-client',
      zoho_books_client_secret: 'fake-secret',
      zoho_books_refresh_token: 'fake-refresh',
      zoho_books_organization_id: '600',
      zoho_books_deposit_account_id: 'SBI-1',
    })) {
      await companySettingsRepository.set(key, value);
    }
    zohoBooksService.setTransport(fakeZoho);
    // The test database is shared by the whole run: earlier tests' TEST- orders must not reach this test's fake Zoho.
    await db.execute("UPDATE orders SET company_legal_name = CONCAT('OLD-', company_legal_name) WHERE company_legal_name LIKE 'TEST-%'");
    userId = (await db.queryOne('SELECT id FROM users ORDER BY id LIMIT 1')).id;
  });

  afterEach(async () => {
    zohoBooksService.setTransport(null);
    delete process.env.ZOHO_INTEGRATION_ENABLED;
    delete process.env.ZOHO_ENVIRONMENT;
    delete process.env.ZOHO_EINVOICE_ENABLED;
    await companySettingsRepository.set('zoho_books_enabled', '0');
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('sends real paid costs to Books as expenses and keeps the rest in the app', async () => {
    const orderId = await createOrder();
    const ecgc = await orderCostEntryRepository.create(orderId, 'ecgc_insurance', 'ECGC cover', 4200, '2026-10-20', userId, 'company_account');
    await orderCostEntryRepository.create(orderId, 'commission', null, 9000, '2026-10-21', userId, 'company_card');
    await orderCostEntryRepository.create(orderId, 'cha_charges', null, 6000, '2026-10-21', userId, 'company_account');
    await orderCostEntryRepository.create(orderId, 'bank_charges', null, 350, '2026-10-21', userId, 'company_account');
    await orderCostEntryRepository.create(orderId, 'travel_expenses', 'Travel expenses (factory visits)', 5000, '2026-10-21', userId);

    expect((await caSyncService.syncPurchases('manual', null)).expenses).toBe(2);

    expect(expenses['EX-1']).toEqual({
      account_id: 'AC-INS', paid_through_account_id: 'SBI-1', amount: 4200, date: '2026-10-20', reference_number: `NX-${orderId}-COST-${ecgc}`,
      description: `TEST-Stage4 Buyer / NX-${orderId}: ECGC Insurance — ECGC cover`, expense_id: 'EX-1',
    });
    expect([expenses['EX-2'].account_id, expenses['EX-2'].paid_through_account_id, expenses['EX-2'].amount]).toEqual(['AC-COMM', 'AC-CARD', 9000]);
    expect((await caSyncService.syncPurchases('manual', null)).expenses).toBe(0); // nothing is sent twice
  });

  it('updates a corrected cost in Books and removes a removed one there', async () => {
    const orderId = await createOrder();
    const id = await orderCostEntryRepository.create(orderId, 'inspection_fee', 'Pre-shipment inspection fee', 7500, '2026-10-22', userId, 'company_account');
    await caSyncService.syncPurchases('manual', null);
    await orderCostEntryRepository.updateAmount(id, 8100, 'Pre-shipment inspection fee');

    expect((await caSyncService.syncPurchases('manual', null)).expenses).toBe(1);
    expect(requestsTo('PUT', '/books/v3/expenses/EX-1')).toHaveLength(1);
    expect(expenses['EX-1'].amount).toBe(8100);
    expect(expenses['EX-1'].account_id).toBe('AC-INSP');

    await orderFinancialsController.deleteCostEntry(fakeReq({ id: String(orderId), entryId: String(id) }, {}), fakeRes());
    expect(expenses).toEqual({}); // removed from Books too
    expect(await orderCostEntryRepository.find(id)).toBeNull();
    expect(await lastMessage(orderId, 'order_cost')).toBe('Removed order cost "Pre-Shipment Inspection Fee" (INR 8100.00) from Zoho Books: it was removed in the app.');
  });

  it('refuses a cost that does not say what it was paid from', async () => {
    const orderId = await createOrder();
    await orderCostEntryRepository.create(orderId, 'documentation', 'COO', 1500, '2026-10-22', userId);

    expect((await caSyncService.syncPurchases('manual', null)).failed).toBe(1);
    expect(await messages(orderId, 'order_cost')).toEqual(['Order cost "Documentation" (INR 1500.00) not sent: it does not say what it was paid from. Remove it and record it again with "Paid from".']);
  });

  it('holds the LC payment, then applies it to the CI with the bank charges', async () => {
    const orderId = await createOrder();
    await orderLcDetailRepository.recordReceived(orderId, '2026-11-05', 1275, 107100, 750, userId);

    const first = await caSyncService.syncPendingRevenue('manual', null);
    expect([first.synced, first.applied]).toEqual([1, 0]); // no CI in Books yet: held
    const payment = payments['PAY-1'];
    expect(payment.amount).toBe(1275);
    expect(payment.exchange_rate).toBe(84.588235); // (credited 107,100 + charges 750) / 1,275
    expect(payment.bank_charges).toBe(8.87); // the charges in USD
    expect(payment.reference_number).toBe(`NX-${orderId}-lc`);

    await approveCi(orderId);
    const second = await caSyncService.syncPendingRevenue('manual', null);
    expect([second.invoices, second.applied]).toEqual([1, 1]);
    expect(payments['PAY-1'].invoices).toEqual([{ invoice_id: 'INV-1', amount_applied: 1275 }]);
    expect(payments['PAY-1'].bank_charges).toBe(8.87); // re-sent whole, with the charges
    expect((await orderLcDetailRepository.find(orderId)).lc_zoho_applied_at).not.toBeNull();
  });

  it('records the LC payment only on an LC order once usable, and not once it is in Books', async () => {
    const orderId = await createOrder();
    const post = { lc_received_at: '2026-10-27', lc_received_amount: '1275', lc_inr_credited: '107100', lc_bank_charges_inr: '750' };
    const run = async (body) => {
      const req = fakeReq({ id: String(orderId) }, body);
      await orderFinancialsController.recordLcReceived(req, fakeRes());
      const all = flash.pull(req);
      return all.length ? all[all.length - 1] : {};
    };

    expect((await run({ ...post, lc_inr_credited: '' })).message).toBe('Enter the rupees credited to the bank account.');
    expect((await run({ ...post, lc_received_at: '2099-01-01' })).message).toBe('Enter the date the bank paid (not in the future).');
    expect(await run(post)).toEqual({ type: 'success', message: 'LC payment recorded: USD 1275, ₹107100 credited.' });
    expect(String((await orderLcDetailRepository.find(orderId)).lc_bank_charges_inr)).toBe('750.00');

    await caSyncService.syncPendingRevenue('manual', null);
    expect((await run(post)).message).toBe('This LC payment is already in Zoho Books, so it cannot be changed here. Ask the CA to correct it there.');

    await db.execute('UPDATE order_lc_details SET lc_confirmed_at = NULL WHERE order_id = :id', { id: orderId });
    expect((await run(post)).message).toBe('Record the LC and confirm it usable (Step 5) first.');
  });

  it('sends received export benefits as journals and follows corrections', async () => {
    const orderId = await createOrder();
    const drawback = await caExportBenefitRepository.record(orderId, 'Duty Drawback', 'SB-1', 18000, '2026-10-28', 'INR', null, userId);
    const rodtep = await caExportBenefitRepository.record(orderId, 'RODTEP', 'SB-1', 9000, '2026-10-28', 'INR', null, userId);
    const other = await caExportBenefitRepository.record(orderId, 'Other', null, 500, '2026-10-28', 'INR', null, userId);
    await caExportBenefitRepository.record(orderId, 'RoSCTL', null, 700, '2026-10-28', 'INR', null, userId); // claimed, not received: not booked
    await caExportBenefitRepository.markReceived(drawback, 17850, '2026-11-20');
    await caExportBenefitRepository.markReceived(rodtep, 9000, '2026-11-25');
    await caExportBenefitRepository.markReceived(other, 500, '2026-11-25');

    const result = await caSyncService.syncBenefits('manual', null);

    expect(result).toEqual({ journals: 2, removed: 0, failed: 1, skipped: 0 });
    expect(journals['JN-1'].line_items).toEqual([
      { account_id: 'SBI-1', debit_or_credit: 'debit', amount: 17850, description: 'Duty Drawback paid into the bank' },
      { account_id: 'AC-INCOME', debit_or_credit: 'credit', amount: 17850, description: 'Duty Drawback received' },
    ]);
    expect([journals['JN-1'].journal_date, journals['JN-1'].reference_number, journals['JN-1'].notes]).toEqual(['2026-11-20', `NX-${orderId}-BEN-${drawback}`, `TEST-Stage4 Buyer / NX-${orderId}: Duty Drawback received (claim SB-1)`]);
    expect(journals['JN-2'].line_items[0].account_id).toBe('AC-SCRIP'); // RoDTEP: credited as e-scrips
    expect(await lastMessage(orderId, 'export_benefit')).toContain('only Duty Drawback, RoDTEP and RoSCTL go to Zoho Books automatically');

    await caExportBenefitRepository.correct(drawback, 'SB-1', 18000, '2026-10-28', 17900, '2026-11-20');
    await caExportBenefitRepository.correct(rodtep, 'SB-1', 9000, '2026-10-28', null, null);
    const again = await caSyncService.syncBenefits('manual', null);
    expect([again.journals, again.removed]).toEqual([1, 1]);
    expect(journals['JN-1'].line_items[0].amount).toBe(17900);
    expect(journals).not.toHaveProperty('JN-2'); // a cleared receipt is removed from Books
    expect((await caExportBenefitRepository.find(rodtep)).zoho_journal_id).toBeNull();
  });

  it('does not delete a claim whose receipt is in Books straight away', async () => {
    const orderId = await createOrder();
    const id = await caExportBenefitRepository.record(orderId, 'Duty Drawback', null, 1000, '2026-10-28', 'INR', null, userId);
    await caExportBenefitRepository.markReceived(id, 1000, '2026-11-20');
    await caSyncService.syncBenefits('manual', null);

    const req = fakeReq({ id: String(orderId), benefitId: String(id) }, { reason: 'Recorded against the wrong order by mistake' });
    await orderFinancialsController.deleteExportBenefit(req, fakeRes());

    expect(await caExportBenefitRepository.find(id)).not.toBeNull(); // still there
    expect((flash.pull(req)[0] || {}).message || '').toMatch(/^This Duty Drawback receipt is in Zoho Books\./);
  });

  it('the App vs Zoho check shows what matches and what does not', async () => {
    const orderId = await createOrder();
    await approveCi(orderId);
    await orderLcDetailRepository.recordReceived(orderId, '2026-11-05', 1275, 107100, 750, userId);
    const cost = await orderCostEntryRepository.create(orderId, 'ecgc_insurance', null, 4200, '2026-10-20', userId, 'company_account');
    await orderCostEntryRepository.create(orderId, 'commission', null, 900, '2026-10-20', userId, 'company_account');
    const benefit = await caExportBenefitRepository.record(orderId, 'Duty Drawback', null, 1000, '2026-10-28', 'INR', null, userId);
    await caExportBenefitRepository.markReceived(benefit, 1000, '2026-11-20');
    await caSyncService.runFullSync('manual', null);

    const clean = await orderZohoMatchService.check(orderId);
    expect(clean.counts).toEqual({ match: 5, not_sent: 0, before_go_live: 0, different: 0, missing: 0, books_only: 0 });

    // Someone changes Books directly, and the app gets a cost not sent yet.
    expenses['EX-1'].amount = 4000;
    delete journals['JN-1'];
    invoices['INV-X'] = { invoice_id: 'INV-X', invoice_number: 'HAND-1', customer_id: 'CT-1', reference_number: `NX-${orderId}`, status: 'sent', total: 50, currency_code: 'USD' };
    await orderCostEntryRepository.create(orderId, 'other', 'Courier', 300, '2026-10-29', userId);

    const before = requests.length;
    const rows = Object.fromEntries((await orderZohoMatchService.check(orderId)).rows.map((r) => [r.item, r.status]));
    expect(rows['Cost: ECGC Insurance']).toBe('different');
    expect(rows['Duty Drawback received']).toBe('missing');
    expect(rows['Invoice HAND-1']).toBe('books_only');
    expect(rows['Cost: Other — Courier']).toBe('not_sent');
    expect(rows[`Commercial Invoice TEST-SC/CI/${orderId}`]).toBe('match');
    expect(rows['LC payment']).toBe('match');
    expect([...new Set(requests.slice(before).map((r) => r.method))]).toEqual(['GET']); // the check only reads
    expect(Number((await orderCostEntryRepository.find(cost)).amount_inr)).toBe(4200);
  });
});
