'use strict';

const db = require('../config/db');
const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const hsCodeRepository = require('../repositories/hsCodeRepository');
const productRepository = require('../repositories/productRepository');
const productSupplierRepository = require('../repositories/productSupplierRepository');
const supplierRepository = require('../repositories/supplierRepository');

/**
 * Port of App\Controllers\CatalogueMasterController — owner decisions D-302 and D-303
 * (points 11 and 15, 11 Nov 2026): one master for every piece of data. Two one-time
 * clean-up pages: "Fix HS codes" (product codes not on the HS Code Master List) and
 * "Link suppliers" (supplier names typed on product prices, linked to the Suppliers
 * directory — exact names by "Match by name", the rest picked or quick-added).
 */

async function productsToFix() {
  return db.query(`SELECT p.id, p.name, p.hs_code, p.category_id, c.default_hs_code FROM catalog_products p
      LEFT JOIN product_categories c ON c.id = p.category_id
     WHERE NOT EXISTS (SELECT 1 FROM hs_codes h WHERE h.code = p.hs_code AND h.is_active = 1)
     ORDER BY p.name`);
}

async function fixHsCodes(req, res) {
  res.renderView('products/fix_hs_codes', {
    products: await productsToFix(),
    hsCodes: await hsCodeRepository.active(),
    canManageHs: !!(req.permissions || {}).manage_hs_codes,
  }, 'layout/base');
}

async function saveHsCode(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  const product = await productRepository.find(id);
  if (!product) {
    flash.set(req, 'error', 'That product no longer exists.');
    res.redirect('/products/fix-hs-codes');
    return;
  }
  const code = String((req.body || {}).hs_code ?? '').trim();
  if (!(await hsCodeRepository.isActiveCode(code))) {
    flash.set(req, 'error', `${product.name}: choose an HS code from the HS Code Master List.`);
    res.redirect('/products/fix-hs-codes');
    return;
  }
  await db.execute('UPDATE catalog_products SET hs_code = :c WHERE id = :id', { c: code, id });
  await auditLogRepository.log(req.user.id, 'PRODUCT_HS_CODE_FIXED', 'products', id, 'hs_code', String(product.hs_code), code);
  flash.set(req, 'success', `${product.name}: HS code set to ${code}.`);
  res.redirect(String((req.body || {}).return_to ?? '') === 'product' ? `/products/${id}` : '/products/fix-hs-codes');
}

async function linkSuppliers(req, res) {
  const names = await productSupplierRepository.unlinkedNames();
  for (const n of names) {
    const match = await supplierRepository.findByExactName(String(n.supplier_name));
    n.match = match ? { id: Number(match.id), name: String(match.supplier_legal_name) } : null;
  }
  res.renderView('products/link_suppliers', {
    names,
    exactCount: names.filter((n) => n.match !== null).length,
    pickList: await supplierRepository.pickList(),
    supplierTypes: (await require('../repositories/lookupRepository').dropdownOptions('supplier_type')).map((o) => String(o.option_value)),
  }, 'layout/base');
}

/** "Match by name" (every exact match at once), or one typed name linked to the supplier picked for it. */
async function saveLinks(req, res) {
  const body = req.body || {};
  if (String(body.action ?? '') === 'match_all') {
    let linked = 0;
    for (const n of await productSupplierRepository.unlinkedNames()) {
      const match = await supplierRepository.findByExactName(String(n.supplier_name));
      if (match) linked += await productSupplierRepository.linkName(String(n.supplier_name), Number(match.id), String(match.supplier_legal_name));
    }
    await auditLogRepository.log(req.user.id, 'PRODUCT_SUPPLIERS_LINKED', 'product_suppliers', null, 'supplier_id', null, `${linked} price(s) linked by exact name`);
    flash.set(req, 'success', linked === 0 ? 'No typed name matches a supplier exactly — pick them one by one below.' : `${linked} price(s) linked to their supplier by name.`);
    res.redirect('/products/link-suppliers');
    return;
  }
  const typed = String(body.typed_name ?? '');
  const supplier = (parseInt(body.supplier_id, 10) || 0) > 0
    ? await supplierRepository.find(parseInt(body.supplier_id, 10))
    : await supplierRepository.findByExactName(String(body.supplier_name ?? ''));
  if (typed === '' || !supplier) {
    flash.set(req, 'error', `Pick the supplier from the Suppliers directory for "${typed}" — or add it with "+ Add a new supplier (quick)".`);
    res.redirect('/products/link-suppliers');
    return;
  }
  const linked = await productSupplierRepository.linkName(typed, Number(supplier.id), String(supplier.supplier_legal_name));
  await auditLogRepository.log(req.user.id, 'PRODUCT_SUPPLIERS_LINKED', 'product_suppliers', null, 'supplier_name', typed, `${supplier.supplier_legal_name} #${supplier.id} (${linked} price(s))`);
  flash.set(req, 'success', `"${typed}" — ${linked} price(s) linked to ${supplier.supplier_legal_name}.`);
  res.redirect('/products/link-suppliers');
}

module.exports = { productsToFix, fixHsCodes, saveHsCode, linkSuppliers, saveLinks };
