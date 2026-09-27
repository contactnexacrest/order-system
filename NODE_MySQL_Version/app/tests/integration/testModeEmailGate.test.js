'use strict';

const db = require('../../src/config/db');
const testModeService = require('../../src/services/testModeService');
const emailService = require('../../src/services/emailService');

/**
 * QA-5 (TM-07/TM-08 — external QA report cross-verification):
 *
 * TM-07: when Test Mode is on but no test_email is configured yet,
 * emailService's Test Mode redirect fell back to sending straight to the
 * real address — defeating Test Mode's whole point at exactly the moment
 * it matters most (a fresh, not-yet-fully-configured Test Mode session).
 *
 * TM-08: the Test Mode gate lived only inside emailService's own SMTP send
 * functions. mailSenderService — the actual chokepoint every
 * order/document/comment email goes through — tried Zoho Mail FIRST, with
 * the untouched real address, and only fell back to emailService (and its
 * gate) if Zoho failed. Turning Zoho on was a complete bypass of Test
 * Mode. Fixed by moving the resolve to the very top of
 * mailSenderService.send(), before either transport is chosen — this file
 * covers the shared resolver both fixes now go through.
 */
describe('Test Mode email gate (QA-5 TM-07/TM-08)', () => {
  let originalSettings;

  beforeAll(async () => {
    originalSettings = await testModeService.getSettings();
  });

  afterEach(async () => {
    // test_mode_settings is a single shared row (id = 1) — every test here
    // must leave it exactly as it found it for whatever test runs next in
    // this same Jest run.
    await db.execute('UPDATE test_mode_settings SET is_enabled = :enabled, test_email = :email WHERE id = 1', {
      enabled: originalSettings.is_enabled,
      email: originalSettings.test_email,
    });
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function setTestModeEnabled(enabled) {
    await db.execute('UPDATE test_mode_settings SET is_enabled = :enabled WHERE id = 1', { enabled: enabled ? 1 : 0 });
  }

  it('passes the real address through when Test Mode is off', async () => {
    await testModeService.setTestEmail('');
    await setTestModeEnabled(false);

    const result = await testModeService.resolveEmailRecipient('buyer@real-company.example', false, 'Order Confirmation');

    expect(result).toBe('buyer@real-company.example');
  });

  it('always bypasses Test Mode for a security email, regardless of settings', async () => {
    await setTestModeEnabled(true);
    await testModeService.setTestEmail(''); // deliberately unconfigured — must not matter for a security email

    const result = await testModeService.resolveEmailRecipient('staff@nexacrest.example', true, '2FA code');

    expect(result).toBe('staff@nexacrest.example');
  });

  it('redirects to the configured test email when Test Mode is on', async () => {
    await setTestModeEnabled(true);
    await testModeService.setTestEmail('qa-inbox@nexacrest.example');

    const result = await testModeService.resolveEmailRecipient('buyer@real-company.example', false, 'Order Confirmation');

    expect(result).toBe('qa-inbox@nexacrest.example');
  });

  // QA-5 TM-07: the actual regression this defect described.
  it('blocks the send instead of falling back to the real address when no test email is configured', async () => {
    await setTestModeEnabled(true);
    await testModeService.setTestEmail('');

    const result = await testModeService.resolveEmailRecipient('buyer@real-company.example', false, 'Order Confirmation');

    expect(result).toBeNull();
  });

  // QA-5 TM-07 at the public API level: both emailService send functions —
  // the ones every other call site (2FA aside) actually calls — must
  // refuse the send outright rather than quietly delivering it to the real
  // address.
  it('emailService refuses to send rather than fall back to the real address', async () => {
    await setTestModeEnabled(true);
    await testModeService.setTestEmail('');

    const plainTextResult = await emailService.sendPlainText('buyer@real-company.example', 'Order Confirmation', 'body');
    const attachmentResult = await emailService.sendWithAttachments('buyer@real-company.example', 'Order Confirmation', 'body', []);

    expect(plainTextResult).toBe(false);
    expect(attachmentResult).toBe(false);
  });
});
