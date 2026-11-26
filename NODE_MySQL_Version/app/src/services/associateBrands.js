'use strict';

/**
 * D-431 (owner 25–26 Nov 2026): brands for divisions, associate side only. PHP twin: src/Services/AssociateBrands.php.
 *
 * - A brand is the division name an associate sees (name only, no logo) with the line under it.
 *   Buyers never see a brand: their form, e-mails, documents and portal stay NexaCrest.
 * - Per-brand associate rules: the associate's own value first, then the brand's, then the
 *   company setting (empty = follows the company setting, also when it changes later).
 * - Company-wide only: payout run and day, cut-off, waiting days, TDS, KYC, bank checks.
 */
const db = require('../config/db');
const env = require('../config/env');
const repo = require('../repositories/associateBrandRepository');

const rules = () => require('./associateRules');

const STATUSES = { active: 'Active', planned: 'Planned', inactive: 'Not used' };

/** The rules a brand may set (key => [label, min, max, kind]). */
const RULES = {
  min_order_inr: ['Minimum order value that earns and counts as a step (₹, FOB of the goods)', 0, 100000000, 'money'],
  group_days: ['Same client within (days) = one ladder step', 7, 45, 'int'],
  price_rule: ["Commission in the client's price", null, null, 'choice'],
  price_fixed_pct: ["The fixed % in the client's price", 0, 50, 'pct'],
  price_requests_per_day: ['Price requests per day', 0, 1000, 'int'],
  price_valid_days: ['A price stays valid for (days)', 1, 365, 'int'],
};

/** D-431 (owner 26 Nov 2026): the reminder on the associate's Home and My clients pages. */
const BUYER_TIP = 'When you talk to buyers, introduce NexaCrest International. Prices and documents always come from NexaCrest. Never tell a buyer about your commission.';

/** The company default for the line under a brand's name. */
const DEFAULT_TAGLINE = 'a division of NexaCrest International Private Limited';

function reset() { repo.reset(); }

/** The associate's brand (null = NexaCrest: no brand, exactly as before). */
async function forAssociateId(associateId) {
  const id = Number(associateId) || 0;
  if (id <= 0) return null;
  const row = await db.queryOne('SELECT brand_id FROM associates WHERE id = :id', { id });
  const b = row ? Number(row.brand_id) || 0 : 0;
  return b > 0 ? repo.find(b) : null;
}

async function forAssociate(associate) {
  if (!associate) return null;
  if (Object.prototype.hasOwnProperty.call(associate, 'brand_id')) {
    return associate.brand_id ? repo.find(Number(associate.brand_id)) : null;
  }
  return forAssociateId(associate.id != null ? Number(associate.id) : null);
}

/** The brand's own rule values. */
async function settings(brandId) {
  return repo.settings(Number(brandId));
}

/** One rule of the associate's brand, or null (= follow the company setting). */
async function rule(associateId, key) {
  const b = await forAssociateId(associateId);
  if (!b) return null;
  const v = (await settings(b.id))[key];
  return v !== undefined && v !== null && v !== '' ? v : null;
}

/** The company values the brand rules fall back to (Associates › Settings, Admin › Pricing, Price access). */
async function companyValues() {
  const inc = await require('../repositories/incentiveRepository').settings();
  const price = await rules().companyPriceRule();
  const pricingService = require('./pricingService');
  const ps = await pricingService.settings();
  const raw = { ...pricingService.DEFAULTS, ...(await require('../repositories/pricingRepository').settings()) };
  const num = (v) => String(Number(v));
  return {
    min_order_inr: num(inc.associate_counting_threshold_inr ?? 500000),
    group_days: String(await rules().companyGroupDays()),
    price_rule: price.rule,
    price_fixed_pct: num(raw.associate_price_fixed_pct ?? 5),
    price_requests_per_day: String(parseInt(ps.price_request_daily_limit, 10)),
    price_valid_days: String(parseInt(ps.price_request_valid_days, 10)),
  };
}

/** The brand's own ladder, or [] (follows the company ladder). */
async function ladderFor(associate) {
  const b = await forAssociate(associate);
  return b ? repo.ladder(Number(b.id)) : [];
}

