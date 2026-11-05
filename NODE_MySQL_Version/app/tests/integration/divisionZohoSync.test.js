'use strict';

const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const caSyncService = require('../../src/services/caSyncService');
const divisionZohoSync = require('../../src/services/divisionZohoSync');
const importService = require('../../src/services/importService');
const zohoBooksService = require('../../src/services/zohoBooksService');
const zohoPendingService = require('../../src/services/zohoPendingService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of DivisionZohoSyncTest.php. D-257 Zoho (owner 5 Nov 2026: "make sure
 * the Zoho sync is complete"): the new divisions' money goes to Zoho Books
 * with the full sync — Domestic Trade's tax invoice and receipts, Import &
 * Distribution's bills, payments abroad, duty payment and IGST journal,
 * Merchant Trade's invoice, bill and both payment legs — once, applied to the
 * right invoice or bill, with the app's own D-212 descriptions; the "Waiting
 * for Zoho" badge counts them; the "App vs Zoho" check compares them. Against
 * a fake Zoho Books.
 */
describe('D-257 Zoho: the new divisions sync like Export', () => {
  let requests;
  let contacts;
  let invoices;
  let bills;
  let customerPayments;
  let vendorPayments;
  let journals;
  let userId;

  const r2 = (n) => Math.round(n * 100) / 100;

  function paid(kind, id) {
    let sum = 0;
    for (const p of Object.values(kind === 'invoice' ? customerPayments : vendorPayments)) {
      for (const a of p[kind === 'invoice' ? 'invoices' : 'bills'] || []) sum += a[`${kind}_id`] === id ? a.amount_applied : 0;
    }
    return sum;
  }
  const total = (p) => r2(p.line_items.reduce((t, l) => t + l.quantity * l.rate * (l.tax_id ? 1 + Number(l.tax_id.replace(/\D/g, '')) / 100 : 1), 0));

  function fakeZoho(method, url, payload) {
    requests.push({ method, url, payload });
    const u = new URL(url);
    const p = u.pathname;
    const q = (k) => u.searchParams.get(k);
    const json = (body, status = 200) => ({ status, body: JSON.stringify(body) });
    let m;
    if (p === '/oauth/v2/token') return json({ access_token: 'fake-access' });
    if ((m = p.match(/^\/books\/v3\/organizations\/(\d+)$/))) return json({ code: 0, organization: { organization_id: m[1], is_registered_for_gst: true, tax_settings: { tax_reg_no: '29AAKCN8733G1ZZ' } } });
    if (p === '/books/v3/settings/taxes') return json({ code: 0, taxes: [0, 5, 12, 18, 28].flatMap((rate) => [{ tax_id: `TX-GST${rate}`, tax_name: `GST${rate}`, tax_percentage: rate }, { tax_id: `TX-IGST${rate}`, tax_name: `IGST${rate}`, tax_percentage: rate }]) });
    if (p === '/books/v3/settings/currencies') return json({ code: 0, currencies: ['INR', 'USD', 'EUR'].map((c) => ({ currency_id: `CUR-${c}`, currency_code: c })) });
    if (p === '/books/v3/chartofaccounts') {
      return json({ code: 0, chartofaccounts: ['Imported Goods Stock', 'IGST Paid on Import', 'Input IGST', 'Merchanting Trade Sales', 'Merchanting Trade Purchases'].map((n) => ({ account_id: `AC-${n}`, account_name: n })) });
    }
    if (p === '/books/v3/contacts' && method === 'GET') return json({ code: 0, contacts: Object.values(contacts).filter((c) => c.contact_name === q('contact_name')) });
    if (p === '/books/v3/contacts' && method === 'POST') {
      const id = `CT-${Object.keys(contacts).length + 1}`;
      contacts[id] = { contact_id: id, contact_name: payload.contact_name, currency_code: payload.currency_id ? payload.currency_id.slice(4) : (payload.currency_code || 'INR'), gst_treatment: payload.gst_treatment || null, payload };
      return json({ code: 0, contact: contacts[id] }, 201);
    }
    if (p === '/books/v3/invoices' && method === 'GET') {
      const rows = Object.values(invoices).filter((i) => i.invoice_number === (q('invoice_number') ?? i.invoice_number));
      return json({ code: 0, invoices: rows.map((i) => ({ ...i, balance: r2(i.total - paid('invoice', i.invoice_id)) })) });
    }
    if (p === '/books/v3/invoices' && method === 'POST') {
      const id = `IN-${Object.keys(invoices).length + 1}`;
      invoices[id] = { invoice_id: id, invoice_number: payload.invoice_number, customer_id: payload.customer_id, status: 'draft', total: total(payload), payload };
      return json({ code: 0, invoice: invoices[id] }, 201);
    }
    if ((m = p.match(/^\/books\/v3\/invoices\/(.+)\/status\/sent$/))) {
      invoices[m[1]].status = 'sent';
      return json({ code: 0, message: 'Invoice status has been changed to Sent.' });
    }
    if (p === '/books/v3/bills' && method === 'GET') {
      const rows = Object.values(bills).filter((b) => b.bill_number === (q('bill_number') ?? b.bill_number) && b.vendor_id === (q('vendor_id') ?? b.vendor_id));
      return json({ code: 0, bills: rows.map((b) => ({ ...b, balance: r2(b.total - paid('bill', b.bill_id)) })) });
    }
    if (p === '/books/v3/bills' && method === 'POST') {
      const id = `BL-${Object.keys(bills).length + 1}`;
      bills[id] = { bill_id: id, bill_number: payload.bill_number, vendor_id: payload.vendor_id, total: total(payload), payload };
      return json({ code: 0, bill: bills[id] }, 201);
    }
    for (const [path, store, one, field] of [['customerpayments', customerPayments, 'payment', 'customer_id'], ['vendorpayments', vendorPayments, 'vendorpayment', 'vendor_id']]) {
      if (p === `/books/v3/${path}` && method === 'GET') {
        const rows = Object.entries(store)
          .filter(([, x]) => (q(field) === null || x[field] === q(field)) && (q('reference_number') === null || x.reference_number === q('reference_number')))
          .map(([id, x]) => ({ payment_id: id, amount: x.amount, reference_number: x.reference_number, [field]: x[field] }));
        return json({ code: 0, [path]: rows, page_context: { has_more_page: false } });
      }
      if (p === `/books/v3/${path}` && method === 'POST') {
        const id = `${path.slice(0, 2).toUpperCase()}-${Object.keys(store).length + 1}`;
        store[id] = payload;
        return json({ code: 0, [one]: { payment_id: id } }, 201);
      }
    }
    if (p === '/books/v3/journals' && method === 'POST') {
      const id = `JN-${Object.keys(journals).length + 1}`;
      journals[id] = payload;
      return json({ code: 0, journal: { journal_id: id } }, 201);
    }
    if (p === '/books/v3/journals' && method === 'GET') {
      return json({ code: 0, journals: Object.entries(journals).map(([id, j]) => ({ journal_id: id, total: j.line_items[0].amount, notes: j.notes })), page_context: { has_more_page: false } });
    }
    if (p === '/books/v3/expenses' && method === 'GET') return json({ code: 0, expenses: [], page_context: { has_more_page: false } });
    return json({ code: 5, message: `fake Zoho has no ${method} ${p}` }, 400);
  }

  const calls = (method, prefix) => requests.filter((r) => r.method === method && new URL(r.url).pathname.startsWith(prefix)).length;
  const rand = (a, b) => a + Math.floor(Math.random() * (b - a));

  async function domesticOrder() {
    const clientId = await createTestClient();
    await db.execute("UPDATE clients SET company_legal_name = 'TEST-Bengaluru Stone Traders' WHERE id = :id", { id: clientId });
    const orderId = await createTestOrder(clientId, 'FOB', 'Standard — New Buyer', 'USD');
    await db.execute('UPDATE orders SET division_id = 2 WHERE id = :id', { id: orderId });
    const no = `DOM/26-27/${String(orderId % 10000).padStart(4, '0')}`;
    await db.execute(`INSERT INTO domestic_orders (order_id, stage, buyer_gstin, buyer_state_code, place_of_supply, seller_state_code, payment_terms_days, tax_invoice_no, tax_invoice_date, taxable_total, cgst_total, sgst_total, igst_total, round_off, invoice_total)
      VALUES (:o, 'payment', '29AAPFU0939F1ZR', '29', '29', '29', 30, :no, '2026-11-02', 100000.00, 9000.00, 9000.00, 0, 0, 118000.00)`, { o: orderId, no });
    await db.execute("INSERT INTO domestic_order_lines (order_id, line_no, description, hsn_code, quantity, unit, rate_inr, gst_rate_pct) VALUES (:o, 1, 'Black granite slabs 20 mm', '6802', 50, 'm2', 2000.00, 18.00)", { o: orderId });
    await db.execute("INSERT INTO domestic_payments (order_id, received_on, amount_inr, bank_reference, description) VALUES (:o, '2026-11-04', 118000.00, :r, :d)",
      { o: orderId, r: `UTR-DOM-${orderId}`, d: `Domestic sale receipt of ₹1,18,000.00 from TEST-Bengaluru Stone Traders against GST tax invoice ${no} (order X), bank ref UTR-DOM-${orderId}` });
    return orderId;
  }

  async function importShipment() {
    const productId = (await db.execute("INSERT INTO products (product_code, name, hs_code) VALUES (:c, 'Imported quartz worktop', '68101990')", { c: `ZI${rand(10000, 99999)}` })).insertId;
    const ref = `IMP/PO/26-27/${rand(1000, 9999)}`;
    const id = (await db.execute(`INSERT INTO import_shipments (division_id, reference, stage, supplier_name, supplier_country, currency_code, po_date, bl_no, bl_date, port_code, cha_name, boe_no, boe_date, customs_fx_rate, clearance_date,
        value_total_inr, assessable_total, bcd_total, sws_total, igst_total, landed_total, igst_credit_on, duty_paid_on, duty_bank_reference, cha_inr, port_inr)
      VALUES (4, :ref, 'cleared', 'TEST-Xiamen Stone Co', 'China', 'USD', '2026-10-01', 'BL-77', '2026-10-15', 'INMAA1', 'TEST-Chennai CHA', :boe, '2026-10-30', 84.0000, '2026-10-31',
        840000.00, 860000.00, 86000.00, 8600.00, 171828.00, 960000.00, '2026-11-01', '2026-10-30', :duty, 18000.00, 22000.00)`,
    { ref, boe: String(rand(1000000, 9999999)), duty: `CIN-${rand(100000, 999999)}` })).insertId;
    await db.execute("INSERT INTO import_shipment_lines (shipment_id, line_no, product_id, description, hsn_code, quantity, unit, unit_price_fc, bcd_pct, sws_pct, igst_pct) VALUES (:s, 1, :p, 'Quartz worktop 3 m', '68101990', 100, 'pcs', 100.00, 10, 10, 18)", { s: id, p: productId });
    for (const [i, [kind, fc, fx, inr]] of [['advance', '3000.00', '83.5000', '250500.00'], ['balance', '7000.00', '84.1000', '588700.00']].entries()) {
      await db.execute('INSERT INTO import_payments (shipment_id, paid_on, kind, amount_fc, fx_rate, amount_inr, bank_reference, description) VALUES (:s, :d, :k, :fc, :fx, :inr, :r, :desc)',
        { s: id, d: i === 0 ? '2026-10-05' : '2026-11-03', k: kind, fc, fx, inr, r: `SWIFT-IMP-${id}-${i}`, desc: `Import supplier ${kind} of USD ${fc} to TEST-Xiamen Stone Co (China) against import PO ${ref}, SWIFT/UTR ref SWIFT-IMP-${id}-${i}` });
    }
    return id;
  }

  async function deal() {
    const n = rand(1000, 9999);
    const id = (await db.execute(`INSERT INTO merchant_deals (division_id, reference, po_no, sale_invoice_no, stage, deal_date, supplier_name, supplier_country, purchase_currency, buyer_name, buyer_country, sale_currency, origin_country, bl_no, bl_date, invoice_generated_at, invoice_date)
      VALUES (3, :r, :po, :inv, 'shipped', '2026-10-01', 'TEST-Anatolia Marble', 'Turkey', 'EUR', 'TEST-Gulf Interiors LLC', 'UAE', 'USD', 'Turkey', 'BL-MTT-1', '2026-10-20', NOW(), '2026-10-21')`,
    { r: `MTT/26-27/${n}`, po: `MTT/PO/26-27/${n}`, inv: `MTT/I/26-27/${n}` })).insertId;
    await db.execute("INSERT INTO merchant_deal_lines (deal_id, line_no, description, hsn_code, quantity, unit, buy_price_fc, sell_price_fc) VALUES (:d, 1, 'Marble blocks', '2515', 20, 'm3', 800.00, 1000.00)", { d: id });
    for (const [leg, fc, fx, inr, on] of [['buy', '16000.00', '91.2000', '1459200.00', '2026-10-10'], ['sell', '20000.00', '84.0000', '1680000.00', '2026-10-25']]) {
      await db.execute('INSERT INTO merchant_payments (deal_id, leg, moved_on, amount_fc, fx_rate, amount_inr, bank_reference, description) VALUES (:d, :l, :o, :fc, :fx, :inr, :r, :desc)',
        { d: id, l: leg, o: on, fc, fx, inr, r: `SWIFT-MTT-${id}-${leg}`, desc: `Merchant trade ${leg} leg ${fc} against deal MTT/26-27/${n}, SWIFT/UTR ref SWIFT-MTT-${id}-${leg}` });
    }
    return id;
  }

  beforeEach(async () => {
    requests = [];
    contacts = {};
    invoices = {};
    bills = {};
    customerPayments = {};
    vendorPayments = {};
    journals = {};
    process.env.ZOHO_INTEGRATION_ENABLED = 'true';
    process.env.ZOHO_ENVIRONMENT = 'test';
    process.env.ZOHO_EINVOICE_ENABLED = 'false';
    for (const [key, value] of Object.entries({
      zoho_books_enabled: '1', zoho_books_client_id: 'fake-client', zoho_books_client_secret: 'fake-secret', zoho_books_refresh_token: 'fake-refresh',
      zoho_books_organization_id: '600', zoho_books_deposit_account_id: 'SBI-1',
    })) await companySettingsRepository.set(key, value);
    zohoBooksService.setTransport(fakeZoho);
    // Earlier tests' records must not reach this test's fake Zoho.
    await db.execute("UPDATE domestic_orders SET zoho_invoice_id = CONCAT('OLD-', order_id) WHERE zoho_invoice_id IS NULL AND tax_invoice_no IS NOT NULL");
    await db.execute("UPDATE domestic_payments SET zoho_payment_id = 'OLD' WHERE zoho_payment_id IS NULL");
    await db.execute("UPDATE import_shipments SET zoho_bill_id = COALESCE(zoho_bill_id, 'OLD'), zoho_duty_bill_id = COALESCE(zoho_duty_bill_id, 'OLD'), duty_zoho_payment_id = COALESCE(duty_zoho_payment_id, 'OLD'), zoho_igst_journal_id = COALESCE(zoho_igst_journal_id, 'OLD'), zoho_clearance_bill_id = COALESCE(zoho_clearance_bill_id, 'OLD')");
    await db.execute("UPDATE import_payments SET zoho_payment_id = 'OLD' WHERE zoho_payment_id IS NULL");
    await db.execute("UPDATE merchant_deals SET zoho_invoice_id = COALESCE(zoho_invoice_id, 'OLD'), zoho_bill_id = COALESCE(zoho_bill_id, 'OLD')");
    await db.execute("UPDATE merchant_payments SET zoho_payment_id = 'OLD' WHERE zoho_payment_id IS NULL");
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

  it('sends every new-division record once, applied, with the app\'s descriptions, on the full sync', async () => {
    const orderId = await domesticOrder();
    const importId = await importShipment();
    const dealId = await deal();
    // ZOH-6/7: the merchant buyer's name is already in Books as a EUR customer, and the domestic buyer as an overseas USD one.
    contacts['CT-EUR'] = { contact_id: 'CT-EUR', contact_name: 'TEST-Gulf Interiors LLC', currency_code: 'EUR', gst_treatment: 'overseas', payload: {} };
    contacts['CT-USD'] = { contact_id: 'CT-USD', contact_name: 'TEST-Bengaluru Stone Traders', currency_code: 'USD', gst_treatment: 'overseas', payload: {} };

    const waiting = (await zohoPendingService.counts(null)).by_type;
    const mine = (by) => Object.fromEntries(Object.entries(by).filter(([k]) => divisionZohoSync.TYPE_LABELS[k]));
    expect(mine(waiting)).toEqual({ domestic_invoice: 1, import_bills: 1, merchant_deal: 1 });

    const r = await caSyncService.runFullSync('manual', userId);
    const errors = (await db.query("SELECT message FROM zoho_sync_log WHERE status = 'error' ORDER BY id DESC LIMIT 3")).map((x) => x.message);
    expect([r.divisions, errors.join(' | ')]).toEqual([{ sent: 10, failed: 0, skipped: 0 }, errors.join(' | ')]);
    expect(mine((await zohoPendingService.counts(null)).by_type)).toEqual({});

    const dom = await db.queryOne('SELECT * FROM domestic_orders WHERE order_id = :o', { o: orderId });
    expect(invoices[dom.zoho_invoice_id].status).toBe('sent');
    const domCustomer = contacts[invoices[dom.zoho_invoice_id].customer_id];
    expect([domCustomer.contact_name, domCustomer.currency_code, domCustomer.gst_treatment, domCustomer.payload.gst_no]).toEqual(['TEST-Bengaluru Stone Traders (INR)', 'INR', 'business_gst', '29AAPFU0939F1ZR']);
    const clientRow = await db.queryOne('SELECT c.zoho_contact_id_inr FROM orders o JOIN clients c ON c.id = o.client_id WHERE o.id = :o', { o: orderId });
    expect(clientRow.zoho_contact_id_inr).toBe(domCustomer.contact_id);
    expect([invoices[dom.zoho_invoice_id].payload.adjustment, invoices[dom.zoho_invoice_id].payload.adjustment_description]).toEqual([0, 'Round off']);
    const receipt = await db.queryOne('SELECT * FROM domestic_payments WHERE order_id = :o', { o: orderId });
    const cp = customerPayments[receipt.zoho_payment_id];
    expect([cp.amount, cp.reference_number, cp.description, cp.invoices, cp.account_id, cp.exchange_rate]).toEqual([118000, `UTR-DOM-${orderId}`, receipt.description, [{ invoice_id: dom.zoho_invoice_id, amount_applied: 118000 }], 'SBI-1', undefined]);

    const s = await db.queryOne('SELECT * FROM import_shipments WHERE id = :id', { id: importId });
    const pays = await db.query('SELECT * FROM import_payments WHERE shipment_id = :s ORDER BY id', { s: importId });
    const adv = vendorPayments[pays[0].zoho_payment_id];
    expect([adv.amount, adv.exchange_rate, adv.reference_number, adv.bills, adv.description]).toEqual([3000, 83.5, `SWIFT-IMP-${importId}-0`, [{ bill_id: s.zoho_bill_id, amount_applied: 3000 }], pays[0].description]);
    expect(vendorPayments[pays[1].zoho_payment_id].bills[0].amount_applied).toBe(7000);
    const duty = vendorPayments[s.duty_zoho_payment_id];
    expect([duty.amount, duty.bills]).toEqual([266428, [{ bill_id: s.zoho_duty_bill_id, amount_applied: 266428 }]]);
    expect(duty.description).toBe(`Customs duty and IGST paid to Indian Customs for import PO ${s.reference}, bill of entry ${s.boe_no} dated 2026-10-30 at INMAA1: basic customs duty ₹86,000.00 + social welfare surcharge ₹8,600.00 + IGST ₹1,71,828.00 = ₹2,66,428.00, against customs duty bill BE-${s.boe_no}, challan / bank ref ${s.duty_bank_reference}`);
    expect(contacts[duty.vendor_id].contact_name).toBe('TEST-Indian Customs');
    const clr = bills[s.zoho_clearance_bill_id];
    expect([clr.bill_number, contacts[clr.vendor_id].contact_name, clr.total]).toEqual([`CLR-${s.boe_no}`, 'TEST-Chennai CHA', 40000]);
    expect(clr.payload.line_items.map((l) => l.description)).toEqual([
      `CHA charges on import PO ${s.reference}, bill of entry ${s.boe_no} dated 2026-10-30`, `Port and terminal charges on import PO ${s.reference}, bill of entry ${s.boe_no} dated 2026-10-30`]);
    const journal = journals[s.zoho_igst_journal_id];
    expect(journal.notes.startsWith('TEST- IGST paid on import taken as input credit')).toBe(true);
    expect([journal.line_items[0].amount, journal.line_items[1].amount]).toEqual([171828, 171828]);

    const d = await db.queryOne('SELECT * FROM merchant_deals WHERE id = :id', { id: dealId });
    expect(invoices[d.zoho_invoice_id].status).toBe('sent');
    expect(contacts[invoices[d.zoho_invoice_id].customer_id].contact_name).toBe('TEST-Gulf Interiors LLC (USD)');
    expect(String(d.sale_invoice_no).length).toBeLessThanOrEqual(16);
    const legs = await db.query('SELECT * FROM merchant_payments WHERE deal_id = :d ORDER BY leg', { d: dealId });
    const buy = vendorPayments[legs[0].zoho_payment_id];
    expect([buy.amount, buy.exchange_rate, buy.bills]).toEqual([16000, 91.2, [{ bill_id: d.zoho_bill_id, amount_applied: 16000 }]]);
    const sell = customerPayments[legs[1].zoho_payment_id];
    expect([sell.amount, sell.exchange_rate, sell.invoices, sell.description]).toEqual([20000, 84, [{ invoice_id: d.zoho_invoice_id, amount_applied: 20000 }], legs[1].description]);

    const again = await caSyncService.runFullSync('manual', userId);
    expect(again.divisions.sent).toBe(0);
    expect([calls('POST', '/books/v3/customerpayments'), calls('POST', '/books/v3/vendorpayments')]).toEqual([2, 4]);

    const logged = await db.query(`SELECT sync_type, status FROM zoho_sync_log WHERE entity_type IN ('import_shipment', 'merchant_deal') AND entity_id IN (:i, :d) ORDER BY id`, { i: importId, d: dealId });
    expect(logged.map((x) => x.sync_type)).toEqual(['import_bills', 'merchant_deal', 'import_payment', 'import_payment', 'import_duty_payment', 'import_igst_journal', 'merchant_payment', 'merchant_payment']);
    expect([...new Set(logged.map((x) => x.status))]).toEqual(['success']);
  });

  it('finds a payment Books already has on a retry, and a payment waits for its invoice', async () => {
    const orderId = await domesticOrder();
    const pending = await divisionZohoSync.pending(null, ['order', orderId]);
    expect([pending.domestic_invoice.length, pending.domestic_receipt.length]).toEqual([1, 0]);
    const [error] = await divisionZohoSync.sendNow('order', orderId, userId);
    expect(error).toBeNull();
    const paymentId = (await db.queryOne('SELECT zoho_payment_id FROM domestic_payments WHERE order_id = :o', { o: orderId })).zoho_payment_id;
    await db.execute('UPDATE domestic_payments SET zoho_payment_id = NULL WHERE order_id = :o', { o: orderId });
    expect(await divisionZohoSync.sendNow('order', orderId, userId)).toEqual([null, '1 record(s) sent to Zoho Books (invoice / bills and their payments).']);
    expect((await db.queryOne('SELECT zoho_payment_id FROM domestic_payments WHERE order_id = :o', { o: orderId })).zoho_payment_id).toBe(paymentId);
    expect(Object.keys(customerPayments)).toHaveLength(1);
    expect(await divisionZohoSync.sendNow('order', orderId, userId)).toEqual([null, 'Nothing of this record was waiting for Zoho Books.']);
  });

  it('sends nothing dated before the go-live date, and logs a failure without stopping the others', async () => {
    const orderId = await domesticOrder();
    const importId = await importShipment();
    const p = await divisionZohoSync.pending('2026-11-03');
    expect(p.domestic_invoice.some((x) => Number(x.order_id) === orderId)).toBe(false);
    expect(p.import_bills.some((x) => Number(x.id) === importId)).toBe(false);
    await db.execute("UPDATE import_shipments SET supplier_name = 'Real Supplier Ltd' WHERE id = :id", { id: importId });
    const r = await divisionZohoSync.run('manual', userId);
    expect([r.failed, r.sent]).toEqual([1, 2]);
    const msg = (await db.queryOne("SELECT message FROM zoho_sync_log WHERE sync_type = 'import_bills' AND entity_id = :id ORDER BY id DESC LIMIT 1", { id: importId })).message;
    expect(msg).toContain('only vendors whose name starts with TEST-');
  });

  it('records the duty payment with its challan, and the App vs Zoho check compares each record', async () => {
    const importId = await importShipment();
    await db.execute('UPDATE import_shipments SET duty_paid_on = NULL, duty_bank_reference = NULL WHERE id = :id', { id: importId });
    expect(await importService.saveDutyPayment(importId, { duty_paid_on: '2026-10-31', duty_bank_reference: '' }, userId)).toBe('Enter the challan or bank reference of the duty payment (e.g. ICEGATE challan CIN).');
    expect(await importService.saveDutyPayment(importId, { duty_paid_on: '2026-10-01', duty_bank_reference: 'CIN-1' }, userId)).toBe('Enter the date the duty was paid (on or after the bill of entry date, not in the future).');
    expect(await importService.saveDutyPayment(importId, { duty_paid_on: '2026-10-31', duty_bank_reference: 'CIN-2611' }, userId)).toBeNull();

    expect((await divisionZohoSync.check('import', importId)).counts).toEqual({ not_sent: 7 });
    await divisionZohoSync.sendNow('import', importId, userId);
    const after = await divisionZohoSync.check('import', importId);
    expect(after.counts).toEqual({ match: 7 });
    expect([after.rows[0].item.slice(0, 15), after.rows[0].app, after.rows[0].books]).toEqual(["Supplier's bill", 'USD 10,000.00', 'USD 10,000.00']);
    expect(await importService.saveDutyPayment(importId, { duty_paid_on: '2026-10-31', duty_bank_reference: 'CIN-9' }, userId)).toBe('This duty payment is already in Zoho Books, so it cannot be changed here. Ask the CA to correct it in Books.');

    const s = await db.queryOne('SELECT zoho_bill_id, duty_zoho_payment_id FROM import_shipments WHERE id = :id', { id: importId });
    bills[s.zoho_bill_id].total = 9999;
    expect((await divisionZohoSync.check('import', importId)).rows[0].status).toBe('different');
    delete vendorPayments[s.duty_zoho_payment_id];
    expect((await divisionZohoSync.check('import', importId)).counts.missing).toBe(1);
  });
});
