'use strict';

const db = require('../config/db');
const reasonValidator = require('../helpers/reasonValidator');
const auditLogRepository = require('../repositories/auditLogRepository');
const moneyVisibility = require('./moneyVisibility');

/**
 * Owner decision D-381 (18 Nov 2026): CHA + transport per container, factory → port, per
 * supplier. The Edit Supplier form has a box "CHA + transport per container, factory → port (₹)"
 * and "Port this figure is for" (optional), saved with a reason when either changes (audit
 * SUPPLIER_CHA_CHANGED, old → new). A supplier-price row without its own figure uses the
 * supplier's (thicknessPrices.chaSource: row → supplier → product default → none).
 * Shown and saved only for those who see purchase prices or logistics costs.
 * Port of App\Services\SupplierCha (PHP twin: src/Services/SupplierCha.php).
 */

const MAX = 9999999999.99;

const blank = (v) => v === null || v === undefined || v === '';
const n2 = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const has = (o, k) => Object.prototype.hasOwnProperty.call(o || {}, k);

/** Whether the signed-in person (req.permissions) sees (and may change) a supplier's CHA + transport figure. */
function canSee(permissions) {
  return moneyVisibility.can(permissions, moneyVisibility.PURCHASE) || moneyVisibility.can(permissions, moneyVisibility.LOGISTICS);
}

/** "₹45,000.00 (Mundra)", "₹45,000.00", or "none". */
function text(amount, port) {
  if (amount === null || amount === undefined) return 'none';
  const p = !blank(port) && String(port).trim() !== '' ? String(port).trim() : null;
  return `₹${n2(amount)}${p !== null ? ` (${p})` : ''}`;
}

/**
 * Reads the box from the posted form and checks it (the same checks the browser does first).
 * Returns [null, null] when nothing changed, [changes, null] to save, or [null, error].
 */
function read(post, supplier) {
  if (!has(post, 'cha_transport_per_container') && !has(post, 'cha_port')) return [null, null];
  const last = (v) => (Array.isArray(v) ? v[v.length - 1] : v);
  const raw = String(last(post.cha_transport_per_container) ?? '').replace(/,/g, '').trim();
  let amount = null;
  if (raw !== '') {
    if (!/^\d{1,10}(\.\d{1,2})?$/.test(raw) || Number(raw) > MAX) {
      return [null, 'CHA + transport per container: enter ₹ (at most 2 decimals), or leave it empty.'];
    }
    amount = Number(raw);
  }
  let port = String(last(post.cha_port) ?? '').trim();
  if ([...port].length > 100) return [null, 'Port this figure is for: at most 100 characters.'];
  port = port !== '' ? port : null;
  if (port !== null && amount === null) {
    return [null, 'Port this figure is for: enter the CHA + transport figure too, or leave both empty.'];
  }
  const oldAmount = !blank(supplier.cha_transport_per_container) ? Number(supplier.cha_transport_per_container) : null;
  const oldPort = !blank(supplier.cha_port) && String(supplier.cha_port).trim() !== '' ? String(supplier.cha_port).trim() : null;
  const same = (oldAmount === null ? amount === null : amount !== null && Math.abs(oldAmount - amount) < 0.005) && oldPort === port;
  if (same) return [null, null];
  const reason = String(last(post.cha_reason) ?? '').trim();
  const error = reasonValidator.check(reason);
  if (error !== null) return [null, `CHA + transport — reason for the change: ${error}`];
  return [{ amount, port, reason }, null];
}

/** Saves the figure and port and writes the audit row (old → new, with the reason). */
async function save(supplierId, supplier, change, userId) {
  await db.execute('UPDATE suppliers SET cha_transport_per_container = :a, cha_port = :p WHERE id = :id',
    { a: change.amount !== null ? Number(change.amount).toFixed(2) : null, p: change.port, id: supplierId });
  const old = text(!blank(supplier.cha_transport_per_container) ? Number(supplier.cha_transport_per_container) : null, supplier.cha_port ?? null);
  const now = text(change.amount, change.port);
  await auditLogRepository.log(userId, 'SUPPLIER_CHA_CHANGED', 'suppliers', supplierId, 'cha_transport_per_container', old, now, change.reason);
}

module.exports = { MAX, canSee, text, read, save };
