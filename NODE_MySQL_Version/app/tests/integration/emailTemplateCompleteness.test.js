'use strict';

const db = require('../../src/config/db');
const emailTemplateRepository = require('../../src/repositories/emailTemplateRepository');
const twoFactorService = require('../../src/services/twoFactorService');
const clientPortalService = require('../../src/services/clientPortalService');
const authController = require('../../src/controllers/authController');
const clientPortalController = require('../../src/controllers/clientPortalController');
const clientIntakeController = require('../../src/controllers/clientIntakeController');
const ordersController = require('../../src/controllers/ordersController');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of EmailTemplateCompletenessTest.php. 90-item backlog #37: a
 * completeness audit of the Email Templates module found 7
 * system/transactional emails (2FA login code, both password resets, the
 * client-portal invite, and 3 staff-generated one-time links) whose
 * subject/body were hardcoded inline, bypassing the admin-editable
 * email_templates table entirely — unlike every "Send to Buyer" document
 * email, which already went through it.
 * emailTemplateRepository.renderOrDefault() is the fix (see its own
 * docblock); this suite proves each of the 7 real call sites is now
 * genuinely wired to it, not just that the helper itself works in
 * isolation.
 */
describe('Email Templates completeness — 7 system emails now admin-editable (90-item backlog #37)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  /**
   * Every test in this file overwrites one of the 7 shared seeded
   * system-template rows. Unlike PHPUnit's DbTestCase, Jest doesn't rebuild
   * the DB between test FILES either, so a mutation left behind here would
   * leak into every later test anywhere in the suite that exercises one of
   * these same templates (e.g. quotationIntakeRateLimit.test.js's own
   * quotation_correction_link send). Restoring the exact seed.sql wording
   * after every test keeps this file's own mutations from ever being
   * observable outside the one test that made them.
   */
  afterEach(async () => {
    for (const [key, [subject, body]] of Object.entries(seededDefaults())) {
      const template = await emailTemplateRepository.find(key);
      if (template) {
        await emailTemplateRepository.update(template.id, subject, body, null, 1);
        await emailTemplateRepository.setActive(template.id, true, 1);
      }
    }
  });

  /** template_key => [subject, body], matching docs/seed.sql exactly. */
  function seededDefaults() {
    return {
      two_factor_code: [
        'Your NexaCrest login verification code',
        'Your verification code is: {code}\nThis code expires in 5 minutes. If you did not request this, contact your administrator.',
      ],
      client_portal_invite: [
        'Your NexaCrest order portal access',
        'Hello,\n\nYour advance payment has been received and cleared — thank you.\n\nYou can now log in to track your order and download your documents (Quotation, Proforma Invoice, and everything issued from here onward) at any time.\n\nTo set up your login, open this link within 72 hours:\n{set_password_link}\n\nYour login email will be: {email}\n\nNexaCrest International Private Limited',
      ],
      staff_password_reset: [
        'Reset your NexaCrest password',
        "Hello {name},\n\nA password reset was requested for your NexaCrest Export Operations account ({email}).\n\nTo set a new password, open this link within 45 minutes:\n{reset_link}\n\nIf you didn't request this, you can ignore this email — your password will not be changed.",
      ],
      client_portal_password_reset: [
        'Reset your NexaCrest order portal password',
        "Hello,\n\nA password reset was requested for your NexaCrest order portal account ({email}).\n\nTo set a new password, open this link within 72 hours:\n{reset_link}\n\nIf you didn't request this, you can ignore this email — your password will not be changed.",
      ],
      quotation_correction_link: [
        'Your NexaCrest quotation request — correction link',
        "Hello,\n\nThank you for your quotation request. We'll review it and get back to you within 24 hours.\n\nSpotted a mistake in what you submitted? You can correct it yourself, as long as we haven't already processed it, at:\n{correction_link}\n\nThis link works for 7 days.\n\nNexaCrest International Private Limited",
      ],
      pi_form_link: [
        'NexaCrest — confirm your PI-stage details',
        'Hello,\n\nThank you for accepting our Quotation for order {order_reference}.\n\nTo issue your Proforma Invoice, please confirm your shipping/consignee details at:\n{pi_form_link}\n\nThis link works for 30 days.\n\nNexaCrest International Private Limited',
      ],
      buyer_action_link: [
        'NexaCrest — acknowledge your order / upload your Buyer PO',
        'Hello,\n\nTo acknowledge your Order Confirmation and/or upload your signed Buyer PO copy for order {order_reference}, please visit:\n{buyer_action_link}\n\nThis link works for 90 days.\n\nNexaCrest International Private Limited',
      ],
    };
  }

  async function overwriteTemplate(templateKey, subject, body) {
    const template = await emailTemplateRepository.find(templateKey);
    expect(template).not.toBeNull();
    await emailTemplateRepository.update(template.id, subject, body, null, 1);
    return template;
  }

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-emailtpl-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function userEmail(userId) {
    const row = await db.queryOne('SELECT email FROM users WHERE id = :id', { id: userId });
    return row.email;
  }

  async function setClientEmail(clientId, email) {
    await db.execute('UPDATE clients SET email = :email WHERE id = :id', { email, id: clientId });
  }

  function fakeRes() {
    const res = { statusCode: 200, body: null, redirectedTo: null, renderedView: null, renderedData: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; return res; };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.renderView = (view, data) => { res.renderedView = view; res.renderedData = data; };
    return res;
  }

  function fakeReq(overrides = {}) {
    return { params: {}, body: {}, session: {}, ...overrides };
  }

  /**
   * Node's emailService falls back to console.error() (not PHP's
   * error_log()) when no SMTP is configured in this environment — no
   * existing Jest precedent for capturing it, so this spies on
   * console.error, runs the action, and joins every call's args into one
   * searchable string, restoring the real console.error afterward either
   * way.
   */
  async function captureEmailLog(action) {
    const spy = jest.spyOn(console, 'error').mockImplementation(() => {});
    let calls;
    try {
      await action();
    } finally {
      // mockRestore() also clears mock.calls (same as mockReset()), so the
      // calls must be captured before restoring, not after.
      calls = spy.mock.calls;
      spy.mockRestore();
    }
    return calls.map((args) => args.join(' ')).join('\n');
  }

  // ---------------------------------------------------------------
  // emailTemplateRepository.renderOrDefault
  // ---------------------------------------------------------------

  it('renderOrDefault uses the active template when one exists', async () => {
    await overwriteTemplate('two_factor_code', 'Custom subject {code}', 'Custom body {code}');

    const rendered = await emailTemplateRepository.renderOrDefault('two_factor_code', { '{code}': '123456' }, 'Fallback subject', 'Fallback body');

    expect(rendered.subject).toBe('Custom subject 123456');
    expect(rendered.body).toBe('Custom body 123456');
  });

  it('renderOrDefault falls back when the template is missing', async () => {
    const rendered = await emailTemplateRepository.renderOrDefault('not_a_real_key', { '{code}': '123456' }, 'Fallback subject {code}', 'Fallback body {code}');

    expect(rendered.subject).toBe('Fallback subject 123456');
    expect(rendered.body).toBe('Fallback body 123456');
  });

  it('renderOrDefault falls back when the template is deactivated', async () => {
    const template = await emailTemplateRepository.find('two_factor_code');
    await emailTemplateRepository.setActive(template.id, false, 1);
    try {
      const rendered = await emailTemplateRepository.renderOrDefault('two_factor_code', { '{code}': '123456' }, 'Fallback subject {code}', 'Fallback body {code}');
      expect(rendered.subject).toBe('Fallback subject 123456');
    } finally {
      await emailTemplateRepository.setActive(template.id, true, 1);
    }
  });

  it('renderOrDefault appends the footer when one is set', async () => {
    const template = await emailTemplateRepository.find('two_factor_code');
    await emailTemplateRepository.update(template.id, 'Subject', 'Body', 'Footer line', 1);

    const rendered = await emailTemplateRepository.renderOrDefault('two_factor_code', {}, 'Fallback', 'Fallback');

    expect(rendered.body).toBe('Body\n\nFooter line');
  });

  // ---------------------------------------------------------------
  // The 7 real call sites — each must genuinely read from the table.
  // ---------------------------------------------------------------

  it('the 2FA code email uses the admin-edited template', async () => {
    await overwriteTemplate('two_factor_code', 'JEST-CUSTOM-2FA', 'Your one-time code is {code}.');
    const userId = await createTestUser('Admin');
    const req = fakeReq();

    const logContents = await captureEmailLog(() => twoFactorService.issueCodeFor(req, userId, 'email', 'two-fa-test@nexacrest.test'));

    expect(logContents).toContain('JEST-CUSTOM-2FA');
    expect(logContents).toMatch(/Your one-time code is \d{6}\./);
  });

  it('the client-portal invite email uses the admin-edited template', async () => {
    await overwriteTemplate('client_portal_invite', 'JEST-CUSTOM-INVITE', 'Set your password at {set_password_link} ({email}).');
    const clientId = await createTestClient();
    await setClientEmail(clientId, 'portal-invite-test@nexacrest.test');
    const orderId = await createTestOrder(clientId);

    const logContents = await captureEmailLog(() => clientPortalService.provisionIfNeeded(clientId, orderId));

    expect(logContents).toContain('JEST-CUSTOM-INVITE');
    expect(logContents).toContain('portal-invite-test@nexacrest.test');
    expect(logContents).toContain('/client/set-password/');
  });

  it('the staff password-reset email uses the admin-edited template', async () => {
    await overwriteTemplate('staff_password_reset', 'JEST-CUSTOM-STAFF-RESET', 'Hello {name}, reset at {reset_link} ({email}).');
    const userId = await createTestUser('Admin');
    const email = await userEmail(userId);
    const req = fakeReq({ body: { email } });
    const res = fakeRes();

    const logContents = await captureEmailLog(() => authController.forgotPassword(req, res));

    expect(logContents).toContain('JEST-CUSTOM-STAFF-RESET');
    expect(logContents).toContain('Jest Test User');
    expect(logContents).toContain('/reset-password/');
  });

  it('the client-portal password-reset email uses the admin-edited template', async () => {
    const clientId = await createTestClient();
    await setClientEmail(clientId, 'portal-reset-test@nexacrest.test');
    const orderId = await createTestOrder(clientId);
    // Provisions the real client_logins row this flow needs — ignore the
    // invite email this also sends.
    await captureEmailLog(() => clientPortalService.provisionIfNeeded(clientId, orderId));

    await overwriteTemplate('client_portal_password_reset', 'JEST-CUSTOM-PORTAL-RESET', 'Reset at {reset_link} ({email}).');
    const req = fakeReq({ body: { email: 'portal-reset-test@nexacrest.test' } });
    const res = fakeRes();

    const logContents = await captureEmailLog(() => clientPortalController.forgotPassword(req, res));

    expect(logContents).toContain('JEST-CUSTOM-PORTAL-RESET');
    expect(logContents).toContain('portal-reset-test@nexacrest.test');
    expect(logContents).toContain('/client/set-password/');
  });

  it('the quotation correction-link email uses the admin-edited template', async () => {
    await overwriteTemplate('quotation_correction_link', 'JEST-CUSTOM-CORRECTION', 'Correct it at {correction_link}.');
    const req = fakeReq({
      ip: '203.0.113.77',
      body: {
        company_legal_name: 'Email Template Completeness Test Co',
        billing_address_line1: '1 Test Street',
        billing_city: 'Testville',
        billing_country: 'Testland',
        vat_eori_tax_no: 'VAT123',
        contact_person: 'Jane Test',
        email: 'correction-link-test@nexacrest.test',
        country_of_destination: 'Testland',
        incoterm_preference: 'FOB',
      },
    });
    const res = fakeRes();

    const logContents = await captureEmailLog(() => clientIntakeController.submit(req, res));

    expect(logContents).toContain('JEST-CUSTOM-CORRECTION');
    expect(logContents).toContain('/quotation-details/edit/');
  });

  it('the PI-form link email uses the admin-edited template', async () => {
    await overwriteTemplate('pi_form_link', 'JEST-CUSTOM-PI-LINK', 'Order {order_reference} — confirm at {pi_form_link}.');
    const clientId = await createTestClient();
    await setClientEmail(clientId, 'pi-form-link-test@nexacrest.test');
    const orderId = await createTestOrder(clientId);
    const userId = await createTestUser('Admin');
    const req = fakeReq({ params: { id: String(orderId) }, user: { id: userId } });
    const res = fakeRes();

    const logContents = await captureEmailLog(() => ordersController.generatePiFormLink(req, res));

    expect(logContents).toContain('JEST-CUSTOM-PI-LINK');
    expect(logContents).toContain('/pi-details/');
  });

  it('the Buyer Action link email uses the admin-edited template', async () => {
    await overwriteTemplate('buyer_action_link', 'JEST-CUSTOM-BUYER-ACTION', 'Order {order_reference} — visit {buyer_action_link}.');
    const clientId = await createTestClient();
    await setClientEmail(clientId, 'buyer-action-link-test@nexacrest.test');
    const orderId = await createTestOrder(clientId);
    const userId = await createTestUser('Admin');
    const req = fakeReq({ params: { id: String(orderId) }, user: { id: userId } });
    const res = fakeRes();

    const logContents = await captureEmailLog(() => ordersController.generateBuyerActionLink(req, res));

    expect(logContents).toContain('JEST-CUSTOM-BUYER-ACTION');
    expect(logContents).toContain('/buyer-action/');
  });
});
