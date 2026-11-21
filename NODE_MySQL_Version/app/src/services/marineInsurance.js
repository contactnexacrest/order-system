'use strict';

const db = require('../config/db');
const { phpRound } = require('../helpers/money');

/**
 * Port of App\Services\MarineInsurance — owner decision D-402 (21 Nov 2026, "approve A"): the indicative
 * insurance on a CIF order is worked out from the CIF value — the premium rate × the cover (110 %) × CIF value,
 * where the CIF value is FOB + freight + the insurance itself:
 *
 *     premium = rate × cover × (FOB + freight) ÷ (1 − rate × cover)
 *
 * The rate is the highest among the order's goods-line categories (Natural Stone 0.30 % incl. breakage),
 * else the default rate in Admin › Global pricing settings › Marine insurance. Optional minimum premium,
 * rounding up to the next whole unit (default), GST not added (NexaCrest's own cost; input tax credit).
 * Only a default: staff can change it on the order, and the buyer is billed the actual premium by Debit Note.
 * CFR and FOB orders have no insurance (D-049). Same rules in the PHP twin (Services/MarineInsurance.php).
 */

const DEFAULTS = {
  insurance_cover_pct: '110.00',
  insurance_rate_pct: '0.2000',
  insurance_min_premium: '',
  insurance_rounding: 'up',
  insurance_add_gst: '0',
  insurance_cover_wording: 'ICC (A) all risks',
};

const LABELS = {
  insurance_cover_pct: 'Insurance cover (% of CIF value)',
  insurance_rate_pct: 'Default insurance premium rate (%)',
  insurance_min_premium: 'Minimum insurance premium per shipment',
  insurance_rounding: 'Insurance rounding',
  insurance_add_gst: 'GST on the insurance premium',
  insurance_cover_wording: 'Default cover wording on documents',
};

const GST_PCT = 18.0;

