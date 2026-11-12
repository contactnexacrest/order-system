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
const db = require('../config/db');

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
  await renderPage(res, order, which, String((req.query || {}).start || '') === 'empty');
}

/**
 * The annexure page. D-341: errors / old re-show a "Save all changes" that was
 * refused (messages beside the fields, the typed values kept, nothing saved).
 */
async function renderPage(res, order, which, startEmpty = false, errors = {}, old = null) {
  const orderId = Number(order.id);
  const made = await annexureChoice.bMade(orderId);
  res.renderView('annexure/index', {
    order,
    which,
    products: await orderAnnexureRepository.forOrder(orderId, which),
    terms: await orderAnnexureTermsRepository.find(orderId, which),
    // D-336: "Copy everything from Annexure A" asks first when B already has content.
    bHasContent: which === 'B' && made,
    // D-341: Annexure B from the Commercial Invoice step on (or once it has content).
    bAvailable: made || await annexureChoice.ciStageReached(orderId),
    // D-341: first visit of an empty Annexure B — "Start from a copy of Annexure A".
    bStartChoice: which === 'B' && !made && !startEmpty && old === null,
    bNotYetNote: annexureChoice.B_NOT_YET_NOTE,
    // D-341: Annexure A changed after its approved / sent document was generated.
    staleAnnexa: which === 'A' ? await annexureChoice.staleApprovedAnnexA(orderId) : null,
    // D-341: the order page's regeneration confirm (QA M-08), for "Generate the new revision".
    annexaRegenerationRisk: which === 'A' ? await require('../services/documentGenerationService').downstreamDocumentsAtRisk(orderId, 'ANNEXA') : [],
    errors,
    old,
    imageMaxBytes: (await fileUploadService.maxBytes('product_image')) || 5242880,
    // 90-item backlog #73/#74: staff editing this screen have no
    // indication the Annexure was already approved/sent to the buyer —
    // surfaced as a warning banner + edit confirmation in the view, never
    // a hard block (consistent with every other staff-trust gate already
    // documented in this codebase). D-336: Annexure B has no document of
    // its own (it is printed with the CI and PL), so the warning is A's only.
    annexaDocument: which === 'A' ? await documentRepository.findLatestForOrderAndTypeCode(orderId, 'ANNEXA') : null,
  }, 'layout/base');
}

/**
 * D-341: Annexure B before the Commercial Invoice step (and still empty) — the
 * change is refused and the page goes back to Annexure A. True when refused.
 */
async function bRefused(req, res, orderId, which) {
  if (which !== 'B' || await annexureChoice.bAvailable(orderId)) return false;
  flash.set(req, 'error', `${annexureChoice.B_NOT_YET_NOTE} Nothing was changed.`);
  res.redirect(annexureUrl(orderId, 'A'));
  return true;
}

/** The product entry fields "Save all changes" saves (D-341). */
const ENTRY_FIELDS = ['name', 'dimensions', 'finish', 'description', 'components', 'technical_notes'];

/** D-341: the newly chosen image per entry (<input type="file" name="images[<entry id>]">), keyed by entry id. */
function postedImages(req) {
  const out = {};
  for (const file of req.files || []) {
    const m = /^images\[(\d+)\]$/.exec(file.fieldname || '');
    if (m && file.originalname) out[parseInt(m[1], 10)] = file;
  }
  return out;
}

/**
 * Owner decision D-341 (12 Nov 2026): one "Save all changes" for the whole
 * annexure page — the content mode, every product entry's fields, the Additional
 * Terms and any newly chosen image per entry (images[<entry id>]), saved together.
 * Anything wrong (a missing name, an image of the wrong type or too large)
 * re-shows the page with the message beside the field and nothing saved. One
 * audit row per changed entry, image, the terms and the mode.
 */
