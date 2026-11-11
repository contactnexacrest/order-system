'use strict';

const db = require('../config/db');
const auditLogRepository = require('../repositories/auditLogRepository');
const notificationRepository = require('../repositories/notificationRepository');
const permissionRepository = require('../repositories/permissionRepository');
const productRepository = require('../repositories/productRepository');
const pricingEngine = require('./pricingEngine');
const pricingService = require('./pricingService');
const productSpecs = require('./productSpecs');

/**
 * Port of App\Services\PriceRequestService — owner decision D-255 (5 Nov 2026):
 * associate price requests.
 *
 * Associates see a catalogue without prices (no supplier names or quarry
 * locations). For one product and specification they ask for a price: the app
 * answers at once from the pricing rules — their quote price and the lowest
 * they may agree, at their commission level — valid N days (setting, 7), at most
 * N automatic requests a day (setting, 10; per-associate override). Anything
 * non-standard ("Other", an unlisted thickness, no per-CBM supplier price) goes to
 * staff to price within 24 hours and does not count toward the limit. An order
 * line can be filled from a valid price request; it then keeps the request's prices.
 * Associates and sales staff never see the cost.
 */

/** A warning on the report when an associate asks this many prices in 90 days and places no order. */
const WARN_REQUESTS = 20;

const p2 = (n) => String(n).padStart(2, '0');
/** Local (server time zone) date 'Y-m-d', like PHP date('Y-m-d'). */
function ymd(d = new Date()) {
  return `${d.getFullYear()}-${p2(d.getMonth() + 1)}-${p2(d.getDate())}`;
}
function ymdhis(d = new Date()) {
  return `${ymd(d)} ${p2(d.getHours())}:${p2(d.getMinutes())}:${p2(d.getSeconds())}`;
}
function addDays(dateYmd, days) {
  const [y, m, d] = dateYmd.split('-').map(Number);
  return ymd(new Date(y, m - 1, d + days));
}
/** PHP number_format($v, 2) */
const money = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const isSet = (v) => v !== null && v !== undefined && v !== '';

async function dailyLimit(associate) {
  return isSet(associate.price_request_daily_limit)
    ? Math.max(0, parseInt(associate.price_request_daily_limit, 10) || 0) : (await pricingService.settings()).price_request_daily_limit;
}

async function validDays(associate) {
  return isSet(associate.price_valid_days)
    ? Math.max(1, parseInt(associate.price_valid_days, 10) || 0) : (await pricingService.settings()).price_request_valid_days;
}

/** { category id: allowed forms ([] = all forms of that category) } */
async function access(associateId) {
  const out = {};
  for (const r of await db.query('SELECT category_id, forms FROM associate_price_access WHERE associate_id = :a', { a: associateId })) {
    out[Number(r.category_id)] = productSpecs.splitForms(r.forms);
  }
  return out;
}

async function saveAccess(associateId, acc) {
  await db.execute('DELETE FROM associate_price_access WHERE associate_id = :a', { a: associateId });
  for (const [categoryId, forms] of Object.entries(acc)) {
    await db.execute('INSERT INTO associate_price_access (associate_id, category_id, forms) VALUES (:a, :c, :f)',
      { a: associateId, c: Number(categoryId), f: forms.length ? forms.join(',') : null });
  }
}

/** The forms of this product the associate may price ([] = none, null = all its forms). */
async function allowedForms(acc, product) {
  const categoryId = await productSpecs.categoryIdFor(product);
  if (categoryId === null || !Object.prototype.hasOwnProperty.call(acc, categoryId)) return [];
  const allowed = acc[categoryId];
  const forms = productSpecs.productForms(product);
  if (!allowed.length) return null;
  if (!forms.length) return allowed;
  return forms.filter((f) => allowed.includes(f));
}

async function mayPrice(associate, product) {
  if (Number(product.is_active ?? 0) !== 1) return false;
  const forms = await allowedForms(await access(Number(associate.id)), product);
  return forms === null || forms.length > 0;
}

/** The catalogue: products the associate may price — no prices, supplier names or quarry locations. */
async function catalogue(associate) {
  const acc = await access(Number(associate.id));
  if (!Object.keys(acc).length) return [];
  const out = [];
  for (const p of await productRepository.all(false)) {
    if (Number(p.is_active ?? 0) !== 1) continue;
    const forms = await allowedForms(acc, p);
    if (forms !== null && !forms.length) continue;
    let finishes = [];
    for (const s of await productSpecs.offered(p)) {
      if (s.name.toLowerCase().includes('finish')) finishes = s.options.map((o) => o.name);
    }
    const formNames = (forms ?? productSpecs.productForms(p)).map((f) => productSpecs.FORMS[f]);
    out.push({ id: Number(p.id), name: String(p.name), description: String(p.specifications ?? ''), forms: formNames, finishes });
  }
  return out;
}

