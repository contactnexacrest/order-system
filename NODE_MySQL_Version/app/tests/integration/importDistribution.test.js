'use strict';

// Real PDFs are not rendered under Jest (puppeteer is stubbed); the HTML each PDF is made from is checked instead.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const requestContext = require('../../src/helpers/requestContext');
const divisionRepository = require('../../src/repositories/divisionRepository');
const domRepo = require('../../src/repositories/domesticOrderRepository');
const repo = require('../../src/repositories/importRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const uiTemplateRepository = require('../../src/repositories/uiTemplateRepository');
const userRepository = require('../../src/repositories/userRepository');
const divisionService = require('../../src/services/divisionService');
const D = require('../../src/services/domesticOrderService');
const docs = require('../../src/services/domesticDocumentService');
const I = require('../../src/services/importService');
const importDocs = require('../../src/services/importDocumentService');
const zoho = require('../../src/services/zohoBooksService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of ImportDistributionTest.php. Owner decision D-257 Phase 3 (5 Nov
 * 2026): the Import & Distribution (IMP) division. IMP stays "planned" in
 * the seed; this test activates it inside the test only, to prove: while
 * planned nothing changes; the landed-cost and duty maths; one import end to
 * end and a local sale out of that stock under IMP's own numbers; stock
 * never goes below zero, even with two issues at the same moment; Export and
 * IMP never see each other's records; the Zoho payloads (mocked); the list
 * and the board offer the same.
 */
describe('D-257 Phase 3 — Import & Distribution', () => {
  const IMP = 4;
  const BUYER_GSTIN = '29AAPFU0939F1ZR';
  let md;
  let impStaff;
  let expStaff;
  let client;
  let productA;
  let productB;

  afterAll(async () => {
    await db.pool.end();
  });

  async function createUser(roleName, divisionId) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-imp-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const password = 'JestImport123!';
    const r = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled, division_id)
       VALUES ('Jest Import User', :email, NULL, :hash, :role_id, 1, 0, 0, :d)`,
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
    await db.execute("UPDATE divisions SET status = 'active' WHERE id = :d", { d: IMP });
  }

  const step = (fn) => as(impStaff, fn);
  const daysAgo = (n) => {
    const d = new Date();
    d.setDate(d.getDate() - n);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  };

  /** An import with two lines (USD), as IMP staff. */
  async function importWithLines() {
    await activate();
    const [id, error] = await step(() => I.create({
      supplier_name: 'TEST-Xiamen Stone Industries', supplier_country: 'China', supplier_address: "No. 8 Shuitou Road\nNan'an, Fujian",
      currency_code: 'USD', incoterm: 'CIF', po_date: daysAgo(20), payment_terms: '30% advance, balance against BL',
    }, impStaff.id));
    expect(error).toBeNull();
    expect(await step(() => I.addLine(id, { product_id: productA, quantity: '100', unit: 'sqm', unit_price_fc: '18.50', bcd_pct: '20', sws_pct: '10', igst_pct: '18' }, impStaff.id))).toBeNull();
    expect(await step(() => I.addLine(id, { product_id: productB, description: 'Quartz worktops', quantity: '3', unit: 'PCS', unit_price_fc: '333.33', bcd_pct: '10', sws_pct: '10', igst_pct: '18' }, impStaff.id))).toBeNull();
    return id;
  }

  /** Takes an import to "In stock" (cleared at 84.65 with charges). */
  async function receive(id) {
    await I.markPoGenerated(id, impStaff.id);
    expect(await step(() => I.saveShipment(id, { bl_no: 'COSU6234567890', bl_date: daysAgo(15), vessel: 'COSCO Leo', port_of_loading: 'Xiamen' }, impStaff.id))).toBeNull();
    expect(await step(() => I.advance(id, 'ordered', impStaff.id))).toBeNull();
    expect(await step(() => I.saveClearance(id, {
      boe_no: '4567891', boe_date: daysAgo(3), port_code: 'innsa1', cha_name: 'Speedways Clearing', customs_fx_rate: '84.65', clearance_date: daysAgo(2),
      freight_inr: '10000', insurance_inr: '1500.50', cha_inr: '12000', port_inr: '8000.25', other_inr: '',
    }, impStaff.id))).toBeNull();
    expect(await step(() => I.advance(id, 'shipped', impStaff.id))).toBeNull();
    expect(await step(() => I.advance(id, 'cleared', impStaff.id))).toBeNull();
  }

  beforeEach(async () => {
    md = await createUser('Managing Director', null);
    impStaff = await createUser('Procurement Manager', IMP);
    expStaff = await createUser('Procurement Manager', 1);
    await db.execute("UPDATE division_modules SET is_enabled = CASE WHEN division_id = 4 THEN 1 ELSE 0 END WHERE module_key = 'import_stock'");
    client = await createTestClient();
    await db.execute("UPDATE clients SET company_legal_name = 'Bengaluru Stone Traders', billing_address = :a WHERE id = :id", { a: '12 MG Road\nBengaluru 560001', id: client });
    const code = `IMPT-${Math.random().toString(16).slice(2, 8)}`;
    productA = (await db.execute("INSERT INTO products (product_code, name, hs_code) VALUES (:c, 'Imported black granite slab', '68029300')", { c: `${code}A` })).insertId;
    productB = (await db.execute("INSERT INTO products (product_code, name, hs_code) VALUES (:c, 'Imported quartz worktop', '68101990')", { c: `${code}B` })).insertId;
  });

  afterEach(async () => {
    await db.execute("UPDATE divisions SET status = 'planned' WHERE id <> 1");
    await db.execute('UPDATE users SET division_id = 1 WHERE division_id IS NOT NULL AND division_id <> 1');
    await db.execute('DELETE FROM client_divisions WHERE client_id = :c AND division_id <> 1', { c: client });
    zoho.setTransport(null);
  });

  it('works out the landed cost and the duty exactly as the PHP app does', () => {
    const f = I.compute([
      { quantity: '100', unit_price_fc: '18.50', bcd_pct: '20', sws_pct: '10', igst_pct: '18' },
      { quantity: '3', unit_price_fc: '333.33', bcd_pct: '10', sws_pct: '10', igst_pct: '18' },
    ], '84.65', { freight_inr: '10000', insurance_inr: '1500.50', cha_inr: '12000', port_inr: '8000.25', other_inr: '0' });
    const a = f.lines[0];
    expect([a.value_fc, a.value_inr, a.freight_share, a.insurance_share, a.assessable_inr]).toEqual(['1850.00', '156602.50', '6491.25', '974.01', '164067.76']);
    expect([a.bcd_inr, a.sws_inr, a.igst_inr]).toEqual(['32813.55', '3281.36', '36029.28']);
    expect([a.charges_share, a.landed_inr, a.landed_unit_inr]).toEqual(['12982.66', '213145.33', '2131.4533']);
    const b = f.lines[1];
    expect([b.value_fc, b.value_inr, b.freight_share, b.insurance_share, b.assessable_inr, b.bcd_inr, b.sws_inr, b.igst_inr, b.charges_share, b.landed_inr, b.landed_unit_inr])
      .toEqual(['999.99', '84649.15', '3508.75', '526.49', '88684.39', '8868.44', '886.84', '17719.14', '7017.59', '105457.26', '35152.4200']);
    expect([f.value_total_fc, f.value_total_inr, f.assessable_total, f.bcd_total, f.sws_total, f.duty_total, f.igst_total, f.charges_total, f.landed_total])
      .toEqual(['2849.99', '241251.65', '252752.15', '41681.99', '4168.20', '45850.19', '53748.42', '20000.25', '318602.59']);
    expect(I.spread(10n, [1n, 2n, 7n])).toEqual([1n, 2n, 7n]);
    expect(I.spread(100n, [1n, 1n, 1n])).toEqual([33n, 33n, 34n]);
    expect(I.suggestedPrice('2131.4533', '20')).toBe('2664.32');
  });

  it('changes nothing while Import & Distribution is planned', async () => {
    expect(await I.isActive()).toBe(false);
    const [id, error] = await as(md, () => I.create({ supplier_name: 'X', supplier_country: 'China', currency_code: 'USD', po_date: daysAgo(0) }, md.id));
    expect(id).toBeNull();
    expect(error).toContain('not active');
    expect(await as(md, () => divisionService.disabledModuleForPath('/import'))).not.toBeNull();
    expect(await as(md, () => divisionService.disabledModuleForPath('/import/reports'))).not.toBeNull();
    expect(await as(md, () => divisionService.disabledModuleForPath('/orders'))).toBeNull();
    const agent = await loginAs(md);
    expect((await agent.get('/import')).status).not.toBe(200);
    expect((await agent.get('/admin/import-duty-rates')).text).not.toContain('href="/import"');
    const [sale, saleError] = await as(md, () => D.create({ client_id: client, buyer_state_code: '29', place_of_supply: '29' }, md.id, 'IMP'));
    expect(sale).toBeNull();
    expect(saleError).toContain('Import & Distribution is not active');
    const items = await divisionRepository.preflight(IMP);
    const labels = items.map((i) => i.label);
    expect(labels).toContain("NexaCrest's IEC is set (the importer of record)");
    expect(labels).toContain('AD bank for import LCs set');
    expect(labels).toContain('Customs agent (CHA) set');
    const flags = Object.fromEntries(items.map((i) => [i.label, i.ok]));
    expect(flags['Import duty rates set (confirm them with the CA and the customs agent)']).toBe(true);
    expect(flags['AD bank for import LCs set']).toBe(false);
    expect(divisionRepository.STARTER_DEPARTMENTS.IMP.map((d) => d[0])).toEqual(['IMP_PURCHASE', 'IMP_WAREHOUSE', 'IMP_SALES']);
  });

  it('runs one import end to end, then a local sale out of its stock', async () => {
    const id = await importWithLines();
    let s = await repo.find(id);
    expect(Number(s.division_id)).toBe(IMP);
    expect(s.reference).toMatch(/^IMP\/PO\/\d{2}-\d{2}\/\d{4}$/);
    expect((await repo.lines(id))[0].description).toBe('Imported black granite slab');
    expect((await repo.lines(id))[0].hsn_code).toBe('68029300');
    expect(await step(() => I.addLine(id, { quantity: '1', unit: 'PCS', unit_price_fc: '1' }, impStaff.id))).toBe('Choose the product (stock is kept per product).');

    expect(await I.blockers(id)).toEqual(['Generate the import PO.', 'Record the bill of lading (BL).']);
    const html = await importDocs.html(id);
    expect(html).toContain('PURCHASE ORDER');
    expect(html).toContain('USD 2,849.99');
    expect(html).toContain('1,850.00');

    expect(await step(() => I.recordPayment(id, { kind: 'advance', paid_on: daysAgo(18), amount_fc: '1000', fx_rate: '84.92', bank_reference: `SWIFT-ADV-${id}` }, impStaff.id))).toBeNull();
    const p = (await repo.payments(id))[0];
    expect(p.description).toBe(`Import supplier advance of USD 1,000.00 (₹84,920.00 at 84.92) to TEST-Xiamen Stone Industries (China) against import PO ${s.reference}, SWIFT/UTR ref SWIFT-ADV-${id}`);
    expect(p.amount_inr).toBe('84920.00');
    expect(await step(() => I.recordPayment(id, { kind: 'balance', paid_on: daysAgo(0), amount_fc: '1', fx_rate: '84', bank_reference: `SWIFT-ADV-${id}` }, impStaff.id))).toBe('This bank reference is already recorded. Check the payment is not entered twice.');
    expect(await step(() => I.recordPayment(id, { kind: 'balance', paid_on: daysAgo(0), amount_fc: '2000', fx_rate: '84', bank_reference: `X-${id}` }, impStaff.id))).toContain('more than the PO still owes (USD 1,849.99)');
    expect(await step(() => I.recordPayment(id, { kind: 'lc', paid_on: daysAgo(0), amount_fc: '1', fx_rate: '84', bank_reference: `Y-${id}` }, impStaff.id))).toBe('Record the LC first.');
    expect(await step(() => I.saveLc(id, { lc_no: '0123ILC000456', lc_bank: 'State Bank of India', lc_amount_fc: '1849.99', lc_expiry: '2099-12-31', lc_margin_inr: '15000' }, impStaff.id))).toBeNull();

    expect(await step(() => I.saveClearance(id, {}, impStaff.id))).toBe('Customs clearance is recorded once the goods are shipped and before they are cleared.');
    await receive(id);
    s = await repo.find(id);
    expect(s.stage).toBe('received');
    expect([s.assessable_total, s.bcd_total, s.sws_total, s.igst_total, s.landed_total]).toEqual(['252752.15', '41681.99', '4168.20', '53748.42', '318602.59']);
    expect(s.port_code).toBe('INNSA1');
    expect((await repo.lines(id)).map((l) => l.landed_inr)).toEqual(['213145.33', '105457.26']);
    let b = await repo.balance(IMP, productA);
    expect([b.qty_on_hand, b.value_inr]).toEqual(['100.000', '213145.33']);

    expect(await I.blockers(id)).toEqual(['Still to pay the supplier: USD 1,849.99.', 'Record when the IGST paid on import was taken as input credit.']);
    expect(await step(() => I.recordPayment(id, { kind: 'lc', paid_on: daysAgo(0), amount_fc: '1849.99', fx_rate: '84.70', bank_reference: `LC-RET-${id}` }, impStaff.id))).toBeNull();
    expect((await repo.payments(id))[1].description).toBe(`LC retirement payment of USD 1,849.99 (₹1,56,694.15 at 84.70) to TEST-Xiamen Stone Industries (China) against import PO ${s.reference}, LC 0123ILC000456, SWIFT/UTR ref LC-RET-${id}`);
    expect(await step(() => I.saveIgstCredit(id, daysAgo(0), impStaff.id))).toBeNull();
    expect(await step(() => I.advance(id, 'received', impStaff.id))).toBeNull();
    expect((await repo.find(id)).stage).toBe('closed');

    // The local sale: a Domestic Trade order in the IMP division, out of stock, under IMP's own numbers.
    await db.execute('INSERT IGNORE INTO client_divisions (client_id, division_id) VALUES (:c, :d)', { c: client, d: IMP });
    const [orderId, error] = await step(() => D.create({ client_id: client, buyer_gstin: BUYER_GSTIN, buyer_state_code: '29', place_of_supply: '29', payment_terms_days: '30' }, impStaff.id, 'IMP'));
    expect(error).toBeNull();
    const order = await orderRepository.find(orderId);
    expect(Number(order.division_id)).toBe(IMP);
    expect(await D.isDomesticOrder(order)).toBe(true);
    expect(await step(() => D.addLine(orderId, { description: 'Granite', hsn_code: '6802', quantity: '1', unit: 'SQM', rate_inr: '10' }, impStaff.id))).toBe('Choose the product: an Import & Distribution sale comes out of stock.');
    expect(await step(() => D.addLine(orderId, { product_id: productA, description: 'Imported black granite slab', hsn_code: '68029300', quantity: '40', unit: 'SQM', rate_inr: '2700' }, impStaff.id))).toBeNull();
    expect(await step(() => D.advance(orderId, 'enquiry', impStaff.id))).toBeNull();
    const [, err] = await step(() => docs.generate(orderId, 'DQT', impStaff.id));
    expect(err).toBeNull();
    expect((await domRepo.latestDocument(orderId, 'DQT')).reference).toMatch(/^IMP\/QT\//);
    expect(await step(() => D.advance(orderId, 'quotation', impStaff.id))).toBeNull();
    await step(() => docs.generate(orderId, 'DOC', impStaff.id));
    expect(await step(() => D.savePurchase(orderId, { supplier_name: 'X', supplier_po_ref: 'Y' }, impStaff.id))).toBe('An Import & Distribution sale comes out of stock: there is no supplier PO.');
    expect(await step(() => D.advance(orderId, 'order_confirmation', impStaff.id))).toBeNull();
    expect(await D.blockers(orderId)).toEqual([]);
    expect(await step(() => D.advance(orderId, 'purchase', impStaff.id))).toBeNull();
    const dom = await domRepo.find(orderId);
    expect(dom.tax_invoice_no).toMatch(/^IMP\/\d{2}-\d{2}\/\d{4}$/);
    b = await repo.balance(IMP, productA);
    expect([b.qty_on_hand, b.value_inr]).toEqual(['60.000', '127887.20']);
    const issue = await db.queryOne("SELECT * FROM stock_movements WHERE kind = 'issue' AND order_id = :o", { o: orderId });
    expect([issue.quantity, issue.value_inr]).toEqual(['-40.000', '-85258.13']);
    expect(await D.paymentDescription(order, dom, '127440.00', 'UTR-IMP-1', null))
      .toBe(`Local sale receipt of ₹1,27,440.00 from Bengaluru Stone Traders against GST tax invoice ${dom.tax_invoice_no} (order ${order.order_reference}), bank ref UTR-IMP-1`);

    const agent = await loginAs(impStaff);
    const page = (await agent.get('/import/reports')).text;
    expect(page).toContain('Imported black granite slab');
    expect(page).toContain('1,27,887.20');
    expect(page).toContain('2131.4533');
    expect(page).toContain('2,664.32');
    expect(page).toContain('4567891');
  });

  it('never lets stock go below zero, even with two issues at the same moment', async () => {
    const id = await importWithLines();
    await receive(id);
    const o1 = await createTestOrder(client);
    const o2 = await createTestOrder(await createTestClient());
    const issue = (qty, orderId) => db.transaction(async (trx) => {
      const e = await I.issueForSale(IMP, orderId, `SALE-${orderId}`, [{ line_no: 1, description: 'Quartz', product_id: productB, quantity: qty }], impStaff.id, trx);
      if (e !== null) throw new Error(e);
      return null;
    }).catch((e) => e.message);
    // 3 on hand: two sales of 2 at the same moment — exactly one goes through.
    const results = await Promise.all([issue('2', o1), issue('2', o2)]);
    expect(results.filter((r) => r === null)).toHaveLength(1);
    expect(results.filter((r) => r === 'Not enough stock of Imported quartz worktop: the sale needs 2, on hand 1.')).toHaveLength(1);
    expect((await repo.balance(IMP, productB)).qty_on_hand).toBe('1.000');
    expect(await issue('1', o2)).toBeNull();
    const bal = await repo.balance(IMP, productB);
    expect([bal.qty_on_hand, bal.value_inr]).toEqual(['0.000', '0.00']);
    expect(await db.transaction((trx) => I.issueForSale(IMP, o1, 'X', [{ line_no: 1, description: 'Quartz', product_id: null, quantity: '1' }], impStaff.id, trx))).toContain('not linked to a product');
    expect(await I.stockShortfalls(IMP, [{ line_no: 1, description: 'Q', product_id: productB, quantity: '5' }]))
      .toEqual(['Not enough stock of Imported quartz worktop: the sale needs 5, on hand 0.']);
    expect(Number((await db.queryOne('SELECT COUNT(*) AS n FROM stock_balances WHERE qty_on_hand < 0')).n)).toBe(0);
  });

  it('keeps Export and Import & Distribution apart', async () => {
    const exportOrder = await createTestOrder(client);
    await db.execute('UPDATE orders SET division_id = 1 WHERE id = :id', { id: exportOrder });
    const id = await importWithLines();
    expect(await as(expStaff, () => divisionService.disabledModuleForPath('/import'))).not.toBeNull();
    const exp = await loginAs(expStaff);
    expect((await exp.get(`/import/${id}`)).text).not.toContain('TEST-Xiamen Stone Industries');
    expect(await as(impStaff, () => divisionService.disabledModuleForPath('/import'))).toBeNull();
    expect(await as(impStaff, () => divisionService.allowsOrderId(exportOrder))).toBe(false);
    const imp = await loginAs(impStaff);
    expect((await imp.get(`/import/${id}`)).text).toContain('TEST-Xiamen Stone Industries');
    const boss = await loginAs(md);
    expect(await as(md, () => divisionService.allowsOrderId(exportOrder))).toBe(true);
    const page = (await boss.get(`/import/${id}`)).text;
    expect(page).toContain('TEST-Xiamen Stone Industries');
    expect(page).toContain('Imports &amp; stock');
  });

  it('builds the Zoho payloads for an import (mocked: no Zoho call)', async () => {
    const id = await importWithLines();
    await receive(id);
    expect(await step(() => I.saveIgstCredit(id, daysAgo(0), impStaff.id))).toBeNull();
    const s = await repo.find(id);
    const acc = async (name) => `acc:${name}`;
    const bill = await zoho.importBillPayload(s, await repo.lines(id), 'V1', acc);
    expect([bill.vendor_id, bill.bill_number, bill.reference_number, bill.currency_code, bill.exchange_rate, bill.gst_treatment]).toEqual(['V1', s.reference, 'COSU6234567890', 'USD', 84.65, 'overseas']);
    expect([bill.line_items[0].quantity, bill.line_items[0].rate, bill.line_items[0].account_id, bill.line_items[0].hsn_or_sac]).toEqual([100, 18.5, 'acc:Inventory Asset', '68029300']);
    expect(bill.notes).toBe(`Import purchase from TEST-Xiamen Stone Industries (China) against import PO ${s.reference}, BL COSU6234567890, bill of entry 4567891.`);
    expect(bill.tags).toBeUndefined();
    const duty = await zoho.customsDutyBillPayload(s, 'C1', acc);
    expect(duty.bill_number).toBe('BE-4567891');
    expect(duty.line_items.map((l) => l.rate)).toEqual([41681.99, 4168.2, 53748.42]);
    expect(duty.line_items.map((l) => l.account_id)).toEqual(['acc:Inventory Asset', 'acc:Inventory Asset', 'acc:IGST Paid on Import']);
    const journal = await zoho.importIgstCreditJournalPayload(s, acc);
    expect(journal.line_items.map((l) => [l.account_id, l.debit_or_credit, l.amount])).toEqual([['acc:Input IGST', 'debit', 53748.42], ['acc:IGST Paid on Import', 'credit', 53748.42]]);
    expect(journal.notes).toContain('₹53,748.42');
    await db.execute("INSERT INTO company_settings (setting_key, setting_value, value_type, category, description) VALUES ('zoho_division_tag_id', 'T1', 'string', 'company', 't'), ('zoho_division_tag_option_imp', 'OPT-IMP', 'string', 'company', 't') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    expect((await zoho.importBillPayload(s, await repo.lines(id), 'V1', acc)).tags).toEqual([{ tag_id: 'T1', tag_option_id: 'OPT-IMP' }]);
    await db.execute("DELETE FROM company_settings WHERE setting_key IN ('zoho_division_tag_id', 'zoho_division_tag_option_imp')");
  });

  it('offers the same on the list and on the board', async () => {
    const id = await importWithLines();
    await userRepository.updateUiTemplatePreference(impStaff.id, (await uiTemplateRepository.findByKey('kanban')).id);
    const agent = await loginAs(impStaff);
    const board = (await agent.get('/import')).text;
    const list = (await agent.get('/import?view=list')).text;
    for (const page of [board, list]) {
      expect(page).toContain(`href="/import/${id}"`);
      expect(page).toContain('href="/import/create"');
      expect(page).toContain('href="/import/reports"');
      expect(page).toContain('name="stage"');
      expect(page).toContain('TEST-Xiamen Stone Industries');
      expect(page).toContain('USD 2,849.99');
    }
    expect(board).toContain('kanban-board');
    expect(board).toContain('Switch to list view');
    expect(list).toContain('Switch to board view');
    expect((await agent.get('/import?view=list&stage=closed')).text).not.toContain(`href="/import/${id}"`);
  });
});
