'use strict';

const db = require('../config/db');
const financialYear = require('../helpers/financialYear');
const { todayIst } = require('../helpers/dbDateTime');
const auditLogRepository = require('../repositories/auditLogRepository');
const domesticRepo = require('../repositories/domesticOrderRepository');
const repo = require('../repositories/merchantTradeRepository');
const G = require('./gstCalculator');
const I = require('./importService');

const divisionService = () => require('./divisionService');

/**
 * Owner decision D-257 Phase 4 (5 Nov 2026): the Merchant Trade (MTT)
 * division — RBI "merchanting trade": buy from a supplier abroad, sell to a
 * buyer abroad, the goods go straight from one to the other and never enter
 * India. A deal runs through its own stages:
 *
 *   contracted → shipped → settled → closed
 *
 * Two foreign-currency legs, each in its own currency: buy (paid to the
 * supplier) and sell (received from the buyer). The margin is tracked in ₹
 * at the rates actually realised and in USD (at the USD leg's realised rate,
 * else the MTT setting mtt_usd_inr_rate).
 *
 * RBI checks, all MTT settings TO BE CONFIRMED WITH THE AD BANK / CA: the
 * whole deal within mtt_max_months_total (9) months of its first payment or
 * receipt; foreign-exchange outlay within mtt_max_outlay_months (4); no
 * advance to the supplier above mtt_advance_limit_usd unless backed by an LC
 * or bank guarantee (a payment that would cross it is refused); the AD
 * bank's document checklist (mtt_bank_documents). The daily alert job
 * reminds the team before a limit is reached and when it is passed.
 * Port of PHP App\Services\MerchantTradeService.
 */

const CODE = 'MTT';
const STAGES = { contracted: 'Contracted', shipped: 'Shipped', settled: 'Settled', closed: 'Closed' };
const STAGE_KEYS = Object.keys(STAGES);
const LEGS = { buy: 'Paid to the supplier (buy leg)', sell: 'Received from the buyer (sell leg)' };
const BACKING = { none: 'None', lc: 'Letter of credit', bg: 'Bank guarantee' };

/** Starting values of MTT's own settings, used when a setting is missing (to be confirmed with the AD bank / CA). */
const DEFAULTS = {
  mtt_max_months_total: '9',
  mtt_max_outlay_months: '4',
  mtt_advance_limit_usd: '500000',
  mtt_usd_inr_rate: '84.0000',
  mtt_bank_documents: "Sale contract or buyer's order|Purchase contract or supplier's proforma|Supplier's invoice|Bill of lading|Our sale invoice|Inward remittance advice (sell leg)|Outward remittance SWIFT copy (buy leg)",
  mtt_ad_bank_approval: '',
};

const INCOTERMS = ['EXW', 'FCA', 'FOB', 'CFR', 'CIF', 'CPT', 'CIP', 'DAP', 'DPU', 'DDP'];
const WARN_DAYS = 30;
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const len = (s) => [...String(s)].length;
const str = (v) => String(v === undefined || v === null ? '' : v).trim();
const isDate = (s) => /^\d{4}-\d{2}-\d{2}$/.test(s) && !Number.isNaN(Date.parse(s));
const d10 = (v) => String(v === null || v === undefined ? '' : v).slice(0, 10);

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

function docKey(label) {
  return String(label).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80);
}

/** doc_key => label, from the MTT setting mtt_bank_documents (in order). */
async function bankDocuments() {
  const out = {};
  for (const raw of (await setting('mtt_bank_documents')).split('|')) {
    const label = raw.trim();
    if (label !== '') out[docKey(label)] = label;
  }
  return out;
}

// ------------------------------------------------------------ maths and dates (pure)

/** 'YYYY-MM-DD' plus whole months, the day kept or cut to the month's last day (31 Jan + 1 month = 28/29 Feb). */
function addMonths(date, months) {
  const [y, m, d] = d10(date).split('-').map((x) => parseInt(x, 10));
  const total = y * 12 + (m - 1) + months;
  const ny = Math.floor(total / 12);
  const nm = (total % 12) + 1;
  const last = new Date(Date.UTC(ny, nm, 0)).getUTCDate();
  return `${String(ny).padStart(4, '0')}-${String(nm).padStart(2, '0')}-${String(Math.min(d, last)).padStart(2, '0')}`;
}

/** Whole days from `from` to `to` (negative when `to` is earlier). */
function daysBetween(from, to) {
  const t = (s) => {
    const [y, m, d] = d10(s).split('-').map((x) => parseInt(x, 10));
    return Date.UTC(y, m - 1, d);
  };
  return Math.round((t(to) - t(from)) / 86400000);
}

/** "05 Nov 2026" (PHP 'd M Y'). */
function dmy(date) {
  const [y, m, d] = d10(date).split('-');
  return `${d} ${MONTHS[parseInt(m, 10) - 1]} ${y}`;
}

