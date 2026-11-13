'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const db = require('../config/db');
const env = require('../config/env');
const requestContext = require('../helpers/requestContext');

/**
 * Port of App\Services\ProductDuplicateService — owner decision D-358 (13 Nov 2026):
 * "Duplicate a product".
 *
 * Copies one catalogue product and every row that belongs to it, in ONE transaction:
 *  - catalog_products (the product row, its category, HS code, unit, costs to FOB, price rules
 *    and pricing overrides) — with a new name, Inactive, no Zoho item, created now by the user;
 *  - spec_definitions with product_id (the product's own specifications), and spec_options with
 *    product_id or under one of those specifications (option ↔ specification links re-pointed to
 *    the copies; the D-357 dropdown_option_id link kept as it is);
 *  - product_spec_hidden and product_option_overrides (re-pointed when they name a copied row);
 *  - catalog_product_suppliers, with their catalog_supplier_thickness_prices and
 *    catalog_product_supplier_options (re-pointed to the copied rows);
 *  - catalog_product_misc_charges;
 *  - catalog_product_images — the image FILE is copied too, so deleting an image on one product
 *    never removes the other's.
 * Not copied: catalog_supplier_price_history (an audit trail of changes made on the source's
 * supplier rows — the current price itself is on the copied supplier and thickness rows),
 * price_requests and anything on orders (order_products, quotations).
 *
 * Every column is copied by an INSERT … SELECT on the live column list, so the values are
 * identical to the source's and a column added later is copied without a code change.
 * The source id is kept in the audit log (PRODUCT_DUPLICATED, old_value = source id) — no new column.
 */

const AUDIT_ACTION = 'PRODUCT_DUPLICATED';
const MAX_NAME = 255;
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const columnCache = {};

/** Two products may not share a name (case and surrounding spaces ignored). */
async function nameTaken(name, exceptId = null) {
  const row = await db.queryOne(
    `SELECT COUNT(*) AS n FROM catalog_products WHERE LOWER(TRIM(name)) = LOWER(TRIM(:n))${exceptId !== null ? ' AND id <> :x' : ''}`,
    exceptId !== null ? { n: name, x: exceptId } : { n: name }
  );
  return Number(row.n) > 0;
}

/** The name proposed on the confirm screen. */
async function proposedName(sourceName) {
  const src = String(sourceName).trim();
  let name = `${src} (copy)`.slice(0, MAX_NAME);
  for (let n = 2; n < 100 && (await nameTaken(name)); n++) {
    name = `${src.slice(0, MAX_NAME - 12)} (copy ${n})`;
  }
  return name;
}

/** Every product name, for the browser check on the confirm screen. */
async function allNames() {
  return (await db.query('SELECT name FROM catalog_products ORDER BY name')).map((r) => String(r.name));
}

/** Server-side check of the typed name; null = fine. */
async function nameError(name) {
  const n = String(name || '').trim();
  if (n === '') return 'Enter the name of the new product.';
  if ([...n].length > MAX_NAME) return `The name can be at most ${MAX_NAME} characters.`;
  if (await nameTaken(n)) return `A product called "${n}" already exists — two products cannot have the same name. Choose another name.`;
  return null;
}

/** What the copy will hold — the counts on the confirm screen. */
async function counts(sourceId) {
  const one = async (sql) => Number((await db.queryOne(sql, { id: sourceId })).n);
  return {
    specs: await one('SELECT COUNT(*) AS n FROM spec_definitions WHERE product_id = :id'),
    options: await one('SELECT COUNT(*) AS n FROM spec_options o LEFT JOIN spec_definitions d ON d.id = o.spec_id AND d.product_id IS NOT NULL WHERE COALESCE(o.product_id, d.product_id) = :id'),
    hidden_specs: await one('SELECT COUNT(*) AS n FROM product_spec_hidden WHERE product_id = :id'),
    option_overrides: await one('SELECT COUNT(*) AS n FROM product_option_overrides WHERE product_id = :id'),
    suppliers: await one('SELECT COUNT(*) AS n FROM catalog_product_suppliers WHERE product_id = :id'),
    thickness_rows: await one('SELECT COUNT(*) AS n FROM catalog_supplier_thickness_prices t JOIN catalog_product_suppliers s ON s.id = t.product_supplier_id WHERE s.product_id = :id'),
    supplier_options: await one('SELECT COUNT(*) AS n FROM catalog_product_supplier_options so JOIN catalog_product_suppliers s ON s.id = so.supplier_id WHERE s.product_id = :id'),
    misc_charges: await one('SELECT COUNT(*) AS n FROM catalog_product_misc_charges WHERE product_id = :id'),
    images: await one('SELECT COUNT(*) AS n FROM catalog_product_images WHERE product_id = :id'),
    price_history: await one('SELECT COUNT(*) AS n FROM catalog_supplier_price_history h JOIN catalog_product_suppliers s ON s.id = h.product_supplier_id WHERE s.product_id = :id'),
  };
}

