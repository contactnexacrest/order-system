'use strict';

const db = require('../../src/config/db');
const { createTestClient, createTestOrder } = require('../support/fixtures');
const clientIntakeRepository = require('../../src/repositories/clientIntakeRepository');
const piIntakeRepository = require('../../src/repositories/piIntakeRepository');

/**
 * Sidebar nav badges for Quotation Intake Review / PI Intake Review
 * (layout/base.njk) — previously the two links gave no indication a
 * submission was waiting, so staff had to click in just to find out.
 * pendingCount()/pendingReviewCount() back those badges with a live
 * COUNT, so the number appears the moment a submission is filed and
 * drops the moment it's accepted or rejected — no caching, nothing to
 * manually decrement.
 */
describe('intake review pending-count nav badges', () => {
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
    return result.insertId;
  }

  async function createClientIntakeSubmission() {
    return clientIntakeRepository.create(
      {
        company_legal_name: 'Badge Test Buyer Ltd',
        billing_address: '1 Badge Street',
        contact_person: 'Jane Buyer',
        email: 'jane@badge-test.example',
        country_of_destination: 'Netherlands',
      },
      '127.0.0.1'
    );
  }

  it('client intake pendingCount reflects only pending rows', async () => {
    const before = await clientIntakeRepository.pendingCount();

    const pendingId = await createClientIntakeSubmission();
    expect(await clientIntakeRepository.pendingCount()).toBe(before + 1);

    const toRejectId = await createClientIntakeSubmission();
    expect(await clientIntakeRepository.pendingCount()).toBe(before + 2);

    const staffId = await createTestUser('Admin');
    await clientIntakeRepository.markRejected(toRejectId, staffId, 'Not a fit');
    expect(await clientIntakeRepository.pendingCount()).toBe(before + 1);

    const clientId = await createTestClient();
    await clientIntakeRepository.markConverted(pendingId, clientId, staffId);
    expect(await clientIntakeRepository.pendingCount()).toBe(before);
  });

  it('PI intake pendingReviewCount reflects only pending_review rows', async () => {
    const before = await piIntakeRepository.pendingReviewCount();

    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId, 'FOB');
    const token = await piIntakeRepository.createLink(orderId, null);
    const submission = await piIntakeRepository.findValidByToken(token);

    // A fresh link alone isn't a standing request yet — only submit() (the
    // client actually filling the form) flips it to pending_review, which
    // is what the badge counts.
    expect(await piIntakeRepository.pendingReviewCount()).toBe(before);

    await piIntakeRepository.submit(
      submission.id,
      {
        company_legal_name: submission.company_legal_name,
        billing_address: submission.billing_address,
        vat_eori_tax_no: submission.vat_eori_tax_no,
        contact_person: submission.contact_person,
        email: submission.email,
        phone: submission.phone,
        port_of_discharge_text: submission.port_of_discharge_text,
        country_of_destination: submission.country_of_destination,
        incoterm_confirmed: submission.incoterm_confirmed,
        container_type_text: submission.container_type_text,
        payment_terms_confirmation: 'CONFIRMED',
        quotation_acceptance_reference: 'We accept Quotation X',
        coo_type: submission.coo_type,
        buyer_po_ref: null,
        changes_from_quotation: null,
        special_document_requirements: null,
      },
      '127.0.0.1'
    );

    expect(await piIntakeRepository.pendingReviewCount()).toBe(before + 1);

    const staffId = await createTestUser('Admin');
    await piIntakeRepository.markRejected(submission.id, staffId, 'Resubmit with correct VAT');
    expect(await piIntakeRepository.pendingReviewCount()).toBe(before);
  });
});
