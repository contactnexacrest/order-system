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
const repo = require('../../src/repositories/merchantTradeRepository');
const uiTemplateRepository = require('../../src/repositories/uiTemplateRepository');
const userRepository = require('../../src/repositories/userRepository');
const divisionService = require('../../src/services/divisionService');
const M = require('../../src/services/merchantTradeService');
const merchantDocs = require('../../src/services/merchantDocumentService');
const zoho = require('../../src/services/zohoBooksService');

/**
 * Port of MerchantTradeTest.php. Owner decision D-257 Phase 4 (5 Nov 2026):
 * the Merchant Trade (MTT) division. MTT stays "planned" in the seed; this
 * test activates it inside the test only, to prove: while planned nothing
 * changes; the margin maths on two foreign-currency legs; the RBI checks
 * and their reminders, sent once; one deal end to end with D-212
 * descriptions; two payments at the same moment never pay more than is
 * owed; Export and MTT never see each other's records; the Zoho payloads
 * (mocked); the list and the board offer the same.
 */
describe('D-257 Phase 4 — Merchant Trade', () => {
  const MTT = 3;
  let md;
  let mttStaff;
  let expStaff;

  afterAll(async () => {
    await db.pool.end();
  });

  async function createUser(roleName, divisionId) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-mtt-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const password = 'JestMerchant123!';
    const r = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled, division_id)
       VALUES ('Jest Merchant User', :email, NULL, :hash, :role_id, 1, 0, 0, :d)`,
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

  function as(user, fn) {
    const seesAll = user.division_id === null;
    return requestContext.run({ userId: user.id, divisionIds: seesAll ? null : [user.division_id], divisionSeesAll: seesAll, divisionUser: { division_id: user.division_id } }, fn);
  }

  const step = (fn) => as(mttStaff, fn);
  const daysAgo = (n) => {
    const d = new Date();
    d.setDate(d.getDate() - n);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  };

  async function activate() {
    await db.execute("UPDATE divisions SET status = 'active' WHERE id = :d", { d: MTT });
  }

  /** A deal (EUR purchase from Turkey, USD sale to the UAE) with one line, as MTT staff. */
  async function deal() {
    await activate();
    const [id, error] = await step(() => M.create({
      deal_date: daysAgo(40), supplier_name: 'TEST-Anatolia Marble', supplier_country: 'Turkey', supplier_address: 'Organize Sanayi 12\nAfyon',
      purchase_currency: 'EUR', purchase_incoterm: 'FOB', buyer_name: 'TEST-Gulf Stone Trading', buyer_country: 'United Arab Emirates',
      buyer_address: 'Office 1203\nDubai', sale_currency: 'USD', sale_incoterm: 'CFR', origin_country: 'Turkey', port_of_loading: 'Izmir', port_of_discharge: 'Jebel Ali',
    }, mttStaff.id));
    expect(error).toBeNull();
    expect(await step(() => M.addLine(id, { description: 'White marble slabs, 2 cm', hsn_code: '68022100', quantity: '500', unit: 'sqm', buy_price_fc: '32.00', sell_price_fc: '38.50' }, mttStaff.id))).toBeNull();
    return id;
  }

  beforeEach(async () => {
    md = await createUser('Managing Director', null);
    mttStaff = await createUser('Procurement Manager', MTT);
    expStaff = await createUser('Procurement Manager', 1);
    await db.execute("UPDATE division_modules SET is_enabled = CASE WHEN division_id = 3 THEN 1 ELSE 0 END WHERE module_key = 'merchant_trade'");
  });

  afterEach(async () => {
    await db.execute("UPDATE divisions SET status = 'planned' WHERE id <> 1");
    await db.execute('UPDATE users SET division_id = 1 WHERE division_id IS NOT NULL AND division_id <> 1');
    await db.execute("UPDATE division_settings SET setting_value = '500000' WHERE division_id = 3 AND setting_key = 'mtt_advance_limit_usd'");
    zoho.setTransport(null);
  });

  it('works out the margin on two currency legs and the dates exactly as the PHP app does', () => {
    const d = { sale_currency: 'USD', purchase_currency: 'EUR', stage: 'shipped', closed_on: null, advance_backing: 'none', backing_ref: null };
    const lines = [{ quantity: '500', buy_price_fc: '32.00', sell_price_fc: '38.50' }];
    const pay = [{ leg: 'buy', moved_on: '2026-01-10', amount_fc: '16000.00', amount_inr: '1459200.00' }, { leg: 'sell', moved_on: '2026-02-01', amount_fc: '19250.00', amount_inr: '1618925.00' }];
    const f = M.money(d, lines, pay, '84.0000');
    expect([f.sale_cents, f.purchase_cents, f.sell_rate, f.buy_rate, f.usd_rate, f.margin_inr, f.margin_usd_cents, f.margin_pct, f.contract_margin_cents, f.final])
      .toEqual([1925000n, 1600000n, '84.1000', '91.2000', '84.1000', '159725.00', 189923n, '9.87', null, true]);
    const g = M.money({ ...d, sale_currency: 'EUR', purchase_currency: 'GBP' }, lines, pay, '84.0000');
    expect([g.usd_rate, g.margin_usd_cents]).toEqual(['84.0000', 190149n]);
    expect(M.money({ ...d, sale_currency: 'USD', purchase_currency: 'USD' }, lines, [], '84').contract_margin_cents).toBe(325000n);
    expect(M.money(d, lines, [pay[0]], '84').final).toBe(false);
    expect([M.addMonths('2026-01-31', 1), M.addMonths('2024-01-31', 1), M.addMonths('2026-11-30', 3), M.addMonths('2026-03-15', 9)]).toEqual(['2026-02-28', '2024-02-29', '2027-02-28', '2026-12-15']);
    expect(M.outlayPeriods([
      { leg: 'buy', moved_on: '2026-01-10', amount_inr: '100' }, { leg: 'sell', moved_on: '2026-02-01', amount_inr: '50' },
      { leg: 'sell', moved_on: '2026-03-01', amount_inr: '60' }, { leg: 'buy', moved_on: '2026-03-05', amount_inr: '20' },
    ])).toEqual([{ from: '2026-01-10', to: '2026-03-01' }, { from: '2026-03-05', to: null }]);
  });

  it('runs the RBI checks', () => {
    const d = { sale_currency: 'USD', purchase_currency: 'EUR', stage: 'shipped', closed_on: null, advance_backing: 'none', backing_ref: null };
    const s = { mtt_max_months_total: '9', mtt_max_outlay_months: '4', mtt_advance_limit_usd: '500000', mtt_usd_inr_rate: '84.0000' };
    const docs = { a: 'Bill of lading', b: 'Our sale invoice' };
    const p = [{ leg: 'buy', moved_on: '2026-01-10', amount_fc: '1000.00', amount_inr: '91200.00' }, { leg: 'sell', moved_on: '2026-06-01', amount_fc: '2000.00', amount_inr: '168200.00' }];
    const view = (checks) => checks.map((c) => [c.key, c.level, c.text]);
    expect(view(M.rbiChecks(d, p, docs, { a: '2026-06-01' }, s, '2026-06-20'))).toEqual([
      ['total', 'ok', 'Finish by 10 Oct 2026 (112 days left).'],
      ['outlay', 'breach', 'Outlay from 10 Jan 2026 to 01 Jun 2026 was longer than the limit (10 May 2026). Report it to the AD bank.'],
      ['advance', 'ok', 'Advance USD 1,085.71, within the limit.'],
      ['documents', 'info', '1 of 2 with the bank. Missing: Our sale invoice.'],
    ]);
    expect(view(M.rbiChecks(d, [p[0]], docs, {}, s, '2026-09-20')).slice(0, 2)).toEqual([
      ['total', 'warn', 'Due soon: finish by 10 Oct 2026 (20 days left).'],
      ['outlay', 'breach', 'Overdue: money paid to the supplier since 10 Jan 2026 had to be covered by the buyer by 10 May 2026. Report it to the AD bank.'],
    ]);
    expect(M.rbiChecks(d, [], docs, {}, s, '2026-09-20').slice(0, 3).map((c) => c.level)).toEqual(['info', 'info', 'ok']);
    const closed = view(M.rbiChecks({ ...d, stage: 'closed', closed_on: '2026-11-01', advance_backing: 'lc', backing_ref: 'LC123' }, p, docs, { a: '2026-06-01', b: '2026-06-02' }, { ...s, mtt_advance_limit_usd: '1000' }, '2026-11-02'));
    expect([closed[0], closed[2], closed[3]]).toEqual([
      ['total', 'breach', 'Finished on 01 Nov 2026, after the limit (10 Oct 2026). Report it to the AD bank.'],
      ['advance', 'ok', 'Advance USD 1,085.71 backed by letter of credit LC123.'],
      ['documents', 'ok', 'All 2 documents are with the bank.'],
    ]);
    expect(view(M.rbiChecks(d, p, docs, {}, { ...s, mtt_advance_limit_usd: '1000' }, '2026-06-20'))[2])
      .toEqual(['advance', 'breach', 'Advance USD 1,085.71 is above the limit and not backed by an LC or bank guarantee. Report it to the AD bank.']);
    expect(M.worstLevel(M.rbiChecks(d, p, docs, {}, s, '2026-06-20'))).toBe('breach');
  });

  it('changes nothing while Merchant Trade is planned', async () => {
    expect(await M.isActive()).toBe(false);
    const [id, error] = await as(md, () => M.create({ deal_date: daysAgo(0) }, md.id));
    expect(id).toBeNull();
    expect(error).toContain('not active');
    expect(await as(md, () => divisionService.disabledModuleForPath('/merchant'))).not.toBeNull();
    expect(await as(md, () => divisionService.disabledModuleForPath('/merchant/reports'))).not.toBeNull();
    expect(await as(md, () => divisionService.disabledModuleForPath('/orders'))).toBeNull();
    const agent = await loginAs(md);
    expect((await agent.get('/merchant')).status).not.toBe(200);
    expect((await agent.get('/dashboard')).text).not.toContain('href="/merchant"');
    const flags = Object.fromEntries((await divisionRepository.preflight(MTT)).map((i) => [i.label, i.ok]));
    expect(flags["AD bank's merchanting approval recorded"]).toBe(false);
    expect(flags["RBI limits and the bank's document list set (confirm them with the AD bank and the CA)"]).toBe(true);
    expect(flags).toHaveProperty('Merchant trade module on; export-only modules off');
    expect(divisionRepository.STARTER_DEPARTMENTS.MTT.map((d) => d[0])).toEqual(['MTT_TRADE', 'MTT_DOCS']);
    expect(Object.keys(await M.bankDocuments())).toHaveLength(7);
  });

  it('runs one deal end to end', async () => {
    const id = await deal();
    let d = await repo.find(id);
    expect(Number(d.division_id)).toBe(MTT);
    expect(d.reference).toMatch(/^MTT\/\d{2}-\d{2}\/\d{4}$/);
    expect(d.po_no).toMatch(/^MTT\/PO\/\d{2}-\d{2}\/\d{4}$/);
    expect(M.headerFields({ deal_date: daysAgo(0), supplier_name: 'X', supplier_country: 'India', buyer_name: 'Y', buyer_country: 'UAE', origin_country: 'India' })[1]).toContain('both the supplier and the buyer are abroad');
    expect(M.headerFields({ deal_date: daysAgo(0), supplier_name: 'X', supplier_country: 'Turkey', buyer_name: 'Y', buyer_country: 'UAE', origin_country: 'india' })[1]).toBe('Goods made in India and shipped abroad are an export, not a merchant trade.');

    expect(await M.blockers(id)).toEqual(['Generate the purchase order to the supplier.', 'Record the bill of lading (BL).']);
    const po = await merchantDocs.html(id, 'po');
    expect(po).toContain('PURCHASE ORDER');
    expect(po).toContain('EUR 16,000.00');
    expect(po).toContain(d.po_no);
    expect(await M.fixInvoice(id, mttStaff.id)).toBe('Record the BL first: the sale invoice goes with the shipping documents.');

    expect(await step(() => M.recordPayment(id, { leg: 'buy', moved_on: daysAgo(35), amount_fc: '8000', fx_rate: '91.20', bank_reference: `SWIFT-MTT-A-${id}` }, mttStaff.id))).toBeNull();
    expect((await repo.payments(id))[0].description).toBe(`Merchant trade supplier advance of EUR 8,000.00 (₹7,29,600.00 at 91.20) to TEST-Anatolia Marble (Turkey) against merchant trade deal ${d.reference}, PO ${d.po_no}, SWIFT/UTR ref SWIFT-MTT-A-${id}`);
    await db.execute("UPDATE division_settings SET setting_value = '10000' WHERE division_id = 3 AND setting_key = 'mtt_advance_limit_usd'");
    const second = { leg: 'buy', moved_on: daysAgo(34), amount_fc: '8000', fx_rate: '91.20', bank_reference: `SWIFT-MTT-B-${id}` };
    expect(await step(() => M.recordPayment(id, second, mttStaff.id))).toBe('This would make the advance to the supplier USD 17,371.42, above the USD 10,000.00 limit for an advance not backed by an LC or bank guarantee (RBI merchanting rule, to be confirmed with the AD bank). Record the LC or bank guarantee first.');
    expect(await M.saveBacking(id, { advance_backing: 'lc' }, mttStaff.id)).toBe('Enter the LC or bank guarantee number.');
    expect(await M.saveBacking(id, { advance_backing: 'lc', backing_ref: 'SBLC-77' }, mttStaff.id)).toBeNull();
    expect(await step(() => M.recordPayment(id, second, mttStaff.id))).toBeNull();
    expect((await repo.payments(id))[1].description).toContain(', backed by letter of credit SBLC-77, SWIFT/UTR ref');
    expect(await step(() => M.recordPayment(id, { leg: 'buy', moved_on: daysAgo(1), amount_fc: '1', fx_rate: '91', bank_reference: `X-${id}` }, mttStaff.id))).toBe('This is more than the supplier is still owed (EUR 0.00).');
    expect(await step(() => M.recordPayment(id, { leg: 'sell', moved_on: daysAgo(1), amount_fc: '1', fx_rate: '84', bank_reference: `SWIFT-MTT-A-${id}` }, mttStaff.id))).toBe('This bank reference is already recorded. Check the payment is not entered twice.');

    expect(await M.saveShipment(id, { bl_no: 'MSCUIZ1234567', bl_date: daysAgo(20), switch_bl: '1' }, mttStaff.id)).toBe('Enter the number of the switch BL.');
    expect(await M.saveShipment(id, { bl_no: 'MSCUIZ1234567', bl_date: daysAgo(20), vessel: 'MSC Aurora', switch_bl: '1', switch_bl_no: 'MSCUIZ1234567-S', switch_bl_ref: 'MSC Dubai agent; SBI letter MT/118' }, mttStaff.id)).toBeNull();
    await M.markPoGenerated(id, mttStaff.id);
    expect(await M.advance(id, 'contracted', mttStaff.id)).toBeNull();

    expect(await M.blockers(id)).toEqual(['Generate the sale invoice to the buyer.', 'Still to receive from the buyer: USD 19,250.00.']);
    expect(await step(() => M.fixInvoice(id, mttStaff.id))).toBeNull();
    d = await repo.find(id);
    expect(d.sale_invoice_no).toMatch(/^MTT\/I\/\d{2}-\d{2}\/\d{4}$/);
    expect(await step(() => M.fixInvoice(id, mttStaff.id))).toBeNull();
    expect((await repo.find(id)).sale_invoice_no).toBe(d.sale_invoice_no);
    const inv = await merchantDocs.html(id, 'invoice');
    expect(inv).toContain('INVOICE');
    expect(inv).toContain('USD 19,250.00');
    expect(inv).toContain('MSCUIZ1234567-S');
    expect(inv).toContain('Nineteen Thousand Two Hundred Fifty');
    expect(inv).toContain('they do not enter India');
    expect(await step(() => M.recordPayment(id, { leg: 'sell', moved_on: daysAgo(5), amount_fc: '19250', fx_rate: '84.10', bank_reference: `SWIFT-MTT-S-${id}` }, mttStaff.id))).toBeNull();
    expect((await repo.payments(id))[2].description).toBe(`Merchant trade sale receipt of USD 19,250.00 (₹16,18,925.00 at 84.10) from TEST-Gulf Stone Trading (United Arab Emirates) against merchant trade deal ${d.reference}, invoice ${d.sale_invoice_no}, SWIFT/UTR ref SWIFT-MTT-S-${id}`);
    const f = await M.figures(d);
    expect([f.margin_inr, f.margin_usd_cents, f.margin_pct, f.final]).toEqual(['159725.00', 189923n, '9.87', true]);
    expect(await M.advance(id, 'shipped', mttStaff.id)).toBeNull();

    expect((await M.blockers(id))[0]).toMatch(/^Documents still to give the AD bank: /);
    for (const key of Object.keys(await M.bankDocuments())) expect(await M.saveDocument(id, key, daysAgo(1), mttStaff.id)).toBeNull();
    expect(await M.saveDocument(id, 'not-on-the-list', daysAgo(1), mttStaff.id)).toBe("Choose a document from the bank's checklist.");
    expect(await M.advance(id, 'settled', mttStaff.id)).toBeNull();
    d = await repo.find(id);
    expect([d.stage, d.closed_on]).toEqual(['closed', daysAgo(0)]);
    expect((await M.checksFor(d)).map((c) => c.level)).toEqual(['ok', 'ok', 'ok', 'ok']);
    expect(await step(() => M.recordPayment(id, { leg: 'sell' }, mttStaff.id))).toBe('The deal is closed.');

    const page = (await (await loginAs(mttStaff)).get('/merchant/reports')).text;
    expect(page).toContain(d.reference);
    expect(page).toContain('1,59,725.00');
    expect(page).toContain('1,899.23');
  });

  it('never pays more than is owed, even with two payments at the same moment', async () => {
    const id = await deal();
    const pay = (ref) => step(() => M.recordPayment(id, { leg: 'buy', moved_on: daysAgo(10), amount_fc: '10000', fx_rate: '91', bank_reference: ref }, mttStaff.id));
    const results = await Promise.all([pay(`CC-1-${id}`), pay(`CC-2-${id}`)]);
    expect(results.filter((r) => r === null)).toHaveLength(1);
    expect(results.filter((r) => r === 'This is more than the supplier is still owed (EUR 6,000.00).')).toHaveLength(1);
    expect((await repo.payments(id))).toHaveLength(1);
  });

  it('sends each reminder once, and only to the division and company level', async () => {
    await db.execute("UPDATE merchant_deals SET stage = 'closed'");
    await activate();
    const [id] = await step(() => M.create({
      deal_date: daysAgo(130), supplier_name: 'TEST-Supplier', supplier_country: 'Vietnam', purchase_currency: 'USD',
      buyer_name: 'TEST-Buyer', buyer_country: 'Kenya', sale_currency: 'USD', origin_country: 'Vietnam',
    }, mttStaff.id));
    expect(await step(() => M.addLine(id, { description: 'Granite', hsn_code: '6802', quantity: '10', unit: 'MT', buy_price_fc: '100', sell_price_fc: '120' }, mttStaff.id))).toBeNull();
    expect(await step(() => M.recordPayment(id, { leg: 'buy', moved_on: daysAgo(125), amount_fc: '500', fx_rate: '84', bank_reference: `REM-${id}` }, mttStaff.id))).toBeNull();
    const count = async (u) => Number((await db.queryOne("SELECT COUNT(*) AS n FROM notifications WHERE type = 'merchant_trade_rbi' AND user_id = :u", { u: u.id })).n);
    expect(await M.runAlerts()).toBeGreaterThan(0);
    expect(await count(mttStaff)).toBe(1);
    expect(await count(md)).toBe(1);
    expect(await count(expStaff)).toBe(0);
    const msg = (await db.queryOne("SELECT message FROM notifications WHERE type = 'merchant_trade_rbi' AND user_id = :u", { u: mttStaff.id })).message;
    expect(msg).toContain('Outlay within 4 months — Overdue');
    expect(await M.runAlerts()).toBe(0);
  });

  it('keeps Export and Merchant Trade apart', async () => {
    const id = await deal();
    expect(await as(expStaff, () => divisionService.disabledModuleForPath('/merchant'))).not.toBeNull();
    expect((await (await loginAs(expStaff)).get(`/merchant/${id}`)).text).not.toContain('TEST-Gulf Stone Trading');
    expect(await as(mttStaff, () => divisionService.disabledModuleForPath('/merchant'))).toBeNull();
    expect((await (await loginAs(mttStaff)).get(`/merchant/${id}`)).text).toContain('TEST-Gulf Stone Trading');
    const page = (await (await loginAs(md)).get(`/merchant/${id}`)).text;
    expect(page).toContain('TEST-Gulf Stone Trading');
    expect(page).toContain('href="/merchant"');
  });

  it('builds the Zoho payloads for a deal (mocked: no Zoho call)', async () => {
    const id = await deal();
    expect(await step(() => M.recordPayment(id, { leg: 'buy', moved_on: daysAgo(30), amount_fc: '16000', fx_rate: '91.20', bank_reference: `Z-B-${id}` }, mttStaff.id))).toBeNull();
    expect(await M.saveShipment(id, { bl_no: 'MSCUIZ7654321', bl_date: daysAgo(20) }, mttStaff.id)).toBeNull();
    expect(await step(() => M.fixInvoice(id, mttStaff.id))).toBeNull();
    expect(await step(() => M.recordPayment(id, { leg: 'sell', moved_on: daysAgo(5), amount_fc: '19250', fx_rate: '84.10', bank_reference: `Z-S-${id}` }, mttStaff.id))).toBeNull();
    const d = await repo.find(id);
    const acc = async (name) => `acc:${name}`;
    const inv = await zoho.merchantInvoicePayload(d, await repo.lines(id), 'C1', acc);
    expect([inv.customer_id, inv.invoice_number, inv.reference_number, inv.currency_code, inv.gst_treatment, inv.exchange_rate]).toEqual(['C1', d.sale_invoice_no, d.reference, 'USD', 'overseas', 84.1]);
    expect([inv.line_items[0].quantity, inv.line_items[0].rate, inv.line_items[0].account_id, inv.line_items[0].hsn_or_sac]).toEqual([500, 38.5, 'acc:Merchanting Trade Sales', '68022100']);
    expect(inv.notes).toBe(`Merchant trade sale invoice ${d.sale_invoice_no} for deal ${d.reference} to TEST-Gulf Stone Trading (United Arab Emirates): goods from Turkey shipped directly to the buyer, BL MSCUIZ7654321; they do not enter India.`);
    expect(inv.tags).toBeUndefined();
    const bill = await zoho.merchantBillPayload(d, await repo.lines(id), 'V1', acc);
    expect([bill.vendor_id, bill.bill_number, bill.currency_code, bill.exchange_rate, bill.line_items[0].rate, bill.line_items[0].account_id]).toEqual(['V1', d.po_no, 'EUR', 91.2, 32, 'acc:Merchanting Trade Purchases']);
    expect(bill.notes).toBe(`Merchant trade purchase from TEST-Anatolia Marble (Turkey) against PO ${d.po_no}, deal ${d.reference}, BL MSCUIZ7654321; goods shipped directly to the buyer abroad.`);
    await db.execute("INSERT INTO company_settings (setting_key, setting_value, value_type, category, description) VALUES ('zoho_division_tag_id', 'T1', 'string', 'company', 't'), ('zoho_division_tag_option_mtt', 'OPT-MTT', 'string', 'company', 't') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    expect((await zoho.merchantBillPayload(d, await repo.lines(id), 'V1', acc)).tags).toEqual([{ tag_id: 'T1', tag_option_id: 'OPT-MTT' }]);
    await db.execute("DELETE FROM company_settings WHERE setting_key IN ('zoho_division_tag_id', 'zoho_division_tag_option_mtt')");
  });

  it('offers the same on the list and on the board', async () => {
    const id = await deal();
    await userRepository.updateUiTemplatePreference(mttStaff.id, (await uiTemplateRepository.findByKey('kanban')).id);
    const agent = await loginAs(mttStaff);
    const board = (await agent.get('/merchant')).text;
    const list = (await agent.get('/merchant?view=list')).text;
    for (const page of [board, list]) {
      expect(page).toContain(`href="/merchant/${id}"`);
      expect(page).toContain('href="/merchant/create"');
      expect(page).toContain('href="/merchant/reports"');
      expect(page).toContain('name="stage"');
      expect(page).toContain('TEST-Gulf Stone Trading');
      expect(page).toContain('Buy EUR 16,000.00');
      expect(page).toContain('Sell USD 19,250.00');
      expect(page).toContain('RBI: on track');
    }
    expect(board).toContain('kanban-board');
    expect(board).toContain('Switch to list view');
    expect(list).toContain('Switch to board view');
    expect((await agent.get('/merchant?view=list&stage=closed')).text).not.toContain(`href="/merchant/${id}"`);
  });
});
