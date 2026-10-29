'use strict';

const path = require('path');
const nunjucks = require('nunjucks');
const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const caExpenseRepository = require('../../src/repositories/caExpenseRepository');
const caExportBenefitRepository = require('../../src/repositories/caExportBenefitRepository');
const caFyLockRepository = require('../../src/repositories/caFyLockRepository');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const orderCostEntryRepository = require('../../src/repositories/orderCostEntryRepository');
const orderExpenseRepository = require('../../src/repositories/orderExpenseRepository');
const orderLcDetailRepository = require('../../src/repositories/orderLcDetailRepository');
const orderVendorBillRepository = require('../../src/repositories/orderVendorBillRepository');
const orderExpenseController = require('../../src/controllers/orderExpenseController');
const caSyncService = require('../../src/services/caSyncService');
const zohoBooksService = require('../../src/services/zohoBooksService');
const { createTestClient, createTestOrder, createTestDocument } = require('../support/fixtures');

/**
 * Port of ZohoBooksStage5Test.php. Owner decisions 2026-10-29 — who owns
 * what between the app and Zoho Books. The app owns what it sends: deleted
 * in Books, it is sent again; changed in Books, it is put back — except in a
 * financial year the CA has locked, where the CA is told instead. Books owns
 * the expenses the CA enters there: the import follows their changes and
 * deletions, links one to its order by the Reference #, and flags a
 * possible duplicate of a cost entered in the app. Removing an order expense
 * in the app removes it from Books. Every request goes to a fake Zoho.
 */
