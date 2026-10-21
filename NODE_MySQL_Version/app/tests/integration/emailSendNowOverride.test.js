'use strict';

const db = require('../../src/config/db');
const emailLogRepository = require('../../src/repositories/emailLogRepository');
const emailDispatchService = require('../../src/services/emailDispatchService');
const auditLogRepository = require('../../src/repositories/auditLogRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of EmailSendNowOverrideTest.php. 90-item backlog Phase 4: the
 * deferred-dispatch job's own docblock anticipated a "future 'send now'
 * button" — an approver who cleared Level-2 approval before a deferred
 * send's scheduled_at had no way to actually make it go out immediately;
 * it still sat waiting for scheduled_at and the next job tick.
 * sendNowOverride() shares dispatch()'s exact code path, so a forced
 * send can never diverge from what the job would have done. Also covers
 * awaitingDispatch() now sorting most-recent-first.
 */
describe('emailDispatchService.sendNowOverride (90-item backlog Phase 4)', () => {
  async function createTestDocument(orderId, typeCode = 'QT', status = 'draft', pdfFileId = null) {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const result = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status, pdf_file_id)
       VALUES (:order_id, :type_id, :ref, :status, :pdf_file_id)`,
      { order_id: orderId, type_id: type.id, ref: `JEST-SENDNOW-${Math.random().toString(16).slice(2, 10)}`, status, pdf_file_id: pdfFileId }
    );
    return result.insertId;
  }

  async function createTestFile(orderId) {
    const result = await db.execute(
      `INSERT INTO file_store (order_id, file_origin, server_path, uuid_filename, original_filename, file_size_bytes, mime_type)
       VALUES (:order_id, 'RECEIVED', :path, :uuid, 'test-file.pdf', 1024, 'application/pdf')`,
      { order_id: orderId, path: `/tmp/jest-sendnow-file-${Math.random().toString(16).slice(2, 10)}.pdf`, uuid: `${Math.random().toString(16).slice(2)}.pdf` }
    );
    return result.insertId;
  }

  afterAll(async () => {
    await db.pool.end();
  });

  it('refuses a row still awaiting approval', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const documentId = await createTestDocument(orderId, 'QT', 'approved', null);
    const emailLogId = await emailLogRepository.create(orderId, documentId, 'send_qt', 'buyer@example.test', 'Subject', 'Body', '2030-01-01 00:00:00', 1);
    // Deliberately not approved — still 'pending_approval'.

    await expect(emailDispatchService.sendNowOverride(emailLogId, 1))
      .rejects.toThrow('Only an already-approved send still waiting to go out can be sent now.');
  });

  it('dispatches an approved row immediately', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const fileId = await createTestFile(orderId);
    const documentId = await createTestDocument(orderId, 'QT', 'approved', fileId);
    const emailLogId = await emailLogRepository.create(orderId, documentId, 'send_qt', 'buyer@example.test', 'Subject', 'Body', '2030-01-01 00:00:00', 1);
    await emailLogRepository.approve(emailLogId, 1);

    await emailDispatchService.sendNowOverride(emailLogId, 1);

    const row = await emailLogRepository.find(emailLogId);
    expect(row.status).not.toBe('approved');

    const log = await auditLogRepository.search({ actionType: 'EMAIL_SEND_FORCED_NOW', limit: 5 });
    expect(log.length).toBeGreaterThan(0);
  });

  it('refuses a row already sent', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const fileId = await createTestFile(orderId);
    const documentId = await createTestDocument(orderId, 'QT', 'approved', fileId);
    const emailLogId = await emailLogRepository.create(orderId, documentId, 'send_qt', 'buyer@example.test', 'Subject', 'Body', null, 1);
    await emailLogRepository.approve(emailLogId, 1);
    await emailDispatchService.dispatch(await emailLogRepository.find(emailLogId));

    await expect(emailDispatchService.sendNowOverride(emailLogId, 1)).rejects.toThrow();
  });

  it('awaitingDispatch sorts most-recent first', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const documentIdA = await createTestDocument(orderId, 'QT', 'approved', null);
    const olderId = await emailLogRepository.create(orderId, documentIdA, 'send_qt', 'buyer@example.test', 'Subject A', 'Body', null, 1);
    await db.execute('UPDATE email_log SET created_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = :id', { id: olderId });

    const documentIdB = await createTestDocument(orderId, 'PI', 'approved', null);
    const newerId = await emailLogRepository.create(orderId, documentIdB, 'send_pi', 'buyer@example.test', 'Subject B', 'Body', null, 1);

    const rows = await emailLogRepository.awaitingDispatch();
    const ids = rows.map((r) => r.id);
    const posNewer = ids.indexOf(newerId);
    const posOlder = ids.indexOf(olderId);

    expect(posNewer).toBeGreaterThanOrEqual(0);
    expect(posOlder).toBeGreaterThanOrEqual(0);
    expect(posNewer).toBeLessThan(posOlder);
  });
});