async function requestsToday(associateId, c = db) {
  const r = await c.queryOne("SELECT COUNT(*) AS n FROM price_requests WHERE associate_id = :a AND kind = 'auto' AND requested_at >= :d",
    { a: associateId, d: `${ymd()} 00:00:00` });
  return Number(r.n);
}

async function leftToday(associate) {
  return Math.max(0, (await dailyLimit(associate)) - (await requestsToday(Number(associate.id))));
}

/** The associate's floor and quote from a build (staff figures with no level). */
function priceFrom(build, level) {
  if (!level) return { floor: build.staff_floor, quote: build.staff_quote };
  return pricingEngine.associate(build.raw, String(level.kind), Number(level.value));
}

function engineInput(p, adjust) {
  const e = p.effective;
  return {
    supplier_inr: Number(p.supplier_inr), fx_rate: p.settings.pricing_fx_rate,
    other_mode: e.other_mode, other_value: e.other_value, margin_pct: e.margin_pct,
    incentive_mode: e.incentive_mode, incentive_value: e.incentive_value, program_on: p.program_on,
    buffer_pct: e.buffer_pct, adjust,
  };
}

/** Each specification's base option. */
function baseMap(offeredSpecs) {
  const map = {};
  for (const s of offeredSpecs) for (const o of s.options) if (o.is_base) map[s.id] = o.id;
  return map;
}

const pctText = (v) => `${v < 0 ? '−' : '+'}${Math.abs(v).toFixed(2).replace(/0+$/, '').replace(/\.$/, '')}%`;

/**
 * The associate's price for a product and choice of options, worked out now (same keys as PHP):
 * ok, manual, reason, quote, lowest, unit, per_m2, m2_per_cbm, breakdown, charges, figures, text, map, level_name.
 */
async function work(associate, product, selection) {
  const associateCommission = require('./associateCommission');
  const [level] = await associateCommission.levelForAssociate(associate, null);
  const p = await pricingService.forProduct(product, [], null, null, selection || {});
  const out = {
    ok: false, manual: !!p.manual, reason: p.missing, map: p.spec ? p.spec.map : {},
    text: p.spec ? p.spec.text : '', unit: p.unit, level_name: level ? `${level.name} — ${pricingService.levelLabel(level)}` : null,
    charges: p.spec ? p.spec.adj.charges : [],
  };
  if (p.build === null) {
    // No supplier price at all: staff price it by hand.
    if (!out.manual && p.spec !== null && !(p.spec.errors || []).length) out.manual = true;
    return out;
  }
  const prices = priceFrom(p.build, level);
  const adj = p.spec.adj;
  const breakdown = [{ label: `Base: ${productSpecs.describe(p.spec.offered, baseMap(p.spec.offered), true) || 'standard specification'}`, change: '—' }];
  if (p.exact) breakdown.push({ label: `Exact price for ${p.exact}`, change: 'used' });
  for (const l of adj.lines) {
    if (l.kind === 'exact') {
      breakdown.push({ label: `${l.option} (${l.spec.toLowerCase()})`, change: 'Included (in the exact price)' });
    } else if (l.kind === 'included') {
      breakdown.push({ label: `${l.option} (${l.spec.toLowerCase()})`, change: 'Included in base price' });
    } else if (l.kind === 'pct') {
      breakdown.push({ label: `${l.option} (${l.spec.toLowerCase()})`, change: pctText(l.value) });
    } else {
      // The effect of this amount on the associate's quote price: worked out with and without it.
      const without = pricingEngine.build(engineInput(p, { pct: adj.pct, usd: adj.usd - Number(l.usd_effect), qty_pct: adj.qty_pct }));
      const effect = prices.quote - priceFrom(without, level).quote;
      breakdown.push({ label: `${l.option} (${l.spec.toLowerCase()})`, change: `${effect < 0 ? '−' : '+'}USD ${money(Math.abs(effect))} on this price${adj.basis === 'cbm' ? ' per CBM' : ''}` });
    }
  }
  const b = p.build;
  out.ok = true;
  out.quote = prices.quote;
  out.lowest = prices.floor;
  out.per_m2 = adj.basis === 'cbm' && adj.thickness_mm ? pricingEngine.round2(prices.quote * adj.thickness_mm / 1000) : null;
  out.m2_per_cbm = adj.basis === 'cbm' && adj.thickness_mm ? Math.round((1000 / adj.thickness_mm) * 100) / 100 : null;
  out.breakdown = breakdown;
  out.figures = {
    cost: b.cost, minimum: b.minimum, reserve: b.reserve, staff_floor: b.staff_floor, staff_quote: b.staff_quote,
    floor: prices.floor, quote: prices.quote, unit: p.unit,
    level_id: level ? Number(level.id) : null, level_name: level ? level.name : null,
    level_kind: level ? level.kind : null, level_value: level ? Number(level.value) : null,
    margin_pct: p.effective.margin_pct, buffer_pct: p.effective.buffer_pct,
    spec_text: p.spec.text, from_price_request: true,
  };
  return out;
}

