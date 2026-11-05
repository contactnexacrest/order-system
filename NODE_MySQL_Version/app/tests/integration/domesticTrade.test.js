'use strict';

// Real PDFs are not rendered under Jest (puppeteer is stubbed); the HTML each PDF is made from is checked instead.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const fs = require('fs');
const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const requestContext = require('../../src/helpers/requestContext');
const { formatIndian, format } = require('../../src/helpers/amountInWords');
const divisionRepository = require('../../src/repositories/divisionRepository');
const repo = require('../../src/repositories/domesticOrderRepository');
const fileStoreRepository = require('../../src/repositories/fileStoreRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const D = require('../../src/services/domesticOrderService');
const docs = require('../../src/services/domesticDocumentService');
const G = require('../../src/services/gstCalculator');
const orderAccessService = require('../../src/services/orderAccessService');
const zoho = require('../../src/services/zohoBooksService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of DomesticTradeTest.php. Owner decision D-257 Phase 2 (5 Nov 2026):
 * the Domestic Trade (DOM) division. DOM stays "planned" in the seed; this
 * test activates it inside the test only, to prove: while planned nothing
 * changes; one DOM order runs end to end with its four documents, GST maths
 * (CGST + SGST in the same state, IGST across states, paise rounding, round
 * off, HSN summary, amount in words in lakh), the e-way bill rule and the
 * D-212 payment description; Export and DOM never see each other's orders;
 * the Zoho payload and the Division tag; Admin › Divisions sets DOM up.
 */
describe('D-257 Phase 2 — Domestic Trade', () => {
  const DOM = 2;
  const BUYER_GSTIN = '29AAPFU0939F1ZR';
  const MH_GSTIN = `27AAPFU0939F1Z${G.gstinCheckChar('27AAPFU0939F1Z')}`;
  let md;
  let domStaff;
  let expStaff;
  let client;

  afterAll(async () => {
    await db.pool.end();
  });

  async function createUser(roleName, divisionId) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-dom-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const password = 'JestDomestic123!';
    const r = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled, division_id)
       VALUES ('Jest Domestic User', :email, NULL, :hash, :role_id, 1, 0, 0, :d)`,
      { email, hash: await bcrypt.hash(password, 10), role_id: role.id, d: divisionId }
    );
    return { id: r.insertId, email, password, division_id: divisionId };
  }

  async function loginAs(user) {
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: user.email, password: user.password });
    return agent;
  }

  /** Runs fn as a request of this user would (what sessionAuth sets up). */
  function as(user, fn) {
    const seesAll = user.division_id === null;
    return requestContext.run({ userId: user.id, divisionIds: seesAll ? null : [user.division_id], divisionSeesAll: seesAll, divisionUser: { division_id: user.division_id } }, fn);
  }

  async function activate() {
    await db.execute("UPDATE divisions SET status = 'active' WHERE id = :d", { d: DOM });
  }

  async function domOrder(placeOfSupply = '29', gstin = BUYER_GSTIN) {
    await activate();
    await db.execute('INSERT IGNORE INTO client_divisions (client_id, division_id) VALUES (:c, :d)', { c: client, d: DOM });
    const [orderId, error] = await as(domStaff, () => D.create({
      client_id: client, buyer_gstin: gstin, buyer_state_code: gstin ? gstin.slice(0, 2) : '29', place_of_supply: placeOfSupply,
      delivery_address: 'Plot 4, Peenya\nBengaluru 560058', payment_terms_days: '30', buyer_po_ref: 'PO/77',
    }, domStaff.id));
    expect(error).toBeNull();
    return orderId;
  }

  const step = (fn) => as(domStaff, fn);

  beforeEach(async () => {
    md = await createUser('Managing Director', null);
    domStaff = await createUser('Sales/Export Manager', DOM);
    expStaff = await createUser('Sales/Export Manager', 1);
    client = await createTestClient();
    await db.execute("UPDATE clients SET company_legal_name = 'Bengaluru Stone Traders', billing_address = :a WHERE id = :id", { a: '12 MG Road\nBengaluru 560001', id: client });
  });

  afterEach(async () => {
    await db.execute("UPDATE divisions SET status = 'planned' WHERE id <> 1");
    await db.execute('UPDATE users SET division_id = 1 WHERE division_id IS NOT NULL AND division_id <> 1');
    await db.execute('DELETE FROM client_divisions WHERE client_id = :c AND division_id <> 1', { c: client });
    zoho.setTransport(null);
  });

  it('GST maths: same state, other state, paise rounding, round off, HSN summary and words in lakh', () => {
    const lines = [
      { quantity: '10.5', rate_inr: '1999.99', gst_rate_pct: '18.00', hsn_code: '6802' },
      { quantity: '3', rate_inr: '333.33', gst_rate_pct: '12.00', hsn_code: '2515' },
      { quantity: '1', rate_inr: '0.05', gst_rate_pct: '18.00', hsn_code: '6802' },
    ];
    const intra = G.compute(lines, '29', '29');
    expect(intra.intra_state).toBe(true);
    expect([intra.lines[0].taxable, intra.lines[0].cgst, intra.lines[0].sgst, intra.lines[0].igst]).toEqual(['20999.90', '1889.99', '1889.99', '0.00']);
    expect(intra.lines[2].cgst).toBe('0.00');
    expect([intra.taxable_total, intra.cgst_total, intra.sgst_total, intra.igst_total]).toEqual(['21999.94', '1949.99', '1949.99', '0.00']);
    expect([intra.gross_total, intra.round_off, intra.invoice_total]).toEqual(['25899.92', '0.08', '25900.00']);
    expect(intra.hsn_summary).toHaveLength(2);
    expect([intra.hsn_summary[0].hsn_code, intra.hsn_summary[0].gst_rate_pct, intra.hsn_summary[0].taxable, intra.hsn_summary[0].tax_total]).toEqual(['6802', '18.00', '20999.95', '3779.98']);
    const inter = G.compute(lines, '29', '27');
    expect(inter.intra_state).toBe(false);
    expect([inter.lines[0].cgst, inter.lines[0].sgst, inter.lines[0].igst]).toEqual(['0.00', '0.00', '3779.98']);
    expect([inter.igst_total, inter.gross_total, inter.round_off, inter.invoice_total]).toEqual(['3899.99', '25899.93', '0.07', '25900.00']);
    const down = G.compute([{ quantity: '1', rate_inr: '85.08', gst_rate_pct: '18', hsn_code: '6802' }], '29', '27');
    expect([down.igst_total, down.gross_total, down.round_off, down.invoice_total]).toEqual(['15.31', '100.39', '-0.39', '100.00']);
    expect(G.isValidGstin(BUYER_GSTIN)).toBe(true);
    expect(G.isValidGstin('29AAPFU0939F1ZA')).toBe(false);
    expect(G.isValidGstin('29AAPFU0939F1Z')).toBe(false);
    expect(G.inr('12345678.5')).toBe('1,23,45,678.50');
    expect(formatIndian('25900.00')).toBe('Indian Rupees Twenty-Five Thousand Nine Hundred Only');
    expect(formatIndian('12345678.50')).toBe('Indian Rupees One Crore Twenty-Three Lakh Forty-Five Thousand Six Hundred Seventy-Eight and Fifty Paise Only');
    expect(format('1000000', 'USD')).toBe('US Dollars One Million Only');
  });

  it('while Domestic Trade is planned nothing changes', async () => {
    expect(await D.isActive()).toBe(false);
    const [id, error] = await as(md, () => D.create({ client_id: client, buyer_state_code: '29', place_of_supply: '29' }, md.id));
    expect(id).toBeNull();
    expect(error).toContain('not active');
    const agent = await loginAs(md);
    const page = (await agent.get('/domestic/orders/create')).text;
    expect(page).toContain('dom-not-active');
    expect(page).not.toContain('dom-create-form');
    expect((await agent.get(`/clients/${client}`)).text).not.toContain('client-gst');
    expect((await agent.get('/orders/create')).text).not.toMatch(/<option[^>]*>\s*INR\b/);
    const inr = await db.queryOne("SELECT id FROM currencies WHERE code = 'INR'");
    expect(inr).not.toBeNull();
    const before = (await db.queryOne('SELECT COUNT(*) AS n FROM orders')).n;
    const csrf = (await agent.get('/orders/create')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/orders').type('form').send({ _csrf: csrf, client_id: String(client), incoterm_id: '1', currency_id: String(inr.id), payment_preset_id: '1' });
    expect((await db.queryOne('SELECT COUNT(*) AS n FROM orders')).n).toBe(before);
    expect((await agent.get('/orders/create')).text).toContain('Orders in rupees belong to Domestic Trade');
    const labels = (await divisionRepository.preflight(DOM)).map((i) => i.label);
    expect(labels).toContain('GST rates set (confirm them with the CA)');
    expect(labels).toContain("NexaCrest's GSTIN is valid (it gives the GST state)");
  });

  it('one domestic order end to end', async () => {
    const orderId = await domOrder();
    const order = await orderRepository.find(orderId);
    expect(Number(order.division_id)).toBe(DOM);
    expect(order.currency_code).toBe('INR');
    expect(String(order.order_reference).startsWith('DOM/')).toBe(true);
    expect((await repo.client(client)).gstin).toBe(BUYER_GSTIN);

    expect(await D.blockers(orderId)).toEqual(['Add at least one line.']);
    expect(await step(() => D.addLine(orderId, { description: 'Granite', hsn_code: '68', quantity: '1', unit: 'SQM', rate_inr: '10' }, domStaff.id))).toBe('Enter the HSN code (4 to 8 digits).');
    expect(await step(() => D.addLine(orderId, { description: 'Black granite slabs, polished, 18 mm', hsn_code: '68029300', quantity: '120.5', unit: 'sqm', rate_inr: '1450' }, domStaff.id))).toBeNull();
    expect(await step(() => D.addLine(orderId, { description: 'Edge polishing', hsn_code: '99887766', quantity: '1', unit: 'LOT', rate_inr: '2500', gst_rate_pct: '12' }, domStaff.id))).toBeNull();
    expect((await repo.lines(orderId)).map((l) => l.gst_rate_pct)).toEqual(['18.00', '12.00']);
    expect(await step(() => D.advance(orderId, 'enquiry', domStaff.id))).toBeNull();
    expect(await step(() => D.advance(orderId, 'enquiry', domStaff.id))).toBe('Someone has already moved this order on. The page shows where it is now.');

    expect(await D.blockers(orderId)).toEqual(['Generate the quotation.']);
    let [, err] = await step(() => docs.generate(orderId, 'GTI', domStaff.id));
    expect(err).toContain('from the Dispatch stage on');
    let qtFile;
    [qtFile, err] = await step(() => docs.generate(orderId, 'DQT', domStaff.id));
    expect(err).toBeNull();
    expect((await repo.latestDocument(orderId, 'DQT')).reference).toMatch(/^DOM\/QT\/\d{2}-\d{2}\/\d{4}$/);
    expect(await step(() => D.advance(orderId, 'quotation', domStaff.id))).toBeNull();

    [, err] = await step(() => docs.generate(orderId, 'DOC', domStaff.id));
    expect(err).toBeNull();
    expect(await step(() => D.advance(orderId, 'order_confirmation', domStaff.id))).toBeNull();
    expect(await D.blockers(orderId)).toEqual(['Record the supplier PO.']);
    expect(await step(() => D.savePurchase(orderId, { supplier_name: 'Sri Balaji Granites', supplier_po_ref: 'NC/SPO/45' }, domStaff.id))).toBeNull();
    expect(await step(() => D.advance(orderId, 'purchase', domStaff.id))).toBeNull();

    const dom = await repo.find(orderId);
    expect(dom.tax_invoice_no).toMatch(/^DOM\/\d{2}-\d{2}\/\d{4}$/);
    expect(dom.tax_invoice_no.length).toBeLessThanOrEqual(16);
    expect([dom.taxable_total, dom.cgst_total, dom.sgst_total, dom.igst_total, dom.round_off, dom.invoice_total]).toEqual(['177225.00', '15875.25', '15875.25', '0.00', '0.50', '208976.00']);
    expect(await step(() => D.addLine(orderId, { description: 'x', hsn_code: '6802', quantity: '1', unit: 'PCS', rate_inr: '1' }, domStaff.id))).toContain('lines can no longer change');
    expect(await D.needsEwayBill(dom)).toBe(true);
    expect(await D.blockers(orderId)).toContain('Enter the e-way bill number: the consignment is above ₹50,000.00.');
    const today = require('../../src/helpers/dbDateTime').todayIst();
    expect(await step(() => D.saveDispatch(orderId, { dispatch_date: today, transporter_name: 'VRL', vehicle_no: '1234' }, domStaff.id))).toBe('Enter the vehicle number (e.g. KA01AB1234).');
    expect(await step(() => D.saveDispatch(orderId, { dispatch_date: today, transporter_name: 'VRL Logistics', vehicle_no: 'ka 01 ab 1234', lr_no: 'LR-1', eway_bill_no: '331012345678', eway_bill_date: today }, domStaff.id))).toBeNull();
    let gtiFile;
    [gtiFile, err] = await step(() => docs.generate(orderId, 'GTI', domStaff.id));
    expect(err).toBeNull();
    [, err] = await step(() => docs.generate(orderId, 'DCH', domStaff.id));
    expect(err).toBeNull();
    expect((await repo.latestDocument(orderId, 'GTI')).reference).toBe(dom.tax_invoice_no);

    const html = await docs.html(orderId, 'GTI', dom.tax_invoice_no);
    for (const needle of ['TAX INVOICE', dom.tax_invoice_no, 'CGST', 'SGST', 'HSN SUMMARY', '68029300', '1,74,725.00', '2,08,976.00',
      'Indian Rupees Two Lakh Eight Thousand Nine Hundred Seventy-Six Only', BUYER_GSTIN, 'Karnataka (29)', '331012345678', 'KA01AB1234', 'Tax payable under reverse charge: No.']) {
      expect(html).toContain(needle);
    }
    expect(html).not.toContain('IGST (₹)');
    expect(html).not.toContain('LUT');
    expect(await docs.html(orderId, 'DCH', 'DOM/DC/26-27/0001')).toContain('DELIVERY CHALLAN');
    expect(fs.readFileSync((await fileStoreRepository.find(gtiFile)).server_path).slice(0, 4).toString()).toBe('%PDF');
    expect(await fileStoreRepository.moneyClassFor(qtFile)).toBe('selling');
    expect(await step(() => D.advance(orderId, 'dispatch', domStaff.id))).toBeNull();

    expect(await D.blockers(orderId)).toEqual(['Mark the tax invoice as sent to the buyer.']);
    expect(await step(() => D.markInvoiceSent(orderId, today, domStaff.id))).toBeNull();
    expect(await step(() => D.advance(orderId, 'tax_invoice', domStaff.id))).toBeNull();
    expect(await step(() => D.recordPayment(orderId, { received_on: today, amount_inr: '100000' }, domStaff.id))).toBe('Enter the bank reference (UTR / NEFT / RTGS / cheque number).');
    expect(await step(() => D.recordPayment(orderId, { received_on: today, amount_inr: '100000', bank_reference: 'UTR SBIN226000111', note: 'part payment' }, domStaff.id))).toBeNull();
    expect(await step(() => D.recordPayment(orderId, { received_on: today, amount_inr: '5', bank_reference: 'UTR SBIN226000111' }, domStaff.id))).toBe('This bank reference is already recorded. Check the payment is not entered twice.');
    expect((await repo.payments(orderId))[0].description).toBe(`Domestic sale receipt of ₹1,00,000.00 from Bengaluru Stone Traders against GST tax invoice ${dom.tax_invoice_no} (order ${order.order_reference}), bank ref UTR SBIN226000111; note: part payment`);
    expect(await D.blockers(orderId)).toEqual(['Still to receive: ₹1,08,976.00.']);
    expect(await step(() => D.recordPayment(orderId, { received_on: today, amount_inr: '108975.50', bank_reference: 'UTR SBIN226000222' }, domStaff.id))).toBeNull();
    expect(await D.blockers(orderId)).toEqual([]);
    expect(await step(() => D.advance(orderId, 'payment', domStaff.id))).toBeNull();
    expect((await repo.find(orderId)).stage).toBe('closed');
    expect((await orderRepository.find(orderId)).status).toBe('complete');

    const page = (await (await loginAs(domStaff)).get(`/orders/${orderId}`)).text;
    expect(page).toContain('data-testid="dom-order"');
    expect(page).toContain('Domestic sale receipt of ₹1,00,000.00');
  }, 120000);

  it('another state is IGST, and an unregistered buyer waits for its GSTIN', async () => {
    const orderId = await domOrder('27', null);
    await step(() => D.addLine(orderId, { description: 'Marble tiles', hsn_code: '6802', quantity: '100', unit: 'SQM', rate_inr: '400' }, domStaff.id));
    const t = await D.totals(orderId);
    expect(t.intra_state).toBe(false);
    expect([t.taxable_total, t.igst_total, t.invoice_total]).toEqual(['40000.00', '7200.00', '47200.00']);
    const html = await docs.html(orderId, 'DQT', 'DOM/QT/26-27/0009');
    expect(html).toContain('IGST');
    expect(html).toContain('Maharashtra (27)');
    expect(html).toContain('Unregistered');
    await step(() => D.advance(orderId, 'enquiry', domStaff.id));
    await step(() => docs.generate(orderId, 'DQT', domStaff.id));
    await step(() => D.advance(orderId, 'quotation', domStaff.id));
    expect(await D.blockers(orderId)).toContain("Enter the buyer's GSTIN (a reseller buys on its GST registration).");
    expect(await step(() => D.saveParty(orderId, { buyer_gstin: BUYER_GSTIN, buyer_state_code: '27', place_of_supply: '27' }, domStaff.id))).toContain('does not match');
    expect(await step(() => D.saveParty(orderId, { buyer_gstin: MH_GSTIN, buyer_state_code: '27', place_of_supply: '27' }, domStaff.id))).toBeNull();
    expect(await D.blockers(orderId)).not.toContain("Enter the buyer's GSTIN (a reseller buys on its GST registration).");
    expect(await D.needsEwayBill({ invoice_total: '50000.00' })).toBe(false);
    expect(await D.needsEwayBill({ invoice_total: '50000.01' })).toBe(true);
  }, 60000);

  it('Export and Domestic Trade never see each other\'s orders', async () => {
    const dOrder = await domOrder();
    const expClient = await createTestClient();
    const eOrder = await createTestOrder(expClient);
    await db.execute('INSERT IGNORE INTO order_assignees (order_id, user_id, assigned_by) VALUES (:o, :u, :u2)', { o: eOrder, u: domStaff.id, u2: md.id });
    await db.execute('INSERT IGNORE INTO order_assignees (order_id, user_id, assigned_by) VALUES (:o, :u, :u2)', { o: dOrder, u: expStaff.id, u2: md.id });

    const expAgent = await loginAs(expStaff);
    expect((await expAgent.get(`/orders/${dOrder}`)).status).toBe(403);
    expect((await expAgent.get('/orders')).text).not.toContain((await orderRepository.find(dOrder)).order_reference);
    const csrf = (await expAgent.get('/')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    const r = await expAgent.post(`/domestic/orders/${dOrder}/lines`).type('form').send({ _csrf: csrf, description: 'x', hsn_code: '6802', quantity: '1', unit: 'PCS', rate_inr: '1' });
    expect([403, 404]).toContain(r.status);
    expect(await repo.lines(dOrder)).toEqual([]);

    const domAgent = await loginAs(domStaff);
    expect((await domAgent.get(`/orders/${eOrder}`)).status).toBe(403);
    const list = (await domAgent.get('/orders')).text;
    expect(list).toContain((await orderRepository.find(dOrder)).order_reference);
    expect(list).not.toContain((await orderRepository.find(eOrder)).order_reference);
    const mdAgent = await loginAs(md);
    const mdCsrf = (await mdAgent.get('/')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    const notDom = await mdAgent.post(`/domestic/orders/${eOrder}/lines`).type('form').send({ _csrf: mdCsrf, description: 'x', hsn_code: '6802', quantity: '1', unit: 'PCS', rate_inr: '1' });
    expect(notDom.status).toBe(404);
    expect(await as(expStaff, async () => orderAccessService.denialReason(await orderRepository.find(dOrder), expStaff.id, { view_all_orders: true }))).not.toBeNull();
  }, 60000);

  it('the Zoho invoice payload, and the Division tag only with two active divisions', async () => {
    const orderId = await domOrder('27', MH_GSTIN);
    await step(() => D.addLine(orderId, { description: 'Marble tiles', hsn_code: '6802', quantity: '10', unit: 'SQM', rate_inr: '400' }, domStaff.id));
    for (const stage of ['enquiry', 'quotation', 'order_confirmation', 'purchase']) {
      if (stage === 'quotation') await step(() => docs.generate(orderId, 'DQT', domStaff.id));
      if (stage === 'order_confirmation') await step(() => docs.generate(orderId, 'DOC', domStaff.id));
      if (stage === 'purchase') await step(() => D.savePurchase(orderId, { supplier_name: 'S', supplier_po_ref: 'P1' }, domStaff.id));
      expect(await step(() => D.advance(orderId, stage, domStaff.id))).toBeNull();
    }
    const dom = await repo.find(orderId);
    let payload = await zoho.domesticInvoicePayload(orderId, 'C-1', async (name) => `T-${name}`);
    expect(payload.gst_treatment).toBe('business_gst');
    expect(payload.gst_no).toBe(MH_GSTIN);
    expect(payload.place_of_supply).toBe('MH');
    expect(payload.invoice_number).toBe(dom.tax_invoice_no);
    expect(payload.line_items[0].tax_id).toBe('T-IGST18');
    expect(payload.line_items[0].hsn_or_sac).toBe('6802');
    expect(payload.notes).toBe(`Domestic GST tax invoice ${dom.tax_invoice_no} for order ${(await orderRepository.find(orderId)).order_reference}, Bengaluru Stone Traders, place of supply Maharashtra.`);
    expect(payload.tags).toBeUndefined();
    await db.execute(`INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive) VALUES
      ('zoho_division_tag_id', 'TAG-1', 'string', 'zoho', 'test', 0), ('zoho_division_tag_option_dom', 'OPT-DOM', 'string', 'zoho', 'test', 0)
      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)`);
    try {
      payload = await zoho.domesticInvoicePayload(orderId, 'C-1', async (name) => `T-${name}`);
      expect(payload.tags).toEqual([{ tag_id: 'TAG-1', tag_option_id: 'OPT-DOM' }]);
      await db.execute("UPDATE divisions SET status = 'planned' WHERE id = :d", { d: DOM });
      expect(await zoho.divisionTags(1)).toEqual([]);
    } finally {
      await db.execute("DELETE FROM company_settings WHERE setting_key IN ('zoho_division_tag_id', 'zoho_division_tag_option_dom')");
    }
  }, 60000);

  it('Admin › Divisions sets Domestic Trade up', async () => {
    const agent = await loginAs(md);
    const csrf = (await agent.get(`/admin/divisions/${DOM}`)).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post(`/admin/divisions/${DOM}/starter-departments`).type('form').send({ _csrf: csrf });
    const made = await db.query("SELECT code, division_id FROM departments WHERE code IN ('DOM_SALES','DOM_PURCHASE','DOM_DISPATCH') ORDER BY code");
    expect(made.map((d) => d.code)).toEqual(['DOM_DISPATCH', 'DOM_PURCHASE', 'DOM_SALES']);
    expect(made.map((d) => Number(d.division_id))).toEqual([DOM, DOM, DOM]);
    await agent.post(`/admin/divisions/${DOM}/staff`).type('form').send({ _csrf: csrf, user_id: String(expStaff.id) });
    expect(Number((await db.queryOne('SELECT division_id FROM users WHERE id = :id', { id: expStaff.id })).division_id)).toBe(DOM);
    await agent.post(`/admin/divisions/${DOM}/staff`).type('form').send({ _csrf: csrf, user_id: String(md.id) });
    expect((await db.queryOne('SELECT division_id FROM users WHERE id = :id', { id: md.id })).division_id).toBeNull();
    const page = (await agent.get(`/admin/divisions/${DOM}`)).text;
    expect(page).toContain('division-setup');
    expect(page).toContain('/admin/gst-rates');
    const checks = Object.fromEntries((await divisionRepository.preflight(DOM)).map((i) => [i.label, i.ok]));
    expect(checks['At least one department works in this division']).toBe(true);
    expect(checks['At least one active staff member works in this division']).toBe(true);
    expect(checks['Export-only modules switched off (export shipping, LUT, benefits)']).toBe(true);
    await db.execute("DELETE FROM departments WHERE code IN ('DOM_SALES','DOM_PURCHASE','DOM_DISPATCH')");
  });
});
