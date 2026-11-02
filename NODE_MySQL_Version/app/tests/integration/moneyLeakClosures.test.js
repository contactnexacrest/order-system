'use strict';

/**
 * Owner points 6 / 15 (2 Nov 2026): "only permitted people see amounts — not
 * a single leak". PHP twin: MoneyLeakClosuresTest.php; whole-app proof:
 * e2e/tests/12-money-matrix.spec.js.
 */
const db = require('../../src/config/db');
const documentRepository = require('../../src/repositories/documentRepository');
const fileStoreRepository = require('../../src/repositories/fileStoreRepository');
const auditMoneyMask = require('../../src/services/auditMoneyMask');
const reportController = require('../../src/controllers/reportController');
const { createTestClient, createTestOrder, createTestFile } = require('../support/fixtures');

async function generatedFile(orderId) {
  const id = await createTestFile(orderId, null);
  await db.execute("UPDATE file_store SET file_origin = 'GENERATED_AUTO' WHERE id = :id", { id });
  return id;
}

async function typeId(code) {
  return (await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code })).id;
}

async function permissionsOf(roleName) {
  const rows = await db.query(
    `SELECT p.permission_key FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
     WHERE r.name = :name`,
    { name: roleName }
  );
  return Object.fromEntries(rows.map((r) => [r.permission_key, true]));
}

describe('Money leak closures (owner points 6/15)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('every generated copy keeps its document\'s money class; older CAFIN copies stay CAFIN-only', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const draft = await generatedFile(orderId);
    const final = await generatedFile(orderId);
    const superseded = await generatedFile(orderId);
    const docId = await documentRepository.create(orderId, await typeId('SUPPO'), 'SC/SPO/26/LEAK1', 0, draft, null, null);

    await documentRepository.markApproved(docId, final);
    expect(await fileStoreRepository.moneyClassFor(draft)).toBe('purchase');
    expect(await fileStoreRepository.moneyClassFor(final)).toBe('purchase');
    await documentRepository.markSuperseded(docId, superseded);
    expect(await fileStoreRepository.moneyClassFor(final)).toBe('purchase');
    expect(await fileStoreRepository.moneyClassFor(superseded)).toBe('purchase');

    const oldCafin = await generatedFile(orderId);
    const newCafin = await generatedFile(orderId);
    const cafin = await typeId('CAFIN');
    await documentRepository.create(orderId, cafin, 'SC/CAFIN/26/LEAK1', 0, oldCafin, null, null);
    await documentRepository.create(orderId, cafin, 'SC/CAFIN/26/LEAK1', 1, newCafin, null, null);
    expect(await fileStoreRepository.documentTypeCodeFor(oldCafin)).toBe('CAFIN');
    const dossierIds = (await fileStoreRepository.forOrderExcludingInternalCaDocs(orderId)).map((f) => f.id);
    expect(dossierIds).not.toContain(oldCafin);
    expect(dossierIds).not.toContain(newCafin);
    expect(dossierIds).toContain(draft);
  });

  it('classifies audit rows by the money they carry', async () => {
    const cases = [
      ['ADVANCE_PAYMENT_RECORDED', 'order_payment_status', 'advance_amount', 'buyer_payments'],
      ['CA_EXCHANGE_RATE_RECORDED', 'order_payment_status', 'advance_exchange_rate', 'buyer_payments'],
      ['SUPPLIER_BILL_ADDED', 'order_vendor_bills', 'bill_no', 'purchase'],
      ['LOGISTICS_BILL_ADDED', 'order_vendor_bills', 'bill_no', 'logistics'],
      ['ORDER_EXPENSE_ADDED', 'order_expenses', 'amount_inr', 'financials'],
      ['ORDER_INCENTIVE_SET', 'orders', null, 'incentives'],
      ['INCENTIVE_PAID', 'incentive_payouts', null, 'payouts'],
      ['FIELD_EDIT', 'orders', 'unit_price', 'selling'],
      ['FIELD_EDIT', 'orders', 'port_of_discharge_text', null],
      ['LOGIN_SUCCESS', 'users', null, null],
    ];
    for (const [action, entity, field, cls] of cases) {
      expect([action, await auditMoneyMask.classFor({ action_type: action, entity_type: entity, entity_id: 0, field_name: field })]).toEqual([action, cls]);
    }
  });

  it('hides audit values from people who may not see that money', async () => {
    const row = { action_type: 'ADVANCE_PAYMENT_RECORDED', entity_type: 'order_payment_status', entity_id: 1, field_name: 'advance_amount', old_value: null, new_value: '823.61', reason: 'Received 823.61 less bank charges' };
    const logistics = { action_type: 'LOGISTICS_BILL_ADDED', entity_type: 'orders', entity_id: 1, field_name: 'taxable_inr', old_value: null, new_value: '649.17', reason: null };

    const logisticsExec = await permissionsOf('Logistics Executive');
    const masked = await auditMoneyMask.mask(logisticsExec, row);
    expect(masked.new_value).toBe(auditMoneyMask.HIDDEN);
    expect(masked.reason).not.toContain('823');
    expect((await auditMoneyMask.mask(logisticsExec, logistics)).new_value).toBe('649.17');

    expect((await auditMoneyMask.mask(await permissionsOf('System Administrator'), logistics)).new_value).toBe(auditMoneyMask.HIDDEN);
    expect((await auditMoneyMask.mask(await permissionsOf('Accounts Executive'), row)).new_value).toBe('823.61');
  });

  it('money reports refuse people who may not see that money', async () => {
    const permissions = await permissionsOf('Logistics Executive');
    for (const action of ['aggregate', 'payments', 'trends', 'ageing', 'products', 'performance', 'amendments', 'todaysPayments']) {
      let status = 200;
      let body = '';
      const res = { status(s) { status = s; return this; }, send(b) { body = b; return this; } };
      await reportController[action]({ permissions, query: {}, params: {}, user: { id: 0 } }, res);
      expect([action, status]).toEqual([action, 403]);
      expect(body).toBe('You do not have permission to see the amounts in this report.');
    }
  });
});
