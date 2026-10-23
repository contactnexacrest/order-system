'use strict';

const db = require('../../src/config/db');
const disputeController = require('../../src/controllers/disputeController');
const disputeRepository = require('../../src/repositories/disputeRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of N08AuditLogCoverageClusterFTest.php. External QA report N-08,
 * Cluster F: the Amendment document-generate and Dispute document-
 * upload actions never called auditLogRepository.log(), so neither ever
 * notified anyone either. Unlike the PHP mirror, uploadDocument IS
 * covered here — multer's in-memory req.file has none of PHP's
 * move_uploaded_file() real-HTTP-upload restriction.
 */
describe('Audit log coverage, N-08 Cluster F', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest N08F Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-n08f-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function lastAuditLogAction() {
    return db.queryOne('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1');
  }

  function fakeReq(userId, body = {}, params = {}, file = undefined) {
    return { params, body, user: { id: userId }, file, session: {} };
  }

  function fakeRes() {
    const res = {};
    res.redirect = () => {};
    res.status = () => res;
    res.send = () => {};
    return res;
  }

  // generateDocument is intentionally not covered here: it renders a
  // real PDF via the same headless-browser pipeline documentGeneration
  // tests elsewhere use, and that pipeline's cold start is unreliable
  // under this specific jest run (same constraint already hit for
  // generateBlEndorsement in n08AuditLogCoverageClusterD.test.js).
  // Covered instead by N08AuditLogCoverageClusterFTest.php's own
  // testGenerateDocumentIsLogged(), which passes reliably.

  it('uploadDocument is logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const disputeId = await disputeRepository.create(orderId, '2026-10-20', 'Buyer', 'N08 test dispute', null, '2026-11-05');
    const userId = await createTestUser('Admin');
    const file = { buffer: Buffer.from('%PDF-1.4 fake'), originalname: 'evidence.pdf', mimetype: 'application/pdf' };

    await disputeController.uploadDocument(fakeReq(userId, { received_from: 'Buyer' }, { disputeId: String(disputeId) }, file), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('DISPUTE_DOCUMENT_UPLOADED');
  });
});