/** The next request number: PR-YY-DD-NNNN (NNNN counts through the year). */
async function nextNumber(c = db) {
  const now = new Date();
  const year = now.getFullYear();
  const r = await c.queryOne('SELECT COUNT(*) AS n FROM price_requests WHERE requested_at >= :a AND requested_at < :b',
    { a: `${year}-01-01 00:00:00`, b: `${year + 1}-01-01 00:00:00` });
  return `PR-${String(year).slice(-2)}-${p2(now.getDate())}-${String(Number(r.n) + 1).padStart(4, '0')}`;
}

const INSERT_KEYS = ['number', 'associate_id', 'product_id', 'selection', 'selection_text', 'other_note', 'kind', 'status', 'unit', 'quote', 'lowest',
  'figures', 'breakdown', 'level_name', 'valid_until', 'requested_at', 'due_at'];

async function insert(row, c = db) {
  for (let attempt = 0; attempt < 5; attempt++) {
    row.number = await nextNumber(c);
    if (attempt > 0) row.number = row.number.slice(0, 9) + String(parseInt(row.number.slice(9), 10) + attempt).padStart(4, '0');
    try {
      const params = {};
      for (const k of INSERT_KEYS) params[k] = row[k] ?? null;
      const r = await c.execute(
        `INSERT INTO price_requests (${INSERT_KEYS.join(', ')}) VALUES (${INSERT_KEYS.map((k) => `:${k}`).join(', ')})`, params);
      // D-257: a price request belongs to its associate's division.
      await c.execute('UPDATE price_requests r JOIN associates a ON a.id = r.associate_id SET r.division_id = a.division_id WHERE r.id = :id AND r.division_id <> a.division_id', { id: r.insertId });
      return Number(r.insertId);
    } catch (e) {
      if (!String(e.message).includes('Duplicate') || attempt === 4) throw e;
    }
  }
  return 0;
}

