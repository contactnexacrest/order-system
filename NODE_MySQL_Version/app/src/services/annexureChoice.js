'use strict';

const db = require('../config/db');
const orderAnnexureRepository = require('../repositories/orderAnnexureRepository');
const orderAnnexureTermsRepository = require('../repositories/orderAnnexureTermsRepository');
const annexureTermsSanitizer = require('../helpers/annexureTermsSanitizer');

/**
 * Owner decision D-336 (12 Nov 2026, owner confirmed twice): an order has two
 * annexures — Annexure A (as quoted: printed with the Quotation, Buyer PO, PI and
 * OC, exactly as before) and Annexure B (as shipped: the same screen and content,
 * printed only with the Commercial Invoice and Packing List). orders.ci_annexure
 * chooses which one the CI and PL carry (A / B / NONE); NULL means the default —
 * Annexure B when it is included and filled in, else Annexure A when it is
 * included and filled in, else none.
 *
 * An order line ticked "As per annexure" (order_products.as_per_annexure) prints
 * "Dimensions: As per Annexure X" and "<qty> (as per Annexure X)"; X is A on the
 * QT / Buyer PO / PI / OC and the chosen annexure on the CI / PL. No such document
 * is generated while that annexure has no product entry (blockReason()).
 *
 * Twin of PHP App\Services\AnnexureChoice — same rules, same wording.
 */

const QUOTED_DOCUMENTS = ['QT', 'BUYERPO', 'PI', 'OC'];
const SHIPPED_DOCUMENTS = ['CI', 'PL'];
const CHOICES = ['A', 'B', 'NONE'];

function mode(order, which) {
  const m = String(which === 'B' ? (order.annexure_b_mode || 'SPEC') : (order.annexure_mode || 'SPEC'));
  return ['SPEC', 'TERMS', 'BOTH'].includes(m) ? m : 'SPEC';
}

function included(order, which) {
  return !!Number(which === 'B' ? (order.include_annexure_b || 0) : (order.include_annexure_a || 0));
}

/** The annexure's Additional Terms, sanitized ('' when none). */
async function termsHtml(orderId, which) {
  const row = await orderAnnexureTermsRepository.find(orderId, which);
  return row && row.content_html ? annexureTermsSanitizer.sanitize(String(row.content_html)) : '';
}

/** Same rule as the documents' annexure_has_content: something to print in the chosen mode. */
async function hasContent(order, which) {
  const orderId = Number(order.id);
  const m = mode(order, which);
  const showSpec = m !== 'TERMS';
  const showTerms = m !== 'SPEC';
  return (showSpec && (await orderAnnexureRepository.countFor(orderId, which)) > 0)
    || (showTerms && (await termsHtml(orderId, which)) !== '');
}

/** The default for the Commercial Invoice / Packing List when nothing was chosen. */
async function ciDefault(order) {
  for (const which of ['B', 'A']) {
    if (included(order, which) && await hasContent(order, which)) return which;
  }
  return 'NONE';
}

/** What the CI / PL print: the saved choice, else the default. */
async function ciChoice(order) {
  const saved = String(order.ci_annexure || '');
  return CHOICES.includes(saved) ? saved : ciDefault(order);
}

/** The annexure a document prints: 'A' / 'B', or null for none (or a document without one). */
async function forDocument(order, documentTypeCode) {
  const code = String(documentTypeCode || '').toUpperCase();
  if (SHIPPED_DOCUMENTS.includes(code)) {
    const choice = await ciChoice(order);
    return choice === 'NONE' ? null : choice;
  }
  if (QUOTED_DOCUMENTS.includes(code) || code === 'ANNEXA') return 'A';
  return null;
}

/** The letter the "As per annexure" wording uses on that document (A when none is printed). */
async function letterFor(order, documentTypeCode) {
  return (await forDocument(order, documentTypeCode)) || 'A';
}

/** "8.801 (as per Annexure A)". */
function quantityText(formattedQuantity, letter) {
  return `${formattedQuantity} (as per Annexure ${letter})`;
}

/** "As per Annexure A". */
function dimensionsText(letter) {
  return `As per Annexure ${letter}`;
}

/** Line numbers of the order's lines ticked "As per annexure". */
async function annexureLineNumbers(orderId, goodsOnly = false) {
  const rows = await db.query(
    'SELECT line_no FROM order_products WHERE order_id = :o AND is_active = 1 AND as_per_annexure = 1'
    + (goodsOnly ? " AND COALESCE(line_type, 'goods') <> 'service'" : '')
    + ' ORDER BY line_no',
    { o: orderId }
  );
  return rows.map((r) => Number(r.line_no));
}

/**
 * Why the document cannot be generated yet (null = it can): a line says "As per
 * annexure" but the annexure that document prints has no product entry.
 */
