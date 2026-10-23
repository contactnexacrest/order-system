'use strict';

const crypto = require('crypto');
const db = require('../../src/config/db');
const clientIntakeController = require('../../src/controllers/clientIntakeController');
const clientIntakeRepository = require('../../src/repositories/clientIntakeRepository');
const clientIntakeReviewController = require('../../src/controllers/clientIntakeReviewController');
const piIntakeReviewController = require('../../src/controllers/piIntakeReviewController');
const piIntakeRepository = require('../../src/repositories/piIntakeRepository');
const reorderRequestController = require('../../src/controllers/reorderRequestController');
const orderReorderRequestRepository = require('../../src/repositories/orderReorderRequestRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of N08AuditLogCoverageClusterBTest.php. External QA report N-08,
 * Cluster B: the public no-login intake correction form and the three
 * staff intake/reorder review queues' accept/reject actions never called
 * auditLogRepository.log(), so none of them ever notified anyone either.
 */
describe('Audit log coverage, N-08 Cluster B', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest N08B Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-n08b-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function lastAuditLogAction() {
    return db.queryOne('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1');
  }

  async function createTestIntakeSubmission() {
    return clientIntakeRepository.create({
      company_legal_name: 'Jest N08 Test Buyer Ltd',
      billing_address: '1 Test Street',
      vat_eori_tax_no: 'VAT123',
      contact_person: 'Test Contact',
      email: 'n08-buyer@example.test',
      phone: '',
      country_of_destination: 'Testland',
      port_of_discharge_text: '',
      coo_type: '',
      incoterm_preference: '',
      container_type_text: '',
      buyer_own_reference: '',
      notes: '',
    }, '127.0.0.1');
  }

  function fakeReq(body = {}, extraParams = {}, userId = null) {
    return { params: extraParams, body, user: userId ? { id: userId } : undefined, session: {} };
  }

  function fakeRes() {
    const res = {};
    res.redirect = () => {};
    res.renderView = () => {};
    res.status = () => res;
    res.send = () => {};
    return res;
  }

  it('quotation-details edit is logged against the submission', async () => {
    const id = await createTestIntakeSubmission();
    const token = crypto.randomBytes(32).toString('hex');
    await db.execute(
      'UPDATE client_intake_submissions SET access_token_hash = :hash, access_token_expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = :id',
      { hash: crypto.createHash('sha256').update(token).digest('hex'), id }
    );

    const req = fakeReq({
      company_legal_name: 'Jest N08 Test Buyer Ltd (corrected)',
      billing_address_line1: '2 Corrected Street',
      billing_city: 'Testville',
      billing_country: 'Testland',
      vat_eori_tax_no: 'VAT123',
      contact_person: 'Test Contact',
      email: 'n08-buyer@example.test',
      phone: '',
      country_of_destination: 'Testland',
      incoterm_preference: 'FOB',
    }, { token });

    await clientIntakeController.updateSubmission(req, fakeRes());

    const audit = await lastAuditLogAction();
    expect(audit.action_type).toBe('CLIENT_INTAKE_SUBMISSION_UPDATED_BY_CLIENT');
    expect(Number(audit.entity_id)).toBe(id);
  });

  it('client intake accept is logged', async () => {
    const id = await createTestIntakeSubmission();
    const userId = await createTestUser();

    await clientIntakeReviewController.accept(fakeReq({}, { id: String(id) }, userId), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('CLIENT_INTAKE_ACCEPTED');
  });

  it('client intake reject is logged', async () => {
    const id = await createTestIntakeSubmission();
    const userId = await createTestUser();

    await clientIntakeReviewController.reject(fakeReq({ reason: 'Duplicate submission already handled by email.' }, { id: String(id) }, userId), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('CLIENT_INTAKE_REJECTED');
  });

  it('PI intake reject is logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const token = await piIntakeRepository.createLink(orderId, null);
    const submission = await piIntakeRepository.findValidByToken(token);
    await piIntakeRepository.submit(submission.id, {
      company_legal_name: submission.company_legal_name,
      billing_address: submission.billing_address,
      vat_eori_tax_no: submission.vat_eori_tax_no,
      contact_person: submission.contact_person,
      email: submission.email,
      phone: submission.phone,
      port_of_discharge_text: null,
      country_of_destination: submission.country_of_destination,
      incoterm_confirmed: null,
      container_type_text: null,
      payment_terms_confirmation: null,
      quotation_acceptance_reference: null,
      coo_type: null,
      buyer_po_ref: null,
      changes_from_quotation: null,
      special_document_requirements: null,
    }, '127.0.0.1');

    const userId = await createTestUser();
    await piIntakeReviewController.reject(fakeReq({ reason: 'Buyer confirmed by phone they will resubmit.' }, { id: String(submission.id) }, userId), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('PI_INTAKE_REJECTED');
  });

  it('reorder request reject is logged', async () => {
    const clientId = await createTestClient();
    const sourceOrderId = await createTestOrder(clientId);
    const id = await orderReorderRequestRepository.create(clientId, sourceOrderId, 'Please reorder the same products.');
    const userId = await createTestUser();

    await reorderRequestController.reject(fakeReq({ reason: 'Client cancelled the reorder request by phone.' }, { id: String(id) }, userId), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('REORDER_REQUEST_REJECTED');
  });
});
