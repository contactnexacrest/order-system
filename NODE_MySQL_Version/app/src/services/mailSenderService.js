'use strict';

const emailService = require('./emailService');
const testModeService = require('./testModeService');
const zohoMailService = require('./zohoMailService');
const mailRedirectService = require('./mailRedirectService');
const emailPolicy = require('./emailPolicy');

/**
 * Why the last send() returned false: 'held' (Admin › E-mail settings or
 * testing mode — see emailPolicy.holdReason()) or 'failed'; '' after a
 * successful send. Lets a caller word its message truthfully.
 */
const state = { lastOutcome: '' };

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
 * @param {{isSecurityEmail?: boolean, kind?: string, audience?: string}} [opts]
 * @returns {Promise<boolean>} true if actually handed to a transport, false if only logged (dev fallback)
 */
async function send(to, subject, body, attachments = [], opts = {}) {
  // Owner decision D-280 (11 Nov 2026): the old master kill switch
  // (mail_sending_enabled) is replaced by Admin › E-mail settings — one switch
  // per e-mail kind and audience, MUST e-mails always on — and by "Testing
  // mode", which holds only buyer and supplier e-mails. Checked here, the one
  // chokepoint every business send passes through.
  state.lastOutcome = '';
  // QA MTX-008: nothing leaves while sample data is being loaded.
  if (require('./emailService').suppressed(to, subject)) {
    state.lastOutcome = 'held';
    return false;
  }
  if (opts.kind && opts.audience) {
    const hold = await emailPolicy.holdReason(opts.kind, opts.audience);
    if (hold !== null) {
      // The body is logged too, so staff can send it themselves.
      console.error(`[EMAIL NOT SENT — ${hold}] Kind: ${opts.kind}/${opts.audience} | To: ${to} | Subject: ${subject} | Body: ${body}`);
      state.lastOutcome = 'held';
      return false;
    }
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
    state.lastOutcome = 'failed';
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

  const ok = await emailService.deliverWithAttachments(resolved, subject, body, attachments, cc);
  state.lastOutcome = ok ? '' : 'failed';
  return ok;
}

module.exports = { send, state };
