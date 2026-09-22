'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const env = require('../config/env');
const flash = require('../helpers/flash');
const sniffMime = require('../helpers/sniffMime');
const auditLogRepository = require('../repositories/auditLogRepository');
const productRepository = require('../repositories/productRepository');
const productImageRepository = require('../repositories/productImageRepository');
const productSupplierRepository = require('../repositories/productSupplierRepository');
const productMiscChargeRepository = require('../repositories/productMiscChargeRepository');
const productService = require('../services/productService');

/**
 * Port of App\Controllers\ProductController — Product Interface / internal
 * product catalog (docs/schema.sql Section U). Completely independent of
 * the order pipeline: nothing here is ever selected into an order, and
 * this catalog is never shown to clients.
 *
 * Two visibility boundaries are enforced SERVER-SIDE (not just hidden in
 * the view), because both are real information-disclosure boundaries:
 *  - browse_product_catalog: without it, a user with only
 *    view_product_catalog gets a search box and no full-list query ever
 *    runs for them (see index()).
 *  - view_product_pricing: without it, pricing/supplier/misc-charge data
 *    is stripped out of the objects passed to the view, not merely hidden
 *    with CSS (see index() and show()).
 */

const ALLOWED_MIME = { 'image/png': 'png', 'image/jpeg': 'jpg' };
const MAX_BYTES = 5 * 1024 * 1024; // 5MB

function perms(req) {
  const p = req.permissions || {};
  return {
    canBrowse: !!p.browse_product_catalog,
    canViewPricing: !!p.view_product_pricing,
    canManage: !!p.manage_product_catalog,
  };
}

async function index(req, res) {
  const { canBrowse, canViewPricing, canManage } = perms(req);

  const query = String(req.query.q || '').trim();
  const searched = query !== '';

  let products;
  if (searched) {
    products = await productRepository.search(query);
  } else if (canBrowse) {
    products = await productRepository.all();
  } else {
    // Search-only user with no query submitted yet — no full-list query
    // executes at all. This is the actual enforcement point; everything
    // else here is just what gets rendered.
    products = [];
  }

  if (canViewPricing) {
    for (const product of products) {
      const suppliers = await productSupplierRepository.forProduct(product.id);
      let primary = null;
      for (const supplier of suppliers) {
        if (supplier.is_primary) {
          primary = supplier;
          break;
        }
      }
      primary = primary ?? (suppliers[0] ?? null);
      product.headline_fob = primary ? productService.effectiveFobForSupplier(primary, product) : null;
    }
  } else {
    // Strip pricing data out of the response entirely — not just hidden in
    // the view — for roles without view_product_pricing.
    for (const product of products) {
      delete product.default_factory_cost;
      delete product.default_transportation_cost;
      delete product.default_packing_cost;
      delete product.default_loading_cost;
      delete product.default_cha_cost;
    }
  }

  res.renderView('products/index', {
    products,
    query,
    searched,
    canBrowse,
    canViewPricing,
    canManage,
  }, 'layout/base');
}

async function create(req, res) {
  res.renderView('products/create', { product: null }, 'layout/base');
}

async function store(req, res) {
  const data = readProductForm(req);
  const error = validateProduct(data);
  if (error) {
    flash.set(req, 'error', error);
    res.redirect('/products/create');
    return;
  }

  const user = req.user;
  const id = await productRepository.create(data, user.id);
  await auditLogRepository.log(user.id, 'PRODUCT_CREATED', 'products', id, null, null, data.name);
  flash.set(req, 'success', 'Product added to the catalog.');
  res.redirect(`/products/${id}`);
}

async function show(req, res) {
  const { canViewPricing, canManage } = perms(req);

  const id = parseInt(req.params.id, 10) || 0;
  const details = await productService.getProductWithDetails(id);
  if (!details.product) {
    res.status(404).send('Product not found.');
    return;
  }

  if (!canViewPricing) {
    delete details.product.default_factory_cost;
    delete details.product.default_transportation_cost;
    delete details.product.default_packing_cost;
    delete details.product.default_loading_cost;
    delete details.product.default_cha_cost;
    details.suppliers = [];
    details.misc_charges = [];
  }

  res.renderView('products/show', {
    product: details.product,
    images: details.images,
    suppliers: details.suppliers,
    miscCharges: details.misc_charges,
    canViewPricing,
    canManage,
  }, 'layout/base');
}

