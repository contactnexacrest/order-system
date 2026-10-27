'use strict';

const fs = require('fs');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderRepository = require('../../src/repositories/orderRepository');
const orderStageRepository = require('../../src/repositories/orderStageRepository');
const orderBuyerPoDocumentRepository = require('../../src/repositories/orderBuyerPoDocumentRepository');
const stageGateService = require('../../src/services/stageGateService');
const { createTestClient, createTestOrder, createTestFile, createTestDocument } = require('../support/fixtures');

const csrfOf = (html) => html.match(/name="_csrf"\s+value="([^"]+)"/)[1];

/**
 * Owner request (2026-10-26): Step 4b sends the buyer a Buyer PO Action
 * Link — they download the Buyer PO generated in 4a, upload it signed with
 * their PO number, and Step 4 completes by itself (the same as staff
 * attaching in 4c and confirming in 4d).
 */
describe('Buyer PO Action Link (Step 4b)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function orderAwaitingPo() {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await stageGateService.passAndUnlockNext(orderId, 1, null);
    const fileId = await createTestFile(orderId, clientId);
    const file = await db.queryOne('SELECT server_path FROM file_store WHERE id = :id', { id: fileId });
    const content = '%PDF-1.4 buyer po to sign';
    fs.writeFileSync(file.server_path, content);
    await db.execute('UPDATE file_store SET file_size_bytes = :n WHERE id = :id', { n: content.length, id: fileId });
    const documentId = await createTestDocument(orderId, 'BUYERPO', 'sent');
    await db.execute('UPDATE documents SET pdf_file_id = :f WHERE id = :id', { f: fileId, id: documentId });
    return { orderId, token: await orderRepository.generateBuyerActionToken(orderId) };
  }

  it('lets the buyer download the issued Buyer PO', async () => {
    const { token } = await orderAwaitingPo();
    const page = await request(app).get(`/buyer-action/${token}`);
    expect(page.text).toContain(`/buyer-action/${token}/buyer-po-document`);
    expect(page.text).toContain('name="buyers_po_ref"');

    const download = await request(app).get(`/buyer-action/${token}/buyer-po-document`);
    expect(download.status).toBe(200);
    expect(download.headers['content-disposition']).toContain('attachment');
    expect((await request(app).get('/buyer-action/bogus/buyer-po-document')).text).toContain('Link No Longer Valid');
  });

  it('completes Step 4 when the buyer uploads the signed PO with its number', async () => {
    const { orderId, token } = await orderAwaitingPo();
    const agent = request.agent(app);
    let page = await agent.get(`/buyer-action/${token}`);

    await agent.post(`/buyer-action/${token}/buyer-po`)
      .field('_csrf', csrfOf(page.text)).field('buyers_po_ref', '')
      .attach('document', Buffer.from('%PDF-1.4 signed'), 'signed-po.pdf');
    expect((await orderStageRepository.findByOrderAndStageNumber(orderId, 2)).status).toBe('in_progress');
    expect(await orderBuyerPoDocumentRepository.forOrder(orderId)).toHaveLength(0);

    page = await agent.get(`/buyer-action/${token}`);
    expect(page.text).toContain('Purchase Order number');
    await agent.post(`/buyer-action/${token}/buyer-po`)
      .field('_csrf', csrfOf(page.text)).field('buyers_po_ref', 'PO-BUYER-42')
      .attach('document', Buffer.from('%PDF-1.4 signed'), 'signed-po.pdf');

    expect((await orderStageRepository.findByOrderAndStageNumber(orderId, 2)).status).toBe('gate_passed');
    expect((await orderRepository.find(orderId)).buyers_po_ref).toBe('PO-BUYER-42');
    expect(await orderBuyerPoDocumentRepository.forOrder(orderId)).toHaveLength(1);
    page = await agent.get(`/buyer-action/${token}`);
    expect(page.text).toContain('We have your signed Purchase Order');
    expect(page.text).not.toContain('name="buyers_po_ref"');
  });

  // Owner request (2026-10-26): one step at a time on the link.
  it('offers the issued Order Confirmation for download at the OC step', async () => {
    // Owner decision (2026-10-27): the buyer reads the exact OC they acknowledge.
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    for (const stage of [1, 2, 3]) await stageGateService.passAndUnlockNext(orderId, stage, null);
    const fileId = await createTestFile(orderId, clientId);
    const file = await db.queryOne('SELECT server_path FROM file_store WHERE id = :id', { id: fileId });
    const content = '%PDF-1.4 order confirmation';
    fs.writeFileSync(file.server_path, content);
    await db.execute('UPDATE file_store SET file_size_bytes = :n WHERE id = :id', { n: content.length, id: fileId });
    const documentId = await createTestDocument(orderId, 'OC', 'sent');
    await db.execute('UPDATE documents SET pdf_file_id = :f WHERE id = :id', { f: fileId, id: documentId });
    const reference = (await db.queryOne('SELECT document_reference FROM documents WHERE id = :id', { id: documentId })).document_reference;
    const token = await orderRepository.generateBuyerActionToken(orderId);

    const page = await request(app).get(`/buyer-action/${token}`);
    expect(page.text).toContain(`/buyer-action/${token}/order-confirmation`);
    expect(page.text).toContain(`I have read Order Confirmation ${reference}`);
    const download = await request(app).get(`/buyer-action/${token}/order-confirmation`);
    expect(download.status).toBe(200);
    expect(download.headers['content-disposition']).toContain('attachment');
  });

  it('shows only the payment step once the PI is issued, and waits before that', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    for (const stage of [1, 2]) {
      await stageGateService.passAndUnlockNext(orderId, stage, null);
    }
    const token = await orderRepository.generateBuyerActionToken(orderId);

    let page = await request(app).get(`/buyer-action/${token}`);
    expect(page.text).toContain("We're preparing your Proforma Invoice");
    expect(page.text).not.toContain('Submit Payment Details');
    expect(page.text).not.toContain('name="buyers_po_ref"');

    const documentId = await createTestDocument(orderId, 'PI', 'sent');
    await db.execute('UPDATE documents SET pdf_file_id = :f WHERE id = :id', { f: await createTestFile(orderId, clientId), id: documentId });
    page = await request(app).get(`/buyer-action/${token}`);
    expect(page.text).toContain('Submit Payment Details');
    expect(page.text).toContain(`/buyer-action/${token}/proforma-invoice`);
    expect(page.text).not.toContain('name="buyers_po_ref"');
  });
});
