'use strict';

const db = require('../../src/config/db');
const reportController = require('../../src/controllers/reportController');
const reportDefinitionRepository = require('../../src/repositories/reportDefinitionRepository');

/**
 * Port of N08AuditLogCoverageClusterGTest.php. External QA report N-08,
 * Cluster G: the 3 saved-report actions never called
 * auditLogRepository.log(), so none of them ever notified anyone either.
 */
describe('Audit log coverage, N-08 Cluster G', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest N08G Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-n08g-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function lastAuditLogAction() {
    return db.queryOne('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1');
  }

  function fakeReq(userId, body = {}, params = {}) {
    return { params, body, user: { id: userId }, session: {} };
  }

  function fakeRes() {
    const res = {};
    res.redirect = () => {};
    res.status = () => res;
    res.send = () => {};
    return res;
  }

  it('saveDefinition is logged', async () => {
    const userId = await createTestUser('Admin');

    await reportController.saveDefinition(
      fakeReq(userId, { name: 'N08 Test Report', report_type: 'aggregate' }),
      fakeRes()
    );

    expect((await lastAuditLogAction()).action_type).toBe('REPORT_DEFINITION_SAVED');
  });

  it('updateDefinition is logged', async () => {
    const userId = await createTestUser('Admin');
    const reportId = await reportDefinitionRepository.create('Original Name', 'aggregate', userId, 'private', {}, []);

    await reportController.updateDefinition(
      fakeReq(userId, { name: 'Updated Name' }, { reportId: String(reportId) }),
      fakeRes()
    );

    expect((await lastAuditLogAction()).action_type).toBe('REPORT_DEFINITION_UPDATED');
  });

  it('deleteDefinition is logged', async () => {
    const userId = await createTestUser('Admin');
    const reportId = await reportDefinitionRepository.create('To Delete', 'aggregate', userId, 'private', {}, []);

    await reportController.deleteDefinition(fakeReq(userId, {}, { reportId: String(reportId) }), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('REPORT_DEFINITION_DELETED');
  });
});