/** An associate asks for a price. Returns [request id, error]. */
async function create(associate, product, selection, other, note) {
  if (!(await mayPrice(associate, product))) return [null, 'You cannot ask for prices for this product.'];
  note = note !== null && note !== undefined ? String(note).trim() : null;
  if (note !== null && [...note].length > 500) return [null, 'Keep the note to 500 characters.'];
  const w = await work(associate, product, selection);
  if (!w.ok && !w.manual) return [null, String(w.reason ?? 'That choice of options cannot be priced.')];
  const now = new Date();
  const nowText = ymdhis(now);
  const row = {
    associate_id: Number(associate.id), product_id: Number(product.id),
    selection: JSON.stringify(w.map), selection_text: [...(w.text !== '' ? w.text : 'Standard specification')].slice(0, 500).join(''),
    other_note: note !== '' ? note : null, requested_at: nowText, level_name: w.level_name,
    unit: w.unit, quote: null, lowest: null, figures: null, breakdown: null, valid_until: null, due_at: null,
  };
  if (other || w.manual || !w.ok) {
    if (other && (note === null || [...note].length < 10)) return [null, 'Tell our team what the buyer needs (at least 10 characters).'];
    row.kind = 'manual';
    row.status = 'pending';
    row.due_at = ymdhis(new Date(now.getTime() + 24 * 3600 * 1000));
    if (!other && w.reason) row.other_note = `${row.other_note ?? ''} [${w.reason}]`.trim();
    const id = await insert(row);
    const number = (await find(id)).number;
    for (const userId of await permissionRepository.usersWithPermission('manage_pricing')) {
      await notificationRepository.create(userId, null, 'price_request', null, // D-280: one e-mail per approval
        `Price request ${number} from ${associate.name} needs a manual price: ${product.name} — ${row.selection_text}.`);
    }
    await auditLogRepository.log(null, 'PRICE_REQUEST_MANUAL', 'price_requests', id, null, null, `${number}: ${product.name} — ${row.selection_text}`);
    return [id, null];
  }
  const limit = await dailyLimit(associate);
  const refusal = `You have used today's ${limit} price requests. Ask NexaCrest if you need more today.`;
  if ((await leftToday(associate)) <= 0) return [null, refusal];
  row.kind = 'auto';
  row.status = 'valid';
  row.quote = Number(w.quote).toFixed(2);
  row.lowest = Number(w.lowest).toFixed(2);
  row.figures = JSON.stringify(w.figures);
  row.breakdown = JSON.stringify({ lines: w.breakdown, charges: w.charges, per_m2: w.per_m2, m2_per_cbm: w.m2_per_cbm });
  row.valid_until = addDays(ymd(now), await validDays(associate));
  // Fresh-eyes review L4: the count and the insert run under a lock on the associate's row,
  // so requests fired at the same moment cannot pass the daily limit together.
  const id = await db.transaction(async (c) => {
    await c.queryOne('SELECT id FROM associates WHERE id = :a FOR UPDATE', { a: Number(associate.id) });
    if (limit - (await requestsToday(Number(associate.id), c)) <= 0) return null;
    return insert(row, c);
  });
  if (id === null) return [null, refusal];
  await auditLogRepository.log(null, 'PRICE_REQUEST_AUTO', 'price_requests', id, null, null,
    `${(await find(id)).number}: ${product.name} — ${row.selection_text} → ${row.quote} / ${row.lowest} per ${row.unit}`);
  return [id, null];
}

/** Manual requests waiting for a price (shown beside the "Price requests" link). */
async function pendingCount() {
  return Number((await db.queryOne(`SELECT COUNT(*) AS n FROM price_requests WHERE status = 'pending'${require('./divisionService').sql('division_id')}`)).n);
}

async function find(id) {
  return db.queryOne('SELECT * FROM price_requests WHERE id = :id', { id });
}

async function findByNumber(number) {
  return db.queryOne('SELECT * FROM price_requests WHERE number = :n', { n: String(number).trim().toUpperCase() });
}

/** "valid" | "expired" | "pending" | "priced" | "ordered" | "refused" as shown. */
function displayStatus(r) {
  if (['valid', 'priced'].includes(r.status) && r.valid_until && String(r.valid_until).slice(0, 10) < ymd()) return 'expired';
  return String(r.status);
}

/** An associate's own requests, newest first. */
async function forAssociate(associateId, limit = 50) {
  return db.query(
    `SELECT r.*, p.name AS product_name FROM price_requests r JOIN catalog_products p ON p.id = r.product_id
     WHERE r.associate_id = :a ORDER BY r.id DESC LIMIT ${parseInt(limit, 10) || 50}`, { a: associateId });
}

/** The staff queue: manual ones waiting first, then the newest. */
async function queue(filter = 'all', limit = 200) {
  let where = '';
  if (filter === 'pending') where = "WHERE r.status = 'pending'";
  else if (filter === 'auto') where = "WHERE r.kind = 'auto'";
  else if (filter === 'manual') where = "WHERE r.kind = 'manual'";
  return db.query(
    `SELECT r.*, p.name AS product_name, a.name AS associate_name FROM price_requests r
     JOIN catalog_products p ON p.id = r.product_id JOIN associates a ON a.id = r.associate_id
     ${where || 'WHERE 1 = 1'}${require('./divisionService').sql('r.division_id')} ORDER BY (r.status = 'pending') DESC, r.id DESC LIMIT ${parseInt(limit, 10) || 200}`);
}

/** Requests against orders per associate over the last 90 days. */
async function report(today = null) {
  today = today || ymd();
  const from = `${addDays(today, -90)} 00:00:00`;
  const rows = await db.query(
    `SELECT a.id, a.name,
            (SELECT COUNT(*) FROM price_requests r WHERE r.associate_id = a.id AND r.requested_at >= :f1) AS requests,
            (SELECT COUNT(*) FROM orders o JOIN clients c ON c.id = o.client_id
              WHERE c.associate_id = a.id AND o.created_at >= :f2 AND o.is_test_data = 0${require('./divisionService').sql('o.division_id')}) AS orders_count
       FROM associates a
      WHERE EXISTS (SELECT 1 FROM price_requests r WHERE r.associate_id = a.id AND r.requested_at >= :f3)${require('./divisionService').sql('a.division_id')}
      ORDER BY requests DESC, a.name`, { f1: from, f2: from, f3: from }); // D-257 (fresh-eyes review L1): the associates of the request's division(s)
  return rows.map((r) => {
    const req = Number(r.requests);
    const ord = Number(r.orders_count);
    return {
      associate_id: Number(r.id), name: String(r.name), requests: req, orders: ord,
      per_order: ord > 0 ? Math.round((req / ord) * 10) / 10 : null, warn: ord === 0 && req >= WARN_REQUESTS,
    };
  });
}

