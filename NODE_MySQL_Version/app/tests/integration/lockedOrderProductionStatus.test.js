'use strict';

const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const ordersController = require('../../src/controllers/ordersController');
const orderRepository = require('../../src/repositories/orderRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of LockedOrderProductionStatusTest.php. QA Round 5, R5-01: on a
 * locked (fully settled) order, changing the production status is a
 * locked-order edit like any other — it needs "Override locked data" and
 * a reason, and is audit-logged as ORDER_EDITED_WHILE_LOCKED.
 */
describe('Production status on a locked order (QA Round 5, R5-01)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function lockedOrder() {
    const orderId = await createTestOrder(await createTestClient());
    await db.execute("UPDATE orders SET is_locked = 1, status = 'complete', production_status_text = 'Shipped' WHERE id = :id", { id: orderId });
    return orderId;
  }

  async function update(orderId, permissions, body) {
    const req = { params: { id: String(orderId) }, user: { id: (await db.queryOne('SELECT id FROM users ORDER BY id LIMIT 1')).id }, permissions, body, session: {} };
    await ordersController.updateProductionStatus(req, { redirect() {} });
    return flash.pull(req);
  }

  const lockedEditAuditCount = async (orderId) => Number((await db.queryOne(
    "SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'ORDER_EDITED_WHILE_LOCKED' AND entity_id = :id AND field_name = 'production_status'",
    { id: orderId }
  )).c);

  it('needs a reason even with the override permission', async () => {
    const orderId = await lockedOrder();
    const messages = await update(orderId, { manage_orders: true, edit_locked_data: true }, { production_status_text: 'Changed after lock' });
    expect((await orderRepository.find(orderId)).production_status_text).toBe('Shipped');
    expect(messages[0].type).toBe('error');
    expect(await lockedEditAuditCount(orderId)).toBe(0);
  });

  it('saves and logs the change with the override permission and a reason', async () => {
    const orderId = await lockedOrder();
    await update(orderId, { manage_orders: true, edit_locked_data: true }, { production_status_text: 'Changed after lock', reason: 'Correcting a typo in the final status' });
    expect((await orderRepository.find(orderId)).production_status_text).toBe('Changed after lock');
    expect(await lockedEditAuditCount(orderId)).toBe(1);
  });

  it('refuses without the override permission', async () => {
    const orderId = await lockedOrder();
    await update(orderId, { manage_orders: true }, { production_status_text: 'Changed after lock', reason: 'Correcting a typo in the final status' });
    expect((await orderRepository.find(orderId)).production_status_text).toBe('Shipped');
    expect(await lockedEditAuditCount(orderId)).toBe(0);
  });
});
