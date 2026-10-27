'use strict';

const crypto = require('crypto');
const db = require('../config/db');
const encryptionService = require('../services/encryptionService');
const { formatForDb, parseDbDateTime } = require('../helpers/dbDateTime');

/**
 * Port of App\Repositories\VendorDetailsRepository. Owner request
 * (2026-10-27): the bank details we pay a supplier or a logistics partner
 * into, and the no-login Vendor Details Link through which they fill in
 * their own details. Shared by the Suppliers and the Logistics Partners
 * directories (kind 'supplier' or 'logistics_partner').
 *
 * The account number is stored encrypted (encryptionService). Every save
 * records who entered it (staff, or the vendor through the link) and
 * clears the "verified" mark unless staff verified it in the same save, so
 * changed bank details are always checked again before a payment.
 */
const KINDS = {
  supplier: { table: 'suppliers', name: 'supplier_legal_name', contact: 'contact_person', label: 'supplier' },
  logistics_partner: { table: 'logistics_partners', name: 'partner_name', contact: 'contact_person_name', label: 'logistics partner' },
};
const BANK_FIELDS = ['bank_account_name', 'bank_name', 'bank_branch', 'bank_ifsc', 'bank_account_type', 'bank_upi_id'];
/** The everyday details the vendor can correct on the link (besides the bank). */
const DETAIL_FIELDS = ['address', 'city', 'state', 'phone', 'whatsapp_number', 'email', 'contact_person_phone', 'contact_person_whatsapp', 'gstin', 'pan'];

function table(kind) {
  if (!KINDS[kind]) throw new Error(`Unknown vendor kind ${kind}`);
  return KINDS[kind].table;
}

async function find(kind, id) {
  return db.queryOne(`SELECT * FROM ${table(kind)} WHERE id = :id`, { id });
}

/** The bank fields with the account number decrypted, plus has_bank / verified status. */
function bankDetails(vendor) {
  const out = {};
  for (const f of BANK_FIELDS) out[f] = vendor && vendor[f] !== undefined ? vendor[f] : null;
  out.bank_account_number = encryptionService.decrypt((vendor && vendor.bank_account_number) || null);
  out.bank_updated_at = (vendor && vendor.bank_updated_at) || null;
  out.bank_updated_source = (vendor && vendor.bank_updated_source) || null;
  out.bank_verified_at = (vendor && vendor.bank_verified_at) || null;
  out.has_bank = !!out.bank_account_number;
  return out;
}

/** "••••1234" — what the vendor and the directory list see. */
function maskAccount(number) {
  return number ? `••••${String(number).slice(-4)}` : '—';
}

async function saveBankDetails(kind, id, bank, source, verifiedBy) {
  const set = [];
  const params = { id, source, verified_by: verifiedBy };
  for (const f of BANK_FIELDS) {
    set.push(`${f} = :${f}`);
    params[f] = bank[f] === undefined ? null : bank[f];
  }
  if (bank.bank_account_number) {
    set.push('bank_account_number = :bank_account_number');
    params.bank_account_number = encryptionService.encrypt(String(bank.bank_account_number));
  }
  set.push('bank_updated_at = NOW()', 'bank_updated_source = :source', 'bank_verified_by = :verified_by', `bank_verified_at = ${verifiedBy !== null ? 'NOW()' : 'NULL'}`);
  await db.execute(`UPDATE ${table(kind)} SET ${set.join(', ')} WHERE id = :id`, params);
}

async function markBankVerified(kind, id, userId) {
  await db.execute(`UPDATE ${table(kind)} SET bank_verified_at = NOW(), bank_verified_by = :by WHERE id = :id`, { by: userId, id });
}

/** The vendor's own corrections from the link — only the DETAIL_FIELDS (and the contact person's name). */
async function saveDetailsFromVendor(kind, id, details) {
  const set = [];
  const params = { id };
  for (const c of [...DETAIL_FIELDS, KINDS[kind].contact]) {
    if (Object.prototype.hasOwnProperty.call(details, c)) {
      set.push(`${c} = :${c}`);
      params[c] = details[c];
    }
  }
  if (set.length) {
    await db.execute(`UPDATE ${table(kind)} SET ${set.join(', ')} WHERE id = :id`, params);
  }
}

/**
 * The no-login Vendor Details Link — same hash / encrypted copy / expiry
 * convention as the Buyer Action Link. One use: it is cleared when the
 * vendor submits, so an old link can never change the bank account again.
 */
async function generateDetailsToken(kind, id, ttlDays = 30) {
  const raw = crypto.randomBytes(32).toString('hex');
  await db.execute(
    `UPDATE ${table(kind)} SET details_token_hash = :hash, details_token_plain = :plain, details_token_expires_at = :expires WHERE id = :id`,
    {
      hash: crypto.createHash('sha256').update(raw).digest('hex'),
      plain: encryptionService.encrypt(raw),
      expires: formatForDb(new Date(Date.now() + ttlDays * 86400000)),
      id,
    }
  );
  return raw;
}

/** The live link's token for staff to re-copy, or null when there is none. */
function liveDetailsToken(vendor) {
  if (!vendor || !vendor.details_token_plain || !vendor.details_token_expires_at
    || parseDbDateTime(vendor.details_token_expires_at).getTime() <= Date.now()) {
    return null;
  }
  return encryptionService.decrypt(vendor.details_token_plain);
}

/** @returns {Promise<[string, object]|null>} the kind and the vendor row for a live, unused token */
async function findByValidDetailsToken(raw) {
  const hash = crypto.createHash('sha256').update(String(raw || '')).digest('hex');
  for (const kind of Object.keys(KINDS)) {
    const row = await db.queryOne(`SELECT id FROM ${table(kind)} WHERE details_token_hash = :hash AND details_token_expires_at > NOW() AND is_active = 1`, { hash });
    if (row) return [kind, await find(kind, row.id)];
  }
  return null;
}

async function markDetailsSubmitted(kind, id) {
  await db.execute(
    `UPDATE ${table(kind)} SET details_submitted_at = NOW(), details_token_hash = NULL, details_token_plain = NULL, details_token_expires_at = NULL WHERE id = :id`,
    { id }
  );
}

module.exports = {
  KINDS, BANK_FIELDS, DETAIL_FIELDS, find, bankDetails, maskAccount, saveBankDetails, markBankVerified,
  saveDetailsFromVendor, generateDetailsToken, liveDetailsToken, findByValidDetailsToken, markDetailsSubmitted,
};
