'use strict';

const db = require('../config/db');

/**
 * Port of App\Services\AssociateAccess. D-261 (owner approval 6 Nov 2026, mockups
 * mock13): associate portal access levels.
 *
 * Each associate is on Starter, Quoting, Full or Custom. What Starter / Quoting / Full
 * show is the MD's matrix (Admin › Associates › Access levels); a Custom associate has
 * their own ticks. This module is the one place that decides — every portal page, menu
 * item, column, download and notice asks allows(). Hidden features are refused by the
 * server (the lock page, HTTP 403), not only hidden from the menu. Cost, margin,
 * supplier prices and CAFIN are never shown at any level (unchanged).
 */
const LEVELS = { starter: 'Starter', quoting: 'Quoting', full: 'Full' };
const ALL_LEVELS = { ...LEVELS, custom: 'Custom' };

/** feature_key => [label, help]. 'profile' is always on. */
const FEATURES = {
  profile: ['Profile, bank & agreement', 'Always on'],
  clients: ['My clients', 'Count and list of clients they brought in'],
  orders: ["Clients' orders and stage", 'Order number, stage, last update'],
  fob_value: ['Show FOB order value', 'Order value of their own orders'],
  commission: ['Show commission per order', 'Amount earned, due, paid'],
  commission_pct: ['Show commission % on screen', 'The rate behind each amount'],
  registration_links: ['Registration links', 'Invite clients, personal code'],
  catalogue: ['Catalogue (no prices)', 'Products in the categories opened for them'],
  get_price: ['Get price / quoting', 'Within Price access categories; 10 a day, valid 7 days'],
  price_requests: ['Price requests ("Other")', 'Ask staff for a price not in the list'],
  messages: ['Messages', 'Messages with NexaCrest'],
  order_documents: ['Documents of their orders', 'PI, CI and other issued documents'],
  tds_certificates: ['TDS certificates', 'Form 16A / TDS deducted'],
  commission_statement: ['Commission statement download', 'PDF/CSV of commission'],
  notice_amounts: ['Order amounts in messages and e-mails', 'Amounts in notifications they receive'],
};

/** The recommended defaults (mockup 6). "Restore recommended defaults" puts these back. */
const DEFAULTS = {
  starter: ['clients', 'orders', 'commission', 'registration_links', 'messages', 'tds_certificates'],
  quoting: ['clients', 'orders', 'fob_value', 'commission', 'commission_pct', 'registration_links', 'catalogue', 'get_price', 'price_requests', 'messages', 'tds_certificates', 'commission_statement'],
  full: ['clients', 'orders', 'fob_value', 'commission', 'commission_pct', 'registration_links', 'catalogue', 'get_price', 'price_requests', 'messages', 'order_documents', 'tds_certificates', 'commission_statement', 'notice_amounts'],
};

/** Kinds of the associate's own documents that are TDS paperwork (shown only with "TDS certificates"). */
const TDS_KINDS = ['form_16a', 'form_15ca', 'form_15cb'];

/** Kept for parity with PHP (which caches per request); Node reads the matrix fresh each time. */
function resetCache() {}

function defaultMatrix() {
  const m = {};
  for (const level of Object.keys(LEVELS)) {
    m[level] = {};
    for (const f of Object.keys(FEATURES)) {
      m[level][f] = f === 'profile' || DEFAULTS[level].includes(f);
    }
  }
  return m;
}

/** level => feature => allowed (the MD's matrix). */
async function matrix() {
  // Read fresh every time: Node is one long-running process, and the matrix is a few rows.
  const m = defaultMatrix();
  for (const r of await db.query('SELECT level, feature_key, allowed FROM associate_access_level_settings')) {
    if (m[r.level] && FEATURES[r.feature_key] && r.feature_key !== 'profile') {
      m[r.level][r.feature_key] = Number(r.allowed) === 1;
    }
  }
  return m;
}

function level(associate) {
  const l = String((associate && associate.access_level) || 'full');
  return ALL_LEVELS[l] ? l : 'full';
}

/** feature => allowed for this associate. */
async function features(associate) {
  const lv = level(associate);
  const m = await matrix();
  if (lv !== 'custom') return { ...m[lv] };
  const out = { ...m.starter }; // a Custom associate starts from Starter for anything not ticked yet
  for (const r of await db.query('SELECT feature_key, allowed FROM associate_access_features WHERE associate_id = :a', { a: Number(associate.id) })) {
    if (FEATURES[r.feature_key]) out[r.feature_key] = Number(r.allowed) === 1;
  }
  out.profile = true;
  return out;
}

