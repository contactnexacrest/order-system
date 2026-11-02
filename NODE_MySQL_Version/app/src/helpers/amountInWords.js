'use strict';

/**
 * Owner decision D-224 (D5, 2 Nov 2026, confirmed twice): the Commercial
 * Invoice prints its total in words instead of a blank line, e.g.
 * "US Dollars Twelve Thousand Five Hundred and Fifty Cents Only".
 * International numbering (thousand / million / billion). PHP twin:
 * App\Helpers\AmountInWords — both must give identical text.
 */

const CURRENCIES = {
  USD: ['US Dollars', 'Cents'],
  EUR: ['Euros', 'Cents'],
  GBP: ['Pounds Sterling', 'Pence'],
  AED: ['UAE Dirhams', 'Fils'],
  INR: ['Indian Rupees', 'Paise'],
};

const ONES = ['Zero', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
  'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
const SCALES = [[1000000000, 'Billion'], [1000000, 'Million'], [1000, 'Thousand']];

function words(n) {
  if (n < 20) return ONES[n];
  if (n < 100) return TENS[Math.floor(n / 10)] + (n % 10 ? `-${ONES[n % 10]}` : '');
  if (n < 1000) return `${ONES[Math.floor(n / 100)]} Hundred${n % 100 ? ` ${words(n % 100)}` : ''}`;
  for (const [size, name] of SCALES) {
    if (n >= size) return `${words(Math.floor(n / size))} ${name}${n % size ? ` ${words(n % size)}` : ''}`;
  }
  return String(n);
}

function format(amount, currencyCode) {
  const cents = Math.round(Math.abs(Number(amount) || 0) * 100);
  const whole = Math.floor(cents / 100);
  const minor = cents % 100;
  const code = String(currencyCode || '').trim().toUpperCase();
  const [major, minorName] = CURRENCIES[code] || [code !== '' ? code : 'Currency', 'Cents'];
  let text = `${major} ${words(whole)}`;
  if (minor > 0) text += ` and ${words(minor)} ${minorName}`;
  return `${text} Only`;
}

module.exports = { format, words };
