'use strict';

/**
 * Spec Section 14 — "DATA MASKING: Client email/phone masked on UI based
 * on permission. Stored plain in DB. Masked at render time." Deliberately
 * a pure display-time transform, not a DB concern — the raw value is what
 * every controller/repository already passes around (documents sent to
 * the buyer need the real address), this only ever runs inside a view
 * just before echoing (registered as Nunjucks filters — see server.js).
 */

function maskEmail(email) {
  if (!email) return '—';
  const at = email.indexOf('@');
  if (at === -1) {
    return '•'.repeat(Math.min(email.length, 6));
  }
  const local = email.slice(0, at);
  const domain = email.slice(at + 1);
  const visible = local.slice(0, 2);
  return `${visible}${'•'.repeat(Math.max(3, local.length - 2))}@${domain}`;
}

function maskPhone(phone) {
  if (!phone) return '—';
  const digitsOnly = String(phone).replace(/\D/g, '');
  const lastFour = digitsOnly.slice(-4);
  return `•••• ${lastFour}`;
}

module.exports = { maskEmail, maskPhone };
