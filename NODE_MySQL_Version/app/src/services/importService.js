'use strict';

const db = require('../config/db');
const financialYear = require('../helpers/financialYear');
const { todayIst } = require('../helpers/dbDateTime');
const auditLogRepository = require('../repositories/auditLogRepository');
const domesticRepo = require('../repositories/domesticOrderRepository');
const repo = require('../repositories/importRepository');
const G = require('./gstCalculator');

const divisionService = () => require('./divisionService');

/**
 * Owner decision D-257 Phase 3 (5 Nov 2026): the Import & Distribution (IMP)
 * division — import goods into India, hold them in stock, sell them locally.
 *
 * An import (a "shipment") runs through its own stages:
 *
 *   ordered → shipped → cleared → received → closed
 *
 *  - ordered:  the import PO to the foreign supplier, in its currency; the
 *              LC where NexaCrest is the applicant; payments abroad.
 *  - shipped:  the inbound BL is recorded.
 *  - cleared:  the bill of entry, the customs exchange rate, the CHA and the
 *              charges in INR. Moving on to "cleared" works out the duty and
 *              the landed cost per item and freezes them.
 *  - received: moving on puts the goods into stock at their landed cost.
 *  - closed:   the supplier is paid in full and the IGST input credit taken.
 *
 * Landed cost per item = purchase × exchange rate + freight + insurance (both
 * spread by value; together they make the assessable value) + basic customs
 * duty + social welfare surcharge + CHA, port and other charges (spread by
 * value). IGST paid on import is input credit, so it is not a cost. Duty
 * rates per HSN are editable starting values, to be confirmed by the CA and
 * the customs agent.
 *
 * Stock is valued at weighted average cost and never goes below zero: an
 * issue locks the balance row first. The local sale is a Domestic Trade
 * order in the IMP division (GST tax invoice, challan, e-way bill, payments
 * under IMP's own numbers); its goods are issued from stock when its tax
 * invoice is fixed. Port of PHP App\Services\ImportService.
 */

const CODE = 'IMP';

const STAGES = { ordered: 'Ordered', shipped: 'Shipped', cleared: 'Cleared', received: 'In stock', closed: 'Closed' };
const STAGE_KEYS = Object.keys(STAGES);

const PAYMENT_KINDS = { advance: 'Supplier advance', balance: 'Supplier balance', lc: 'LC retirement' };

/** Starting values of IMP's own settings, used when a setting is missing (to be confirmed by the CA / customs agent). */
const DEFAULTS = {
  imp_default_bcd_pct: '10',
  imp_default_sws_pct: '10',
  imp_default_igst_pct: '18',
  imp_margin_pct: '20',
  imp_lc_bank: '',
  imp_default_cha: '',
};

const CURRENCIES = ['USD', 'EUR', 'GBP', 'CNY', 'JPY', 'AED', 'SGD', 'CHF', 'AUD', 'CAD'];
const INCOTERMS = ['EXW', 'FCA', 'FOB', 'CFR', 'CIF', 'CPT', 'CIP', 'DAP', 'DPU', 'DDP'];

const len = (s) => [...String(s)].length;
const str = (v) => String(v === undefined || v === null ? '' : v).trim();
const isDate = (s) => /^\d{4}-\d{2}-\d{2}$/.test(s) && !Number.isNaN(Date.parse(s));

async function divisionId() {
  const d = (await divisionService().all()).find((x) => x.code === CODE);
  return d ? Number(d.id) : null;
}

async function isActive() {
  const id = await divisionId();
  const d = id !== null ? await divisionService().find(id) : null;
  return !!d && d.status === 'active';
}

async function setting(key) {
  const id = await divisionId();
  const v = id !== null ? await domesticRepo.divisionSetting(id, key) : null;
  return v !== null && v !== '' ? v : (DEFAULTS[key] || '');
}

// ------------------------------------------------------------ maths (pure)

/** A float rounded half away from zero to a whole number (the same in PHP and Node). */
function roundHalfAway(x) {
  return x >= 0 ? Math.floor(x + 0.5) : -Math.floor(-x + 0.5);
}

/**
 * Spreads a whole number of paise over weights, in proportion; the last
 * share takes what rounding leaves, so the shares always add up exactly.
 * @param {bigint} total @param {bigint[]} weights @returns {bigint[]}
 */
function spread(total, weights) {
  const t = BigInt(total);
  const w = weights.map((x) => BigInt(x));
  const sum = w.reduce((a, b) => a + b, 0n);
  const out = [];
  let given = 0n;
  w.forEach((x, i) => {
    if (i === w.length - 1) {
      out.push(t - given);
      return;
    }
    const share = sum > 0n ? BigInt(roundHalfAway(Number(t) * Number(x) / Number(sum))) : 0n;
    out.push(share);
    given += share;
  });
  return out;
}

