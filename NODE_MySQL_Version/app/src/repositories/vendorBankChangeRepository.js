'use strict';

const crypto = require('crypto');
const db = require('../config/db');
const encryptionService = require('../services/encryptionService');

/**
 * Port of VendorBankChangeRepository.php. Owner decisions 11 Nov 2026, point 18
 * (D-294): a change of a supplier's or logistics partner's bank details waits
 * here — confirmed by the vendor through a link e-mailed to their registered
 * e-mail, verified by staff, then copied onto the supplier once its waiting
 * days are over. The account in use stays on the supplier row until then.
 */

async function create(kind, vendorId, bank, source, userId, email, tokenHash, confirmDays, last4) {
  // An earlier change still open is replaced by this one.
  await db.execute(`UPDATE vendor_bank_changes SET status = 'cancelled', cancelled_at = NOW(), cancelled_how = 'replaced', confirm_token_hash = NULL
                     WHERE vendor_kind = :k AND vendor_id = :v AND status IN ('awaiting_confirmation','pending','verified')`, { k: kind, v: vendorId });
  const r = await db.execute(
    `INSERT INTO vendor_bank_changes (vendor_kind, vendor_id, bank_account_name, bank_name, bank_branch, bank_account_number, account_last4, bank_ifsc, bank_account_type, bank_upi_id,
        entered_source, entered_by, sent_to_email, confirm_token_hash, confirm_by)
     VALUES (:k, :v, :holder, :bank, :branch, :num, :l4, :ifsc, :type, :upi, :src, :u, :email, :tok, DATE_ADD(NOW(), INTERVAL ${parseInt(confirmDays, 10) || 7} DAY))`,
    {
      k: kind, v: vendorId, holder: bank.bank_account_name ?? null, bank: bank.bank_name ?? null, branch: bank.bank_branch ?? null,
      num: bank.bank_account_number ? encryptionService.encrypt(String(bank.bank_account_number)) : null, l4: last4 ?? null,
      ifsc: bank.bank_ifsc ?? null, type: bank.bank_account_type ?? null, upi: bank.bank_upi_id ?? null,
      src: source, u: userId ?? null, email: email ?? null, tok: tokenHash,
    }
  );
  return r.insertId;
}

async function find(id) {
  return db.queryOne('SELECT * FROM vendor_bank_changes WHERE id = :id', { id });
}

/** The change a live confirmation link points to (still waiting, within its 7 days). */
async function findByToken(raw) {
  const hash = crypto.createHash('sha256').update(String(raw)).digest('hex');
  return db.queryOne("SELECT * FROM vendor_bank_changes WHERE confirm_token_hash = :h AND status = 'awaiting_confirmation' AND confirm_by > NOW()", { h: hash });
}

async function forVendor(kind, vendorId) {
  return db.query(
    `SELECT c.*, u1.name AS entered_by_name, u2.name AS first_checked_by_name, u3.name AS verified_by_name
       FROM vendor_bank_changes c LEFT JOIN users u1 ON u1.id = c.entered_by LEFT JOIN users u2 ON u2.id = c.first_checked_by LEFT JOIN users u3 ON u3.id = c.verified_by
      WHERE c.vendor_kind = :k AND c.vendor_id = :v ORDER BY c.id DESC`, { k: kind, v: vendorId });
}

function number(row) {
  return row.bank_account_number ? encryptionService.decrypt(String(row.bank_account_number)) : null;
}

async function newToken(id, hash, confirmDays, email) {
  await db.execute(`UPDATE vendor_bank_changes SET confirm_token_hash = :h, confirm_by = DATE_ADD(NOW(), INTERVAL ${parseInt(confirmDays, 10) || 7} DAY), sent_to_email = :e, reminded_at = NOW() WHERE id = :id`,
    { h: hash, e: email, id });
}

/** Status change guarded by the expected current status; true when it happened. 'NOW()' values become NOW(). */
async function move(id, from, to, set = {}) {
  const cols = ['status = :to'];
  const params = { to, id, from };
  for (const [col, val] of Object.entries(set)) {
    if (val === 'NOW()') {
      cols.push(`${col} = NOW()`);
    } else {
      cols.push(`${col} = :${col}`);
      params[col] = val;
    }
  }
  const r = await db.execute(`UPDATE vendor_bank_changes SET ${cols.join(', ')} WHERE id = :id AND status = :from`, params);
  return r.affectedRows === 1;
}

async function overdue() {
  return db.query("SELECT * FROM vendor_bank_changes WHERE status = 'awaiting_confirmation' AND confirm_by < NOW() ORDER BY id");
}

/** Verified changes whose waiting days are over. */
async function due(kind = null, vendorId = null) {
  if (kind !== null && vendorId !== null) {
    return db.query("SELECT * FROM vendor_bank_changes WHERE status = 'verified' AND usable_from <= CURDATE() AND vendor_kind = :k AND vendor_id = :v ORDER BY id", { k: kind, v: vendorId });
  }
  return db.query("SELECT * FROM vendor_bank_changes WHERE status = 'verified' AND usable_from <= CURDATE() ORDER BY id");
}

module.exports = { create, find, findByToken, forVendor, number, newToken, move, overdue, due };
