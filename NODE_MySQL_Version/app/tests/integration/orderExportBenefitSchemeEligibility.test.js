'use strict';

const db = require('../../src/config/db');
const orderFinancialsController = require('../../src/controllers/orderFinancialsController');
const auditLogRepository = require('../../src/repositories/auditLogRepository');
const orderExportBenefitSchemeRepository = require('../../src/repositories/orderExportBenefitSchemeRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of OrderExportBenefitSchemeEligibilityTest.php. Export-benefit
 * scheme eligibility (docs/schema.sql's order_export_benefit_schemes,
 * added 2026-10-24d) — the same "never a missing row standing in for
 * not applicable" fix as the Compliance Checklist, applied to RODTEP/
 * Duty Drawback/RoSCTL/Other scheme eligibility. Separate from
 * caExportBenefitRepository, which records a real claim and is
 * untouched by this.
 */
describe('Export-benefit scheme eligibility (per order)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-user-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function fakeRes() {
    const res = { statusCode: 200, body: null, redirectedTo: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  function findRow(rows, schemeName) {
    const row = rows.find((r) => r.scheme_name === schemeName);
    if (!row) throw new Error(`No row found for scheme ${schemeName}`);
    return row;
  }

  it('initializeForOrder writes a real row for every active scheme at creation', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const row = await db.queryOne(
      "SELECT status FROM order_export_benefit_schemes WHERE order_id = :order_id AND scheme_name = 'RODTEP'",
      { order_id: orderId }
    );

    expect(row).not.toBeNull();
    expect(row.status).toBe('pending');
  });

  it('forOrder lists every active scheme defaulting to pending', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const rows = await orderExportBenefitSchemeRepository.forOrder(orderId);
    const names = rows.map((r) => r.scheme_name);

    expect(names).toContain('RODTEP');
    expect(names).toContain('Duty Drawback');
    for (const r of rows) {
      expect(r.status).toBe('pending');
      expect(r.resolved_at).toBeNull();
    }
  });

  it('setStatus then forOrder reflects the new status', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();

    await orderExportBenefitSchemeRepository.setStatus(orderId, 'RODTEP', 'eligible', null, userId);

    const row = findRow(await orderExportBenefitSchemeRepository.forOrder(orderId), 'RODTEP');
    expect(row.status).toBe('eligible');
    expect(row.resolved_at).not.toBeNull();
    expect(row.resolved_by_name).toBe('Jest Test User');
  });

  it('setStatus twice upserts rather than duplicating', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();

    await orderExportBenefitSchemeRepository.setStatus(orderId, 'RODTEP', 'pending', null, userId);
    await orderExportBenefitSchemeRepository.setStatus(orderId, 'RODTEP', 'eligible', null, userId);

    const matches = (await orderExportBenefitSchemeRepository.forOrder(orderId)).filter((r) => r.scheme_name === 'RODTEP');
    expect(matches).toHaveLength(1);
  });

  it('summary counts eligible and not_eligible as resolved', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();
    const totalActiveSchemes = (await orderExportBenefitSchemeRepository.forOrder(orderId)).length;

    await orderExportBenefitSchemeRepository.setStatus(orderId, 'RODTEP', 'eligible', null, userId);
    await orderExportBenefitSchemeRepository.setStatus(orderId, 'Duty Drawback', 'not_eligible', 'Product not covered under this scheme', userId);

    const summary = await orderExportBenefitSchemeRepository.summaryForOrder(orderId);
    expect(summary.total).toBe(totalActiveSchemes);
    expect(summary.resolved).toBe(2);
    expect(summary.pending).toBe(totalActiveSchemes - 2);
  });

  it('controller rejects not_eligible without a reason', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();

    const req = { params: { id: String(orderId) }, body: { scheme_name: 'RODTEP', status: 'not_eligible' }, user: { id: userId }, permissions: {}, session: {} };
    await orderFinancialsController.setExportBenefitSchemeStatus(req, fakeRes());

    const row = findRow(await orderExportBenefitSchemeRepository.forOrder(orderId), 'RODTEP');
    expect(row.status).toBe('pending');
  });

  it('controller accepts not_eligible with a reason and audit logs it', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();

    const req = {
      params: { id: String(orderId) },
      body: { scheme_name: 'RODTEP', status: 'not_eligible', reason: 'Buyer country excluded from this scheme' },
      user: { id: userId },
      permissions: {},
      session: {},
    };
    await orderFinancialsController.setExportBenefitSchemeStatus(req, fakeRes());

    const row = findRow(await orderExportBenefitSchemeRepository.forOrder(orderId), 'RODTEP');
    expect(row.status).toBe('not_eligible');
    expect(row.reason).toBe('Buyer country excluded from this scheme');

    const logRows = (await auditLogRepository.forOrder(orderId)).filter((r) => r.action_type === 'EXPORT_BENEFIT_SCHEME_STATUS_UPDATED');
    expect(logRows).toHaveLength(1);
    expect(logRows[0].user_id).toBe(userId);
  });

  it('controller accepts eligible with no reason required', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();

    const req = { params: { id: String(orderId) }, body: { scheme_name: 'RODTEP', status: 'eligible' }, user: { id: userId }, permissions: {}, session: {} };
    await orderFinancialsController.setExportBenefitSchemeStatus(req, fakeRes());

    const row = findRow(await orderExportBenefitSchemeRepository.forOrder(orderId), 'RODTEP');
    expect(row.status).toBe('eligible');
  });

  it('controller rejects setting status back to pending', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();

    const req = { params: { id: String(orderId) }, body: { scheme_name: 'RODTEP', status: 'pending' }, user: { id: userId }, permissions: {}, session: {} };
    await orderFinancialsController.setExportBenefitSchemeStatus(req, fakeRes());

    const row = findRow(await orderExportBenefitSchemeRepository.forOrder(orderId), 'RODTEP');
    expect(row.status).toBe('pending');
    const logRows = (await auditLogRepository.forOrder(orderId)).filter((r) => r.action_type === 'EXPORT_BENEFIT_SCHEME_STATUS_UPDATED');
    expect(logRows).toHaveLength(0);
  });
});