/** Scale-4 integer => "12.3456". */
function fmt4(v) {
  const n = BigInt(v);
  const sign = n < 0n ? '-' : '';
  const a = n < 0n ? -n : n;
  return `${sign}${a / 10000n}.${String(a % 10000n).padStart(4, '0')}`;
}

/** Scale-3 integer => "12.500". */
function fmt3(v) {
  const n = BigInt(v);
  const sign = n < 0n ? '-' : '';
  const a = n < 0n ? -n : n;
  return `${sign}${a / 1000n}.${String(a % 1000n).padStart(3, '0')}`;
}

function trim3(v) {
  return fmt3(v).replace(/\.?0+$/, '');
}

/** Cents => "12,345.67" (western grouping, for foreign currency). */
function fc(cents) {
  const n = BigInt(cents);
  const sign = n < 0n ? '-' : '';
  const a = n < 0n ? -n : n;
  return `${sign}${String(a / 100n).replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${String(a % 100n).padStart(2, '0')}`;
}

/** A line's value in the supplier's currency, in cents (quantity × unit price, rounded to the cent). */
function lineValueCents(quantity, unitPrice) {
  return G.roundDiv(G.scaled(quantity, 3) * G.scaled(unitPrice, 2), 1000n);
}

/** Duty and landed cost of an import (pure: no database). */
function compute(lines, fxRate, costs) {
  const fx = G.scaled(fxRate, 4);
  const cents = lines.map((l) => lineValueCents(String(l.quantity), String(l.unit_price_fc)));
  const values = cents.map((c) => G.roundDiv(c * fx, 10000n));
  const p = (k) => G.scaled(String(costs[k] === undefined || costs[k] === null ? '0' : costs[k]), 2);
  const freight = spread(p('freight_inr'), values);
  const insurance = spread(p('insurance_inr'), values);
  const charges = spread(p('cha_inr') + p('port_inr') + p('other_inr'), values);
  const t = { value_fc: 0n, value: 0n, assessable: 0n, bcd: 0n, sws: 0n, igst: 0n, charges: 0n, landed: 0n };
  const out = lines.map((l, i) => {
    const assessable = values[i] + freight[i] + insurance[i];
    const bcd = G.roundDiv(assessable * G.scaled(String(l.bcd_pct), 2), 10000n);
    const sws = G.roundDiv(bcd * G.scaled(String(l.sws_pct), 2), 10000n);
    const igst = G.roundDiv((assessable + bcd + sws) * G.scaled(String(l.igst_pct), 2), 10000n);
    const landed = assessable + bcd + sws + charges[i];
    const qty = G.scaled(String(l.quantity), 3);
    t.value_fc += cents[i];
    t.value += values[i];
    t.assessable += assessable;
    t.bcd += bcd;
    t.sws += sws;
    t.igst += igst;
    t.charges += charges[i];
    t.landed += landed;
    return {
      ...l,
      value_fc: G.money(cents[i]),
      value_inr: G.money(values[i]),
      freight_share: G.money(freight[i]),
      insurance_share: G.money(insurance[i]),
      assessable_inr: G.money(assessable),
      bcd_inr: G.money(bcd),
      sws_inr: G.money(sws),
      igst_inr: G.money(igst),
      charges_share: G.money(charges[i]),
      landed_inr: G.money(landed),
      landed_unit_inr: fmt4(qty > 0n ? G.roundDiv(landed * 100000n, qty) : 0n),
    };
  });
  return {
    lines: out,
    value_total_fc: G.money(t.value_fc),
    value_total_inr: G.money(t.value),
    freight_inr: G.money(p('freight_inr')),
    insurance_inr: G.money(p('insurance_inr')),
    assessable_total: G.money(t.assessable),
    bcd_total: G.money(t.bcd),
    sws_total: G.money(t.sws),
    duty_total: G.money(t.bcd + t.sws),
    igst_total: G.money(t.igst),
    charges_total: G.money(t.charges),
    landed_total: G.money(t.landed),
  };
}

/** The suggested selling price per unit (before GST): landed cost per unit ÷ (1 − margin). */
function suggestedPrice(landedUnit, marginPct) {
  const denominator = 10000n - G.scaled(String(marginPct), 2);
  if (denominator <= 0n) return '0.00';
  return G.money(G.roundDiv(G.scaled(String(landedUnit), 4) * 10000n, denominator * 100n));
}

const pct = (v) => G.money(G.scaled(String(v), 2));

