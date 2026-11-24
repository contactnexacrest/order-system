'use strict';

/**
 * Port of RupeeIndianGroupingD410Test.php. D-410 screens show rupees in Indian grouping (₹1,85,477.76,
 * ₹5,00,000) with the inr / inr0 filters, never "money" / "money0", which group the Western way (₹185,477.76).
 * Exchange rates "₹84.00 per USD" are exempt. Older screens (payout rows, TDS lines) are outside this check.
 */
const fs = require('fs');
const path = require('path');
const { indian } = require('../../src/helpers/moneyFormat');

const VIEWS = ['associates/_commission_rule.njk', 'associates/show.njk', 'orders/_associate_margin.njk', 'associate_portal/commission.njk', 'incentives/payouts.njk'];

describe('D-410 rupee amounts use Indian grouping', () => {
  it('has no Western-grouped rupee amount in the D-410 views', () => {
    const bad = [];
    for (const v of VIEWS) {
      fs.readFileSync(path.join(__dirname, '../../views', v), 'utf8').split('\n').forEach((line, i) => {
        if (line.includes('per USD')) return;
        // The D-410 amounts (same as the PHP twin): advice totals, the margin box, "Paid from our margin so far", the minimum.
        if (/₹\{\{\s*[\w.]*(total_paid|margin_inr|marginSoFar\.inr|minOrderInr)\s*\|\s*money0?\s*\}\}|'₹' if [^%]*total_paid\s*\|\s*money/.test(line)) bad.push(`${v}:${i + 1}`);
      });
    }
    expect(bad).toEqual([]);
    expect(indian(185477.76)).toBe('1,85,477.76');
  });
});