const isNumeric = (v) => v !== null && v !== undefined && typeof v !== 'boolean' && String(v).trim() !== '' && /^\s*[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?\s*$/.test(String(v));

/** PHP rtrim(rtrim($v, '0'), '.') on a stored decimal ("0.3000" → "0.3"). */
function trimRate(v) {
  return String(v).includes('.') ? String(v).replace(/0+$/, '').replace(/\.$/, '') : String(v);
}

/** @returns {Promise<{cover_pct: number, rate_pct: number, min_premium: ?number, rounding: string, add_gst: boolean, cover_wording: string}>} */
async function settings(raw = null) {
  const r = { ...DEFAULTS, ...(raw ?? (await require('../repositories/pricingRepository').settings())) };
  // PHP's "$raw + DEFAULTS": a key present with null still falls back (array + keeps only set keys; null is set,
  // but the pricing_settings table never stores null, so treat null/undefined as missing).
  for (const k of Object.keys(DEFAULTS)) {
    if (r[k] === null || r[k] === undefined) r[k] = DEFAULTS[k];
  }
  const min = String(r.insurance_min_premium).trim();
  const wording = String(r.insurance_cover_wording).trim();
  return {
    cover_pct: parseFloat(r.insurance_cover_pct) || 0,
    rate_pct: parseFloat(r.insurance_rate_pct) || 0,
    min_premium: min !== '' && (parseFloat(min) || 0) > 0 ? parseFloat(min) : null,
    rounding: String(r.insurance_rounding) === '2dp' ? '2dp' : 'up',
    add_gst: String(r.insurance_add_gst) === '1',
    cover_wording: wording !== '' ? wording : DEFAULTS.insurance_cover_wording,
  };
}

/** The premium for a FOB value and freight at a rate. */
function premium(fob, freight, ratePct, s) {
  fob = Number(fob);
  freight = Number(freight);
  const k = (Number(ratePct) / 100) * (s.cover_pct / 100);
  if (fob + freight <= 0 || k <= 0 || k >= 1) {
    return 0;
  }
  let p = k * (fob + freight) / (1 - k);
  if (s.add_gst) {
    p *= 1 + GST_PCT / 100;
  }
  if (s.min_premium !== null && s.min_premium !== undefined && p < s.min_premium) {
    p = s.min_premium;
  }
  p = phpRound(p, 6);
  return s.rounding === '2dp' ? phpRound(p, 2) : Math.ceil(p - 1e-9);
}

/** @returns {Promise<Array<object>>} */
async function categories() {
  return db.query('SELECT id, name, is_system, insurance_rate_pct, insurance_cover_wording FROM product_categories ORDER BY id');
}

/**
 * The rate and cover wording for a set of category ids (null = the system category): the highest
 * category rate; a category without its own rate uses the default.
 * @returns {Promise<{rate_pct: number, wording: string, category: ?string}>}
 */
async function rateFor(categoryIds, s = null) {
  s = s ?? (await settings());
  let best = { rate_pct: s.rate_pct, wording: s.cover_wording, category: null };
  let found = false;
  for (const c of await categories()) {
    const id = Number(c.id);
    const wanted = categoryIds.includes(id) || (Number(c.is_system) === 1 && categoryIds.includes(null));
    if (!wanted) continue;
    const rate = c.insurance_rate_pct !== null && c.insurance_rate_pct !== '' ? parseFloat(c.insurance_rate_pct) : s.rate_pct;
    const w = String(c.insurance_cover_wording ?? '').trim();
    const wording = w !== '' ? w : s.cover_wording;
    if (!found || rate > best.rate_pct) {
      best = { rate_pct: rate, wording, category: String(c.name) };
      found = true;
    }
  }
  return best;
}

/** @returns {Promise<Array<?number>>} the categories of an order's active goods lines (null = the system category) */
async function orderCategoryIds(orderId) {
  const rows = await db.query(
    "SELECT DISTINCT category_id FROM order_products WHERE order_id = :o AND line_type = 'goods' AND is_active = 1",
    { o: orderId },
  );
  const ids = rows.map((r) => (r.category_id === null ? null : Number(r.category_id)));
  return ids.length === 0 ? [null] : ids;
}

/** The order's FOB value (the same figure the documents print), or null when nothing is priced yet. */
async function orderFob(orderId) {
  const fob = phpRound(Number(await require('../repositories/orderProductRepository').totalFobValue(orderId)) || 0, 2);
  return fob > 0 ? fob : null;
}

/** The calculated premium for an order, or null when it cannot be worked out yet (no priced lines or no freight). */
async function forOrder(orderId, freight) {
  const fob = await orderFob(orderId);
  if (fob === null || fob <= 0 || !isNumeric(freight)) {
    return null;
  }
  const s = await settings();
  return premium(fob, parseFloat(freight), (await rateFor(await orderCategoryIds(orderId), s)).rate_pct, s);
}

/**
 * A CIF order with freight but no insurance figure gets the calculated one (server side, so a form
 * saved without the browser's calculator still gets it). Any figure staff typed is kept.
 */
async function fillIfEmpty(orderId) {
  const o = await db.queryOne('SELECT incoterm_code, indicative_freight_amount, indicative_insurance_amount FROM orders WHERE id = :id', { id: orderId });
  if (!o || String(o.incoterm_code ?? '').toUpperCase() !== 'CIF'
    || (o.indicative_insurance_amount !== null && String(o.indicative_insurance_amount).trim() !== '')) {
    return;
  }
  const p = await forOrder(orderId, o.indicative_freight_amount);
  if (p !== null && p > 0) {
    await db.execute('UPDATE orders SET indicative_insurance_amount = :p WHERE id = :id', { p: phpRound(p, 2).toFixed(2), id: orderId });
  }
}

/** The same for the New Order form's lines (before the order exists). */
async function forLines(lines, freight) {
  let fob = 0;
  const cats = [];
  for (const l of lines) {
    if (isNumeric(l.quantity) && isNumeric(l.unit_price)) {
      fob += parseFloat(l.quantity) * parseFloat(l.unit_price);
    }
    cats.push(l.category_id ?? null);
  }
  if (fob <= 0 || !isNumeric(freight)) {
    return null;
  }
  const s = await settings();
  return premium(phpRound(fob, 2), parseFloat(freight), (await rateFor(cats.length === 0 ? [null] : cats, s)).rate_pct, s);
}

/** PHP (string) of a float: 110.0 → "110", 0.2 → "0.2". */
const floatStr = (n) => String(Number(n));

/**
 * The data the order forms' calculator reads (data-ins-* attributes on the freight/insurance fieldset).
 * @returns {Promise<Object<string,string>>}
 */
async function formAttributes(orderId = null) {
  const s = await settings();
  const cats = {};
  for (const c of await categories()) {
    cats[Number(c.id)] = [
      c.insurance_rate_pct !== null && c.insurance_rate_pct !== '' ? parseFloat(c.insurance_rate_pct) : null,
      String(c.insurance_cover_wording ?? '').trim(),
      String(c.name),
      Number(c.is_system),
    ];
  }
  const products = {};
  for (const p of await db.query('SELECT id, category_id FROM catalog_products WHERE category_id IS NOT NULL')) {
    products[Number(p.id)] = Number(p.category_id);
  }
  const attrs = {
    'data-ins-cover': floatStr(s.cover_pct),
    'data-ins-rate': floatStr(s.rate_pct),
    'data-ins-wording': s.cover_wording,
    'data-ins-min': s.min_premium !== null ? floatStr(s.min_premium) : '',
    'data-ins-round': s.rounding,
    'data-ins-gst': s.add_gst ? '1' : '0',
    'data-ins-categories': phpJson(cats),
    'data-ins-products': phpJson(products),
  };
  if (orderId !== null && orderId !== undefined) {
    const fob = await orderFob(orderId);
    attrs['data-ins-fob'] = fob !== null ? floatStr(fob) : '';
    attrs['data-ins-order-categories'] = phpJson(await orderCategoryIds(orderId));
  }
  return attrs;
}

/**
 * PHP json_encode(): an empty map is "[]", "/" is escaped as "\/" and non-ASCII as \uXXXX, so the
 * attribute reads the same on both stacks.
 */
function phpJson(v) {
  if (v && typeof v === 'object' && !Array.isArray(v) && Object.keys(v).length === 0) return '[]';
  return JSON.stringify(v)
    .replace(/\//g, '\\/')
    .replace(/[\u007f-￿]/g, (ch) => `\\u${ch.charCodeAt(0).toString(16).padStart(4, '0')}`);
}

/** PHP htmlspecialchars($v, ENT_QUOTES). */
function escAttr(v) {
  return String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

/** The attributes as an HTML attribute string (values HTML-escaped). */
async function formAttributeHtml(orderId = null) {
  let out = '';
  for (const [k, v] of Object.entries(await formAttributes(orderId))) {
    out += ` ${k}="${escAttr(v)}"`;
  }
  return out;
}

module.exports = {
  DEFAULTS, LABELS, GST_PCT, settings, premium, rateFor, categories, orderCategoryIds, orderFob, forOrder, fillIfEmpty, forLines,
  formAttributes, formAttributeHtml, trimRate,
};