async function allows(associate, feature) {
  if (feature === 'profile') return true;
  return !!(await features(associate))[feature];
}

/**
 * The feature(s) a portal address needs (any one of them opens it); null when only the
 * login itself is needed (home, profile, bank, agreement, logout).
 */
function featuresForPath(method, path, query = {}, post = {}) {
  const p = String(path || '/').replace(/\/+$/, '') || '/';
  const m = String(method || 'GET').toUpperCase();
  const rules = [
    [/^\/associate\/clients(\/\d+\/certificate)?$/, 'GET', ['clients']],
    [/^\/associate\/(invitations|code)(\/.*)?$/, 'POST', ['registration_links']],
    [/^\/associate\/orders(\/\d+)?$/, 'GET', ['orders']],
    [/^\/associate\/(prices|catalogue)$/, 'GET', ['catalogue']],
    [/^\/associate\/catalogue\/\d+$/, 'GET', ['get_price']],
    [/^\/associate\/catalogue\/\d+\/price$/, 'POST', post && post.other ? ['price_requests'] : ['get_price']],
    [/^\/associate\/price-requests\/\d+$/, 'GET', ['get_price', 'price_requests']],
    [/^\/associate\/commission\/\d+\/invoice$/, 'POST', ['commission']],
    [/^\/associate\/messages$/, '*', ['messages']],
  ];
  for (const [re, method2, feats] of rules) {
    if ((method2 === '*' || method2 === m) && re.test(p)) return feats;
  }
  if (p === '/associate/commission' && m === 'GET') {
    return ((query && query.format) === 'csv' || String((query && query.print) || '') === '1') ? ['commission_statement'] : ['commission', 'tds_certificates'];
  }
  return null;
}

async function allowsPath(associate, method, path, query = {}, post = {}) {
  const needed = featuresForPath(method, path, query, post);
  if (needed === null) return true;
  const f = await features(associate);
  return needed.some((k) => !!f[k]);
}

/**
 * One order row for the portal with what this associate may not see taken out on the
 * server: the FOB value (order, lines, basis) and the commission % / level.
 */
function filterOrder(access, o) {
  const out = { ...o };
  const fob = !!access.fob_value;
  const pct = !!access.commission_pct;
  if (!fob) out.fob_value = null;
  if (out.commission && typeof out.commission === 'object') {
    out.commission = { ...out.commission };
    if (!pct) {
      out.commission.level = null;
      out.commission.rate = null;
    }
    if (out.commission.detail && typeof out.commission.detail === 'object') {
      const d = { ...out.commission.detail };
      if (!fob) {
        d.lines = (d.lines || []).map((l) => ({ ...l, fob: null }));
        d.goods_fc = null;
        d.basis_fc = null;
        d.basis_inr = null;
        d.reduction_fc = 0;
      }
      if (!pct) {
        d.level_label = null;
        d.rate = null;
      }
      d.show_fob = fob;
      d.show_pct = pct;
      out.commission.detail = d;
    }
  }
  return out;
}

/**
 * A notice / e-mail for the associate with what they may not see blanked out: money
 * amounts without "Order amounts in messages and e-mails", percentages without
 * "Show commission % on screen".
 */
async function maskNotice(associate, message) {
  const f = await features(associate);
  let m = String(message);
  if (!f.notice_amounts) {
    m = m.replace(/(?:\b(?:USD|EUR|GBP|INR|AED|AUD|CAD|SGD|JPY|CNY|CHF|SAR)\s?|₹\s?|Rs\.?\s?)-?\d[\d,]*(?:\.\d+)?/gu, '[amount in your portal]');
  }
  if (!f.commission_pct) {
    m = m.replace(/\b\d+(?:\.\d+)?\s?%/gu, '[rate in your agreement]');
  }
  return m;
}

// ------------------------------------------------------------ changes (MD only)

async function isMd(user) {
  return require('./incentiveService').isMd(user);
}

/** "Starter" or "Custom: My clients, …" — what the history table shows. */
async function describe(associate) {
  const lv = level(associate);
  if (lv !== 'custom') return ALL_LEVELS[lv];
  const on = [];
  for (const [f, allowed] of Object.entries(await features(associate))) {
    if (allowed && f !== 'profile') on.push(FEATURES[f][0]);
  }
  return `Custom: ${on.length ? on.join(', ') : 'profile only'}`;
}

