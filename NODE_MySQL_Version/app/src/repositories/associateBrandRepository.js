'use strict';

const db = require('../config/db');

/**
 * Port of AssociateBrandRepository.php. D-431 (owner 25–26 Nov 2026): brands for divisions, associate side only.
 * A brand is the division name an associate sees (name only, no logo), with its own associate rules
 * (empty = the company setting) and, optionally, its own ladder.
 */
function reset() {}

async function all() {
  return db.query(
    `SELECT b.*, (SELECT COUNT(*) FROM associates a WHERE a.brand_id = b.id) AS associate_count
       FROM associate_brands b WHERE 1 = 1${require('../services/associateBrands').scopeSql('b.id')} ORDER BY FIELD(b.status, 'active', 'planned', 'inactive'), b.name`
  );
}

async function usable() {
  return db.query(`SELECT * FROM associate_brands WHERE status <> 'inactive'${require('../services/associateBrands').scopeSql('id')} ORDER BY name`); // D-432
}

async function find(id) {
  return db.queryOne('SELECT * FROM associate_brands WHERE id = :id', { id: Number(id) || 0 });
}

async function findByCode(code) {
  return db.queryOne("SELECT * FROM associate_brands WHERE code = :c AND status = 'active'", { c: String(code || '').trim().toLowerCase() });
}

async function nameTaken(name, exceptId = null) {
  const r = await db.queryOne('SELECT COUNT(*) AS n FROM associate_brands WHERE name = :n AND id <> :id', { n: name, id: Number(exceptId) || 0 });
  return Number(r.n) > 0;
}

async function codeTaken(code, exceptId = null) {
  const r = await db.queryOne('SELECT COUNT(*) AS n FROM associate_brands WHERE code = :c AND id <> :id', { c: code, id: Number(exceptId) || 0 });
  return Number(r.n) > 0;
}

async function save(id, d, userId) {
  const p = { code: d.code, name: d.name, tagline: d.tagline, products: d.products ?? null, website: d.website ?? null, email: d.email ?? null, status: d.status, u: userId };
  if (id === null || id === undefined) {
    const res = await db.execute(
      `INSERT INTO associate_brands (code, name, tagline, products, website, email, status, created_by, updated_by)
       VALUES (:code, :name, :tagline, :products, :website, :email, :status, :u, :u2)`, { ...p, u2: userId });
    return Number(res.insertId);
  }
  await db.execute(
    `UPDATE associate_brands SET code = :code, name = :name, tagline = :tagline, products = :products, website = :website,
            email = :email, status = :status, updated_by = :u WHERE id = :id`, { ...p, id: Number(id) });
  return Number(id);
}

/** The brand's own rule values (missing = follows the company setting). */
async function settings(brandId) {
  const rows = await db.query('SELECT setting_key, setting_value FROM associate_brand_settings WHERE brand_id = :b', { b: Number(brandId) });
  const out = {};
  for (const r of rows) out[String(r.setting_key)] = String(r.setting_value);
  return out;
}

/** values: null or '' = remove (follow the company setting). */
async function saveSettings(brandId, values, userId) {
  for (const [k, v] of Object.entries(values)) {
    if (v === null || v === undefined || String(v).trim() === '') {
      await db.execute('DELETE FROM associate_brand_settings WHERE brand_id = :b AND setting_key = :k', { b: Number(brandId), k });
    } else {
      await db.execute(
        `INSERT INTO associate_brand_settings (brand_id, setting_key, setting_value, updated_by) VALUES (:b, :k, :v, :u)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)`,
        { b: Number(brandId), k, v: String(v).trim(), u: userId });
    }
  }
}

/** The brand's own ladder ([] = follows the company ladder). */
async function ladder(brandId) {
  return db.query(
    `SELECT s.id, s.from_order, s.to_order, s.level_id, l.name AS level_name, l.kind, l.value, l.is_active
       FROM associate_brand_ladder_steps s JOIN commission_levels l ON l.id = s.level_id
      WHERE s.brand_id = :b ORDER BY s.from_order`, { b: Number(brandId) });
}

/** steps: [{from, to, level_id}] — [] = remove (follow the company ladder). */
async function replaceLadder(brandId, steps, userId) {
  await db.execute('DELETE FROM associate_brand_ladder_steps WHERE brand_id = :b', { b: Number(brandId) });
  for (const s of steps) {
    await db.execute('INSERT INTO associate_brand_ladder_steps (brand_id, from_order, to_order, level_id, updated_by) VALUES (:b, :f, :t, :l, :u)',
      { b: Number(brandId), f: s.from, t: s.to, l: s.level_id, u: userId });
  }
}

async function setAssociateBrand(associateId, brandId) {
  await db.execute('UPDATE associates SET brand_id = :b WHERE id = :id', { b: brandId ? Number(brandId) : null, id: Number(associateId) });
}

module.exports = { reset, all, usable, find, findByCode, nameTaken, codeTaken, save, settings, saveSettings, ladder, replaceLadder, setAssociateBrand };
