'use strict';

const db = require('../config/db');
const context = require('../helpers/orderSnapshotContext');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const termsClauseRepository = require('../repositories/termsClauseRepository');
const pricingRepository = require('../repositories/pricingRepository');
const productCategoryRepository = require('../repositories/productCategoryRepository');
const auditLogRepository = require('../repositories/auditLogRepository');

/**
 * Owner decision D-262 (6 Nov 2026, double-confirmed): an order is self-contained.
 * Port of App\Services\OrderSnapshotService — see that file for the full rule.
 *
 * When an order's PI is first approved, freeze() copies onto the order the
 * clauses, tolerance and validity days, company details, category rows,
 * payment preset approval flag, the USD rate for USD-per-unit commission on a
 * non-USD order, and each line's Zoho item. within() runs document work with
 * those frozen values. Before the freeze an order reads the live values.
 */
const COMPANY_KEYS = [
  'legal_name', 'registered_office', 'corporate_office', 'gstin', 'iec_pan',
  'md_name', 'md_title', 'phone', 'email',
  'bank_name', 'bank_branch', 'bank_account_no', 'swift_bic', 'ifsc', 'bank_address',
  'lut_number', 'lut_valid_fy',
  'rcmc_number', 'rcmc_valid_until',
  'rbi_purpose_code_advance', 'rbi_purpose_code_balance', 'rbi_purpose_code_freight',
  'non_usd_price_buffer_pct',
  'generated_document_disclaimer_text', 'show_generated_document_disclaimer',
  'quantity_shortfall_tolerance_pct', 'quotation_validity_days', 'pi_validity_days',
];

const REFRESHABLE_KEYS = [
  'legal_name', 'registered_office', 'corporate_office', 'gstin', 'iec_pan',
  'md_name', 'md_title', 'phone', 'email',
  'bank_name', 'bank_branch', 'bank_account_no', 'swift_bic', 'ifsc', 'bank_address',
  'lut_number', 'lut_valid_fy',
  'rcmc_number', 'rcmc_valid_until',
  'rbi_purpose_code_advance', 'rbi_purpose_code_balance', 'rbi_purpose_code_freight',
  'generated_document_disclaimer_text', 'show_generated_document_disclaimer',
];

function parse(raw) {
  if (raw === null || raw === undefined || raw === '') return null;
  if (typeof raw === 'object') return raw;
  try { return JSON.parse(raw) || null; } catch (e) { return null; }
}

/** The order's frozen snapshot, or null while its PI is not yet approved. */
async function snapshot(orderId) {
  const row = await db.queryOne('SELECT frozen_snapshot_json FROM orders WHERE id = :id', { id: orderId });
  return row ? parse(row.frozen_snapshot_json) : null;
}

async function isFrozen(orderId) {
  return (await snapshot(orderId)) !== null;
}

async function liveCompany() {
  const out = {};
  for (const key of COMPANY_KEYS) out[key] = await companySettingsRepository.get(key);
  return out;
}

/** D-262: the company's own values (no division overlay) as JSON — kept by DOM / IMP / MTT records. */
async function companyValuesJson() {
  const out = {};
  for (const key of REFRESHABLE_KEYS) out[key] = await companySettingsRepository.companyValue(key);
  return JSON.stringify(out);
}

/** D-262: a reader of a record's company values — its own snapshot when it has one, else today's. */
function companyReader(snapshotJson) {
  const frozen = parse(snapshotJson);
  return async (k) => {
    if (frozen && Object.prototype.hasOwnProperty.call(frozen, k)) return String(frozen[k] ?? '');
    return String((await companySettingsRepository.companyValue(k)) ?? '');
  };
}

async function captureCategories(orderId) {
  const rows = await db.query(
    `SELECT DISTINCT COALESCE(op.category_id, (SELECT pc.id FROM product_categories pc WHERE pc.is_system = 1 ORDER BY pc.id LIMIT 1)) AS cid
       FROM order_products op
      WHERE op.order_id = :o AND op.line_type = 'goods'`,
    { o: orderId }
  );
  const out = {};
  for (const cid of rows.map((r) => Number(r.cid)).filter((v) => v > 0)) {
    const c = await productCategoryRepository.find(cid);
    if (c) {
      out[String(cid)] = {
        id: Number(c.id), name: String(c.name), is_system: Number(c.is_system || 0),
        tc_clause_set: c.tc_clause_set ?? null, packing_type: c.packing_type ?? null, quality_documents: c.quality_documents ?? null,
      };
    }
  }
  return out;
}

