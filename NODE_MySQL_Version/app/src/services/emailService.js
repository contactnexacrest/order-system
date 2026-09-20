'use strict';

const nodemailer = require('nodemailer');
const env = require('../config/env');

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

/** @returns {Promise<boolean>} true if actually handed to a transport, false if only logged (dev fallback) */
async function sendPlainText(toEmail, subject, body) {
  const t = transport();
  if (!t) {
    console.error(`[EMAIL NOT SENT — no SMTP configured] To: ${toEmail} | Subject: ${subject} | Body: ${body}`);
    return false;
  }
  try {
    await t.sendMail({ from: fromHeader(), to: toEmail, subject, text: body });
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
 * @returns {Promise<boolean>}
 */
async function sendWithAttachment(toEmail, subject, body, attachmentPath, attachmentName) {
  const t = transport();
  if (!t) {
    console.error(`[EMAIL NOT SENT — no SMTP configured] To: ${toEmail} | Subject: ${subject} | Attachment: ${attachmentName}`);
    return false;
  }
  try {
    await t.sendMail({
      from: fromHeader(),
      to: toEmail,
      subject,
      text: body,
      attachments: [{ filename: attachmentName, path: attachmentPath }],
    });
    return true;
  } catch (e) {
    console.error('[EMAIL SEND FAILURE]', e.message);
    return false;
  }
}

module.exports = { sendPlainText, sendWithAttachment };