describe('Zoho Books: the app and Books each own their records', () => {
  let requests;
  let store;
  let userId;
  const accounts = [
    { account_id: 'AC-TRANS', account_name: 'Transportation Expense' },
    { account_id: 'AC-OTHER', account_name: 'Other Expenses' },
    { account_id: 'AC-TRAVEL', account_name: 'Travel Expense' },
    { account_id: 'AC-POST', account_name: 'Postage' },
    { account_id: 'AC-INS', account_name: 'Insurance Expense' },
    { account_id: 'AC-INCOME', account_name: 'Export Incentive Income' },
    { account_id: 'AC-SCRIP', account_name: 'Export Incentive e-Scrips' },
  ];
  // plural => [store key, singular key in a response, id prefix, list filter]
  const STORES = {
    customerpayments: ['payments', 'payment', 'PAY-', 'customer_id'],
    vendorpayments: ['vendorPayments', 'vendorpayment', 'VP-', 'vendor_id'],
    bills: ['bills', 'bill', 'BL-', 'vendor_id'],
    expenses: ['expenses', 'expense', 'EX-', null],
    journals: ['journals', 'journal', 'JN-', null],
  };

  function paidOnBill(billId) {
    let paid = 0;
    for (const p of Object.values(store.vendorPayments)) {
      for (const applied of p.bills || []) paid += applied.bill_id === billId ? applied.amount_applied : 0;
    }
    return paid;
  }

  function withTotals(plural, r) {
    if (plural === 'bills') return { ...r, total: Number(r.line_items[0].rate), balance: Number(r.line_items[0].rate) - paidOnBill(String(r.bill_id)) };
    if (plural === 'expenses') return { ...r, total: Number(r.amount) };
    if (plural === 'journals') return { ...r, total: r.line_items.reduce((sum, l) => sum + (l.debit_or_credit === 'debit' ? Number(l.amount) : 0), 0) };
    return r;
  }

  function fakeZoho(method, url, payload) {
    requests.push({ method, url, payload });
    const u = new URL(url);
    const p = u.pathname;
    const q = (k) => u.searchParams.get(k);
    const json = (body, status = 200) => ({ status, body: JSON.stringify(body) });
    const gone = json({ code: 1002, message: 'Record does not exist.' }, 404);
    let m;

    if (p === '/oauth/v2/token') return json({ access_token: 'fake-access' });
    if ((m = p.match(/^\/books\/v3\/organizations\/(\d+)$/))) return json({ code: 0, organization: { organization_id: m[1], is_registered_for_gst: false } });
    if (p === '/books/v3/settings/currencies') return json({ code: 0, currencies: [{ currency_id: 'C-USD', currency_code: 'USD' }, { currency_id: 'C-INR', currency_code: 'INR' }] });
    if (p === '/books/v3/chartofaccounts') return json({ code: 0, chartofaccounts: accounts });
    if (p === '/books/v3/contacts' && method === 'GET') return json({ code: 0, contacts: Object.values(store.contacts).filter((c) => c.contact_name === q('contact_name')) });
    if (p === '/books/v3/contacts' && method === 'POST') {
      const id = `CT-${Object.keys(store.contacts).length + 1}`;
      store.contacts[id] = { contact_id: id, contact_name: payload.contact_name, currency_code: 'USD' };
      return json({ code: 0, contact: store.contacts[id] }, 201);
    }
    if ((m = p.match(/^\/books\/v3\/contacts\/(.+)$/))) return json({ code: 0, contact: store.contacts[m[1]] });
    if (p === '/books/v3/invoices' && method === 'GET') {
      const rows = Object.values(store.invoices).filter((i) => (q('invoice_number') === null || i.invoice_number === q('invoice_number')) && (q('customer_id') === null || i.customer_id === q('customer_id')));
      return json({ code: 0, invoices: rows });
    }
    if (p === '/books/v3/invoices' && method === 'POST') {
      const id = `INV-${Object.keys(store.invoices).length + 1}`;
      const total = payload.line_items.reduce((sum, line) => sum + Math.round(line.quantity * line.rate * 100) / 100, 0);
      store.invoices[id] = { invoice_id: id, invoice_number: payload.invoice_number, customer_id: payload.customer_id, reference_number: payload.reference_number, status: 'draft', total, balance: total };
      return json({ code: 0, invoice: store.invoices[id] }, 201);
    }
    if ((m = p.match(/^\/books\/v3\/invoices\/([^/]+)\/status\/sent$/))) {
      store.invoices[m[1]].status = 'sent';
      return json({ code: 0 });
    }
    for (const [plural, [key, one, prefix, filter]] of Object.entries(STORES)) {
      const idKey = plural.endsWith('payments') ? 'payment_id' : `${one}_id`;
      const responseKey = plural === 'customerpayments' ? 'payment' : one;
      if (p === `/books/v3/${plural}` && method === 'GET') {
        const rows = Object.values(store[key]).filter((r) => (filter === null || q(filter) === null || r[filter] === q(filter))
          && (q('bill_number') === null || r.bill_number === q('bill_number')));
        return json({ code: 0, [plural]: rows.map((r) => withTotals(plural, r)), page_context: { has_more_page: false } });
      }
      if (p === `/books/v3/${plural}` && method === 'POST') {
        const id = `${prefix}${Object.keys(store[key]).length + 1 + requests.filter((r) => r.method === 'DELETE').length}`;
        store[key][id] = { ...payload, [idKey]: id };
        return json({ code: 0, [responseKey]: withTotals(plural, store[key][id]) }, 201);
      }
      if ((m = p.match(new RegExp(`^/books/v3/${plural}/(.+)$`)))) {
        if (!store[key][m[1]]) return gone;
        if (method === 'DELETE') {
          delete store[key][m[1]];
          return json({ code: 0 });
        }
        if (method === 'PUT') store[key][m[1]] = { ...payload, [idKey]: m[1] };
        return json({ code: 0, [responseKey]: withTotals(plural, store[key][m[1]]) });
      }
    }
    return json({ code: 5, message: `fake Zoho has no ${method} ${p}` }, 400);
  }

  const requestsTo = (method, prefix) => requests.filter((r) => r.method === method && new URL(r.url).pathname.startsWith(prefix));
  const messages = async (type) => (await db.query('SELECT message FROM zoho_sync_log WHERE sync_type = :t ORDER BY id', { t: type })).map((r) => r.message);
  const first = (obj) => Object.keys(obj)[0];
  const idOf = (key, match) => {
    const found = Object.entries(store[key]).find(([, r]) => match(r));
    if (!found) throw new Error(`nothing in ${key} matches`);
    return found[0];
  };

  /**
   * A TEST- order in USD with: a CI in Books (paid by an LC payment), a
   * TEST- transporter's bill and its payment, an order expense, an ECGC cost
   * and a Duty Drawback received — all sent to the fake Zoho.
   */
  async function sentOrder() {
    const clientId = await createTestClient();
    await db.execute('UPDATE clients SET company_legal_name = :n WHERE id = :id', { n: 'TEST-Stage5 Buyer', id: clientId });
    const orderId = await createTestOrder(clientId, 'CIF');
    await db.execute("UPDATE orders SET buyer_inquiry_ref = :ref, payment_method = 'LC' WHERE id = :id", { ref: `NX5-${orderId}`, id: orderId });
    await db.execute("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value, hs_code) VALUES (:o, 1, 'Granite slab', 100, 'SQM', 12, 1200, '68022310')", { o: orderId });
    await db.execute("INSERT INTO order_shipping (order_id, shipping_bill_no, shipping_bill_date, customs_exchange_rate) VALUES (:id, 'TEST-SB-5', '2026-10-27', 83.5)", { id: orderId });
    await db.execute("INSERT INTO order_lc_details (order_id, lc_number, lc_amount, lc_confirmed_at) VALUES (:id, 'TEST-LC-5', 1200, '2026-10-10 10:00:00')", { id: orderId });
    const doc = await createTestDocument(orderId, 'CI', 'approved');
    await db.execute("UPDATE documents SET document_reference = :ref, document_date = '2026-10-28' WHERE id = :id", { ref: `TEST-CI5/${orderId}`, id: doc });
    await orderLcDetailRepository.recordReceived(orderId, '2026-10-28', 1200, 99500, 500, userId);
    const partner = (await db.execute("INSERT INTO logistics_partners (partner_name, service_type) VALUES ('TEST-Stage5 GTA', 'transportation')")).insertId;
    const bill = await orderVendorBillRepository.create(orderId, 'logistics', { logistics_partner_id: partner, bill_for: 'transport', doc_kind: 'invoice', bill_no: `TEST-GTA5-${orderId}`, bill_date: '2026-10-26', taxable_inr: 12000, gst_inr: 0 }, userId);
    await orderVendorBillRepository.addPayment(bill, null, '2026-10-27', 12000, 'IMPS', `TEST-UTR5-${orderId}`, userId);
    const expense = await orderExpenseRepository.create(orderId, { expense_date: '2026-10-22', category: 'courier', details: 'Documents to buyer', amount_inr: 1800, paid_by: 'company_account', no_receipt_reason: 'test' }, userId);
    const cost = await orderCostEntryRepository.create(orderId, 'ecgc_insurance', null, 4200, '2026-10-20', userId, 'company_account');
    const benefit = await caExportBenefitRepository.record(orderId, 'Duty Drawback', null, 1800, '2026-10-28', 'INR', null, userId);
    await caExportBenefitRepository.markReceived(benefit, 1750, '2026-10-29');
    await caSyncService.runFullSync('manual', null);
    return { order: orderId, bill, expense, cost, benefit };
  }

  beforeEach(async () => {
    requests = [];
    store = { contacts: {}, invoices: {}, payments: {}, bills: {}, vendorPayments: {}, expenses: {}, journals: {} };
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
    // The test database is shared by the whole run: earlier tests' TEST- records must not reach this test's fake Zoho.
    await db.execute("UPDATE orders SET company_legal_name = CONCAT('OLD-', company_legal_name) WHERE company_legal_name LIKE 'TEST-%'");
    await db.execute('UPDATE ca_bank_statement_lines SET matched_expense_id = NULL');
    await db.execute('DELETE FROM ca_expenses');
    await db.execute('UPDATE ca_fy_locks SET unlocked_at = NOW() WHERE unlocked_at IS NULL');
    userId = (await db.queryOne('SELECT id FROM users ORDER BY id LIMIT 1')).id;
  });

  afterEach(async () => {
    zohoBooksService.setTransport(null);
    delete process.env.ZOHO_INTEGRATION_ENABLED;
    delete process.env.ZOHO_ENVIRONMENT;
    delete process.env.ZOHO_EINVOICE_ENABLED;
    await companySettingsRepository.set('zoho_books_enabled', '0');
    await db.execute('UPDATE ca_fy_locks SET unlocked_at = NOW() WHERE unlocked_at IS NULL');
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('sends again what was deleted in Books', async () => {
    const o = await sentOrder();
    expect(Object.keys(store.payments)).toHaveLength(1);
    // Someone deletes, directly in Books: the LC payment, the bill and its payment, the expense, the cost and the journal.
    Object.assign(store, { payments: {}, vendorPayments: {}, bills: {}, expenses: {}, journals: {} });

    const result = await caSyncService.runFullSync('manual', null);

    expect([result.revenue.synced, result.revenue.failed]).toEqual([1, 0]);
    expect([result.purchases.bills, result.purchases.payments, result.purchases.expenses, result.purchases.failed]).toEqual([1, 1, 2, 0]);
    expect([result.benefits.journals, result.benefits.failed]).toEqual([1, 0]);
    expect(Object.keys(store.payments)).toHaveLength(1);
    expect(Object.values(store.payments)[0].invoices).toEqual([{ invoice_id: 'INV-1', amount_applied: 1200 }]); // applied to the CI again
    expect(Object.values(store.vendorPayments)[0].bills[0].amount_applied).toBe(12000); // the re-created payment is applied to the re-created bill
    expect(Object.keys(store.expenses)).toHaveLength(2);
    expect(Object.keys(store.journals)).toHaveLength(1);
    for (const type of ['revenue_payment', 'vendor_bill', 'vendor_payment', 'order_expense', 'order_cost', 'export_benefit']) {
      expect([type, (await messages(type)).pop()]).toEqual([type, expect.stringMatching(/^Re-created in Zoho Books — it had been deleted there\./)]);
    }
    expect((await orderLcDetailRepository.find(o.order)).lc_zoho_applied_at).not.toBeNull();

    const again = await caSyncService.runFullSync('manual', null);
    expect([again.revenue.skipped, again.purchases.skipped, again.benefits.skipped]).toEqual([1, 1, 1]); // nothing to do the next time
  });

  it("puts back the app's values when they were changed in Books", async () => {
    await sentOrder();
    const pay = first(store.payments);
    store.payments[pay].amount = 1100;
    const bill = first(store.bills);
    store.bills[bill].line_items[0].rate = 11000;
    const vp = first(store.vendorPayments);
    store.vendorPayments[vp].amount = 11000;
    const exp = idOf('expenses', (e) => String(e.description).includes('Documents to buyer'));
    store.expenses[exp].amount = 1500;
    const cost = idOf('expenses', (e) => String(e.description).includes('ECGC'));
    store.expenses[cost].amount = 4000;
    const jn = first(store.journals);
    store.journals[jn].line_items[0].amount = 1700;

    const result = await caSyncService.runFullSync('manual', null);

    expect(result.revenue.synced).toBe(1);
    expect([result.purchases.bills, result.purchases.payments, result.purchases.expenses]).toEqual([1, 1, 2]);
    expect(result.benefits.journals).toBe(1);
    expect(store.payments[pay].amount).toBe(1200);
    expect(store.payments[pay].bank_charges).toBe(6); // the ₹500 bank charges, in USD, as first sent
    expect(store.bills[bill].line_items[0].rate).toBe(12000);
    expect(store.vendorPayments[vp].amount).toBe(12000);
    expect([store.expenses[exp].amount, store.expenses[cost].amount]).toEqual([1800, 4200]);
    expect(store.journals[jn].line_items[0].amount).toBe(1750);
    expect(Object.keys(store.payments)).toHaveLength(1); // put back in place, never a second one
    expect(Object.keys(store.bills)).toHaveLength(1);
    for (const type of ['revenue_payment', 'vendor_bill', 'vendor_payment', 'order_expense', 'order_cost', 'export_benefit']) {
      expect([type, (await messages(type)).pop()]).toEqual([type, expect.stringMatching(/^Restored to the app's values — it had been changed in Zoho Books\./)]);
    }
    expect(requestsTo('POST', '/books/v3/bills')).toHaveLength(1); // the bill was updated, not created again
  });

  it('in a locked year restores nothing and tells the CA once', async () => {
    await sentOrder();
    await caFyLockRepository.lock('2026-27', userId);
    store.expenses = {};
    store.bills[first(store.bills)].line_items[0].rate = 11000;

    await caSyncService.runFullSync('manual', null);
    await caSyncService.runFullSync('manual', null);

    expect(store.expenses).toEqual({}); // not re-created
    expect(Object.values(store.bills)[0].line_items[0].rate).toBe(11000); // not put back
    const errors = (await db.query("SELECT message FROM zoho_sync_log WHERE status = 'error' AND message LIKE '%which is locked: not restored%' ORDER BY id")).map((r) => r.message);
    expect(errors).toHaveLength(3); // the bill, the expense and the cost — each logged once, not on every sync
    const orderId = (await db.queryOne('SELECT order_id FROM order_vendor_bills ORDER BY id DESC LIMIT 1')).order_id;
    expect(errors).toContain(`Bill TEST-GTA5-${orderId} from TEST-Stage5 GTA was changed in Zoho Books, but it is dated in FY 2026-27, which is locked: not restored — CA to decide.`);
  });

  it('the import follows Books and links an expense to its order', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await db.execute('UPDATE orders SET buyer_inquiry_ref = :r WHERE id = :id', { r: `NX5-LINK-${orderId}`, id: orderId });
    const book = (id, amount, date, reference = '', description = 'CA entry') => {
      store.expenses[id] = { expense_id: id, account_name: 'Postage', description, vendor_name: 'DHL', amount, currency_code: 'INR', date, reference_number: reference };
    };
    book('CA-1', 2350, '2026-10-27', `Courier nx5-link-${orderId}`);
    book('CA-2', 900, '2026-10-27');
    book('CA-3', 400, '2026-10-27');
    book('CA-OLD', 700, '2025-06-10');

    const firstRun = await caSyncService.importPendingExpenses('manual', null);
    expect([firstRun.imported, firstRun.updated, firstRun.removed]).toEqual([4, 0, 0]);
    const byZoho = await caExpenseRepository.allByZohoId();
    expect(Number(byZoho.get('CA-1').order_id)).toBe(orderId); // linked by its Reference #
    expect(byZoho.get('CA-2').order_id).toBeNull();

    // The CA changes one, deletes three in Books: one is matched to a bank line, one is in a locked year.
    store.expenses['CA-1'].amount = 2400;
    delete store.expenses['CA-2'];
    delete store.expenses['CA-3'];
    delete store.expenses['CA-OLD'];
    await db.execute("INSERT INTO ca_bank_statement_lines (line_hash, transaction_date, description, debit_amount, matched_expense_id) VALUES (SHA2('stage5-ca3', 256), '2026-10-27', 'DHL', 400, :id)", { id: byZoho.get('CA-3').id });
    await caFyLockRepository.lock('2025-26', userId);

    const second = await caSyncService.importPendingExpenses('manual', null);

    expect([second.imported, second.updated, second.removed]).toEqual([0, 1, 1]);
    const after = await caExpenseRepository.allByZohoId();
    expect(String(after.get('CA-1').amount)).toBe('2400.00');
    expect(after.has('CA-2')).toBe(false);
    expect(after.has('CA-3')).toBe(true); // matched to a bank line: kept
    expect(after.has('CA-OLD')).toBe(true); // locked year: kept
    const audit = (await db.query("SELECT action_type FROM audit_log WHERE action_type IN ('CA_EXPENSE_UPDATED_FROM_ZOHO', 'CA_EXPENSE_REMOVED_FROM_ZOHO') ORDER BY id DESC LIMIT 2")).map((r) => r.action_type);
    expect(audit.sort()).toEqual(['CA_EXPENSE_REMOVED_FROM_ZOHO', 'CA_EXPENSE_UPDATED_FROM_ZOHO']);
    const log = (await messages('expense_import')).join('\n');
    expect(log).toContain('matched to a bank statement line: kept here');
    expect(log).toContain('dated in FY 2025-26, which is locked: kept here');
    await db.execute("DELETE FROM ca_bank_statement_lines WHERE line_hash = SHA2('stage5-ca3', 256)");
  });

  it('a whole list gone at once removes nothing', async () => {
    for (let i = 1; i <= 12; i += 1) {
      store.expenses[`CA-${i}`] = { expense_id: `CA-${i}`, account_name: 'Postage', description: 'x', vendor_name: null, amount: 10 * i, currency_code: 'INR', date: '2026-10-27', reference_number: '' };
    }
    await caSyncService.importPendingExpenses('manual', null);
    store.expenses = {};

    const result = await caSyncService.importPendingExpenses('manual', null);

    expect([result.removed, result.failed]).toEqual([0, 1]);
    expect((await caExpenseRepository.allByZohoId()).size).toBe(12);
  });

  it('flags a Zoho expense that repeats a cost entered in the app, and does not count it', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderCostEntryRepository.create(orderId, 'ecgc_insurance', null, 4200, '2026-10-29', userId, 'company_account');
    for (const [zid, amount, cur] of [['Z-DUP', 4200, 'INR'], ['Z-OK', 2350, 'INR'], ['Z-USD', 50, 'USD']]) {
      const id = await caExpenseRepository.insert(zid, 'Expense', null, null, amount, cur, '2026-10-29');
      await caExpenseRepository.linkToOrder(id, orderId);
    }

    const rows = Object.fromEntries((await caExpenseRepository.forOrderCounted(orderId)).map((r) => [r.zoho_expense_id, r]));

    expect([rows['Z-DUP'].counted, rows['Z-DUP'].reason]).toEqual([false, 'possible duplicate of a cost entered in the app — not counted']);
    expect([rows['Z-OK'].counted, rows['Z-OK'].reason]).toEqual([true, null]);
    expect([rows['Z-USD'].counted, rows['Z-USD'].reason]).toEqual([false, 'not in rupees — not counted']);
  });

  it('removing an order expense removes it from Books too', async () => {
    const o = await sentOrder();
    const zid = (await orderExpenseRepository.find(o.expense)).zoho_expense_id;
    expect(store.expenses[zid]).toBeDefined();
    const req = { params: { id: String(o.order), expenseId: String(o.expense) }, body: {}, query: {}, session: {}, user: { id: userId, name: 'Jest Admin' }, permissions: { ca_fy_lock_override: true } };

    await orderExpenseController.remove(req, { redirect() {}, status() { return this; }, send() {} });

    expect(store.expenses[zid]).toBeUndefined();
    expect(await orderExpenseRepository.find(o.expense)).toBeFalsy();
    expect(flash.pull(req)[0].message).toBe('Expense removed, here and in Zoho Books.');
    expect((await messages('order_expense')).pop()).toBe('Removed expense "Documents to buyer" (INR 1800.00) from Zoho Books: it was removed in the app.');
  });

  it('the Sync Log links an imported expense to the expenses, not to an order', () => {
    const env = new nunjucks.Environment(new nunjucks.FileSystemLoader(path.join(__dirname, '../../views')));
    const html = env.render('ca/zoho_sync.njk', {
      log: [{ created_at: '2026-10-29 10:00:00', triggered_by: 'manual', triggered_by_name: 'A', entity_type: 'ca_expenses', entity_id: 98765, leg: null, status: 'success', zoho_reference: 'Z-1', message: 'Imported' }],
      isEnabled: true, pendingCount: 0, heldCount: 0, purchasesPendingCount: 0, benefitsPendingCount: 0, csrfToken: 't', canOpenOrder: () => true, legLabels: {},
    });

    expect(html).toContain('<a href="/ca/expenses">Expense #98765</a>');
    expect(html).not.toContain('Order #98765');
  });
});
