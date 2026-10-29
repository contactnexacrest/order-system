'use strict';

const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const orderZohoInvoiceRepository = require('../../src/repositories/orderZohoInvoiceRepository');
const caSyncService = require('../../src/services/caSyncService');
const zohoBooksService = require('../../src/services/zohoBooksService');
const { createTestClient, createTestOrder, createTestDocument } = require('../support/fixtures');

/**
 * Port of ZohoBooksStage2Test.php. Zoho integration Stage 2 (2026-10-29):
 * the Commercial Invoice and the freight Debit Note go to Zoho Books as
 * export invoices numbered with our own references, and the buyer's
 * payments are applied to them — the advance held unapplied until the CI
 * is in Books. Every request goes to a fake Zoho that keeps the invoices
 * and payments it is sent; the tests never reach the real one.
 */
describe('Zoho Books Stage 2', () => {
  let requests;
  let contacts;
  let invoices;
  let payments;
  let gstRegistered;
  let invoiceWrites500;

  function storedInvoice(id, payload, status) {
    const total = payload.line_items.reduce((sum, line) => sum + Math.round(line.quantity * line.rate * 100) / 100, 0);
    return { invoice_id: id, invoice_number: payload.invoice_number, customer_id: payload.customer_id, status, total, payload };
  }

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
    const json = (body, status = 200) => ({ status, body: JSON.stringify(body) });
    let m;

    if (p === '/oauth/v2/token') return json({ access_token: 'fake-access' });
    if ((m = p.match(/^\/books\/v3\/organizations\/(\d+)$/))) return json({ code: 0, organization: { organization_id: m[1], is_registered_for_gst: gstRegistered } });
    if (p === '/books/v3/settings/currencies') return json({ code: 0, currencies: [{ currency_id: 'C-USD', currency_code: 'USD' }, { currency_id: 'C-INR', currency_code: 'INR' }] });
    if (p === '/books/v3/contacts' && method === 'GET') return json({ code: 0, contacts: Object.values(contacts).filter((c) => c.contact_name === u.searchParams.get('contact_name')) });
    if (p === '/books/v3/contacts' && method === 'POST') {
      const id = `CT-${Object.keys(contacts).length + 1}`;
      contacts[id] = { contact_id: id, contact_name: payload.contact_name, currency_code: 'USD' };
      return json({ code: 0, contact: contacts[id] }, 201);
    }
    if ((m = p.match(/^\/books\/v3\/contacts\/(.+)$/))) return json({ code: 0, contact: contacts[m[1]] });
    if (p === '/books/v3/invoices' && method === 'GET') {
      const number = u.searchParams.get('invoice_number');
      const rows = Object.values(invoices).filter((i) => number === null || i.invoice_number === number);
      return json({ code: 0, invoices: rows.map((i) => ({ ...i, balance: invoiceBalance(i.invoice_id) })) });
    }
    if (p === '/books/v3/invoices' && method === 'POST') {
      const id = `INV-${Object.keys(invoices).length + 1}`;
      invoices[id] = storedInvoice(id, payload, 'draft');
      return invoiceWrites500 ? { status: 500, body: `{"code":0,"message":"The invoice has been created.","invoice":{"invoice_id":"${id}` } : json({ code: 0, invoice: invoices[id] }, 201);
    }
    if ((m = p.match(/^\/books\/v3\/invoices\/([^/]+)\/status\/sent$/))) {
      invoices[m[1]].status = 'sent';
      return json({ code: 0, message: 'Invoice status has been changed to Sent.' });
    }
    if ((m = p.match(/^\/books\/v3\/invoices\/([^/]+)$/)) && method === 'PUT') {
      if (invoices[m[1]].status !== 'draft' && !payload.reason) return json({ code: 110701, message: 'Please enter the reason for updating a sent invoice.' }, 400);
      invoices[m[1]] = storedInvoice(m[1], payload, invoices[m[1]].status);
      return json({ code: 0, invoice: invoices[m[1]] });
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
    return json({ code: 5, message: `fake Zoho has no ${method} ${p}` }, 400);
  }

  const requestsTo = (method, prefix) => requests.filter((r) => r.method === method && new URL(r.url).pathname.startsWith(prefix));

  // A TEST- CIF order in USD: two products (FOB 1,275.00), freight 200 +
  // insurance 15, the advance of 300 received at 84.10.
  async function createCifOrder(buyer = 'TEST-Stage2 Buyer') {
    const clientId = await createTestClient();
    await db.execute('UPDATE clients SET company_legal_name = :name WHERE id = :id', { name: buyer, id: clientId });
    const orderId = await createTestOrder(clientId, 'CIF');
    await db.execute("UPDATE orders SET buyer_inquiry_ref = :ref, port_of_loading_name = 'Chennai', port_of_discharge_name = 'Hamburg' WHERE id = :id", { ref: `NX-${orderId}`, id: orderId });
    const insert = 'INSERT INTO order_products (order_id, line_no, description, dimensions, finish, quantity, unit, unit_price, fob_value, hs_code) VALUES (:order_id, :line_no, :description, :dimensions, :finish, :quantity, :unit, :unit_price, :fob_value, :hs_code)';
    await db.execute(insert, { order_id: orderId, line_no: 1, description: 'Granite slab', dimensions: '60x60x2 cm', finish: 'Polished', quantity: 100, unit: 'SQM', unit_price: 10, fob_value: 1000, hs_code: '68022310' });
    await db.execute(insert, { order_id: orderId, line_no: 2, description: 'Sandstone tile', dimensions: null, finish: null, quantity: 50, unit: 'SQM', unit_price: 5.5, fob_value: 275, hs_code: '68029900' });
    await db.execute('INSERT INTO order_freight (order_id, confirmed_freight_rate, insurance_amount) VALUES (:id, 200, 15)', { id: orderId });
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:id)', { id: orderId });
    await db.execute(`UPDATE order_payment_status SET advance_amount = 300, advance_cleared_at = '2026-10-20 11:00:00', advance_inr_actual = 25230, advance_exchange_rate = 84.1,
                      balance_exchange_rate = 84.5, freight_exchange_rate = 84.3 WHERE order_id = :id`, { id: orderId });
    return orderId;
  }

  async function approveDocument(orderId, typeCode, reference, revision = 0, documentDate = null) {
    const id = await createTestDocument(orderId, typeCode, 'approved');
    await db.execute('UPDATE documents SET document_reference = :ref, revision_number = :rev, generated_at = :gen, document_date = :date WHERE id = :id',
      { ref: reference, rev: revision, gen: '2026-10-27 10:00:00', date: documentDate, id });
    return id;
  }

  const sync = () => caSyncService.syncPendingRevenue('manual', null);
  const logRows = (orderId) => db.query('SELECT sync_type, leg, status, message FROM zoho_sync_log WHERE entity_id = :id ORDER BY id', { id: orderId });
  const invoiceNamed = (number) => Object.values(invoices).find((i) => i.invoice_number === number);

  beforeEach(async () => {
    requests = [];
    contacts = {};
    invoices = {};
    payments = {};
    gstRegistered = false;
    invoiceWrites500 = false;
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
    // orders must not be synced into this test's fake Zoho.
    await db.execute("UPDATE orders SET company_legal_name = CONCAT('OLD-', company_legal_name) WHERE company_legal_name LIKE 'TEST-%'");
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

  it('holds the advance until the CI, then applies it to it', async () => {
    const orderId = await createCifOrder();

    expect(await sync()).toEqual({ synced: 1, failed: 0, skipped: 0, invoices: 0, applied: 0 });
    expect(payments['PAY-1']).not.toHaveProperty('invoices'); // no CI yet: the advance is held unapplied
    expect((await logRows(orderId)).pop().message).toContain('held unapplied until the Commercial Invoice is in Zoho Books');

    await approveDocument(orderId, 'CI', 'TEST-SC/CI/2026/2810001', 0, '2026-10-28');
    expect(await sync()).toEqual({ synced: 0, failed: 0, skipped: 0, invoices: 1, applied: 1 });

    const invoice = invoices['INV-1'];
    expect(invoice.status).toBe('sent'); // marked as sent so payments can be applied
    expect(invoice.total).toBe(1275); // the CI is the FOB value — freight is on the Debit Note
    expect(invoice.payload).toEqual({
      customer_id: 'CT-1',
      invoice_number: 'TEST-SC/CI/2026/2810001',
      date: '2026-10-28',
      reference_number: `NX-${orderId}`,
      line_items: [
        { name: 'Granite slab', description: 'Granite slab. Size: 60x60x2 cm. Finish: Polished. HS code 68022310', quantity: 100, rate: 10, unit: 'SQM' },
        { name: 'Sandstone tile', description: 'Sandstone tile. HS code 68029900', quantity: 50, rate: 5.5, unit: 'SQM' },
      ],
      exchange_rate: 84.5,
    });
    expect(requestsTo('POST', '/books/v3/invoices')[0].url).toContain('ignore_auto_number_generation=true');
    expect(payments['PAY-1'].invoices).toEqual([{ invoice_id: 'INV-1', amount_applied: 300 }]);
    expect(payments['PAY-1'].amount).toBe(300);
    expect(payments['PAY-1'].exchange_rate).toBe(84.1);
    expect(invoiceBalance('INV-1')).toBe(975);
    const row = await db.queryOne('SELECT advance_zoho_applied_at FROM order_payment_status WHERE order_id = :id', { id: orderId });
    expect(row.advance_zoho_applied_at).not.toBeNull();

    expect(await sync()).toEqual({ synced: 0, failed: 0, skipped: 1, invoices: 0, applied: 0 }); // nothing is sent twice
  });

  it('applies the balance to the CI straight away and the freight to the Debit Note', async () => {
    const orderId = await createCifOrder();
    await approveDocument(orderId, 'CI', 'TEST-SC/CI/2026/2810002');
    await approveDocument(orderId, 'FDN', 'TEST-SC/DN/2026/2710001');
    await db.execute(`UPDATE order_payment_status SET balance_amount = 975, balance_cleared_at = '2026-11-20 10:00:00', balance_inr_actual = 82615.5,
      freight_amount = 215, freight_cleared_at = '2026-10-27 15:00:00', freight_inr_actual = 18124.5 WHERE order_id = :id`, { id: orderId });

    expect(await sync()).toEqual({ synced: 3, failed: 0, skipped: 0, invoices: 2, applied: 3 });

    const debitNote = invoiceNamed('TEST-SC/DN/2026/2710001');
    expect(debitNote.total).toBe(215);
    expect(debitNote.payload.line_items).toEqual([
      { name: 'Ocean freight', description: 'Ocean freight, Chennai to Hamburg (CIF)', quantity: 1, rate: 200 },
      { name: 'Marine insurance', description: 'Marine insurance (CIF)', quantity: 1, rate: 15 },
    ]);
    expect(debitNote.payload.exchange_rate).toBe(84.3);
    expect(debitNote.payload.date).toBe('2026-10-27');
    expect(invoiceBalance(debitNote.invoice_id)).toBe(0);
    expect(invoiceBalance(invoiceNamed('TEST-SC/CI/2026/2810002').invoice_id)).toBe(0); // advance 300 + balance 975 = the CI
  });

  it('a revised CI updates the same Books invoice', async () => {
    const orderId = await createCifOrder();
    await approveDocument(orderId, 'CI', 'TEST-SC/CI/2026/2810003');
    await sync();
    await db.execute('UPDATE order_products SET quantity = 110, fob_value = 1100 WHERE order_id = :id AND line_no = 1', { id: orderId });
    await approveDocument(orderId, 'CI', 'TEST-SC/CI/2026/2810003', 1);

    expect((await sync()).invoices).toBe(1);
    expect(Object.keys(invoices)).toHaveLength(1); // no second invoice
    expect(invoices['INV-1'].total).toBe(1375);
    expect(requestsTo('PUT', '/books/v3/invoices/INV-1')).toHaveLength(1);
    expect(invoices['INV-1'].payload.reason).toBe('Revised in the order system: Commercial Invoice TEST-SC/CI/2026/2810003 revision 1 approved.'); // Books needs a reason to change a sent invoice
    expect((await logRows(orderId)).pop().message).toMatch(/^Updated Commercial Invoice TEST-SC\/CI\/2026\/2810003/);
  });

  it('a draft revision is ignored until approved', async () => {
    const orderId = await createCifOrder();
    await approveDocument(orderId, 'CI', 'TEST-SC/CI/2026/2810004');
    await sync();
    const draft = await createTestDocument(orderId, 'CI', 'in_review');
    await db.execute("UPDATE documents SET revision_number = 1, document_reference = 'TEST-SC/CI/2026/2810004' WHERE id = :id", { id: draft });
    await db.execute('UPDATE order_products SET unit_price = 11, fob_value = 1100 WHERE order_id = :id AND line_no = 1', { id: orderId });

    await sync();
    expect(requestsTo('PUT', '/books/v3/invoices/')).toHaveLength(0); // Books keeps the approved version until the revision is approved

    await db.execute("UPDATE documents SET status = 'approved' WHERE id = :id", { id: draft });
    await sync();
    expect(invoices['INV-1'].total).toBe(1375); // approved: now it goes
  });

  it('a CI flagged as possibly out of date is not sent', async () => {
    const orderId = await createCifOrder();
    const documentId = await approveDocument(orderId, 'CI', 'TEST-SC/CI/2026/2810011');
    await db.execute('UPDATE documents SET drift_flagged_at = NOW() WHERE id = :id', { id: documentId });

    await sync();

    expect(invoices).toEqual({});
    const rows = (await logRows(orderId)).filter((r) => r.sync_type === 'invoice');
    expect(rows[0].message).toBe('Commercial Invoice not sent: TEST-SC/CI/2026/2810011 is flagged on the order page as possibly out of date (an earlier document was regenerated after it). Generate and approve a revised Commercial Invoice first.');
  });

  it('an invoice created despite an HTTP 500 is found and not duplicated', async () => {
    const orderId = await createCifOrder();
    await approveDocument(orderId, 'CI', 'TEST-SC/CI/2026/2810005');
    invoiceWrites500 = true;

    expect((await sync()).invoices).toBe(1);
    expect((await orderZohoInvoiceRepository.find(orderId, 'ci')).zoho_invoice_id).toBe('INV-1');
    expect(Object.keys(invoices)).toHaveLength(1);
  });

  it('a GST-registered organisation gets HSN and overseas treatment', async () => {
    gstRegistered = true;
    const orderId = await createCifOrder();
    await approveDocument(orderId, 'CI', 'TEST-SC/CI/2026/2810006');

    await sync();

    const { payload } = invoices['INV-1'];
    expect(payload.gst_treatment).toBe('overseas');
    expect(payload.line_items[0].hsn_or_sac).toBe('68022310');
    expect(payload.line_items[0].description).toBe('Granite slab. Size: 60x60x2 cm. Finish: Polished');
  });

  it('an invoice number used by another customer is refused', async () => {
    const orderId = await createCifOrder();
    await approveDocument(orderId, 'CI', 'TEST-SC/CI/2026/2810007');
    invoices['INV-X'] = { invoice_id: 'INV-X', invoice_number: 'TEST-SC/CI/2026/2810007', customer_id: 'CT-OTHER', customer_name: 'Someone Else', status: 'sent', total: 5 };

    expect((await sync()).failed).toBe(1);
    expect(requestsTo('POST', '/books/v3/invoices')).toHaveLength(0);
    const rows = (await logRows(orderId)).filter((r) => r.sync_type === 'invoice');
    expect(rows[0].message).toBe('Invoice number TEST-SC/CI/2026/2810007 is already used in Zoho Books for Someone Else. Nothing was changed.');
  });

  it('refuses not-ready cases with a message staff can act on', async () => {
    const orderId = await createCifOrder();
    await approveDocument(orderId, 'CI', 'TEST-SC/CI/2026/2810008');
    await db.execute('UPDATE order_payment_status SET balance_exchange_rate = NULL, advance_exchange_rate = NULL WHERE order_id = :id', { id: orderId });

    await sync();
    let messages = (await logRows(orderId)).filter((r) => r.sync_type === 'invoice').map((r) => r.message);
    expect(messages).toEqual(['Commercial Invoice not sent: no exchange rate is recorded for the balance or advance leg. Set it in CA / Accounting -> INR Settlement Register.']);

    await db.execute('UPDATE order_payment_status SET balance_exchange_rate = 84.5 WHERE order_id = :id', { id: orderId });
    await db.execute('UPDATE order_products SET quantity_is_tbc = 1 WHERE order_id = :id AND line_no = 2', { id: orderId });
    await sync();
    messages = (await logRows(orderId)).filter((r) => r.sync_type === 'invoice').map((r) => r.message);
    expect(messages.pop()).toBe('Commercial Invoice not sent: line 2 has no confirmed quantity or unit price.');
    expect(invoices).toEqual({});
  });

  it('only TEST- customers in test, and no test or sample orders in production', async () => {
    const real = await createCifOrder('Real Buyer GmbH');
    await approveDocument(real, 'CI', 'SC/CI/2026/2810009');
    const test = await createCifOrder();
    await approveDocument(test, 'CI', 'TEST-SC/CI/2026/2810010');

    await sync();
    expect(Object.values(invoices).map((i) => i.invoice_number)).toEqual(['TEST-SC/CI/2026/2810010']); // test: only TEST- customers

    process.env.ZOHO_ENVIRONMENT = 'production';
    await db.execute('UPDATE orders SET is_test_data = 1 WHERE id = :id', { id: real });
    try {
      expect((await orderZohoInvoiceRepository.documentsToSync()).filter((d) => d.order_id === real)).toEqual([]); // production: test orders never go
    } finally {
      // Test data left behind keeps Test Mode from being switched off in later tests.
      await db.execute('UPDATE orders SET is_test_data = 0 WHERE id = :id', { id: real });
    }
  });
});
