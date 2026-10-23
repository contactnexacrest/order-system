'use strict';

const crypto = require('crypto');
const db = require('../../src/config/db');
const clientIntakeReviewController = require('../../src/controllers/clientIntakeReviewController');
const clientIntakeRepository = require('../../src/repositories/clientIntakeRepository');
const uiTemplateRepository = require('../../src/repositories/uiTemplateRepository');
const userRepository = require('../../src/repositories/userRepository');
const { createTestClient } = require('../support/fixtures');

/** Task #42 — the Kanban UI template's Client Intake Review board: Pending / Converted / Rejected columns. */
describe('Client Intake Kanban board (Task #42)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Intake Kanban Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-intake-kanban-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
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
    await clientIntakeReviewController.index(req, res);
    return res;
  }

  async function createSubmission(companyName) {
    return clientIntakeRepository.create(
      {
        company_legal_name: companyName,
        billing_address: '1 Intake Street',
        billing_address_line1: null,
        billing_address_line2: null,
        billing_city: null,
        billing_postcode: null,
        billing_country: null,
        vat_eori_tax_no: null,
        contact_person: 'Jane Buyer',
        email: `jane-${crypto.randomBytes(4).toString('hex')}@intake-kanban.example`,
        phone: null,
        country_of_destination: 'Netherlands',
        port_of_discharge_text: null,
        coo_type: null,
        incoterm_preference: null,
        container_type_text: null,
        buyer_own_reference: null,
        notes: null,
      },
      '127.0.0.1'
    );
  }

  it('a user with no preference gets the standard list view, not the board', async () => {
    const userId = await createTestUser();

    const res = await render(userId);

    expect(res.view).toBe('client_intake_review/index');
  });

  it('a user who prefers kanban gets the board view', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());

    const res = await render(userId);

    expect(res.view).toBe('client_intake_review/index_kanban');
    expect(res.locals.pending).toBeDefined();
  });

  it('a pending submission appears in the pending column', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());
    const submissionId = await createSubmission('Pending Buyer Kanban Ltd');

    const res = await render(userId);

    expect(res.locals.pending.some((s) => s.id === submissionId)).toBe(true);
  });

  it('a converted submission moves to the converted column', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());
    const submissionId = await createSubmission('Converted Buyer Kanban Ltd');
    const clientId = await createTestClient();
    await clientIntakeRepository.markConverted(submissionId, clientId, userId);

    const res = await render(userId);

    expect(res.locals.converted.some((s) => s.id === submissionId)).toBe(true);
    expect(res.locals.pending.some((s) => s.id === submissionId)).toBe(false);
  });

  it('a rejected submission moves to the rejected column', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());
    const submissionId = await createSubmission('Rejected Buyer Kanban Ltd');
    await clientIntakeRepository.markRejected(submissionId, userId, 'Not a genuine inquiry.');

    const res = await render(userId);

    expect(res.locals.rejected.some((s) => s.id === submissionId)).toBe(true);
    expect(res.locals.pending.some((s) => s.id === submissionId)).toBe(false);
  });
});
