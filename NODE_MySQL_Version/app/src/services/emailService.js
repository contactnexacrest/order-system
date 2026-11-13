'use strict';

const crypto = require('crypto');
const nodemailer = require('nodemailer');
const env = require('../config/env');
const testModeService = require('./testModeService');
const mailRedirectService = require('./mailRedirectService');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const { toRfc2822Ist } = require('../helpers/dbDateTime');

/**
 * Port of App\Services\EmailService. The PHP version gated real sending on
 * "is PHPMailer installed" (vendor/ present) as well as SMTP config, because
 * Phase A had to run before Composer had ever been touched; in Node,
 * nodemailer is always installed (package.json dependency, not optional), so
 * the only gate that still makes sense is "is SMTP actually configured" —
 * same dev/production behavior, one fewer condition.
 */
/**
 * QA Round 5, R5-17: SMTP_ENCRYPTION means the same on both stacks — blank
 * or 'tls' is STARTTLS and the send fails if the server doesn't offer it
 * (PHP's PHPMailer SMTPSecure='tls'); 'ssl' is implicit TLS. Before, a
 * blank value here sent in clear when the server offered no TLS.
 */
function smtpSecurityOptions() {
  const encryption = String(env.get('SMTP_ENCRYPTION', 'tls')).toLowerCase();
  return { secure: encryption === 'ssl', requireTLS: encryption === 'tls' };
}

