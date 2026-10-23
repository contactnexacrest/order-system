'use strict';

const flash = require('../helpers/flash');
const orderAnnexureRepository = require('../repositories/orderAnnexureRepository');
const orderAnnexureTermsRepository = require('../repositories/orderAnnexureTermsRepository');
const orderRepository = require('../repositories/orderRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const annexureTermsSanitizer = require('../helpers/annexureTermsSanitizer');
const fileUploadService = require('../services/fileUploadService');
const documentRepository = require('../repositories/documentRepository');

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
    // 90-item backlog #73/#74: staff editing this screen have no
    // indication the Annexure was already approved/sent to the buyer —
    // surfaced as a warning banner + edit confirmation in the view, never
    // a hard block (consistent with every other staff-trust gate already
    // documented in this codebase).
    annexaDocument: await documentRepository.findLatestForOrderAndTypeCode(orderId, 'ANNEXA'),
  }, 'layout/base');
}

async function toggleInclude(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const include = !!req.body.include_annexure_a;
  await orderRepository.setIncludeAnnexureA(orderId, include);
  // External QA report N-08: this action never reached the audit log,
  // so it never notified anyone either.
  await auditLogRepository.log(req.user ? req.user.id : null, 'ANNEXURE_INCLUDE_TOGGLED', 'orders', orderId, 'include_annexure_a', null, String(Number(include)));
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

  const productId = await orderAnnexureRepository.createProduct(orderId, {
    name,
    description: String(req.body.description || '').trim(),
    dimensions: String(req.body.dimensions || '').trim(),
    finish: String(req.body.finish || '').trim(),
    components: String(req.body.components || '').trim(),
    technical_notes: String(req.body.technical_notes || '').trim(),
  });

  if (req.file) {
    const order = await orderRepository.find(orderId);
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
    } catch (e) {
      flash.set(req, 'error', `Product entry added, but the image failed to upload: ${flash.describeException(e, 'upload failed — check the server error log.')}`);
      res.redirect(`/orders/${orderId}/annexure`);
      return;
    }
  }

  // External QA report N-08: this action never reached the audit log,
  // so it never notified anyone either.
  await auditLogRepository.log(req.user.id, 'ANNEXURE_PRODUCT_CREATED', 'order_annexure_products', productId, null, null, name);
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
  // External QA report N-08: this action never reached the audit log,
  // so it never notified anyone either.
  await auditLogRepository.log(req.user.id, 'ANNEXURE_PRODUCT_UPDATED', 'order_annexure_products', productId, 'name', null, name);
  flash.set(req, 'success', 'Product entry updated.');
  res.redirect(`/orders/${orderId}/annexure`);
}

async function deleteProduct(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const productId = parseInt(req.params.productId, 10);
  await orderAnnexureRepository.deleteProduct(productId);
  // External QA report N-08: this action never reached the audit log,
  // so it never notified anyone either.
  await auditLogRepository.log(req.user.id, 'ANNEXURE_PRODUCT_DELETED', 'order_annexure_products', productId, null, null, null);
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
    const imageId = await orderAnnexureRepository.addImage(productId, fileId);
    // External QA report N-08: this action never reached the audit
    // log, so it never notified anyone either.
    await auditLogRepository.log(req.user.id, 'ANNEXURE_IMAGE_UPLOADED', 'order_annexure_images', imageId, null, null, null);
    flash.set(req, 'success', 'Image uploaded.');
  } catch (e) {
    flash.setErrorFromException(req, e);
  }
  res.redirect(`/orders/${orderId}/annexure`);
}

async function removeImage(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const imageId = parseInt(req.params.imageId, 10);
  await orderAnnexureRepository.removeImage(imageId);
  // External QA report N-08: this action never reached the audit log,
  // so it never notified anyone either.
  await auditLogRepository.log(req.user.id, 'ANNEXURE_IMAGE_REMOVED', 'order_annexure_images', imageId, null, null, null);
  flash.set(req, 'success', 'Image removed.');
  res.redirect(`/orders/${orderId}/annexure`);
}

module.exports = { index, toggleInclude, updateMode, updateTerms, createProduct, updateProduct, deleteProduct, uploadImage, removeImage };
