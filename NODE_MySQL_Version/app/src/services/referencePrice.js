'use strict';

/**
 * Port of ReferencePrice.php. Owner decision 2 Nov 2026 ("reference price yes with
 * approval", D-240): a Products-menu product may carry a reference SELLING price; an
 * order line picked from it remembers it (order_products.catalog_product_id) while its
 * own unit price stays freely editable and is what every document prints. This works out
 * the difference, the note staff see (only people who may see selling prices, never on a
 * document or in the client portal), and the MD/ED approval a discount at or beyond the
 * warning level needs before the Proforma Invoice can be generated.
 */

const db = require('../config/db');
const auditLogRepository = require('../repositories/auditLogRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');

const RULE = 'price_discount';

async function settings() {
  const enabled = await companySettingsRepository.get('ref_price_note_enabled');
  const note = await companySettingsRepository.get('ref_price_note_pct');
  const warning = await companySettingsRepository.get('ref_price_warning_pct');
  const num = (v, d) => (v !== null && v !== undefined && v !== '' && !Number.isNaN(Number(v)) ? Math.max(0, Number(v)) : d);
  return {
    enabled: enabled === null || enabled === undefined ? true : String(enabled) === '1',
    note_pct: num(note, 5),
    warning_pct: num(warning, 10),
  };
}

