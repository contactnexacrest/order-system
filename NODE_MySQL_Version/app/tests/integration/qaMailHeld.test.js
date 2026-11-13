'use strict';

const fs = require('fs');
const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const emailLogRepository = require('../../src/repositories/emailLogRepository');
const emailDispatchService = require('../../src/services/emailDispatchService');
const { createTestClient, createTestOrder, createTestFile, createTestDocument, withDocumentMailsHeld } = require('../support/fixtures');

/**
 * QA MTX-003 (D-280): a send held by testing mode (or its switch in Admin ›
 * E-mail settings) is not a failure. The log records it as held with the
 * reason (not 'failed'), the screen says why (not "check the mail
 * configuration"), and staff send it outside the app or submit it again once
 * the hold is lifted; it is never sent later by itself. Port of QaMailHeldTest.php.
 */
describe('QA MTX-003: held e-mails are logged as held, not failed', () => {
  const REASON = 'testing mode holds e-mails to buyers and suppliers';
  const rand = () => Math.random().toString(16).slice(2, 10);

  async function user(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :r', { r: roleName });
    const r = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :e, NULL, 'x', :role, 1, 0, 0)`,
      { e: `jest-user-${rand()}@nexacrest.test`, role: role.id }
    );
    return r.insertId;
  }

  async function order() {
    const clientId = await createTestClient();
    await db.execute('UPDATE clients SET email = :e WHERE id = :id', { e: `buyer-${rand()}@email.com`, id: clientId });
    return createTestOrder(clientId);
  }

  async function requestApproveSendNow(orderId, documentId, template) {
    const requester = await user('Export Executive');
    const approver = await user('Admin');
    const r = await emailDispatchService.requestSend(orderId, documentId, template, null, requester);
    if (!r.dispatched) await emailDispatchService.approveSend(r.id, approver);
    const sent = await emailDispatchService.sendNowOverride(r.id, approver);
    return [r.id, sent];
  }

  afterAll(async () => {
    await db.pool.end();
  });

  test('MTX-003: a generic send held by testing mode is logged as held, not failed', async () => {
    const orderId = await order();
    await withDocumentMailsHeld(async () => {
      const [id, sent] = await requestApproveSendNow(orderId, null, 'payment_followup');
      expect(sent).toBe(false);
      expect(emailDispatchService.dispatchState.lastHoldReason).toBe(REASON);
      const row = await emailLogRepository.find(id);
      expect(row.status).toBe('cancelled');
      expect(row.cancelled_by).toBeNull();
      expect(row.cancellation_reason).toBe(`Held, not sent: ${REASON}. Send it outside the app, or submit it again once that changes.`);
      expect(emailDispatchService.heldMessage(REASON).startsWith(`Not e-mailed: ${REASON}.`)).toBe(true);
      const again = await emailDispatchService.requestSend(orderId, null, 'payment_followup', null, await user('Export Executive'));
      expect(again.id).toBeDefined();
    });
  });

  test('MTX-003: a document send held by testing mode is logged as held, not failed', async () => {
    const orderId = await order();
    const fileId = await createTestFile(orderId, null);
    fs.writeFileSync((await db.queryOne('SELECT server_path FROM file_store WHERE id = :id', { id: fileId })).server_path, '%PDF-1.4 test');
    const docId = await createTestDocument(orderId, 'PI', 'approved');
    await db.execute('UPDATE documents SET pdf_file_id = :f WHERE id = :id', { f: fileId, id: docId });
    await withDocumentMailsHeld(async () => {
      const [id, sent] = await requestApproveSendNow(orderId, docId, 'send_pi');
      expect(sent).toBe(false);
      expect(emailDispatchService.dispatchState.lastHoldReason).toBe(REASON);
      const row = await emailLogRepository.find(id);
      expect(row.status).toBe('cancelled');
      expect(row.cancellation_reason.startsWith(`Held, not sent: ${REASON}`)).toBe(true);
    });
  });

  test('MTX-003: an immediate send with the approval queue off reports the hold', async () => {
    const orderId = await order();
    await companySettingsRepository.set('mail_approval_queue_enabled', '0', null);
    try {
      await withDocumentMailsHeld(async () => {
        const r = await emailDispatchService.requestSend(orderId, null, 'shipment_readiness', null, await user('Export Executive'));
        expect(r.dispatched).toBe(true);
        expect(r.sent).toBe(false);
        expect(r.held).toBe(REASON);
      });
    } finally {
      await companySettingsRepository.set('mail_approval_queue_enabled', '1', null);
    }
  });
});