/** The duty rates for an HSN: the longest matching HSN start, else IMP's defaults. */
async function dutyFor(hsn) {
  let best = null;
  let bestLen = -1;
  for (const r of await repo.dutyRates(true)) {
    const prefix = String(r.hsn_prefix || '');
    if (prefix !== '' && hsn.startsWith(prefix) && prefix.length > bestLen) {
      best = r;
      bestLen = prefix.length;
    }
  }
  return best
    ? { bcd: pct(best.bcd_pct), sws: pct(best.sws_pct), igst: pct(best.igst_pct) }
    : { bcd: pct(await setting('imp_default_bcd_pct')), sws: pct(await setting('imp_default_sws_pct')), igst: pct(await setting('imp_default_igst_pct')) };
}

// ------------------------------------------------------------ helpers

async function nextReference(series, label) {
  const fy = financialYear.label(todayIst());
  const short = `${fy.slice(2, 4)}-${fy.slice(5, 7)}`;
  return `IMP/${label}/${short}/${String(await domesticRepo.nextNumber(series, fy)).padStart(4, '0')}`;
}

/** The PO value in the supplier's currency, in cents. */
async function poValueCents(shipmentId, executor = undefined) {
  let c = 0n;
  for (const l of await repo.lines(shipmentId, executor)) c += lineValueCents(String(l.quantity), String(l.unit_price_fc));
  return c;
}

async function paidCents(shipmentId, executor = undefined) {
  let c = 0n;
  for (const p of await repo.payments(shipmentId, executor)) c += G.scaled(String(p.amount_fc), 2);
  return c;
}

/** The current figures of an import: frozen once cleared, else worked out from its lines now. */
async function figures(s, executor) {
  const lines = await repo.lines(Number(s.id), executor);
  const fx = s.customs_fx_rate !== null && s.customs_fx_rate !== undefined && String(s.customs_fx_rate) !== '' ? String(s.customs_fx_rate) : '0';
  return compute(lines, fx, {
    freight_inr: s.freight_inr, insurance_inr: s.insurance_inr, cha_inr: s.cha_inr, port_inr: s.port_inr, other_inr: s.other_inr,
  });
}

// ------------------------------------------------------------ create and edit

function headerFields(input) {
  const name = str(input.supplier_name);
  const country = str(input.supplier_country);
  const address = str(input.supplier_address);
  const currency = str(input.currency_code).toUpperCase();
  const incoterm = str(input.incoterm).toUpperCase();
  const date = str(input.po_date);
  const terms = str(input.payment_terms);
  const fields = {
    supplier_name: name, supplier_country: country, supplier_address: address !== '' ? address : null,
    currency_code: currency, incoterm: incoterm !== '' ? incoterm : null, po_date: date, payment_terms: terms !== '' ? terms : null,
  };
  let error = null;
  if (name === '' || len(name) > 255) error = 'Enter the foreign supplier.';
  else if (country === '' || len(country) > 80) error = "Enter the supplier's country.";
  else if (country.toUpperCase() === 'INDIA') error = 'An import comes from a supplier abroad; buy from an Indian supplier as a Domestic Trade purchase.';
  else if (len(address) > 1000) error = 'The supplier address is too long.';
  else if (!CURRENCIES.includes(currency)) error = "Choose the currency of the supplier's invoice.";
  else if (incoterm !== '' && !INCOTERMS.includes(incoterm)) error = 'Choose a valid Incoterm.';
  else if (!isDate(date) || date > todayIst()) error = 'Enter the PO date (not in the future).';
  else if (len(terms) > 255) error = 'The payment terms are too long.';
  return [fields, error];
}

/** Creates an import (its PO number is taken now). @returns {Promise<[number|null, string|null]>} */
async function create(input, userId) {
  const divId = await divisionId();
  if (divId === null || !(await isActive())) return [null, 'Import & Distribution is not active yet. The MD activates it in Admin › Divisions.'];
  const [fields, error] = headerFields(input);
  if (error !== null) return [null, error];
  fields.division_id = divId;
  fields.reference = await nextReference('IMP-PO', 'PO');
  const id = await repo.insert(fields, userId);
  await auditLogRepository.log(userId, 'IMPORT_CREATED', 'import_shipments', id, 'reference', null, fields.reference);
  return [id, null];
}

async function saveHeader(id, input, userId) {
  const s = await repo.find(id);
  if (!s) return 'Import not found.';
  if (s.stage !== 'ordered') return 'The PO can change only while the goods are not shipped.';
  const [fields, error] = headerFields(input);
  if (error !== null) return error;
  // QA batch 2 (ORD-08): payments are recorded in the PO's currency — it cannot change under them.
  if (fields.currency_code !== String(s.currency_code) && (await repo.payments(id)).length > 0) {
    return `The supplier has already been paid in ${s.currency_code}, so the PO currency can no longer change.`;
  }
  // A PO generated before this change is out of date: generate it again before the goods ship.
  const asText = (v) => (v === null || v === undefined ? '' : (v instanceof Date ? v.toISOString().slice(0, 10) : String(v)));
  const changed = Object.keys(fields).some((col) => asText(s[col]) !== asText(fields[col]));
  await repo.update(id, changed ? { ...fields, ipo_generated_at: null } : fields, userId);
  await auditLogRepository.log(userId, 'IMPORT_PO_SAVED', 'import_shipments', id, 'supplier_name', s.supplier_name, fields.supplier_name);
  return null;
}

