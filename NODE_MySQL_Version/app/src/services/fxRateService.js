'use strict';

const https = require('https');
const db = require('../config/db');

/**
 * Port of App\Services\FxRateService — owner decision D-301 (point 4, 11 Nov 2026):
 * the USD → INR exchange rate.
 *
 *  - Automatic (default): fetched once a day (jobs/fetchFxRate.js) from Frankfurter
 *    (European Central Bank rates), or open.er-api.com when that fails. Every fetch is
 *    kept (fx_rates) and shown on Pricing settings.
 *  - Pricing rate = market rate × (1 − cushion %); cushion 2 % by default, 0 to 5 % with
 *    one decimal.
 *  - A fetch that fails keeps the last rate in use, with a warning.
 *  - A change of more than 2 % against the rate in use waits for the MD's OK.
 *  - Manual: the "Pricing exchange rate" typed on Pricing settings (as before); also used
 *    until the first rate has been fetched.
 * Prices already issued on a Quotation or Proforma Invoice never change.
 *
 * The HTTP call is injectable (setHttp(url => Promise<body|null>)) so tests never touch the network.
 */

const SOURCES = [
  { name: 'Frankfurter (European Central Bank)', url: 'https://api.frankfurter.app/latest?from=USD&to=INR' },
  { name: 'open.er-api.com', url: 'https://open.er-api.com/v6/latest/USD' },
];
const JUMP_PCT = 2.0;
const CUSHION_MIN = 0;
const CUSHION_MAX = 5;

let http = null;

/** Tests: replace the HTTP fetcher (url => Promise of the body string, or null on failure). */
function setHttp(fn) {
  http = fn || null;
}

function defaultGet(url) {
  return new Promise((resolve) => {
    const req = https.get(url, { timeout: 15000, headers: { Accept: 'application/json', 'User-Agent': 'exchange-rate-check' } }, (res) => {
      if (res.statusCode < 200 || res.statusCode >= 300) {
        res.resume();
        resolve(null);
        return;
      }
      let body = '';
      res.setEncoding('utf8');
      res.on('data', (c) => { body += c; });
      res.on('end', () => resolve(body));
    });
    req.on('timeout', () => { req.destroy(); resolve(null); });
    req.on('error', () => resolve(null));
  });
}

async function get(url) {
  return http ? http(url) : defaultGet(url);
}

/** The rate from a source's JSON (₹ per USD), or null when it is not there or not sensible. */
function parse(body) {
  let j;
  try {
    j = JSON.parse(body);
  } catch (e) {
    return null;
  }
  const v = j && j.rates ? j.rates.INR : undefined;
  if (v === undefined || v === null || Number.isNaN(Number(v))) return null;
  const n = Number(v);
  return n > 1 && n < 1000 ? n : null;
}

function config(raw) {
  const c = Number(raw.fx_cushion_pct);
  return {
    mode: raw.fx_mode === 'manual' ? 'manual' : 'auto',
    cushion: Math.max(CUSHION_MIN, Math.min(CUSHION_MAX, Number.isNaN(c) || raw.fx_cushion_pct === undefined ? 2 : c)),
    manual: Number(raw.pricing_fx_rate ?? 82),
  };
}

/** market × (1 − cushion %), 4 decimals (PHP round()). */
function pricingRateFor(market, cushionPct) {
  return Math.round(market * (1 - cushionPct / 100) * 10000 + Number.EPSILON) / 10000;
}

async function latestInUse() {
  return db.queryOne("SELECT * FROM fx_rates WHERE status = 'in_use' AND market_rate IS NOT NULL ORDER BY rate_date DESC, id DESC LIMIT 1");
}

const ymd = (d) => {
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
};
const dateStr = (v) => (v instanceof Date ? ymd(v) : String(v).slice(0, 10));

/** Just the pricing rate prices are built with now. */
async function rate(raw) {
  const cfg = config(raw);
  if (cfg.mode === 'manual') return cfg.manual;
  const row = await latestInUse();
  return row === null ? cfg.manual : pricingRateFor(Number(row.market_rate), cfg.cushion);
}

/** "+2.40 %" / "−3.10 %". */
function signed(v) {
  return `${v < 0 ? '−' : '+'}${Math.abs(v).toFixed(2)} %`;
}

