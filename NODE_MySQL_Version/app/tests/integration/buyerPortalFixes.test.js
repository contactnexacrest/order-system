'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderOcAcknowledgmentRepository = require('../../src/repositories/orderOcAcknowledgmentRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const buyerActionService = require('../../src/services/buyerActionService');
const { createTestClient, createTestOrder, createTestClientLogin, createTestDocument } = require('../support/fixtures');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * Port of BuyerPortalFixesTest.php. Three buyer-portal issues found while recording the
 * buyer tutorials (7 Nov 2026): the OC acknowledgement table's Port of Discharge for a typed
 * port, "Reorder This" unit matching ("sqm" vs "SQM", never a silent switch to PCS), and no
 * Buyer PO upload on a cancelled or lost order.
 */
describe('Buyer-portal fixes (7 Nov 2026)', () => {
  let agent;
  let clientId;
  const email = `jest-bpfix-${Math.random().toString(16).slice(2, 8)}@nexacrest.test`;
  const password = 'JestBuyerFix123!';

  beforeAll(async () => {
    clientId = await createTestClient(email);
    await createTestClientLogin(clientId, password);
    agent = request.agent(app);
    const loginPage = await agent.get('/client/login');
    const res = await agent.post('/client/login').type('form').send({ _csrf: extractCsrf(loginPage.text), email, password });
    expect(res.status).toBe(302);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function orderWithLine(unit) {
    const orderId = await createTestOrder(clientId);
    if (unit !== null) {
      await db.execute("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value, line_type) VALUES (:o, 1, 'Granite slab', 100, :u, 10, 1000, 'goods')", { o: orderId, u: unit });
    }
    return orderId;
  }

  it('the OC acknowledgement shows the typed Port of Discharge', async () => {
    const orderId = await orderWithLine(null);
    await db.execute("UPDATE orders SET port_of_discharge_id = NULL, port_of_discharge_name = NULL, port_of_discharge_city = NULL, port_of_discharge_text = 'Port of Antwerp' WHERE id = :id", { id: orderId });
    const doc = await createTestDocument(orderId, 'OC', 'sent');
    const now = new Date();
    const fmt = (d) => d.toISOString().slice(0, 19).replace('T', ' ');
    await orderOcAcknowledgmentRepository.recordSent(orderId, doc, fmt(now), fmt(new Date(now.getTime() + 2 * 86400000)));

    const page = await agent.get(`/client/orders/${orderId}`);
    expect(page.text).toContain('I acknowledge and confirm to proceed');
    expect(page.text).toMatch(/<th>Port of Discharge<\/th><td>Port of Antwerp<\/td>/);
  });

  it('Reorder matches the unit whatever its case or spacing', async () => {
    const orderId = await orderWithLine(' sqm ');
    const page = await agent.get(`/client/orders/${orderId}/reorder`);
    expect(page.status).toBe(200);
    expect(page.text).toMatch(/<option value="SQM"\s+selected>/);
    expect(page.text).not.toMatch(/<option value="PCS"\s+selected>/);
  });

  it('Reorder never switches an unknown unit to PCS but asks the buyer to pick', async () => {
    const orderId = await orderWithLine('Crates');
    const page = await agent.get(`/client/orders/${orderId}/reorder`);
    expect(page.text).not.toMatch(/<option value="PCS"\s+selected>/);
    expect(page.text).toContain('<option value="" selected>Choose… (your earlier unit: Crates)</option>');
    expect(page.text).toMatch(/<select name="product_unit\[0\]" required>/);
  });

  it.each(['cancelled', 'lost'])('Buyer PO upload is closed on a %s order', async (status) => {
    const orderId = await orderWithLine(null);
    await db.execute('UPDATE orders SET status = :s WHERE id = :id', { s: status, id: orderId });

    const page = await agent.get(`/client/orders/${orderId}`);
    expect(page.text).not.toContain('/buyer-po" enctype="multipart/form-data"');
    expect(page.text).toContain(`This order is ${status}, so a Buyer PO can no longer be uploaded.`);

    const result = await buyerActionService.uploadBuyerPo({ body: {} }, await orderRepository.find(orderId));
    expect(result.ok).toBe(false);
    expect(result.message).toBe(`This order is ${status}, so a Buyer PO can no longer be uploaded. Contact your NexaCrest representative if you have a question.`);
    const n = await db.queryOne('SELECT COUNT(*) AS n FROM order_buyer_po_documents WHERE order_id = :o', { o: orderId });
    expect(Number(n.n)).toBe(0);
  });

  it('the Buyer PO upload form still shows on an open order', async () => {
    const orderId = await orderWithLine(null);
    const page = await agent.get(`/client/orders/${orderId}`);
    expect(page.text).toContain('/buyer-po" enctype="multipart/form-data"');
  });
});