async function addLine(id, input, userId) {
  const s = await repo.find(id);
  if (!s) return 'Import not found.';
  if (s.stage !== 'ordered') return 'The lines can change only while the goods are not shipped.';
  const product = await repo.product(input.product_id || 0);
  const description = str(input.description);
  let hsn = String(input.hsn_code || '').replace(/\D/g, '');
  if (hsn === '' && product) hsn = String(product.hs_code || '').replace(/\D/g, '');
  const qty = str(input.quantity);
  const unit = str(input.unit);
  const price = str(input.unit_price_fc);
  const p = { bcd_pct: str(input.bcd_pct), sws_pct: str(input.sws_pct), igst_pct: str(input.igst_pct) };
  const badPct = (v) => v !== '' && (!/^\d{1,3}(\.\d{1,2})?$/.test(v) || G.scaled(v, 2) > 15000n);
  let error = null;
  if (!product || Number(product.is_active) !== 1) error = 'Choose the product (stock is kept per product).';
  else if (len(description) > 500) error = 'The description is too long (up to 500 characters).';
  else if (hsn.length < 4 || hsn.length > 8) error = 'Enter the HSN code (4 to 8 digits).';
  else if (!/^\d{1,7}(\.\d{1,3})?$/.test(qty) || G.scaled(qty, 3) <= 0n) error = 'Enter a quantity above zero (up to 3 decimals).';
  else if (unit === '' || len(unit) > 20) error = 'Enter the unit (e.g. SQM, PCS).';
  else if (!/^\d{1,9}(\.\d{1,2})?$/.test(price) || G.scaled(price, 2) <= 0n) error = "Enter the unit price in the supplier's currency, above zero (up to 2 decimals).";
  else if (lineValueCents(qty, price) > 99999999999n) error = 'The line value is too large; split it into several lines.';
  else if (badPct(p.bcd_pct) || badPct(p.sws_pct) || badPct(p.igst_pct)) error = 'A duty rate is a percentage, 0 to 150, with up to 2 decimals.';
  if (error !== null) return error;
  const duty = await dutyFor(hsn);
  const pick = (v, fallback) => (v !== '' ? pct(v) : fallback);
  await repo.addLine(id, {
    product_id: Number(product.id),
    description: description !== '' ? description : String(product.name),
    hsn_code: hsn,
    quantity: qty,
    unit: unit.toUpperCase(),
    unit_price_fc: price,
    bcd_pct: pick(p.bcd_pct, duty.bcd),
    sws_pct: pick(p.sws_pct, duty.sws),
    igst_pct: pick(p.igst_pct, duty.igst),
  }, userId);
  await repo.update(id, { ipo_generated_at: null }, userId); // QA batch 2 (ORD-08): the PO changed
  await auditLogRepository.log(userId, 'IMPORT_LINE_ADDED', 'import_shipments', id, 'line', null, `${product.product_code} × ${qty}`);
  return null;
}

async function removeLine(id, lineId, userId) {
  const s = await repo.find(id);
  if (!s || s.stage !== 'ordered') return 'The lines can change only while the goods are not shipped.';
  // QA batch 2 (ORD-08): the PO value never falls below what the supplier has already been paid.
  const line = (await repo.lines(id)).find((l) => Number(l.id) === Number(lineId));
  if (!line) return 'Line not found.';
  const after = (await poValueCents(id)) - lineValueCents(String(line.quantity), String(line.unit_price_fc));
  const paid = await paidCents(id);
  if (after < paid) return `Removing this line would take the PO value (${s.currency_code} ${fc(after)}) below what the supplier has already been paid (${s.currency_code} ${fc(paid)}).`;
  if (!(await repo.removeLine(id, lineId))) return 'Line not found.';
  await repo.update(id, { ipo_generated_at: null }, userId);
  await auditLogRepository.log(userId, 'IMPORT_LINE_REMOVED', 'import_shipments', id, 'line', String(lineId), null);
  return null;
}

