'use strict';

const db = require('../config/db');

/**
 * Owner decision D-342 (12 Nov 2026, owner "confirm 1" and "confirm 2"): once the
 * order's first Quotation is approved, the same exchange rate and the same prices
 * hold for every other document — "not a single change, not even because of the
 * exchange rate". No change until it goes through an amendment.
 *
 *  - fixAtQtApproval(): when the first Quotation is approved the order keeps the
 *    pricing rate in use then (orders.pricing_fx_rate_at_qt; the PI keeps the same
 *    rate), and each line keeps the price the buyer accepted
 *    (order_products.qt_fixed_unit_price) and its price check figures
 *    (price_snapshot, at that rate).
 *  - refusal(): from then on no line is added, changed, copied or removed on the
 *    order page — not with "Override", not by the Super Admin. Only an activated
 *    "Products & prices" amendment changes lines (productsAmendmentService).
 *  - documentGuard(): the safety net — PI, OC, Buyer PO and CI are refused while a
 *    goods line is priced above the fixed price, or is not in the approved
 *    Quotation (e.g. data changed by a script).
 *
 * PHP twin: app/src/Services/PriceLock.php.
 */

const GUARDED_DOCUMENTS = { PI: 'the Proforma Invoice', OC: 'the Order Confirmation', BUYERPO: 'the Buyer PO', CI: 'the Commercial Invoice' };
const AMENDMENT_PATH = 'Order page › Amendments › Products & prices';
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const money = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function isFixed(order) {
  return !!(order && order.prices_fixed_at);
}

/** The pricing rate (₹ per USD) fixed at the Quotation's approval, or null. */
function fixedRate(order) {
  const v = order ? order.pricing_fx_rate_at_qt : null;
  return isFixed(order) && v !== null && v !== undefined && v !== '' && Number(v) > 0 ? Number(v) : null;
}

/** "12 Nov 2026" from a 'YYYY-MM-DD HH:MM:SS' value (the session's IST wall clock). */
function dayText(value) {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ''));
  return m ? `${Number(m[3])} ${MONTHS[Number(m[2]) - 1]} ${m[1]}` : '';
}

/** "12 Nov 2026" — the day the Quotation fixed the prices. */
function fixedDate(order) {
  return dayText(order && order.prices_fixed_at);
}

function message(order) {
  return `Prices and products are fixed by the approved Quotation of ${fixedDate(order)} — change them through an Amendment (${AMENDMENT_PATH}).`;
}

/** Why the order's lines cannot be changed on the order page, or null while they can. */
function refusal(order) {
  return order && isFixed(order) ? message(order) : null;
}

/**
 * The order's first Quotation is approved: fix the rate, each line's price and its
 * check figures. A second call does nothing. Returns true when this call fixed them.
 */
async function fixAtQtApproval(orderId, documentId) {
  const row = await db.queryOne('SELECT id, prices_fixed_at, pricing_fx_rate_at_pi FROM orders WHERE id = :id', { id: orderId });
  if (!row || row.prices_fixed_at !== null) return false;
  // A PI approved before the Quotation already fixed a rate: the Quotation keeps the same one.
  const rate = row.pricing_fx_rate_at_pi !== null && Number(row.pricing_fx_rate_at_pi) > 0
    ? Number(row.pricing_fx_rate_at_pi)
    : await require('./fxRateService').rate(await require('../repositories/pricingRepository').settings());
  const upd = await db.execute(
    `UPDATE orders SET prices_fixed_at = NOW(), prices_fixed_by_document_id = :d, pricing_fx_rate_at_qt = :fx
      WHERE id = :id AND prices_fixed_at IS NULL`,
    { d: documentId, fx: Number(rate).toFixed(4), id: orderId }
  );
  if (!upd.affectedRows) return false;
  await db.execute('UPDATE order_products SET qt_fixed_unit_price = unit_price WHERE order_id = :o AND is_active = 1 AND unit_price IS NOT NULL', { o: orderId });
  await require('./orderLinePricing').snapshotLines(orderId);
  await require('../repositories/auditLogRepository').log(null, 'PRICES_FIXED_AT_QT', 'orders', orderId, 'pricing_fx_rate_at_qt', null, Number(rate).toFixed(4),
    `Prices and products fixed by the approved Quotation (document #${documentId}) at ₹${money(rate)} per USD.`);
  return true;
}

/**
 * Why typeCode (PI, OC, BUYERPO, CI) cannot be generated: a goods line priced above
 * the price fixed by the approved Quotation (or by an activated amendment), or a goods
 * line that is not in it. Null when it can.
 */
async function documentGuard(orderId, typeCode) {
  const doc = GUARDED_DOCUMENTS[typeCode];
  if (!doc) return null;
  const order = await db.queryOne('SELECT prices_fixed_at, currency_code FROM orders WHERE id = :id', { id: orderId });
  if (!order || order.prices_fixed_at === null) return null;
  const ccy = String(order.currency_code || '');
  const lines = await db.query(
    `SELECT line_no, unit_price, qt_fixed_unit_price FROM order_products
      WHERE order_id = :o AND is_active = 1 AND line_type = 'goods' ORDER BY line_no`, { o: orderId });
  for (const l of lines) {
    const no = Number(l.line_no);
    if (l.qt_fixed_unit_price === null) {
      return `Line ${no} is not in the approved Quotation — ${doc} cannot be generated. Lines are added only through an Amendment (${AMENDMENT_PATH}).`;
    }
    if (l.unit_price !== null && Number(l.unit_price) > Number(l.qt_fixed_unit_price) + 0.004) {
      return `Line ${no}: ${ccy} ${money(l.unit_price)} is higher than ${ccy} ${money(l.qt_fixed_unit_price)}, the price the buyer accepted in the approved Quotation — ${doc} cannot be generated. Prices change only through an Amendment (${AMENDMENT_PATH}).`;
    }
  }
  return null;
}

module.exports = { GUARDED_DOCUMENTS, AMENDMENT_PATH, money, isFixed, fixedRate, dayText, fixedDate, message, refusal, fixAtQtApproval, documentGuard };
