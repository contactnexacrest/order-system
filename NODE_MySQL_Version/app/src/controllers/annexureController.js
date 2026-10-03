'use strict';

const flash = require('../helpers/flash');
const orderAnnexureRepository = require('../repositories/orderAnnexureRepository');
const orderAnnexureTermsRepository = require('../repositories/orderAnnexureTermsRepository');
const orderRepository = require('../repositories/orderRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const annexureTermsSanitizer = require('../helpers/annexureTermsSanitizer');
const fileUploadService = require('../services/fileUploadService');

const VALID_MODES = ['SPEC', 'TERMS', 'BOTH'];

// Port of App\Controllers\AnnexureController. Staff management screen for
// Annexure A — Product Technical Specifications (order_annexure_products/
// order_annexure_images shipped in the original delivery with no screen
// ever built against them — this is that missing piece). Reached from the
// order page whenever orders.include_annexure_a is set (or an order
// created before that flag existed on the New Order form can turn it on
// here).

function sanitizePathSegment(value) {
  return String(value == null ? '' : value).replace(/[^A-Za-z0-9_-]+/g, '-');
}

async function index(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }

  res.renderView('annexure/index', {
    order,
    products: await orderAnnexureRepository.forOrder(orderId),
    terms: await orderAnnexureTermsRepository.find(orderId),
  }, 'layout/base');
}

async function toggleInclude(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const include = !!req.body.include_annexure_a;
  await orderRepository.setIncludeAnnexureA(orderId, include);
  flash.set(req, 'success', `Annexure A ${include ? 'enabled' : 'disabled'} for this order.`);
  res.redirect(`/orders/${orderId}/annexure`);
}

async function updateMode(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const mode = String(req.body.mode || '');
  if (!VALID_MODES.includes(mode)) {
    flash.set(req, 'error', 'Invalid Annexure A content mode.');
    res.redirect(`/orders/${orderId}/annexure`);
    return;
  }

  await orderRepository.setAnnexureMode(orderId, mode);
  await auditLogRepository.log(req.user.id, 'ANNEXURE_MODE_UPDATED', 'order', orderId, 'annexure_mode', null, mode);
  flash.set(req, 'success', 'Annexure A content mode updated.');
  res.redirect(`/orders/${orderId}/annexure`);
}

async function updateTerms(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const sanitized = annexureTermsSanitizer.sanitize(String(req.body.content_html || ''));

  await orderAnnexureTermsRepository.upsert(orderId, sanitized, req.user.id);
  await auditLogRepository.log(req.user.id, 'ANNEXURE_TERMS_UPDATED', 'order', orderId, 'content_html', null, null);
  flash.set(req, 'success', 'Additional Terms saved.');
  res.redirect(`/orders/${orderId}/annexure`);
}

async function createProduct(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const name = String(req.body.name || '').trim();
  if (name === '') {
    flash.set(req, 'error', 'Product name is required.');
    res.redirect(`/orders/${orderId}/annexure`);
    return;
  }

  await orderAnnexureRepository.createProduct(orderId, {
    name,
    description: String(req.body.description || '').trim(),
    dimensions: String(req.body.dimensions || '').trim(),
    finish: String(req.body.finish || '').trim(),
    components: String(req.body.components || '').trim(),
    technical_notes: String(req.body.technical_notes || '').trim(),
  });
  flash.set(req, 'success', 'Product entry added.');
  res.redirect(`/orders/${orderId}/annexure`);
}

async function updateProduct(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const productId = parseInt(req.params.productId, 10);
  const name = String(req.body.name || '').trim();
  if (name === '') {
    flash.set(req, 'error', 'Product name is required.');
    res.redirect(`/orders/${orderId}/annexure`);
    return;
  }

  await orderAnnexureRepository.updateProduct(productId, {
    name,
    description: String(req.body.description || '').trim(),
    dimensions: String(req.body.dimensions || '').trim(),
    finish: String(req.body.finish || '').trim(),
    components: String(req.body.components || '').trim(),
    technical_notes: String(req.body.technical_notes || '').trim(),
  });
  flash.set(req, 'success', 'Product entry updated.');
  res.redirect(`/orders/${orderId}/annexure`);
}

async function deleteProduct(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const productId = parseInt(req.params.productId, 10);
  await orderAnnexureRepository.deleteProduct(productId);
  flash.set(req, 'success', 'Product entry removed.');
  res.redirect(`/orders/${orderId}/annexure`);
}

async function uploadImage(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const productId = parseInt(req.params.productId, 10);
  const order = await orderRepository.find(orderId);
  const product = await orderAnnexureRepository.findProduct(productId);
  if (!order || !product) {
    res.status(404).send('Not found.');
    return;
  }

  try {
    const fileId = await fileUploadService.handleUpload(
      req,
      'image',
      'product_image',
      `clients/${sanitizePathSegment(order.client_unique_number)}/${sanitizePathSegment(order.order_reference)}/annexure`,
      null,
      orderId,
      req.user.id
    );
    await orderAnnexureRepository.addImage(productId, fileId);
    flash.set(req, 'success', 'Image uploaded.');
  } catch (e) {
    flash.set(req, 'error', e.message);
  }
  res.redirect(`/orders/${orderId}/annexure`);
}

async function removeImage(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const imageId = parseInt(req.params.imageId, 10);
  await orderAnnexureRepository.removeImage(imageId);
  flash.set(req, 'success', 'Image removed.');
  res.redirect(`/orders/${orderId}/annexure`);
}

module.exports = { index, toggleInclude, updateMode, updateTerms, createProduct, updateProduct, deleteProduct, uploadImage, removeImage };