async function saveAll(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  const body = req.body || {};
  const which = orderAnnexureRepository.which(body.which);
  if (await bRefused(req, res, orderId, which)) return;

  const products = {};
  for (const p of await orderAnnexureRepository.forOrder(orderId, which)) products[Number(p.id)] = p;
  const errors = {};
  const entryError = (id, field, message) => {
    errors.entries = errors.entries || {};
    errors.entries[id] = errors.entries[id] || {};
    errors.entries[id][field] = message;
  };

  const currentMode = annexureChoice.mode(order, which);
  const mode = body.mode !== undefined ? String(body.mode) : currentMode;
  if (!VALID_MODES.includes(mode)) errors.mode = `Choose what Annexure ${which} contains.`;

  const postedEntries = body.entries && typeof body.entries === 'object' ? body.entries : {};
  const entries = {};
  for (const key of Object.keys(postedEntries)) {
    const id = parseInt(key, 10);
    const fields = postedEntries[key];
    if (!products[id] || !fields || typeof fields !== 'object') continue; // not an entry of this annexure (removed meanwhile) — ignored
    const data = {};
    for (const f of ENTRY_FIELDS) data[f] = String(fields[f] === undefined || fields[f] === null ? '' : fields[f]).trim();
    if (data.name === '') entryError(id, 'name', 'Product name is required.');
    entries[id] = data;
  }

  const uploads = postedImages(req);
  for (const key of Object.keys(uploads)) {
    const id = Number(key);
    if (!products[id]) continue;
    try {
      await fileUploadService.checkUpload(uploads[key], 'product_image');
    } catch (e) {
      entryError(id, 'image', flash.describeException(e, 'The image could not be uploaded.'));
    }
  }

  const termsPosted = Object.prototype.hasOwnProperty.call(body, 'content_html');
  const terms = annexureTermsSanitizer.sanitize(String(body.content_html || ''));

  if (Object.keys(errors).length) {
    res.status(422);
    await renderPage(res, order, which, true, errors, { mode, entries, content_html: termsPosted ? terms : null });
    return;
  }

  const userId = req.user.id;
  const note = auditNote(which);
  const audits = [];
  try {
    // The new images are stored first (checked above); the order's rows change in one transaction.
    const stored = [];
    for (const key of Object.keys(uploads)) {
      const id = Number(key);
      if (!products[id]) continue;
      stored.push({ id, fileId: await fileUploadService.storeUpload(uploads[key], 'product_image', orderStorage.folder(order, 'annexure'), null, orderId, userId) });
    }
    const currentTerms = await annexureChoice.termsHtml(orderId, which);
    await db.transaction(async (conn) => {
      if (mode !== currentMode) {
        await conn.execute(`UPDATE orders SET ${which === 'B' ? 'annexure_b_mode' : 'annexure_mode'} = :mode WHERE id = :id`, { mode, id: orderId });
        audits.push(['ANNEXURE_MODE_UPDATED', 'order', orderId, which === 'B' ? 'annexure_b_mode' : 'annexure_mode', currentMode, mode]);
      }
      for (const key of Object.keys(entries)) {
        const id = Number(key);
        const data = entries[key];
        const before = products[id];
        if (ENTRY_FIELDS.some((f) => data[f] !== String(before[f] === null || before[f] === undefined ? '' : before[f]).trim())) {
          await conn.execute(
            `UPDATE order_annexure_products SET name = :name, description = :description, dimensions = :dimensions,
               finish = :finish, components = :components, technical_notes = :technical_notes WHERE id = :id`,
            {
              id, name: data.name, description: data.description || null, dimensions: data.dimensions || null,
              finish: data.finish || null, components: data.components || null, technical_notes: data.technical_notes || null,
            }
          );
          audits.push(['ANNEXURE_PRODUCT_UPDATED', 'order_annexure_products', id, 'name', String(before.name), data.name]);
        }
      }
      if (termsPosted && terms !== currentTerms) {
        const existing = await conn.queryOne('SELECT id FROM order_annexure_terms WHERE order_id = :o AND `which` = :w', { o: orderId, w: which });
        if (existing) {
          await conn.execute('UPDATE order_annexure_terms SET content_html = :c, updated_by = :u WHERE id = :id', { c: terms, u: userId, id: existing.id });
        } else {
          await conn.execute('INSERT INTO order_annexure_terms (order_id, `which`, content_html, updated_by) VALUES (:o, :w, :c, :u)', { o: orderId, w: which, c: terms, u: userId });
        }
        audits.push(['ANNEXURE_TERMS_UPDATED', 'order', orderId, 'content_html', null, null]);
      }
      for (const s of stored) {
        const result = await conn.execute(
          `INSERT INTO order_annexure_images (order_annexure_product_id, file_id, sort_order)
           VALUES (:p, :f, (SELECT next_sort FROM (SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort FROM order_annexure_images WHERE order_annexure_product_id = :p2) t))`,
          { p: s.id, p2: s.id, f: s.fileId }
        );
        audits.push(['ANNEXURE_IMAGE_UPLOADED', 'order_annexure_images', result.insertId, null, null, null]);
      }
    });
  } catch (e) {
    flash.set(req, 'error', `Nothing was saved: ${flash.describeException(e, 'the changes could not be saved — check the server error log.')}`);
    res.redirect(annexureUrl(orderId, which));
    return;
  }
  for (const a of audits) {
    await auditLogRepository.log(userId, a[0], a[1], a[2], a[3], a[4], a[5], note);
  }
  flash.set(req, 'success', audits.length ? `All changes to Annexure ${which} saved.` : 'Nothing had changed — nothing to save.');
  res.redirect(annexureUrl(orderId, which));
}

async function toggleInclude(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const which = orderAnnexureRepository.which(req.body.which);
  if (await bRefused(req, res, orderId, which)) return;
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
  if (await bRefused(req, res, orderId, which)) return;
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
  if (await bRefused(req, res, orderId, which)) return;
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
  if (await bRefused(req, res, orderId, 'B')) return;
  const count = await annexureChoice.copyAToB(order, req.user.id);
  const entries = `${count} product entr${count === 1 ? 'y' : 'ies'}`;
  await auditLogRepository.log(req.user.id, 'ANNEXURE_COPIED_FROM_A', 'order', orderId, null, null, entries, 'Annexure B');
  flash.set(req, 'success', `Annexure B is now a copy of Annexure A (${entries}, images, Additional Terms and content). Change what is different.`);
  res.redirect(annexureUrl(orderId, 'B'));
}

async function createProduct(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const which = orderAnnexureRepository.which(req.body.which);
  if (await bRefused(req, res, orderId, which)) return;
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
  await auditLogRepository.log(req.user.id, 'ANNEXURE_PRODUCT_DELETED', 'order_annexure_products', productId, 'order_id', String(orderId), null, auditNote(which));
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
  await auditLogRepository.log(req.user.id, 'ANNEXURE_IMAGE_REMOVED', 'order_annexure_images', imageId, 'order_id', String(orderId), null, auditNote(which));
  flash.set(req, 'success', 'Image removed.');
  res.redirect(annexureUrl(orderId, which));
}

module.exports = { index, saveAll, toggleInclude, updateMode, updateTerms, copyFromA, createProduct, updateProduct, deleteProduct, uploadImage, removeImage };
