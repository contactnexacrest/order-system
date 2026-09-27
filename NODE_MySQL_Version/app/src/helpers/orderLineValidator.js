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
  return null;
}

module.exports = { checkQuantity, checkUnitPrice };
