'use strict';

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');
const clientIntakeRepository = require('../../src/repositories/clientIntakeRepository');
const piIntakeRepository = require('../../src/repositories/piIntakeRepository');
const permissionService = require('../../src/services/permissionService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * QA-4 P2 (docs/QA/TEST_PLAN.md Section 6): CSRF, staff-side IDOR beyond
 * the client portal, and public unauthenticated endpoints.
 */
describe('IDOR and input validation (QA-4 P2)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-user-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return { id: result.insertId, roleId: role.id };
  }

  // ---------------------------------------------------------------
  // 1. Every state-changing POST route requires a valid CSRF token.
  //    CSRF is enforced by shared middleware (verifyCsrf), not per-route
  //    logic, so a middleware-level static check — every route
  //    registration actually wires the middleware in — is more valuable
  //    here than exercising all 177 POST routes individually.
  // ---------------------------------------------------------------

  it('every POST route in server.js requires CSRF verification', () => {
    const serverFile = path.join(__dirname, '../../src/server.js');
    const contents = fs.readFileSync(serverFile, 'utf8');
    const lines = contents.split('\n').filter((line) => /^app\.post\(/.test(line.trim()));

    expect(lines.length).toBeGreaterThan(100);

    const missing = lines.filter((line) => !line.includes('verifyCsrf')).map((line) => {
      const m = line.match(/^app\.post\('([^']+)'/);
      return m ? m[1] : line;
    });
    expect(missing).toEqual([]);
  });

  // ---------------------------------------------------------------
  // 2. Staff-side IDOR: does holding one permission (e.g. manage_orders)
  //    let a role reach a DIFFERENT permission's gated actions it was
  //    never granted? Cross-checks docs/QA/TEST_PLAN.md Section 2's role
  //    grant table directly against permissionService.load() (the same
  //    map req.permissions is populated from, which permissionCheck.js
  //    middleware reads for every route).
  // ---------------------------------------------------------------

  it('the role permission matrix matches the documented grants exactly', async () => {
    const cases = [
      // Logistics Executive holds manage_orders but has no CA/finance
      // access at all — the exact "editing CA figures via a crafted
      // request" scenario the test plan names.
      ['Logistics Executive', 'manage_orders', true],
      ['Logistics Executive', 'inr_actual_edit', false],
      ['Logistics Executive', 'ca_module_manage', false],
      ['Logistics Executive', 'ca_fy_lock_override', false],
      ['Logistics Executive', 'view_audit_log', false],
      // Accounts Executive is the one role that SHOULD reach CA financial
      // actions.
      ['Accounts Executive', 'inr_actual_edit', true],
      ['Accounts Executive', 'ca_fy_lock_override', true],
      ['Accounts Executive', 'view_audit_log', false],
      // Viewer / Auditor is read-only — must never reach any
      // order-mutating action.
      ['Viewer / Auditor', 'view_reports', true],
      ['Viewer / Auditor', 'view_audit_log', true],
      ['Viewer / Auditor', 'manage_orders', false],
      ['Viewer / Auditor', 'generate_documents', false],
      // CA / Chartered Accountant is explicitly "view-only... no
      // order-management access at all" per its own seed.sql description
      // — must never reach manage_orders or edit rights, despite being
      // the CA module's own named role.
      ['CA / Chartered Accountant', 'ca_module_view', true],
      ['CA / Chartered Accountant', 'inr_actual_view', true],
      ['CA / Chartered Accountant', 'manage_orders', false],
      ['CA / Chartered Accountant', 'inr_actual_edit', false],
      ['CA / Chartered Accountant', 'ca_module_manage', false],
      // Export Executive handles orders but has no CA access.
      ['Export Executive', 'manage_orders', true],
      ['Export Executive', 'ca_module_view', false],
    ];

    for (const [roleName, permissionKey, expected] of cases) {
      const { id: userId, roleId } = await createTestUser(roleName);
      const permissions = await permissionService.load(userId, roleId);
      const actual = Boolean(permissions[permissionKey]);
      expect(actual).toBe(expected);
    }
  });

  // ---------------------------------------------------------------
  // 3. Public unauthenticated endpoints — token security for the
  //    client-facing intake correction links (clientIntakeRepository) and
  //    the per-order PI-stage intake link (piIntakeRepository). Both
  //    compare a SHA-256 hash of a 256-bit random token, never the raw
  //    value, and both additionally gate on status — a guessed token, an
  //    expired token, or the right token used after staff (or the
  //    client) has already moved the submission past the editable state
  //    must all be refused with no data returned.
  // ---------------------------------------------------------------

  function randomToken() {
    return crypto.randomBytes(32).toString('hex');
  }

  function sha256(value) {
    return crypto.createHash('sha256').update(value).digest('hex');
  }

  function mysqlDatetime(atMs) {
    return new Date(atMs).toISOString().slice(0, 19).replace('T', ' ');
  }

  async function createTestIntakeSubmission() {
    return clientIntakeRepository.create(
      {
        company_legal_name: 'Jest Test Buyer Ltd',
        billing_address: '1 Test Street',
        vat_eori_tax_no: 'VAT123',
        contact_person: 'Test Contact',
        email: 'buyer@example.test',
        phone: '',
        country_of_destination: 'Testland',
        port_of_discharge_text: '',
        coo_type: '',
        incoterm_preference: '',
        container_type_text: '',
        buyer_own_reference: '',
        notes: '',
      },
      '127.0.0.1'
    );
  }

  it('client intake correction link accepts the genuine token while pending', async () => {
    const id = await createTestIntakeSubmission();
    const rawToken = randomToken();
    await clientIntakeRepository.setAccessToken(id, sha256(rawToken), mysqlDatetime(Date.now() + 86400000));

    const found = await clientIntakeRepository.findValidByToken(rawToken);

    expect(found).not.toBeNull();
    expect(Number(found.id)).toBe(id);
  });

  it('client intake correction link rejects a wrong token', async () => {
    const id = await createTestIntakeSubmission();
    const rawToken = randomToken();
    await clientIntakeRepository.setAccessToken(id, sha256(rawToken), mysqlDatetime(Date.now() + 86400000));

    const guessedToken = randomToken();
    expect(await clientIntakeRepository.findValidByToken(guessedToken)).toBeNull();
  });

  it('client intake correction link rejects an expired token', async () => {
    const id = await createTestIntakeSubmission();
    const rawToken = randomToken();
    await clientIntakeRepository.setAccessToken(id, sha256(rawToken), mysqlDatetime(Date.now() - 3600000));

    expect(await clientIntakeRepository.findValidByToken(rawToken)).toBeNull();
  });

  it('client intake correction link rejects the real token once staff have acted on it', async () => {
    const id = await createTestIntakeSubmission();
    const rawToken = randomToken();
    await clientIntakeRepository.setAccessToken(id, sha256(rawToken), mysqlDatetime(Date.now() + 86400000));
    const clientId = await createTestClient();
    const { id: reviewerId } = await createTestUser('Export Executive');
    await clientIntakeRepository.markConverted(id, clientId, reviewerId);

    expect(await clientIntakeRepository.findValidByToken(rawToken)).toBeNull();
  });

  it('PI intake link accepts the genuine token while awaiting client', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const rawToken = await piIntakeRepository.createLink(orderId, null);

    const found = await piIntakeRepository.findValidByToken(rawToken);

    expect(found).not.toBeNull();
    expect(Number(found.order_id)).toBe(orderId);
  });

  it('PI intake link rejects a wrong token', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await piIntakeRepository.createLink(orderId, null);

    const guessedToken = randomToken();
    expect(await piIntakeRepository.findValidByToken(guessedToken)).toBeNull();
  });

  it('PI intake link rejects its own token once the client has submitted', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const rawToken = await piIntakeRepository.createLink(orderId, null);
    const submission = await piIntakeRepository.findValidByToken(rawToken);

    await piIntakeRepository.submit(
      submission.id,
      {
        company_legal_name: 'Buyer', billing_address: 'Addr',
        consignee_name: null, consignee_address: null,
        vat_eori_tax_no: null, contact_person: 'Contact', email: 'b@example.test', phone: null,
        notify_party: null, port_of_discharge_text: 'Port', country_of_destination: 'Country',
        incoterm_confirmed: 'FOB', container_type_text: null,
        payment_terms_confirmation: 'Confirmed', quotation_acceptance_reference: 'REF',
        coo_type: 'TBC', buyer_po_ref: null, changes_from_quotation: null,
        special_document_requirements: null,
      },
      '127.0.0.1'
    );

    expect(await piIntakeRepository.findValidByToken(rawToken)).toBeNull();
  });

  it('PI intake link rejects the real token once applied', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const rawToken = await piIntakeRepository.createLink(orderId, null);
    const submission = await piIntakeRepository.findValidByToken(rawToken);
    const { id: userId } = await createTestUser('Export Executive');
    await piIntakeRepository.markApplied(submission.id, userId);

    expect(await piIntakeRepository.findValidByToken(rawToken)).toBeNull();
  });
});