/** Sets an associate's level (and, for Custom, the ticks). MD only, reason of 10+ characters. Returns an error or null. */
async function setLevel(associate, newLevel, customTicks, reason, user) {
  if (!(await isMd(user))) return "Only the Managing Director can change an associate's portal access.";
  if (!ALL_LEVELS[newLevel]) return 'Choose a level.';
  const why = String(reason ?? '').trim();
  if ([...why].length < 10) return 'Give the reason for the change (at least 10 characters).';
  const before = await describe(associate);
  const id = Number(associate.id);
  const ticks = (customTicks || []).map(String);
  await db.transaction(async (conn) => {
    await conn.execute('UPDATE associates SET access_level = :l WHERE id = :id', { l: newLevel, id });
    if (newLevel === 'custom') {
      await conn.execute('DELETE FROM associate_access_features WHERE associate_id = :a', { a: id });
      for (const f of Object.keys(FEATURES)) {
        if (f !== 'profile') {
          await conn.execute('INSERT INTO associate_access_features (associate_id, feature_key, allowed) VALUES (:a, :f, :v)', { a: id, f, v: ticks.includes(f) ? 1 : 0 });
        }
      }
    }
  });
  const after = await describe({ ...associate, access_level: newLevel });
  await db.execute('INSERT INTO associate_access_log (associate_id, changed_by, old_value, new_value, reason) VALUES (:a, :u, :o, :n, :r)',
    { a: id, u: Number(user.id), o: before, n: after, r: [...why].slice(0, 500).join('') });
  await require('../repositories/auditLogRepository').log(Number(user.id), 'ASSOCIATE_ACCESS_CHANGED', 'associates', id, 'access_level', before, after);
  return null;
}

/** Saves the level matrix (ticks[level] = [feature, …]). MD only, reason needed. Returns an error or null. */
async function saveMatrix(ticks, reason, user, restoreDefaults = false) {
  if (!(await isMd(user))) return 'Only the Managing Director can change the level settings.';
  const why = String(reason ?? '').trim();
  if ([...why].length < 10) return 'Give the reason for the change (at least 10 characters).';
  const before = await matrix();
  let next;
  if (restoreDefaults) {
    next = defaultMatrix();
  } else {
    next = {};
    for (const lv of Object.keys(LEVELS)) {
      const list = [].concat((ticks && ticks[lv]) || []).map(String);
      next[lv] = {};
      for (const f of Object.keys(FEATURES)) next[lv][f] = f === 'profile' || list.includes(f);
    }
  }
  const changes = [];
  for (const [lv, fs] of Object.entries(next)) {
    for (const [f, on] of Object.entries(fs)) {
      if (f !== 'profile' && on !== !!(before[lv] && before[lv][f])) changes.push(`${LEVELS[lv]}: ${FEATURES[f][0]}${on ? ' on' : ' off'}`);
    }
  }
  if (!changes.length) return 'Nothing changed.';
  await db.transaction(async (conn) => {
    for (const [lv, fs] of Object.entries(next)) {
      for (const [f, on] of Object.entries(fs)) {
        if (f !== 'profile') {
          await conn.execute('INSERT INTO associate_access_level_settings (level, feature_key, allowed) VALUES (:l, :f, :v) ON DUPLICATE KEY UPDATE allowed = VALUES(allowed)', { l: lv, f, v: on ? 1 : 0 });
        }
      }
    }
    await conn.execute('INSERT INTO associate_access_log (associate_id, changed_by, old_value, new_value, reason) VALUES (NULL, :u, :o, :n, :r)',
      { u: Number(user.id), o: restoreDefaults ? 'Level settings' : null, n: (restoreDefaults ? 'Recommended defaults restored: ' : '') + changes.join('; '), r: [...why].slice(0, 500).join('') });
  });
  resetCache();
  await require('../repositories/auditLogRepository').log(Number(user.id), 'ASSOCIATE_ACCESS_LEVELS_CHANGED', 'associate_access_level_settings', null, 'matrix', null, changes.join('; '));
  return null;
}

