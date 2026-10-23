'use strict';

const db = require('../../src/config/db');
const caController = require('../../src/controllers/caController');
const caFyLockRepository = require('../../src/repositories/caFyLockRepository');

/**
 * Port of N08AuditLogCoverageClusterCTest.php. External QA report N-08,
 * Cluster C: the CA module's Zoho sync, bank statement upload, and
 * financial-year lock/unlock actions never called
 * auditLogRepository.log(), so none of them ever notified anyone either.
 */
describe('Audit log coverage, N-08 Cluster C', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest N08C Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-n08c-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function lastAuditLogAction() {
    return db.queryOne('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1');
  }

  function fakeReq(userId, body = {}, file = undefined) {
    return { user: { id: userId }, body, file, session: {} };
  }

  function fakeRes() {
    const res = {};
    res.redirect = () => {};
    return res;
  }

  it('runZohoSync is logged', async () => {
    const userId = await createTestUser();
    await caController.runZohoSync(fakeReq(userId), fakeRes());
    expect((await lastAuditLogAction()).action_type).toBe('CA_ZOHO_SYNC_RUN');
  });

  it('uploadBankStatement is logged', async () => {
    const userId = await createTestUser();
    const file = {
      originalname: 'statement.csv',
      buffer: Buffer.from('Date,Description,Reference,Credit,Debit\n2026-10-20,Test credit,REF123,1000.00,\n'),
    };
    await caController.uploadBankStatement(fakeReq(userId, {}, file), fakeRes());
    expect((await lastAuditLogAction()).action_type).toBe('CA_BANK_STATEMENT_UPLOADED');
  });

  it('lockFinancialYear is logged', async () => {
    const userId = await createTestUser();
    await caController.lockFinancialYear(fakeReq(userId, { financial_year: '2025-26' }), fakeRes());
    const audit = await lastAuditLogAction();
    expect(audit.action_type).toBe('CA_FINANCIAL_YEAR_LOCKED');
    expect(audit.new_value).toBe('2025-26');
  });

  it('unlockFinancialYear is logged', async () => {
    const userId = await createTestUser();
    await caFyLockRepository.lock('2024-25', userId);
    await caController.unlockFinancialYear(fakeReq(userId, { financial_year: '2024-25', unlock_reason: 'Correcting a posting error found during audit.' }), fakeRes());
    const audit = await lastAuditLogAction();
    expect(audit.action_type).toBe('CA_FINANCIAL_YEAR_UNLOCKED');
    expect(audit.old_value).toBe('2024-25');
  });
});