async function edit(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  const product = await productRepository.find(id);
  if (!product) {
    res.status(404).send('Product not found.');
    return;
  }
  res.renderView('products/edit', { product }, 'layout/base');
}

async function update(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  const product = await productRepository.find(id);
  if (!product) {
    res.status(404).send('Product not found.');
    return;
  }

  const data = readProductForm(req);
  const error = validateProduct(data);
  if (error) {
    flash.set(req, 'error', error);
    res.redirect(`/products/${id}/edit`);
    return;
  }

  const user = req.user;
  await productRepository.update(id, data, user.id);
  await auditLogRepository.log(user.id, 'PRODUCT_UPDATED', 'products', id, null, null, data.name);
  flash.set(req, 'success', 'Product updated.');
  res.redirect(`/products/${id}`);
}

async function remove(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  const product = await productRepository.find(id);
  if (!product) {
    res.status(404).send('Product not found.');
    return;
  }

  const user = req.user;
  await productRepository.remove(id);
  await auditLogRepository.log(user.id, 'PRODUCT_DELETED', 'products', id, null, product.name, null);
  flash.set(req, 'success', 'Product removed from the catalog.');
  res.redirect('/products');
}

async function uploadImage(req, res) {
  const productId = parseInt(req.params.id, 10) || 0;
  const product = await productRepository.find(productId);
  if (!product) {
    res.status(404).send('Product not found.');
    return;
  }

  if (!req.file) {
    flash.set(req, 'error', 'No file was uploaded, or the upload failed.');
    res.redirect(`/products/${productId}`);
    return;
  }

  const file = req.file; // multer memoryStorage: { buffer, size, originalname, mimetype }
  if (file.size > MAX_BYTES) {
    flash.set(req, 'error', 'File too large (max 5MB).');
    res.redirect(`/products/${productId}`);
    return;
  }

  const mime = sniffMime.sniff(file.buffer);
  if (!mime || !ALLOWED_MIME[mime]) {
    flash.set(req, 'error', 'Unsupported file type. Use PNG or JPG.');
    res.redirect(`/products/${productId}`);
    return;
  }

  const ext = ALLOWED_MIME[mime];
  const storageBase = env.get('STORAGE_BASE_PATH', path.join(__dirname, '..', '..', 'storage'));
  const targetDir = path.join(storageBase, 'products', String(productId), 'images');
  fs.mkdirSync(targetDir, { recursive: true });

  const stamp = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 15);
  const filename = `img_${stamp}_${crypto.randomBytes(4).toString('hex')}.${ext}`;
  const targetPath = path.join(targetDir, filename);

  fs.writeFileSync(targetPath, file.buffer);

  const user = req.user;
  const imageId = await productImageRepository.create(productId, targetPath, mime, user.id);
  await auditLogRepository.log(user.id, 'PRODUCT_IMAGE_UPLOADED', 'product_images', imageId, null, null, targetPath);
  flash.set(req, 'success', 'Image uploaded.');
  res.redirect(`/products/${productId}`);
}

async function deleteImage(req, res) {
  const imageId = parseInt(req.params.imageId, 10) || 0;
  const image = await productImageRepository.find(imageId);
  if (!image) {
    flash.set(req, 'error', 'Image not found.');
    res.redirect('/products');
    return;
  }

  const productId = image.product_id;
  const user = req.user;
  await productImageRepository.remove(imageId);
  if (fs.existsSync(image.server_path)) {
    try { fs.unlinkSync(image.server_path); } catch (e) { /* best-effort, same as PHP's @unlink */ }
  }
  await auditLogRepository.log(user.id, 'PRODUCT_IMAGE_DELETED', 'product_images', imageId, null, image.server_path, null);
  flash.set(req, 'success', 'Image removed.');
  res.redirect(`/products/${productId}`);
}

