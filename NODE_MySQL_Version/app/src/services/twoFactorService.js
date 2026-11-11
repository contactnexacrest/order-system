'use strict';

const crypto = require('crypto');
const bcrypt = require('bcrypt');
const env = require('../config/env');
const emailService = require('./emailService');
const emailTemplateRepository = require('../repositories/emailTemplateRepository');

// Port of App\Services\TwoFactorService — the 6-digit login code e-mailed at
// every sign-in (owner decision D-265, 7 Nov 2026): every staff member, every
// buyer. Associates keep their own code (associatePortalService), unchanged.
//
// The code is short-lived and single-use, so it lives in the session, scoped to
// the sign-in in progress. Each portal has its own pending slot ('staff' /
// 'client'), so a staff sign-in and a buyer sign-in in one browser never mix.
// Rules (as the associate code): valid 10 minutes, 5 tries, a new code at most
// once a minute; always by e-mail (SMS stays off), sent as security mail.

const CODE_TTL_SECONDS = 600; // 10 minutes
/** D-280: the buyer's own login-code wording (template client_login_code; this is its fallback). */
const CLIENT_CODE_BODY = 'Hello,\n\nYour code to log in to your NexaCrest order portal is: {code}\n\nIt is valid for 10 minutes.\n\nIf you did not try to log in, you can ignore this e-mail — nobody can log in without this code. If it keeps happening, change your password with "Forgot your password?" on the login page.\n\nNexaCrest International Private Limited';
const MAX_VERIFY_ATTEMPTS = 5;
const RESEND_AFTER_SECONDS = 60;
const SESSION_KEYS = { staff: '_2fa_pending', client: '_client_2fa_pending' };

function key(scope) {
  return SESSION_KEYS[scope] || SESSION_KEYS.staff;
}

/** Sends a new code by e-mail. Returns the code only in local dev (APP_ENV=local), else ''. */
async function issueCodeFor(req, subjectId, method, destination, scope = 'staff') {
  const code = String(crypto.randomInt(0, 1000000)).padStart(6, '0');
  const now = Date.now();
  req.session[key(scope)] = {
    user_id: subjectId,
    method: 'email',
    destination,
    code_hash: await bcrypt.hash(code, 10),
    issued_at: now,
    expires_at: now + CODE_TTL_SECONDS * 1000,
    attempts: 0,
  };
  // 90-item backlog #37: admin-editable (Admin > Settings > Email Templates), with this
  // wording as the fallback, since a login code must never silently fail to go out.
  // D-280: each audience gets its own plain wording — a buyer has no "administrator".
  const rendered = scope === 'client'
    ? await emailTemplateRepository.renderOrDefault(
      'client_login_code',
      { '{code}': code },
      'Your NexaCrest order portal login code',
      CLIENT_CODE_BODY
    )
    : await emailTemplateRepository.renderOrDefault(
      'two_factor_code',
      { '{code}': code },
      'Your NexaCrest login verification code',
      'Your verification code is: {code}\nThis code expires in 10 minutes. If you did not request this, contact your administrator.'
    );
  await emailService.sendPlainText(destination, rendered.subject, rendered.body, { isSecurityEmail: true, kind: 'login_code', audience: scope === 'client' ? 'buyer' : 'staff' });

  // Local dev only: the code is shown on screen so the flow can be tried without a mail server.
  return env.isLocal() ? code : '';
}

function pendingUserId(req, scope = 'staff') {
  const p = req.session[key(scope)];
  return p ? Number(p.user_id) : null;
}

/** A new code for the sign-in in progress, at most once a minute. */
async function resend(req, scope = 'staff') {
  const p = req.session[key(scope)];
  if (!p) return { status: 'none' };
  const wait = Math.ceil((Number(p.issued_at) + RESEND_AFTER_SECONDS * 1000 - Date.now()) / 1000);
  if (wait > 0) return { status: 'too_soon', wait };
  const devCode = await issueCodeFor(req, Number(p.user_id), 'email', String(p.destination), scope);
  return { status: 'sent', dev_code: devCode };
}

/** Counts a try against the pending sign-in; false (and the sign-in ends) once 5 tries are used or it expired. */
function useTry(req, scope) {
  const k = key(scope);
  const p = req.session[k];
  if (!p) return false;
  if (p.attempts >= MAX_VERIFY_ATTEMPTS || Date.now() > p.expires_at) {
    delete req.session[k];
    return false;
  }
  p.attempts += 1;
  return true;
}

async function verify(req, submittedCode, scope = 'staff') {
  if (!useTry(req, scope)) return false;
  const k = key(scope);
  if (await bcrypt.compare(String(submittedCode).trim(), req.session[k].code_hash)) {
    delete req.session[k];
    return true;
  }
  if (req.session[k].attempts >= MAX_VERIFY_ATTEMPTS) delete req.session[k];
  return false;
}

/** A backup-code try counts against the same 5 tries; the caller checks the backup code itself. */
function countBackupTry(req, scope = 'staff') {
  return useTry(req, scope);
}

function clear(req, scope = 'staff') {
  delete req.session[key(scope)];
}

module.exports = {
  issueCodeFor, pendingUserId, resend, verify, countBackupTry, clear,
  CODE_TTL_SECONDS, MAX_VERIFY_ATTEMPTS, RESEND_AFTER_SECONDS, CLIENT_CODE_BODY,
};