/** The name an associate sees at the top of the portal and e-mails. */
function displayName(brand) { return brand ? String(brand.name) : 'NexaCrest'; }
function tagline(brand) { return brand ? String(brand.tagline) : ''; }

/** The footer every associate page and e-mail of a branded associate carries. */
function legalLine(brand) {
  if (!brand) return '';
  return `${brand.name} is a division of NexaCrest International Private Limited. Your agreement and your payments are with NexaCrest International Private Limited.`;
}

/** Validates a brand rules form (empty = follows the company setting). Returns [values, errors]. */
function validateRules(post) {
  const out = {};
  const errors = [];
  const PRICE_RULES = rules().PRICE_RULES;
  for (const [key, [label, min, max, kind]] of Object.entries(RULES)) {
    let raw = String(post[key] ?? '').trim();
    if (raw === '') { out[key] = null; continue; }
    if (kind === 'choice') {
      if (!PRICE_RULES[raw]) errors.push(`Choose a valid option for "${label}".`);
      else out[key] = raw;
      continue;
    }
    raw = raw.replace(/,/g, '');
    const ok = kind === 'int' ? /^[+-]?\d+$/.test(raw) : /^[+-]?(\d+\.?\d*|\.\d+)(e[+-]?\d+)?$/i.test(raw);
    const n = Number(raw);
    if (!ok || !Number.isFinite(n) || n < min || n > max) {
      errors.push(`"${label}" must be between ${min} and ${max}, or empty to follow the company setting.`);
      continue;
    }
    out[key] = kind === 'int' ? String(Math.trunc(n)) : n.toFixed(2);
  }
  if ((out.price_rule ?? null) !== 'fixed') {
    out.price_fixed_pct = null;
  } else if ((out.price_fixed_pct ?? null) === null) {
    errors.push('Enter the fixed % for "A fixed %".');
  }
  return [out, errors];
}

/**
 * D-431: an e-mail to an associate in their brand's name — the subject's "NexaCrest" becomes the brand name, the
 * body starts with the brand and its line and ends with the legal line. No brand: unchanged. Returns [subject, body].
 */
async function email(associate, subject, body) {
  const brand = await forAssociate(associate);
  if (!brand) return [subject, body];
  const name = String(brand.name);
  const s = subject.split('NexaCrest').join(name);
  let b = body.split('NexaCrest relationship manager').join(`${name} relationship manager`);
  b = b.replace(/\n\nNexaCrest International Private Limited$/, `\n\n${name} associates team`);
  return [s, `${name} — ${brand.tagline}\n\n${b}\n\n${legalLine(brand)}`];
}

// ------------------------------------------------------------ D-432 brand managers

/** D-432 (owner 26 Nov 2026): the brand a staff member looks after (users.brand_id); null = all brands, as before. */
function userBrandId(user = null) {
  const u = user || ((require('../helpers/requestContext').get() || {}).divisionUser) || null;
  const b = Number((u && u.brand_id) || 0);
  return b > 0 ? b : null;
}

/** " AND {col} = {brand}" for a brand manager, else "" (the brand id is an integer, safe to inline). */
function scopeSql(column = 'a.brand_id') {
  const b = userBrandId();
  return b === null ? '' : ` AND ${column} = ${b}`;
}

/** Whether the signed-in staff member may see this associate (always, unless they manage another brand). */
function inScope(associate, user = null) {
  const b = userBrandId(user);
  return b === null || (!!associate && Number(associate.brand_id || 0) === b);
}

/** Company-wide pages a brand manager may not change (D-432: they change only their own brand). */
const COMPANY_WIDE_POSTS = ['/associates/ladder', '/associates/access-levels', '/associates/registrations/settings',
  '/pricing/commission-levels', '/pricing/commission-ladder', '/pricing/settings/associate-price-rule', '/admin/brands'];

/**
 * D-432: for a brand manager, the refusal (404 text) for a page or action of another brand, a company-wide setting or
 * brand details; null = allowed. Runs after the route's own sign-in / permission / CSRF checks. PHP twin: refusalForPath().
 */
