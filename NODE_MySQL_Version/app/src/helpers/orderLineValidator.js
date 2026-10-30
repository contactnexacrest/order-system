'use strict';

// Port of App\Helpers\OrderLineValidator.
//
// QA-5 ORD-05/ORD-06: order creation passed product_quantity/product_unit_price
// straight through as raw trimmed strings all the way to a DECIMAL column
// bind — a non-numeric value ("abc") threw an unhandled
// ER_TRUNCATED_WRONG_VALUE_FOR_FIELD deep inside orderProductRepository.add(),
// by which point the orders/order_stages/order_payment_status rows were
// already committed (no transaction wrapped the write path — see
// ordersController.store()), leaving a half-created order behind. A negative
// quantity was never rejected at all, silently producing a negative FOB
// value. Checked here, before anything is written, matching the existing
// HS-code-check-early pattern in ordersController.store() ("so a bad code
// never leaves a half-created order behind").

/** @returns {string|null} an error message if invalid, null if the value passes. A blank or TBC quantity is always valid — quantity is optional at creation. */
function checkQuantity(rawValue, isTbc) {
  const value = String(rawValue ?? '').trim();
  if (value === '' || isTbc) {
    return null;
  }
  if (!Number.isFinite(Number(value))) {
    return `quantity must be a number (got "${rawValue}")`;
  }
  if (Number(value) <= 0) {
    return `quantity must be greater than zero (got "${rawValue}")`;
  }
  return null;
}

/** @returns {string|null} an error message if invalid, null if the value passes. A blank unit price is always valid — price may be pending quotation. */
function checkUnitPrice(rawValue) {
  const value = String(rawValue ?? '').trim();
  if (value === '') {
    return null;
  }
  if (!Number.isFinite(Number(value))) {
    return `unit price must be a number (got "${rawValue}")`;
  }
  if (Number(value) < 0) {
    return `unit price must not be negative (got "${rawValue}")`;
  }
  // QA Round 6, R6-N14/N15: what the DECIMAL(14,2) column can hold — no
  // more than 2 decimals (0.0001 was stored as 0.00) and no more than
  // 999,999,999,999.99 (a larger price was an HTTP 500).
  if (decimals(value) > 2) {
    return `unit price can have at most 2 decimals (got "${rawValue}")`;
  }
  if (Number(value) > MAX_PRICE) {
    return `unit price can be at most ${fmt(MAX_PRICE, 2)} (got "${rawValue}")`;
  }
  return null;
}

/** QA Round 6, R6-N14: DECIMAL(14,2) for price and line value, DECIMAL(14,3) for quantity. */
const MAX_PRICE = 999999999999.99;
const MAX_QUANTITY = 99999999999.999;

function fmt(n, places) {
  return Number(n).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places });
}

function decimals(value) {
  const v = String(value).toLowerCase();
  if (v.includes('e')) return 99;
  const dot = v.indexOf('.');
  return dot === -1 ? 0 : v.slice(dot + 1).replace(/0+$/, '').length;
}

/**
 * QA Round 6, R6-N14/N15: one line's quantity, unit price and line value
 * (quantity x price) — every place a product line is saved (order create,
 * add line, edit line) uses this. Port of OrderLineValidator::checkLine().
 */
function checkLine(rawQuantity, isTbc, rawPrice) {
  const error = checkQuantity(rawQuantity, isTbc) || checkUnitPrice(rawPrice);
  if (error) return error;
  const quantity = String(rawQuantity ?? '').trim();
  const price = String(rawPrice ?? '').trim();
  if (quantity !== '' && !isTbc) {
    if (decimals(quantity) > 3) return `quantity can have at most 3 decimals (got "${rawQuantity}")`;
    if (Number(quantity) > MAX_QUANTITY) return `quantity can be at most ${fmt(MAX_QUANTITY, 3)} (got "${rawQuantity}")`;
    if (price !== '' && Number(quantity) * Number(price) > MAX_PRICE) return `the line value (quantity x unit price) can be at most ${fmt(MAX_PRICE, 2)}`;
  }
  return null;
}

module.exports = { checkQuantity, checkUnitPrice, checkLine, MAX_PRICE, MAX_QUANTITY };