/** The deal's money on both legs and its margin (pure). */
function money(deal, lines, payments, usdRateSetting) {
  let sale = 0n;
  let purchase = 0n;
  for (const l of lines) {
    sale += I.lineValueCents(String(l.quantity), String(l.sell_price_fc));
    purchase += I.lineValueCents(String(l.quantity), String(l.buy_price_fc));
  }
  const t = { buy: { fc: 0n, inr: 0n }, sell: { fc: 0n, inr: 0n } };
  for (const p of payments) {
    t[p.leg].fc += G.scaled(String(p.amount_fc), 2);
    t[p.leg].inr += G.scaled(String(p.amount_inr), 2);
  }
  const rate = (leg) => (leg.fc > 0n ? G.roundDiv(leg.inr * 10000n, leg.fc) : null);
  const sellRate = rate(t.sell);
  const buyRate = rate(t.buy);
  let usdRate;
  if (deal.sale_currency === 'USD' && sellRate !== null) usdRate = sellRate;
  else if (deal.purchase_currency === 'USD' && buyRate !== null) usdRate = buyRate;
  else usdRate = G.scaled(String(usdRateSetting), 4);
  const margin = t.sell.inr - t.buy.inr;
  return {
    sale_cents: sale,
    purchase_cents: purchase,
    received_cents: t.sell.fc,
    paid_cents: t.buy.fc,
    received_inr: G.money(t.sell.inr),
    paid_inr: G.money(t.buy.inr),
    sell_rate: sellRate !== null ? I.fmt4(sellRate) : null,
    buy_rate: buyRate !== null ? I.fmt4(buyRate) : null,
    usd_rate: I.fmt4(usdRate),
    margin_inr: G.money(margin),
    margin_usd_cents: usdRate > 0n ? G.roundDiv(margin * 10000n, usdRate) : 0n,
    margin_pct: t.sell.inr > 0n ? G.money(G.roundDiv(margin * 10000n, t.sell.inr)) : null,
    contract_margin_cents: deal.sale_currency === deal.purchase_currency ? sale - purchase : null,
    final: sale > 0n && t.sell.fc === sale && t.buy.fc === purchase,
  };
}

/** A buy payment's value in USD cents: its own amount when the purchase is in USD, else its ₹ at the USD rate. */
function usdCents(deal, payment, usdRateSetting) {
  if (deal.purchase_currency === 'USD') return G.scaled(String(payment.amount_fc), 2);
  const r = G.scaled(String(usdRateSetting), 4);
  return r > 0n ? G.roundDiv(G.scaled(String(payment.amount_inr), 2) * 10000n, r) : 0n;
}

/** The supplier payments made before the buyer's first receipt (a receipt on the same day covers it). */
function advances(payments) {
  let first = null;
  for (const p of payments) {
    if (p.leg === 'sell' && (first === null || d10(p.moved_on) < first)) first = d10(p.moved_on);
  }
  return payments.filter((p) => p.leg === 'buy' && (first === null || d10(p.moved_on) < first));
}

/** The periods money paid out to the supplier was not yet covered by the buyer's money (₹ at the realised rates). */
function outlayPeriods(payments) {
  const sorted = [...payments].sort((a, b) => {
    const ka = `${d10(a.moved_on)}|${a.leg === 'sell' ? 0 : 1}`;
    const kb = `${d10(b.moved_on)}|${b.leg === 'sell' ? 0 : 1}`;
    return ka < kb ? -1 : (ka > kb ? 1 : 0);
  });
  let cum = 0n;
  const periods = [];
  let open = null;
  for (const p of sorted) {
    const inr = G.scaled(String(p.amount_inr), 2);
    cum += p.leg === 'buy' ? inr : -inr;
    if (cum > 0n && open === null) open = d10(p.moved_on);
    else if (cum <= 0n && open !== null) {
      periods.push({ from: open, to: d10(p.moved_on) });
      open = null;
    }
  }
  if (open !== null) periods.push({ from: open, to: null });
  return periods;
}

