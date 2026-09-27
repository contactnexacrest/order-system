'use strict';

const db = require('../../src/config/db');
const emailLogRepository = require('../../src/repositories/emailLogRepository');
const emailDispatchService = require('../../src/services/emailDispatchService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * QA-5 EML-06 (external QA report cross-verification): an overlapping run
 * of the deferred-email dispatch job — a slow run still going when the
 * next scheduler tick fires, or an in-process scheduler racing an OS cron
 * entry pointed at the same script — could pull the same 'approved'
 * email_log row via dueForSend() twice and send it twice. Fixed by making
 * emailDispatchService.dispatch() atomically claim the row
 * (emailLogRepository.claimForSend(): UPDATE ... WHERE status =
 * 'approved') before doing anything else, so only one of two racing
 * attempts can ever proceed.
 */
describe('emailDispatchService — EML-06 double-send guard', () => {
  async function createTestDocument(orderId, typeCode = 'QT', status = 'draft', pdfFileId = null) {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const result = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status, pdf_file_id)
       VALUES (:order_id, :type_id, :ref, :status, :pdf_file_id)`,
      { order_id: orderId, type_id: type.id, ref: `JEST-EML06-${Math.random().toString(16).slice(2, 10)}`, status, pdf_file_id: pdfFileId }
    );
    return result.insertId;
  }

  async function createTestFile(orderId) {
    const result = await db.execute(
      `INSERT INTO file_store (order_id, file_origin, server_path, uuid_filename, original_filename, file_size_bytes, mime_type)
       VALUES (:order_id, 'RECEIVED', :path, :uuid, 'test-file.pdf', 1024, 'application/pdf')`,
      { order_id: orderId, path: `/tmp/jest-eml06-file-${Math.random().toString(16).slice(2, 10)}.pdf`, uuid: `${Math.random().toString(16).slice(2)}.pdf` }
    );
    return result.insertId;
  }

  afterAll(async () => {
    await db.pool.end();
  });

  it('claimForSend wins once and loses on a second attempt', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const documentId = await createTestDocument(orderId, 'QT', 'approved', null);
    const emailLogId = await emailLogRepository.create(orderId, documentId, 'send_qt', 'buyer@example.test', 'Subject', 'Body', null, 1);
    await emailLogRepository.approve(emailLogId, 1);

    await expect(emailLogRepository.claimForSend(emailLogId)).resolves.toBe(true);
    expect((await emailLogRepository.find(emailLogId)).status).toBe('sending');

    await expect(emailLogRepository.claimForSend(emailLogId)).resolves.toBe(false);
  });

  it('claimForSend refuses a row that was never approved', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const documentId = await createTestDocument(orderId, 'QT', 'approved', null);
    const emailLogId = await emailLogRepository.create(orderId, documentId, 'send_qt', 'buyer@example.test', 'Subject', 'Body', null, 1);
    // Deliberately not approved — still 'pending_approval'.

    await expect(emailLogRepository.claimForSend(emailLogId)).resolves.toBe(false);
    expect((await emailLogRepository.find(emailLogId)).status).toBe('pending_approval');
  });

  // The exact shape of the original vulnerability: dispatch() called twice
  // on the row a dueForSend() query returned to two overlapping runs
  // before either had updated it. The second call must be a complete
  // no-op — no second mail-send attempt, no second mutation of the row at
  // all — whatever the first call's own outcome was.
  it('dispatch called twice on the same approved row only ever acts once', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const fileId = await createTestFile(orderId);
    const documentId = await createTestDocument(orderId, 'QT', 'approved', fileId);
    const emailLogId = await emailLogRepository.create(orderId, documentId, 'send_qt', 'buyer@example.test', 'Subject', 'Body', null, 1);
    await emailLogRepository.approve(emailLogId, 1);

    // First call: whatever real-world outcome (sent or failed — SMTP may
    // not be configured in this environment), it must move the row off
    // 'approved' via the atomic claim.
    await emailDispatchService.dispatch(await emailLogRepository.find(emailLogId));
    const afterFirstCall = await emailLogRepository.find(emailLogId);
    expect(afterFirstCall.status).not.toBe('approved');

    // Second call: simulates the overlapping run reaching this same row
    // from its own dueForSend() snapshot, taken before the first call's
    // claim landed.
    const secondResult = await emailDispatchService.dispatch(afterFirstCall);
    const afterSecondCall = await emailLogRepository.find(emailLogId);

    expect(secondResult).toBe(false);
    expect(afterSecondCall).toEqual(afterFirstCall);
  });
});