/** The LC opened by NexaCrest (applicant) for this import — a record of it. */
async function saveLc(id, input, userId) {
  const s = await repo.find(id);
  const no = str(input.lc_no);
  const bank = str(input.lc_bank);
  const amount = str(input.lc_amount_fc);
  const expiry = str(input.lc_expiry);
  const margin = str(input.lc_margin_inr);
  let error = null;
  if (!s) error = 'Import not found.';
  else if (s.stage === 'closed') error = 'The import is closed.';
  else if (no === '' || len(no) > 40) error = 'Enter the LC number.';
  else if (bank === '' || len(bank) > 150) error = 'Enter the bank that opened the LC.';
  else if (!/^\d{1,11}(\.\d{1,2})?$/.test(amount) || G.scaled(amount, 2) <= 0n) error = `Enter the LC amount in ${s.currency_code}.`;
  else if (!isDate(expiry)) error = 'Enter the LC expiry date.';
  else if (margin !== '' && !/^\d{1,11}(\.\d{1,2})?$/.test(margin)) error = 'The margin money is an amount in ₹.';
  if (error !== null) return error;
  await repo.update(id, { lc_no: no, lc_bank: bank, lc_amount_fc: amount, lc_expiry: expiry, lc_margin_inr: margin !== '' ? margin : null }, userId);
  await auditLogRepository.log(userId, 'IMPORT_LC_SAVED', 'import_shipments', id, 'lc_no', s.lc_no, no);
  return null;
}

async function saveShipment(id, input, userId) {
  const s = await repo.find(id);
  const bl = str(input.bl_no);
  const blDate = str(input.bl_date);
  const vessel = str(input.vessel);
  const pol = str(input.port_of_loading);
  let error = null;
  if (!s) error = 'Import not found.';
  else if (!['ordered', 'shipped'].includes(s.stage)) error = 'The BL is recorded before the goods are cleared.';
  else if (bl === '' || len(bl) > 40) error = 'Enter the bill of lading (BL) number.';
  else if (!isDate(blDate) || blDate < String(s.po_date)) error = 'Enter the BL date (on or after the PO date).';
  else if (len(vessel) > 100 || len(pol) > 100) error = 'The vessel or port of loading is too long.';
  if (error !== null) return error;
  await repo.update(id, { bl_no: bl, bl_date: blDate, vessel: vessel !== '' ? vessel : null, port_of_loading: pol !== '' ? pol : null }, userId);
  await auditLogRepository.log(userId, 'IMPORT_BL_SAVED', 'import_shipments', id, 'bl_no', s.bl_no, bl);
  return null;
}

/** The bill of entry, the customs exchange rate, the CHA, the clearance date and the charges in INR. */
async function saveClearance(id, input, userId) {
  const s = await repo.find(id);
  const boe = String(input.boe_no || '').replace(/\D/g, '');
  const boeDate = str(input.boe_date);
  const port = str(input.port_code).toUpperCase();
  const cha = str(input.cha_name);
  const fx = str(input.customs_fx_rate);
  const cleared = str(input.clearance_date);
  const money = {};
  for (const k of ['freight_inr', 'insurance_inr', 'cha_inr', 'port_inr', 'other_inr']) money[k] = str(input[k]);
  const badMoney = Object.values(money).some((v) => v !== '' && !/^\d{1,11}(\.\d{1,2})?$/.test(v));
  let error = null;
  if (!s) error = 'Import not found.';
  else if (s.stage !== 'shipped') error = 'Customs clearance is recorded once the goods are shipped and before they are cleared.';
  else if (boe.length < 6 || boe.length > 8) error = 'Enter the bill of entry number (6 to 8 digits).';
  else if (!isDate(boeDate) || boeDate < String(s.bl_date)) error = 'Enter the bill of entry date (on or after the BL date).';
  else if (!/^IN[A-Z0-9]{3}[0-9A-Z]$/.test(port)) error = 'Enter the Indian port code of the bill of entry (6 characters, e.g. INNSA1).';
  else if (cha === '' || len(cha) > 150) error = 'Enter the customs agent (CHA).';
  else if (!/^\d{1,3}(\.\d{1,4})?$/.test(fx) || G.scaled(fx, 4) <= 0n) error = `Enter the customs exchange rate on the bill of entry (₹ per ${s.currency_code}, up to 4 decimals).`;
  else if (!isDate(cleared) || cleared < boeDate || cleared > todayIst()) error = 'Enter the clearance date (on or after the bill of entry date, not in the future).';
  else if (badMoney) error = 'Each charge is an amount in ₹ (up to 2 decimals); leave it empty for none.';
  if (error !== null) return error;
  const fields = { boe_no: boe, boe_date: boeDate, port_code: port, cha_name: cha, customs_fx_rate: fx, clearance_date: cleared };
  for (const [k, v] of Object.entries(money)) fields[k] = v !== '' ? v : '0';
  await repo.update(id, fields, userId);
  await auditLogRepository.log(userId, 'IMPORT_CLEARANCE_SAVED', 'import_shipments', id, 'boe_no', s.boe_no, boe);
  return null;
}

