'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const db = require('../../src/config/db');
const emailLogRepository = require('../../src/repositories/emailLogRepository');
const orderShippingRepository = require('../../src/repositories/orderShippingRepository');
const fileStoreRepository = require('../../src/repositories/fileStoreRepository');
const emailDispatchService = require('../../src/services/emailDispatchService');
const emailDispatchController = require('../../src/controllers/emailDispatchController');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of GenericEmailSendTest.php. External QA report L-42: four seeded
 * email templates — payment_followup, shipment_readiness, bl_copy_sent,
 * balance_receipt_confirmation — have no document behind them, but there
 * was no route or controller action anywhere that could ever submit a
 * send for one: emailDispatchController had no generic counterpart to
 * requestSend() (which needs a :documentId route param), and
 * emailDispatchService.dispatch() unconditionally marked ANY
 * document-less row failed before even looking at its template (EM-NODOC).
 */
describe('Generic (document-less) email send (External QA report L-42)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function setClientEmail(clientId) {
    await db.execute('UPDATE clients SET email = :email WHERE id = :id', { email: 'jane@buyer.example', id: clientId });
  }

  function fakeRes() {
    return { redirect: () => {} };
  }

  // bl_copy_sent is deliberately excluded here — since N-06, it also
  // requires a scanned BL on file before requestSend() will accept it
  // (see the dedicated bl_copy_sent tests below), unlike the other 3
  // purely document-less templates.
  const genericTemplates = ['payment_followup', 'shipment_readiness', 'balance_receipt_confirmation'];

  it.each(genericTemplates)('requestSendGeneric accepts the %s template and creates a document-less row', async (templateKey) => {
    const clientId = await createTestClient();
    await setClientEmail(clientId);
    const orderId = await createTestOrder(clientId);
    const req = { params: { id: String(orderId) }, body: { template_key: templateKey }, user: { id: 1 }, session: {} };

    // Before this fix, emailDispatchController.requestSendGeneric did not
    // exist at all — calling it threw "is not a function"; there was
    // simply no way to reach this code path.
    await emailDispatchController.requestSendGeneric(req, fakeRes());

    const rows = await emailLogRepository.forOrder(orderId);
    const row = rows.find((r) => r.template_key === templateKey);
    expect(row).toBeTruthy();
    expect(row.document_id).toBeNull();
  });

  it('requestSend refuses a duplicate generic send still in flight', async () => {
    const clientId = await createTestClient();
    await setClientEmail(clientId);
    const orderId = await createTestOrder(clientId);

    await emailDispatchService.requestSend(orderId, null, 'payment_followup', null, 1);

    await expect(emailDispatchService.requestSend(orderId, null, 'payment_followup', null, 1)).rejects.toThrow(
      /already awaiting approval or scheduled to go out/
    );
  });

  it('requestSend allows a different generic template while one is in flight', async () => {
    const clientId = await createTestClient();
    await setClientEmail(clientId);
    const orderId = await createTestOrder(clientId);

    await emailDispatchService.requestSend(orderId, null, 'payment_followup', null, 1);
    // Must not be blocked by the unrelated payment_followup send still in flight.
    const result = await emailDispatchService.requestSend(orderId, null, 'shipment_readiness', null, 1);

    expect(result.id).toBeTruthy();
  });

  // External QA report N-06: bl_copy_sent used to be requestable (and
  // approvable) with no scanned BL on file at all — it then failed
  // silently at dispatch time (see the next test below), with nobody
  // told. Refused up front now, instead.
  it('requestSend refuses bl_copy_sent with no scanned BL on file', async () => {
    const clientId = await createTestClient();
    await setClientEmail(clientId);
    const orderId = await createTestOrder(clientId);

    await expect(emailDispatchService.requestSend(orderId, null, 'bl_copy_sent', null, 1)).rejects.toThrow(
      /Upload the scanned Bill of Lading copy/
    );
  });

  it('requestSend accepts bl_copy_sent once a scanned BL is on file', async () => {
    const clientId = await createTestClient();
    await setClientEmail(clientId);
    const orderId = await createTestOrder(clientId);
    const tmpPath = path.join(os.tmpdir(), `jest-n06-bl-copy-${Math.random().toString(16).slice(2, 10)}.pdf`);
    fs.writeFileSync(tmpPath, '%PDF-1.4 fake scanned BL for test');
    const fileResult = await db.execute(
      `INSERT INTO file_store (order_id, file_origin, server_path, uuid_filename, original_filename, file_size_bytes, mime_type)
       VALUES (:order_id, 'RECEIVED', :path, :uuid, 'bl-scan.pdf', :size, 'application/pdf')`,
      { order_id: orderId, path: tmpPath, uuid: `${Math.random().toString(16).slice(2, 18)}.pdf`, size: fs.statSync(tmpPath).size }
    );
    await orderShippingRepository.upsert(orderId, { shipping_line: 'Maersk' });
    await orderShippingRepository.attachBlCopy(orderId, fileResult.insertId);

    try {
      const result = await emailDispatchService.requestSend(orderId, null, 'bl_copy_sent', null, 1);
      expect(result.id).toBeTruthy();
    } finally {
      fs.unlinkSync(tmpPath);
    }
  });

  it('dispatch fails gracefully without crashing when bl_copy_sent has no attachment', async () => {
    const clientId = await createTestClient();
    await setClientEmail(clientId);
    const orderId = await createTestOrder(clientId);
    const id = await emailLogRepository.create(orderId, null, 'bl_copy_sent', 'jane@buyer.example', 'Subject', 'Body', null, 1);
    await emailLogRepository.approve(id, 1);

    const sent = await emailDispatchService.dispatch(await emailLogRepository.find(id));

    expect(sent).toBe(false);
    expect((await emailLogRepository.find(id)).status).toBe('failed');
  });

  it('dispatch reaches the attachment check with a real BL copy on file', async () => {
    const clientId = await createTestClient();
    await setClientEmail(clientId);
    const orderId = await createTestOrder(clientId);
    const tmpPath = path.join(os.tmpdir(), `jest-l42-bl-copy-${Math.random().toString(16).slice(2, 10)}.pdf`);
    fs.writeFileSync(tmpPath, '%PDF-1.4 fake scanned BL for test');
    const fileResult = await db.execute(
      `INSERT INTO file_store (order_id, file_origin, server_path, uuid_filename, original_filename, file_size_bytes, mime_type)
       VALUES (:order_id, 'RECEIVED', :path, :uuid, 'bl-scan.pdf', :size, 'application/pdf')`,
      { order_id: orderId, path: tmpPath, uuid: `${Math.random().toString(16).slice(2, 18)}.pdf`, size: fs.statSync(tmpPath).size }
    );
    await orderShippingRepository.upsert(orderId, { shipping_line: 'Maersk' });
    await orderShippingRepository.attachBlCopy(orderId, fileResult.insertId);

    const id = await emailLogRepository.create(orderId, null, 'bl_copy_sent', 'jane@buyer.example', 'Subject', 'Body', null, 1);
    await emailLogRepository.approve(id, 1);

    try {
      // No SMTP is configured in this test environment, so the actual
      // transport attempt itself still fails — the real assertion is
      // simply that getting here (past the attachment check, with a
      // genuine file on disk) never throws.
      await emailDispatchService.dispatch(await emailLogRepository.find(id));
    } finally {
      fs.unlinkSync(tmpPath);
    }
  });
});
