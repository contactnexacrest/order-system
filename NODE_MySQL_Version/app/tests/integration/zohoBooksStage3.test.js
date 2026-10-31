'use strict';

const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const orderExpenseRepository = require('../../src/repositories/orderExpenseRepository');
const orderVendorBillRepository = require('../../src/repositories/orderVendorBillRepository');
const zohoPurchaseRepository = require('../../src/repositories/zohoPurchaseRepository');
const caSyncService = require('../../src/services/caSyncService');
const zohoBooksService = require('../../src/services/zohoBooksService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of ZohoBooksStage3Test.php. Zoho integration Stage 3 (2026-10-29):
 * suppliers and logistics partners as Books vendors, their tax invoices as
 * bills, the payments made to them as vendor payments applied to the bill
 * (a payment on a proforma held as an advance until the tax invoice
 * replacing it is in Books), and the order's expenses as Books expenses
 * that the expense import then skips. Every request goes to a fake Zoho;
 * the tests never reach the real one.
 */
describe('Zoho Books Stage 3', () => {
  let requests;
  let contacts;
  let bills;
  let vendorPayments;
  let expenses;
  let gstRegistered;
  let accounts;
  let userId;

  function billBalance(billId) {
    let paid = 0;
    for (const payment of Object.values(vendorPayments)) {
      for (const applied of payment.bills || []) paid += applied.bill_id === billId ? applied.amount_applied : 0;
    }
    return Math.round((bills[billId].total - paid) * 100) / 100;
  }

  function fakeZoho(method, url, payload) {
    requests.push({ method, url, payload });
    const u = new URL(url);
    const p = u.pathname;
    const q = (k) => u.searchParams.get(k);
    const json = (body, status = 200) => ({ status, body: JSON.stringify(body) });
    let m;

    if (p === '/oauth/v2/token') return json({ access_token: 'fake-access' });
    if ((m = p.match(/^\/books\/v3\/organizations\/(\d+)$/))) return json({ code: 0, organization: { organization_id: m[1], is_registered_for_gst: gstRegistered, tax_settings: { tax_reg_no: gstRegistered ? '29AAKCN8733G1ZZ' : '' } } });
    if (p === '/books/v3/settings/taxes') {
      return json({ code: 0, taxes: [0, 5, 12, 18, 28, 40].flatMap((rate) => [{ tax_id: `TX-GST${rate}`, tax_name: `GST${rate}`, tax_percentage: rate }, { tax_id: `TX-IGST${rate}`, tax_name: `IGST${rate}`, tax_percentage: rate }]) });
    }
    if (p === '/books/v3/chartofaccounts') return json({ code: 0, chartofaccounts: accounts });
    if (p === '/books/v3/contacts' && method === 'GET') return json({ code: 0, contacts: Object.values(contacts).filter((c) => c.contact_name === q('contact_name')) });
    if (p === '/books/v3/contacts' && method === 'POST') {
      const id = `VN-${Object.keys(contacts).length + 1}`;
      contacts[id] = { contact_id: id, contact_name: payload.contact_name, contact_type: payload.contact_type, payload };
      return json({ code: 0, contact: contacts[id] }, 201);
    }
    if (p === '/books/v3/bills' && method === 'GET') {
      const rows = Object.values(bills).filter((b) => b.bill_number === (q('bill_number') ?? b.bill_number) && b.vendor_id === (q('vendor_id') ?? b.vendor_id));
      return json({ code: 0, bills: rows.map((b) => ({ ...b, balance: billBalance(b.bill_id) })) });
    }
    if (p === '/books/v3/bills' && method === 'POST') {
      const id = `BL-${Object.keys(bills).length + 1}`;
      const line = payload.line_items[0];
      const percent = line.tax_id ? Number(line.tax_id.replace(/\D/g, '')) : 0;
      bills[id] = { bill_id: id, bill_number: payload.bill_number, vendor_id: payload.vendor_id, total: Math.round(line.rate * (1 + percent / 100) * 100) / 100, payload };
      return json({ code: 0, bill: bills[id] }, 201);
    }
    if (p === '/books/v3/vendorpayments' && method === 'GET') {
      const rows = Object.entries(vendorPayments).map(([id, x]) => ({ payment_id: id, amount: x.amount, vendor_id: x.vendor_id }));
      return json({ code: 0, vendorpayments: rows.filter((r) => q('vendor_id') === null || r.vendor_id === q('vendor_id')) });
    }
    if (p === '/books/v3/vendorpayments' && method === 'POST') {
      const id = `VP-${Object.keys(vendorPayments).length + 1}`;
      vendorPayments[id] = payload;
      return json({ code: 0, vendorpayment: { payment_id: id } }, 201);
    }
    if ((m = p.match(/^\/books\/v3\/vendorpayments\/(.+)$/)) && method === 'PUT') {
      vendorPayments[m[1]] = payload;
      return json({ code: 0, vendorpayment: { payment_id: m[1] } });
    }
    if (p === '/books/v3/expenses' && method === 'POST') {
      const id = `EX-${Object.keys(expenses).length + 1}`;
      expenses[id] = { ...payload, expense_id: id };
      return json({ code: 0, expense: { expense_id: id } }, 201);
    }
    if (p === '/books/v3/expenses' && method === 'GET') {
      return json({ code: 0, expenses: Object.values(expenses).map((e) => ({ expense_id: e.expense_id, account_name: 'Travel Expense', description: e.description, total: e.amount, currency_code: 'INR', date: e.date })), page_context: { has_more_page: false } });
    }
    return json({ code: 5, message: `fake Zoho has no ${method} ${p}` }, 400);
  }

  const requestsTo = (method, prefix) => requests.filter((r) => r.method === method && new URL(r.url).pathname.startsWith(prefix));

  // A TEST- order with a TEST- supplier (on a supplier PO) and a TEST- freight forwarder.
  async function createOrder(buyer = 'TEST-Stage3 Buyer', supplierName = 'TEST-Stage3 Granite Supplier') {
    const clientId = await createTestClient();
    await db.execute('UPDATE clients SET company_legal_name = :name WHERE id = :id', { name: buyer, id: clientId });
    const order = await createTestOrder(clientId, 'CIF');
    await db.execute('UPDATE orders SET buyer_inquiry_ref = :ref WHERE id = :id', { ref: `NX-${order}`, id: order });
    const supplier = (await db.execute("INSERT INTO suppliers (supplier_legal_name, gstin, pan, state) VALUES (:name, '29ABCDE1234F1Z5', 'ABCDE1234F', 'Karnataka')", { name: supplierName })).insertId;
    const po = (await db.execute("INSERT INTO order_supplier_po (order_id, supplier_id, supplier_legal_name, supplier_po_reference) VALUES (:o, :s, :n, 'TEST-SPO-1')", { o: order, s: supplier, n: supplierName })).insertId;
    const partner = (await db.execute("INSERT INTO logistics_partners (partner_name, service_type) VALUES ('TEST-Stage3 Forwarder', 'freight_forwarder')")).insertId;
    return { order, po, partner, supplier };
  }

  function supplierBill(o, docKind, no, taxable, gst, replaces = null) {
    return orderVendorBillRepository.create(o.order, 'supplier', {
      supplier_po_id: o.po, bill_for: 'advance', doc_kind: docKind, bill_no: no, bill_date: '2026-10-25',
      taxable_inr: taxable, gst_inr: gst, requested_amount_inr: docKind === 'proforma' ? taxable + gst : null, replaces_bill_id: replaces,
    }, userId);
  }

  const sync = () => caSyncService.syncPurchases('manual', null);
  const messages = async (orderId, type) => (await db.query('SELECT message FROM zoho_sync_log WHERE entity_id = :id AND sync_type = :t ORDER BY id', { id: orderId, t: type })).map((r) => r.message);

  beforeEach(async () => {
    requests = [];
    contacts = {};
    bills = {};
    vendorPayments = {};
    expenses = {};
    gstRegistered = false;
    accounts = [
      { account_id: 'AC-COGS', account_name: 'Cost of Goods Sold' },
      { account_id: 'AC-TRANS', account_name: 'Transportation Expense' },
      { account_id: 'AC-OTHER', account_name: 'Other Expenses' },
      { account_id: 'AC-TRAVEL', account_name: 'Travel Expense' },
      { account_id: 'AC-POST', account_name: 'Postage' },
      { account_id: 'AC-EMP', account_name: 'Employee Reimbursements' },
    ];
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
    // The test database is shared by the whole run: earlier tests' TEST-
    // orders, bills and expenses must not reach this test's fake Zoho.
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

  it('holds a proforma payment, applies it to the tax invoice, and applies the balance straight away', async () => {
    const o = await createOrder();
    const proforma = await supplierBill(o, 'proforma', 'TEST-PI-77', 42372.88, 7627.12);
    await orderVendorBillRepository.addPayment(proforma, 'advance', '2026-10-20', 50000, 'NEFT / RTGS', 'TEST-UTR-ADV', userId);

    expect(await sync()).toEqual({ bills: 0, payments: 1, applied: 0, expenses: 0, failed: 0, skipped: 0 });
    expect(bills).toEqual({}); // a proforma is an advance request, not a bill
    // The UTR stays the reference; the description says which payment it is (owner, 2026-10-31).
    expect(vendorPayments['VP-1']).toEqual({ vendor_id: 'VN-1', amount: 50000, date: '2026-10-20', payment_mode: 'NEFT / RTGS', reference_number: 'TEST-UTR-ADV',
      description: `Supplier advance payment for order NX-${o.order} · Supplier PO TEST-SPO-1 · against Proforma TEST-PI-77`, paid_through_account_id: 'SBI-1' });
    expect(contacts['VN-1'].payload).toEqual({ contact_name: 'TEST-Stage3 Granite Supplier', company_name: 'TEST-Stage3 Granite Supplier', contact_type: 'vendor', pan_no: 'ABCDE1234F' }); // not GST-registered: no GST fields
    expect((await messages(o.order, 'vendor_payment'))[0]).toContain('held unapplied until the tax invoice for proforma TEST-PI-77 is in Zoho Books');

    const invoice = await supplierBill(o, 'invoice', 'TEST-INV-77', 100000, 18000, proforma);
    expect(await sync()).toEqual({ bills: 1, payments: 0, applied: 1, expenses: 0, failed: 0, skipped: 0 });
    const bill = bills['BL-1'];
    expect(bill.bill_number).toBe('TEST-INV-77');
    expect(bill.total).toBe(118000);
    expect(bill.payload.line_items[0].account_id).toBe('AC-COGS');
    // owner rule D-212: the bill names its Supplier PO
    expect(bill.payload.line_items[0].description.startsWith(`Supplier advance bill for order NX-${o.order} (Supplier PO TEST-SPO-1): taxable 100000.00 + GST 18000.00`)).toBe(true);
    expect(bill.payload.reference_number).toBe(`NX-${o.order}`);
    expect(vendorPayments['VP-1'].bills).toEqual([{ bill_id: 'BL-1', amount_applied: 50000 }]);

    await orderVendorBillRepository.addPayment(invoice, 'balance', '2026-10-28', 68000, 'NEFT / RTGS', 'TEST-UTR-BAL', userId);
    expect(await sync()).toEqual({ bills: 0, payments: 1, applied: 1, expenses: 0, failed: 0, skipped: 0 });
    expect(billBalance('BL-1')).toBe(0);
    expect(vendorPayments['VP-2'].reference_number).toBe('TEST-UTR-BAL');
    expect(vendorPayments['VP-2'].description).toBe(`Supplier balance payment for order NX-${o.order} · Supplier PO TEST-SPO-1 · against Tax invoice TEST-INV-77`);
    expect(requestsTo('POST', '/books/v3/contacts')).toHaveLength(1); // the vendor is created once and remembered
    expect((await db.queryOne('SELECT zoho_contact_id FROM suppliers WHERE id = :id', { id: o.supplier })).zoho_contact_id).toBe('VN-1');

    expect(await sync()).toEqual({ bills: 0, payments: 0, applied: 0, expenses: 0, failed: 0, skipped: 1 }); // nothing is sent twice
  });

  it('a logistics bill goes to transport and its payment is applied', async () => {
    const o = await createOrder();
    const billId = await orderVendorBillRepository.create(o.order, 'logistics', {
      logistics_partner_id: o.partner, bill_for: 'ocean_freight', payment_kind: 'final', doc_kind: 'invoice',
      bill_no: 'TEST-FF-901', bill_date: '2026-10-27', taxable_inr: 16000, gst_inr: 0,
    }, userId);
    await orderVendorBillRepository.addPayment(billId, null, '2026-10-29', 16000, 'IMPS', 'TEST-UTR-FF', userId);

    expect(await sync()).toEqual({ bills: 1, payments: 1, applied: 1, expenses: 0, failed: 0, skipped: 0 });
    expect(bills['BL-1'].payload.line_items[0].account_id).toBe('AC-TRANS');
    expect(bills['BL-1'].payload.line_items[0].name).toBe('Ocean freight bill');
    expect(contacts['VN-1'].contact_name).toBe('TEST-Stage3 Forwarder');
    expect(billBalance('BL-1')).toBe(0);
    expect([vendorPayments['VP-1'].reference_number, vendorPayments['VP-1'].description])
      .toEqual(['TEST-UTR-FF', `Ocean freight — final payment for order NX-${o.order} · against Tax invoice TEST-FF-901`]);
  });

  it('order expenses go to Books and are not imported back', async () => {
    const o = await createOrder();
    const staff = await orderExpenseRepository.create(o.order, { expense_date: '2026-10-22', category: 'travel_ticket', details: 'Train to the factory', amount_inr: 2450, paid_by: 'staff', staff_name: 'Ravi', no_receipt_reason: 'test' }, userId);
    await orderExpenseRepository.create(o.order, { expense_date: '2026-10-23', category: 'courier', details: 'Samples to buyer', amount_inr: 1800, paid_by: 'company_account', no_receipt_reason: 'test' }, userId);

    expect((await sync()).expenses).toBe(2);
    expect(expenses['EX-1']).toEqual({
      account_id: 'AC-TRAVEL', paid_through_account_id: 'AC-EMP', amount: 2450, date: '2026-10-22', reference_number: `NX-${o.order}-EXP-${staff}`,
      description: `TEST-Stage3 Buyer / NX-${o.order}: Travel — train / flight / bus — Train to the factory (paid by Ravi)`, expense_id: 'EX-1',
    });
    expect([expenses['EX-2'].account_id, expenses['EX-2'].paid_through_account_id]).toEqual(['AC-POST', 'SBI-1']);

    const imported = await caSyncService.importPendingExpenses('manual', null);
    expect(imported.imported).toBe(0); // the expense import skips what this app sent
    expect(await zohoPurchaseRepository.isPushedExpense('EX-1')).toBe(true);
  });

  it('a missing Books account is refused with where to add it', async () => {
    const o = await createOrder();
    await orderExpenseRepository.create(o.order, { expense_date: '2026-10-22', category: 'hotel', details: 'Hotel', amount_inr: 3000, paid_by: 'company_card', no_receipt_reason: 'test' }, userId);

    expect((await sync()).failed).toBe(1);
    expect(await messages(o.order, 'order_expense')).toEqual(['Zoho Books has no account named "Company Card". Add it in Zoho Books -> Accountant -> Chart of Accounts (or ask the CA which account to use), then sync again.']);
    expect(expenses).toEqual({});
  });

  it('in a GST-registered organisation a bill carries its GST: IGST for another state, CGST + SGST for Karnataka', async () => {
    gstRegistered = true;
    const o = await createOrder();
    await db.execute("UPDATE suppliers SET gstin = '08ZZTPT1111Z1ZG' WHERE id = :id", { id: o.supplier });
    await supplierBill(o, 'invoice', 'TEST-INV-RJ', 100000, 18000);
    await db.execute("UPDATE logistics_partners SET gstin = '29ZZTPT2222Z1Z6' WHERE id = :id", { id: o.partner });
    await orderVendorBillRepository.create(o.order, 'logistics', {
      logistics_partner_id: o.partner, bill_for: 'cha', doc_kind: 'invoice', bill_no: 'TEST-CHA-KA', bill_date: '2026-10-27', taxable_inr: 10000, gst_inr: 1800,
    }, userId);

    expect((await sync()).bills).toBe(2);

    const rajasthan = bills['BL-1'];
    expect([rajasthan.payload.line_items[0].rate, rajasthan.payload.line_items[0].tax_id]).toEqual([100000, 'TX-IGST18']); // another state: IGST on the taxable value
    expect(rajasthan.total).toBe(118000);
    expect([contacts['VN-1'].payload.gst_treatment, contacts['VN-1'].payload.gst_no]).toEqual(['business_gst', '08ZZTPT1111Z1ZG']);
    expect(bills['BL-2'].payload.line_items[0].tax_id).toBe('TX-GST18'); // Karnataka, like NexaCrest: CGST + SGST (the GST group)
    expect(bills['BL-2'].total).toBe(11800);
  });

  it('in a GST-registered organisation a bill without GST goes in at 0 % from a registered vendor and without tax from an unregistered one', async () => {
    gstRegistered = true;
    const o = await createOrder();
    await supplierBill(o, 'invoice', 'TEST-INV-ZERO', 5000, 0);
    await orderVendorBillRepository.create(o.order, 'logistics', {
      logistics_partner_id: o.partner, bill_for: 'other', doc_kind: 'invoice', bill_no: 'TEST-NOGST-1', bill_date: '2026-10-27', taxable_inr: 500, gst_inr: 0,
    }, userId);

    expect((await sync()).bills).toBe(2);
    expect(bills['BL-1'].payload.line_items[0].tax_id).toBe('TX-GST0'); // registered supplier in Karnataka (29...)
    expect(bills['BL-2'].payload.line_items[0]).not.toHaveProperty('tax_id'); // unregistered forwarder: no tax
    expect(contacts['VN-2'].payload.gst_treatment).toBe('business_none');
  });

  it('in a GST-registered organisation GST from a vendor without GSTIN or an odd rate is refused', async () => {
    gstRegistered = true;
    const o = await createOrder();
    await supplierBill(o, 'invoice', 'TEST-INV-ODD', 100000, 15000);
    await orderVendorBillRepository.create(o.order, 'logistics', {
      logistics_partner_id: o.partner, bill_for: 'transport', doc_kind: 'invoice', bill_no: 'TEST-TR-NOGSTIN', bill_date: '2026-10-27', taxable_inr: 2000, gst_inr: 360,
    }, userId);

    expect((await sync()).failed).toBe(2);
    expect(await messages(o.order, 'vendor_bill')).toEqual([
      'Bill TEST-INV-ODD not sent: GST 15000.00 on taxable 100000.00 is not 5, 12, 18, 28 or 40 % — correct the bill (a bill with several GST rates needs the CA).',
      'Bill TEST-TR-NOGSTIN not sent: it carries GST (360.00), but TEST-Stage3 Forwarder has no GSTIN in the app. Add the GSTIN to the supplier / logistics partner, or correct the bill.',
    ]);
    expect(bills).toEqual({});
  });

  // Owner decision 2026-10-29 (CA advice): a road transporter (GTA) that charges no GST is booked under reverse charge at 5%.
  it('in a GST-registered organisation a reverse-charge transport bill carries the 5% as reverse charge', async () => {
    gstRegistered = true;
    const o = await createOrder();
    await orderVendorBillRepository.create(o.order, 'logistics', {
      logistics_partner_id: o.partner, bill_for: 'transport', doc_kind: 'invoice', bill_no: 'TEST-GTA-1', bill_date: '2026-10-27', taxable_inr: 10000, gst_inr: 0, gst_reverse_charge: 1,
    }, userId);
    const tamilNadu = (await db.execute("INSERT INTO logistics_partners (partner_name, service_type, gstin) VALUES ('TEST-Stage3 GTA Chennai', 'transportation', '33ZZTPT4444Z1Z5')")).insertId;
    await orderVendorBillRepository.create(o.order, 'logistics', {
      logistics_partner_id: tamilNadu, bill_for: 'transport', doc_kind: 'invoice', bill_no: 'TEST-GTA-2', bill_date: '2026-10-27', taxable_inr: 8000, gst_inr: 0, gst_reverse_charge: 1,
    }, userId);
    await orderVendorBillRepository.create(o.order, 'logistics', {
      logistics_partner_id: tamilNadu, bill_for: 'transport', doc_kind: 'invoice', bill_no: 'TEST-GTA-BAD', bill_date: '2026-10-27', taxable_inr: 1000, gst_inr: 50, gst_reverse_charge: 1,
    }, userId);

    const result = await sync();

    expect([result.bills, result.failed]).toEqual([2, 1]);
    const unregistered = bills['BL-1'].payload;
    expect(unregistered.is_reverse_charge_applied).toBe(true);
    expect(unregistered.line_items[0]).toMatchObject({ rate: 10000, reverse_charge_tax_id: 'TX-GST5' }); // no GSTIN: our own state, the GST group; the bill stays at its taxable value
    expect(unregistered.line_items[0]).not.toHaveProperty('tax_id');
    expect(bills['BL-1'].total).toBe(10000);
    expect(bills['BL-2'].payload.line_items[0].reverse_charge_tax_id).toBe('TX-IGST5'); // a transporter registered in another state: IGST
    expect(bills['BL-2'].payload.line_items[0]).not.toHaveProperty('tax_id');
    expect(await messages(o.order, 'vendor_bill')).toEqual([
      'Sent bill TEST-GTA-1 from TEST-Stage3 Forwarder (INR 10000.00, reverse charge) as Zoho Books bill.',
      'Sent bill TEST-GTA-2 from TEST-Stage3 GTA Chennai (INR 8000.00, reverse charge) as Zoho Books bill.',
      'Bill TEST-GTA-BAD not sent: it is marked reverse charge but carries GST (50.00). Under reverse charge the transporter charges no GST — correct the bill.',
    ]);
  });

  it('a bill already in Books is reused, not duplicated', async () => {
    const o = await createOrder();
    contacts['VN-9'] = { contact_id: 'VN-9', contact_name: 'TEST-Stage3 Granite Supplier', contact_type: 'vendor' };
    bills['BL-9'] = { bill_id: 'BL-9', bill_number: 'TEST-INV-DUP', vendor_id: 'VN-9', total: 118000 };
    const billId = await supplierBill(o, 'invoice', 'TEST-INV-DUP', 100000, 18000);

    expect((await sync()).bills).toBe(1);
    expect(requestsTo('POST', '/books/v3/bills')).toHaveLength(0);
    expect((await zohoPurchaseRepository.findBill(billId)).zoho_bill_id).toBe('BL-9');
    expect(requestsTo('POST', '/books/v3/contacts')).toHaveLength(0); // the existing vendor is found by name
  });

  it('in test only TEST- orders and TEST- vendors go', async () => {
    const realVendor = await createOrder('TEST-Stage3 Buyer', 'Real Granite Pvt Ltd');
    await supplierBill(realVendor, 'invoice', 'REAL-INV-1', 1000, 0);
    const realBuyer = await createOrder('Real Buyer GmbH');
    await supplierBill(realBuyer, 'invoice', 'TEST-INV-RB', 1000, 0);
    await orderExpenseRepository.create(realBuyer.order, { expense_date: '2026-10-22', category: 'food', details: 'Lunch', amount_inr: 500, paid_by: 'company_account', no_receipt_reason: 'test' }, userId);

    expect(await sync()).toEqual({ bills: 0, payments: 0, applied: 0, expenses: 0, failed: 0, skipped: 1 });
    expect(requestsTo('POST', '/books/')).toEqual([]);

    process.env.ZOHO_ENVIRONMENT = 'production';
    await db.execute('UPDATE suppliers SET is_sample_data = 1 WHERE id = :id', { id: realBuyer.supplier });
    try {
      const numbers = (await zohoPurchaseRepository.billsToSync()).map((b) => String(b.bill_no));
      expect(numbers).not.toContain('TEST-INV-RB'); // production: a sample supplier never goes
      expect(numbers).toContain('REAL-INV-1');
    } finally {
      // Sample data left behind would hold up the sample-data tests.
      await db.execute('UPDATE suppliers SET is_sample_data = 0 WHERE id = :id', { id: realBuyer.supplier });
      await db.execute("UPDATE order_vendor_bills SET zoho_bill_id = 'DONE' WHERE bill_no IN ('REAL-INV-1', 'TEST-INV-RB')");
    }
  });
});