async function blockReason(orderId, documentTypeCode, ciChoiceOverride = null) {
  const code = String(documentTypeCode || '').toUpperCase();
  const quoted = QUOTED_DOCUMENTS.includes(code);
  const shipped = SHIPPED_DOCUMENTS.includes(code);
  if (!quoted && !shipped) return null;
  const lines = await annexureLineNumbers(orderId, shipped);
  if (!lines.length) return null;
  const order = await require('../repositories/orderRepository').find(orderId);
  if (!order) return null;
  const line = lines[0];
  if (quoted) {
    if (included(order, 'A') && (await orderAnnexureRepository.countFor(orderId, 'A')) > 0) return null;
    return `Line ${line} says "As per annexure" but Annexure A has no product yet — add it in Annexure A (order page › Annexure A), then generate again.`;
  }
  const choice = ciChoiceOverride || await ciChoice(order);
  if (choice === 'NONE') {
    return `Line ${line} says "As per annexure" but "No annexure" is chosen for the Commercial Invoice and Packing List — choose Annexure B (as shipped) or Annexure A (as quoted), then generate again.`;
  }
  if ((await orderAnnexureRepository.countFor(orderId, choice)) > 0) return null;
  return choice === 'B'
    ? `Line ${line} says "As per annexure" but Annexure B (as shipped) has no product yet — add it, or choose Annexure A, then generate again.`
    : `Line ${line} says "As per annexure" but Annexure A (as quoted) has no product yet — add it, or choose Annexure B, then generate again.`;
}

/**
 * Owner decision D-338 (1a): a reorder request line's "As per annexure" tick box —
 * never together with the quantity-not-decided tick box (tbcLabel names it as the
 * form shows it), and the quantity stays required. Null when the line is fine.
 */
function reorderLineError(lineNo, perAnnexure, quantityIsTbc, quantity, tbcLabel) {
  if (!perAnnexure) return null;
  if (quantityIsTbc) return `Line ${lineNo}: tick either "${tbcLabel}" or "As per annexure", not both.`;
  if (String(quantity == null ? '' : quantity).trim() === '') return `Line ${lineNo}: a line "As per annexure" still needs its quantity — enter the Qty.`;
  return null;
}

/**
 * Owner decision D-338 (1b): a duplicated (or reordered) order gets a copy of the
 * source order's Annexure A — its product entries (pointing at the same image
 * files, exactly like "Copy everything from Annexure A"), its Additional Terms,
 * whether it is included and its content mode. Annexure B and the choice for the
 * Commercial Invoice are NOT copied (they describe what was shipped on the source
 * order). Returns the number of product entries copied.
 */
async function copyAnnexureAToOrder(sourceOrderId, newOrderId, userId) {
  const orderRepository = require('../repositories/orderRepository');
  const source = await orderRepository.find(sourceOrderId);
  if (!source) return 0;
  const count = await orderAnnexureRepository.copyToOrder(sourceOrderId, newOrderId, 'A');
  const termsA = await orderAnnexureTermsRepository.find(sourceOrderId, 'A');
  if (termsA && String(termsA.content_html || '').trim() !== '') {
    await orderAnnexureTermsRepository.upsert(newOrderId, String(termsA.content_html), userId, 'A');
  }
  await orderRepository.setAnnexureMode(newOrderId, mode(source, 'A'));
  await orderRepository.setIncludeAnnexureA(newOrderId, included(source, 'A'));
  return count;
}

/**
 * "Copy everything from Annexure A": Annexure B becomes a copy of A — product
 * entries (same image files), Additional Terms and content mode. Returns the
 * number of product entries copied.
 */
async function copyAToB(order, userId) {
  const orderId = Number(order.id);
  const count = await orderAnnexureRepository.replaceWithCopy(orderId, 'A', 'B');
  const termsA = await orderAnnexureTermsRepository.find(orderId, 'A');
  if (termsA || await orderAnnexureTermsRepository.find(orderId, 'B')) {
    await orderAnnexureTermsRepository.upsert(orderId, String((termsA && termsA.content_html) || ''), userId, 'B');
  }
  await require('../repositories/orderRepository').setAnnexureBMode(orderId, mode(order, 'A'));
  return count;
}

/** When that annexure was last saved (from the audit log), or null. */
async function lastSaved(orderId, which) {
  const ids = (await orderAnnexureRepository.forOrder(orderId, which)).map((p) => parseInt(p.id, 10));
  const row = await db.queryOne(
    "SELECT MAX(created_at) AS saved FROM audit_log WHERE reason = :r AND ((entity_type IN ('order','orders') AND entity_id = :o)"
    + (ids.length ? ` OR (entity_type = 'order_annexure_products' AND entity_id IN (${ids.join(',')}))` : '')
    + ')',
    { r: `Annexure ${which}`, o: orderId }
  );
  return row && row.saved ? row.saved : null;
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** "18 Jan" — like PHP date('j M'). */
function shortDate(value) {
  const d = value instanceof Date ? value : new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return null;
  return `${d.getDate()} ${MONTHS[d.getMonth()]}`;
}

/** The order page's Commercial Invoice step: the three choices, which is selected, which can be chosen. */
async function ciStep(order) {
  const orderId = Number(order.id);
  const savedB = await lastSaved(orderId, 'B');
  return {
    choice: await ciChoice(order),
    default: await ciDefault(order),
    a: { count: await orderAnnexureRepository.countFor(orderId, 'A'), has: await hasContent(order, 'A') },
    b: { count: await orderAnnexureRepository.countFor(orderId, 'B'), has: await hasContent(order, 'B'), saved: savedB ? shortDate(savedB) : null },
    lines: await annexureLineNumbers(orderId, true),
  };
}

module.exports = {
  QUOTED_DOCUMENTS, SHIPPED_DOCUMENTS, CHOICES,
  mode, included, termsHtml, hasContent, ciDefault, ciChoice, forDocument, letterFor,
  quantityText, dimensionsText, annexureLineNumbers, blockReason, copyAToB, lastSaved, ciStep,
  reorderLineError, copyAnnexureAToOrder,
};
