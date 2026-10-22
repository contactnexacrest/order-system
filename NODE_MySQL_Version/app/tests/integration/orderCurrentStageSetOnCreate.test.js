'use strict';

const db = require('../../src/config/db');
const orderRepository = require('../../src/repositories/orderRepository');
const orderStageRepository = require('../../src/repositories/orderStageRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of OrderCurrentStageSetOnCreateTest.php. External QA report L-14:
 * "Stage-1 documents are filed under a generic `stage` folder — new
 * orders have no current_stage_id until the first gate passes (151 of
 * 216 orders)." orders.current_stage_id — read by the per-order/
 * per-stage audit file_store folder layout — stayed NULL until
 * stageGateService.passAndUnlockNext() ran for the first time; every
 * brand-new order's own documents filed into a fallback folder in the
 * meantime. orderStageRepository.initializeForOrder() — the one
 * chokepoint every "new order" path already calls — now also sets it to
 * Stage 1 immediately.
 */
describe('orders.current_stage_id set at creation (External QA report L-14)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('initializeForOrder sets current_stage_id to Stage 1', async () => {
    // Deliberately not the createTestOrder() fixture: it already calls
    // initializeForOrder() itself (exactly this fix, now that it's
    // applied), so it can't demonstrate the "before" state. A bare INSERT
    // with no current_stage_id, mirroring exactly what orderRepository's
    // own insert writes, is what actually isolates the behavior under
    // test here.
    const clientId = await createTestClient();
    const preset = await db.queryOne('SELECT id FROM payment_presets LIMIT 1');
    const incoterm = await db.queryOne('SELECT id FROM incoterms LIMIT 1');
    const currency = await db.queryOne('SELECT id FROM currencies LIMIT 1');
    // company_legal_name is included (not left to its NULL default) so
    // this row renders safely through views/orders/index.njk like any
    // other test order would, matching the PHP port's own note on this
    // line.
    const result = await db.execute(
      `INSERT INTO orders
         (order_reference, client_id, sequence_no, buyer_inquiry_ref, payment_preset_id, incoterm_id,
          currency_id, coo_type, buyers_po_ref, quotation_date, quotation_valid_until, status, company_legal_name)
       VALUES
         (:ref, :client_id, 999, 'X', :preset_id, :incoterm_id, :currency_id, 'TBC', 'NIL', CURDATE(), CURDATE(), 'active', 'Jest L-14 Test Buyer Ltd')`,
      {
        ref: `JEST-L14-${Math.random().toString(16).slice(2, 10)}`,
        client_id: clientId, preset_id: preset.id, incoterm_id: incoterm.id, currency_id: currency.id,
      }
    );
    const orderId = result.insertId;
    const before = await orderRepository.find(orderId);
    expect(before.current_stage_id).toBeNull();

    await orderStageRepository.initializeForOrder(orderId);

    const stage1 = await db.queryOne('SELECT id FROM stages_master WHERE stage_number = 1');
    const after = await orderRepository.find(orderId);
    expect(after.current_stage_id).not.toBeNull();
    expect(Number(after.current_stage_id)).toBe(stage1.id);
  });
});