/** The rate prices are built with now, and what to say about it (same shape as PHP). */
async function current(raw = null) {
  if (raw === null) raw = await require('./pricingService').settingsRaw();
  const cfg = config(raw);
  const out = { rate: cfg.manual, mode: cfg.mode, market: null, cushion: cfg.cushion, source: null, date: null, warnings: [], waiting: null };
  if (cfg.mode === 'manual') return out;
  const row = await latestInUse();
  if (row === null) {
    out.warnings.push(`No market rate has been fetched yet — prices use the manual rate ₹${cfg.manual.toFixed(2)} until the first daily fetch.`);
    return out;
  }
  out.market = Number(row.market_rate);
  out.rate = pricingRateFor(Number(row.market_rate), cfg.cushion);
  out.source = String(row.source);
  out.date = dateStr(row.rate_date);
  const today = ymd(new Date());
  const yesterday = new Date();
  yesterday.setDate(yesterday.getDate() - 1);
  const latest = await db.queryOne('SELECT * FROM fx_rates ORDER BY rate_date DESC, id DESC LIMIT 1');
  if (latest && latest.status === 'waiting_md') {
    out.waiting = { ...latest, rate_date: dateStr(latest.rate_date) };
    out.warnings.push(`Today's market rate ₹${Number(latest.market_rate).toFixed(2)} is ${signed(Number(latest.change_pct))} against the rate in use — it waits for the MD's OK. Prices still use ₹${Number(row.market_rate).toFixed(2)} (${out.date}).`);
  } else if (latest && latest.status === 'failed' && dateStr(latest.rate_date) === today) {
    out.warnings.push(`Today's rate could not be fetched (${latest.note}) — prices keep the rate of ${out.date}.`);
  } else if (out.date < ymd(yesterday) && !(latest && latest.status === 'rejected')) {
    out.warnings.push(`The rate in use is from ${out.date} — the daily fetch has not run since. Check the scheduled job.`);
  }
  return out;
}

async function forDate(date) {
  return db.queryOne('SELECT * FROM fx_rates WHERE rate_date = :d', { d: date });
}

/** The daily fetch (same steps and messages as PHP). */
async function fetchDaily(date = null) {
  const d = date || ymd(new Date());
  let rateV = null;
  let source = null;
  const errors = [];
  for (const s of SOURCES) {
    const body = await get(s.url);
    const v = body !== null && body !== undefined ? parse(body) : null;
    if (v !== null) {
      rateV = v;
      source = s.name;
      break;
    }
    errors.push(`${s.name}${body === null || body === undefined ? ' did not answer' : ' gave no INR rate'}`);
  }
  const now = new Date();
  const nowStr = `${ymd(now)} ${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}:${String(now.getSeconds()).padStart(2, '0')}`;
  if (rateV === null) {
    const note = errors.join('; ').slice(0, 255);
    const existing = await forDate(d);
    if (!existing || existing.status === 'failed') {
      await db.execute(`INSERT INTO fx_rates (rate_date, market_rate, source, status, note, fetched_at) VALUES (:d, NULL, NULL, 'failed', :n, :t)
        ON DUPLICATE KEY UPDATE note = VALUES(note), fetched_at = VALUES(fetched_at)`, { d, n: note, t: nowStr });
    }
    return { status: 'failed', market: null, source: null, change_pct: null, message: `Exchange rate not fetched: ${note}. The last rate stays in use.` };
  }
  const inUse = await db.queryOne("SELECT * FROM fx_rates WHERE status = 'in_use' AND market_rate IS NOT NULL AND rate_date <> :d ORDER BY rate_date DESC, id DESC LIMIT 1", { d });
  const change = inUse ? (rateV / Number(inUse.market_rate) - 1) * 100 : null;
  const status = change !== null && Math.abs(change) > JUMP_PCT ? 'waiting_md' : 'in_use';
  const existing = await forDate(d);
  if (existing && ['in_use', 'rejected'].includes(existing.status) && existing.decided_by !== null) {
    return { status: String(existing.status), market: Number(existing.market_rate), source: String(existing.source),
      change_pct: existing.change_pct !== null ? Number(existing.change_pct) : null, message: "Today's rate was already decided by the MD." };
  }
  await db.execute(`INSERT INTO fx_rates (rate_date, market_rate, source, status, change_pct, note, fetched_at) VALUES (:d, :r, :s, :st, :c, NULL, :t)
    ON DUPLICATE KEY UPDATE market_rate = VALUES(market_rate), source = VALUES(source), status = VALUES(status),
                            change_pct = VALUES(change_pct), note = NULL, fetched_at = VALUES(fetched_at)`,
  { d, r: rateV.toFixed(4), s: source, st: status, c: change !== null ? change.toFixed(3) : null, t: nowStr });
  const fmt4 = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 4, maximumFractionDigits: 4 });
  let msg = `Exchange rate ${d}: ₹${fmt4(rateV)} per USD from ${source}${change !== null ? ` (${signed(change)})` : ''}.`;
  if (status === 'waiting_md') {
    const notificationRepository = require('../repositories/notificationRepository');
    const permissionRepository = require('../repositories/permissionRepository');
    const text = `The market rate moved ${signed(change)} today (₹${Number(inUse.market_rate).toFixed(2)} → ₹${rateV.toFixed(2)}). It is not used for prices until you approve it: Products › Pricing settings › Exchange rate.`;
    for (const mdId of await permissionRepository.usersWithPermission('manage_pricing')) {
      await notificationRepository.createInAppOnly(Number(mdId), 'fx_rate_approval', null, text.slice(0, 500));
    }
    msg += ` Over ${JUMP_PCT} % — waits for the MD.`;
  }
  return { status, market: rateV, source, change_pct: change, message: msg };
}

