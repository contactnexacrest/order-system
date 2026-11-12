'use strict';

const flash = require('../helpers/flash');
const recordCheck = require('../helpers/recordCheck');
const orderStorage = require('../helpers/orderStorage');
const orderAnnexureRepository = require('../repositories/orderAnnexureRepository');
const orderAnnexureTermsRepository = require('../repositories/orderAnnexureTermsRepository');
const orderRepository = require('../repositories/orderRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const annexureTermsSanitizer = require('../helpers/annexureTermsSanitizer');
const fileUploadService = require('../services/fileUploadService');
const documentRepository = require('../repositories/documentRepository');
const annexureChoice = require('../services/annexureChoice');

const VALID_MODES = ['SPEC', 'TERMS', 'BOTH'];

// Port of App\Controllers\AnnexureController. Staff management screen for
// Annexure A (and, owner decision D-336, the same screen for Annexure B "as
// shipped", ?which=B — printed with the Commercial Invoice and Packing List) — Product Technical Specifications (order_annexure_products/
// order_annexure_images shipped in the original delivery with no screen
// ever built against them — this is that missing piece). Reached from the
// order page whenever orders.include_annexure_a is set (or an order
// created before that flag existed on the New Order form can turn it on
// here).


// D-336: the page of one annexure — /orders/:id/annexure (A) or ?which=B.
function annexureUrl(orderId, which) {
  return `/orders/${orderId}/annexure${which === 'B' ? '?which=B' : ''}`;
}

// D-336: the audit "reason" that marks an Annexure B entry (Annexure A entries stay as before).
function auditNote(which) {
  return which === 'B' ? 'Annexure B' : null;
}

function whichOfProduct(product) {
  return orderAnnexureRepository.which(product ? product.which : 'A');
}

async function index(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  const which = orderAnnexureRepository.which((req.query || {}).which);

  res.renderView('annexure/index', {
    order,
    which,
    products: await orderAnnexureRepository.forOrder(orderId, which),
    terms: await orderAnnexureTermsRepository.find(orderId, which),
    // D-336: "Copy everything from Annexure A" asks first when B already has content.
    bHasContent: which === 'B' && ((await orderAnnexureRepository.countFor(orderId, 'B')) > 0 || (await annexureChoice.termsHtml(orderId, 'B')) !== ''),
    // 90-item backlog #73/#74: staff editing this screen have no
    // indication the Annexure was already approved/sent to the buyer —
    // surfaced as a warning banner + edit confirmation in the view, never
    // a hard block (consistent with every other staff-trust gate already
    // documented in this codebase). D-336: Annexure B has no document of
    // its own (it is printed with the CI and PL), so the warning is A's only.
    annexaDocument: which === 'A' ? await documentRepository.findLatestForOrderAndTypeCode(orderId, 'ANNEXA') : null,
  }, 'layout/base');
}

async function toggleInclude(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const which = orderAnnexureRepository.which(req.body.which);
  let include;
  if (which === 'B') {
    include = !!req.body.include_annexure_b;
    await orderRepository.setIncludeAnnexureB(orderId, include);
    await auditLogRepository.log(req.user ? req.user.id : null, 'ANNEXURE_INCLUDE_TOGGLED', 'orders', orderId, 'include_annexure_b', null, String(Number(include)), auditNote(which));
  } else {
    include = !!req.body.include_annexure_a;
    await orderRepository.setIncludeAnnexureA(orderId, include);
    // External QA report N-08: this action never reached the audit log,
    // so it never notified anyone either.
    await auditLogRepository.log(req.user ? req.user.id : null, 'ANNEXURE_INCLUDE_TOGGLED', 'orders', orderId, 'include_annexure_a', null, String(Number(include)));
  }
  flash.set(req, 'success', `Annexure ${which} ${include ? 'enabled' : 'disabled'} for this order.`);
  res.redirect(annexureUrl(orderId, which));
}

async function updateMode(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const which = orderAnnexureRepository.which(req.body.which);
  const mode = String(req.body.mode || '');
  if (!VALID_MODES.includes(mode)) {
    flash.set(req, 'error', `Invalid Annexure ${which} content mode.`);
    res.redirect(annexureUrl(orderId, which));
    return;
  }

  if (which === 'B') {
    await orderRepository.setAnnexureBMode(orderId, mode);
  } else {
    await orderRepository.setAnnexureMode(orderId, mode);
  }
  await auditLogRepository.log(req.user.id, 'ANNEXURE_MODE_UPDATED', 'order', orderId, which === 'B' ? 'annexure_b_mode' : 'annexure_mode', null, mode, auditNote(which));
  flash.set(req, 'success', `Annexure ${which} content mode updated.`);
  res.redirect(annexureUrl(orderId, which));
}

async function updateTerms(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const which = orderAnnexureRepository.which(req.body.which);
  const sanitized = annexureTermsSanitizer.sanitize(String(req.body.content_html || ''));

  await orderAnnexureTermsRepository.upsert(orderId, sanitized, req.user.id, which);
  await auditLogRepository.log(req.user.id, 'ANNEXURE_TERMS_UPDATED', 'order', orderId, 'content_html', null, null, auditNote(which));
  flash.set(req, 'success', 'Additional Terms saved.');
  res.redirect(annexureUrl(orderId, which));
}

/**
 * D-336: "Copy everything from Annexure A" — Annexure B becomes a copy of
 * Annexure A: its product entries (with the same images), its Additional Terms
 * and its content mode. Whatever B held is replaced (the page asks first when
 * B already has content).
 */
async function copyFromA(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  const count = await annexureChoice.copyAToB(order, req.user.id);
  const entries = `${count} product entr${count === 1 ? 'y' : 'ies'}`;
  await auditLogRepository.log(req.user.id, 'ANNEXURE_COPIED_FROM_A', 'order', orderId, null, null, entries, 'Annexure B');
  flash.set(req, 'success', `Annexure B is now a copy of Annexure A (${entries}, images, Additional Terms and content). Change what is different.`);
  res.redirect(annexureUrl(orderId, 'B'));
}

async function createProduct(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const which = orderAnnexureRepository.which(req.body.which);
  const name = String(req.body.name || '').trim();
  if (name === '') {
    flash.set(req, 'error', 'Product name is required.');
    res.redirect(annexureUrl(orderId, which));
    return;
  }

  const productId = await orderAnnexureRepository.createProduct(orderId, {
    name,
    description: String(req.body.description || '').trim(),
    dimensions: String(req.body.dimensions || '').trim(),
    finish: String(req.body.finish || '').trim(),
    components: String(req.body.components || '').trim(),
    technical_notes: String(req.body.technical_notes || '').trim(),
  }, which);

  if (req.file) {
    const order = await orderRepository.find(orderId);
    try {
      const fileId = await fileUploadService.handleUpload(
        req,
        'image',
        'product_image',
        orderStorage.folder(order, 'annexure'),
        null,
        orderId,
        req.user.id
      );
      await orderAnnexureRepository.addImage(productId, fileId);
    } catch (e) {
      flash.set(req, 'error', `Product entry added, but the image failed to upload: ${flash.describeException(e, 'upload failed — check the server error log.')}`);
      res.redirect(annexureUrl(orderId, which));
      return;
    }
  }

  // External QA report N-08: this action never reached the audit log,
  // so it never notified anyone either.
  await auditLogRepository.log(req.user.id, 'ANNEXURE_PRODUCT_CREATED', 'order_annexure_products', productId, null, null, name, auditNote(which));
  flash.set(req, 'success', 'Product entry added.');
  res.redirect(annexureUrl(orderId, which));
}

async function updateProduct(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const productId = parseInt(req.params.productId, 10);
  const which = whichOfProduct(await orderAnnexureRepository.findProduct(productId));
  const name = String(req.body.name || '').trim();
  if (name === '') {
    flash.set(req, 'error', 'Product name is required.');
    res.redirect(annexureUrl(orderId, which));
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
  await auditLogRepository.log(req.user.id, 'ANNEXURE_PRODUCT_UPDATED', 'order_annexure_products', productId, 'name', null, name, auditNote(which));
  flash.set(req, 'success', 'Product entry updated.');
  res.redirect(annexureUrl(orderId, which));
}

async function deleteProduct(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const productId = parseInt(req.params.productId, 10);
  const which = whichOfProduct(await orderAnnexureRepository.findProduct(productId));
  if (await recordCheck.missing(req, res, 'order_annexure_products', productId, annexureUrl(orderId, which), 'product entry', { order_id: orderId })) return;
  await orderAnnexureRepository.deleteProduct(productId);
  // External QA report N-08: this action never reached the audit log,
  // so it never notified anyone either.
  await auditLogRepository.log(req.user.id, 'ANNEXURE_PRODUCT_DELETED', 'order_annexure_products', productId, null, null, null, auditNote(which));
  flash.set(req, 'success', 'Product entry removed.');
  res.redirect(annexureUrl(orderId, which));
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
  const which = whichOfProduct(product);

  try {
    const fileId = await fileUploadService.handleUpload(
      req,
      'image',
      'product_image',
      orderStorage.folder(order, 'annexure'),
      null,
      orderId,
      req.user.id
    );
    const imageId = await orderAnnexureRepository.addImage(productId, fileId);
    // External QA report N-08: this action never reached the audit
    // log, so it never notified anyone either.
    await auditLogRepository.log(req.user.id, 'ANNEXURE_IMAGE_UPLOADED', 'order_annexure_images', imageId, null, null, null, auditNote(which));
    flash.set(req, 'success', 'Image uploaded.');
  } catch (e) {
    flash.setErrorFromException(req, e);
  }
  res.redirect(annexureUrl(orderId, which));
}

async function removeImage(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const imageId = parseInt(req.params.imageId, 10);
  const image = await orderAnnexureRepository.findImage(imageId);
  const which = whichOfProduct(image ? await orderAnnexureRepository.findProduct(image.order_annexure_product_id) : null);
  // QA Round 6, R6-N04: only an image of this order's annexure.
  if (!(await recordCheck.found('SELECT 1 AS found FROM order_annexure_images i JOIN order_annexure_products p ON p.id = i.order_annexure_product_id WHERE i.id = :i AND p.order_id = :o', { i: imageId, o: orderId }))) {
    flash.set(req, 'error', 'That image was not found — it may have been removed already. Nothing was changed.');
    res.redirect(annexureUrl(orderId, which));
    return;
  }
  await orderAnnexureRepository.removeImage(imageId);
  // External QA report N-08: this action never reached the audit log,
  // so it never notified anyone either.
  await auditLogRepository.log(req.user.id, 'ANNEXURE_IMAGE_REMOVED', 'order_annexure_images', imageId, null, null, null, auditNote(which));
  flash.set(req, 'success', 'Image removed.');
  res.redirect(annexureUrl(orderId, which));
}

module.exports = { index, toggleInclude, updateMode, updateTerms, copyFromA, createProduct, updateProduct, deleteProduct, uploadImage, removeImage };