/** The RBI checks of a deal (pure). Each: key, label, level (ok | info | warn | breach) and text. */
function rbiChecks(deal, payments, docs, received, s, today) {
  const out = [];
  const months = parseInt(s.mtt_max_months_total, 10);
  const outlayMonths = parseInt(s.mtt_max_outlay_months, 10);
  const closed = deal.stage === 'closed' && !!deal.closed_on;

  // 1. The whole deal within N months of its first payment or receipt.
  let start = null;
  for (const p of payments) if (start === null || d10(p.moved_on) < start) start = d10(p.moved_on);
  let label = `Whole deal within ${months} months`;
  if (start === null) {
    out.push({ key: 'total', label, level: 'info', text: `Starts with the first payment or receipt; the deal must then finish within ${months} months.` });
  } else {
    const due = addMonths(start, months);
    if (closed) {
      out.push(d10(deal.closed_on) <= due
        ? { key: 'total', label, level: 'ok', text: `Finished on ${dmy(deal.closed_on)}, within the limit (${dmy(due)}).` }
        : { key: 'total', label, level: 'breach', text: `Finished on ${dmy(deal.closed_on)}, after the limit (${dmy(due)}). Report it to the AD bank.` });
    } else {
      const left = daysBetween(today, due);
      if (left < 0) out.push({ key: 'total', label, level: 'breach', text: `Overdue: the deal had to finish by ${dmy(due)} (started ${dmy(start)}). Report it to the AD bank.` });
      else if (left <= WARN_DAYS) out.push({ key: 'total', label, level: 'warn', text: `Due soon: finish by ${dmy(due)} (${left} days left).` });
      else out.push({ key: 'total', label, level: 'ok', text: `Finish by ${dmy(due)} (${left} days left).` });
    }
  }

  // 2. Foreign-exchange outlay within N months.
  label = `Outlay within ${outlayMonths} months`;
  const periods = outlayPeriods(payments);
  if (periods.length === 0) {
    out.push(payments.length === 0
      ? { key: 'outlay', label, level: 'info', text: 'No money has moved yet.' }
      : { key: 'outlay', label, level: 'ok', text: "No outlay: the buyer's money came before the supplier was paid." });
  } else {
    let check = null;
    for (const p of periods) {
      const due = addMonths(p.from, outlayMonths);
      if (p.to !== null) {
        check = p.to > due
          ? { level: 'breach', text: `Outlay from ${dmy(p.from)} to ${dmy(p.to)} was longer than the limit (${dmy(due)}). Report it to the AD bank.` }
          : (check || { level: 'ok', text: `Outlay from ${dmy(p.from)} covered on ${dmy(p.to)}, within the limit.` });
        if (check.level === 'breach') break;
        continue;
      }
      const left = daysBetween(today, due);
      if (left < 0) check = { level: 'breach', text: `Overdue: money paid to the supplier since ${dmy(p.from)} had to be covered by the buyer by ${dmy(due)}. Report it to the AD bank.` };
      else if (left <= WARN_DAYS) check = { level: 'warn', text: `Due soon: the buyer's money must cover the outlay since ${dmy(p.from)} by ${dmy(due)} (${left} days left).` };
      else check = { level: 'ok', text: `Outlay open since ${dmy(p.from)}: cover it by ${dmy(due)} (${left} days left).` };
    }
    out.push({ key: 'outlay', label, ...check });
  }

  // 3. No advance above the limit unless backed by an LC / BG.
  const limitCents = G.scaled(String(s.mtt_advance_limit_usd), 2);
  label = `Advance to the supplier within USD ${I.fc(limitCents)} unless backed by an LC or bank guarantee`;
  let adv = 0n;
  for (const p of advances(payments)) adv += usdCents(deal, p, s.mtt_usd_inr_rate);
  const backing = String(deal.advance_backing || 'none');
  if (adv === 0n) out.push({ key: 'advance', label, level: 'ok', text: 'No advance paid to the supplier before the buyer paid.' });
  else if (backing !== 'none') out.push({ key: 'advance', label, level: 'ok', text: `Advance USD ${I.fc(adv)} backed by ${BACKING[backing].toLowerCase()} ${String(deal.backing_ref || '')}.` });
  else if (adv <= limitCents) out.push({ key: 'advance', label, level: 'ok', text: `Advance USD ${I.fc(adv)}, within the limit.` });
  else out.push({ key: 'advance', label, level: 'breach', text: `Advance USD ${I.fc(adv)} is above the limit and not backed by an LC or bank guarantee. Report it to the AD bank.` });

  // 4. The AD bank's document checklist.
  const keys = Object.keys(docs);
  const missing = keys.filter((k) => !(k in received)).map((k) => docs[k]);
  label = 'Documents with the AD bank';
  out.push(missing.length === 0
    ? { key: 'documents', label, level: 'ok', text: `All ${keys.length} documents are with the bank.` }
    : {
      key: 'documents', label, level: ['settled', 'closed'].includes(deal.stage) ? 'warn' : 'info',
      text: `${keys.length - missing.length} of ${keys.length} with the bank. Missing: ${missing.join('; ')}.`,
    });
  return out;
}

async function rbiSettings() {
  const out = {};
  for (const k of ['mtt_max_months_total', 'mtt_max_outlay_months', 'mtt_advance_limit_usd', 'mtt_usd_inr_rate']) out[k] = await setting(k);
  return out;
}

function frozenRbi(deal) {
  const raw = deal ? deal.rbi_settings_json : null;
  if (raw === null || raw === undefined || raw === '') return null;
  if (typeof raw === 'object') return raw;
  try { const v = JSON.parse(raw); return v && typeof v === 'object' ? v : null; } catch (e) { return null; }
}

