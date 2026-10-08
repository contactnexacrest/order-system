'use strict';

const db = require('../../src/config/db');
const clientRepository = require('../../src/repositories/clientRepository');
const clientsController = require('../../src/controllers/clientsController');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Item 2 — the actual signed-agreement file, expiry date, force-expire,
 * and renew. There is no real multer upload under Jest (no HTTP request),
 * so the upload/renew-with-file controller paths are covered only up to
 * fileUploadService's own "no file selected" guard (same established
 * pattern as clientAgreementFooter.test.js's updateAgreementFooter
 * coverage); the actual file-fields persistence is covered directly
 * against clientRepository, and the force-expire/renew-without-file paths
 * are covered end-to-end through the controller.
 */
describe('Client agreement file/expiry/force-expire/renew (Item 2)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestStaffUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test Staff', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-staff-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function auditLogCount(actionType, field, entityId) {
    const row = await db.queryOne(
      "SELECT COUNT(*) AS cnt FROM audit_log WHERE action_type = :action_type AND entity_type = 'clients' AND entity_id = :entity_id AND field_name = :field",
      { action_type: actionType, entity_id: entityId, field }
    );
    return parseInt(row.cnt, 10);
  }

  function fakeReq(staffUserId, body = {}, params = {}, file = undefined) {
    return { user: { id: staffUserId }, body, params, session: {}, file };
  }

  function fakeRes() {
    const res = { redirectedTo: null, statusCode: 200, sentBody: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.sentBody = body; };
    return res;
  }

  test('uploadAgreement with no file selected changes nothing and never 404s', async () => {
    const clientId = await createTestClient();
    const staffId = await createTestStaffUser();
    const res = fakeRes();

    await clientsController.uploadAgreement(fakeReq(staffId, {}, { id: String(clientId) }), res);

    expect(res.statusCode).not.toBe(404);
    expect((await clientRepository.find(clientId)).agreement_file_path).toBeNull();
  });

  test('uploadAgreement 404s for a missing client', async () => {
    const staffId = await createTestStaffUser();
    const res = fakeRes();
    await clientsController.uploadAgreement(fakeReq(staffId, {}, { id: '999999999' }), res);
    expect(res.statusCode).toBe(404);
  });

  test('setAgreementFile persists path/filename/expiry and clears force_expired', async () => {
    const clientId = await createTestClient();
    await clientRepository.setAgreementForceExpired(clientId, true);
    expect((await clientRepository.find(clientId)).agreement_force_expired).toBeTruthy();

    await clientRepository.setAgreementFile(clientId, '/tmp/fake-agreement.pdf', 'Signed Agreement.pdf', '2030-01-01');

    const client = await clientRepository.find(clientId);
    expect(client.agreement_file_path).toBe('/tmp/fake-agreement.pdf');
    expect(client.agreement_file_original_name).toBe('Signed Agreement.pdf');
    expect(client.agreement_expiry_date).toBe('2030-01-01');
    expect(client.agreement_force_expired).toBeFalsy();
    expect(client.agreement_uploaded_at).not.toBeNull();
  });

  test('setAgreementFile with null expiry means no automatic expiry', async () => {
    const clientId = await createTestClient();
    await clientRepository.setAgreementFile(clientId, '/tmp/fake.pdf', 'fake.pdf', null);
    expect((await clientRepository.find(clientId)).agreement_expiry_date).toBeNull();
  });

  test('forceExpireAgreement sets the flag and logs audit', async () => {
    const clientId = await createTestClient();
    await clientRepository.setAgreementFile(clientId, '/tmp/fake.pdf', 'fake.pdf', '2030-01-01');
    const staffId = await createTestStaffUser();

    await clientsController.forceExpireAgreement(fakeReq(staffId, {}, { id: String(clientId) }), fakeRes());

    expect((await clientRepository.find(clientId)).agreement_force_expired).toBeTruthy();
    expect(await auditLogCount('CLIENT_AGREEMENT_FORCE_EXPIRED', 'agreement_force_expired', clientId)).toBe(1);
  });

  test('forceExpireAgreement 404s for a missing client', async () => {
    const staffId = await createTestStaffUser();
    const res = fakeRes();
    await clientsController.forceExpireAgreement(fakeReq(staffId, {}, { id: '999999999' }), res);
    expect(res.statusCode).toBe(404);
  });

  test('renewAgreement without a new file updates expiry and clears force_expired', async () => {
    const clientId = await createTestClient();
    await clientRepository.setAgreementFile(clientId, '/tmp/fake.pdf', 'fake.pdf', '2025-01-01');
    await clientRepository.setAgreementForceExpired(clientId, true);
    const staffId = await createTestStaffUser();

    await clientsController.renewAgreement(fakeReq(staffId, { agreement_expiry_date: '2030-06-15' }, { id: String(clientId) }), fakeRes());

    const client = await clientRepository.find(clientId);
    expect(client.agreement_expiry_date).toBe('2030-06-15');
    expect(client.agreement_force_expired).toBeFalsy();
    expect(client.agreement_file_path).toBe('/tmp/fake.pdf'); // untouched when renewing without a new upload
    expect(await auditLogCount('CLIENT_AGREEMENT_RENEWED', 'agreement_expiry_date', clientId)).toBe(1);
  });

  test('renewAgreement 404s for a missing client', async () => {
    const staffId = await createTestStaffUser();
    const res = fakeRes();
    await clientsController.renewAgreement(fakeReq(staffId, { agreement_expiry_date: '2030-01-01' }, { id: '999999999' }), res);
    expect(res.statusCode).toBe(404);
  });

  test('downloadAgreement 404s when no file is on record', async () => {
    const clientId = await createTestClient();
    const res = fakeRes();
    await clientsController.downloadAgreement(fakeReq(null, {}, { id: String(clientId) }), res);
    expect(res.statusCode).toBe(404);
  });

  test('downloadAgreement 404s when the file is missing from disk', async () => {
    const clientId = await createTestClient();
    await clientRepository.setAgreementFile(clientId, `/tmp/does-not-exist-${Math.random().toString(16).slice(2)}.pdf`, 'fake.pdf', null);
    const res = fakeRes();
    await clientsController.downloadAgreement(fakeReq(null, {}, { id: String(clientId) }), res);
    expect(res.statusCode).toBe(404);
  });

  // The gating logic: resolveAgreementFooter only lets the text through on
  // a generated document while the agreement is genuinely active.
  test('resolveAgreementFooter shows text when active with no expiry', async () => {
    const clientId = await createTestClient();
    await clientRepository.updateAgreementFooterText(clientId, 'Active clause.');
    const orderId = await createTestOrder(clientId);

    const context = await documentDataAssembler.assemble(orderId, 'QT');
    expect(context.buyer.agreement_footer_text).toBe('Active clause.');
  });

  test('resolveAgreementFooter shows text when expiry is in the future', async () => {
    const clientId = await createTestClient();
    await clientRepository.updateAgreementFooterText(clientId, 'Future clause.');
    const future = new Date(Date.now() + 10 * 86400000).toISOString().slice(0, 10);
    await clientRepository.setAgreementFile(clientId, '/tmp/fake.pdf', 'fake.pdf', future);
    const orderId = await createTestOrder(clientId);

    const context = await documentDataAssembler.assemble(orderId, 'QT');
    expect(context.buyer.agreement_footer_text).toBe('Future clause.');
  });

  test('resolveAgreementFooter hides text when expiry is in the past', async () => {
    const clientId = await createTestClient();
    await clientRepository.updateAgreementFooterText(clientId, 'Expired clause.');
    const past = new Date(Date.now() - 86400000).toISOString().slice(0, 10);
    await clientRepository.setAgreementFile(clientId, '/tmp/fake.pdf', 'fake.pdf', past);
    const orderId = await createTestOrder(clientId);

    const context = await documentDataAssembler.assemble(orderId, 'QT');
    expect('agreement_footer_text' in context.buyer).toBe(true);
    expect(context.buyer.agreement_footer_text).toBeNull();
  });

  test('resolveAgreementFooter hides text when force-expired, even with a future expiry', async () => {
    const clientId = await createTestClient();
    await clientRepository.updateAgreementFooterText(clientId, 'Force-expired clause.');
    const future = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10);
    await clientRepository.setAgreementFile(clientId, '/tmp/fake.pdf', 'fake.pdf', future);
    await clientRepository.setAgreementForceExpired(clientId, true);
    const orderId = await createTestOrder(clientId);

    const context = await documentDataAssembler.assemble(orderId, 'QT');
    expect(context.buyer.agreement_footer_text).toBeNull();
  });
});