/** The table's stored columns, without the auto-increment id and generated columns. */
async function columns(conn, table) {
  if (!columnCache[table]) {
    const rows = await conn.query(
      `SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
          AND EXTRA NOT LIKE '%auto_increment%' AND EXTRA NOT LIKE '%GENERATED%'
        ORDER BY ORDINAL_POSITION`, { t: table });
    columnCache[table] = rows.map((r) => String(r.c));
  }
  return columnCache[table];
}

/**
 * INSERT INTO table (every column) SELECT (every column, or the override) FROM table WHERE where.
 * An override is a value (bound) or { raw: 'NOW()' }. Returns the new row's id (0 for tables without one).
 */
async function copyRow(conn, table, where, whereParams, overrides) {
  const cols = await columns(conn, table);
  const params = { ...whereParams };
  const select = cols.map((c) => {
    if (Object.prototype.hasOwnProperty.call(overrides, c)) {
      const v = overrides[c];
      if (v !== null && typeof v === 'object' && v.raw) return v.raw;
      params[`ov_${c}`] = v;
      return `:ov_${c}`;
    }
    return `\`${c}\``;
  });
  const quoted = cols.map((c) => `\`${c}\``).join(', ');
  const result = await conn.execute(`INSERT INTO \`${table}\` (${quoted}) SELECT ${select.join(', ')} FROM \`${table}\` WHERE ${where}`, params);
  if (result.affectedRows !== 1) throw new Error(`Copy of ${table} wrote ${result.affectedRows} rows, expected 1.`);
  return Number(result.insertId || 0);
}

