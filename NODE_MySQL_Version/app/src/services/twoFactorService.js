'use strict';

const crypto = require('crypto');
const bcrypt = require('bcrypt');
const env = require('../config/env');
const emailService = require('./emailService');
const smsService = require('./smsService');

// Port of App\Services\TwoFactorService. Session-scoped to the in-progress
// login attempt (not users.two_fa_secret, which is the persistent per-user
// config) — same distinction as the PHP original. Every function takes
// `req` since Express has no $_SESSION global.

const SESSION_KEY = '_2fa_pending';
const CODE_TTL_SECONDS = 300; // 5 minutes
const MAX_VERIFY_ATTEMPTS = 5;

async function issueCodeFor(req, userId, method, destination) {
  const code = String(crypto.randomInt(0, 1000000)).padStart(6, '0');

  req.session[SESSION_KEY] = {
    user_id: userId,
    method,
    code_hash: await bcrypt.hash(code, 10),
    expires_at: Date.now() + CODE_TTL_SECONDS * 1000,
    attempts: 0,
  };

  const subject = 'Your NexaCrest login verification code';
  const body = `Your verification code is: ${code}\nThis code expires in 5 minutes. If you did not request this, contact your administrator.`;

  if (method === 'sms' && smsService.isAvailable()) {
    await smsService.send(destination, `NexaCrest login code: ${code} (expires in 5 min)`);
  } else {
    await emailService.sendPlainText(destination, subject, body, { isSecurityEmail: true });
  }

  // Local dev only — surfaces the code so the flow can be tested without a
  // real SMTP/SMS provider wired up. Never happens outside APP_ENV=local.
  if (env.isLocal()) return code;
  return '';
}

function pendingUserId(req) {
  return req.session[SESSION_KEY]?.user_id ?? null;
}

async function verify(req, submittedCode) {
  const pending = req.session[SESSION_KEY];
  if (!pending) return false;

  if (pending.attempts >= MAX_VERIFY_ATTEMPTS) {
    delete req.session[SESSION_KEY];
    return false;
  }
  if (Date.now() > pending.expires_at) {
    delete req.session[SESSION_KEY];
    return false;
  }

  pending.attempts += 1;

  if (await bcrypt.compare(submittedCode, pending.code_hash)) {
    delete req.session[SESSION_KEY];
    return true;
  }
  return false;
}

function clear(req) {
  delete req.session[SESSION_KEY];
}

module.exports = { issueCodeFor, pendingUserId, verify, clear };