/** Streams a product image file — storage lives outside the web root, exactly like assetController.preview(). */
async function viewImage(req, res) {
  const imageId = parseInt(req.params.imageId, 10) || 0;
  const image = await productImageRepository.find(imageId);
  if (!image || !fs.existsSync(image.server_path)) {
    res.status(404).end();
    return;
  }
  res.set('Content-Type', image.mime_type || 'application/octet-stream');
  res.set('Cache-Control', 'private, max-age=60');
  fs.createReadStream(image.server_path).pipe(res);
}

async function addSupplier(req, res) {
  const productId = parseInt(req.params.id, 10) || 0;
  const product = await productRepository.find(productId);
  if (!product) {
    res.status(404).send('Product not found.');
    return;
  }

  const data = readSupplierForm(req);
  data.product_id = productId;
  const error = validateSupplier(data);
  if (error) {
    flash.set(req, 'error', error);
    res.redirect(`/products/${productId}`);
    return;
  }

  const user = req.user;
  const supplierId = await productSupplierRepository.create(data, user.id);
  if (data.is_primary) {
    await productService.setPrimarySupplier(productId, supplierId);
  }
  await auditLogRepository.log(user.id, 'PRODUCT_SUPPLIER_ADDED', 'product_suppliers', supplierId, null, null, data.supplier_name);
  flash.set(req, 'success', 'Supplier added.');
  res.redirect(`/products/${productId}`);
}

async function updateSupplier(req, res) {
  const supplierId = parseInt(req.params.supplierId, 10) || 0;
  const existing = await productSupplierRepository.find(supplierId);
  if (!existing) {
    flash.set(req, 'error', 'Supplier not found.');
    res.redirect('/products');
    return;
  }
  const productId = existing.product_id;

  const data = readSupplierForm(req);
  data.product_id = productId;
  const error = validateSupplier(data);
  if (error) {
    flash.set(req, 'error', error);
    res.redirect(`/products/${productId}`);
    return;
  }

  const user = req.user;
  await productSupplierRepository.update(supplierId, data, user.id);
  if (data.is_primary) {
    await productService.setPrimarySupplier(productId, supplierId);
  }
  await auditLogRepository.log(user.id, 'PRODUCT_SUPPLIER_UPDATED', 'product_suppliers', supplierId, null, null, data.supplier_name);
  flash.set(req, 'success', 'Supplier updated.');
  res.redirect(`/products/${productId}`);
}

async function deleteSupplier(req, res) {
  const supplierId = parseInt(req.params.supplierId, 10) || 0;
  const existing = await productSupplierRepository.find(supplierId);
  if (!existing) {
    flash.set(req, 'error', 'Supplier not found.');
    res.redirect('/products');
    return;
  }
  const productId = existing.product_id;

  const user = req.user;
  await productSupplierRepository.remove(supplierId);
  await auditLogRepository.log(user.id, 'PRODUCT_SUPPLIER_DELETED', 'product_suppliers', supplierId, null, existing.supplier_name, null);
  flash.set(req, 'success', 'Supplier removed.');
  res.redirect(`/products/${productId}`);
}

async function setPrimarySupplier(req, res) {
  const supplierId = parseInt(req.params.supplierId, 10) || 0;
  const existing = await productSupplierRepository.find(supplierId);
  if (!existing) {
    flash.set(req, 'error', 'Supplier not found.');
    res.redirect('/products');
    return;
  }
  const productId = existing.product_id;

  const user = req.user;
  await productService.setPrimarySupplier(productId, supplierId);
  await auditLogRepository.log(user.id, 'PRODUCT_SUPPLIER_SET_PRIMARY', 'product_suppliers', supplierId);
  flash.set(req, 'success', 'Primary supplier updated.');
  res.redirect(`/products/${productId}`);
}

