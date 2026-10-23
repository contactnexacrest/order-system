'use strict';

const db = require('../../src/config/db');
const annexureController = require('../../src/controllers/annexureController');
const orderAnnexureRepository = require('../../src/repositories/orderAnnexureRepository');
const { createTestClient, createTestOrder, createTestFile } = require('../support/fixtures');

/**
 * Port of N08AuditLogCoverageClusterETest.php. External QA report N-08,
 * Cluster E: 6 Annexure actions never called auditLogRepository.log(),
 * so none of them ever notified anyone either. Unlike the PHP mirror,
 * uploadImage IS covered here — multer's in-memory req.file has none of
 * PHP's move_uploaded_file() real-HTTP-upload restriction.
 */
describe('Audit log coverage, N-08 Cluster E', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest N08E Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-n08e-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function lastAuditLogAction() {
    return db.queryOne('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1');
  }

  function fakeReq(orderId, userId, body = {}, extraParams = {}, file = undefined) {
    return { params: { id: String(orderId), ...extraParams }, body, user: { id: userId }, file, session: {} };
  }

  function fakeRes() {
    const res = {};
    res.redirect = () => {};
    res.status = () => res;
    res.send = () => {};
    return res;
  }

  it('toggleInclude is logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();

    await annexureController.toggleInclude(fakeReq(orderId, userId, { include_annexure_a: '1' }), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('ANNEXURE_INCLUDE_TOGGLED');
  });

  it('createProduct is logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();

    await annexureController.createProduct(fakeReq(orderId, userId, { name: 'N08 Test Product' }), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('ANNEXURE_PRODUCT_CREATED');
  });

  it('updateProduct is logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const productId = await orderAnnexureRepository.createProduct(orderId, { name: 'Original Name', description: '', dimensions: '', finish: '', components: '', technical_notes: '' });
    const userId = await createTestUser();

    await annexureController.updateProduct(fakeReq(orderId, userId, { name: 'Updated Name' }, { productId: String(productId) }), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('ANNEXURE_PRODUCT_UPDATED');
  });

  it('deleteProduct is logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const productId = await orderAnnexureRepository.createProduct(orderId, { name: 'To Delete', description: '', dimensions: '', finish: '', components: '', technical_notes: '' });
    const userId = await createTestUser();

    await annexureController.deleteProduct(fakeReq(orderId, userId, {}, { productId: String(productId) }), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('ANNEXURE_PRODUCT_DELETED');
  });

  it('uploadImage is logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const productId = await orderAnnexureRepository.createProduct(orderId, { name: 'With Image', description: '', dimensions: '', finish: '', components: '', technical_notes: '' });
    const userId = await createTestUser();
    const file = { buffer: Buffer.from([0x89, 0x50, 0x4e, 0x47]), originalname: 'product.png', mimetype: 'image/png' };

    await annexureController.uploadImage(fakeReq(orderId, userId, {}, { productId: String(productId) }, file), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('ANNEXURE_IMAGE_UPLOADED');
  });

  it('removeImage is logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const productId = await orderAnnexureRepository.createProduct(orderId, { name: 'With Image', description: '', dimensions: '', finish: '', components: '', technical_notes: '' });
    const fileId = await createTestFile(orderId, null);
    const imageId = await orderAnnexureRepository.addImage(productId, fileId);
    const userId = await createTestUser();

    await annexureController.removeImage(fakeReq(orderId, userId, {}, { imageId: String(imageId) }), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('ANNEXURE_IMAGE_REMOVED');
  });
});
