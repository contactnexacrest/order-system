'use strict';

const nodemailer = require('nodemailer');
const env = require('../config/env');
const testModeService = require('./testModeService');

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
 * Test Mode (docs/schema.sql Section V) redirect — the single chokepoint
 * both send functions below funnel through, so nothing that calls either
 * of them has to know about Test Mode. `isSecurityEmail` is the one
 * exception carved out by design: staff's own 2FA codes and password-reset
 * links must keep going to the real address they belong to, or Test Mode
 * would lock staff out of their own accounts. Every other call site
 * (order/document notices, buyer communications, reminder alerts, client
 * portal access emails) defaults to redirectable.
 */
async function resolveRecipient(toEmail, isSecurityEmail, subject) {
  if (isSecurityEmail) return toEmail;
  const settings = await testModeService.getSettings();
  if (!settings || parseInt(settings.is_enabled, 10) !== 1) return toEmail;
  if (!settings.test_email) {
    console.error(`[TEST MODE — no test_email configured, sending to real address] To: ${toEmail} | Subject: ${subject}`);
    return toEmail;
  }
  console.log(`[TEST MODE — email redirected] Original To: ${toEmail} -> Test: ${settings.test_email} | Subject: ${subject}`);
  return settings.test_email;
}

/**
 * @param {{isSecurityEmail?: boolean}} [opts] — set true for staff 2FA/password-reset emails, which Test Mode never redirects.
 * @returns {Promise<boolean>} true if actually handed to a transport, false if only logged (dev fallback)
 */
async function sendPlainText(toEmail, subject, body, opts = {}) {
  const to = await resolveRecipient(toEmail, !!opts.isSecurityEmail, subject);
  const t = transport();
  if (!t) {
    console.error(`[EMAIL NOT SENT — no SMTP configured] To: ${to} | Subject: ${subject} | Body: ${body}`);
    return false;
  }
  try {
    await t.sendMail({ from: fromHeader(), to, subject, text: body });
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
  const to = await resolveRecipient(toEmail, !!opts.isSecurityEmail, subject);
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

module.exports = { sendPlainText, sendWithAttachment, sendWithAttachments };