async function saveIgstCredit(id, date, userId) {
  const s = await repo.find(id);
  let error = null;
  if (!s) error = 'Import not found.';
  else if (!['cleared', 'received'].includes(s.stage)) error = 'The IGST input credit is recorded after the goods are cleared.';
  else if (!isDate(date) || date < String(s.boe_date) || date > todayIst()) error = 'Enter the date the IGST credit was taken (on or after the bill of entry date, not in the future).';
  if (error !== null) return error;
  await repo.update(id, { igst_credit_on: date }, userId);
  await auditLogRepository.log(userId, 'IMPORT_IGST_CREDIT', 'import_shipments', id, 'igst_credit_on', null, date);
  return null;
}

/**
 * D-257 Zoho: the customs duty and IGST paid for the bill of entry (ICEGATE
 * e-payment) — its date and challan / bank reference (D-212). It goes to
 * Zoho Books as a payment to Customs on the duty bill.
 */
async function saveDutyPayment(id, input, userId) {
  const s = await repo.find(id);
  const date = String((input && input.duty_paid_on) || '').trim();
  const ref = String((input && input.duty_bank_reference) || '').trim();
  let error = null;
  if (!s) error = 'Import not found.';
  else if (!s.boe_no || s.bcd_total === null || s.bcd_total === undefined) error = 'Record the bill of entry (customs clearance) first: the duty is worked out from it.';
  else if (s.duty_zoho_payment_id) error = 'This duty payment is already in Zoho Books, so it cannot be changed here. Ask the CA to correct it in Books.';
  else if (!isDate(date) || date < String(s.boe_date) || date > todayIst()) error = 'Enter the date the duty was paid (on or after the bill of entry date, not in the future).';
  else if (ref === '' || [...ref].length > 60) error = 'Enter the challan or bank reference of the duty payment (e.g. ICEGATE challan CIN).';
  if (error !== null) return error;
  await repo.update(id, { duty_paid_on: date, duty_bank_reference: ref }, userId);
  await auditLogRepository.log(userId, 'IMPORT_DUTY_PAID', 'import_shipments', id, 'duty_bank_reference', s.duty_bank_reference || null, ref);
  return null;
}

/** Rule D-212: what a payment abroad is, to whom, for which import, at what rate, and the bank reference. */
function paymentDescription(s, kind, amountFc, fxRate, amountInr, bankReference, note) {
  const what = { advance: 'Import supplier advance', balance: 'Import supplier balance payment', lc: 'LC retirement payment' }[kind];
  const text = `${what} of ${s.currency_code} ${fc(G.scaled(amountFc, 2))} (₹${G.inr(amountInr)} at ${fxRate}) to ${s.supplier_name} (${s.supplier_country}) against import PO ${s.reference}`
    + (kind === 'lc' && s.lc_no ? `, LC ${s.lc_no}` : '')
    + `, SWIFT/UTR ref ${bankReference}`;
  const n = str(note);
  return n !== '' ? `${text}; note: ${n}` : text;
}

/**
 * QA batch 2 (ORD-12): the import row is locked while a payment is checked and written, so two people
 * recording the same SWIFT/UTR (or together paying more than the PO) at the same moment cannot both pass.
 */
async function recordPayment(id, input, userId) {
  const db = require('../config/db');
  const done = await db.transaction(async (trx) => {
    await trx.queryOne('SELECT id FROM import_shipments WHERE id = :id FOR UPDATE', { id });
    return recordPaymentLocked(id, input, userId, trx);
  });
  if (done.error !== null) return done.error;
  await auditLogRepository.log(userId, 'IMPORT_PAYMENT_RECORDED', 'import_shipments', id, 'amount_fc', null, done.amount);
  return null;
}