function stamp() {
  const d = new Date();
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}_${p(d.getHours())}${p(d.getMinutes())}${p(d.getSeconds())}`;
}

/** Copies the product; returns the new product's id. Throws on failure (nothing is kept). */
async function duplicate(sourceId, name, userId) {
  const newName = String(name).trim();
  const copiedFiles = [];
  try {
    return await db.transaction(async (conn) => {
      const src = await conn.queryOne('SELECT id, name FROM catalog_products WHERE id = :id FOR UPDATE', { id: sourceId });
      if (!src) throw new Error('Product not found.');

      // 1. The product row: new name, Inactive, no Zoho item, created now by this user.
      const newId = await copyRow(conn, 'catalog_products', 'id = :src', { src: sourceId }, {
        name: newName, is_active: 0,
        zoho_item_id: null, zoho_synced_hash: null, zoho_synced_at: null,
        created_by: userId, updated_by: userId,
        created_at: { raw: 'NOW()' }, updated_at: { raw: 'NOW()' },
      });

      // 2. The product's own specifications, then its options (re-pointed to the copies).
      const specMap = {};
      for (const r of await conn.query('SELECT id FROM spec_definitions WHERE product_id = :p ORDER BY id', { p: sourceId })) {
        specMap[r.id] = await copyRow(conn, 'spec_definitions', 'id = :src', { src: r.id }, { product_id: newId });
      }
      const optionMap = {};
      const specIds = Object.keys(specMap).map(Number);
      const optSql = `SELECT id, spec_id FROM spec_options WHERE product_id = :p${specIds.length ? ` OR spec_id IN (${specIds.join(',')})` : ''} ORDER BY id`;
      for (const o of await conn.query(optSql, { p: sourceId })) {
        optionMap[o.id] = await copyRow(conn, 'spec_options', 'id = :src', { src: o.id }, {
          product_id: newId, spec_id: specMap[o.spec_id] ?? Number(o.spec_id),
        });
      }

      // 3. Hidden category specifications and option overrides.
      for (const r of await conn.query('SELECT spec_id FROM product_spec_hidden WHERE product_id = :p ORDER BY spec_id', { p: sourceId })) {
        await copyRow(conn, 'product_spec_hidden', 'product_id = :src AND spec_id = :s', { src: sourceId, s: r.spec_id },
          { product_id: newId, spec_id: specMap[r.spec_id] ?? Number(r.spec_id) });
      }
      for (const r of await conn.query('SELECT option_id FROM product_option_overrides WHERE product_id = :p ORDER BY option_id', { p: sourceId })) {
        await copyRow(conn, 'product_option_overrides', 'product_id = :src AND option_id = :o', { src: sourceId, o: r.option_id },
          { product_id: newId, option_id: optionMap[r.option_id] ?? Number(r.option_id) });
      }

      // 4. Supplier rows with their thickness prices and offered options.
      for (const s of await conn.query('SELECT id FROM catalog_product_suppliers WHERE product_id = :p ORDER BY id', { p: sourceId })) {
        const newSup = await copyRow(conn, 'catalog_product_suppliers', 'id = :src', { src: s.id }, { product_id: newId });
        for (const t of await conn.query('SELECT id FROM catalog_supplier_thickness_prices WHERE product_supplier_id = :s ORDER BY id', { s: s.id })) {
          await copyRow(conn, 'catalog_supplier_thickness_prices', 'id = :src', { src: t.id }, { product_supplier_id: newSup });
        }
        for (const r of await conn.query('SELECT spec_id, option_id FROM catalog_product_supplier_options WHERE supplier_id = :s ORDER BY spec_id, option_id', { s: s.id })) {
          await copyRow(conn, 'catalog_product_supplier_options', 'supplier_id = :src AND spec_id = :sp AND option_id = :op',
            { src: s.id, sp: r.spec_id, op: r.option_id },
            { supplier_id: newSup, spec_id: specMap[r.spec_id] ?? Number(r.spec_id), option_id: optionMap[r.option_id] ?? Number(r.option_id) });
        }
      }

      // 5. Misc charges.
      for (const m of await conn.query('SELECT id FROM catalog_product_misc_charges WHERE product_id = :p ORDER BY id', { p: sourceId })) {
        await copyRow(conn, 'catalog_product_misc_charges', 'id = :src', { src: m.id }, { product_id: newId });
      }

      // 6. Images: the file is copied into the new product's own folder.
      for (const i of await conn.query('SELECT id, server_path FROM catalog_product_images WHERE product_id = :p ORDER BY id', { p: sourceId })) {
        const from = String(i.server_path);
        if (!fs.existsSync(from) || !fs.statSync(from).isFile()) continue; // nothing on disk to show — the copy simply has no such image
        const dir = path.join(env.storageBasePath(), 'products', String(newId), 'images');
        fs.mkdirSync(dir, { recursive: true });
        const ext = (path.extname(from).slice(1) || 'jpg').toLowerCase();
        const to = path.join(dir, `img_${stamp()}_${crypto.randomBytes(4).toString('hex')}.${ext}`);
        fs.copyFileSync(from, to);
        copiedFiles.push(to);
        await copyRow(conn, 'catalog_product_images', 'id = :src', { src: i.id }, {
          product_id: newId, server_path: to, uploaded_by: userId, uploaded_at: { raw: 'NOW()' },
        });
      }

      // The audit row, in the same transaction (D-214: who acted as which designation).
      const ctx = requestContext.get();
      const actingAs = ctx && Number(ctx.userId) === Number(userId) ? (ctx.actingAs || null) : null;
      await conn.execute(
        `INSERT INTO audit_log (user_id, acting_as, action_type, entity_type, entity_id, field_name, old_value, new_value, reason, ip_address)
         VALUES (:u, :a, :t, 'products', :e, 'copied_from_product_id', :o, :n, :r, NULL)`,
        { u: userId, a: actingAs, t: AUDIT_ACTION, e: newId, o: String(sourceId), n: newName, r: `Copied from product #${sourceId} "${src.name}"` }
      );
      return newId;
    });
  } catch (e) {
    for (const f of copiedFiles) {
      try { fs.unlinkSync(f); } catch (_) { /* already gone */ }
    }
    throw e;
  }
}

/**
 * The "Copied from …" note on a product made by Duplicate: the source (id, name — null when
 * it was deleted since), when, and the role (or designation) of the person who copied it.
 */
async function copiedFrom(productId) {
  const row = await db.queryOne(
    `SELECT al.old_value, al.created_at, r.name AS role_name, d.title AS designation, p.name AS source_name
       FROM audit_log al
       LEFT JOIN users u ON u.id = al.user_id
       LEFT JOIN roles r ON r.id = u.role_id
       LEFT JOIN designations d ON d.id = u.designation_id
       LEFT JOIN catalog_products p ON p.id = CAST(al.old_value AS UNSIGNED)
      WHERE al.action_type = :a AND al.entity_type = 'products' AND al.entity_id = :id
      ORDER BY al.id DESC LIMIT 1`, { a: AUDIT_ACTION, id: productId });
  if (!row) return null;
  const by = String(row.role_name || '').trim() || String(row.designation || '').trim() || 'a staff member';
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(row.created_at || ''));
  return {
    source_id: Number(row.old_value),
    source_name: row.source_name !== null ? String(row.source_name) : null,
    source_exists: row.source_name !== null,
    copied_at: m ? `${Number(m[3])} ${MONTHS[Number(m[2]) - 1]} ${m[1]}` : '',
    by,
  };
}

module.exports = { AUDIT_ACTION, MAX_NAME, nameTaken, proposedName, allNames, nameError, counts, duplicate, copiedFrom };
