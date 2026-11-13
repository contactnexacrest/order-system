'use strict';

/**
 * HNT-L01 (owner-confirmed twice, 13 Nov 2026): every Node money figure is
 * rounded exactly as PHP round() rounds it. The old Math.round(x * 100) / 100
 * (and EPSILON / toFixed) forms rounded about 0.7 % of half-cent amounts the
 * other way from PHP, so the same order could show or send to Zoho a paisa /
 * cent different on the two stacks.
 *
 * 1. No money rounding by Math.round(x * 100) / 100 (or the 10000 / 1e6 /
 *    EPSILON / Number(x.toFixed(2)) forms) is left in src — the only ones
 *    left are listed below as non-money (days, pixels, CBM, percentages).
 * 2. PHP-vs-Node parity on half-cent values: milestones, vendor bill GST and
 *    totals, pricing conversion and purchase award value, Zoho payload and
 *    document amounts, large-amount round(), price per piece. The figures in
 *    tests/fixtures/hntl01_money_rounding_cases.json come from PHP itself;
 *    the PHP twin (MoneyRoundingParityHntL01Test.php) checks PHP still gives them.
 */
const fs = require('fs');
const path = require('path');
const money = require('../../src/helpers/money');
const moneyFormat = require('../../src/helpers/moneyFormat');
const vendorBillsView = require('../../src/helpers/vendorBillsView');
const lineTable = require('../../src/services/lineTable');
const lineUnits = require('../../src/services/lineUnits');
const purchasingService = require('../../src/services/purchasingService');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');

const SRC = path.join(__dirname, '..', '..', 'src');
const CASES = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'fixtures', 'hntl01_money_rounding_cases.json'), 'utf8'));

/** Rounding that is not money, kept as it is (file → a piece of the line, and why). */
const NOT_MONEY = [
  ['helpers/leadTimeDisplay.js', 'Math.round((days / 7) * 10) / 10', 'weeks'],
  ['controllers/ordersController.js', 'shortfallPct = Math.max(0, Math.round(', 'percentage short'],
  ['controllers/productsController.js', 'Math.round(fit.fits_cbm * 100) / 100', 'CBM'],
  ['controllers/incentiveController.js', 'share_pct: Math.round(Number(share) * 100) / 100', 'role share %'],
  ['controllers/incentiveController.js', 'share_pct: Math.round(Number(newShare) * 100) / 100', 'role share %'],
  ['controllers/incentiveController.js', 'share = Math.round(Number(raw) * 100) / 100', 'role share %'],
  ['controllers/incentiveController.js', 'Math.round(recordedTds / tdsCalc.gross * 10000) / 100', 'TDS rate %'],
  ['controllers/pricingController.js', 'Math.round(cha.fits_cbm * 100) / 100', 'CBM'],
  ['controllers/pricingController.js', 'Weight check:', 'CBM / tonnes'],
  ['services/amendmentService.js', 'Math.round((100 - advancePct - milestonePct) * 100) / 100', 'balance %'],
  ['services/amendmentService.js', 'Math.round((advancePct + milestones) * 100) / 100', 'percentages'],
  ['services/sampleDataService.js', 'const shortfallPct = Math.round(', 'percentage short'],
  ['services/orderLinePricing.js', 'Math.round(Number(thicknessCm) * 100) / 10', 'thickness mm'],
  ['services/orderLinePricing.js', 'out.cbm = Math.round(out.cbm * 100) / 100', 'CBM'],
  ['services/orderLinePricing.js', 'margin_pct: price > 0 ? Math.round(', 'margin %'],
  ['services/fileUploadService.js', 'Math.round((parseInt(context.max_size_bytes, 10) / 1048576) * 10) / 10', 'megabytes'],
  ['services/priceRequestService.js', 'Math.round((1000 / adj.thickness_mm) * 100) / 100', 'm² per CBM'],
  ['services/priceRequestService.js', 'Math.round((req / ord) * 10) / 10', 'requests per order'],
  ['services/lineUnits.js', 'Math.round((cbm / CONTAINER_CBM) * 1e6) / 1e6', 'containers'],
  ['repositories/reportRepository.js', 'Math.round((resolvedDaysSum / resolvedCount) * 10) / 10', 'days'],
  ['repositories/reportRepository.js', 'margin_after_pct', 'margin %'],
  ['server.js', "addFilter('factor4'", 'unit conversion factor'],
  ['services/remittanceDifference.js', 'const pct = (v) => String(Number(Number(v).toFixed(2)));', 'percentage text'],
];

/** Number(<expr>.toFixed(2)) — rounding by toFixed, wrapped back into a number. */
function toFixedNumber(code) {
  for (let i = code.indexOf('Number('); i >= 0; i = code.indexOf('Number(', i + 1)) {
    let depth = 0;
    for (let j = i + 6; j < code.length; j += 1) {
      if (code[j] === '(') depth += 1;
      if (code[j] === ')' && --depth === 0) {
        if (code.slice(i + 7, j).trimEnd().endsWith('.toFixed(2)')) return true;
        break;
      }
    }
  }
  return false;
}

