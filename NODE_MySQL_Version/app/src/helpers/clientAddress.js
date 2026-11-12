'use strict';

/**
 * Owner decision D-346 (12 Nov 2026): the client's single "Billing Address"
 * box is gone. clients.billing_address (still read by documents, Zoho and the
 * domestic documents) is always composed from the structured parts, the same
 * way on every path (New / Edit / Duplicate Client, the Quotation and PI
 * intake forms): "Line 1, Line 2, City Postcode, Country" — city and postcode
 * joined by a space, everything else by ", ", empty parts skipped.
 * PHP twin: App\Helpers\ClientAddress.
 */
function compose(line1, line2, city, postcode, country) {
  const t = (v) => String(v === null || v === undefined ? '' : v).trim();
  const cityPostcode = [t(city), t(postcode)].filter((p) => p !== '').join(' ');
  return [t(line1), t(line2), cityPostcode, t(country)].filter((p) => p !== '').join(', ');
}

module.exports = { compose };