/** D-262: the RBI settings the deal was made under (frozen at creation), else today's. */
async function dealRbiSettings(deal) {
  const frozen = frozenRbi(deal);
  const live = await rbiSettings();
  if (!frozen) return live;
  for (const k of Object.keys(live)) {
    if (frozen[k] !== undefined && frozen[k] !== null && frozen[k] !== '') live[k] = String(frozen[k]);
  }
  return live;
}

/** D-262: the AD bank's document checklist the deal was made under. */
async function dealBankDocuments(deal) {
  const frozen = frozenRbi(deal);
  const bd = frozen ? frozen.bank_documents : null;
  if (bd && typeof bd === 'object' && !Array.isArray(bd) && Object.keys(bd).length > 0) {
    return Object.fromEntries(Object.entries(bd).map(([k, v]) => [k, String(v)]));
  }
  return bankDocuments();
}

/** The RBI checks of a stored deal, today. */
async function checksFor(deal, today = null) {
  return rbiChecks(deal, await repo.payments(Number(deal.id)), await dealBankDocuments(deal), await repo.documents(Number(deal.id)), await dealRbiSettings(deal), today || todayIst());
}

/** The worst level of a deal's checks: breach > warn > ok/info. */
function worstLevel(checks) {
  const levels = checks.map((c) => c.level);
  if (levels.includes('breach')) return 'breach';
  return levels.includes('warn') ? 'warn' : 'ok';
}

/** The deal's money and margin from the database. */
async function figures(deal, executor) {
  return money(deal, await repo.lines(Number(deal.id), executor), await repo.payments(Number(deal.id), executor), (await dealRbiSettings(deal)).mtt_usd_inr_rate);
}

// ------------------------------------------------------------ helpers

async function nextReference(series, label) {
  const fy = financialYear.label(todayIst());
  const short = `${fy.slice(2, 4)}-${fy.slice(5, 7)}`;
  return `MTT/${label ? `${label}/` : ''}${short}/${String(await domesticRepo.nextNumber(series, fy)).padStart(4, '0')}`;
}

// ------------------------------------------------------------ create and edit

function headerFields(input) {
  const t = (k) => str(input[k]);
  const f = {
    deal_date: t('deal_date'),
    supplier_name: t('supplier_name'), supplier_country: t('supplier_country'), supplier_address: t('supplier_address'),
    purchase_currency: t('purchase_currency').toUpperCase(), purchase_incoterm: t('purchase_incoterm').toUpperCase(),
    buyer_name: t('buyer_name'), buyer_country: t('buyer_country'), buyer_address: t('buyer_address'),
    sale_currency: t('sale_currency').toUpperCase(), sale_incoterm: t('sale_incoterm').toUpperCase(),
    origin_country: t('origin_country'), port_of_loading: t('port_of_loading'), port_of_discharge: t('port_of_discharge'),
  };
  const india = (c) => c.toUpperCase() === 'INDIA';
  let error = null;
  if (!isDate(f.deal_date) || f.deal_date > todayIst()) error = 'Enter the deal date (not in the future).';
  else if (f.supplier_name === '' || len(f.supplier_name) > 255) error = 'Enter the supplier abroad.';
  else if (f.supplier_country === '' || len(f.supplier_country) > 80) error = "Enter the supplier's country.";
  else if (f.buyer_name === '' || len(f.buyer_name) > 255) error = 'Enter the buyer abroad.';
  else if (f.buyer_country === '' || len(f.buyer_country) > 80) error = "Enter the buyer's country.";
  else if (india(f.supplier_country) || india(f.buyer_country)) error = 'In a merchant trade both the supplier and the buyer are abroad. Buying from or selling to India is an export, import or domestic sale.';
  else if (f.origin_country === '' || len(f.origin_country) > 80) error = 'Enter the country the goods come from.';
  else if (india(f.origin_country)) error = 'Goods made in India and shipped abroad are an export, not a merchant trade.';
  else if (len(f.supplier_address) > 1000 || len(f.buyer_address) > 1000) error = 'An address is too long.';
  else if (!I.CURRENCIES.includes(f.purchase_currency)) error = "Choose the currency of the purchase (the supplier's invoice).";
  else if (!I.CURRENCIES.includes(f.sale_currency)) error = 'Choose the currency of the sale (our invoice to the buyer).';
  else if ((f.purchase_incoterm !== '' && !INCOTERMS.includes(f.purchase_incoterm)) || (f.sale_incoterm !== '' && !INCOTERMS.includes(f.sale_incoterm))) error = 'Choose a valid Incoterm.';
  else if (len(f.port_of_loading) > 100 || len(f.port_of_discharge) > 100) error = 'A port name is too long.';
  for (const k of ['supplier_address', 'buyer_address', 'purchase_incoterm', 'sale_incoterm', 'port_of_loading', 'port_of_discharge']) f[k] = f[k] !== '' ? f[k] : null;
  return [f, error];
}

