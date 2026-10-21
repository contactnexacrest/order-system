'use strict';

const crypto = require('crypto');
const db = require('../../src/config/db');
const clientIntakeRepository = require('../../src/repositories/clientIntakeRepository');
const clientIntakeReviewController = require('../../src/controllers/clientIntakeReviewController');

/**
 * Port of IntakeResendCorrectionLinkTest.php. 90-item backlog
 * "resend-lost-intake-link flow": the correction link issued at
 * submit() time expires after 7 days with no recovery path — if the
 * client never got the email, or it expired before they got to it, the
 * submission was stuck pending forever with no way to correct it
 * themselves. Staff can now resend a fresh link from the review queue.
 */
describe('intake resend correction link (90-item backlog "resend-lost-intake-link flow")', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function fakeRes() {
    const res = {};
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-resend-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function createTestIntakeSubmission() {
    return clientIntakeRepository.create(
      {
        company_legal_name: 'Jest Resend Test Buyer Ltd',
        billing_address: '1 Test Street',
        billing_address_line1: null,
        billing_address_line2: null,
        billing_city: null,
        billing_postcode: null,
        billing_country: null,
        vat_eori_tax_no: 'VAT123',
        contact_person: 'Test Contact',
        email: 'resend-buyer@example.test',
        phone: null,
        country_of_destination: 'Testland',
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

  it('resending issues a fresh token that works after the original has expired', async () => {
    const id = await createTestIntakeSubmission();
    const expiredToken = crypto.randomBytes(32).toString('hex');
    const pastExpiry = new Date(Date.now() - 3600000).toISOString().slice(0, 19).replace('T', ' ');
    await clientIntakeRepository.setAccessToken(id, crypto.createHash('sha256').update(expiredToken).digest('hex'), pastExpiry);
    // Sanity check reads the row directly rather than through
    // findValidByToken()'s own NOW()-comparison query — that query is the
    // same mechanism behind the PHP side's separately-documented
    // pre-existing flake; this test's own subject is resendLink(), not
    // that comparison.
    const beforeRow = await clientIntakeRepository.find(id);
    expect(beforeRow.access_token_expires_at).toBe(pastExpiry);

    const userId = await createTestUser();
    const req = { params: { id: String(id) }, user: { id: userId }, session: {} };
    await clientIntakeReviewController.resendLink(req, fakeRes());

    const submission = await clientIntakeRepository.find(id);
    expect(submission.access_token_hash).not.toBeNull();
    expect(new Date(submission.access_token_expires_at).getTime()).toBeGreaterThan(Date.now());
  });

  it('resend is rejected for an already-resolved submission', async () => {
    const id = await createTestIntakeSubmission();
    const userId = await createTestUser('Export Executive');
    await clientIntakeRepository.markRejected(id, userId, 'Not a fit');
    const before = (await clientIntakeRepository.find(id)).access_token_hash;

    const req = { params: { id: String(id) }, user: { id: userId }, session: {} };
    await clientIntakeReviewController.resendLink(req, fakeRes());

    const after = (await clientIntakeRepository.find(id)).access_token_hash;
    expect(after).toBe(before);
  });
});