function money(v) {
  return Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** "USD 45.00 per SQFT" (unit optional). */
function label(product) {
  const text = `${String(product.reference_currency || '').trim()} ${money(product.reference_price || 0)}`.trim();
  const unit = String(product.reference_unit || '').trim();
  return text + (unit !== '' ? ` per ${unit}` : '');
}

function pct(value) {
  const t = (Math.round(value * 10) / 10).toFixed(1);
  return t.endsWith('.0') ? t.slice(0, -2) : t;
}

/** Difference of a line's price from its product's reference price (% of the reference; negative = below), or null. */
function lineDifference(line, product, orderCurrency) {
  if (!product || product.reference_price === null || product.reference_price === undefined || Number(product.reference_price) <= 0) return null;
  if (line.unit_price === null || line.unit_price === undefined || line.unit_price === '') return null;
  if (String(product.reference_currency || '').toUpperCase() !== String(orderCurrency || '').toUpperCase()) return null;
  const reference = Number(product.reference_price);
  const price = Number(line.unit_price);
  return { pct: Math.round(((price - reference) / reference) * 1000) / 10, reference, price, label: label(product) };
}

/** The note for one line, or null: { kind: 'note'|'warning'|'above', text }. */
function note(difference, s) {
  if (!difference || !s.enabled) return null;
  const p = difference.pct;
  if (p < 0) {
    const below = -p;
    if (below >= s.warning_pct) return { kind: 'warning', text: `⚠ ${pct(below)}% below reference price (${difference.label})` };
    if (below >= s.note_pct) return { kind: 'note', text: `${pct(below)}% below reference price (${difference.label})` };
    return null;
  }
  if (p > 0) return { kind: 'above', text: `${pct(p)}% above reference price (${difference.label})` };
  return null;
}

/** Catalog products by id, for the given lines. */
async function productsFor(lines) {
  const ids = [...new Set(lines.map((l) => Number(l.catalog_product_id || 0)).filter((id) => id > 0))];
  if (ids.length === 0) return {};
  const params = {};
  ids.forEach((id, i) => { params[`id${i}`] = id; });
  const rows = await db.query(`SELECT * FROM catalog_products WHERE id IN (${ids.map((_, i) => `:id${i}`).join(',')})`, params);
  const out = {};
  for (const r of rows) out[Number(r.id)] = r;
  return out;
}

async function linesNeedingApproval(orderId) {
  const order = await require('../repositories/orderRepository').find(orderId);
  if (!order) return [];
  const lines = await db.query('SELECT * FROM order_products WHERE order_id = :o AND is_active = 1 ORDER BY line_no', { o: orderId });
  const products = await productsFor(lines);
  const { warning_pct: warning } = await settings();
  const out = [];
  for (const line of lines) {
    const diff = lineDifference(line, products[Number(line.catalog_product_id || 0)] || null, order.currency_code);
    if (!diff || -diff.pct < warning) continue;
    out.push({
      line_id: Number(line.id), line_no: Number(line.line_no), description: String(line.description),
      unit_price: diff.price, reference_price: diff.reference, pct: diff.pct, label: diff.label,
      price_set_by: line.price_set_by !== null && line.price_set_by !== undefined ? Number(line.price_set_by) : null,
    });
  }
  return out;
}

async function currentApproval(orderId) {
  return (await db.queryOne('SELECT * FROM order_price_approvals WHERE order_id = :o AND lapsed_at IS NULL ORDER BY id DESC LIMIT 1', { o: orderId })) || null;
}

/** The lines needing approval that the approval does not cover (not in its snapshot, or now cheaper). */
function uncovered(approval, needing) {
  if (!approval) return needing;
  const snapshot = {};
  for (const s of JSON.parse(approval.snapshot || '[]')) snapshot[Number(s.line_id)] = Number(s.unit_price);
  return needing.filter((line) => snapshot[line.line_id] === undefined || line.unit_price < snapshot[line.line_id] - 0.0001);
}

/** Read-only state for the order page (nothing is changed here). */
async function status(orderId) {
  const needing = await linesNeedingApproval(orderId);
  const approval = await currentApproval(orderId);
  const unc = needing.length === 0 ? [] : uncovered(approval, needing);
  return { needing, approval, uncovered: unc, covered: unc.length === 0 };
}

/** Why the PI cannot be generated yet, or null. A current approval that no longer covers the lines lapses here (audited). */
async function piBlockReason(orderId, actorId = null) {
  const needing = await linesNeedingApproval(orderId);
  const approval = await currentApproval(orderId);
  if (needing.length === 0) return null;
  const unc = uncovered(approval, needing);
  if (unc.length === 0) return null;
  if (approval) {
    await db.execute('UPDATE order_price_approvals SET lapsed_at = NOW() WHERE id = :id AND lapsed_at IS NULL', { id: approval.id });
    await auditLogRepository.log(actorId ?? Number(approval.approved_by), 'PRICE_DISCOUNT_LAPSED', 'orders', orderId, null, null, 'A line price dropped further, or a new line is below the warning level, after the discount was approved.');
  }
  const first = unc[0];
  return `Line ${first.line_no} is ${pct(-first.pct)}% below its reference price — an MD/ED must approve the discount first (order page → Price check).`;
}

/** Approve the order's discount. Returns null on success or the refusal text. */
async function approve(orderId, userId, reason) {
  reason = String(reason || '').trim();
  if ([...reason].length < 10) return 'Give a reason of at least 10 characters for approving the discount.';
  if ([...reason].length > 500) return 'Keep the reason under 500 characters.';
  const needing = await linesNeedingApproval(orderId);
  if (needing.length === 0) return 'No line on this order is below its reference price by the warning level — nothing to approve.';
  const makers = needing.map((l) => l.price_set_by).filter((v) => v !== null);
  const isMaker = makers.includes(Number(userId));
  if (isMaker && !(await require('./makerCheckerGuard').selfApprovalAllowed(RULE, Number(userId)))) {
    return 'You set a discounted price on this order — a different MD/ED must approve the discount.';
  }
  await db.execute('UPDATE order_price_approvals SET lapsed_at = NOW() WHERE order_id = :o AND lapsed_at IS NULL', { o: orderId });
  const snapshot = needing.map((l) => ({ line_id: l.line_id, line_no: l.line_no, unit_price: l.unit_price, reference_price: l.reference_price, pct: l.pct }));
  await db.execute('INSERT INTO order_price_approvals (order_id, approved_by, reason, snapshot) VALUES (:o, :u, :r, :s)', { o: orderId, u: userId, r: reason, s: JSON.stringify(snapshot) });
  const summary = needing.map((l) => `line ${l.line_no} ${pct(-l.pct)}% below ${l.label}`).join('; ');
  await auditLogRepository.log(userId, 'PRICE_DISCOUNT_APPROVED', 'orders', orderId, null, null, `${summary} — reason: ${reason}`);
  if (isMaker) {
    await auditLogRepository.log(userId, 'PRICE_DISCOUNT_SELF_APPROVED', 'orders', orderId, null, null, 'Self-approval allowed by Admin → Self-approval rules (price_discount).');
  }
  return null;
}

/** "Pick from Products" list with the display fields the views need. */
async function pickerList() {
  const rows = await require('../repositories/productRepository').forPicker();
  return rows.map((p) => ({
    ...p,
    reference_label: p.reference_price !== null ? label(p) : null,
    reference_price_fixed: p.reference_price !== null ? Number(p.reference_price).toFixed(2) : null,
  }));
}

module.exports = { pickerList, RULE, settings, label, pct, lineDifference, note, productsFor, linesNeedingApproval, currentApproval, status, piBlockReason, approve };
