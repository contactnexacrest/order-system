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
// D-280: the old switch mail_sending_enabled became "Testing mode — hold e-mails to buyers
// and suppliers" (email_testing_mode, the opposite value). These helpers keep the old
// meaning for the specs: '1' = buyer e-mails go out (testing mode off).
function getMailSending() {
  const testing = runSql("SELECT setting_value FROM company_settings WHERE setting_key = 'email_testing_mode'", 'reading email_testing_mode') || '0';
  return testing === '1' ? '0' : '1';
}

function setMailSending(value) {
  runSql(`UPDATE company_settings SET setting_value = '${value === '1' ? '0' : '1'}' WHERE setting_key = 'email_testing_mode'`, 'setting email_testing_mode');
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

/**
 * Owner decision 1 Nov 2026 (associates): a verified bank account for the
 * associate with this e-mail, usable today — the two-person check itself is
 * covered by the PHPUnit / Jest suites; here it only lets the browser test
 * set the associate Active.
 */
function verifyAssociateBank(email) {
  const id = runSql(`SELECT id FROM associates WHERE email = '${email.replace(/'/g, "''")}'`, 'find associate');
  runSql(`INSERT INTO associate_bank_accounts (associate_id, status, holder_name, account_number_enc, account_last4, ifsc, bank_name, account_currency, verified_at, usable_from)
          VALUES (${Number(id)}, 'verified', 'E2E Associate', 'e2e-not-encrypted', '9012', 'HDFC0001234', 'HDFC Bank', 'INR', NOW(), CURDATE())`, 'verified bank');
  return Number(id);
}

/**
 * Owner decision D-245 P2: on a USD order a goods line is picked from Products. Makes sure
 * there is an active product with a supplier price "used for pricing" (₹80,000, quoted
 * today), so Pick from Products fills a quote price at or above the floor; returns its id.
 */
function ensurePricedProduct() {
  const name = 'E2E Priced Granite Block';
  let id = runSql(`SELECT id FROM catalog_products WHERE name = '${name}' LIMIT 1`, 'priced product lookup');
  if (!id) {
    runSql(`INSERT INTO catalog_products (name, specifications, hs_code, is_active) VALUES ('${name}', 'Granite block, polished', '68022310', 1)`, 'priced product insert');
    id = runSql(`SELECT id FROM catalog_products WHERE name = '${name}' LIMIT 1`, 'priced product lookup');
  }
  runSql(`UPDATE catalog_products SET is_active = 1 WHERE id = ${Number(id)}`, 'priced product activate');
  const supplier = runSql(`SELECT id FROM catalog_product_suppliers WHERE product_id = ${Number(id)} AND is_primary = 1 LIMIT 1`, 'priced product supplier lookup');
  if (!supplier) {
    runSql(`INSERT INTO catalog_product_suppliers (product_id, supplier_name, fob_source, fob_value, is_primary, quoted_on) VALUES (${Number(id)}, 'E2E Quarry', 'direct', 80000, 1, CURDATE())`, 'priced product supplier insert');
  } else {
    runSql(`UPDATE catalog_product_suppliers SET fob_source = 'direct', fob_value = 80000, quoted_on = CURDATE(), valid_until = NULL WHERE id = ${Number(supplier)}`, 'priced product supplier refresh');
  }
  return Number(id);
}

module.exports = { resetClientPassword, provisionClientLogin, getMailSending, setMailSending, lastEmailSentWithDocument, verifyAssociateBank, runSql, dbName, bcryptHash, ensurePricedProduct };