async function recordPaymentLocked(id, input, userId, trx) {
  const s = await repo.find(id, trx);
  const date = str(input.paid_on);
  const kind = String(input.kind || '');
  const amount = str(input.amount_fc);
  const fx = str(input.fx_rate);
  const ref = str(input.bank_reference);
  const note = str(input.note);
  let error = null;
  if (!s) error = 'Import not found.';
  else if (s.stage === 'closed') error = 'The import is closed.';
  else if (!PAYMENT_KINDS[kind]) error = 'Choose what the payment is (advance, balance or LC retirement).';
  else if (kind === 'lc' && !s.lc_no) error = 'Record the LC first.';
  else if (!isDate(date) || date > todayIst()) error = 'Enter the date the money left the bank (not in the future).';
  else if (!/^\d{1,9}(\.\d{1,2})?$/.test(amount) || G.scaled(amount, 2) <= 0n) error = `Enter the amount paid in ${s.currency_code}.`;
  else if (!/^\d{1,3}(\.\d{1,4})?$/.test(fx) || G.scaled(fx, 4) <= 0n) error = `Enter the bank's exchange rate (₹ per ${s.currency_code}, up to 4 decimals).`;
  else if (ref === '' || len(ref) > 60) error = 'Enter the SWIFT / UTR reference of the remittance.';
  else if (await repo.bankReferenceUsed(ref, trx)) error = 'This bank reference is already recorded. Check the payment is not entered twice.';
  else {
    const po = await poValueCents(id, trx);
    const paid = await paidCents(id, trx);
    if (paid + G.scaled(amount, 2) > po) error = `This is more than the PO still owes (${s.currency_code} ${fc(po - paid)}).`;
    else if (len(note) > 255) error = 'The note is too long.';
  }
  if (error !== null) return { error, amount };
  const inr = G.money(G.roundDiv(G.scaled(amount, 2) * G.scaled(fx, 4), 10000n));
  const description = paymentDescription(s, kind, amount, fx, inr, ref, note);
  await repo.addPayment(id, {
    paid_on: date, kind, amount_fc: amount, fx_rate: fx, amount_inr: inr, bank_reference: ref, description, note: note !== '' ? note : null,
  }, userId, trx);
  return { error: null, amount };
}

async function markPoGenerated(id, userId) {
  const now = new Date();
  const p = (n) => String(n).padStart(2, '0');
  await repo.update(id, { ipo_generated_at: `${todayIst()} ${p(now.getHours())}:${p(now.getMinutes())}:${p(now.getSeconds())}` }, userId);
}

// ------------------------------------------------------------ stages

/** What still stops the import moving on (empty = it may move on). */
async function blockers(id) {
  const s = await repo.find(id);
  if (!s) return ['Import not found.'];
  const lines = await repo.lines(id);
  const out = [];
  switch (s.stage) {
    case 'ordered':
      if (lines.length === 0) out.push('Add at least one line.');
      if (!s.ipo_generated_at) out.push('Generate the import PO.');
      if (!s.bl_no) out.push('Record the bill of lading (BL).');
      break;
    case 'shipped':
      if (!s.boe_no || !s.clearance_date || !s.customs_fx_rate) out.push('Record the customs clearance (bill of entry, exchange rate, CHA, clearance date).');
      break;
    case 'cleared':
      break; // moving on puts the goods into stock
    case 'received': {
      const due = (await poValueCents(id)) - (await paidCents(id));
      if (due > 0n) out.push(`Still to pay the supplier: ${s.currency_code} ${fc(due)}.`);
      if (!s.igst_credit_on) out.push('Record when the IGST paid on import was taken as input credit.');
      break;
    }
    case 'closed':
      out.push('The import is closed.');
      break;
    default:
      break;
  }
  return out;
}

/** At clearance the duty and the landed cost per item are worked out and frozen. */
async function freeze(s, userId, trx) {
  const f = await figures(s, trx);
  for (const l of f.lines) await repo.setLineFigures(Number(l.id), l, trx);
  await repo.update(Number(s.id), {
    value_total_inr: f.value_total_inr, assessable_total: f.assessable_total, bcd_total: f.bcd_total,
    sws_total: f.sws_total, igst_total: f.igst_total, landed_total: f.landed_total,
  }, userId, trx);
}

/** Puts every line into stock at its frozen landed cost (weighted average). */
async function receiveIntoStock(s, userId, trx) {
  const today = todayIst();
  for (const l of await repo.lines(Number(s.id), trx)) {
    const b = await repo.lockBalance(Number(s.division_id), Number(l.product_id), trx);
    const qty = G.scaled(String(b.qty_on_hand), 3) + G.scaled(String(l.quantity), 3);
    const value = G.scaled(String(b.value_inr), 2) + G.scaled(String(l.landed_inr), 2);
    await repo.setBalance(Number(s.division_id), Number(l.product_id), fmt3(qty), G.money(value), trx);
    await repo.addMovement({
      division_id: Number(s.division_id), product_id: Number(l.product_id), moved_on: today, kind: 'receipt',
      quantity: String(l.quantity), value_inr: String(l.landed_inr), shipment_id: Number(s.id),
      note: `Received from import ${s.reference} (bill of entry ${s.boe_no})`,
    }, userId, trx);
  }
  await repo.update(Number(s.id), { received_on: today }, userId, trx);
}

