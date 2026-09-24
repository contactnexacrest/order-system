'use strict';

const emailService = require('./emailService');
const zohoMailService = require('./zohoMailService');

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
  if (!opts.isSecurityEmail && (await zohoMailService.isEnabled())) {
    try {
      const sent = await zohoMailService.send(to, subject, body, attachments);
      if (sent) return true;
      console.error('[ZOHO MAIL — send returned false, falling back to SMTP] To:', to);
    } catch (e) {
      console.error('[ZOHO MAIL — exception, falling back to SMTP]', e.message);
    }
  }

  return emailService.sendWithAttachments(to, subject, body, attachments, opts);
}

module.exports = { send };