/** Newest first; a null associate = the level-settings history. */
async function history(associateId, limit = 50) {
  const lim = Math.max(1, Number(limit) || 50);
  if (associateId === null) {
    return db.query(`SELECT l.*, u.name AS changed_by_name FROM associate_access_log l LEFT JOIN users u ON u.id = l.changed_by WHERE l.associate_id IS NULL ORDER BY l.id DESC LIMIT ${lim}`);
  }
  return db.query(`SELECT l.*, u.name AS changed_by_name FROM associate_access_log l LEFT JOIN users u ON u.id = l.changed_by WHERE l.associate_id = :a ORDER BY l.id DESC LIMIT ${lim}`, { a: Number(associateId) });
}

async function countsPerLevel() {
  const out = Object.fromEntries(Object.keys(ALL_LEVELS).map((k) => [k, 0]));
  for (const r of await db.query('SELECT access_level, COUNT(*) AS n FROM associates GROUP BY access_level')) out[r.access_level] = Number(r.n);
  return out;
}

// ------------------------------------------------------------ track record / upgrade

async function upgradeAfter() {
  const s = await require('../repositories/incentiveRepository').settings();
  return Math.max(1, parseInt(s.associate_upgrade_after_orders ?? '3', 10) || 3);
}

/** Clients brought in, orders, order value per currency, and how many orders are completed and fully paid. */
async function trackRecord(associateId) {
  const incentiveService = require('./incentiveService');
  const c = await db.queryOne('SELECT COUNT(*) AS n FROM clients WHERE associate_id = :a', { a: Number(associateId) });
  const rows = await db.query(
    `SELECT o.id, o.order_reference, o.status, o.currency_code, o.created_at, cl.company_legal_name,
            (SELECT COALESCE(SUM(op.fob_value), 0) FROM order_products op WHERE op.order_id = o.id AND op.is_active = 1 AND op.line_type <> 'service') AS order_value
       FROM orders o JOIN clients cl ON cl.id = o.client_id
      WHERE cl.associate_id = :a AND o.status NOT IN ('cancelled','lost')
      ORDER BY o.created_at DESC, o.id DESC`,
    { a: Number(associateId) },
  );
  const list = [];
  const value = {};
  let completedPaid = 0;
  for (const o of rows) {
    const paid = (await incentiveService.fullyPaidOn(Number(o.id))) !== null;
    const done = ['complete', 'shipped_docs_sent'].includes(o.status);
    if (paid && done) completedPaid += 1;
    const cur = String(o.currency_code || 'USD');
    value[cur] = (value[cur] || 0) + Number(o.order_value);
    list.push({ ...o, fully_paid: paid, completed: done });
  }
  return { clients: Number(c ? c.n : 0), orders: list.length, value, completed_paid: completedPaid, list };
}

/** True when the MD should see "Ready for the Quoting level?" for this associate. */
async function suggestUpgrade(associate, completedPaid = null) {
  if (level(associate) !== 'starter') return false;
  const n = completedPaid ?? (await trackRecord(Number(associate.id))).completed_paid;
  if (n < (await upgradeAfter())) return false;
  const dismissed = associate.upgrade_dismissed_at_count;
  return dismissed === null || dismissed === undefined || n > Number(dismissed); // "Not yet" hides it until another order is completed and paid
}

/** "Not yet": hides the suggestion until one more order is completed and fully paid. MD only. */
async function dismissUpgrade(associate, user) {
  if (!(await isMd(user))) return 'Only the Managing Director can answer this.';
  const n = (await trackRecord(Number(associate.id))).completed_paid;
  await db.execute('UPDATE associates SET upgrade_dismissed_at_count = :n WHERE id = :id', { n, id: Number(associate.id) });
  return null;
}

/** Associates the MD is asked about (dashboard). */
async function upgradeSuggestions() {
  const out = [];
  for (const a of await db.query("SELECT * FROM associates WHERE access_level = 'starter' AND status NOT IN ('ended','inactive')")) {
    const n = (await trackRecord(Number(a.id))).completed_paid;
    if (await suggestUpgrade(a, n)) out.push({ id: Number(a.id), name: String(a.name), completed_paid: n });
  }
  return out;
}

module.exports = {
  LEVELS, ALL_LEVELS, FEATURES, DEFAULTS, TDS_KINDS,
  resetCache, defaultMatrix, matrix, level, features, allows, featuresForPath, allowsPath, filterOrder, maskNotice,
  describe, setLevel, saveMatrix, history, countsPerLevel, upgradeAfter, trackRecord, suggestUpgrade, dismissUpgrade, upgradeSuggestions,
};