async function refusalForPath(path, method, user) {
  const mine = userBrandId(user);
  if (mine === null) return null;
  const brandOf = async (sql, id) => {
    const r = await db.queryOne(sql, { id: Number(id) });
    const v = r ? Object.values(r)[0] : null;
    return v === null || v === undefined ? null : Number(v);
  };
  const other = 'Not found.';
  let m;
  if (method === 'POST' && (COMPANY_WIDE_POSTS.includes(path) || /^\/pricing\/commission-levels\/\d+/.test(path)
      || /^\/associates\/\d+\/brand$/.test(path) || /^\/admin\/brands\/\d+$/.test(path))) {
    return other; // a company-wide setting or brand details — only staff who look after all brands change them
  }
  if ((m = path.match(/^\/admin\/brands\/(\d+)/))) return Number(m[1]) === mine ? null : other;
  if ((m = path.match(/^\/associates\/(\d+)(\/|$)/))) return (await brandOf('SELECT brand_id AS b FROM associates WHERE id = :id', m[1])) === mine ? null : other;
  if ((m = path.match(/^\/associates\/(?:payouts|tds)\/(\d+)\//))) {
    return (await brandOf('SELECT a.brand_id AS b FROM incentive_payouts p JOIN associates a ON a.id = p.associate_id WHERE p.id = :id', m[1])) === mine ? null : other;
  }
  if ((m = path.match(/^\/associates\/price-requests\/(\d+)\//))) {
    return (await brandOf('SELECT a.brand_id AS b FROM price_requests r JOIN associates a ON a.id = r.associate_id WHERE r.id = :id', m[1])) === mine ? null : other;
  }
  if ((m = path.match(/^\/associates\/registrations\/links\/(\d+)\//))) {
    return (await brandOf('SELECT brand_id AS b FROM associate_registration_links WHERE id = :id', m[1])) === mine ? null : other;
  }
  if ((m = path.match(/^\/associates\/registrations\/(\d+)\//))) {
    return (await brandOf('SELECT l.brand_id AS b FROM associate_registrations r JOIN associate_registration_links l ON l.id = r.link_id WHERE r.id = :id', m[1])) === mine ? null : other;
  }
  return null;
}

/** Login address codes that are already pages of the portal. */
const RESERVED_CODES = ['code'];

/** A login address code: lower-case letters, digits and hyphens, 2–30 characters. */
function validCode(code) {
  const c = String(code || '');
  return /^[a-z0-9](?:[a-z0-9-]{0,28}[a-z0-9])?$/.test(c) && c.length >= 2 && !RESERVED_CODES.includes(c);
}

const SESSION_KEY = '_associate_login_brand';
const COOKIE = 'nx_assoc_brand';

function cookieFrom(req) {
  const header = (req && req.headers && req.headers.cookie) || '';
  for (const part of header.split(';')) {
    const i = part.indexOf('=');
    if (i > 0 && part.slice(0, i).trim() === COOKIE) {
      try { return decodeURIComponent(part.slice(i + 1).trim()); } catch (e) { return ''; }
    }
  }
  return '';
}

/** Remembers the brand of a login address in the session and, for a year, in the browser. */
function rememberLoginBrand(req, res, brand) {
  if (req && req.session) req.session[SESSION_KEY] = String(brand.code);
  if (res && typeof res.cookie === 'function' && !res.headersSent) {
    res.cookie(COOKIE, String(brand.code), { maxAge: 365 * 86400 * 1000, path: '/associate', secure: !env.isLocal(), httpOnly: true, sameSite: 'lax' });
  }
}

/** The brand shown on the login pages before the associate is known (login address used, or remembered); null = NexaCrest. */
async function loginBrand(req) {
  const code = String((req && req.session && req.session[SESSION_KEY]) || cookieFrom(req) || '');
  return code !== '' && validCode(code) ? repo.findByCode(code) : null;
}

module.exports = {
  STATUSES, RULES, BUYER_TIP, DEFAULT_TAGLINE, RESERVED_CODES, SESSION_KEY, COOKIE,
  reset, forAssociateId, forAssociate, settings, rule, companyValues, ladderFor, displayName, tagline, legalLine,
  validateRules, email, validCode, rememberLoginBrand, loginBrand, userBrandId, scopeSql, inScope, refusalForPath,
};
