'use strict';

const { spawnSync } = require('child_process');

// Dev-only credentials, same ones used throughout this suite's setup
// (see e2e/README.md) — not a secret worth protecting, just a local
// disposable dev database. QA Round 5, R5-13: override them (and the
// database name) with E2E_DB_USER / E2E_DB_PASSWORD / E2E_DB_NAME.
const DB_USER = process.env.E2E_DB_USER || 'nexacrest_app';
const DB_PASSWORD = process.env.E2E_DB_PASSWORD || 'DevPass123!';

function isNodeStack() {
  return (process.env.BASE_URL || '').includes(':3000');
}

function dbName() {
  if (process.env.E2E_DB_NAME) return process.env.E2E_DB_NAME;
  return isNodeStack() ? 'nexacrest_node_dev' : 'nexacrest_php_dev';
}

function runSql(sql, what) {
  const result = spawnSync('mysql', ['-N', '-u', DB_USER, `-p${DB_PASSWORD}`, dbName(), '-e', sql]);
  if (result.status !== 0) {
    throw new Error(`${what} failed: ${result.stderr.toString()}`);
  }
  return result.stdout.toString().trim();
}

/**
 * Hashes with each stack's own bcrypt implementation rather than assuming
 * cross-compatibility (PHP's password_hash() produces a $2y$ hash, Node's
 * bcrypt package a $2b$ one) — the same discipline used for the staff
 * test accounts set up earlier in this session.
 */
function bcryptHash(password) {
  if (isNodeStack()) {
    const bcryptModulePath = require.resolve('bcrypt', {
      paths: ['/home/user/order-system/NODE_MySQL_Version/app/node_modules'],
    });
    const result = spawnSync('node', [
      '-e',
      `console.log(require(${JSON.stringify(bcryptModulePath)}).hashSync(${JSON.stringify(password)}, 12))`,
    ]);
    if (result.status !== 0) {
      throw new Error(`bcrypt hash failed: ${result.stderr.toString()}`);
    }
    return result.stdout.toString().trim();
  }

  const result = spawnSync('php', [
    '-r',
    `echo password_hash(${JSON.stringify(password)}, PASSWORD_BCRYPT, ['cost'=>12]);`,
  ]);
  if (result.status !== 0) {
    throw new Error(`php password_hash failed: ${result.stderr.toString()}`);
  }
  return result.stdout.toString().trim();
}

/**
 * The client-portal login (client_logins row) is provisioned automatically
 * by ClientPortalService::provisionIfNeeded() the moment an order's advance
 * payment clears, with a random password the client never sees — delivered
 * by an emailed set-password link that won't fire without SMTP in dev. For
 * a scripted test, overwrite the hash directly, mirroring how the staff
 * test accounts were set up for this suite.
 */
function resetClientPassword(email, password) {
  const hash = bcryptHash(password);
  const sql =
    `UPDATE client_logins SET password_hash='${hash}', force_password_change=0, is_active=1 ` +
    `WHERE client_id=(SELECT id FROM clients WHERE email='${email}')`;
  const result = spawnSync('mysql', ['-u', DB_USER, `-p${DB_PASSWORD}`, dbName(), '-e', sql]);
  if (result.status !== 0) {
    throw new Error(`client_logins password reset failed: ${result.stderr.toString()}`);
  }
}

/**
 * Creates (or resets, if one already exists) a client_logins row directly
 * for a given client email — used by specs whose feature under test
 * (e.g. Reorder Requests) has no stage requirement of its own but still
 * needs a real client-portal login, without making the test walk an
 * order all the way through the Stage 3 advance-cleared gate just to
 * trigger ClientPortalService::provisionIfNeeded() as a side effect.
 */
function provisionClientLogin(email, password) {
  const hash = bcryptHash(password);
  const sql =
    `INSERT INTO client_logins (client_id, password_hash, is_active, force_password_change) ` +
    `SELECT id, '${hash}', 1, 0 FROM clients WHERE email='${email}' ` +
    `ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), is_active=1, force_password_change=0`;
  const result = spawnSync('mysql', ['-u', DB_USER, `-p${DB_PASSWORD}`, dbName(), '-e', sql]);
  if (result.status !== 0) {
    throw new Error(`client_logins provisioning failed: ${result.stderr.toString()}`);
  }
}

/**
 * QA Round 5, R5-13: the seed ships with email sending OFF, but the email
 * specs (05, 08) exercise sending — each switches it to what it needs in
 * its own setup and puts the previous value back afterwards.
 */
function getMailSending() {
  return runSql("SELECT setting_value FROM company_settings WHERE setting_key = 'mail_sending_enabled'", 'reading mail_sending_enabled') || '0';
}

function setMailSending(value) {
  runSql(`UPDATE company_settings SET setting_value = '${value === '1' ? '1' : '0'}' WHERE setting_key = 'mail_sending_enabled'`, 'setting mail_sending_enabled');
}

/** Whether the newest email_log row to this recipient went out with a document attached. */
function lastEmailSentWithDocument(recipient) {
  const row = runSql(
    `SELECT CONCAT(status, '|', IFNULL(document_id, '')) FROM email_log WHERE recipient_email = '${recipient.replace(/'/g, "''")}' ORDER BY id DESC LIMIT 1`,
    'reading email_log'
  );
  const [status, documentId] = row.split('|');
  return status === 'sent' && documentId !== '';
}

module.exports = { resetClientPassword, provisionClientLogin, getMailSending, setMailSending, lastEmailSentWithDocument };
