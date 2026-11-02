'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const permissionRepository = require('../../src/repositories/permissionRepository');
const roleRepository = require('../../src/repositories/roleRepository');
const departmentTaskRepository = require('../../src/repositories/departmentTaskRepository');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const orderExportBenefitSchemeRepository = require('../../src/repositories/orderExportBenefitSchemeRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of ExportComplianceTest.php. Owner decision D-226 (2 Nov 2026,
 * "3 yes"): the Export Compliance department — Executive, Manager,
 * Director — for government and agency work: the compliance checklist, the
 * COO Prep Sheet, export benefit eligibility and claims, government
 * documents on the order and their own work queue; never purchase prices,
 * logistics costs, buyer payment amounts, profit, incentives or CAFIN.
 */
describe('Export Compliance department (D-226)', () => {
  const PASSWORD = 'JestExportCompliance!1';
  const ROLES = ['Export Compliance Executive', 'Export Compliance Manager', 'Export Compliance Director'];
  // permission => [Executive, Manager, Director]
  const MATRIX = {
    view_orders: [1, 1, 1],
    download_pdf: [1, 1, 1],
    view_selling_prices: [1, 1, 1],
    manage_compliance_checklist: [1, 1, 1],
    manage_export_benefit_claims: [1, 1, 1],
    generate_compliance_documents: [1, 1, 1],
    upload_order_documents: [1, 1, 1],
    view_all_orders: [0, 1, 1],
    assign_department_owner: [0, 1, 1],
    approve_documents: [0, 1, 1],
    view_cockpit: [0, 0, 1],
    view_buyer_payments: [0, 0, 0],
    view_purchase_prices: [0, 0, 0],
    view_logistics_costs: [0, 0, 0],
    manage_order_financials: [0, 0, 0],
    manage_incentives: [0, 0, 0],
    view_cafin: [0, 0, 0],
    generate_documents: [0, 0, 0],
    close_orders: [0, 0, 0],
    manage_orders: [0, 0, 0],
  };

  afterAll(async () => {
    await db.pool.end();
  });

  async function signedInAs(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-expcomp-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const id = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    )).insertId;
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });
    return { id, agent };
  }

  async function csrfFrom(agent, orderId) {
    const page = await agent.get(`/orders/${orderId}`);
    return page.text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
  }

  it('has the department, its ladder and the permissions of each role', async () => {
    const rows = await db.query(
      `SELECT dd.title, dd.level, r.name AS role FROM department_designations dd JOIN departments d ON d.id = dd.department_id
        JOIN roles r ON r.id = dd.role_id WHERE d.code = 'EXPORT_COMPLIANCE' ORDER BY dd.sort_order`
    );
    expect(rows.map((r) => [r.title, r.level, r.role])).toEqual([
      ['Export Compliance Executive', 'executive', 'Export Compliance Executive'],
      ['Export Compliance Manager', 'manager', 'Export Compliance Manager'],
      ['Export Compliance Director', 'director', 'Export Compliance Director'],
    ]);
    for (const [i, roleName] of ROLES.entries()) {
      const role = await roleRepository.findByName(roleName);
      const effective = await permissionRepository.effectivePermissions(0, role.id);
      for (const [key, expected] of Object.entries(MATRIX)) {
        expect([roleName, key, Boolean(effective[key])]).toEqual([roleName, key, Boolean(expected[i])]);
      }
    }
  });

  it('keeps the checklist for every role that had it through Close orders', async () => {
    const missing = await db.query(
      `SELECT r.name FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
        WHERE p.permission_key = 'close_orders' AND rp.is_enabled = 1
          AND NOT EXISTS (SELECT 1 FROM role_permissions rp2 JOIN permissions p2 ON p2.id = rp2.permission_id
                           WHERE rp2.role_id = rp.role_id AND p2.permission_key = 'manage_compliance_checklist' AND rp2.is_enabled = 1)`
    );
    expect(missing).toEqual([]);
  });

  it('shows their tools on the order page and hides every other money', async () => {
    const { id, agent } = await signedInAs('Export Compliance Executive');
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, id);
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 7777.11, '2026-06-01');
    await orderPaymentStatusRepository.markAdvanceCleared(orderId, '2026-06-02 10:00:00', id);

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);
    expect(res.text).toContain('id="compliance-checklist"');
    expect(res.text).toContain(`action="/orders/${orderId}/additional-documents"`);
    expect(res.text).not.toMatch(/7,777\.11/);
    expect(res.text).not.toContain('id="order-financials"');
    expect(res.text).not.toContain('/ca-internal');
  });

  it('generates only the COO Prep Sheet', async () => {
    const { id, agent } = await signedInAs('Export Compliance Executive');
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, id);
    let csrf = await csrfFrom(agent, orderId);
    await agent.post(`/orders/${orderId}/documents/generate`).type('form').send({ _csrf: csrf, document_type: 'QT', generate_pdf: '1' }).expect(302);
    expect((await agent.get(`/orders/${orderId}`)).text).toContain('You can generate only the COO Prep Sheet');

    csrf = await csrfFrom(agent, orderId);
    await agent.post(`/orders/${orderId}/documents/generate`).type('form').send({ _csrf: csrf, document_type: 'COOPREP', generate_pdf: '1' }).expect(302);
    expect((await agent.get(`/orders/${orderId}`)).text).not.toContain('You can generate only the COO Prep Sheet');
  });

  it('answers the scheme eligibility', async () => {
    const { id, agent } = await signedInAs('Export Compliance Executive');
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, id);
    const schemes = await orderExportBenefitSchemeRepository.forOrder(orderId);
    expect(schemes.length).toBeGreaterThan(0);
    const csrf = await csrfFrom(agent, orderId);
    const res = await agent.post(`/orders/${orderId}/export-benefit-schemes`).type('form')
      .send({ _csrf: csrf, 'schemes[0][scheme_name]': schemes[0].scheme_name, 'schemes[0][status]': 'eligible', 'schemes[0][reason]': '' });
    expect(res.status).toBe(302);
    expect(res.headers.location).toBe(`/orders/${orderId}#step-13g-benefits`);
    const after = await orderExportBenefitSchemeRepository.forOrder(orderId);
    expect(after.find((s) => s.scheme_name === schemes[0].scheme_name).status).toBe('eligible');
  });

  it('puts the checklist and the eBRC follow-up in their queue, without amounts', async () => {
    const { id } = await signedInAs('Export Compliance Executive');
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, id);
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 1000, '2026-06-01');
    await orderPaymentStatusRepository.markAdvanceCleared(orderId, '2026-06-02 10:00:00', id);

    let tasks = (await departmentTaskRepository.open(['EXPORT_COMPLIANCE'])).filter((t) => Number(t.order_id) === orderId);
    const keys = tasks.map((t) => t.task_key);
    expect(keys).toContain('compliance_checklist_item');
    expect(keys).toContain('ebrc_followup');
    for (const t of tasks) {
      expect(t.department_code).toBe('EXPORT_COMPLIANCE');
      expect(String(t.subject)).not.toMatch(/\d,\d{3}\.\d{2}/);
    }
    await orderPaymentStatusRepository.setAdvanceFirc(orderId, 'FIRC-TEST-1', '2026-06-03');
    tasks = (await departmentTaskRepository.open(['EXPORT_COMPLIANCE'])).filter((t) => Number(t.order_id) === orderId);
    expect(tasks.map((t) => t.task_key)).not.toContain('ebrc_followup');
  });
});