/** The MD's decision on a rate that moved more than 2 %. Returns an error, or null. */
async function decide(id, approve, reason, userId) {
  const row = await db.queryOne('SELECT * FROM fx_rates WHERE id = :id', { id });
  if (!row || row.status !== 'waiting_md') return 'That rate is not waiting for a decision.';
  const reasonError = require('../helpers/reasonValidator').check(reason);
  if (reasonError) return reasonError;
  await db.execute('UPDATE fx_rates SET status = :s, decided_by = :u, decided_at = NOW(), decision_reason = :r WHERE id = :id',
    { s: approve ? 'in_use' : 'rejected', u: userId, r: String(reason).slice(0, 255), id });
  await require('../repositories/auditLogRepository').log(userId, approve ? 'FX_RATE_APPROVED' : 'FX_RATE_REJECTED', 'fx_rates', id, 'status', 'waiting_md',
    `${approve ? 'in_use' : 'rejected'} — ₹${Number(row.market_rate).toLocaleString('en-US', { minimumFractionDigits: 4, maximumFractionDigits: 4 })} (${dateStr(row.rate_date)})`, reason);
  return null;
}

async function history(limit = 30) {
  const n = Math.max(1, Math.min(366, parseInt(limit, 10) || 30));
  const rows = await db.query(`SELECT f.*, u.name AS decided_by_name FROM fx_rates f LEFT JOIN users u ON u.id = f.decided_by ORDER BY f.rate_date DESC, f.id DESC LIMIT ${n}`);
  return rows.map((r) => ({ ...r, rate_date: dateStr(r.rate_date) }));
}

/** Reads the exchange-rate part of the Pricing settings form: [values, errors]. */
function readForm(post) {
  const values = {};
  const errors = [];
  if (Object.prototype.hasOwnProperty.call(post, 'fx_mode')) {
    const mode = String(post.fx_mode);
    if (!['auto', 'manual'].includes(mode)) errors.push('Choose Automatic or Manual for the exchange rate.');
    else values.fx_mode = mode;
  }
  if (Object.prototype.hasOwnProperty.call(post, 'fx_cushion_pct')) {
    const c = String(post.fx_cushion_pct).trim();
    if (!/^\d(\.\d)?$/.test(c) || Number(c) < CUSHION_MIN || Number(c) > CUSHION_MAX) {
      errors.push('Cushion below the market rate: enter 0 to 5 % with at most one decimal (e.g. 2 or 2.5).');
    } else {
      values.fx_cushion_pct = Number(c).toFixed(1);
    }
  }
  return [values, errors];
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const nf = (v, d = 2) => Number(v).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });

/** "12 Nov 2026" from "2026-11-12" (or a DATETIME); "" when empty. */
function dayLabel(v) {
  if (v === null || v === undefined || v === '') return '';
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(dateStr(v));
  return m ? `${Number(m[3])} ${MONTHS[Number(m[2]) - 1]} ${m[1]}` : String(v);
}

/**
 * Owner decision D-340 (12 Nov 2026): the "Rate used for prices right now" box on Pricing
 * settings — current() plus what the box and the field badges say (same as PHP status()).
 * state: auto / fallback / manual; tone: green / orange / blue.
 */
