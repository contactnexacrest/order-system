'use strict';

const salesTargetRepository = require('../repositories/salesTargetRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const reasonValidator = require('../helpers/reasonValidator');
const dates = require('../helpers/dates');
const logger = require('../helpers/logger');
const { phpRound } = require('../helpers/money');
const { todayIst, formatForDb } = require('../helpers/dbDateTime');

/**
 * Owner decision D-378 (15 Nov 2026): sales targets — the rules.
 * Build spec: docs/decisions/D-378-sales-targets-spec.md.
 *
 *  - Periods: month; quarter (Jan–Mar …); half (Apr–Sep / Oct–Mar); year (Apr–Mar, Indian FY).
 *  - An order counts for its salesperson when it is CONFIRMED: the earliest of the first advance
 *    received, the LC received, or (no-advance terms only) the buyer PO received AND the PI approved
 *    (the later of the two). Containers = the order's estimated containers, at least 1.
 *  - sync() keeps a snapshot (sales_target_credits) of each confirmed order's dates. Corrections are
 *    dated events: −confirmed when the order is cancelled / lost later, (shipped − confirmed) when
 *    the CI shows another number of containers. A period's count is the sum of the events dated in
 *    it, so a closed period is never rewritten.
 *  - Credit: orders.sales_credit_user_id (moved by the MD / ED), else the order's Sales & Export
 *    owner, else Unassigned — resolved when read. Associates: the order's earning associate.
 *  - Company target (money): (monthly costs + profit) × months; earned = profit after incentive of
 *    the orders shipped in the period; containers needed from the average margin per container of
 *    the last 3 months' shipments.
 *  - Personal targets: own, else the designation default, with a build-up for new joiners.
 *  - Daily alerts (checkAlerts): behind, review due, order with no salesperson.
 *
 * This service only READS order data. It never changes a price, incentive, commission, payout,
 * document, Zoho record or order gate.
 *
 * Credit rows, owners, users and orders are Maps keyed by id, in the order PHP's keyed arrays
 * keep. Port of App\Services\SalesTargetService.
 */

const PERMISSION = 'manage_sales_targets';

/** Setting key => default value (stored NULL recipients = these defaults). */
const DEFAULTS = {
  period: 'quarter',
  multiple: '3',
  buildup_months: '3',
  review_months: '12',
  alert_pct: '60',
  alert_behind_recipients: null,
  alert_review_recipients: null,
  alert_no_owner_recipients: null,
};

/** Period type => label for the settings drop-down. */
const PERIOD_TYPES = {
  month: 'Month',
  quarter: 'Quarter (Jan–Mar, Apr–Jun, Jul–Sep, Oct–Dec)',
  half: 'Half-year (Apr–Sep, Oct–Mar)',
  year: 'Year (Apr–Mar)',
};

/** Mark Lost: the optional category (stored key => label). */
const LOST_CATEGORIES = {
  price: 'Price',
  quality: 'Quality',
  delivery_time: 'Delivery time',
  competitor: 'Competitor',
  buyer_postponed: 'Buyer postponed',
  other: 'Other',
};

/** Alert kind => [settings key, label on Admin › E-mail settings, default roles]. */
const ALERTS = {
  behind: ['alert_behind_recipients', 'Sales target — the company is behind its target', ['Managing Director', 'Executive Director']],
  review: ['alert_review_recipients', "Sales target — a person's target is due for review", ['Managing Director', 'Executive Director']],
  no_owner: ['alert_no_owner_recipients', 'Sales target — a confirmed order has no salesperson', ['Managing Director', 'Sales/Export Manager']],
};

const MONTHS = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const has = (obj, key) => Object.prototype.hasOwnProperty.call(obj, key);

/**
 * order id => profit after incentive, cached until resetCache() (PHP: one request). The Targets
 * page, the cockpit box and the daily alerts reset it when they start.
 */
let profitCache = null;

function resetCache() {
  profitCache = null;
}

// ================================================================ small helpers (PHP semantics)

/** PHP empty(): null, '', '0', 0, false, [] are empty. */
function phpEmpty(v) {
  return v === undefined || v === null || v === '' || v === '0' || v === 0 || v === false || (Array.isArray(v) && v.length === 0);
}

/** PHP is_numeric() for a form value. */
function isNumeric(s) {
  return /^\s*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?\s*$/.test(String(s));
}

/** PHP rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.'): 6 → "6", 4.5 → "4.5". */
function num1(v) {
  return phpRound(Number(v) || 0, 1).toFixed(1).replace(/0+$/, '').replace(/\.$/, '');
}

/** The values of a Map, an array or a keyed object. */
function vals(x) {
  if (x === null || x === undefined) return [];
  if (x instanceof Map) return [...x.values()];
  if (Array.isArray(x)) return x.filter((v) => v !== undefined);
  return Object.values(x);
}

/** A Map from a Map, an array of credit rows (keyed by order_id) or a keyed object. */
function creditMap(x) {
  if (x instanceof Map) return x;
  const m = new Map();
  for (const c of vals(x)) m.set(Number(c.order_id), c);
  return m;
}

function mapGet(map, key) {
  if (!map) return undefined;
  return map instanceof Map ? map.get(Number(key)) : map[key];
}

/** owner of an order id (null when Unassigned or unknown). */
function ownerOf(owners, orderId) {
  const v = mapGet(owners, orderId);
  return v === undefined ? null : v;
}

function pad2(n) {
  return String(n).padStart(2, '0');
}

function parts(ymdStr) {
  const [y, m, d] = String(ymdStr).slice(0, 10).split('-').map(Number);
  return [y, m, d];
}

function utc(ymdStr) {
  const [y, m, d] = parts(ymdStr);
  return Date.UTC(y, m - 1, d);
}

function fromUtc(ms) {
  const dt = new Date(ms);
  return `${dt.getUTCFullYear()}-${pad2(dt.getUTCMonth() + 1)}-${pad2(dt.getUTCDate())}`;
}

/** PHP DateTimeImmutable::modify("+n months") (day overflow carries into the next month, like PHP). */
function addMonths(ymdStr, n) {
  const [y, m, d] = parts(ymdStr);
  return fromUtc(Date.UTC(y, m - 1 + n, d));
}

function addDays(ymdStr, n) {
  return fromUtc(utc(ymdStr) + n * 86400000);
}

/** Whole days from a to b (b − a), signed. */
function dayDiff(a, b) {
  return Math.round((utc(b) - utc(a)) / 86400000);
}

const inRange = (d, from, to) => d !== null && d !== undefined && d !== '' && String(d) >= from && String(d) <= to;

// ================================================================ who sees what

/** Whether the signed-in person (their permissions) may open the Targets page, its settings and move a credit. */
function canManage(permissions) {
  return !!(permissions && permissions[PERMISSION]);
}

/** Money figures (₹, margin, average margin per container): manage_sales_targets AND view_price_buildup. */
function canSeeMoney(permissions) {
  return canManage(permissions) && !!permissions.view_price_buildup;
}

// ================================================================ settings

/** @returns {Promise<Object<string, ?string>>} every setting, stored value or default */
async function settings() {
  let stored = {};
  try {
    stored = await salesTargetRepository.settings();
  } catch (e) {
    // A database before migration 2026-11-15a: defaults.
  }
  const out = { ...DEFAULTS };
  for (const [k, v] of Object.entries(stored)) {
    if (has(out, k)) out[k] = v;
  }
  if (!has(PERIOD_TYPES, String(out.period))) out.period = 'quarter';
  return out;
}

/**
 * Checks the settings form. Empty company costs and profit together switch the company target off.
 *
 * @returns {[object, string[]]} [clean values, errors]
 */
function validateSettings(post, withMoney) {
  post = post || {};
  const errors = [];
  const v = {};
  const period = String(post.period ?? '');
  if (!has(PERIOD_TYPES, period)) errors.push('Choose the target period.');
  v.period = period;
  const multiple = String(post.multiple ?? '').trim();
  if (!/^\d{1,2}(\.\d)?$/.test(multiple) || Number(multiple) < 1 || Number(multiple) > 10) {
    errors.push('The target multiple is a number from 1 to 10 with at most one decimal (e.g. 3 or 2.5).');
  }
  v.multiple = multiple;
  for (const [k, [min, max, label]] of Object.entries({ buildup_months: [0, 6, 'Build-up months'], review_months: [3, 24, 'Review every … months'], alert_pct: [1, 100, 'Alert below … %'] })) {
    const raw = String(post[k] ?? '').trim();
    if (!/^\d+$/.test(raw) || parseInt(raw, 10) < min || parseInt(raw, 10) > max) {
      errors.push(`${label}: enter a whole number from ${min} to ${max}.`);
    }
    v[k] = raw;
  }
  if (withMoney) {
    for (const [k, label] of Object.entries({ costs_monthly: 'Company costs per month', profit_monthly: 'Profit wanted per month' })) {
      const raw = String(post[k] ?? '').trim().replace(/,/g, '');
      if (raw !== '' && (!isNumeric(raw) || Number(raw) < 0 || Number(raw) > 999999999999.99)) {
        errors.push(`${label}: enter an amount in ₹ (0 or more), or leave it empty.`);
      }
      v[k] = raw === '' ? null : phpRound(isNumeric(raw) ? Number(raw) : (parseFloat(raw) || 0), 2);
    }
    const from = String(post.effective_from ?? '').trim();
    if (from !== '' && !/^\d{4}-(0[1-9]|1[0-2])$/.test(from)) errors.push('Effective from: choose a month.');
    v.effective_from = from === '' ? null : `${from}-01`;
    if ((v.costs_monthly !== null || v.profit_monthly !== null) && v.effective_from === null) {
      errors.push('Effective from: choose the month the company target starts.');
    }
  }
  // Designation defaults: designation[<id>][containers|new_buyers|quotations], empty = none.
  v.designations = new Map();
  const posted = post.designation && typeof post.designation === 'object' ? post.designation : {};
  for (const [id, row] of Object.entries(posted)) {
    if (!/^\d+$/.test(String(id)) || row === null || typeof row !== 'object') continue;
    const c = String(row.containers ?? '').trim();
    const nb = String(row.new_buyers ?? '').trim();
    const q = String(row.quotations ?? '').trim();
    if (c !== '' && !/^\d{1,4}(\.\d)?$/.test(c)) {
      errors.push('Designation containers: a number with at most one decimal (e.g. 6 or 4.5).');
    }
    for (const n of [nb, q]) {
      if (n !== '' && (!/^\d+$/.test(n) || parseInt(n, 10) > 9999)) {
        errors.push('Designation new buyers and quotations: whole numbers.');
        break;
      }
    }
    v.designations.set(parseInt(id, 10), {
      containers: c === '' ? null : phpRound(parseFloat(c) || 0, 1),
      new_buyers: nb === '' ? null : (parseInt(nb, 10) || 0),
      quotations: q === '' ? null : (parseInt(q, 10) || 0),
    });
  }
  return [v, [...new Set(errors)]];
}

/** PHP json_encode of a designation's values (containers is a float: 6 → 6.0). */
function designationJson(d) {
  const f = (x) => (x === null ? 'null' : (Number.isInteger(x) ? `${x}.0` : String(x)));
  const i = (x) => (x === null ? 'null' : String(x));
  return `{"containers":${f(d.containers)},"new_buyers":${i(d.new_buyers)},"quotations":${i(d.quotations)}}`;
}

/**
 * Saves the settings form (values already validated). Every change is in the Audit Log as
 * SALES_TARGET_SETTINGS_CHANGED, with the reason.
 *
 * @returns {Promise<string[]>} what changed
 */
async function saveSettings(v, withMoney, userId, reason) {
  const changes = [];
  const current = await settings();
  for (const k of ['period', 'multiple', 'buildup_months', 'review_months', 'alert_pct']) {
    let nv = String(v[k]);
    if (k === 'multiple') nv = num1(nv);
    if (String(current[k] ?? '') === nv) continue;
    await salesTargetRepository.saveSetting(k, nv, userId);
    await auditLogRepository.log(userId, 'SALES_TARGET_SETTINGS_CHANGED', 'sales_target_settings', null, k, String(current[k] ?? ''), nv, reason);
    changes.push(k);
  }
  if (withMoney) {
    const row = await salesTargetRepository.latestCompanyRow();
    const old = row === null ? null : [
      String(row.effective_from).slice(0, 10),
      row.costs_monthly !== null ? phpRound(Number(row.costs_monthly), 2) : null,
      row.profit_monthly !== null ? phpRound(Number(row.profit_monthly), 2) : null,
    ];
    const nw = [v.effective_from, v.costs_monthly, v.profit_monthly];
    const emptyNew = nw[1] === null && nw[2] === null;
    const same = old !== null && old[0] === nw[0] && old[1] === nw[1] && old[2] === nw[2];
    if ((old === null && !emptyNew) || (old !== null && !same && !(emptyNew && old[1] === null && old[2] === null))) {
      const from = nw[0] ?? `${todayIst().slice(0, 7)}-01`;
      const id = await salesTargetRepository.insertCompanyRow(from, nw[1], nw[2], reason, userId);
      const fmt = (r) => (r === null ? null : `from ${r[0]}: costs ${r[1] ?? '—'}, profit ${r[2] ?? '—'} per month`);
      await auditLogRepository.log(userId, 'SALES_TARGET_SETTINGS_CHANGED', 'sales_target_company', id, 'company_target', fmt(old), fmt([from, nw[1], nw[2]]), reason);
      changes.push('company target');
    }
  }
  const designationTargets = await salesTargetRepository.targetsByScope('designation');
  for (const [id, d] of v.designations || new Map()) {
    const cur = designationTargets.get(id) || null;
    const curVals = {
      containers: cur !== null && cur.containers !== null ? phpRound(Number(cur.containers), 1) : null,
      new_buyers: cur !== null && cur.new_buyers !== null ? parseInt(cur.new_buyers, 10) : null,
      quotations: cur !== null && cur.quotations !== null ? parseInt(cur.quotations, 10) : null,
    };
    if (curVals.containers === d.containers && curVals.new_buyers === d.new_buyers && curVals.quotations === d.quotations) continue;
    if (cur === null && d.containers === null && d.new_buyers === null && d.quotations === null) continue;
    await salesTargetRepository.saveTarget('designation', id, { ...d, reason }, userId);
    await auditLogRepository.log(userId, 'SALES_TARGET_SETTINGS_CHANGED', 'sales_targets', id, 'designation_default',
      designationJson(curVals), designationJson(d), reason);
    changes.push('designation default');
  }
  return [...new Set(changes)];
}

// ================================================================ periods

/**
 * The period of `type` that holds `date`.
 *
 * @returns {{type:string, start:string, end:string, months:number, label:string, key:string}}
 */
function period(type, date) {
  const [y, m] = parts(date);
  let sy;
  let sm;
  let len;
  switch (type) {
    case 'month':
      [sy, sm, len] = [y, m, 1];
      break;
    case 'half':
      [sy, sm, len] = m >= 4 && m <= 9 ? [y, 4, 6] : [m >= 10 ? y : y - 1, 10, 6];
      break;
    case 'year':
      [sy, sm, len] = [m >= 4 ? y : y - 1, 4, 12];
      break;
    case 'quarter':
    default:
      type = 'quarter';
      [sy, sm, len] = [y, Math.floor((m - 1) / 3) * 3 + 1, 3];
      break;
  }
  const start = `${sy}-${pad2(sm)}-01`;
  const last = addMonths(start, len - 1);
  const [ly, lm] = parts(last);
  const end = fromUtc(Date.UTC(ly, lm, 0));
  let label;
  if (len === 1) {
    label = `${MONTHS[sm]} ${sy}`;
  } else if (ly === sy) {
    label = `${MONTHS[sm]}–${MONTHS[lm]} ${sy}`;
  } else {
    label = `${MONTHS[sm]} ${sy}–${MONTHS[lm]} ${ly}`;
  }
  return { type, start, end, months: len, label, key: start };
}

/** The period just before `p`. */
function previous(p) {
  return period(p.type, addDays(p.start, -1));
}

/** The current period and the count − 1 before it, newest first. */
function recentPeriods(type, today, count = 4) {
  const out = [period(type, today)];
  while (out.length < count) out.push(previous(out[out.length - 1]));
  return out;
}

/** The first day of each month of the period. */
function monthsOf(p) {
  const out = [];
  for (let i = 0; i < p.months; i += 1) out.push(addMonths(p.start, i));
  return out;
}

// ================================================================ confirmation and sync

/**
 * When (and how) an order became confirmed, from the facts salesTargetRepository.orderFacts()
 * reads: the earliest of advance, LC and (no-advance terms only) PO + PI approved.
 *
 * @returns {[?string, ?string]} [date, 'advance'|'lc'|'po_pi'], or [null, null]
 */
function confirmation(f) {
  const cands = [];
  let adv = f.advance_receipt_on ?? null;
  if (adv === null || adv === '') adv = f.advance_status_on ?? null;
  if (adv !== null && adv !== '') cands.push(['advance', String(adv).slice(0, 10)]);
  if (!phpEmpty(f.lc_received_on)) cands.push(['lc', String(f.lc_received_on).slice(0, 10)]);
  if ((Number(f.advance_pct ?? 0) || 0) === 0 && !phpEmpty(f.buyer_po_received_on) && !phpEmpty(f.frozen_on)) {
    const po = String(f.buyer_po_received_on).slice(0, 10);
    const pi = String(f.frozen_on).slice(0, 10);
    cands.push(['po_pi', po > pi ? po : pi]);
  }
  let best = [null, null];
  for (const [how, date] of cands) {
    if (best[0] === null || date < best[0]) best = [date, how];
  }
  return best;
}

/** The order's estimated containers from its goods lines, at least 1. */
async function estimatedContainers(orderId) {
  const orderLinePricing = require('./orderLinePricing');
  const lines = await require('../repositories/orderProductRepository').forOrder(orderId);
  const totals = orderLinePricing.totals(lines, (await orderLinePricing.forOrder(orderId)).lines);
  return Math.max(1, Math.trunc(Number(totals.containers) || 0));
}

/**
 * Brings sales_target_credits up to date (daily job, and before a page reads it). Never writes
 * order data. Existing rows keep their dates: a confirmed order keeps its confirmed_on; a
 * cancellation / loss adds reversed_on once; the CI adds shipped_on once.
 *
 * @returns {Promise<{inserted:number, shipped:number, reversed:number}>}
 */
async function sync(today = null) {
  today = today ?? todayIst();
  const credits = await salesTargetRepository.credits();
  const done = { inserted: 0, shipped: 0, reversed: 0 };
  for (const f of await salesTargetRepository.orderFacts()) {
    try {
      await syncOne(f, credits.get(Number(f.id)) || null, today, done);
    } catch (e) {
      // One order's odd data never stops the daily job; it is tried again on the next run.
      logger.error(`D-378 sales target sync skipped order ${Number(f.id)}`, e);
    }
  }
  await salesTargetRepository.refreshNewBuyers();
  return done;
}

/** sync() for one order: insert its credit row once, then add a reversal / shipment once each. */
async function syncOne(f, row, today, done) {
  const id = Number(f.id);
  const status = String(f.status);
  const stopped = status === 'lost' || status === 'cancelled';
  let stopOn = status === 'lost' ? (f.lost_on ?? null) : (status === 'cancelled' ? (f.cancelled_on ?? null) : null);
  stopOn = stopOn !== null && stopOn !== '' ? String(stopOn).slice(0, 10) : null;
  const maxStr = (a, b) => (String(a) > String(b) ? String(a) : String(b));
  if (row === null) {
    const [confirmedOn, how] = confirmation(f);
    if (confirmedOn === null) return;
    // Lost / cancelled before it was confirmed: never counts.
    if (stopped && stopOn !== null && stopOn < confirmedOn) return;
    row = {
      order_id: id,
      client_id: Number(f.client_id),
      confirmed_on: confirmedOn,
      how,
      containers_confirmed: await estimatedContainers(id),
      shipped_on: null,
      containers_shipped: null,
      reversed_on: stopped ? maxStr(stopOn ?? today, confirmedOn) : null,
      reversed_why: stopped ? status : null,
    };
    await salesTargetRepository.insertCredit(row);
    done.inserted += 1;
  } else if (row.reversed_on === null && stopped) {
    row = { ...row, reversed_on: maxStr(stopOn ?? today, String(row.confirmed_on)) };
    await salesTargetRepository.markReversed(id, row.reversed_on, status);
    done.reversed += 1;
  }
  if (row.shipped_on === null && row.reversed_on === null) {
    const ci = await salesTargetRepository.latestIssuedCi(id);
    if (ci !== null) {
      const count = await salesTargetRepository.shippingContainerCount(id);
      const shipped = Math.max(1, count !== null && count > 0 ? count : await estimatedContainers(id));
      const printed = require('../repositories/documentRepository').printedDate(ci);
      await salesTargetRepository.markShipped(id, String(printed).slice(0, 10), shipped);
      done.shipped += 1;
    }
  }
}

// ================================================================ counting

/**
 * The dated events of one credit row: +confirmed, −confirmed (reversal), shipped − confirmed.
 *
 * @returns {Array<{date:string, kind:string, containers:number}>}
 */
function events(c) {
  const cc = parseInt(c.containers_confirmed, 10) || 0;
  const out = [{ date: String(c.confirmed_on), kind: 'confirmed', containers: cc }];
  if (!phpEmpty(c.reversed_on)) {
    out.push({ date: String(c.reversed_on), kind: String(phpEmpty(c.reversed_why) ? 'reversed' : c.reversed_why), containers: -cc });
  } else if (!phpEmpty(c.shipped_on) && c.containers_shipped !== null && c.containers_shipped !== undefined && parseInt(c.containers_shipped, 10) !== cc) {
    out.push({ date: String(c.shipped_on), kind: 'shipped_difference', containers: parseInt(c.containers_shipped, 10) - cc });
  }
  return out;
}

/**
 * A period's numbers over some credit rows.
 *  counted     = sum of the events dated in the period (what the target is measured on)
 *  confirmed   = containers confirmed in the period (before corrections)
 *  shipped     = of the orders confirmed in the period and still standing, containers shipped
 *  awaiting    = of those, containers not shipped yet
 *  corrections = the correction events dated in the period (reversals, shipped differences)
 *  new_buyers  = first orders of a client confirmed in the period
 */
function periodCounts(credits, from, to) {
  const out = { counted: 0, confirmed: 0, shipped: 0, awaiting: 0, corrections: 0, new_buyers: 0, rows: [], correction_rows: [] };
  for (const c of vals(credits)) {
    for (const e of events(c)) {
      if (!inRange(e.date, from, to)) continue;
      out.counted += e.containers;
      if (e.kind === 'confirmed') {
        out.confirmed += e.containers;
      } else {
        out.corrections += e.containers;
        out.correction_rows.push({ ...e, order_id: Number(c.order_id) });
      }
    }
    if (inRange(String(c.confirmed_on), from, to)) {
      out.rows.push(c);
      if (Number(c.is_new_buyer) === 1) out.new_buyers += 1;
      if (phpEmpty(c.reversed_on)) {
        if (!phpEmpty(c.shipped_on)) {
          out.shipped += parseInt(c.containers_shipped, 10) || 0;
        } else {
          out.awaiting += parseInt(c.containers_confirmed, 10) || 0;
        }
      }
    }
  }
  return out;
}

/** @returns {Promise<Map<number, object>>} credit rows credited (resolved now) to userId; null = Unassigned */
async function creditsFor(userId, credits = null, owners = null) {
  credits = credits ?? await salesTargetRepository.credits();
  const map = creditMap(credits);
  owners = owners ?? await salesTargetRepository.creditUsers([...map.keys()]);
  const out = new Map();
  for (const [oid, c] of map) {
    if (ownerOf(owners, Number(c.order_id)) === userId) out.set(oid, c);
  }
  return out;
}

// ================================================================ personal targets

/** Build-up factor for month k since joining (joining month = 1) with N build-up months. */
function buildupFactor(k, n) {
  if (k < 1) return 0.0; // a month before joining
  if (n <= 1) return 1.0;
  if (k === 1) return 0.0;
  return k < n ? 0.5 : 1.0;
}

/** Months since joining, the joining month being 1 (0 or less = before joining). */
function monthsSinceJoining(joinedOn, monthStart) {
  const [jy, jm] = parts(joinedOn);
  const [my, mm] = parts(monthStart);
  return (my * 12 + mm) - (jy * 12 + jm) + 1;
}

/**
 * A person's target for a period: own (scope user) values, else the designation default;
 * containers × the average build-up factor of the period's months.
 */
async function personalTarget(userId, p, settingsIn = null, user = null) {
  const s = settingsIn ?? await settings();
  if (user === null) user = (await salesTargetRepository.usersByIds([userId])).get(Number(userId)) || { joined_on: null };
  const own = await salesTargetRepository.target('user', userId);
  const designation = await salesTargetRepository.designationFor(userId);
  const def = designation !== null ? await salesTargetRepository.target('designation', designation.id) : null;
  const pick = (col) => {
    if (own !== null && own[col] !== null && own[col] !== undefined) return String(own[col]);
    return def !== null && def[col] !== null && def[col] !== undefined ? String(def[col]) : null;
  };
  const base = pick('containers');
  const buildupOn = own === null || own.buildup_enabled === null || own.buildup_enabled === undefined || Number(own.buildup_enabled) === 1;
  const n = parseInt(s.buildup_months, 10) || 0;
  const factors = {};
  for (const month of monthsOf(p)) {
    factors[month] = (buildupOn && !phpEmpty(user.joined_on))
      ? buildupFactor(monthsSinceJoining(String(user.joined_on), month), n)
      : 1.0;
  }
  const list = Object.values(factors);
  const avg = list.length === 0 ? 1.0 : list.reduce((a, b) => a + b, 0) / list.length;
  const inBuildup = Math.min(...list) < 1.0;
  const ownMultiple = own !== null && own.multiple !== null && own.multiple !== undefined ? Number(own.multiple) : null;
  const nb = pick('new_buyers');
  let source = null;
  if (own !== null && own.containers !== null && own.containers !== undefined) source = 'own';
  else if (def !== null && def.containers !== null && def.containers !== undefined) source = 'designation';
  return {
    containers: base === null ? 0.0 : phpRound(Number(base) * avg, 1),
    base_containers: base === null ? null : Number(base),
    new_buyers: nb === null ? null : parseInt(nb, 10),
    quotations: def !== null && def.quotations !== null && def.quotations !== undefined ? parseInt(def.quotations, 10) : null,
    own_multiple: ownMultiple,
    multiple: ownMultiple ?? Number(s.multiple),
    buildup_enabled: buildupOn,
    factors,
    factor: phpRound(avg, 3),
    in_buildup: inBuildup,
    source,
    designation,
    reviewed_at: own !== null ? (own.reviewed_at ?? null) : null,
    own,
    joined_on: user.joined_on ?? null,
  };
}

// ================================================================ company target

/** Profit after incentive of one order (₹), cached until resetCache(). */
async function profit(orderId) {
  profitCache = profitCache ?? new Map();
  if (!profitCache.has(orderId)) {
    const r = await require('./orderProfitabilityService').computeForOrder(orderId);
    profitCache.set(orderId, Number((r && r.profit_after_incentive_inr) ?? 0) || 0);
  }
  return profitCache.get(orderId);
}

/**
 * Average margin per container: profit after incentive of the orders shipped in the 3 months up to
 * today ÷ their containers shipped; null when nothing shipped ("not enough history yet").
 */
async function avgMarginPerContainer(today, credits = null) {
  credits = credits ?? await salesTargetRepository.credits();
  const from = addMonths(today, -3);
  let total = 0.0;
  let containers = 0;
  for (const c of vals(credits)) {
    if (!phpEmpty(c.reversed_on) || phpEmpty(c.shipped_on) || String(c.shipped_on) <= from || String(c.shipped_on) > today) continue;
    total += await profit(Number(c.order_id));
    containers += parseInt(c.containers_shipped, 10) || 0;
  }
  return containers > 0 ? phpRound(total / containers, 2) : null;
}

/**
 * needed = (costs + profit) × months; containers needed = ceil(needed ÷ average margin);
 * break-even = ceil(costs × months ÷ average margin). Null containers without a usable average
 * ("not enough history yet").
 */
function companyMath(costsMonthly, profitMonthly, months, avgMargin, on = true) {
  const needed = (costsMonthly + profitMonthly) * months;
  const usable = on && avgMargin !== null && avgMargin > 0;
  return {
    needed: phpRound(needed, 2),
    avg_margin: avgMargin,
    containers_needed: usable ? Math.ceil(phpRound(needed / avgMargin, 6)) : null,
    breakeven_containers: usable ? Math.ceil(phpRound((costsMonthly * months) / avgMargin, 6)) : null,
  };
}

/** The company target for a period. `on` false when no row applies or costs and profit are both empty / 0. */
async function companyTarget(p, today, credits = null) {
  credits = credits ?? await salesTargetRepository.credits();
  let row = null;
  try {
    row = await salesTargetRepository.companyRowFor(p.start);
  } catch (e) {
    row = null;
  }
  const costs = row !== null && row.costs_monthly !== null ? Number(row.costs_monthly) : 0.0;
  const profitWanted = row !== null && row.profit_monthly !== null ? Number(row.profit_monthly) : 0.0;
  const on = row !== null && (costs > 0 || profitWanted > 0);
  const months = parseInt(p.months, 10);
  let earned = 0.0;
  let shipped = 0;
  let awaiting = 0;
  for (const c of vals(credits)) {
    if (!phpEmpty(c.reversed_on)) continue;
    if (!phpEmpty(c.shipped_on) && String(c.shipped_on) >= p.start && String(c.shipped_on) <= p.end) {
      shipped += parseInt(c.containers_shipped, 10) || 0;
      if (on) earned += await profit(Number(c.order_id));
    } else if (phpEmpty(c.shipped_on) && String(c.confirmed_on) <= p.end) {
      awaiting += parseInt(c.containers_confirmed, 10) || 0;
    }
  }
  const avg = on ? await avgMarginPerContainer(today, credits) : null;
  return {
    on,
    period: p,
    costs_monthly: costs,
    profit_monthly: profitWanted,
    earned: phpRound(earned, 2),
    counted: periodCounts(credits, p.start, p.end).counted,
    shipped,
    awaiting,
    ...companyMath(costs, profitWanted, months, avg, on),
  };
}

// ================================================================ pages

/** Orders as a plain id-keyed object (for the views) plus their ids in the query's order. */
function ordersView(map) {
  const byId = {};
  const list = [];
  for (const [id, o] of map) {
    byId[id] = o;
    list.push(o);
  }
  return { byId, list };
}

/**
 * The Targets page (manage_sales_targets): team table, Unassigned, associates, total and the check
 * line against the company's containers needed.
 */
async function teamData(p, today) {
  const s = await settings();
  const credits = await salesTargetRepository.credits();
  const pipeline = await salesTargetRepository.pipelineOrders();
  const owners = await salesTargetRepository.creditUsers([...credits.keys()].concat(pipeline.map((o) => Number(o.id))));
  const inPeriod = periodCounts(credits, p.start, p.end);
  const periodOrderIds = [...new Set(inPeriod.rows.map((c) => Number(c.order_id)).concat(inPeriod.correction_rows.map((e) => Number(e.order_id))))];
  const people = await salesTargetRepository.salesMembers();
  for (const uid of (await salesTargetRepository.targetsByScope('user')).keys()) people.push(uid);
  for (const oid of periodOrderIds) {
    if (ownerOf(owners, oid) !== null) people.push(ownerOf(owners, oid));
  }
  for (const o of pipeline) {
    if (ownerOf(owners, Number(o.id)) !== null) people.push(ownerOf(owners, Number(o.id)));
  }
  const users = await salesTargetRepository.usersByIds(people);
  const quotations = await salesTargetRepository.quotationsSent(p.start, p.end);
  const rows = [];
  let teamTarget = 0.0;
  for (const [uid, u] of users) {
    const mine = vals(credits).filter((c) => ownerOf(owners, Number(c.order_id)) === uid);
    const counts = periodCounts(mine, p.start, p.end);
    const t = await personalTarget(uid, p, s, u);
    const pipe = pipeline.filter((o) => ownerOf(owners, Number(o.id)) === uid);
    if (Number(u.is_active) !== 1 && counts.counted === 0 && counts.rows.length === 0 && pipe.length === 0) continue;
    let qt = 0;
    for (const [oid, n] of quotations) {
      if (ownerOf(owners, oid) === uid) qt += n;
    }
    teamTarget += t.containers;
    rows.push({
      user: u,
      target: t,
      counts,
      pipeline: pipe.length,
      quotations_sent: qt,
      progress: t.containers > 0 ? phpRound((100 * counts.counted) / t.containers, 0) : null,
    });
  }
  rows.sort((a, b) => {
    const x = String(a.user.name);
    const y = String(b.user.name);
    return x < y ? -1 : (x > y ? 1 : 0);
  });
  const unassignedIds = periodOrderIds.filter((oid) => ownerOf(owners, oid) === null);
  const unassignedCredits = vals(credits).filter((c) => unassignedIds.includes(Number(c.order_id)));
  const unassigned = periodCounts(unassignedCredits, p.start, p.end);
  const company = await companyTarget(p, today, credits);
  const credited = ordersView(await salesTargetRepository.ordersByIds(periodOrderIds));
  const ownersObj = {};
  for (const [oid, uid] of owners) ownersObj[oid] = uid;
  const staff = await salesTargetRepository.activeStaff();
  const staffNames = {};
  for (const st of staff) staffNames[st.id] = st.name;
  return {
    settings: s,
    period: p,
    rows,
    unassigned: { counts: unassigned, orders: ordersView(await salesTargetRepository.ordersByIds(unassignedIds)).list },
    associates: await associateRows(p, credits, periodOrderIds),
    total: inPeriod,
    team_target: phpRound(teamTarget, 1),
    company,
    credited_orders: credited.list,
    credited_order_refs: credited.byId,
    owners: ownersObj,
    staff,
    staff_names: staffNames, // "Credited to": an active staff member's name, else Unassigned (as PHP)
  };
}

/** Associates: the order's earning associate is credited too (containers only). */
async function associateRows(p, credits, orderIds) {
  const orderRepository = require('../repositories/orderRepository');
  const associateCommission = require('./associateCommission');
  const by = new Map();
  for (const oid of orderIds) {
    const order = await orderRepository.find(oid);
    const a = order ? await associateCommission.earningAssociate(order) : null;
    if (!a) continue;
    const aid = Number(a.id);
    if (!by.has(aid)) by.set(aid, { associate: a, credits: new Map() });
    by.get(aid).credits.set(oid, credits.get(oid));
  }
  for (const aid of (await salesTargetRepository.targetsByScope('associate')).keys()) {
    if (!by.has(aid)) {
      const a = await require('../repositories/associateRepository').find(aid);
      if (a) by.set(aid, { associate: a, credits: new Map() });
    }
  }
  const out = [];
  for (const [aid, x] of by) {
    const t = await salesTargetRepository.target('associate', aid);
    out.push({
      associate: x.associate,
      target: t !== null && t.containers !== null ? Number(t.containers) : 0.0,
      reviewed_at: t !== null ? (t.reviewed_at ?? null) : null,
      counts: periodCounts(x.credits, p.start, p.end),
    });
  }
  out.sort((a, b) => {
    const x = String(a.associate.name);
    const y = String(b.associate.name);
    return x < y ? -1 : (x > y ? 1 : 0);
  });
  return out;
}

/**
 * The salesperson's own "Your target" box on the Dashboard, or null when they have no target
 * (containers > 0). Containers only; the order's USD FOB only when seesSelling.
 */
async function dashboardFor(userId, today, seesSelling) {
  userId = Number(userId);
  const s = await settings();
  const p = period(String(s.period), today);
  let t;
  try {
    t = await personalTarget(userId, p, s);
  } catch (e) {
    return null; // a database before migration 2026-11-15a
  }
  if (t.containers <= 0) return null;
  const credits = await salesTargetRepository.credits();
  const pipeline = await salesTargetRepository.pipelineOrders();
  const owners = await salesTargetRepository.creditUsers([...credits.keys()].concat(pipeline.map((o) => Number(o.id))));
  const mine = vals(credits).filter((c) => ownerOf(owners, Number(c.order_id)) === userId);
  const counts = periodCounts(mine, p.start, p.end);
  const pipe = pipeline.filter((o) => ownerOf(owners, Number(o.id)) === userId);
  const ids = counts.rows.map((c) => Number(c.order_id)).concat(counts.correction_rows.map((e) => Number(e.order_id)));
  let qt = 0;
  if (t.in_buildup) {
    for (const [oid, n] of await salesTargetRepository.quotationsSent(p.start, p.end)) {
      if (ownerOf(owners, oid) === userId) qt += n;
    }
  }
  const fob = {};
  if (seesSelling) {
    for (const [oid, v] of await salesTargetRepository.fobValues(ids)) fob[oid] = v;
  }
  return {
    period: p,
    target: t,
    counts,
    still: Math.max(0.0, phpRound(t.containers - counts.counted, 1)),
    pipeline: pipe,
    progress: phpRound((100 * counts.counted) / t.containers, 0),
    orders: ordersView(await salesTargetRepository.ordersByIds(ids)).byId,
    fob,
    has_fob: Object.keys(fob).length > 0,
    quotations_sent: qt,
  };
}

/** The cockpit box (company target ON and manage_sales_targets): this period and the 2 before. */
async function cockpitBox(today) {
  resetCache(); // PHP caches profits for one request; this long-running process starts each page afresh.
  let s;
  let credits;
  try {
    s = await settings();
    credits = await salesTargetRepository.credits();
  } catch (e) {
    return null;
  }
  const periods = recentPeriods(String(s.period), today, 3);
  const current = await companyTarget(periods[0], today, credits);
  if (!current.on) return null;
  return {
    current,
    previous: [await companyTarget(periods[1], today, credits), await companyTarget(periods[2], today, credits)],
    elapsed_pct: elapsedPct(periods[0], today),
  };
}

/** How much of the period has passed on today, in %. */
function elapsedPct(p, today) {
  const days = Math.abs(dayDiff(p.start, p.end)) + 1;
  const gone = Math.abs(dayDiff(p.start, today)) + 1;
  if (today < p.start) return 0;
  return Math.min(100, Math.floor((100 * gone) / days));
}

// ================================================================ Sales Performance — by salesperson

/**
 * The "By salesperson" table of Sales Performance (salesperson 'all' or a user id): quotes sent,
 * won (PI), lost before / after PI, win rate, average days QT → PI, top lost category — orders
 * credited to that person. Same date rules as the report: QT e-mail sent date, pi_date, lost_at.
 */
async function performanceBySalesperson(from, to, salesperson, isTestData = 0) {
  const db = require('../config/db');
  const params = { t: isTestData };
  let qtRange = '';
  if (from) {
    qtRange += ' AND DATE(el.sent_at) >= :qt_f';
    params.qt_f = from;
  }
  if (to) {
    qtRange += ' AND DATE(el.sent_at) <= :qt_t';
    params.qt_t = to;
  }
  const sql = `SELECT o.id, o.status, o.pi_date, DATE(o.lost_at) AS lost_on, o.lost_category,
                      EXISTS (SELECT 1 FROM documents d JOIN document_types dt ON dt.id = d.document_type_id WHERE dt.code = 'PI' AND d.order_id = o.id) AS has_pi,
                      (SELECT DATE(MIN(el2.sent_at)) FROM email_log el2 JOIN documents d2 ON d2.id = el2.document_id JOIN document_types dt2 ON dt2.id = d2.document_type_id
                        WHERE dt2.code = 'QT' AND el2.status = 'sent' AND d2.order_id = o.id) AS first_qt_on,
                      (SELECT COUNT(DISTINCT el.document_id) FROM email_log el JOIN documents d ON d.id = el.document_id JOIN document_types dt ON dt.id = d.document_type_id
                        WHERE dt.code = 'QT' AND el.status = 'sent' AND d.order_id = o.id${qtRange}) AS qt_sent
                 FROM orders o
                WHERE o.is_test_data = :t${require('./divisionService').sql('o.division_id')}`;
  const inR = (d) => d !== null && d !== undefined && d !== '' && (!from || String(d) >= from) && (!to || String(d) <= to);
  const orders = new Map();
  for (const o of await db.query(sql, params)) {
    const won = Number(o.has_pi) === 1 && inR(o.pi_date);
    const lost = o.status === 'lost' && inR(o.lost_on);
    if (Number(o.qt_sent) === 0 && !won && !lost) continue;
    orders.set(Number(o.id), { ...o, won, lost });
  }
  const owners = await salesTargetRepository.creditUsers([...orders.keys()]);
  const by = new Map();
  for (const [oid, o] of orders) {
    const uid = ownerOf(owners, oid);
    if (salesperson !== 'all' && String(uid ?? '') !== salesperson) continue;
    const k = uid ?? 0;
    if (!by.has(k)) by.set(k, { user_id: uid, quotes: 0, won: 0, lost_before_pi: 0, lost_after_pi: 0, days: [], categories: new Map() });
    const r = by.get(k);
    r.quotes += parseInt(o.qt_sent, 10) || 0;
    if (o.won) {
      r.won += 1;
      if (!phpEmpty(o.first_qt_on) && !phpEmpty(o.pi_date)) r.days.push(dayDiff(String(o.first_qt_on), String(o.pi_date)));
    }
    if (o.lost) {
      r[Number(o.has_pi) === 1 ? 'lost_after_pi' : 'lost_before_pi'] += 1;
      const cat = String(o.lost_category ?? '');
      if (has(LOST_CATEGORIES, cat)) r.categories.set(cat, (r.categories.get(cat) || 0) + 1);
    }
  }
  const users = await salesTargetRepository.usersByIds([...by.values()].map((r) => r.user_id).filter((x) => x));
  const out = [];
  for (const r of by.values()) {
    // PHP arsort() + array_key_first(): the highest count; on a tie the category seen first.
    let top = null;
    let topN = -1;
    for (const [cat, n] of r.categories) {
      if (n > topN) {
        top = cat;
        topN = n;
      }
    }
    const u = r.user_id === null ? null : users.get(r.user_id);
    out.push({
      user_id: r.user_id,
      name: r.user_id === null ? 'Unassigned' : String(u ? u.name : `User #${r.user_id}`),
      quotes: r.quotes,
      won: r.won,
      lost_before_pi: r.lost_before_pi,
      lost_after_pi: r.lost_after_pi,
      win_rate_pct: r.quotes > 0 ? phpRound((100 * r.won) / r.quotes, 1) : null,
      avg_days_qt_to_pi: r.days.length ? phpRound(r.days.reduce((a, b) => a + b, 0) / r.days.length, 1) : null,
      top_lost_category: top !== null ? LOST_CATEGORIES[top] : null,
    });
  }
  out.sort((a, b) => {
    const an = a.user_id === null ? 1 : 0;
    const bn = b.user_id === null ? 1 : 0;
    if (an !== bn) return an - bn;
    return a.name < b.name ? -1 : (a.name > b.name ? 1 : 0);
  });
  return out;
}

// ================================================================ editing (MD / ED)

/**
 * Saves a person's own target (POST /sales-targets/person/:id); reviewed_at = now.
 *
 * @returns {Promise<?string>} an error, or null when saved
 */
async function savePerson(personId, post, userId) {
  post = post || {};
  const reason = String(post.reason ?? '').trim();
  const error = reasonValidator.check(reason);
  if (error) return error;
  const c = String(post.containers ?? '').trim();
  const nb = String(post.new_buyers ?? '').trim();
  const mult = String(post.multiple ?? '').trim();
  if (c !== '' && !/^\d{1,4}(\.\d)?$/.test(c)) {
    return 'Containers: a number with at most one decimal (e.g. 6 or 4.5), or empty for the designation default.';
  }
  if (nb !== '' && (!/^\d+$/.test(nb) || parseInt(nb, 10) > 9999)) {
    return 'New buyers: a whole number, or empty for the designation default.';
  }
  if (mult !== '' && (!/^\d{1,2}(\.\d)?$/.test(mult) || Number(mult) < 1 || Number(mult) > 10)) {
    return 'Multiple: a number from 1 to 10 with at most one decimal, or empty for the company setting.';
  }
  const old = await salesTargetRepository.target('user', personId);
  const fields = {
    containers: c === '' ? null : phpRound(parseFloat(c), 1),
    new_buyers: nb === '' ? null : parseInt(nb, 10),
    multiple: mult === '' ? null : phpRound(parseFloat(mult), 1),
    buildup_enabled: post.buildup_present !== undefined && post.buildup_present !== null
      ? (!phpEmpty(post.buildup_enabled) ? 1 : 0)
      : (old !== null ? (old.buildup_enabled ?? null) : null),
    reason,
    reviewed_at: formatForDb(new Date()),
  };
  await salesTargetRepository.saveTarget('user', personId, fields, userId);
  const dash = (x) => (x === null || x === undefined ? '—' : String(x));
  const show = (r) => (r === null ? '—' : `containers ${dash(r.containers)}, new buyers ${dash(r.new_buyers)}, multiple ${dash(r.multiple)}, build-up ${
    r.buildup_enabled === null || r.buildup_enabled === undefined || Number(r.buildup_enabled) === 1 ? 'on' : 'off'}`);
  await auditLogRepository.log(userId, 'SALES_TARGET_CHANGED', 'users', personId, 'sales_target', show(old), show(fields), reason);
  return null;
}

/** POST /sales-targets/associate/:id: the associate's containers target. */
async function saveAssociate(associateId, post, userId) {
  post = post || {};
  const reason = String(post.reason ?? '').trim();
  const error = reasonValidator.check(reason);
  if (error) return error;
  const c = String(post.containers ?? '').trim();
  if (c !== '' && !/^\d{1,4}(\.\d)?$/.test(c)) {
    return 'Containers: a number with at most one decimal (e.g. 2 or 1.5), or empty for none.';
  }
  const old = await salesTargetRepository.target('associate', associateId);
  const nv = c === '' ? null : phpRound(parseFloat(c), 1);
  await salesTargetRepository.saveTarget('associate', associateId, { containers: nv, reason, reviewed_at: formatForDb(new Date()) }, userId);
  await auditLogRepository.log(userId, 'SALES_TARGET_CHANGED', 'associates', associateId, 'containers',
    old !== null && old.containers !== null && old.containers !== undefined ? String(old.containers) : null, nv === null ? null : String(nv), reason);
  return null;
}

/**
 * POST /sales-targets/credit/:orderId: who gets the order's target credit. 'auto' = back to the
 * Sales & Export owner. Logged as ORDER_SALES_CREDIT_MOVED. Changes nothing else on the order.
 */
async function moveCredit(orderId, to, reason, userId) {
  const error = reasonValidator.check(reason);
  if (error) return error;
  const order = await require('../repositories/orderRepository').find(orderId);
  if (!order) return 'Order not found.';
  let nv;
  to = String(to ?? '');
  if (to === 'auto') {
    nv = null;
  } else {
    nv = /^\d+$/.test(to) ? parseInt(to, 10) : 0;
    const u = nv > 0 ? ((await salesTargetRepository.usersByIds([nv])).get(nv) || null) : null;
    if (u === null || Number(u.is_active) !== 1) return 'Choose an active staff member to credit.';
  }
  const old = order.sales_credit_user_id ?? null;
  if (String(old ?? '') === String(nv ?? '')) return null;
  await salesTargetRepository.setCreditUser(orderId, nv);
  await auditLogRepository.log(userId, 'ORDER_SALES_CREDIT_MOVED', 'orders', orderId, 'sales_credit_user_id',
    old !== null ? String(old) : null, nv !== null ? String(nv) : null, reason);
  return null;
}

// ================================================================ alerts and their recipients

/** PHP array_values(array_filter(array_map('intval', explode(',', $stored)))). */
function storedIds(stored) {
  return String(stored).split(',').map((x) => parseInt(x, 10) || 0).filter((x) => x !== 0);
}

/** @returns {Promise<number[]>} who is told about this alert kind (stored ids, the defaults, or the MD). */
async function alertRecipients(kind, settingsIn = null) {
  const s = settingsIn ?? await settings();
  const [key, , roles] = ALERTS[kind];
  const stored = s[key] ?? null;
  let ids;
  if (stored === null) {
    ids = await salesTargetRepository.usersWithRoles(roles);
  } else {
    const wanted = storedIds(stored);
    const users = await salesTargetRepository.usersByIds(wanted);
    ids = wanted.filter((id) => users.has(id) && Number(users.get(id).is_active) === 1);
  }
  return ids.length ? ids : require('./staffRouting').managingDirectors();
}

/** @returns {Promise<Object<string, number[]>>} kind => the ticked people on Admin › E-mail settings */
async function alertRecipientsForForm() {
  const s = await settings();
  const out = {};
  for (const [kind, [key, , roles]] of Object.entries(ALERTS)) {
    const stored = s[key] ?? null;
    out[kind] = stored === null ? await salesTargetRepository.usersWithRoles(roles) : storedIds(stored);
  }
  return out;
}

/**
 * Saves the 3 rows of Admin › E-mail settings section E (target_alerts[kind][] = user ids). A row
 * left exactly at its defaults stays "defaults" (NULL); nobody ticked is stored as '' (= the MD).
 *
 * @returns {Promise<string[]>} labels of the rows that changed
 */
async function saveAlertRecipients(posted, userId, reason) {
  posted = posted || {};
  const s = await settings();
  const active = (await salesTargetRepository.activeStaff()).map((u) => u.id);
  const changes = [];
  for (const [kind, [key, label, roles]] of Object.entries(ALERTS)) {
    const raw = posted[kind];
    const list = raw === undefined || raw === null ? [] : (Array.isArray(raw) ? raw : (typeof raw === 'object' ? Object.values(raw) : [raw]));
    const ids = [...new Set(list.map((x) => parseInt(x, 10) || 0).filter((id) => active.includes(id)))];
    ids.sort((a, b) => a - b);
    const current = s[key] ?? null;
    if (current === null) {
      const defaults = await salesTargetRepository.usersWithRoles(roles);
      if (ids.length === defaults.length && ids.every((id, i) => id === defaults[i])) continue; // still the defaults
    }
    const nv = ids.join(',');
    if (current !== null && current === nv) continue;
    await salesTargetRepository.saveSetting(key, nv, userId);
    await auditLogRepository.log(userId, 'SALES_TARGET_SETTINGS_CHANGED', 'sales_target_settings', null, key, current ?? 'defaults', nv === '' ? 'nobody (the MD)' : nv, reason);
    changes.push(label);
  }
  return changes;
}

/**
 * The daily alerts (checkAlerts, after sync()), each sent once:
 *  - behind: the period ≥ 50 % gone, company target ON, (containers shipped in the period +
 *    confirmed awaiting shipment) ÷ containers needed below alert_pct → once per period;
 *  - review: a person with a target whose own target was last reviewed more than review_months
 *    ago (never reviewed: counted from joined_on, or due) → once per person per month;
 *  - no_owner: a standing credit row with nobody credited → once per order.
 * In-app notice and e-mail to the recipients of Admin › E-mail settings section E.
 *
 * @returns {Promise<number>} notices created
 */
async function runAlerts(today) {
  const notificationRepository = require('../repositories/notificationRepository');
  resetCache(); // a fresh daily run
  const s = await settings();
  const credits = await salesTargetRepository.credits();
  const p = period(String(s.period), today);
  let created = 0;

  // behind
  if (elapsedPct(p, today) >= 50) {
    const company = await companyTarget(p, today, credits);
    if (company.on && company.containers_needed !== null && company.containers_needed > 0) {
      const pct = (100 * (company.shipped + company.awaiting)) / company.containers_needed;
      const alertPct = parseInt(s.alert_pct, 10) || 0;
      if (pct < alertPct && await salesTargetRepository.once('sales_target_behind', p.key, p.type, today)) {
        const msg = `Sales target ${p.label}: ${company.shipped + company.awaiting} of ${company.containers_needed} containers needed are shipped or confirmed (${Math.floor(pct)}%, alert below ${alertPct}%) with ${elapsedPct(p, today)}% of the period gone — see Sales targets.`;
        const ids = await alertRecipients('behind', s);
        created += (await notificationRepository.notifyRouted(ids, ids, 'sales_target_behind', null, msg)).length;
      }
    }
  }

  // review
  const reviewMonths = parseInt(s.review_months, 10) || 0;
  const limit = addMonths(today, -reviewMonths);
  const people = [...new Set((await salesTargetRepository.salesMembers()).concat([...(await salesTargetRepository.targetsByScope('user')).keys()]))];
  const users = await salesTargetRepository.usersByIds(people);
  let reviewIds = null;
  for (const [uid, u] of users) {
    if (Number(u.is_active) !== 1) continue;
    const t = await personalTarget(uid, p, s, u);
    if (t.containers <= 0) continue;
    const since = t.reviewed_at !== null ? String(t.reviewed_at).slice(0, 10) : (u.joined_on ?? null);
    if (since !== null && String(since) > limit) continue;
    if (!await salesTargetRepository.once('sales_target_review', String(uid), today.slice(0, 7), today)) continue;
    reviewIds = reviewIds ?? await alertRecipients('review', s);
    const msg = `Sales target: ${String(u.name)}'s target was last reviewed ${t.reviewed_at !== null ? dates.short(String(t.reviewed_at)) : 'never'} — review it on Sales targets (every ${reviewMonths} months).`;
    created += (await notificationRepository.notifyRouted(reviewIds, reviewIds, 'sales_target_review', null, msg)).length;
  }

  // no_owner
  const standing = new Map([...credits].filter(([, c]) => phpEmpty(c.reversed_on)));
  const owners = await salesTargetRepository.creditUsers([...standing.keys()]);
  let orders = null;
  let noOwnerIds = null;
  for (const [oid, c] of standing) {
    if (ownerOf(owners, oid) !== null) continue;
    if (!await salesTargetRepository.once('sales_target_no_owner', String(oid), 'once', today)) continue;
    orders = orders ?? await salesTargetRepository.ordersByIds([...standing.keys()]);
    noOwnerIds = noOwnerIds ?? await alertRecipients('no_owner', s);
    const o = orders.get(oid);
    const ref = o && o.order_reference !== null && o.order_reference !== undefined ? String(o.order_reference) : `#${oid}`;
    const msg = `Sales target: order ${ref} is confirmed (${dates.short(String(c.confirmed_on))}) but has no salesperson — choose its Sales & Export owner, or move its credit on Sales targets.`;
    created += (await notificationRepository.notifyRouted(noOwnerIds, noOwnerIds, 'sales_target_no_owner', oid, msg)).length;
  }
  return created;
}

module.exports = {
  PERMISSION, DEFAULTS, PERIOD_TYPES, LOST_CATEGORIES, ALERTS,
  resetCache, canManage, canSeeMoney, num1,
  settings, validateSettings, saveSettings,
  period, previous, recentPeriods, monthsOf,
  confirmation, estimatedContainers, sync,
  events, periodCounts, creditsFor,
  buildupFactor, monthsSinceJoining, personalTarget,
  avgMarginPerContainer, companyTarget, companyMath,
  teamData, dashboardFor, cockpitBox, elapsedPct,
  performanceBySalesperson,
  savePerson, saveAssociate, moveCredit,
  alertRecipients, alertRecipientsForForm, saveAlertRecipients, runAlerts,
};
