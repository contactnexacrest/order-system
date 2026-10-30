'use strict';

const { scalarString } = require('./input');

/**
 * QA Round 5, R5-28/R5-29: the server checks the same simple rules the
 * browser checks (app.js fieldError()), so a request that bypasses the
 * browser — an old cached app.js, JavaScript off, a scripted post — gets a
 * clear message instead of a database error (HTTP 500) or a stored bad
 * value. Every check returns null when the value is fine, else the message
 * to show. A blank value is fine here; "required" stays with each form.
 * Mirrors FieldValidator.php on the PHP stack word for word.
 */

function plain(number) {
  return String(Number(number.toFixed(2)));
}

function wholeNumber(value, label, min = 0) {
  if (value === '') return null;
  if (!/^-?\d+$/.test(value) || parseInt(value, 10) < min) {
    return `${label} must be a whole number of ${min} or more.`;
  }
  return null;
}

function decimal(value, label, min = 0, max = null) {
  if (value === '') return null;
  if (!/^-?\d*\.?\d+$/.test(value) || parseFloat(value) < min || (max !== null && parseFloat(value) > max)) {
    return max === null
      ? `${label} must be a number of ${plain(min)} or more (digits and a decimal point only).`
      : `${label} must be a number from ${plain(min)} to ${plain(max)}.`;
  }
  return null;
}

function email(value, label) {
  if (value === '') return null;
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
    return `${label} must be a valid e-mail address.`;
  }
  return null;
}

/** The number fields on the New Order and Edit Order forms — the first problem found, or null. */
function orderNumbers(body) {
  const value = (name) => scalarString(body[name]);
  const whole = [
    ['lead_time_min_days', 'Production lead time (min days)'],
    ['lead_time_max_days', 'Production lead time (max days)'],
    ['sailing_lead_time_min_days', 'Sailing time (min days)'],
    ['sailing_lead_time_max_days', 'Sailing time (max days)'],
  ];
  for (const [name, label] of whole) {
    const error = wholeNumber(value(name), label);
    if (error) return error;
  }
  const decimals = [
    ['estimated_total_cbm', 'Total CBM'],
    ['estimated_gross_weight_kg', 'Gross weight (kg)'],
    ['estimated_net_weight_kg', 'Net weight (kg)'],
    ['indicative_freight_amount', 'Indicative freight'],
    ['indicative_insurance_amount', 'Indicative insurance'],
  ];
  for (const [name, label] of decimals) {
    const error = decimal(value(name), label);
    if (error) return error;
  }
  return null;
}

/** Largest exchange rate the database keeps (DECIMAL(10,4)). */
const MAX_RATE = 999999.9999;
/** Largest money amount the database keeps (DECIMAL(14,2)). */
const MAX_AMOUNT = 999999999999.99;

const round = (n, d) => Math.round(n * 10 ** d) / 10 ** d;

/** QA Round 6, R6-N22/N23: at least 0.0001 once rounded to 4 decimals, at most MAX_RATE. */
function exchangeRate(rate, label = 'The exchange rate') {
  if (!Number.isFinite(rate) || round(rate, 4) <= 0) return `${label} must be at least 0.0001 (₹ per 1 unit of the order currency).`;
  if (rate > MAX_RATE) return `${label} is too large: it can be at most 999999.9999.`;
  return null;
}

/** QA Round 6, R6-N14/N15/N19/N21: at least 0.01 once rounded to 2 decimals, at most MAX_AMOUNT. */
function moneyAmount(amount, label) {
  if (!Number.isFinite(amount) || round(amount, 2) <= 0) return `${label} must be at least 0.01.`;
  if (amount > MAX_AMOUNT) return `${label} is too large: it can be at most 999999999999.99.`;
  return null;
}

module.exports = { MAX_RATE, MAX_AMOUNT, wholeNumber, decimal, email, orderNumbers, exchangeRate, moneyAmount };