/** Creates a deal (its reference and PO number are taken now). @returns {Promise<[number|null, string|null]>} */
async function create(input, userId) {
  const divId = await divisionId();
  if (divId === null || !(await isActive())) return [null, 'Merchant Trade is not active yet. The MD activates it in Admin › Divisions.'];
  const [fields, error] = headerFields(input);
  if (error !== null) return [null, error];
  fields.division_id = divId;
  fields.reference = await nextReference('MTT-DEAL', '');
  fields.po_no = await nextReference('MTT-PO', 'PO');
  const id = await repo.insert(fields, userId);
  // D-262: the deal keeps the RBI settings and the bank's checklist it was made under.
  await db.execute('UPDATE merchant_deals SET rbi_settings_json = :j WHERE id = :id',
    { j: JSON.stringify({ ...(await rbiSettings()), bank_documents: await bankDocuments() }), id });
  await auditLogRepository.log(userId, 'MERCHANT_DEAL_CREATED', 'merchant_deals', id, 'reference', null, fields.reference);
  return [id, null];
}

async function saveHeader(id, input, userId) {
  const d = await repo.find(id);
  if (!d) return 'Deal not found.';
  if (d.stage !== 'contracted') return 'The deal can change only before the goods are shipped.';
  if ((await repo.payments(id)).length > 0 && (str(input.purchase_currency).toUpperCase() !== d.purchase_currency || str(input.sale_currency).toUpperCase() !== d.sale_currency)) {
    return 'Money has already moved on this deal, so its currencies cannot change.';
  }
  const [fields, error] = headerFields(input);
  if (error !== null) return error;
  await repo.update(id, fields, userId);
  await auditLogRepository.log(userId, 'MERCHANT_DEAL_SAVED', 'merchant_deals', id, 'buyer_name', d.buyer_name, fields.buyer_name);
  return null;
}

async function addLine(id, input, userId) {
  const d = await repo.find(id);
  if (!d) return 'Deal not found.';
  if (d.stage !== 'contracted') return 'The goods can change only before they are shipped.';
  const description = str(input.description);
  const hsn = String(input.hsn_code || '').replace(/\D/g, '');
  const qty = str(input.quantity);
  const unit = str(input.unit);
  const buy = str(input.buy_price_fc);
  const sell = str(input.sell_price_fc);
  const price = (v) => /^\d{1,9}(\.\d{1,2})?$/.test(v) && G.scaled(v, 2) > 0n;
  let error = null;
  if (description === '' || len(description) > 500) error = 'Enter the description of the goods (up to 500 characters).';
  else if (hsn.length < 4 || hsn.length > 8) error = 'Enter the HS code (4 to 8 digits).';
  else if (!/^\d{1,7}(\.\d{1,3})?$/.test(qty) || G.scaled(qty, 3) <= 0n) error = 'Enter a quantity above zero (up to 3 decimals).';
  else if (unit === '' || len(unit) > 20) error = 'Enter the unit (e.g. MT, SQM, PCS).';
  else if (!price(buy)) error = `Enter the buy price per unit in ${d.purchase_currency}, above zero (up to 2 decimals).`;
  else if (!price(sell)) error = `Enter the sell price per unit in ${d.sale_currency}, above zero (up to 2 decimals).`;
  else if (I.lineValueCents(qty, buy) > 99999999999n || I.lineValueCents(qty, sell) > 99999999999n) error = 'The line value is too large; split it into several lines.';
  if (error !== null) return error;
  await repo.addLine(id, { description, hsn_code: hsn, quantity: qty, unit: unit.toUpperCase(), buy_price_fc: buy, sell_price_fc: sell }, userId);
  await auditLogRepository.log(userId, 'MERCHANT_LINE_ADDED', 'merchant_deals', id, 'line', null, `${description} × ${qty}`);
  return null;
}

async function removeLine(id, lineId, userId) {
  const d = await repo.find(id);
  if (!d || d.stage !== 'contracted') return 'The goods can change only before they are shipped.';
  if (!(await repo.removeLine(id, lineId))) return 'Line not found.';
  await auditLogRepository.log(userId, 'MERCHANT_LINE_REMOVED', 'merchant_deals', id, 'line', String(lineId), null);
  return null;
}

/** The LC or bank guarantee that backs an advance to the supplier (RBI). */
async function saveBacking(id, input, userId) {
  const d = await repo.find(id);
  const backing = String(input.advance_backing || '');
  const ref = str(input.backing_ref);
  let error = null;
  if (!d) error = 'Deal not found.';
  else if (d.stage === 'closed') error = 'The deal is closed.';
  else if (!BACKING[backing]) error = 'Choose what backs the advance (none, LC or bank guarantee).';
  else if (backing !== 'none' && (ref === '' || len(ref) > 60)) error = 'Enter the LC or bank guarantee number.';
  if (error !== null) return error;
  await repo.update(id, { advance_backing: backing, backing_ref: backing !== 'none' ? ref : null }, userId);
  await auditLogRepository.log(userId, 'MERCHANT_BACKING_SAVED', 'merchant_deals', id, 'advance_backing', String(d.advance_backing), backing);
  return null;
}

