'use strict';

const emailService = require('./emailService');
const testModeService = require('./testModeService');
const zohoMailService = require('./zohoMailService');
const mailRedirectService = require('./mailRedirectService');
const companySettingsRepository = require('../repositories/companySettingsRepository');

/**
 * docs/schema.sql Section AI — the one chokepoint every outbound email in
 * the app should go through from here on (order-comment notifications and
 * document sends today; 2FA/reset emails can be migrated onto it the same
 * way). When company_settings.zoho_mail_enabled is on, this tries the Zoho
 * Mail API first; on ANY failure at all — bad/missing credentials, a
 * network error, a malformed response, anything — it falls straight
 * through to the existing SMTP path (emailService) with no error ever
 * surfaced to the caller. Zoho is strictly an optional enhancement layer,
 * never something the app can be blocked by.
 *
 * @param {Array<{path: string, name: string}>} [attachments]
 * @param {{isSecurityEmail?: boolean}} [opts]
 * @returns {Promise<boolean>} true if actually handed to a transport, false if only logged (dev fallback)
 */
async function send(to, subject, body, attachments = [], opts = {}) {
  // docs/schema.sql Section AU: a master kill switch for business email,
  // separate from Test Mode/Mail Redirect — never applies to 2FA/password-
  // reset, matching every other gate in this function. Checked here, the
  // one chokepoint every non-security send passes through, so it can never
  // be bypassed by a future transport added below Zoho/SMTP.
  if (!opts.isSecurityEmail && (await companySettingsRepository.get('mail_sending_enabled')) === '0') {
    console.error(`[EMAIL NOT SENT — sending disabled by admin (mail_sending_enabled=0)] To: ${to} | Subject: ${subject}`);
    return false;
  }

  // QA-5 TM-08: resolved ONCE, here, before either transport is chosen.
  // Test Mode's redirect used to live only inside emailService's own send
  // functions — the SMTP fallback path — so turning Zoho on gave every
  // outbound email a way around Test Mode entirely, straight to the real
  // buyer's inbox. Both transports below now see the same, already-
  // resolved address.
  let resolved = await testModeService.resolveEmailRecipient(to, !!opts.isSecurityEmail, subject);
  if (resolved === null) {
    // QA-5 TM-07: Test Mode is on and no test_email is configured — never
    // fall back to sending this to the real address, via Zoho or SMTP.
    return false;
  }
  // docs/schema.sql Section AS: chained immediately after Test Mode's own
  // resolution, for the same reason QA-5 TM-08 resolves Test Mode here
  // rather than inside each transport — both transports below must see the
  // same, already-resolved address and CC list.
  resolved = await mailRedirectService.resolveRecipient(resolved, !!opts.isSecurityEmail);
  const cc = await mailRedirectService.ccList(!!opts.isSecurityEmail);

  if (!opts.isSecurityEmail && (await zohoMailService.isEnabled())) {
    try {
      const sent = await zohoMailService.send(resolved, subject, body, attachments, cc);
      if (sent) return true;
      console.error('[ZOHO MAIL — send returned false, falling back to SMTP] To:', resolved);
    } catch (e) {
      console.error('[ZOHO MAIL — exception, falling back to SMTP]', e.message);
    }
  }

  return emailService.deliverWithAttachments(resolved, subject, body, attachments, cc);
}

module.exports = { send };