function srcFiles(dir) {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? srcFiles(path.join(dir, e.name)) : e.name.endsWith('.js') ? [path.join(dir, e.name)] : []));
}

describe('HNT-L01: Node money rounding = PHP round()', () => {
  it('no money rounding by Math.round(x * 100) / 100, EPSILON or Number(x.toFixed(2)) is left in src', () => {
    const found = [];
    for (const file of srcFiles(SRC)) {
      const rel = path.relative(SRC, file).split(path.sep).join('/');
      fs.readFileSync(file, 'utf8').split('\n').forEach((line, i) => {
        const code = line.replace(/\/\/.*$/, '').replace(/^\s*\*.*$/, '');
        const scaled = /Math\.round\(.*\)\s*\/\s*(10|100|10000|1e2|1e4|1e6)\b(?!\d)/.test(code);
        const epsilon = /Number\.EPSILON/.test(code);
        if (!scaled && !epsilon && !toFixedNumber(code)) return;
        if (NOT_MONEY.some(([f, snippet]) => f === rel && line.includes(snippet))) return;
        found.push(`${rel}:${i + 1}: ${line.trim()}`);
      });
    }
    expect(found).toEqual([]);
  });

  it('every allow-listed non-money site still exists (the list stays honest)', () => {
    for (const [rel, snippet] of NOT_MONEY) {
      expect([rel, fs.readFileSync(path.join(SRC, rel), 'utf8').includes(snippet)]).toEqual([rel, true]);
    }
  });

  it('round2 / round4 / phpRound equal PHP round() on large half-cent amounts', () => {
    expect(CASES.round.length).toBeGreaterThan(100);
    for (const c of CASES.round) {
      expect([c.x, money.round2(c.x)]).toEqual([c.x, c.r2]);
      expect([c.x, money.round4(c.x)]).toEqual([c.x, c.r4]);
      expect([c.x, money.phpRound(c.x, 0)]).toEqual([c.x, c.r0]);
      expect([c.x, lineTable.roundTo(c.x, 2)]).toEqual([c.x, c.r2]);
    }
    expect(money.round2(8484053.915)).toBe(8484053.92);
  });

  it('payment milestones: FOB × % and the balance after them, as PHP', () => {
    for (const c of CASES.milestones) {
      const amount = money.round2(Number(c.fob) * Number(c.pct) / 100);
      expect([c.fob, c.pct, amount]).toEqual([c.fob, c.pct, c.amount]);
      expect([c.fob, orderPaymentStatusRepository.balanceDue(c.fob, 0, { advance_amount: null }, amount)]).toEqual([c.fob, c.balance_after]);
    }
  });

  it('vendor bills: GST and total, as PHP (VendorBillController)', () => {
    for (const c of CASES.vendor_bills) {
      const fig = vendorBillsView.prefillFigures([{ option_value: String(c.rate), is_default: 0 }], { taxable: c.taxable, gst_rate: c.rate });
      expect([c.taxable, c.rate, fig.gst, fig.total]).toEqual([c.taxable, c.rate, c.gst.toFixed(2), c.total.toFixed(2)]);
    }
    const src = fs.readFileSync(path.join(SRC, 'controllers/vendorBillController.js'), 'utf8');
    expect(src).toContain("gst_inr: require('../helpers/money').phpRound(round2(Number(taxable)) * Number(gstRate), 0) / 100");
  });

  it('pricing: conversion and the purchase award value, as PHP', () => {
    for (const c of CASES.pricing) expect([c.quote, c.factor, money.round2(c.quote * c.factor)]).toEqual([c.quote, c.factor, c.converted]);
    for (const c of CASES.awards) {
      expect([c.unit_price_inr, c.quantity, purchasingService.awardValue({ unit_price_inr: c.unit_price_inr, gst_percent: String(c.gst_percent) }, c.quantity)])
        .toEqual([c.unit_price_inr, c.quantity, c.value]);
    }
    for (const c of CASES.per_piece) expect([c.unit_price, c.per_piece, lineTable.pricePerPiece(c.unit_price, c.per_piece)]).toEqual([c.unit_price, c.per_piece, c.price]);
  });

  it('Zoho payload amounts and document amounts, as PHP number_format', () => {
    for (const c of CASES.zoho) {
      expect([c.x, money.fixed2(c.x)]).toEqual([c.x, c.payload]);
      expect([c.x, moneyFormat.amount(c.x, 'USD')]).toEqual([c.x, c.document_usd]);
      expect([c.x, moneyFormat.amount(c.x, 'INR')]).toEqual([c.x, c.document_inr]);
      expect([c.x, lineUnits.num(c.x)]).toEqual([c.x, c.payload.replace(/0+$/, '').replace(/\.$/, '')]);
    }
    // The Zoho Books payload helper sends money through fixed2 (PHP number_format($x, 2, '.', '')).
    expect(fs.readFileSync(path.join(SRC, 'services/zohoBooksService.js'), 'utf8')).toContain("const money = (n) => require('../helpers/money').fixed2(n);");
  });
});
