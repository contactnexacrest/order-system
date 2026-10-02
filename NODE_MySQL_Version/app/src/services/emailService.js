'use strict';

const nodemailer = require('nodemailer');
const env = require('../config/env');
const testModeService = require('./testModeService');
const mailRedirectService = require('./mailRedirectService');

/**
 * Port of App\Services\EmailService. The PHP version gated real sending on
 * "is PHPMailer installed" (vendor/ present) as well as SMTP config, because
 * Phase A had to run before Composer had ever been touched; in Node,
 * nodemailer is always installed (package.json dependency, not optional), so
 * the only gate that still makes sense is "is SMTP actually configured" —
 * same dev/production behavior, one fewer condition.
 */
let cachedTransport = null;
function transport() {
  const host = env.get('SMTP_HOST');
  if (!host) return null;
  if (cachedTransport) return cachedTransport;
  cachedTransport = nodemailer.createTransport({
    host,
    port: env.getInt('SMTP_PORT', 587),
    secure: env.get('SMTP_ENCRYPTION', 'tls') === 'ssl',
    auth: env.get('SMTP_USERNAME') ? { user: env.get('SMTP_USERNAME'), pass: env.get('SMTP_PASSWORD') } : undefined,
  });
  return cachedTransport;
}

function fromHeader() {
  return {
    name: env.get('SMTP_FROM_NAME', 'NexaCrest International Private Limited'),
    address: env.get('SMTP_FROM_ADDRESS', 'no-reply@example.com'),
  };
}

/**
 * @param {{isSecurityEmail?: boolean}} [opts] — set true for staff 2FA/password-reset emails, which Test Mode never redirects.
 * @returns {Promise<boolean>} true if actually handed to a transport, false if only logged (dev fallback)
 */
async function sendPlainText(toEmail, subject, body, opts = {}) {
  const resolved = await testModeService.resolveEmailRecipient(toEmail, !!opts.isSecurityEmail, subject);
  if (resolved === null) {
    // QA-5 TM-07: Test Mode is on and no test_email is configured — never
    // fall back to sending this to the real address.
    return false;
  }
  const to = await mailRedirectService.resolveRecipient(resolved, !!opts.isSecurityEmail);
  const cc = await mailRedirectService.ccList(!!opts.isSecurityEmail);
  const t = transport();
  if (!t) {
    console.error(`[EMAIL NOT SENT — no SMTP configured] To: ${to} | Subject: ${subject} | Body: ${body}`);
    return false;
  }
  try {
    await t.sendMail({ from: fromHeader(), to, cc: cc.length ? cc : undefined, subject, text: body });
    return true;
  } catch (e) {
    console.error('[EMAIL SEND FAILURE]', e.message);
    return false;
  }
}

/**
 * Phase D — deferred client document sends. The buyer gets exactly one
 * attachment at attachmentPath — never enforced here, only by what the
 * caller passes in (same contract as the PHP original).
 * @param {{isSecurityEmail?: boolean}} [opts]
 * @returns {Promise<boolean>}
 */
async function sendWithAttachment(toEmail, subject, body, attachmentPath, attachmentName, opts = {}) {
  return sendWithAttachments(toEmail, subject, body, [{ path: attachmentPath, name: attachmentName }], opts);
}

/**
 * Order progress chat (docs/schema.sql Section AI) can carry several
 * images/videos on one comment — this is the general form
 * sendWithAttachment() above now delegates to.
 * @param {Array<{path: string, name: string}>} attachments
 * @param {{isSecurityEmail?: boolean}} [opts]
 * @returns {Promise<boolean>}
 */
async function sendWithAttachments(toEmail, subject, body, attachments, opts = {}) {
  const resolved = await testModeService.resolveEmailRecipient(toEmail, !!opts.isSecurityEmail, subject);
  if (resolved === null) {
    // QA-5 TM-07: see sendPlainText() above.
    return false;
  }
  const to = await mailRedirectService.resolveRecipient(resolved, !!opts.isSecurityEmail);
  const cc = await mailRedirectService.ccList(!!opts.isSecurityEmail);
  return deliverWithAttachments(to, subject, body, attachments, cc);
}

/**
 * QA-5 TM-08: mailSenderService is the one caller that needs to resolve the
 * Test Mode recipient itself, BEFORE choosing Zoho vs. this SMTP fallback —
 * Zoho's own send() call has to see the resolved (possibly redirected)
 * address too, so the gate can't live only inside sendWithAttachments()
 * above. This is that already-resolved delivery path; `to` here is never
 * re-checked against Test Mode.
 * @param {Array<{path: string, name: string}>} attachments
 * @param {string[]} [cc]
 * @returns {Promise<boolean>}
 */
async function deliverWithAttachments(to, subject, body, attachments, cc = []) {
  const t = transport();
  if (!t) {
    const names = attachments.map((a) => a.name).join(', ');
    console.error(`[EMAIL NOT SENT — no SMTP configured] To: ${to} | Subject: ${subject} | Attachments: ${names}`);
    return false;
  }
  try {
    await t.sendMail({
      from: fromHeader(),
      to,
      cc: cc.length ? cc : undefined,
      subject,
      text: body,
      attachments: attachments.map((a) => ({ filename: a.name, path: a.path })),
    });
    return true;
  } catch (e) {
    console.error('[EMAIL SEND FAILURE]', e.message);
    return false;
  }
}

module.exports = { sendPlainText, sendWithAttachment, sendWithAttachments, deliverWithAttachments };