async function status(raw = null) {
  if (raw === null) raw = await require('./pricingService').settingsRaw();
  const fx = await current(raw);
  const cfg = config(raw);
  const last = await latestInUse();
  fx.manual = cfg.manual;
  fx.has_market = !!last;
  fx.state = fx.mode === 'manual' ? 'manual' : (fx.market !== null ? 'auto' : 'fallback');
  fx.tone = fx.state === 'manual' ? 'blue' : (fx.state === 'auto' && fx.warnings.length === 0 ? 'green' : 'orange');
  if (fx.state === 'manual') fx.badge = 'Manual';
  else if (fx.state === 'fallback') fx.badge = 'Manual fall-back';
  else fx.badge = fx.date === ymd(new Date()) ? 'Automatic · fetched today' : `Automatic · rate of ${dayLabel(fx.date)}`;
  fx.fetched = null;
  fx.cushion_line = null;
  if (fx.state === 'auto' && last) {
    const t = /\d{2}:\d{2}/.exec(String(last.fetched_at || '').slice(11));
    fx.fetched = dayLabel(fx.date) + (t ? `, ${t[0]}` : '');
    fx.cushion_line = `${nf(fx.cushion, 1)}% → ₹${nf(fx.market, 2)} × ${(1 - fx.cushion / 100).toFixed(3).replace(/0+$/, '').replace(/\.$/, '')}`;
  }
  fx.manual_note = fx.state === 'auto' ? `Manual rate ₹${nf(cfg.manual, 2)} is not used — it is only the fall-back if no rate has ever been fetched.` : null;
  fx.fallback_hint = fx.state === 'fallback' ? 'Check that the daily job runs (README › Scheduled jobs), or press "Fetch today\'s rate now".' : null;
  fx.compare = null;
  fx.above_note = null;
  if (fx.state === 'manual') {
    if (!last) {
      fx.compare = 'No market rate has been fetched yet to compare with.';
    } else {
      const pct = (cfg.manual / Number(last.market_rate) - 1) * 100;
      fx.compare = `For comparison, last market rate fetched: ₹${nf(last.market_rate, 2)} (${dayLabel(last.rate_date)}) — your rate is `
        + (Math.abs(pct) < 0.05 ? 'the same as the market.' : `${nf(Math.abs(pct), 1)}% ${pct > 0 ? 'above' : 'below'} the market.`);
      if (pct >= 0.05) fx.above_note = 'Above the market means prices in USD come out lower than intended.';
    }
  }
  return fx;
}

/**
 * Owner decision D-340 (12 Nov 2026): the order page's one line about the rate its prices were
 * worked out at — price build-up viewers only (MD, ED); never on documents (D-304). Before the PI
 * the prices follow today's rate; once the PI is issued the rate fixed on the lines
 * (price_snapshot, D-245), else the order's pricing_fx_rate_at_pi. Null when no line is checked.
 * Port of FxRateService::orderLine().
 */
async function orderLine(order, checks) {
  const all = Object.values(checks || {});
  if (!all.some((c) => (c.status || 'unchecked') !== 'unchecked')) return null;
  let fixedRate = null;
  let issued = false;
  for (const c of all) {
    let snap = null;
    if (c.line && c.line.price_snapshot) {
      try { snap = JSON.parse(c.line.price_snapshot); } catch (e) { snap = null; }
    }
    if (snap && typeof snap === 'object') {
      issued = true;
      if (fixedRate === null && snap.fx_rate !== undefined && snap.fx_rate !== null && Number(snap.fx_rate) > 0) fixedRate = Number(snap.fx_rate);
    }
  }
  const now = await status();
  if (issued) {
    if (fixedRate === null && order.pricing_fx_rate_at_pi !== null && order.pricing_fx_rate_at_pi !== undefined && Number(order.pricing_fx_rate_at_pi) > 0) fixedRate = Number(order.pricing_fx_rate_at_pi);
    const row = await db.queryOne("SELECT MIN(d.generated_at) AS first_pi FROM documents d JOIN document_types dt ON dt.id = d.document_type_id WHERE d.order_id = :o AND dt.code = 'PI'", { o: Number(order.id) });
    const piDate = dayLabel(row ? row.first_pi : null);
    const of = piDate !== '' ? ` of ${piDate}` : '';
    if (fixedRate === null) return `Prices fixed on the Proforma Invoice${of}.`;
    const r = `₹${nf(fixedRate, 2)}`;
    return `Prices worked out at ${r} per USD — fixed on the Proforma Invoice${of} at ${r} per USD`
      + (Math.abs(fixedRate - Number(now.rate)) >= 0.005 ? `; today's rate ₹${nf(now.rate, 2)} does not change them.` : '.');
  }
  const how = now.state === 'manual' ? 'Manual' : (now.state === 'fallback' ? 'Manual fall-back' : `Automatic, ${dayLabel(now.date)}`);
  return `Prices worked out at ₹${nf(now.rate, 2)} per USD (${how}) — live: follows today's rate.`;
}

module.exports = {
  SOURCES, JUMP_PCT, CUSHION_MIN, CUSHION_MAX,
  setHttp, parse, config, pricingRateFor, latestInUse, rate, signed, current, forDate, fetchDaily, decide, history, readForm,
  dayLabel, status, orderLine,
};
