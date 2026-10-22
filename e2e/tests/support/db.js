'use strict';

const { spawnSync } = require('child_process');

// Dev-only credentials, same ones used throughout this suite's setup
// (see e2e/README.md) — not a secret worth protecting, just a local
// disposable dev database.
const DB_USER = 'nexacrest_app';
const DB_PASSWORD = 'DevPass123!';

function isNodeStack() {
  return (process.env.BASE_URL || '').includes(':3000');
}

function dbName() {
  return isNodeStack() ? 'nexacrest_node_dev' : 'nexacrest_php_dev';
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

module.exports = { resetClientPassword };