/** Freezes the order once, when its PI is first approved. Returns true when this call froze it. */
async function freeze(orderId) {
  const row = await db.queryOne('SELECT id, frozen_at, payment_preset_id FROM orders WHERE id = :id', { id: orderId });
  if (!row || row.frozen_at !== null) return false;
  const company = await liveCompany();
  const snap = {
    version: 1,
    company,
    terms: await termsClauseRepository.captureAll(),
    categories: await captureCategories(orderId),
  };
  let requiresMd = null;
  if (row.payment_preset_id !== null) {
    const p = await db.queryOne('SELECT requires_md_approval FROM payment_presets WHERE id = :id', { id: row.payment_preset_id });
    requiresMd = p ? Number(p.requires_md_approval) : null;
  }
  // D-340 follow-up (owner, 12 Nov 2026): the rate prices were actually built with — the automatic
  // pricing rate when Automatic is on (market × (1 − cushion)), else the manual rate — not the raw setting.
  const fx = await require('./fxRateService').rate(await pricingRepository.settings());
  const tolerance = company.quantity_shortfall_tolerance_pct;
  await db.execute(
    `UPDATE orders SET frozen_at = NOW(), frozen_snapshot_json = :j, quantity_tolerance_pct = :t,
            payment_terms_requires_md_approval = :m, pricing_fx_rate_at_pi = :fx
      WHERE id = :id AND frozen_at IS NULL`,
    {
      j: JSON.stringify(snap),
      t: tolerance !== null && tolerance !== undefined && tolerance !== '' && !Number.isNaN(Number(tolerance)) ? tolerance : null,
      m: requiresMd,
      fx: fx !== null && fx !== undefined && fx !== '' && Number(fx) > 0 ? fx : null,
      id: orderId,
    }
  );
  await db.execute(
    `UPDATE order_products op JOIN catalog_products cp ON cp.id = op.catalog_product_id
        SET op.zoho_item_id = cp.zoho_item_id
      WHERE op.order_id = :o AND op.zoho_item_id IS NULL AND cp.zoho_item_id IS NOT NULL`,
    { o: orderId }
  );
  return true;
}

/** The MD's "Refresh company details on this order" — returns why not, or null. Logged with the reason. */
async function refreshCompany(orderId, userId, reason) {
  const r = String(reason ?? '').trim();
  if (r.length < 10) return 'Give a reason of at least 10 characters (e.g. "Bank account changed to the new SBI account").';
  const snap = await snapshot(orderId);
  if (snap === null) return 'This order still uses the current company details: they are kept on the order when its PI is approved.';
  const old = snap.company || {};
  const live = await liveCompany();
  const fresh = { ...old };
  for (const key of REFRESHABLE_KEYS) fresh[key] = live[key];
  snap.company = fresh;
  const now = new Date(Date.now() + 330 * 60000).toISOString().slice(0, 19).replace('T', ' ');
  snap.company_refreshed_at = now;
  await db.execute('UPDATE orders SET frozen_snapshot_json = :j WHERE id = :id', { j: JSON.stringify(snap), id: orderId });
  const changed = REFRESHABLE_KEYS.filter((k) => String(old[k] ?? '') !== String(fresh[k] ?? ''));
  const pick = (o) => Object.fromEntries(changed.map((k) => [k, o[k] ?? null]));
  await auditLogRepository.log(userId, 'ORDER_COMPANY_DETAILS_REFRESHED', 'orders', orderId, 'frozen_snapshot_json.company',
    JSON.stringify(pick(old)), JSON.stringify(pick(fresh)), r);
  return null;
}

/** Runs fn with the order's frozen company values and clauses in place of the live ones (no-op before the freeze). */
async function within(orderId, fn) {
  const snap = await snapshot(orderId);
  if (snap === null) return fn();
  return context.run({ company: snap.company || {}, terms: snap.terms || {} }, fn);
}

/** The category row the order's documents use: frozen when the order is frozen and knew it, else live. */
async function category(orderId, categoryId) {
  const snap = await snapshot(orderId);
  const frozen = snap && snap.categories ? snap.categories[String(categoryId)] : null;
  return frozen || productCategoryRepository.find(categoryId);
}

/** The tolerance % the order prints and enforces: frozen, else the live setting. */
async function tolerancePct(order) {
  if (order && order.quantity_tolerance_pct !== null && order.quantity_tolerance_pct !== undefined) return Number(order.quantity_tolerance_pct);
  return Number((await companySettingsRepository.get('quantity_shortfall_tolerance_pct')) ?? 5);
}

module.exports = {
  COMPANY_KEYS, REFRESHABLE_KEYS, snapshot, isFrozen, freeze, refreshCompany, within, category, tolerancePct,
  companyValuesJson, companyReader, liveCompany,
};