/** The bill of lading, and a switch BL where the bank allows it. */
async function saveShipment(id, input, userId) {
  const d = await repo.find(id);
  const bl = str(input.bl_no);
  const blDate = str(input.bl_date);
  const vessel = str(input.vessel);
  const sw = String(input.switch_bl || '') === '1';
  const switchNo = str(input.switch_bl_no);
  const switchRef = str(input.switch_bl_ref);
  let error = null;
  if (!d) error = 'Deal not found.';
  else if (!['contracted', 'shipped'].includes(d.stage)) error = 'The BL is recorded before the deal is settled.';
  else if (bl === '' || len(bl) > 40) error = 'Enter the bill of lading (BL) number.';
  else if (!isDate(blDate) || blDate < d10(d.deal_date) || blDate > todayIst()) error = 'Enter the BL date (on or after the deal date, not in the future).';
  else if (len(vessel) > 100) error = 'The vessel is too long.';
  else if (sw && (switchNo === '' || len(switchNo) > 40)) error = 'Enter the number of the switch BL.';
  else if (sw && (switchRef === '' || len(switchRef) > 255)) error = "Enter who issued the switch BL and the bank's consent (e.g. carrier agent, bank letter reference).";
  if (error !== null) return error;
  await repo.update(id, {
    bl_no: bl, bl_date: blDate, vessel: vessel !== '' ? vessel : null, switch_bl: sw ? 1 : 0, switch_bl_no: sw ? switchNo : null, switch_bl_ref: sw ? switchRef : null,
  }, userId);
  await auditLogRepository.log(userId, 'MERCHANT_BL_SAVED', 'merchant_deals', id, 'bl_no', d.bl_no, bl + (sw ? ` / switch ${switchNo}` : ''));
  return null;
}

/** Ticks (or unticks, date '') one document of the AD bank's checklist. */
async function saveDocument(id, key, date, userId) {
  const d = await repo.find(id);
  const docs = d ? await dealBankDocuments(d) : await bankDocuments();
  let error = null;
  if (!d) error = 'Deal not found.';
  else if (d.stage === 'closed') error = 'The deal is closed.';
  else if (!Object.prototype.hasOwnProperty.call(docs, key)) error = "Choose a document from the bank's checklist.";
  else if (date !== '' && (!isDate(date) || date > todayIst())) error = 'Enter the date the bank received it (not in the future).';
  if (error !== null) return error;
  await repo.setDocument(id, key, date !== '' ? date : null, userId);
  await auditLogRepository.log(userId, 'MERCHANT_BANK_DOCUMENT', 'merchant_deals', id, key, null, date !== '' ? date : 'removed');
  return null;
}

/** Rule D-212: what the money is, to or from whom, for which deal, at what rate, and the bank reference. */
function paymentDescription(d, leg, isAdvance, amountFc, fxRate, amountInr, bankReference, note) {
  const cur = leg === 'buy' ? d.purchase_currency : d.sale_currency;
  const m = `${cur} ${I.fc(G.scaled(String(amountFc), 2))} (₹${G.inr(String(amountInr))} at ${fxRate})`;
  let text;
  if (leg === 'buy') {
    text = `${isAdvance ? 'Merchant trade supplier advance' : 'Merchant trade supplier payment'} of ${m} to ${d.supplier_name} (${d.supplier_country}) against merchant trade deal ${d.reference}, PO ${d.po_no}`
      + (isAdvance && d.advance_backing !== 'none' ? `, backed by ${BACKING[d.advance_backing].toLowerCase()} ${d.backing_ref}` : '');
  } else {
    text = `${isAdvance ? 'Merchant trade buyer advance received' : 'Merchant trade sale receipt'} of ${m} from ${d.buyer_name} (${d.buyer_country}) against merchant trade deal ${d.reference}`
      + (d.sale_invoice_no ? `, invoice ${d.sale_invoice_no}` : '');
  }
  text += `, SWIFT/UTR ref ${bankReference}`;
  const n = str(note);
  return n !== '' ? `${text}; note: ${n}` : text;
}