async function addMiscCharge(req, res) {
  const productId = parseInt(req.params.id, 10) || 0;
  const product = await productRepository.find(productId);
  if (!product) {
    res.status(404).send('Product not found.');
    return;
  }

  const label = String(req.body.label || '').trim();
  const amountRaw = String(req.body.amount || '').trim();
  if (label === '' || amountRaw === '' || !isNumeric(amountRaw)) {
    flash.set(req, 'error', 'A label and a numeric amount are required for a misc charge.');
    res.redirect(`/products/${productId}`);
    return;
  }

  const user = req.user;
  const notes = String(req.body.notes || '').trim();
  const chargeId = await productMiscChargeRepository.create(productId, label, parseFloat(amountRaw), notes !== '' ? notes : null, user.id);
  await auditLogRepository.log(user.id, 'PRODUCT_MISC_CHARGE_ADDED', 'product_misc_charges', chargeId, null, null, `${label}: ${amountRaw}`);
  flash.set(req, 'success', 'Misc charge added.');
  res.redirect(`/products/${productId}`);
}

async function deleteMiscCharge(req, res) {
  const chargeId = parseInt(req.params.chargeId, 10) || 0;
  const charge = await productMiscChargeRepository.find(chargeId);
  if (!charge) {
    flash.set(req, 'error', 'Misc charge not found.');
    res.redirect('/products');
    return;
  }
  const productId = charge.product_id;

  const user = req.user;
  await productMiscChargeRepository.remove(chargeId);
  await auditLogRepository.log(user.id, 'PRODUCT_MISC_CHARGE_DELETED', 'product_misc_charges', chargeId, null, `${charge.label}: ${charge.amount}`, null);
  flash.set(req, 'success', 'Misc charge removed.');
  res.redirect(`/products/${productId}`);
}

function readProductForm(req) {
  return {
    name: String(req.body.name || '').trim(),
    specifications: String(req.body.specifications || '').trim(),
    hs_code: String(req.body.hs_code || '').trim(),
    origin: nullableString(req.body.origin),
    default_factory_cost: nullableDecimal(req.body.default_factory_cost),
    default_transportation_cost: nullableDecimal(req.body.default_transportation_cost),
    default_packing_cost: nullableDecimal(req.body.default_packing_cost),
    default_loading_cost: nullableDecimal(req.body.default_loading_cost),
    default_cha_cost: nullableDecimal(req.body.default_cha_cost),
    is_active: req.body.is_active !== undefined ? 1 : 0,
  };
}

/** hs_code (and name/specifications, both NOT NULL columns) validated server-side — never trusted from client-side only. */
function validateProduct(data) {
  if (data.name === '') {
    return 'Product name is required.';
  }
  if (data.specifications === '') {
    return 'Specifications are required.';
  }
  if (data.hs_code === '') {
    return 'HS Code is required.';
  }
  return null;
}

function readSupplierForm(req) {
  let fobSource = String(req.body.fob_source || 'direct');
  if (!['direct', 'computed'].includes(fobSource)) {
    fobSource = 'direct';
  }
  return {
    supplier_name: String(req.body.supplier_name || '').trim(),
    location: nullableString(req.body.location),
    contact_person: nullableString(req.body.contact_person),
    contact_phone: nullableString(req.body.contact_phone),
    contact_email: nullableString(req.body.contact_email),
    fob_source: fobSource,
    fob_value: nullableDecimal(req.body.fob_value),
    factory_cost: nullableDecimal(req.body.factory_cost),
    transportation_cost: nullableDecimal(req.body.transportation_cost),
    packing_cost: nullableDecimal(req.body.packing_cost),
    loading_cost: nullableDecimal(req.body.loading_cost),
    cha_cost: nullableDecimal(req.body.cha_cost),
    is_primary: req.body.is_primary !== undefined ? 1 : 0,
    notes: nullableString(req.body.notes),
  };
}

function validateSupplier(data) {
  if (data.supplier_name === '') {
    return 'Supplier name is required.';
  }
  return null;
}

function nullableString(value) {
  const v = String(value ?? '').trim();
  return v === '' ? null : v;
}

function nullableDecimal(value) {
  const v = String(value ?? '').trim();
  return (v === '' || !isNumeric(v)) ? null : parseFloat(v);
}

function isNumeric(value) {
  return value !== '' && !isNaN(Number(value));
}

module.exports = {
  index,
  create,
  store,
  show,
  edit,
  update,
  remove,
  uploadImage,
  deleteImage,
  viewImage,
  addSupplier,
  updateSupplier,
  deleteSupplier,
  setPrimarySupplier,
  addMiscCharge,
  deleteMiscCharge,
};
