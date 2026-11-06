'use strict';

/**
 * Owner decision D-257 Phase 2 (5 Nov 2026): GST on a Domestic Trade (DOM)
 * sale. Pure maths, no database — the same figures as PHP GstCalculator.
 *
 *  - Same state (the seller's GST state = the place of supply): CGST + SGST,
 *    each half of the rate. Another state: IGST at the full rate.
 *  - Each line: taxable = quantity × rate, then its tax, each rounded to the
 *    paisa (half away from zero). Totals are the sums of the lines.
 *  - The invoice total is rounded to the rupee; the difference is "round off".
 *  - All money is worked in whole paise as BigInt, exactly; amounts come back
 *    as strings with two decimals ("1234.50").
 *
 * GST rates and the rules are standard Indian rules — to be confirmed by the CA.
 */

const GSTIN_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

/** "12.5" with 3 decimals => 12500n, exactly (no floating point). */
function scaled(value, decimals) {
  let v = String(value === null || value === undefined ? '' : value).trim();
  if (v === 'null' || v === 'undefined') v = ''; // String(null) from a still-empty column counts as 0, as PHP's cast does
  const negative = v.startsWith('-');
  v = v.replace(/^[+-]+/, '');
  const [whole, fracRaw = ''] = v.split('.', 2);
  const frac = (fracRaw + '0'.repeat(decimals + 1)).slice(0, decimals + 1);
  let n = BigInt(whole === '' ? '0' : whole) * 10n ** BigInt(decimals) + BigInt(frac) / 10n;
  if (BigInt(frac) % 10n >= 5n) n += 1n; // a further decimal place rounds half away from zero
  return negative ? -n : n;
}

/** a / b rounded half away from zero (b > 0), BigInt. */
function roundDiv(a, b) {
  const abs = a < 0n ? -a : a;
  const q = (abs * 2n + b) / (2n * b);
  return a < 0n ? -q : q;
}

/** Paise => "1234.50". */
function money(paise) {
  const p = BigInt(paise);
  const sign = p < 0n ? '-' : '';
  const abs = p < 0n ? -p : p;
  return `${sign}${abs / 100n}.${String(abs % 100n).padStart(2, '0')}`;
}

/** "1234.5" => "1,234.50" in the Indian grouping (12,34,567.00). */
function inr(amount) {
  const p = scaled(amount, 2);
  const sign = p < 0n ? '-' : '';
  const abs = p < 0n ? -p : p;
  let whole = String(abs / 100n);
  if (whole.length > 3) {
    const last3 = whole.slice(-3);
    const rest = whole.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',');
    whole = `${rest},${last3}`;
  }
  return `${sign}${whole}.${String(abs % 100n).padStart(2, '0')}`;
}

/**
 * @param {object[]} lines each: quantity, rate_inr, gst_rate_pct, hsn_code (+ anything else, kept)
 * @returns {object}
 */
function compute(lines, sellerStateCode, placeOfSupply) {
  const intra = String(sellerStateCode || '') !== '' && String(sellerStateCode) === String(placeOfSupply);
  const out = [];
  const hsn = new Map();
  const sum = { taxable: 0n, cgst: 0n, sgst: 0n, igst: 0n };
  for (const line of lines) {
    const taxable = roundDiv(scaled(line.quantity, 3) * scaled(line.rate_inr, 2), 1000n);
    const rateBp = scaled(line.gst_rate_pct, 2);
    let cgst = 0n;
    let sgst = 0n;
    let igst = 0n;
    if (intra) {
      cgst = roundDiv(taxable * rateBp, 20000n);
      sgst = cgst;
    } else {
      igst = roundDiv(taxable * rateBp, 10000n);
    }
    out.push({
      ...line,
      taxable: money(taxable),
      cgst: money(cgst),
      sgst: money(sgst),
      igst: money(igst),
      line_total: money(taxable + cgst + sgst + igst),
    });
    const key = `${line.hsn_code}|${money(rateBp)}`;
    if (!hsn.has(key)) hsn.set(key, { hsn_code: String(line.hsn_code), gst_rate_pct: money(rateBp), taxable: 0n, cgst: 0n, sgst: 0n, igst: 0n });
    const h = hsn.get(key);
    h.taxable += taxable;
    h.cgst += cgst;
    h.sgst += sgst;
    h.igst += igst;
    sum.taxable += taxable;
    sum.cgst += cgst;
    sum.sgst += sgst;
    sum.igst += igst;
  }
  const gross = sum.taxable + sum.cgst + sum.sgst + sum.igst;
  const rounded = roundDiv(gross, 100n) * 100n;
  return {
    intra_state: intra,
    lines: out,
    hsn_summary: [...hsn.values()].map((h) => ({
      hsn_code: h.hsn_code,
      gst_rate_pct: h.gst_rate_pct,
      taxable: money(h.taxable),
      cgst: money(h.cgst),
      sgst: money(h.sgst),
      igst: money(h.igst),
      tax_total: money(h.cgst + h.sgst + h.igst),
    })),
    taxable_total: money(sum.taxable),
    cgst_total: money(sum.cgst),
    sgst_total: money(sum.sgst),
    igst_total: money(sum.igst),
    tax_total: money(sum.cgst + sum.sgst + sum.igst),
    gross_total: money(gross),
    round_off: money(rounded - gross),
    invoice_total: money(rounded),
  };
}

/** The 15th character of a GSTIN, from its first 14 (the GSTN's base-36 check). */
function gstinCheckChar(first14) {
  let sum = 0;
  for (let i = 0; i < 14; i++) {
    const product = GSTIN_CHARS.indexOf(first14[i]) * (i % 2 === 0 ? 1 : 2);
    sum += Math.floor(product / 36) + (product % 36);
  }
  return GSTIN_CHARS[(36 - (sum % 36)) % 36];
}

/** Whether a GSTIN is well formed: state code, PAN, entity number, Z, and the check character. */
function isValidGstin(gstin) {
  const g = String(gstin || '').trim().toUpperCase();
  if (!/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/.test(g)) return false;
  return gstinCheckChar(g.slice(0, 14)) === g[14];
}

module.exports = { compute, isValidGstin, gstinCheckChar, scaled, roundDiv, money, inr };
