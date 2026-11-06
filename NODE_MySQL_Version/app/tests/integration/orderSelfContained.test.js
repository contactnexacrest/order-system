'use strict';

// Owner decision D-262 (6 Nov 2026, double-confirmed): an order is self-contained.
// Once its PI is approved, editing a master (a clause, the tolerance, the company bank,
// a preset's approval flag, the pricing exchange rate, a category, a compliance task's
// name, a product's Zoho item, a vendor's identity, the Merchant Trade settings, an
// associate's GST) never changes that order's next document, its gates or its money.
// Before the PI is approved an order follows today's settings, as before.
// PHP twin: OrderSelfContainedTest.php.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const orderVendorBillRepository = require('../../src/repositories/orderVendorBillRepository');
const productCategoryRepository = require('../../src/repositories/productCategoryRepository');
const zohoPurchaseRepository = require('../../src/repositories/zohoPurchaseRepository');
const incentiveRepository = require('../../src/repositories/incentiveRepository');
const associateCommission = require('../../src/services/associateCommission');
const categoryDocumentProfile = require('../../src/services/categoryDocumentProfile');
const documentGenerationService = require('../../src/services/documentGenerationService');
const merchantTradeService = require('../../src/services/merchantTradeService');
const orderSnapshotService = require('../../src/services/orderSnapshotService');
const paymentTermsApprovalService = require('../../src/services/paymentTermsApprovalService');
const shipmentGateService = require('../../src/services/shipmentGateService');
const zohoInvoiceData = require('../../src/services/zohoInvoiceData');
const orderSnapshotContext = require('../../src/helpers/orderSnapshotContext');
const { renderPdfFromHtml } = require('../../src/services/pdfRenderService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

const text = (html) => html.replace(/<style[\s\S]*?<\/style>/g, ' ').replace(/<[^>]+>/g, ' ')
  .replace(/&#39;|&#x27;|&apos;/g, "'").replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>')
  .replace(/&nbsp;|&#160;/g, ' ').replace(/&amp;/g, '&').replace(/\s+/g, ' ');
const rand = () => Math.random().toString(16).slice(2, 10);

describe('D-262: an order is self-contained once its PI is approved', () => {
  afterAll(async () => { await db.pool.end(); });

  const settingsBefore = {};
  let fxBefore = null;
  let clauseBefore = null;
  let taskNameBefore = null;

  async function user(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const r = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Selfcontained', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-selfcontained-${rand()}@nexacrest.test`, role_id: role.id }
    );
    return r.insertId;
  }

  beforeEach(async () => {
    for (const k of ['bank_account_no', 'lut_number', 'quantity_shortfall_tolerance_pct']) settingsBefore[k] = await companySettingsRepository.companyValue(k);
    const fx = await db.queryOne("SELECT setting_value FROM pricing_settings WHERE setting_key = 'pricing_fx_rate'");
    fxBefore = fx ? fx.setting_value : null;
    const t = await db.queryOne("SELECT name FROM compliance_task_types WHERE task_key = 'pre_shipment_inspection'");
    taskNameBefore = t ? t.name : null;
    clauseBefore = null;
  });

  afterEach(async () => {
    for (const [k, v] of Object.entries(settingsBefore)) await companySettingsRepository.set(k, v);
    if (fxBefore !== null) await db.execute("UPDATE pricing_settings SET setting_value = :v WHERE setting_key = 'pricing_fx_rate'", { v: fxBefore });
    if (clauseBefore) await db.execute('UPDATE tc_clauses SET clause_text = :t WHERE id = :id', { t: clauseBefore.clause_text, id: clauseBefore.id });
    if (taskNameBefore !== null) await db.execute("UPDATE compliance_task_types SET name = :n WHERE task_key = 'pre_shipment_inspection'", { n: taskNameBefore });
    await db.execute("UPDATE payment_presets SET requires_md_approval = 0 WHERE preset_name = 'Standard — New Buyer'");
  });

  async function engineeringCategory() {
    const id = await productCategoryRepository.create(`TEST-Selfcontained Eng ${rand()}`, ['Material'], await user('Admin'));
    await productCategoryRepository.updateSettings(id, {
      default_unit: 'PCS', allowed_units: 'PCS', default_hs_code: '848180',
      packing_type: 'Wooden Boxes', quality_documents: 'Mill Test Certificate 3.1',
      tc_clause_set: 'engineering',
    });
    return id;
  }

  async function order(categoryId = null) {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
    const lineId = await orderProductRepository.add(orderId, 1, 'Ball Valve', null, null, '200', false, 'PCS', '42.00', '848180');
    const cid = categoryId || await engineeringCategory();
    await productCategoryRepository.setLineCategory(lineId, cid, { Material: 'SS316' });
    await db.execute('UPDATE orders SET estimated_package_count = 8 WHERE id = :id', { id: orderId });
    return [orderId, cid];
  }

  async function approvePi(orderId) {
    const pi = await documentGenerationService.generate(orderId, 'PI', await user('Admin'), null, false, true);
    await documentGenerationService.finalizeApproval(pi.document_id);
  }

  async function ocText(orderId) {
    await documentGenerationService.generate(orderId, 'OC', await user('Admin'), null, false, true);
    const calls = renderPdfFromHtml.mock.calls;
    return text(calls[calls.length - 1][0]);
  }

  /** Edits every master the order's documents read. */
  async function editMasters(categoryId) {
    await companySettingsRepository.set('bank_account_no', 'NEW-ACCOUNT-999');
    await companySettingsRepository.set('lut_number', 'NEW-LUT-2027');
    await companySettingsRepository.set('quantity_shortfall_tolerance_pct', '9');
    const clause = await db.queryOne("SELECT id, clause_text FROM tc_clauses WHERE clause_set = 'engineering' AND status = 'active' ORDER BY id LIMIT 1");
    expect(clause).toBeTruthy();
    clauseBefore = clause;
    await db.execute('UPDATE tc_clauses SET clause_text = :t WHERE id = :id', { t: 'CHANGED CLAUSE TEXT AFTER THE PI', id: clause.id });
    await productCategoryRepository.updateSettings(categoryId, {
      default_unit: 'PCS', allowed_units: 'PCS', default_hs_code: '848180',
      packing_type: 'Steel Drums', quality_documents: 'Hydro Test Report',
      tc_clause_set: 'engineering',
    });
    await db.execute("UPDATE payment_presets SET requires_md_approval = 1 WHERE preset_name = 'Standard — New Buyer'");
    await db.execute("UPDATE pricing_settings SET setting_value = '99.5000' WHERE setting_key = 'pricing_fx_rate'");
  }

  it('approving the PI freezes the order once', async () => {
    const [orderId] = await order();
    expect(await orderSnapshotService.isFrozen(orderId)).toBe(false);
    await approvePi(orderId);
    const o = await orderRepository.find(orderId);
    expect(o.frozen_at).not.toBeNull();
    expect(Number(o.payment_terms_requires_md_approval)).toBe(0);
    expect(o.quantity_tolerance_pct).not.toBeNull();
    const snap = await orderSnapshotService.snapshot(orderId);
    expect(snap.company.bank_account_no).toBe(await companySettingsRepository.companyValue('bank_account_no'));
    expect(Object.keys(snap.terms)).toContain('PI|standard');
    expect(await orderSnapshotService.freeze(orderId)).toBe(false);
  });

  it("a master edit after the PI never changes the order's next document", async () => {
    const [orderId, categoryId] = await order();
    await approvePi(orderId);
    const [liveOrder] = await order(categoryId); // same category, PI not approved: follows the new settings
    await editMasters(categoryId);

    const oc = await ocText(orderId);
    expect(oc).not.toContain('NEW-ACCOUNT-999');
    expect(oc).not.toContain('CHANGED CLAUSE TEXT AFTER THE PI');
    expect(oc).not.toContain('Hydro Test Report');
    expect(oc).toContain('Mill Test Certificate 3.1');

    const profile = await categoryDocumentProfile.forOrder(orderId);
    expect(profile.packing_label).toBe('Wooden Boxes');
    expect(profile.quality_documents.map((q) => q.name)).toEqual(['Mill Test Certificate 3.1']);

    const liveProfile = await categoryDocumentProfile.forOrder(liveOrder);
    expect(liveProfile.packing_label).toBe('Steel Drums');
    expect(await ocText(liveOrder)).toContain('CHANGED CLAUSE TEXT AFTER THE PI');
    expect(orderSnapshotContext.current()).toBeFalsy(); // no frozen values leak out of a document run
  });

  it('gates and money stay with the order', async () => {
    const [orderId, categoryId] = await order();
    await db.execute("UPDATE orders SET currency_code = 'EUR' WHERE id = :id", { id: orderId });
    await approvePi(orderId);
    const fxAtPi = Number((await orderRepository.find(orderId)).pricing_fx_rate_at_pi);
    await editMasters(categoryId);
    const o = await orderRepository.find(orderId);

    expect((await paymentTermsApprovalService.state(orderId)).required).toBe(false);
    expect(await associateCommission.usdRate(o)).toBe(fxAtPi);
    expect(await associateCommission.usdRate(o)).not.toBe(99.5);
    expect(await orderSnapshotService.tolerancePct(o)).toBe(Number(settingsBefore.quantity_shortfall_tolerance_pct));
  });

  it('the inspection gate follows the fixed task key, not the name', async () => {
    const [orderId] = await order();
    expect(await shipmentGateService.inspectionApplies(orderId)).toBe(true);
    await db.execute("UPDATE compliance_task_types SET name = 'PSI by SGS' WHERE task_key = 'pre_shipment_inspection'");
    expect(await shipmentGateService.inspectionApplies(orderId)).toBe(true);
  });

  it('the Zoho item and the vendor identity stay with the order', async () => {
    const [orderId] = await order();
    const p = await db.execute("INSERT INTO catalog_products (name, hs_code, specifications, zoho_item_id) VALUES ('TEST-Selfcontained Valve', '848180', '', 'ZITEM-OLD')");
    const productId = p.insertId;
    const pi = await documentGenerationService.generate(orderId, 'PI', await user('Admin'), null, false, true);
    await db.execute('UPDATE order_products SET catalog_product_id = :p WHERE order_id = :o', { p: productId, o: orderId });
    await documentGenerationService.finalizeApproval(pi.document_id);
    await db.execute("UPDATE catalog_products SET zoho_item_id = 'ZITEM-NEW' WHERE id = :p", { p: productId });
    expect((await db.queryOne('SELECT zoho_item_id FROM order_products WHERE order_id = :o', { o: orderId })).zoho_item_id).toBe('ZITEM-OLD');
    const lines = await zohoInvoiceData.ciLines(orderId, 'Invoice');
    expect(lines[0].item_id).toBe('ZITEM-OLD');

    const lp = await db.execute("INSERT INTO logistics_partners (partner_name, service_type, gstin, pan) VALUES ('TEST-Selfcontained Forwarder', 'freight_forwarder', '33AAAAA0000A1Z5', 'AAAAA0000A')");
    const billId = await orderVendorBillRepository.create(orderId, 'logistics', {
      logistics_partner_id: lp.insertId, bill_for: 'ocean_freight', payment_kind: 'final', doc_kind: 'invoice',
      bill_no: 'TEST-SC-1', bill_date: '2026-11-06', taxable_inr: 1000, gst_inr: 0,
    }, await user('Admin'));
    await db.execute("UPDATE logistics_partners SET partner_name = 'TEST-Renamed Forwarder', gstin = '33BBBBB1111B1Z5', pan = 'BBBBB1111B' WHERE id = :id", { id: lp.insertId });
    const bill = await zohoPurchaseRepository.findBill(billId);
    expect([bill.vendor_name, bill.vendor_gstin, bill.vendor_pan]).toEqual(['TEST-Selfcontained Forwarder', '33AAAAA0000A1Z5', 'AAAAA0000A']);
  });

  it('merchant deals and associate payouts keep their own settings', async () => {
    const frozen = { rbi_settings_json: JSON.stringify({ mtt_max_months_total: '9', mtt_max_outlay_months: '4', mtt_advance_limit_usd: '500000', mtt_usd_inr_rate: '84.0000', bank_documents: { inv: 'Invoice' } }) };
    const s = await merchantTradeService.dealRbiSettings(frozen);
    expect([s.mtt_max_months_total, s.mtt_max_outlay_months, s.mtt_advance_limit_usd, s.mtt_usd_inr_rate]).toEqual(['9', '4', '500000', '84.0000']);
    expect(await merchantTradeService.dealBankDocuments(frozen)).toEqual({ inv: 'Invoice' });
    expect(await merchantTradeService.dealRbiSettings({ rbi_settings_json: null })).toEqual(await merchantTradeService.rbiSettings());

    const [orderId] = await order();
    const a = await db.execute("INSERT INTO associates (name, status, commission_mode, gstin) VALUES ('TEST-Selfcontained Assoc', 'active', 'ladder', '33CCCCC2222C1Z5')");
    const month = new Date(Date.now() + 330 * 60000).toISOString().slice(0, 7);
    const payoutId = await incentiveRepository.addPayout({
      order_id: orderId, payee_kind: 'associate', associate_id: a.insertId, role: 'associate', amount_inr: 1000, incentive_month: month,
    });
    await db.execute('UPDATE associates SET gstin = NULL WHERE id = :id', { id: a.insertId });
    const row = await db.queryOne('SELECT gst_pct, associate_gstin FROM incentive_payouts WHERE id = :id', { id: payoutId });
    expect(row.associate_gstin).toBe('33CCCCC2222C1Z5');
    expect(Number(row.gst_pct)).toBe((await associateCommission.settings()).gst_pct);
  });

  it('division documents keep the company details they were fixed with', async () => {
    const read = orderSnapshotService.companyReader(JSON.stringify({ bank_account_no: 'FROZEN-1' }));
    await companySettingsRepository.set('bank_account_no', 'NEW-ACCOUNT-999');
    expect(await read('bank_account_no')).toBe('FROZEN-1');
    expect(await orderSnapshotService.companyReader(null)('bank_account_no')).toBe('NEW-ACCOUNT-999');
  });

  it('the MD refreshes the company details with a reason and it is logged', async () => {
    const [orderId] = await order();
    const md = await user('Managing Director');
    expect(await orderSnapshotService.refreshCompany(orderId, md, 'Bank account changed')).not.toBeNull();
    await approvePi(orderId);
    await companySettingsRepository.set('bank_account_no', 'NEW-ACCOUNT-999');
    expect(await orderSnapshotService.refreshCompany(orderId, md, 'short')).not.toBeNull();
    expect(await orderSnapshotService.refreshCompany(orderId, md, 'Bank account changed to the new SBI account')).toBeNull();
    expect((await orderSnapshotService.snapshot(orderId)).company.bank_account_no).toBe('NEW-ACCOUNT-999');
    const log = await db.queryOne("SELECT reason, new_value FROM audit_log WHERE action_type = 'ORDER_COMPANY_DETAILS_REFRESHED' AND entity_id = :id", { id: orderId });
    expect(log.reason).toBe('Bank account changed to the new SBI account');
    expect(String(log.new_value)).toContain('NEW-ACCOUNT-999');
  });
});