/** Moves the import to its next stage when nothing stops it. @returns {Promise<string|null>} why not, or null */
async function advance(id, expectedStage, userId) {
  const s = await repo.find(id);
  if (!s) return 'Import not found.';
  if (s.stage !== expectedStage) return 'Someone has already moved this import on. The page shows where it is now.';
  const b = await blockers(id);
  if (b.length > 0) return b.join(' ');
  const next = STAGE_KEYS[STAGE_KEYS.indexOf(s.stage) + 1];
  const moved = await db.transaction(async (trx) => {
    if (!(await repo.moveStage(id, s.stage, next, userId, trx))) return false;
    if (next === 'cleared') await freeze(s, userId, trx);
    if (next === 'received') await receiveIntoStock(s, userId, trx);
    return true;
  });
  if (!moved) return 'Someone has already moved this import on. The page shows where it is now.';
  await auditLogRepository.log(userId, 'IMPORT_STAGE_MOVED', 'import_shipments', id, 'stage', s.stage, next);
  return null;
}

// ------------------------------------------------------------ stock out (local sale)

/** What stops a local sale's lines being issued from stock (every line a product; enough on hand). */
async function stockShortfalls(divId, saleLines) {
  const need = new Map();
  const out = [];
  for (const l of saleLines) {
    if (!l.product_id) {
      out.push(`Line ${l.line_no} (${l.description}) is not linked to a product, so it cannot come out of stock.`);
      continue;
    }
    const pid = Number(l.product_id);
    need.set(pid, (need.get(pid) || 0n) + G.scaled(String(l.quantity), 3));
  }
  for (const [pid, qty] of need) {
    const b = await repo.balance(divId, pid);
    const have = b ? G.scaled(String(b.qty_on_hand), 3) : 0n;
    if (have < qty) {
      const p = await repo.product(pid);
      out.push(`Not enough stock of ${p ? p.name : `product ${pid}`}: the sale needs ${trim3(qty)}, on hand ${trim3(have)}.`);
    }
  }
  return out;
}

/**
 * Issues a local sale's lines from stock, inside the caller's transaction
 * (trx). Each balance row is locked first, so two sales at once can never
 * take the same stock twice. Returns null, or why not (the caller rolls back).
 */
async function issueForSale(divId, orderId, orderReference, saleLines, userId, trx) {
  const today = todayIst();
  for (const l of saleLines) {
    if (!l.product_id) return `Line ${l.line_no} is not linked to a product, so it cannot come out of stock.`;
    const pid = Number(l.product_id);
    const b = await repo.lockBalance(divId, pid, trx);
    const have = G.scaled(String(b.qty_on_hand), 3);
    const value = G.scaled(String(b.value_inr), 2);
    const qty = G.scaled(String(l.quantity), 3);
    if (have < qty) {
      const p = await repo.product(pid);
      return `Not enough stock of ${p ? p.name : `product ${pid}`}: the sale needs ${trim3(qty)}, on hand ${trim3(have)}.`;
    }
    // Weighted average: the issue takes its share of the value; the last unit takes all that is left.
    const out = qty === have ? value : BigInt(roundHalfAway(Number(value) * Number(qty) / Number(have)));
    await repo.setBalance(divId, pid, fmt3(have - qty), G.money(value - out), trx);
    await repo.addMovement({
      division_id: divId, product_id: pid, moved_on: today, kind: 'issue', quantity: fmt3(-qty), value_inr: G.money(-out),
      order_id: orderId, note: `Issued to local sale ${orderReference}`,
    }, userId, trx);
  }
  return null;
}

// ------------------------------------------------------------ reports

/** The divisions the current person's reports cover (IMP only; company level: IMP, whatever the header choice is). */
async function reportDivisionIds() {
  const imp = await divisionId();
  if (imp === null) return [];
  return divisionService().allows(imp) ? [imp] : [];
}

/** Stock on hand with its average cost and suggested selling price. */
async function stockReport() {
  const margin = await setting('imp_margin_pct');
  const rows = [];
  for (const r of await repo.stockOnHand(await reportDivisionIds())) {
    const qty = G.scaled(String(r.qty_on_hand), 3);
    const avg = qty > 0n ? G.roundDiv(G.scaled(String(r.value_inr), 2) * 100000n, qty) : 0n;
    rows.push({ ...r, avg_cost_inr: fmt4(avg), suggested_price_inr: suggestedPrice(fmt4(avg), margin) });
  }
  return rows;
}

module.exports = {
  CODE, STAGES, STAGE_KEYS, PAYMENT_KINDS, DEFAULTS, CURRENCIES, INCOTERMS,
  divisionId, isActive, setting, roundHalfAway, spread, fmt4, fmt3, fc, lineValueCents, compute, suggestedPrice, dutyFor,
  poValueCents, paidCents, figures, headerFields, create, saveHeader, addLine, removeLine, saveLc, saveShipment, saveClearance,
  saveIgstCredit, saveDutyPayment, paymentDescription, recordPayment, markPoGenerated, blockers, advance, stockShortfalls, issueForSale,
  reportDivisionIds, stockReport,
};
