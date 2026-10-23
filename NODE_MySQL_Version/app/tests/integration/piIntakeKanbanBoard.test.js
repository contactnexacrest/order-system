'use strict';

const db = require('../../src/config/db');
const piIntakeReviewController = require('../../src/controllers/piIntakeReviewController');
const piIntakeRepository = require('../../src/repositories/piIntakeRepository');
const uiTemplateRepository = require('../../src/repositories/uiTemplateRepository');
const userRepository = require('../../src/repositories/userRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/** Task #43 — the Kanban UI template's PI Intake Review board: Pending Review / Applied / Rejected columns. */
describe('PI Intake Kanban board (Task #43)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest PI Intake Kanban Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-pi-intake-kanban-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function kanbanTemplateId() {
    return (await uiTemplateRepository.findByKey('kanban')).id;
  }

  function fakeRes() {
    const res = { statusCode: 200, body: null, view: null, locals: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; };
    res.renderView = (view, locals) => { res.view = view; res.locals = locals; };
    return res;
  }

  async function render(userId) {
    const user = await userRepository.findById(userId);
    const req = { query: {}, user, permissions: {} };
    const res = fakeRes();
    await piIntakeReviewController.index(req, res);
    return res;
  }

  /** Creates a pending_review PI intake submission for a fresh test order, returns { submissionId, orderId }. */
  async function createPendingSubmission() {
    const orderId = await createTestOrder(await createTestClient());
    const token = await piIntakeRepository.createLink(orderId, null);
    const submission = await piIntakeRepository.findValidByToken(token);

    await piIntakeRepository.submit(submission.id, {
      company_legal_name: submission.company_legal_name,
      billing_address: submission.billing_address,
      billing_address_line1: submission.billing_address_line1,
      billing_address_line2: submission.billing_address_line2,
      billing_city: submission.billing_city,
      billing_postcode: submission.billing_postcode,
      billing_country: submission.billing_country,
      vat_eori_tax_no: submission.vat_eori_tax_no,
      contact_person: submission.contact_person,
      email: submission.email,
      phone: submission.phone,
      port_of_discharge_text: submission.port_of_discharge_text,
      country_of_destination: submission.country_of_destination,
      incoterm_confirmed: submission.incoterm_confirmed,
      container_type_text: submission.container_type_text,
      payment_terms_confirmation: 'CONFIRMED — Kanban board test.',
      quotation_acceptance_reference: 'We accept Quotation X',
      coo_type: submission.coo_type,
      buyer_po_ref: null,
      changes_from_quotation: null,
      special_document_requirements: null,
      consignee_same_as_buyer: 1,
      consignee_name: null, consignee_address_line1: null, consignee_address_line2: null,
      consignee_city: null, consignee_postcode: null, consignee_country: null,
      consignee_vat_eori_tax_no: null, consignee_contact_person: null,
      consignee_phone: null, consignee_email: null,
      notify_party_same_as_consignee: 1,
      notify_party: null, notify_party_address_line1: null, notify_party_address_line2: null,
      notify_party_city: null, notify_party_postcode: null, notify_party_country: null,
      notify_party_contact_person: null, notify_party_phone: null, notify_party_email: null,
    }, '127.0.0.1');

    return { submissionId: submission.id, orderId };
  }

  it('a user with no preference gets the standard list view, not the board', async () => {
    const userId = await createTestUser();

    const res = await render(userId);

    expect(res.view).toBe('pi_intake_review/index');
  });

  it('a user who prefers kanban gets the board view', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());

    const res = await render(userId);

    expect(res.view).toBe('pi_intake_review/index_kanban');
    expect(res.locals.pending).toBeDefined();
  });

  it('a pending submission appears in the pending review column', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());
    const { submissionId } = await createPendingSubmission();

    const res = await render(userId);

    expect(res.locals.pending.some((s) => s.id === submissionId)).toBe(true);
  });

  it('an applied submission moves to the applied column', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());
    const { submissionId } = await createPendingSubmission();
    await piIntakeRepository.markApplied(submissionId, userId);

    const res = await render(userId);

    expect(res.locals.applied.some((s) => s.id === submissionId)).toBe(true);
    expect(res.locals.pending.some((s) => s.id === submissionId)).toBe(false);
  });

  it('a rejected submission moves to the rejected column', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());
    const { submissionId } = await createPendingSubmission();
    await piIntakeRepository.markRejected(submissionId, userId, 'Details did not match official documents.');

    const res = await render(userId);

    expect(res.locals.rejected.some((s) => s.id === submissionId)).toBe(true);
    expect(res.locals.pending.some((s) => s.id === submissionId)).toBe(false);
  });
});