let cachedTransport = null;
function transport() {
  const host = env.get('SMTP_HOST');
  if (!host) return null;
  if (cachedTransport) return cachedTransport;
  cachedTransport = nodemailer.createTransport({
    host,
    port: env.getInt('SMTP_PORT', 587),
    ...smtpSecurityOptions(),
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

function mailDomain() {
  const fromAddress = env.get('SMTP_FROM_ADDRESS', 'no-reply@example.com');
  const atPos = fromAddress.lastIndexOf('@');
  return atPos !== -1 ? fromAddress.slice(atPos + 1) : 'example.com';
}

/**
 * External QA report L-43: customer emails had no Reply-To, so a buyer's
 * reply to a QT/PI/OC (e.g. "we confirm the OC") went to the no-reply
 * mailbox and was lost. Falls back to the general company contact email
 * when no dedicated reply-to address is configured.
 */
async function replyToAddress() {
  const configured = (await companySettingsRepository.get('reply_to_email')) || '';
  if (configured.trim() !== '') {
    return configured.trim();
  }
  return ((await companySettingsRepository.get('email')) || '').trim() || undefined;
}

/**
 * External QA report L-46: nodemailer defaults the Message-ID header's
 * domain to the local machine's own hostname (e.g. <...@vm>) rather than
 * the company's own mail domain — a mismatch spam filters penalize.
 * Derived from SMTP_FROM_ADDRESS's own domain, matching the PHPMailer
 * Hostname fix in the PHP stack's EmailService.
 */
function freshMessageId() {
  return `<${crypto.randomBytes(16).toString('hex')}@${mailDomain()}>`;
}

/** Common nodemailer identity headers applied to every real (non-test) send — see replyToAddress()/freshMessageId()/toRfc2822Ist() above. */
async function identityOptions() {
  return {
    replyTo: await replyToAddress(),
    messageId: freshMessageId(),
    date: toRfc2822Ist(),
  };
}

/**
 * @param {{isSecurityEmail?: boolean}} [opts] — set true for staff 2FA/password-reset emails, which Test Mode never redirects.
 * @returns {Promise<boolean>} true if actually handed to a transport, false if only logged (dev fallback)
 */
// QA MTX-008: while a withoutSending() scope is active (this async call chain only — never other requests),
// no e-mail of any kind leaves; the reason is logged instead.
const { AsyncLocalStorage } = require('async_hooks');

const suppression = new AsyncLocalStorage();

/**
 * QA MTX-008: runs fn with every outgoing e-mail held — security mail included. Used while sample data is
 * loaded: demo records must not e-mail anyone (buyers, staff). Scoped to this call chain only.
 */
function withoutSending(reason, fn) {
  return suppression.run({ reason }, fn);
}

/** QA MTX-008: true (and the e-mail written to the log) when sending is suppressed right now. */
function suppressed(to, subject) {
  const s = suppression.getStore();
  if (!s) return false;
  console.error(`[EMAIL NOT SENT — ${s.reason}] To: ${to} | Subject: ${subject}`);
  return true;
}

async function sendPlainText(toEmail, subject, body, opts = {}) {
  // QA batch 3 MAIL-2: every business e-mail (link e-mails, notifications,
  // alerts) goes through mailSenderService — the one place with the "e-mail
  // sending off" switch, Test Mode, Mail Redirect and the Zoho transport.
  // Only security mail (2FA, password reset, one-time codes) keeps this path.
  // Required here, not at the top: mailSenderService requires this module.
  if (!opts.isSecurityEmail) {
    // D-280: the e-mail's kind and audience (Admin › E-mail settings) travel with it.
    return opts.kind
      ? require('./mailSenderService').send(toEmail, subject, body, [], { kind: opts.kind, audience: opts.audience })
      : require('./mailSenderService').send(toEmail, subject, body);
  }
  if (suppressed(toEmail, subject)) return false; // QA MTX-008
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
    await t.sendMail({ from: fromHeader(), to, cc: cc.length ? cc : undefined, subject, text: body, ...(await identityOptions()) });
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
  // QA batch 3 MAIL-2: business mail goes through mailSenderService (see sendPlainText()).
  if (!opts.isSecurityEmail) {
    return opts.kind
      ? require('./mailSenderService').send(toEmail, subject, body, attachments, { kind: opts.kind, audience: opts.audience })
      : require('./mailSenderService').send(toEmail, subject, body, attachments);
  }
  if (suppressed(toEmail, subject)) return false; // QA MTX-008
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
  // QA batch 3 MAIL-10: an attachment whose file is missing fails the send
  // clearly (as on PHP), before any transport is tried.
  const missing = attachments.find((a) => !a.path || !require('fs').existsSync(a.path));
  if (missing) {
    console.error(`[EMAIL NOT SENT — attachment missing] To: ${to} | Subject: ${subject} | Attachment: ${missing.name || ''}`);
    return false;
  }
  const t = transport();
  if (!t) {
    const names = attachments.map((a) => a.name).join(', ');
    // D-280: the log keeps the full text (as sendPlainText's own fallback does), so a link can be sent by hand.
    console.error(`[EMAIL NOT SENT — no SMTP configured] To: ${to} | Subject: ${subject} | Attachments: ${names} | Body: ${body}`);
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
      ...(await identityOptions()),
    });
    return true;
  } catch (e) {
    console.error('[EMAIL SEND FAILURE]', e.message);
    return false;
  }
}

/**
 * Settings "Send Test Email" button (docs/schema.sql Section AS). Unlike
 * sendPlainText()/sendWithAttachments(), this never swallows a failure
 * into a logged line and a false return — the whole point of this entry
 * point is letting an admin see the *actual* SMTP error (bad credentials,
 * connection refused, TLS mismatch, …) right on the settings screen while
 * they're configuring it, the same way settingsController.testZohoEmail
 * calls zohoMailService.send() directly rather than through
 * mailSenderService's silent fallback. Deliberately bypasses Test Mode
 * and Mail Redirect entirely — this confirms the raw SMTP_* credentials
 * actually work, not a simulated business email, so it must go to
 * exactly the address the admin typed.
 *
 * @throws if SMTP isn't configured or the send itself fails
 */
async function sendTestEmail(toEmail, subject, body) {
  const t = transport();
  if (!t) {
    throw new Error('SMTP_HOST is not configured in .env.');
  }
  await t.sendMail({ from: fromHeader(), to: toEmail, subject, text: body });
  return true;
}

module.exports = {
  withoutSending,
  suppressed,
  sendPlainText, sendWithAttachment, sendWithAttachments, deliverWithAttachments, sendTestEmail,
  mailDomain, replyToAddress, freshMessageId, smtpSecurityOptions,
};