async function paymentError(d, leg, date, amount, fx, ref, note, trx) {
  if (d.stage === 'closed') return 'The deal is closed.';
  if (!LEGS[leg]) return 'Choose the leg: paid to the supplier or received from the buyer.';
  const cur = leg === 'buy' ? d.purchase_currency : d.sale_currency;
  const f = await figures(d, trx);
  const owed = leg === 'buy' ? f.purchase_cents - f.paid_cents : f.sale_cents - f.received_cents;
  if (!isDate(date) || date > todayIst() || date < d10(d.deal_date)) return 'Enter the date the money moved through the bank (on or after the deal date, not in the future).';
  if (!/^\d{1,9}(\.\d{1,2})?$/.test(amount) || G.scaled(amount, 2) <= 0n) return `Enter the amount in ${cur}.`;
  if (!/^\d{1,3}(\.\d{1,4})?$/.test(fx) || G.scaled(fx, 4) <= 0n) return `Enter the bank's exchange rate (₹ per ${cur}, up to 4 decimals).`;
  if (ref === '' || len(ref) > 60) return 'Enter the SWIFT / UTR reference.';
  if (await repo.bankReferenceUsed(ref, trx)) return 'This bank reference is already recorded. Check the payment is not entered twice.';
  if (G.scaled(amount, 2) > owed) return `${leg === 'buy' ? 'This is more than the supplier is still owed' : 'This is more than the buyer still owes'} (${cur} ${I.fc(owed > 0n ? owed : 0n)}).`;
  if (len(note) > 255) return 'The note is too long.';
  return null;
}

/**
 * Records money on one leg. The deal row is locked first, so two payments at
 * once are checked one after the other (never more than is owed, never an
 * unbacked advance above the limit).
 */
async function recordPayment(id, input, userId) {
  const leg = String(input.leg || '');
  const date = str(input.moved_on);
  const amount = str(input.amount_fc);
  const fx = str(input.fx_rate);
  const ref = str(input.bank_reference);
  const note = str(input.note);
  const result = await db.transaction(async (trx) => {
    const d = await repo.lock(id, trx);
    if (!d) return 'Deal not found.';
    const rbi = await dealRbiSettings(d);
    const usdRate = rbi.mtt_usd_inr_rate;
    const limit = G.scaled(rbi.mtt_advance_limit_usd, 2);
    const error = await paymentError(d, leg, date, amount, fx, ref, note, trx);
    if (error !== null) return error;
    const payments = await repo.payments(id, trx);
    const inr = G.money(G.roundDiv(G.scaled(amount, 2) * G.scaled(fx, 4), 10000n));
    const neu = { leg, moved_on: date, amount_fc: amount, amount_inr: inr };
    // A supplier payment before any money from the buyer (a receipt on the same day covers it) is an advance;
    // a buyer's payment before our sale invoice is an advance received.
    const covered = payments.some((p) => p.leg === 'sell' && d10(p.moved_on) <= date);
    const isAdvance = leg === 'buy' ? !covered : !d.sale_invoice_no;
    if (leg === 'buy' && isAdvance && d.advance_backing === 'none') {
      let usd = 0n;
      for (const p of advances([...payments, neu])) usd += usdCents(d, p, usdRate);
      if (usd > limit) {
        return `This would make the advance to the supplier USD ${I.fc(usd)}, above the USD ${I.fc(limit)} limit for an advance not backed by an LC or bank guarantee (RBI merchanting rule, to be confirmed with the AD bank). Record the LC or bank guarantee first.`;
      }
    }
    await repo.addPayment(id, {
      leg, moved_on: date, amount_fc: amount, fx_rate: fx, amount_inr: inr, bank_reference: ref,
      description: paymentDescription(d, leg, isAdvance, amount, fx, inr, ref, note), note: note !== '' ? note : null,
    }, userId, trx);
    return null;
  });
  if (result !== null) return result;
  await auditLogRepository.log(userId, 'MERCHANT_PAYMENT_RECORDED', 'merchant_deals', id, leg, null, amount);
  return null;
}

function nowIst() {
  const now = new Date();
  const p = (n) => String(n).padStart(2, '0');
  return `${todayIst()} ${p(now.getHours())}:${p(now.getMinutes())}:${p(now.getSeconds())}`;
}

async function markPoGenerated(id, userId) {
  await repo.update(id, { po_generated_at: nowIst() }, userId);
  await freezeCompany(id);
}

/** D-262: the deal keeps the company details its first document printed (PO or sale invoice). */
async function freezeCompany(id) {
  await db.execute('UPDATE merchant_deals SET company_snapshot_json = :j WHERE id = :id AND company_snapshot_json IS NULL',
    { j: await require('./orderSnapshotService').companyValuesJson(), id });
}

/** Fixes the sale invoice number the first time the invoice is generated (it needs the BL). Returns why not, or null. */
async function fixInvoice(id, userId) {
  const d = await repo.find(id);
  if (!d) return 'Deal not found.';
  if ((await repo.lines(id)).length === 0) return 'Add the goods first.';
  if (!d.bl_no) return 'Record the BL first: the sale invoice goes with the shipping documents.';
  const fields = { invoice_generated_at: nowIst() };
  if (!d.sale_invoice_no) {
    fields.sale_invoice_no = await nextReference('MTT-INV', 'I'); // MTT/I/26-27/0001: 16 characters, the most a GST-registered Books org accepts (ZOH-9)
    fields.invoice_date = todayIst();
  }
  await repo.update(id, fields, userId);
  await freezeCompany(id);
  if (fields.sale_invoice_no) await auditLogRepository.log(userId, 'MERCHANT_INVOICE_FIXED', 'merchant_deals', id, 'sale_invoice_no', null, fields.sale_invoice_no);
  return null;
}

