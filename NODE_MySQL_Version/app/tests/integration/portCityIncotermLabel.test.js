'use strict';

const db = require('../../src/config/db');
const portRepository = require('../../src/repositories/portRepository');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of PortCityIncotermLabelTest.php. 90-item backlog "ports city
 * field": Incoterms labels print the CITY ("FOB Mumbai"), not the port's
 * formal terminal name ("FOB Nhava Sheva (JNPT)").
 */
describe('Port city drives the Incoterms label (90-item backlog "ports city field")', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function setOrderLoadingPort(orderId, portId) {
    await db.execute('UPDATE orders SET port_of_loading_id = :port_id WHERE id = :id', { port_id: portId, id: orderId });
  }

  it("uses the port's city, not its terminal name", async () => {
    const portId = await portRepository.create('Nhava Sheva (JNPT)', 'Mumbai', 'India', 'loading', 99);
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId, 'FOB');
    await setOrderLoadingPort(orderId, portId);

    const data = await documentDataAssembler.assemble(orderId);

    expect(data.order.incoterm_label).toBe('FOB Mumbai — Incoterms® 2020');
    expect(data.order.port_of_loading).toBe('Mumbai');
  });

  it('falls back to the port name when city is blank', async () => {
    const portId = await portRepository.create('Nhava Sheva (JNPT)', null, 'India', 'loading', 98);
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId, 'FOB');
    await setOrderLoadingPort(orderId, portId);

    const data = await documentDataAssembler.assemble(orderId);

    expect(data.order.incoterm_label).toBe('FOB Nhava Sheva (JNPT) — Incoterms® 2020');
  });
});
