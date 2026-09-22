'use strict';

const db = require('../config/db');
const productRepository = require('../repositories/productRepository');
const productImageRepository = require('../repositories/productImageRepository');
const productSupplierRepository = require('../repositories/productSupplierRepository');
const productMiscChargeRepository = require('../repositories/productMiscChargeRepository');

/**
 * Port of App\Services\ProductService. Business logic for the Product
 * Interface / internal product catalog. The one rule that matters here is
 * effectiveFobForSupplier() — see its docblock — plus the "only one
 * primary supplier per product" invariant, which is centralized in
 * setPrimarySupplier() so it can't be violated from two different call
 * sites.
 */

/**
 * Computes the effective FOB for one supplier row.
 *
 * - fob_source = 'direct'   -> the supplier's own typed fob_value verbatim
 *   (may be null if not yet entered — callers must render that as "TBC",
 *   never crash on it).
 * - fob_source = 'computed' -> factory + transportation + packing +
 *   loading + cha, where each component is the supplier's own override if
 *   set, else the parent product's default, else 0.
 *
 * This MUST stay a literal JS `??` chain (nullish coalescing), NOT `||`:
 * a supplier value of literal 0 is a real override and must NOT fall back
 * to the product default (only null/undefined falls back). mysql2 returns
 * DECIMAL columns as strings (e.g. "0.00"), which is fine here — `??`
 * only tests for null/undefined, never for falsiness, so a string "0.00"
 * is correctly treated as "set" and is never skipped the way `||` would
 * skip it (an actual bug `||` would introduce that `??` avoids, exactly
 * like PHP's own `??` on the source side). Never persisted — this is a
 * pure, stateless calculation recomputed fresh every time it's needed
 * (list view, detail view); catalog_product_suppliers.fob_value stays
 * NULL forever for 'computed' rows.
 *
 * @param {object} supplier
 * @param {object} product
 * @returns {number|null}
 */
function effectiveFobForSupplier(supplier, product) {
  if ((supplier.fob_source ?? 'direct') === 'direct') {
    const value = supplier.fob_value ?? null;
    return value === null ? null : Number(value);
  }

  const factory = supplier.factory_cost ?? product.default_factory_cost ?? 0;
  const transport = supplier.transportation_cost ?? product.default_transportation_cost ?? 0;
  const packing = supplier.packing_cost ?? product.default_packing_cost ?? 0;
  const loading = supplier.loading_cost ?? product.default_loading_cost ?? 0;
  const cha = supplier.cha_cost ?? product.default_cha_cost ?? 0;

  return Number(factory) + Number(transport) + Number(packing) + Number(loading) + Number(cha);
}

/**
 * Product + its images + suppliers (each with an added 'effective_fob' key
 * computed fresh — not from the DB) + misc charges. Callers decide whether
 * to include pricing/suppliers/misc-charges in what's shown
 * (view_product_pricing gating happens in the controller, not here) — this
 * always assembles the full data so the controller can freely strip what
 * a role isn't allowed to see.
 *
 * @returns {Promise<{product: object|null, images: Array, suppliers: Array, misc_charges: Array}>}
 */
async function getProductWithDetails(id) {
  const product = await productRepository.find(id);
  if (!product) {
    return { product: null, images: [], suppliers: [], misc_charges: [] };
  }

  const suppliers = await productSupplierRepository.forProduct(id);
  for (const supplier of suppliers) {
    supplier.effective_fob = effectiveFobForSupplier(supplier, product);
  }

  const [images, miscCharges] = await Promise.all([
    productImageRepository.forProduct(id),
    productMiscChargeRepository.forProduct(id),
  ]);

  return { product, images, suppliers, misc_charges: miscCharges };
}

/**
 * Sets a supplier as the product's primary (headline) FOB, clearing every
 * other supplier's is_primary flag for the same product first, in one
 * transaction — the only place this invariant is applied, so it cannot be
 * violated by calling clearPrimaryForProduct()/setPrimary() separately
 * from two different places.
 */
async function setPrimarySupplier(productId, supplierId) {
  await db.transaction(async (conn) => {
    await conn.execute('UPDATE catalog_product_suppliers SET is_primary = 0 WHERE product_id = :product_id', { product_id: productId });
    await conn.execute('UPDATE catalog_product_suppliers SET is_primary = 1 WHERE id = :id', { id: supplierId });
  });
}

module.exports = { effectiveFobForSupplier, getProductWithDetails, setPrimarySupplier };
