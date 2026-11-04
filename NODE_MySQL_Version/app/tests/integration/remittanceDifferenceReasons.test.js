'use strict';

const path = require('path');
const nunjucks = require('nunjucks');
const db = require('../../src/config/db');
const ordersController = require('../../src/controllers/ordersController');
const remittanceReasonController = require('../../src/controllers/remittanceReasonController');
const orderCostEntryRepository = require('../../src/repositories/orderCostEntryRepository');
const partPaymentService = require('../../src/services/partPaymentService');
const remittanceDifference = require('../../src/services/remittanceDifference');
const stageGateService = require('../../src/services/stageGateService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of RemittanceDifferenceReasonsTest.php. Owner follow-up on point 18
 * (2 Nov 2026): "Why is it different?" is a drop-down of the usual reasons,
 * kept by Admin (Settings → Remittance difference reasons), each treated as
 * bank charges / buyer paid less / buyer paid more, with "Other — type the
 * reason" last. Only reasons that fit the amount are accepted; a reason's
 * treatment is fixed once used. Jest runs on the shared dev database, so the
 * reasons this test adds have unique names.
 */
describe('Remittance difference reasons (admin-managed list + Other)', () => {
  let admin;
  const tag = Math.random().toString(16).slice(2, 8);

  afterAll(async () => {
    await db.execute('DELETE FROM remittance_difference_reasons WHERE label LIKE :l', { l: `%${tag}%` });
    await db.pool.end();
  });

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Reasons User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-reasons-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return { id: result.insertId, roleId: role.id, name: 'Jest Reasons User' };
  }

  async function permissionsOf(roleId) {
    const rows = await db.query('SELECT p.permission_key FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = :r', { r: roleId });
    return Object.fromEntries(rows.map((r) => [r.permission_key, true]));
  }

  async function run(controller, action, body, params = {}) {
    const rendered = {};
    const req = { user: { id: admin.id, role_id: admin.roleId, name: admin.name }, permissions: await permissionsOf(admin.roleId), body, params, session: {} };
    const res = { redirect: () => {}, renderView: (v, ctx) => { rendered.view = v; rendered.ctx = ctx; }, status: () => res, send: () => {} };
    await controller[action](req, res);
    const flashes = req.session._flash || [];
    return { flash: flashes[flashes.length - 1] || { type: '', message: '' }, rendered };
  }

  async function orderAtAdvance() {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
    await db.execute("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 45, 4500)", { o: orderId });
    await db.execute('UPDATE orders SET advance_pct = 40 WHERE id = :o', { o: orderId });
    await stageGateService.passAndUnlockNext(orderId, 1, null);
    await stageGateService.passAndUnlockNext(orderId, 2, null);
    const type = await db.queryOne("SELECT id FROM document_types WHERE code = 'PI'");
    await db.execute("INSERT INTO documents (order_id, document_type_id, document_reference, status) VALUES (:o, :t, :r, 'approved')", { o: orderId, t: type.id, r: `JEST-RR-${Math.random().toString(16).slice(2, 10)}` });
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
    return orderId;
  }

  const advance = (amount, extra) => ({ advance_amount: amount, advance_received_at: '2026-10-20', advance_inr_unknown: '1', advance_exchange_rate: '84', advance_bank_reference: 'UTR990011', ...extra });
  const idOf = async (label) => Number((await db.queryOne('SELECT id FROM remittance_difference_reasons WHERE label = :l', { l: label })).id);

  beforeAll(async () => {
    admin = await createTestUser('Admin');
  });

  it('reads the seeded list and a posted choice', async () => {
    const list = await remittanceDifference.reasons();
    const seeded = list.filter((r) => !r.label.includes(tag));
    const count = (t) => seeded.filter((r) => r.treatment === t).length;
    expect([count('bank_charges') >= 4, count('short') >= 4, count('excess') >= 3]).toEqual([true, true, true]);
    expect(seeded.filter((r) => r.treatment === 'bank_charges')[0].label).toBe('Intermediary / correspondent bank charges deducted');

    expect((await remittanceDifference.resolvePosted({})).treatment).toBeNull();
    const listed = await remittanceDifference.resolvePosted({ difference_reason_choice: String(await idOf('Buyer deducted an agreed discount')) });
    expect([listed.treatment, listed.reason_text, listed.custom]).toEqual(['short', 'Buyer deducted an agreed discount', false]);

    expect((await remittanceDifference.resolvePosted({ difference_reason_choice: 'other', difference_reason_other: 'too short', difference_treatment: 'short' })).error)
      .toBe('Type the reason the amount is different (at least 10 characters), or choose one from the list.');
    expect((await remittanceDifference.resolvePosted({ difference_reason_choice: 'other', difference_reason_other: 'Buyer sent part by cheque', difference_treatment: '' })).error)
      .toBe('Choose how to treat the difference: bank charges, buyer paid less, or buyer paid more.');
    const other = await remittanceDifference.resolvePosted({ difference_reason_choice: 'other', difference_reason_other: '  Buyer sent   part by cheque ', difference_treatment: 'short' });
    expect([other.treatment, other.reason_id, other.reason_text, other.custom]).toEqual(['short', null, 'Buyer sent part by cheque', true]);

    expect((await remittanceDifference.resolvePosted({ difference_reason_choice: 'bank_charges' })).error).toBe('Choose a reason from the list, or "Other — type the reason".');
    await run(remittanceReasonController, 'create', { label: `Old reason ${tag} switched off`, treatment: 'excess' });
    const offId = await idOf(`Old reason ${tag} switched off`);
    await run(remittanceReasonController, 'toggle', {}, { id: String(offId) });
    expect((await remittanceDifference.resolvePosted({ difference_reason_choice: String(offId) })).error).toBe('That reason is no longer in the list. Choose another one.');
  });

  it('refuses a reason that does not fit the amount', async () => {
    const orderId = await orderAtAdvance();
    const call = async (body) => {
      const req = { user: { id: admin.id, role_id: admin.roleId, name: admin.name }, permissions: await permissionsOf(admin.roleId), body, params: { id: String(orderId) }, session: {} };
      const res = { redirect: () => {}, renderView: () => {}, status: () => res, send: () => {} };
      await ordersController.recordAdvancePayment(req, res);
      const f = req.session._flash || [];
      return f[f.length - 1] || { type: '', message: '' };
    };
    let r = await call(advance('1700', { difference_reason_choice: String(await idOf('Buyer paid extra — adjust against balance')) }));
    expect(r.type).toBe('error');
    expect(r.message).toBe('USD 1,700.00 is not more than the advance still due (USD 1,800.00) — choose another reason, or "Amount as due — no difference".');
    r = await call(advance('1900', { difference_reason_choice: String(await idOf("Our bank deducted its charges from the amount credited")) }));
    expect(r.message).toBe('Bank charges apply only when less than the advance still due arrived (USD 1,800.00). Nothing is missing here — choose "Amount as due — no difference".');
    const saved = await db.queryOne('SELECT COUNT(*) AS n FROM order_payment_receipts WHERE order_id = :o', { o: orderId });
    expect(Number(saved.n)).toBe(0);
  });

  it('saves a typed-in reason and the description uses it', async () => {
    const orderId = await orderAtAdvance();
    const text = `SWIFT fee and courier charge deducted ${tag}`;
    const req = {
      user: { id: admin.id, role_id: admin.roleId, name: admin.name }, permissions: await permissionsOf(admin.roleId), params: { id: String(orderId) }, session: {},
      body: advance('1785', { difference_reason_choice: 'other', difference_reason_other: text, difference_treatment: 'bank_charges', bank_charges: '15' }),
    };
    const res = { redirect: () => {}, renderView: () => {}, status: () => res, send: () => {} };
    await ordersController.recordAdvancePayment(req, res);
    expect((req.session._flash || []).slice(-1)[0].type).toBe('success');
    const receipt = (await partPaymentService.summary(orderId, 'advance')).receipts[0];
    expect([receipt.difference_reason, receipt.difference_reason_id, receipt.difference_reason_text, Number(receipt.difference_reason_custom)]).toEqual(['bank_charges', null, text, 1]);
    const costs = await orderCostEntryRepository.forOrder(orderId);
    expect(costs[0].description.endsWith(`· bank ref UTR990011 · Reason: ${text}`)).toBe(true);
    const custom = (await remittanceDifference.customReasonsUsed()).find((c) => c.reason === text);
    expect([custom.treatment, Number(custom.uses)]).toEqual(['bank_charges', 1]);
    const audit = await db.queryOne("SELECT reason FROM audit_log WHERE action_type = 'REMITTANCE_BANK_CHARGES_RECORDED' AND entity_id = :r", { r: receipt.id });
    expect(audit.reason).toContain(`Reason: ${text}`);
  });

  it('admin adds, renames, reorders and switches off; the treatment locks once used', async () => {
    const label = `SWIFT charges deducted ${tag}`;
    expect((await run(remittanceReasonController, 'create', { label, treatment: 'bank_charges', sort_order: '' })).flash.type).toBe('success');
    const id = await idOf(label);
    const max = await db.queryOne('SELECT MAX(sort_order) AS m FROM remittance_difference_reasons');
    expect(Number((await db.queryOne('SELECT sort_order FROM remittance_difference_reasons WHERE id = :id', { id })).sort_order)).toBe(Number(max.m));
    expect((await run(remittanceReasonController, 'create', { label: label.toLowerCase(), treatment: 'short' })).flash.message).toBe(`A reason called "${label.toLowerCase()}" is already in the list.`);
    expect((await run(remittanceReasonController, 'create', { label: `Something new ${tag}`, treatment: 'refund' })).flash.message).toBe('Choose how the reason is treated: bank charges, buyer paid less, or buyer paid more.');

    const renamed = `SWIFT fee deducted ${tag}`;
    expect((await run(remittanceReasonController, 'update', { label: renamed, treatment: 'short', sort_order: '5' }, { id: String(id) })).flash.type).toBe('success');
    const row = await db.queryOne('SELECT label, treatment, sort_order FROM remittance_difference_reasons WHERE id = :id', { id });
    expect([row.label, row.treatment, Number(row.sort_order)]).toEqual([renamed, 'short', 5]);

    const orderId = await orderAtAdvance();
    const req = { user: { id: admin.id, role_id: admin.roleId, name: admin.name }, permissions: await permissionsOf(admin.roleId), params: { id: String(orderId) }, session: {}, body: advance('1700', { difference_reason_choice: String(id) }) };
    const res = { redirect: () => {}, renderView: () => {}, status: () => res, send: () => {} };
    await ordersController.recordAdvancePayment(req, res);
    expect((req.session._flash || []).slice(-1)[0].type).toBe('success');
    expect((await run(remittanceReasonController, 'update', { label: renamed, treatment: 'bank_charges', sort_order: '5' }, { id: String(id) })).flash.message)
      .toBe(`"${renamed}" is already on 1 receipt(s), so how it is treated cannot change. Switch it off and add a new reason instead.`);
    expect((await run(remittanceReasonController, 'update', { label: `${renamed} by a bank`, treatment: 'short', sort_order: '5' }, { id: String(id) })).flash.type).toBe('success');

    expect((await run(remittanceReasonController, 'toggle', {}, { id: String(id) })).flash.message).toContain('is switched off');
    expect((await remittanceDifference.reasons()).map((r) => r.id)).not.toContain(id);
    expect((await partPaymentService.summary(orderId, 'advance')).receipts[0].difference_reason_text).toBe(renamed);
    expect((await run(remittanceReasonController, 'toggle', {}, { id: String(id) })).flash.message).toContain('is back in the list');

    const codes = (await db.query("SELECT action_type FROM audit_log WHERE entity_type = 'remittance_difference_reasons' AND entity_id = :id ORDER BY id", { id })).map((x) => x.action_type);
    expect(codes).toEqual(['REMITTANCE_REASON_ADDED', 'REMITTANCE_REASON_UPDATED', 'REMITTANCE_REASON_UPDATED', 'REMITTANCE_REASON_UPDATED', 'REMITTANCE_REASON_UPDATED', 'REMITTANCE_REASON_DEACTIVATED', 'REMITTANCE_REASON_REACTIVATED']);
  });

  it('the form partial and the admin page show the list', async () => {
    const env = new nunjucks.Environment(new nunjucks.FileSystemLoader(path.join(__dirname, '../../views')));
    env.addFilter('float', (v) => parseFloat(v) || 0);
    env.addFilter('money', (v) => Number(v).toFixed(2));
    const html = env.render('orders/_remittance_difference.njk', {
      rd: { label: 'Advance', due: 1800, so_far: 0, currency: 'USD', max_pct: 3 }, remittanceReasons: await remittanceDifference.reasons(),
    });
    expect(html).toContain('<option value="" data-none>Amount as due — no difference</option>');
    expect(html).toContain('data-treatment-group="bank_charges"');
    expect(html).toContain('>Intermediary / correspondent bank charges deducted</option>');
    expect(html).toContain('<option value="other" data-treatment="other">Other — type the reason</option>');
    expect(html).toContain('name="difference_reason_other"');
    expect(html).toContain('name="difference_treatment" required data-remit-treatment');

    const { rendered } = await run(remittanceReasonController, 'index', {});
    expect(rendered.view).toBe('settings/remittance_reasons');
    const page = env.render('settings/remittance_reasons.njk', { ...rendered.ctx, csrfToken: 'x' });
    expect(page).toContain('Remittance difference reasons');
    expect(page).toContain('value="Buyer deducted a quality / quantity claim"');
    expect(page).toContain('Reasons typed in by hand');
  });
});
