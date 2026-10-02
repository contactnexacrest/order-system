'use strict';

const companySettingsRepository = require('../repositories/companySettingsRepository');

/**
 * Port of App\Services\MailRedirectService (docs/schema.sql Section AS).
 * Independent of Test Mode (testModeService) — a separate on/off switch so
 * staff can rehearse real outgoing mail (CC lists, templates, attachments)
 * without turning on the whole Test Mode sandbox. Every transport chains
 * this immediately after testModeService.resolveEmailRecipient() so
 * neither switch can be bypassed by picking a different transport.
 * isSecurityEmail is the same carve-out as Test Mode, for the same reason:
 * staff's own 2FA codes and password-reset links must always reach the
 * real address, never be redirected or CC'd to a third party.
 */

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function isValidEmail(addr) {
  return typeof addr === 'string' && EMAIL_RE.test(addr);
}

async function resolveRecipient(toEmail, isSecurityEmail) {
  if (isSecurityEmail) return toEmail;
  if ((await companySettingsRepository.get('mail_redirect_enabled')) !== '1') return toEmail;

  const redirectTo = ((await companySettingsRepository.get('mail_redirect_address')) || '').trim();
  if (!isValidEmail(redirectTo)) {
    console.error(`[MAIL REDIRECT — enabled but mail_redirect_address is blank/invalid, sending to original recipient] To: ${toEmail}`);
    return toEmail;
  }
  console.error(`[MAIL REDIRECT — email redirected] Original To: ${toEmail} -> ${redirectTo}`);
  return redirectTo;
}

/**
 * @returns {Promise<string[]>} deduplicated, validated CC addresses —
 *   mail_cc_emails (comma-separated) plus mail_default_cc_email, always
 *   applied together. Empty for a security email.
 */
async function ccList(isSecurityEmail) {
  if (isSecurityEmail) return [];

  const addresses = [];
  const raw = (await companySettingsRepository.get('mail_cc_emails')) || '';
  for (const part of raw.split(',')) {
    const addr = part.trim();
    if (isValidEmail(addr)) addresses.push(addr);
  }

  const defaultCc = ((await companySettingsRepository.get('mail_default_cc_email')) || '').trim();
  if (isValidEmail(defaultCc)) addresses.push(defaultCc);

  return [...new Set(addresses)];
}

module.exports = { resolveRecipient, ccList };