/** Staff price a manual request. Returns an error or null. */
async function priceManual(id, userId, quote, lowest, unit, note) {
  const r = await find(id);
  if (!r || r.status !== 'pending') return 'That price request is not waiting for a price.';
  quote = String(quote ?? '');
  lowest = String(lowest ?? '');
  const q = Number(quote);
  const l = Number(lowest);
  if (!/^\d+(\.\d{1,2})?$/.test(quote) || !/^\d+(\.\d{1,2})?$/.test(lowest) || !(q > 0) || !(l > 0) || l > q) {
    return 'Enter the quote price and the lowest price in USD (at most 2 decimals, lowest not above the quote).';
  }
  unit = String(unit ?? '').trim();
  if (unit === '' || [...unit].length > 20) return 'Say what unit the price is per (e.g. SQM or CBM).';
  note = String(note ?? '').trim();
  if ([...note].length > 500) return 'Keep the note to 500 characters.';
  const associateRepository = require('../repositories/associateRepository');
  const associate = await associateRepository.find(Number(r.associate_id));
  const validUntil = addDays(ymd(), await validDays(associate || {}));
  const round = (v) => Math.round(v * 100) / 100;
  const figures = { quote: round(q), floor: round(l), minimum: round(l), unit, manual: true, from_price_request: true };
  await db.execute(
    `UPDATE price_requests SET status = 'priced', quote = :q, lowest = :l, unit = :u, price_note = :n, priced_by = :by, priced_at = NOW(),
            valid_until = :v, figures = :f, breakdown = :b WHERE id = :id AND status = 'pending'`,
    {
      q: q.toFixed(2), l: l.toFixed(2), u: unit, n: note !== '' ? note : null, by: userId, v: validUntil, f: JSON.stringify(figures),
      b: JSON.stringify({ lines: [{ label: `Priced by NexaCrest${note !== '' ? `: ${note}` : ''}`, change: '—' }], charges: [] }), id,
    }
  );
  await auditLogRepository.log(userId, 'PRICE_REQUEST_PRICED', 'price_requests', id, null, null, `${r.number}: USD ${quote} / ${lowest} per ${unit}`);
  if (associate) {
    const associateService = require('./associateService');
    const dates = require('../helpers/dates');
    await associateService.notify(associate, 'price_ready', `Your price request ${r.number} has a price: quote USD ${money(q)}, lowest USD ${money(l)}`
      + ` per ${unit}, valid until ${dates.short(validUntil)}.`);
  }
  return null;
}

/** A price request an order line may use. Returns [request, error]. */
async function usableForOrder(number, order) {
  const r = await findByNumber(number);
  if (!r) return [null, 'That price request number was not found.'];
  if (!['valid', 'priced'].includes(displayStatus(r))) return [null, `Price request ${r.number} cannot be used: it is ${displayStatus(r)}.`];
  const clientRepository = require('../repositories/clientRepository');
  const client = order.client_id ? await clientRepository.find(Number(order.client_id)) : null;
  if (!client || Number(client.associate_id || 0) !== Number(r.associate_id)) return [null, `Price request ${r.number} belongs to another associate's buyers.`];
  if (String(order.currency_code || '').toUpperCase() !== 'USD') return [null, 'Price requests are in USD — this order is in another currency.'];
  return [r, null];
}

async function markOrdered(id, orderId) {
  await db.execute("UPDATE price_requests SET status = 'ordered', order_id = :o WHERE id = :id AND status IN ('valid','priced')", { o: orderId, id });
}

module.exports = {
  WARN_REQUESTS, dailyLimit, validDays, access, saveAccess, allowedForms, mayPrice, catalogue, requestsToday, leftToday, work,
  create, pendingCount, find, findByNumber, displayStatus, forAssociate, queue, report, priceManual, usableForOrder, markOrdered,
};
