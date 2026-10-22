'use strict';

const crypto = require('crypto');
const env = require('../config/env');

/**
 * External QA report L-24: orders.buyer_action_token_plain used to store
 * the raw Buyer Action Link token next to its SHA-256 hash — needed
 * because staff re-view/re-copy the live link from the order page at any
 * time before it expires (ordersController.js), not just once at
 * generation — so "show it once and never store it" isn't viable without
 * changing that UX. Anyone with DB or backup read access could instead
 * use every live link (OC acknowledgement, Buyer PO upload, payment
 * report) directly from the raw column. Reversible AES-256-GCM encryption
 * under APP_ENCRYPTION_KEY (already provisioned in .env, previously
 * unused anywhere in the codebase) closes that exposure while keeping the
 * re-display feature: the column holds ciphertext, decrypted only when
 * actually rendering the link back to staff.
 */

const CIPHER = 'aes-256-gcm';
const IV_LENGTH = 12;
const TAG_LENGTH = 16;

/** sha256() accepts APP_ENCRYPTION_KEY at any length and derives a real 32-byte AES-256 key from it. */
function key() {
  const raw = env.get('APP_ENCRYPTION_KEY');
  if (!raw) {
    throw new Error('APP_ENCRYPTION_KEY is not configured in .env.');
  }
  return crypto.createHash('sha256').update(raw).digest();
}

/** @returns {string} base64(iv . authTag . ciphertext) — a single opaque column value. */
function encrypt(plaintext) {
  const iv = crypto.randomBytes(IV_LENGTH);
  const cipher = crypto.createCipheriv(CIPHER, key(), iv);
  const ciphertext = Buffer.concat([cipher.update(String(plaintext), 'utf8'), cipher.final()]);
  const tag = cipher.getAuthTag();
  return Buffer.concat([iv, tag, ciphertext]).toString('base64');
}

/** @returns {string|null} null if `encoded` is empty/not valid ciphertext (e.g. a pre-encryption plaintext row, or tampering) rather than throwing. */
function decrypt(encoded) {
  if (!encoded) return null;
  let raw;
  try {
    raw = Buffer.from(encoded, 'base64');
  } catch (e) {
    return null;
  }
  if (raw.length < IV_LENGTH + TAG_LENGTH) return null;
  const iv = raw.subarray(0, IV_LENGTH);
  const tag = raw.subarray(IV_LENGTH, IV_LENGTH + TAG_LENGTH);
  const ciphertext = raw.subarray(IV_LENGTH + TAG_LENGTH);
  try {
    const decipher = crypto.createDecipheriv(CIPHER, key(), iv);
    decipher.setAuthTag(tag);
    return Buffer.concat([decipher.update(ciphertext), decipher.final()]).toString('utf8');
  } catch (e) {
    return null;
  }
}

module.exports = { encrypt, decrypt };