// ------------------------------------------------------------ stages

async function blockers(id) {
  const d = await repo.find(id);
  if (!d) return ['Deal not found.'];
  const out = [];
  if (d.stage === 'contracted') {
    if ((await repo.lines(id)).length === 0) out.push('Add the goods.');
    if (!d.po_generated_at) out.push('Generate the purchase order to the supplier.');
    if (!d.bl_no) out.push('Record the bill of lading (BL).');
  } else if (d.stage === 'shipped') {
    const f = await figures(d);
    if (!d.sale_invoice_no) out.push('Generate the sale invoice to the buyer.');
    if (f.received_cents < f.sale_cents) out.push(`Still to receive from the buyer: ${d.sale_currency} ${I.fc(f.sale_cents - f.received_cents)}.`);
    if (f.paid_cents < f.purchase_cents) out.push(`Still to pay the supplier: ${d.purchase_currency} ${I.fc(f.purchase_cents - f.paid_cents)}.`);
  } else if (d.stage === 'settled') {
    const docs = await dealBankDocuments(d);
    const have = await repo.documents(id);
    const missing = Object.keys(docs).filter((k) => !(k in have)).map((k) => docs[k]);
    if (missing.length > 0) out.push(`Documents still to give the AD bank: ${missing.join('; ')}.`);
  } else if (d.stage === 'closed') {
    out.push('The deal is closed.');
  }
  return out;
}

async function advance(id, expectedStage, userId) {
  const d = await repo.find(id);
  if (!d) return 'Deal not found.';
  if (d.stage !== expectedStage) return 'Someone has already moved this deal on. The page shows where it is now.';
  const b = await blockers(id);
  if (b.length > 0) return b.join(' ');
  const next = STAGE_KEYS[STAGE_KEYS.indexOf(d.stage) + 1];
  if (!(await repo.moveStage(id, d.stage, next, userId))) return 'Someone has already moved this deal on. The page shows where it is now.';
  if (next === 'closed') await repo.update(id, { closed_on: todayIst() }, userId);
  await auditLogRepository.log(userId, 'MERCHANT_STAGE_MOVED', 'merchant_deals', id, 'stage', d.stage, next);
  return null;
}

// ------------------------------------------------------------ reminders (daily alert job)

/**
 * Reminds the team (manage_purchasing, in MTT or company level) of every RBI
 * check that is due soon or passed, once per deal, check and level. Run from
 * src/jobs/checkAlerts.js. @returns {Promise<number>} reminders created
 */
async function runAlerts(today = null) {
  const day = today || todayIst();
  const notificationRepository = require('../repositories/notificationRepository');
  let created = 0;
  for (const d of await repo.openDeals()) {
    let recipients = null;
    for (const c of await checksFor(d, day)) {
      if (!['warn', 'breach'].includes(c.level) || !(await repo.markAlertSent(Number(d.id), c.key, c.level))) continue;
      if (recipients === null) recipients = await repo.alertRecipients(Number(d.division_id));
      const message = [...`Merchant trade ${d.reference} (${d.buyer_name}): ${c.label} — ${c.text}`].slice(0, 500).join('');
      for (const userId of recipients) {
        await notificationRepository.create(userId, null, 'merchant_trade_rbi', null, message);
        created++;
      }
    }
  }
  return created;
}

// ------------------------------------------------------------ reports

async function reportDivisionIds() {
  const mtt = await divisionId();
  return mtt !== null && divisionService().allows(mtt) ? [mtt] : [];
}

/** Every deal with its money, margin and RBI status. */
async function reportRows() {
  const rows = [];
  for (const d of await repo.list(await reportDivisionIds())) {
    const checks = await checksFor(d);
    rows.push({ ...d, figures: await figures(d), checks, worst: worstLevel(checks) });
  }
  return rows;
}

module.exports = {
  CODE, STAGES, STAGE_KEYS, LEGS, BACKING, DEFAULTS, INCOTERMS, WARN_DAYS,
  divisionId, isActive, setting, bankDocuments, docKey, addMonths, daysBetween, dmy, money, usdCents, advances, outlayPeriods, rbiChecks,
  rbiSettings, dealRbiSettings, dealBankDocuments, checksFor, worstLevel, figures, headerFields, create, saveHeader, addLine, removeLine, saveBacking, saveShipment, saveDocument,
  paymentDescription, recordPayment, markPoGenerated, fixInvoice, blockers, advance, runAlerts, reportDivisionIds, reportRows,
};
