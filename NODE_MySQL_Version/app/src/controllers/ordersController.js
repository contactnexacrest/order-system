'use strict';

const fs = require('fs');
const JSZip = require('jszip');
const flash = require('../helpers/flash');
const reasonValidator = require('../helpers/reasonValidator');
const adminOverrideRepository = require('../repositories/adminOverrideRepository');
const amendmentRepository = require('../repositories/amendmentRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const caFyLockRepository = require('../repositories/caFyLockRepository');
const caFyLockGuard = require('../services/caFyLockGuard');
const orderDuplicationService = require('../services/orderDuplicationService');
const orderEditGuard = require('../services/orderEditGuard');
const clientPaymentReportRepository = require('../repositories/clientPaymentReportRepository');
const orderCommentRepository = require('../repositories/orderCommentRepository');
const clientRepository = require('../repositories/clientRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const hsCodeRepository = require('../repositories/hsCodeRepository');
const orderOcAcknowledgmentRepository = require('../repositories/orderOcAcknowledgmentRepository');
const disputeRepository = require('../repositories/disputeRepository');
const documentCrossVerificationRepository = require('../repositories/documentCrossVerificationRepository');
const documentRepository = require('../repositories/documentRepository');
const documentReviewRepository = require('../repositories/documentReviewRepository');
const emailLogRepository = require('../repositories/emailLogRepository');
const lookupRepository = require('../repositories/lookupRepository');
const orderCrateRepository = require('../repositories/orderCrateRepository');
const orderFreightRepository = require('../repositories/orderFreightRepository');
const orderPackingRepository = require('../repositories/orderPackingRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const orderProductionRepository = require('../repositories/orderProductionRepository');
const orderProductRepository = require('../repositories/orderProductRepository');
const orderRepository = require('../repositories/orderRepository');
const orderShippingRepository = require('../repositories/orderShippingRepository');
const orderStageRepository = require('../repositories/orderStageRepository');
const orderSupplierPoRepository = require('../repositories/orderSupplierPoRepository');
const orderBuyerPoDocumentRepository = require('../repositories/orderBuyerPoDocumentRepository');
const fileStoreRepository = require('../repositories/fileStoreRepository');
const orderSupplierPoDocumentRepository = require('../repositories/orderSupplierPoDocumentRepository');
const piIntakeRepository = require('../repositories/piIntakeRepository');
const supplierRepository = require('../repositories/supplierRepository');
const userRepository = require('../repositories/userRepository');
const env = require('../config/env');
const emailService = require('../services/emailService');
const referenceNumberService = require('../services/referenceNumberService');
const stageGateService = require('../services/stageGateService');
const documentGenerationService = require('../services/documentGenerationService');
const clientPortalService = require('../services/clientPortalService');
const fileUploadService = require('../services/fileUploadService');
const testModeService = require('../services/testModeService');

// Port of App\Controllers\OrderController.

function todayYmd() {
  return new Date().toISOString().slice(0, 10);
}

function addDaysYmd(fromYmd, days) {
  const d = fromYmd ? new Date(`${fromYmd}T00:00:00Z`) : new Date();
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}

function sanitizePathSegment(value) {
  return String(value == null ? '' : value).replace(/[^A-Za-z0-9_-]+/g, '-');
}

function str(v, fallback = '') {
  return String(v ?? fallback).trim();
}

/**
 * Card-grid list (2026-09-23 redesign) — the filter chips are plain
 * query-string links (?status=...), no JS. Counts for the chip labels
 * always come from the full unfiltered set so a chip never has to be
 * clicked to know its count.
 */
async function index(req, res) {
  const all = await orderRepository.all();
  const statusFilter = String(req.query.status || '').trim();

  const counts = { all: all.length, active: 0, overdue: 0, complete: 0, lost: 0 };
  for (const o of all) {
    if (o.is_overdue) counts.overdue++;
    if (Object.prototype.hasOwnProperty.call(counts, o.status)) counts[o.status]++;
  }

  let orders = all;
  if (statusFilter === 'overdue') {
    orders = all.filter((o) => !!o.is_overdue);
  } else if (['active', 'complete', 'lost'].includes(statusFilter)) {
    orders = all.filter((o) => o.status === statusFilter);
  }

  // Precomputed here (not in the template) since Nunjucks has no min/max
  // filter — how many of the 9 mini-stage-track segments render filled.
  const stageTotal = 9;
  orders = orders.map((o) => ({
    ...o,
    filled_segments: o.status === 'complete' ? stageTotal : (o.status === 'lost' ? Math.max(o.current_stage_number || 0, 1) : (o.current_stage_number || 0)),
  }));

  res.renderView('orders/index', {
    orders,
    statusFilter: statusFilter || 'all',
    counts,
  }, 'layout/base');
}

async function archivedIndex(req, res) {
  res.renderView('orders/archived', { orders: await orderRepository.allArchived() }, 'layout/base');
}

/**
 * Visibility-only, never a deletion path — see schema.sql Section W. No
 * reason is required (matches the toggle-active precedent for a
 * reversible, non-destructive state flip); the confirm() dialog on the
 * button is what stands in for a deliberate-action check here.
 */
async function archive(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    flash.set(req, 'error', 'Order not found.');
    res.redirect('/orders');
    return;
  }
  await orderRepository.archive(orderId, req.user.id);
  await auditLogRepository.log(req.user.id, 'ORDER_ARCHIVED', 'orders', orderId, 'is_archived', '0', '1');
  flash.set(req, 'success', `${order.order_reference} archived — it no longer appears in the main Orders list. Nothing was deleted; view it any time from Archived Orders.`);
  res.redirect('/orders');
}

async function unarchive(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    flash.set(req, 'error', 'Order not found.');
    res.redirect('/orders/archived');
    return;
  }
  await orderRepository.unarchive(orderId);
  await auditLogRepository.log(req.user.id, 'ORDER_UNARCHIVED', 'orders', orderId, 'is_archived', '1', '0');
  flash.set(req, 'success', `${order.order_reference} restored to the main Orders list.`);
  res.redirect(`/orders/${orderId}`);
}

/**
 * Order-Edit feature — "repeat order" for a client who's ordered before,
 * even long after the original closed. Never touches the source order
 * (no gate needed there — nothing about it changes) and the new order
 * starts fresh at Stage 1, exactly like one created by hand through
 * /orders/create.
 */
async function duplicateOrder(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    flash.set(req, 'error', 'Order not found.');
    res.redirect('/orders');
    return;
  }
  const newOrderId = await orderDuplicationService.duplicate(orderId, req.user.id);
  flash.set(req, 'success', `Duplicated ${order.order_reference} into a new order — review and adjust it below before proceeding.`);
  res.redirect(`/orders/${newOrderId}/edit`);
}

async function create(req, res) {
  const preselectedClientId = req.query.client_id ? parseInt(req.query.client_id, 10) : null;
  res.renderView(
    'orders/create',
    {
      clients: await clientRepository.all(),
      incoterms: await lookupRepository.incoterms(),
      currencies: await lookupRepository.currencies(),
      loadingPorts: await lookupRepository.ports('loading'),
      dischargePorts: await lookupRepository.ports('discharge'),
      paymentPresets: await lookupRepository.paymentPresets(),
      cooTypes: await lookupRepository.dropdownOptions('coo_type'),
      containerTypes: await lookupRepository.dropdownOptions('container_type'),
      hsCodes: await hsCodeRepository.active(),
      preselectedClientId,
    },
    'layout/base'
  );
}

async function store(req, res) {
  const user = req.user;
  const body = req.body;

  const clientId = parseInt(body.client_id || 0, 10);
  const client = clientId ? await clientRepository.find(clientId) : null;
  if (!client) {
    flash.set(req, 'error', 'Please select a valid client.');
    res.redirect('/orders/create');
    return;
  }

  const incotermId = parseInt(body.incoterm_id || 0, 10);
  const currencyId = parseInt(body.currency_id || 0, 10);
  const paymentPresetId = parseInt(body.payment_preset_id || 0, 10);
  if (!incotermId || !currencyId || !paymentPresetId) {
    flash.set(req, 'error', 'Incoterm, currency, and a payment preset are all required.');
    res.redirect(`/orders/create?client_id=${clientId}`);
    return;
  }

  const descriptions = [].concat(body.product_description || []);
  const hasAtLeastOneProduct = descriptions.some((d) => str(d) !== '');
  if (!hasAtLeastOneProduct) {
    flash.set(req, 'error', 'At least one product line (with a description) is required.');
    res.redirect(`/orders/create?client_id=${clientId}`);
    return;
  }

  // HS code must come from the master list (docs/schema.sql Section AB) —
  // never freehand — so a typo or an invalid code can never reach an
  // order. Checked here, before anything is written, so a bad code never
  // leaves a half-created order behind.
  {
    const hsCodesInput = [].concat(body.product_hs_code || []);
    for (let i = 0; i < descriptions.length; i++) {
      if (str(descriptions[i]) === '') continue;
      const hsCode = str(hsCodesInput[i]);
      if (hsCode === '' || !(await hsCodeRepository.isActiveCode(hsCode))) {
        flash.set(req, 'error', `HS code "${hsCode}" is not on the HS Code master list — add it there first (HS Codes, under Admin) before using it on an order.`);
        res.redirect(`/orders/create?client_id=${clientId}`);
        return;
      }
    }
  }

  const portOfDischargeId = body.port_of_discharge_id ? parseInt(body.port_of_discharge_id, 10) : null;
  const portOfDischargeText = str(body.port_of_discharge_text);

  const sequenceNo = await orderRepository.nextSequenceForClient(clientId);
  const orderRefFormat = (await companySettingsRepository.get('order_ref_format')) || 'SC/OC/{YYYY}/{NNN}';
  const testModeEnabled = await testModeService.isEnabled();
  const orderReference = testModeService.applyReferencePrefix(
    orderRefFormat
      .replace(/\{YYYY\}/g, String(new Date().getFullYear()))
      .replace(/\{NNN\}/g, String(sequenceNo).padStart(3, '0')) + `-${clientId}`, // client suffix keeps this globally unique even though the format string isn't scoped per-client
    testModeEnabled
  );

  const quotationValidityDays = parseInt((await companySettingsRepository.get('quotation_validity_days')) || '30', 10);

  const orderId = await orderRepository.create(
    {
      order_reference: orderReference,
      client_id: clientId,
      sequence_no: sequenceNo,
      buyer_inquiry_ref: client.client_unique_number,
      payment_preset_id: paymentPresetId,
      incoterm_id: incotermId,
      port_of_loading_id: body.port_of_loading_id ? parseInt(body.port_of_loading_id, 10) : null,
      port_of_discharge_id: portOfDischargeId,
      port_of_discharge_text: portOfDischargeId ? null : portOfDischargeText || null,
      currency_id: currencyId,
      coo_type: str(body.coo_type) || client.coo_type || 'TBC',
      include_annexure_a: !!body.include_annexure_a,
      special_requirements: str(body.special_requirements) || null,
      container_type: str(body.container_type) || null,
      estimated_total_cbm: str(body.estimated_total_cbm),
      estimated_gross_weight_kg: str(body.estimated_gross_weight_kg),
      estimated_net_weight_kg: str(body.estimated_net_weight_kg),
      estimated_package_count: str(body.estimated_package_count) || null,
      estimated_package_type: str(body.estimated_package_type) || null,
      est_lead_time_text: str(body.est_lead_time_text) || null,
      indicative_freight_low: str(body.indicative_freight_low),
      indicative_freight_high: str(body.indicative_freight_high),
      indicative_insurance_amount: str(body.indicative_insurance_amount),
      buyers_po_ref: 'NIL',
      quotation_date: todayYmd(),
      quotation_valid_until: addDaysYmd(todayYmd(), quotationValidityDays),
    },
    user.id
  );
  if (testModeEnabled) {
    await orderRepository.markTest(orderId);
  }

  await orderStageRepository.initializeForOrder(orderId);
  await orderPaymentStatusRepository.initializeForOrder(orderId);

  const dimensions = [].concat(body.product_dimensions || []);
  const finishes = [].concat(body.product_finish || []);
  const quantities = [].concat(body.product_quantity || []);
  const quantityTbcFlags = body.product_quantity_tbc || {};
  const units = [].concat(body.product_unit || []);
  const unitPrices = [].concat(body.product_unit_price || []);
  const hsCodes = [].concat(body.product_hs_code || []);

  let lineNo = 1;
  for (let i = 0; i < descriptions.length; i++) {
    const description = str(descriptions[i]);
    if (description === '') continue; // blank row — skip rather than insert an empty product
    const quantityIsTbc = !!(Array.isArray(quantityTbcFlags) ? quantityTbcFlags[i] : quantityTbcFlags[i]);
    await orderProductRepository.add(
      orderId,
      lineNo++,
      description,
      str(dimensions[i]) || null,
      str(finishes[i]) || null,
      str(quantities[i]) || null,
      quantityIsTbc,
      str(units[i]) || null,
      str(unitPrices[i]) || null,
      str(hsCodes[i])
    );
  }

  flash.set(req, 'success', `Order ${orderReference} created for ${client.company_legal_name}.`);
  res.redirect(`/orders/${orderId}`);
}

/**
 * Order-Edit feature — the core fields set once at order creation had no
 * edit path at all until now. Deliberately excludes payment terms
 * (advance/balance %, balance trigger/days) — those change through the
 * Amendments module specifically, never here, so there's exactly one
 * place that changes payment terms. Freely editable before Order
 * Confirmation (Stage 4); gated by orderEditGuard from Stage 4 on.
 */
async function editDetails(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  res.renderView(
    'orders/edit_details',
    {
      order,
      incoterms: await lookupRepository.incoterms(),
      currencies: await lookupRepository.currencies(),
      loadingPorts: await lookupRepository.ports('loading'),
      dischargePorts: await lookupRepository.ports('discharge'),
      cooTypes: await lookupRepository.dropdownOptions('coo_type'),
      containerTypes: await lookupRepository.dropdownOptions('container_type'),
      isPostConfirmation: orderEditGuard.isPostConfirmation(order.current_stage_number),
      canOverride: orderEditGuard.canOverride(req),
    },
    'layout/base'
  );
}

async function updateDetails(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  const body = req.body;

  const incotermId = parseInt(body.incoterm_id || 0, 10);
  const currencyId = parseInt(body.currency_id || 0, 10);
  if (!incotermId || !currencyId) {
    flash.set(req, 'error', 'Incoterm and currency are both required.');
    res.redirect(`/orders/${orderId}/edit`);
    return;
  }

  const reason = str(body.reason);
  if (!(await orderEditGuard.allow(req, order.current_stage_number, reason, 'orders', orderId, 'order_details'))) {
    res.redirect(`/orders/${orderId}/edit`);
    return;
  }

  const portOfDischargeId = body.port_of_discharge_id ? parseInt(body.port_of_discharge_id, 10) : null;
  const portOfDischargeText = str(body.port_of_discharge_text);

  await orderRepository.updateDetails(orderId, {
    incoterm_id: incotermId,
    currency_id: currencyId,
    port_of_loading_id: body.port_of_loading_id ? parseInt(body.port_of_loading_id, 10) : null,
    port_of_discharge_id: portOfDischargeId,
    port_of_discharge_text: portOfDischargeId ? null : portOfDischargeText || null,
    coo_type: str(body.coo_type) || 'TBC',
    container_type: str(body.container_type) || null,
    buyers_po_ref: str(body.buyers_po_ref) || 'NIL',
    special_requirements: str(body.special_requirements) || null,
    est_lead_time_text: str(body.est_lead_time_text) || null,
    estimated_total_cbm: str(body.estimated_total_cbm),
    estimated_gross_weight_kg: str(body.estimated_gross_weight_kg),
    estimated_net_weight_kg: str(body.estimated_net_weight_kg),
    estimated_package_count: str(body.estimated_package_count) || null,
    estimated_package_type: str(body.estimated_package_type) || null,
    indicative_freight_low: str(body.indicative_freight_low),
    indicative_freight_high: str(body.indicative_freight_high),
    indicative_insurance_amount: str(body.indicative_insurance_amount),
  });

  flash.set(req, 'success', 'Order details updated.');
  res.redirect(`/orders/${orderId}`);
}

/**
 * Order-Edit feature — order_products had no add/edit/delete/duplicate
 * path once the order was created; this closes that gap, gated by
 * orderEditGuard exactly like updateDetails() above once the order has
 * reached Order Confirmation.
 */
async function addProduct(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  const body = req.body;
  const description = str(body.description);
  const hsCode = str(body.hs_code);
  if (description === '') {
    flash.set(req, 'error', 'Description is required.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  if (hsCode === '' || !(await hsCodeRepository.isActiveCode(hsCode))) {
    flash.set(req, 'error', `HS code "${hsCode}" is not on the HS Code master list — add it there first (HS Codes, under Admin).`);
    res.redirect(`/orders/${orderId}`);
    return;
  }

  const reason = str(body.reason);
  if (!(await orderEditGuard.allow(req, order.current_stage_number, reason, 'order_products', orderId, 'product_added'))) {
    res.redirect(`/orders/${orderId}`);
    return;
  }

  await orderProductRepository.add(
    orderId,
    await orderProductRepository.nextLineNo(orderId),
    description,
    str(body.dimensions) || null,
    str(body.finish) || null,
    str(body.quantity) || null,
    !!body.quantity_is_tbc,
    str(body.unit) || null,
    str(body.unit_price) || null,
    hsCode
  );
  flash.set(req, 'success', 'Product line added.');
  res.redirect(`/orders/${orderId}`);
}

async function updateProduct(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const productId = parseInt(req.params.productId, 10);
  const order = await orderRepository.find(orderId);
  const product = await orderProductRepository.find(productId);
  if (!order || !product || product.order_id !== orderId) {
    res.status(404).send('Product line not found.');
    return;
  }
  const body = req.body;
  const description = str(body.description);
  const hsCode = str(body.hs_code);
  if (description === '') {
    flash.set(req, 'error', 'Description is required.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  if (hsCode === '' || !(await hsCodeRepository.isActiveCode(hsCode))) {
    flash.set(req, 'error', `HS code "${hsCode}" is not on the HS Code master list — add it there first (HS Codes, under Admin).`);
    res.redirect(`/orders/${orderId}`);
    return;
  }

  const reason = str(body.reason);
  if (!(await orderEditGuard.allow(req, order.current_stage_number, reason, 'order_products', productId, 'product_line'))) {
    res.redirect(`/orders/${orderId}`);
    return;
  }

  await orderProductRepository.update(
    productId,
    description,
    str(body.dimensions) || null,
    str(body.finish) || null,
    str(body.quantity) || null,
    !!body.quantity_is_tbc,
    str(body.unit) || null,
    str(body.unit_price) || null,
    hsCode
  );
  flash.set(req, 'success', 'Product line updated.');
  res.redirect(`/orders/${orderId}`);
}

async function deleteProduct(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const productId = parseInt(req.params.productId, 10);
  const order = await orderRepository.find(orderId);
  const product = await orderProductRepository.find(productId);
  if (!order || !product || product.order_id !== orderId) {
    res.status(404).send('Product line not found.');
    return;
  }

  const reason = str(req.body.reason);
  if (!(await orderEditGuard.allow(req, order.current_stage_number, reason, 'order_products', productId, 'product_removed'))) {
    res.redirect(`/orders/${orderId}`);
    return;
  }

  await orderProductRepository.softDelete(productId);
  flash.set(req, 'success', 'Product line removed.');
  res.redirect(`/orders/${orderId}`);
}

/** Clones a product line — the common case is "same product, just the name or dimension changed". */
async function duplicateProduct(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const productId = parseInt(req.params.productId, 10);
  const order = await orderRepository.find(orderId);
  const product = await orderProductRepository.find(productId);
  if (!order || !product || product.order_id !== orderId) {
    res.status(404).send('Product line not found.');
    return;
  }

  const reason = str(req.body.reason);
  if (!(await orderEditGuard.allow(req, order.current_stage_number, reason, 'order_products', productId, 'product_duplicated'))) {
    res.redirect(`/orders/${orderId}`);
    return;
  }

  await orderProductRepository.duplicate(productId);
  flash.set(req, 'success', 'Product line duplicated — edit the copy below to adjust its name or dimensions.');
  res.redirect(`/orders/${orderId}`);
}

async function show(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  // Archiving only removes an order from the default listing — direct
  // access by URL/bookmark still needs its own gate, or view_archived_orders
  // would be meaningless (Super Admin already bypasses every permission).
  if (order.is_archived && !req.permissions.view_archived_orders) {
    res.status(403).send('This order has been archived. You need the "View archived orders" permission to open it.');
    return;
  }

  const stages = await orderStageRepository.forOrder(orderId);
  const stageByNumber = {};
  for (const s of stages) stageByNumber[s.stage_number] = s;

  const documents = await documentRepository.forOrder(orderId);
  const reviewsByDocument = {};
  const crossVerificationsByDocument = {};
  for (const d of documents) {
    reviewsByDocument[d.id] = await documentReviewRepository.forDocument(d.id);
    crossVerificationsByDocument[d.id] = await documentCrossVerificationRepository.forDocument(d.id);
  }

  const disputes = await disputeRepository.forOrder(orderId);

  // Real gap this closes: emailLogRepository.forOrder() already existed
  // but nothing on this page ever called it, so the "Send to Buyer" link
  // would silently reappear after a Level-2 rejection or a cron dispatch
  // failure with no visible reason, and nothing stopped a second Level-1
  // request from being submitted while an earlier one still sat in the
  // approval queue. Grouped by document_id so each document's own send
  // history renders next to its own review/approval block below.
  const emailLogByDocument = {};
  for (const log of await emailLogRepository.forOrder(orderId)) {
    if (log.document_id !== null) {
      (emailLogByDocument[log.document_id] = emailLogByDocument[log.document_id] || []).push(log);
    }
  }

  const supplierPo = await orderSupplierPoRepository.findLatestForOrder(orderId);
  const activeUsers = await userRepository.listActive();
  const usersById = {};
  for (const u of activeUsers) usersById[u.id] = u.name;

  // Phase 6: precomputed here (not inside the .njk) since nunjucks macros
  // can't await a repository call — mirrors the PHP view's inline closures,
  // which call CaFyLockRepository::lockMessageForDate() directly.
  const paymentForLocks = await orderPaymentStatusRepository.find(orderId);
  const caLockMessages = { advance: null, balance: null, freight: null, exchangeRate: null };
  if (paymentForLocks) {
    caLockMessages.advance = await caFyLockRepository.lockMessageForDate(paymentForLocks.advance_cleared_at);
    caLockMessages.balance = await caFyLockRepository.lockMessageForDate(paymentForLocks.balance_cleared_at);
    caLockMessages.freight = await caFyLockRepository.lockMessageForDate(paymentForLocks.freight_cleared_at);
    caLockMessages.exchangeRate = caLockMessages.advance || caLockMessages.balance || caLockMessages.freight;
  }
  const piIntake = await piIntakeRepository.latestForOrder(orderId);
  let piFormFullLink = null;
  if (
    piIntake
    && ['awaiting_client', 'rejected'].includes(piIntake.status)
    && new Date(piIntake.access_token_expires_at).getTime() > Date.now()
  ) {
    piFormFullLink = `${env.get('APP_URL', '').replace(/\/+$/, '')}/pi-details/${piIntake.access_token_plain}`;
  }

  res.renderView(
    'orders/show',
    {
      order,
      products: await orderProductRepository.forOrder(orderId),
      hsCodes: await hsCodeRepository.active(),
      isPostConfirmationOrder: orderEditGuard.isPostConfirmation(order.current_stage_number),
      canOverrideOrderEdit: orderEditGuard.canOverride(req),
      canManageOrders: !!req.permissions.manage_orders,
      stages,
      stageByNumber,
      payment: await orderPaymentStatusRepository.find(orderId),
      documents,
      reviewsByDocument,
      crossVerificationsByDocument,
      emailLogByDocument,
      activeUsers,
      fobTotal: await orderProductRepository.totalFobValue(orderId),
      suppliers: await supplierRepository.all(),
      supplierPo,
      buyerPoDocuments: await orderBuyerPoDocumentRepository.forOrder(orderId),
      supplierPoDocuments: supplierPo ? await orderSupplierPoDocumentRepository.forSupplierPo(supplierPo.id) : [],
      freight: await orderFreightRepository.find(orderId),
      packing: await orderPackingRepository.find(orderId),
      orderedQuantitySummary: await orderProductRepository.orderedQuantitySummary(orderId),
      shortfallTolerance: (await companySettingsRepository.get('quantity_shortfall_tolerance_pct')) ?? '5',
      crates: await orderCrateRepository.forOrder(orderId),
      shipping: await orderShippingRepository.find(orderId),
      production: await orderProductionRepository.find(orderId),
      supplierTypes: await lookupRepository.dropdownOptions('supplier_type'),
      amendmentCount: (await amendmentRepository.forOrder(orderId)).length,
      openDisputeCount: disputes.filter((d) => d.status !== 'Resolved').length,
      piIntake,
      piFormFullLink,
      clientPaymentReports: await clientPaymentReportRepository.forOrder(orderId),
      ocAcknowledgment: await orderOcAcknowledgmentRepository.find(orderId),
      comments: await orderCommentRepository.forOrder(orderId),
      canViewInrActual: !!req.permissions.inr_actual_view,
      canEditInrActual: !!req.permissions.inr_actual_edit,
      canDeleteInrActual: !!req.permissions.inr_actual_delete,
      canOverrideFyLock: caFyLockGuard.canOverride(req),
      caLockMessages,
      usersById,
    },
    'layout/base'
  );
}

/**
 * Staff-initiated: generates (or regenerates) a per-order PI-stage
 * intake link and emails it to the client's on-file address — mirroring
 * clientPortalService's set-password email pattern. The link is also
 * flashed back once, same as a freshly created user's temp password, so
 * staff always have a way to hand it over even when no SMTP is
 * configured yet (emailService degrades to a log line in that case,
 * never a thrown error).
 */
async function generatePiFormLink(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }

  const user = req.user;
  const rawToken = await piIntakeRepository.createLink(orderId, user.id);
  const link = `${env.get('APP_URL', '').replace(/\/+$/, '')}/pi-details/${rawToken}`;

  if (order.client_email) {
    const body = 'Hello,\n\n'
      + `Thank you for accepting our Quotation for order ${order.order_reference}.\n\n`
      + `To issue your Proforma Invoice, please confirm your shipping/consignee details at:\n${link}\n\n`
      + 'This link works for 30 days.\n\n'
      + 'NexaCrest International Private Limited';
    await emailService.sendPlainText(order.client_email, 'NexaCrest — confirm your PI-stage details', body);
  }

  await auditLogRepository.log(user.id, 'PI_INTAKE_LINK_GENERATED', 'orders', orderId, null, null, null, 'PI-stage intake link generated' + (order.client_email ? ' and emailed to client' : ' — no client email on file, hand this link over directly'));
  flash.set(req, 'success', `PI-stage form link${order.client_email ? ` emailed to ${order.client_email}` : ' generated'}: ${link}`);
  res.redirect(`/orders/${orderId}`);
}

/**
 * Real gap this closes: no way existed to hand over (or archive)
 * everything on file for one order in a single action — every generated
 * document and every received/uploaded file (dispute evidence, buyer PO
 * copy, supplier PO acknowledgment, ...) had to be downloaded one at a
 * time. file_store.order_id is already set for both origins
 * (insertGenerated() and insertReceived()), so fileStoreRepository.forOrder()
 * alone is everything the dossier needs — no separate joins through
 * documents/dispute_documents/orderBuyerPoDocuments/etc.
 */
async function downloadDossier(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }

  const files = await fileStoreRepository.forOrder(orderId);
  if (files.length === 0) {
    flash.set(req, 'error', 'No files are on record for this order yet.');
    res.redirect(`/orders/${orderId}`);
    return;
  }

  const zip = new JSZip();
  const usedNames = new Set();
  for (const file of files) {
    if (!fs.existsSync(file.server_path)) {
      continue; // skip a row whose file went missing rather than fail the whole dossier
    }
    const folder = String(file.file_origin).startsWith('GENERATED') ? 'Generated Documents' : 'Received Documents';
    let name = file.original_filename;
    const key = `${folder}/${name}`;
    if (usedNames.has(key)) {
      // Same filename twice in the same folder (e.g. two QT revisions both
      // named "QT ... Rev.0.pdf" before a numbering scheme changed) —
      // disambiguate with the file_store id rather than silently letting
      // one overwrite the other inside the ZIP.
      const dot = name.lastIndexOf('.');
      name = dot > 0 ? `${name.slice(0, dot)} (#${file.id})${name.slice(dot)}` : `${name} (#${file.id})`;
    }
    usedNames.add(key);
    zip.file(`${folder}/${name}`, fs.readFileSync(file.server_path));
  }

  const buffer = await zip.generateAsync({ type: 'nodebuffer' });
  const safeOrderRef = String(order.order_reference).replace(/[^A-Za-z0-9_-]+/g, '-');
  const downloadName = `NexaCrest Dossier - ${safeOrderRef}.zip`;

  res.setHeader('Content-Type', 'application/zip');
  res.setHeader('Content-Disposition', `attachment; filename="${downloadName}"`);
  res.setHeader('Content-Length', String(buffer.length));
  res.send(buffer);
}

/** Stage 1->2 manual gate: buyer's signed PO received. */
async function recordBuyerPo(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  const ref = str(req.body.buyers_po_ref);
  if (ref === '') {
    flash.set(req, 'error', "Buyer's PO / reference number is required to confirm this gate.");
    res.redirect(`/orders/${orderId}`);
    return;
  }
  await orderRepository.setBuyersPoRef(orderId, ref);
  await stageGateService.passAndUnlockNext(orderId, 2, user.id);
  flash.set(req, 'success', `Buyer PO recorded (${ref}). Stage 3 (PI / Production) unlocked.`);
  res.redirect(`/orders/${orderId}`);
}

/**
 * Real gap this closes: recordBuyerPo() above only ever captured a
 * reference number typed by staff — the buyer's actual signed PO was
 * never kept on file anywhere, unlike every other counterparty-evidence
 * flow in this app (amendment_signed_copy, dispute_document,
 * buyer_approval). A separate upload endpoint (not folded into the
 * gate-confirmation form) so attaching a copy is never blocked by, or
 * required for, passing the gate — and a second/corrected upload adds a
 * new file_store row rather than replacing one, giving real version
 * history for free (schema.sql SECTION S).
 */
async function uploadBuyerPoDocument(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }

  try {
    const fileId = await fileUploadService.handleUpload(
      req,
      'document',
      'buyer_po_copy',
      `clients/${sanitizePathSegment(order.client_unique_number)}/${sanitizePathSegment(order.order_reference)}/buyer_po`,
      null,
      orderId,
      req.user.id,
      null,
      'buyer',
      'Buyer PO copy'
    );
    await orderBuyerPoDocumentRepository.attach(orderId, fileId);
    flash.set(req, 'success', 'Buyer PO copy attached.');
  } catch (e) {
    flash.set(req, 'error', e.message);
  }
  res.redirect(`/orders/${orderId}`);
}

async function recordAdvancePayment(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const amount = parseFloat(req.body.advance_amount || 0);
  const receivedAt = str(req.body.advance_received_at) || todayYmd();
  if (!(amount > 0)) {
    flash.set(req, 'error', 'Enter the advance amount actually received.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  await orderPaymentStatusRepository.recordAdvanceReceived(orderId, amount, receivedAt);

  // docs/schema.sql Section AC — fallback lock trigger. The PI-details
  // form (client's own consent) is the primary trigger; if a client never
  // completes that, real money moving is the latest point client data can
  // still be safely editable. A no-op if the PI-details consent already
  // locked this client first.
  {
    const order = await orderRepository.find(orderId);
    if (order) {
      await clientRepository.lockData(order.client_id, 'Auto-locked: advance remittance recorded before client PI-details consent');
    }
  }

  flash.set(req, 'success', 'Advance remittance recorded. Mark it cleared once your bank confirms receipt.');
  res.redirect(`/orders/${orderId}`);
}

/**
 * Acknowledges a client's self-reported payment (docs/schema.sql Section
 * AD) as seen — purely a bookkeeping marker for staff, never a substitute
 * for actually verifying the bank statement and recording the payment via
 * recordAdvancePayment()/recordBalancePayment()/recordFreightPayment() as
 * before.
 */
async function markPaymentReportReviewed(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const reportId = parseInt(req.params.reportId, 10) || 0;
  const report = await clientPaymentReportRepository.find(reportId);
  if (!report || report.order_id !== orderId) {
    flash.set(req, 'error', 'Payment report not found.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const user = req.user;
  await clientPaymentReportRepository.markReviewed(reportId, user.id);
  flash.set(req, 'success', 'Payment report marked reviewed.');
  res.redirect(`/orders/${orderId}`);
}

/** Stage 2->3 gate: advance payment marked cleared in NexaCrest's bank account. */
async function clearAdvancePayment(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  const clearedAt = str(req.body.advance_cleared_at) || todayYmd();

  const fobTotal = await orderProductRepository.totalFobValue(orderId);
  const order = await orderRepository.find(orderId);
  const balanceAmount = Math.round(fobTotal * (parseFloat(order.balance_pct) / 100) * 100) / 100;
  const balanceDueDate =
    order.balance_trigger_option === 'A_BEFORE_SHIPMENT'
      ? null // due date is event-triggered (shipment readiness), not a fixed date, for this preset
      : addDaysYmd(todayYmd(), parseInt(order.balance_days, 10));

  await orderPaymentStatusRepository.markAdvanceCleared(orderId, clearedAt, user.id);
  await orderPaymentStatusRepository.setBalanceAmount(orderId, balanceAmount, balanceDueDate);
  await stageGateService.passAndUnlockNext(orderId, 3, user.id);

  // Client portal access is provisioned here, and only here — see
  // clientPortalService's docblock. No-op if this client already has a
  // login from an earlier order.
  const provisionStatus = await clientPortalService.provisionIfNeeded(order.client_id, orderId);

  const baseMessage = 'Advance payment cleared. Stage 4 unlocked — you can now generate the Order Confirmation.';
  if (provisionStatus === 'provisioned') {
    flash.set(req, 'success', `${baseMessage} The client has been emailed their portal login.`);
  } else if (provisionStatus === 'no_email_on_file') {
    flash.set(req, 'warning', `${baseMessage} WARNING: this client has no email on file, so portal login could NOT be provisioned — add an email to their record and provision access manually, otherwise they will never be able to log in.`);
  } else {
    flash.set(req, 'success', `${baseMessage} The client already has portal access from an earlier order.`);
  }
  res.redirect(`/orders/${orderId}`);
}

async function updateProductionStatus(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const text = str(req.body.production_status_text);
  if (text !== '') {
    await orderRepository.setProductionStatus(orderId, text);
  }
  const shipmentText = str(req.body.est_shipment_date_text);
  if (shipmentText !== '') {
    await orderRepository.setEstShipmentDate(orderId, shipmentText);
  }
  flash.set(req, 'success', 'Production status updated.');
  res.redirect(`/orders/${orderId}`);
}

/** Stage 4->5 gate: buyer acknowledges the Order Confirmation. */
/**
 * Stage 4->5 gate: staff records a buyer's Order Confirmation
 * acknowledgment that arrived by reply-to-the-email rather than through
 * the client portal button (docs/schema.sql Section AE). Replaces the
 * old staff-only "Confirm Buyer Acknowledged Order" button, which never
 * required any evidence the buyer had actually agreed to anything — a
 * mandatory note (the reply itself, quoted, is normal practice here) is
 * this feature's substitute for that missing evidence.
 */
async function recordOcAcknowledgment(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const ack = await orderOcAcknowledgmentRepository.find(orderId);
  if (!ack || ack.acknowledged_at !== null) {
    flash.set(req, 'error', 'No pending Order Confirmation acknowledgment for this order — send the OC to the buyer first.');
    res.redirect(`/orders/${orderId}`);
    return;
  }

  const note = String(req.body.acknowledged_note || '').trim();
  const reasonError = reasonValidator.check(note);
  if (reasonError) {
    flash.set(req, 'error', `Describe the evidence (e.g. quote the buyer's email reply): ${reasonError}`);
    res.redirect(`/orders/${orderId}`);
    return;
  }

  const user = req.user;
  await orderOcAcknowledgmentRepository.markAcknowledged(orderId, 'staff_recorded_email', note, user.id);
  await stageGateService.passAndUnlockNext(orderId, 4, user.id);
  await auditLogRepository.log(user.id, 'OC_ACKNOWLEDGED_VIA_EMAIL', 'orders', orderId, null, null, null, note);
  flash.set(req, 'success', 'Buyer acknowledgement recorded. Stage 5 (Supplier PO) unlocked.');
  res.redirect(`/orders/${orderId}`);
}

/** docs/schema.sql Section AF — per-order, staff-controlled, default off. */
async function setDisputeButtonVisible(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const visible = !!req.body.dispute_button_visible_to_client;
  await orderRepository.setDisputeButtonVisible(orderId, visible);
  const user = req.user;
  await auditLogRepository.log(user.id, 'DISPUTE_BUTTON_VISIBILITY_CHANGED', 'orders', orderId, 'dispute_button_visible_to_client', null, visible ? '1' : '0');
  flash.set(req, 'success', visible ? 'The client can now raise a dispute on this order from their portal.' : 'The "Raise a Dispute" button is now hidden from the client for this order.');
  res.redirect(`/orders/${orderId}`);
}

/** Stage 5: fill the Supplier PO's material/commercial terms — creates order_supplier_po ahead of generating the SUPPO document. */
async function saveSupplierPo(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const supplierId = parseInt(req.body.supplier_id || 0, 10);
  if (!supplierId) {
    flash.set(req, 'error', 'Select or add a supplier first.');
    res.redirect(`/orders/${orderId}`);
    return;
  }

  const docTypeId = await documentGenerationService.documentTypeIdFor('SUPPO');
  const supplierPoReference = docTypeId ? await referenceNumberService.generateDocumentRef(docTypeId) : null;
  if (!supplierPoReference) {
    flash.set(req, 'error', 'Could not generate a Supplier PO reference — check document_types.ref_format for SUPPO.');
    res.redirect(`/orders/${orderId}`);
    return;
  }

  const body = req.body;
  await orderSupplierPoRepository.create(orderId, supplierId, supplierPoReference, {
    material_stone_type: str(body.material_stone_type) || null,
    grade: str(body.grade) || 'Grade A',
    surface_finish: str(body.surface_finish) || null,
    dimensions: str(body.dimensions) || null,
    dimensional_tolerance: str(body.dimensional_tolerance) || null,
    quantity: str(body.quantity) || null,
    unit: str(body.unit) || null,
    colour_reference: str(body.colour_reference) || null,
    special_requirements: str(body.special_requirements) || null,
    unit_price_inr: str(body.unit_price_inr) || null,
    basic_value_inr: str(body.basic_value_inr) || null,
    gst_rate_pct: str(body.gst_rate_pct) || null,
    gst_amount_inr: str(body.gst_amount_inr) || null,
    total_payable_inr: str(body.total_payable_inr) || null,
    advance_pct: str(body.advance_pct) || null,
    advance_amount_inr: str(body.advance_amount_inr) || null,
    balance_amount_inr: str(body.balance_amount_inr) || null,
    delivery_location: str(body.delivery_location) || null,
    required_delivery_date: str(body.required_delivery_date) || null,
    packing_requirement: str(body.packing_requirement) || null,
  });

  flash.set(req, 'success', `Supplier PO terms saved (${supplierPoReference}). Generate the Supplier PO document below.`);
  res.redirect(`/orders/${orderId}`);
}

async function createSupplier(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const name = str(req.body.supplier_legal_name);
  if (name === '') {
    flash.set(req, 'error', 'Supplier legal name is required.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const supplierId = await supplierRepository.create({
    supplier_legal_name: name,
    address: str(req.body.address) || null,
    gstin: str(req.body.gstin) || null,
    pan: str(req.body.pan) || null,
    contact_person: str(req.body.contact_person) || null,
    phone: str(req.body.phone) || null,
    supplier_type: str(req.body.supplier_type) || null,
  });
  if (await testModeService.isEnabled()) {
    await supplierRepository.markTest(supplierId);
  }
  flash.set(req, 'success', `Supplier "${name}" added.`);
  res.redirect(`/orders/${orderId}`);
}

/** Stage 5->6 gate: supplier signs, stamps and returns the Supplier PO. Auto-skips Stage 6 for FOB orders. */
async function confirmSupplierSigned(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  const supplierPo = await orderSupplierPoRepository.findLatestForOrder(orderId);
  if (!supplierPo) {
    flash.set(req, 'error', 'Save the Supplier PO terms and generate the document before confirming signature.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  await orderSupplierPoRepository.markSigned(supplierPo.id);
  await stageGateService.passAndUnlockNext(orderId, 5, user.id);
  await stageGateService.maybeAutoSkipFreightStage(orderId, user.id);
  flash.set(req, 'success', 'Supplier PO signature confirmed.');
  res.redirect(`/orders/${orderId}`);
}

/**
 * Real gap this closes: confirmSupplierSigned() above only ever flipped
 * order_supplier_po.status on a button click — the supplier's actual
 * signed acknowledgment was never kept on file anywhere. A separate
 * upload endpoint, keyed to the specific order_supplier_po row (not the
 * order) since an order can have more than one Supplier PO version and
 * the acknowledgment belongs to the version it was signed against; a
 * second/corrected upload adds a new file_store row rather than
 * replacing one (schema.sql SECTION S).
 */
async function uploadSupplierPoDocument(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  const supplierPo = await orderSupplierPoRepository.findLatestForOrder(orderId);
  if (!order || !supplierPo) {
    flash.set(req, 'error', 'Save the Supplier PO terms before attaching an acknowledgment copy.');
    res.redirect(`/orders/${orderId}`);
    return;
  }

  try {
    const fileId = await fileUploadService.handleUpload(
      req,
      'document',
      'supplier_po_ack',
      `clients/${sanitizePathSegment(order.client_unique_number)}/${sanitizePathSegment(order.order_reference)}/supplier_po`,
      null,
      orderId,
      req.user.id,
      null,
      'supplier',
      'Supplier PO acknowledgment'
    );
    await orderSupplierPoDocumentRepository.attach(supplierPo.id, fileId);
    flash.set(req, 'success', 'Supplier PO acknowledgment attached.');
  } catch (e) {
    flash.set(req, 'error', e.message);
  }
  res.redirect(`/orders/${orderId}`);
}

/** Stage 6 (CFR/CIF only): record NexaCrest's agreed freight/insurance terms before generating the FDN. */
async function saveFreightTerms(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const body = req.body;
  await orderFreightRepository.upsert(orderId, {
    confirmed_freight_rate: str(body.confirmed_freight_rate) || null,
    insurance_amount: str(body.insurance_amount) || null,
    freight_forwarder_name: str(body.freight_forwarder_name) || null,
    freight_forwarder_contact: str(body.freight_forwarder_contact) || null,
    gst_treatment: str(body.gst_treatment) || null,
  });
  flash.set(req, 'success', 'Freight & insurance terms saved. Generate the Freight Debit Note below.');
  res.redirect(`/orders/${orderId}`);
}

async function recordFreightPayment(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const amount = parseFloat(req.body.freight_amount || 0);
  const receivedAt = str(req.body.freight_received_at) || todayYmd();
  if (!(amount > 0)) {
    flash.set(req, 'error', 'Enter the freight & insurance amount actually received.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  await orderPaymentStatusRepository.recordFreightReceived(orderId, amount, receivedAt);
  flash.set(req, 'success', 'Freight remittance recorded. Mark it cleared once your bank confirms receipt.');
  res.redirect(`/orders/${orderId}`);
}

/** Stage 6->7 gate: freight & insurance payment cleared in NexaCrest's bank account. */
async function clearFreightPayment(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  const clearedAt = str(req.body.freight_cleared_at) || todayYmd();
  await orderPaymentStatusRepository.markFreightCleared(orderId, clearedAt, user.id);
  await stageGateService.passAndUnlockNext(orderId, 6, user.id);
  flash.set(req, 'success', 'Freight payment cleared. Stage 7 (Packing & BL Instruction) unlocked.');
  res.redirect(`/orders/${orderId}`);
}

/** Stage 7: record actual packing figures + crate-level breakdown, ahead of generating the Packing List. */
/**
 * Stage 7: record actual packing figures + crate-level breakdown, ahead
 * of generating the Packing List.
 *
 * Quantity-tolerance hard rule: when the ordered quantity can be
 * unambiguously compared (single unit, not TBC), the shortfall is
 * computed HERE, server-side, never trusted from the client — and a
 * shortfall exceeding company_settings.quantity_shortfall_tolerance_pct
 * blocks the save entirely until the buyer's written approval is
 * uploaded in the same request (order_packing.buyer_approval_file_id — a
 * column the original delivery reserved but never wired to anything).
 * When the order can't be unambiguously compared (mixed units, or an
 * unconfirmed TBC quantity), this falls back to the pre-existing manual
 * shortfall_pct entry rather than blocking a save the system has no
 * sound basis to validate.
 */
async function savePacking(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const body = req.body;
  const actualQtyRaw = str(body.actual_quantity_packed);
  const actualQty = actualQtyRaw !== '' ? parseFloat(actualQtyRaw) : null;

  const summary = await orderProductRepository.orderedQuantitySummary(orderId);
  const tolerance = parseFloat((await companySettingsRepository.get('quantity_shortfall_tolerance_pct')) ?? '5');
  let shortfallPct = null;

  if (summary.comparable && actualQty !== null && summary.total > 0) {
    shortfallPct = Math.max(0, Math.round((((summary.total - actualQty) / summary.total) * 100) * 100) / 100);
  }

  const existingPacking = await orderPackingRepository.find(orderId);
  const hasExistingApproval = existingPacking && existingPacking.buyer_approval_file_id;
  const uploadingApprovalNow = !!(req.file && req.file.buffer);

  if (shortfallPct !== null && shortfallPct > tolerance && !hasExistingApproval && !uploadingApprovalNow) {
    const totalDisplay = String(summary.total).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
    flash.set(req, 'error', `Actual quantity packed (${actualQtyRaw} ${summary.unit}) is ${shortfallPct.toFixed(2)}% short of the ordered quantity (${totalDisplay} ${summary.unit}) — this exceeds the ${tolerance.toFixed(2)}% tolerance in Company Settings. Nothing was saved. Upload the buyer's written approval of this shortfall below to proceed.`);
    res.redirect(`/orders/${orderId}`);
    return;
  }

  await orderPackingRepository.upsert(orderId, {
    actual_quantity_packed: actualQtyRaw || null,
    crate_count: str(body.crate_count) || null,
    total_net_weight_kg: str(body.total_net_weight_kg) || null,
    total_gross_weight_kg: str(body.total_gross_weight_kg) || null,
    total_cbm: str(body.total_cbm) || null,
    packing_date: str(body.packing_date) || null,
    // Auto-computed whenever comparable; otherwise the pre-existing
    // manual field is the only source of truth we have.
    shortfall_pct: shortfallPct !== null ? String(shortfallPct) : (str(body.shortfall_pct) || null),
  });

  if (uploadingApprovalNow) {
    const order = await orderRepository.find(orderId);
    try {
      const fileId = await fileUploadService.handleUpload(
        req,
        'buyer_approval',
        'buyer_approval',
        `clients/${sanitizePathSegment(order.client_unique_number)}/${sanitizePathSegment(order.order_reference)}/packing`,
        null,
        orderId,
        req.user.id,
        null,
        'Buyer',
        'Quantity shortfall approval'
      );
      await orderPackingRepository.attachBuyerApproval(orderId, fileId);
    } catch (e) {
      flash.set(req, 'error', `Packing figures saved, but the approval upload failed: ${e.message}`);
      res.redirect(`/orders/${orderId}`);
      return;
    }
  }

  const crateNos = [].concat(body.crate_no || []);
  const marks = [].concat(body.crate_marks_numbers || []);
  const descs = [].concat(body.crate_product_description || []);
  const dims = [].concat(body.crate_dimensions || []);
  const pcs = [].concat(body.crate_pcs || []);
  const netW = [].concat(body.crate_net_weight_kg || []);
  const grossW = [].concat(body.crate_gross_weight_kg || []);
  const cbms = [].concat(body.crate_cbm || []);
  const hsCodes = [].concat(body.crate_hs_code || []);

  const crates = [];
  for (let i = 0; i < crateNos.length; i++) {
    const crateNo = str(crateNos[i]);
    if (crateNo === '') continue;
    crates.push({
      crate_no: crateNo,
      marks_numbers: str(marks[i]) || null,
      product_description: str(descs[i]) || null,
      dimensions_lwh_cm: str(dims[i]) || null,
      pcs: str(pcs[i]) || null,
      net_weight_kg: str(netW[i]) || null,
      gross_weight_kg: str(grossW[i]) || null,
      cbm: str(cbms[i]) || null,
      hs_code: str(hsCodes[i]) || null,
    });
  }
  await orderCrateRepository.replaceForOrder(orderId, crates);

  flash.set(req, 'success', 'Packing figures and crate breakdown saved. Generate the Packing List below.');
  res.redirect(`/orders/${orderId}`);
}

/** Stage 7: record shipping/vessel details, ahead of generating the BL Instruction Sheet. */
async function saveShipping(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const body = req.body;
  await orderShippingRepository.upsert(orderId, {
    shipping_line: str(body.shipping_line) || null,
    vessel_name: str(body.vessel_name) || null,
    voyage_number: str(body.voyage_number) || null,
    etd: str(body.etd) || null,
    eta: str(body.eta) || null,
    container_type: str(body.ship_container_type) || null,
    container_no: str(body.container_no) || null,
    seal_no: str(body.seal_no) || null,
  });
  flash.set(req, 'success', 'Shipping details saved. Generate the BL Instruction Sheet below.');
  res.redirect(`/orders/${orderId}`);
}

/** Stage 7->8 gate: BL issued (vessel departs) — also the date CI must match. */
async function recordBlIssued(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  const blNumber = str(req.body.bl_number);
  const blDate = str(req.body.bl_date) || todayYmd();
  if (blNumber === '') {
    flash.set(req, 'error', 'BL number is required to confirm this gate.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  await orderShippingRepository.recordBl(orderId, blNumber, blDate);
  await stageGateService.passAndUnlockNext(orderId, 7, user.id);
  flash.set(req, 'success', `Bill of Lading recorded (${blNumber}). Stage 8 (Commercial Invoice) unlocked.`);
  res.redirect(`/orders/${orderId}`);
}

async function recordScannedBlSent(req, res) {
  const orderId = parseInt(req.params.id, 10);
  await orderShippingRepository.recordScannedBlSent(orderId);
  flash.set(req, 'success', 'Scanned BL copy marked as sent to buyer.');
  res.redirect(`/orders/${orderId}`);
}

async function recordBalancePayment(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const amount = parseFloat(req.body.balance_amount || 0);
  const receivedAt = str(req.body.balance_received_at) || todayYmd();
  if (!(amount > 0)) {
    flash.set(req, 'error', 'Enter the balance amount actually received.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  await orderPaymentStatusRepository.recordBalanceReceived(orderId, amount, receivedAt);
  flash.set(req, 'success', 'Balance remittance recorded. Mark it cleared once your bank confirms receipt.');
  res.redirect(`/orders/${orderId}`);
}

/** Stage 8->9 gate: balance payment cleared — triggers COO prep / document despatch / closure. */
async function clearBalancePayment(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  const clearedAt = str(req.body.balance_cleared_at) || todayYmd();
  await orderPaymentStatusRepository.markBalanceCleared(orderId, clearedAt, user.id);
  await stageGateService.passAndUnlockNext(orderId, 8, user.id);
  flash.set(req, 'success', 'Balance payment cleared. Stage 9 (Document Despatch & Closure) unlocked.');
  res.redirect(`/orders/${orderId}`);
}

// ----------------------------------------------------------------
// CA / Accounting module (Phase 1) — INR actual settlement amounts.
// Routes are gated on inr_actual_edit/inr_actual_delete (see server.js);
// every write is audit-logged since this is exactly the kind of field an
// auditor/CA will want a trail on.
// ----------------------------------------------------------------

async function recordAdvanceInrActual(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  const amount = parseFloat(req.body.advance_inr_actual || 0);
  if (!(amount > 0)) {
    flash.set(req, 'error', 'Enter the actual INR amount credited to the bank.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  if (!(await caFyLockGuard.allow(req, ((await orderPaymentStatusRepository.find(orderId)) || {}).advance_cleared_at ?? null, 'order_payment_status', orderId, 'advance_inr_actual'))) {
    res.redirect(`/orders/${orderId}`);
    return;
  }
  await orderPaymentStatusRepository.setAdvanceInrActual(orderId, amount, user.id);
  await auditLogRepository.log(user.id, 'CA_INR_ACTUAL_RECORDED', 'order_payment_status', orderId, 'advance_inr_actual', null, String(amount));
  flash.set(req, 'success', 'Advance INR actual amount recorded.');
  res.redirect(`/orders/${orderId}`);
}

async function deleteAdvanceInrActual(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  if (!(await caFyLockGuard.allow(req, ((await orderPaymentStatusRepository.find(orderId)) || {}).advance_cleared_at ?? null, 'order_payment_status', orderId, 'advance_inr_actual'))) {
    res.redirect(`/orders/${orderId}`);
    return;
  }
  await orderPaymentStatusRepository.clearAdvanceInrActual(orderId);
  await auditLogRepository.log(user.id, 'CA_INR_ACTUAL_DELETED', 'order_payment_status', orderId, 'advance_inr_actual');
  flash.set(req, 'success', 'Advance INR actual amount removed.');
  res.redirect(`/orders/${orderId}`);
}

async function recordBalanceInrActual(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  const amount = parseFloat(req.body.balance_inr_actual || 0);
  if (!(amount > 0)) {
    flash.set(req, 'error', 'Enter the actual INR amount credited to the bank.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  if (!(await caFyLockGuard.allow(req, ((await orderPaymentStatusRepository.find(orderId)) || {}).balance_cleared_at ?? null, 'order_payment_status', orderId, 'balance_inr_actual'))) {
    res.redirect(`/orders/${orderId}`);
    return;
  }
  await orderPaymentStatusRepository.setBalanceInrActual(orderId, amount, user.id);
  await auditLogRepository.log(user.id, 'CA_INR_ACTUAL_RECORDED', 'order_payment_status', orderId, 'balance_inr_actual', null, String(amount));
  flash.set(req, 'success', 'Balance INR actual amount recorded.');
  res.redirect(`/orders/${orderId}`);
}

async function deleteBalanceInrActual(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  if (!(await caFyLockGuard.allow(req, ((await orderPaymentStatusRepository.find(orderId)) || {}).balance_cleared_at ?? null, 'order_payment_status', orderId, 'balance_inr_actual'))) {
    res.redirect(`/orders/${orderId}`);
    return;
  }
  await orderPaymentStatusRepository.clearBalanceInrActual(orderId);
  await auditLogRepository.log(user.id, 'CA_INR_ACTUAL_DELETED', 'order_payment_status', orderId, 'balance_inr_actual');
  flash.set(req, 'success', 'Balance INR actual amount removed.');
  res.redirect(`/orders/${orderId}`);
}

async function recordFreightInrActual(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  const amount = parseFloat(req.body.freight_inr_actual || 0);
  if (!(amount > 0)) {
    flash.set(req, 'error', 'Enter the actual INR amount credited to the bank.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  if (!(await caFyLockGuard.allow(req, ((await orderPaymentStatusRepository.find(orderId)) || {}).freight_cleared_at ?? null, 'order_payment_status', orderId, 'freight_inr_actual'))) {
    res.redirect(`/orders/${orderId}`);
    return;
  }
  await orderPaymentStatusRepository.setFreightInrActual(orderId, amount, user.id);
  await auditLogRepository.log(user.id, 'CA_INR_ACTUAL_RECORDED', 'order_payment_status', orderId, 'freight_inr_actual', null, String(amount));
  flash.set(req, 'success', 'Freight INR actual amount recorded.');
  res.redirect(`/orders/${orderId}`);
}

async function deleteFreightInrActual(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  if (!(await caFyLockGuard.allow(req, ((await orderPaymentStatusRepository.find(orderId)) || {}).freight_cleared_at ?? null, 'order_payment_status', orderId, 'freight_inr_actual'))) {
    res.redirect(`/orders/${orderId}`);
    return;
  }
  await orderPaymentStatusRepository.clearFreightInrActual(orderId);
  await auditLogRepository.log(user.id, 'CA_INR_ACTUAL_DELETED', 'order_payment_status', orderId, 'freight_inr_actual');
  flash.set(req, 'success', 'Freight INR actual amount removed.');
  res.redirect(`/orders/${orderId}`);
}

// ----------------------------------------------------------------
// CA / Accounting module (Phase 2) — assumed exchange rate (one per
// order, for the register's forex gain/loss column) and per-leg
// FIRC/eBRC references. Same inr_actual_edit gating as Phase 1.
// ----------------------------------------------------------------

async function recordAssumedExchangeRate(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  const rate = parseFloat(req.body.assumed_exchange_rate || 0);
  if (!(rate > 0)) {
    flash.set(req, 'error', 'Enter the assumed INR exchange rate for this order.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  // Not tied to one leg — changing it would change the forex gain/loss
  // shown for every cleared leg on the order, so it's blocked if ANY of
  // them falls in a locked FY, not just one.
  const ops = (await orderPaymentStatusRepository.find(orderId)) || {};
  for (const col of ['advance_cleared_at', 'balance_cleared_at', 'freight_cleared_at']) {
    if (!(await caFyLockGuard.allow(req, ops[col] ?? null, 'order_payment_status', orderId, 'assumed_exchange_rate'))) {
      res.redirect(`/orders/${orderId}`);
      return;
    }
  }
  await orderPaymentStatusRepository.setAssumedExchangeRate(orderId, rate, user.id);
  await auditLogRepository.log(user.id, 'CA_EXCHANGE_RATE_RECORDED', 'order_payment_status', orderId, 'assumed_exchange_rate', null, String(rate));
  flash.set(req, 'success', 'Assumed exchange rate recorded.');
  res.redirect(`/orders/${orderId}`);
}

async function recordAdvanceFirc(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  const reference = str(req.body.advance_firc_reference);
  if (!reference) {
    flash.set(req, 'error', 'Enter the FIRC/eBRC reference.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  if (!(await caFyLockGuard.allow(req, ((await orderPaymentStatusRepository.find(orderId)) || {}).advance_cleared_at ?? null, 'order_payment_status', orderId, 'advance_firc_reference'))) {
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const receivedAt = str(req.body.advance_firc_received_at) || todayYmd();
  await orderPaymentStatusRepository.setAdvanceFirc(orderId, reference, receivedAt);
  await auditLogRepository.log(user.id, 'CA_FIRC_RECORDED', 'order_payment_status', orderId, 'advance_firc_reference', null, reference);
  flash.set(req, 'success', 'Advance FIRC/eBRC reference recorded.');
  res.redirect(`/orders/${orderId}`);
}

async function recordBalanceFirc(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  const reference = str(req.body.balance_firc_reference);
  if (!reference) {
    flash.set(req, 'error', 'Enter the FIRC/eBRC reference.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  if (!(await caFyLockGuard.allow(req, ((await orderPaymentStatusRepository.find(orderId)) || {}).balance_cleared_at ?? null, 'order_payment_status', orderId, 'balance_firc_reference'))) {
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const receivedAt = str(req.body.balance_firc_received_at) || todayYmd();
  await orderPaymentStatusRepository.setBalanceFirc(orderId, reference, receivedAt);
  await auditLogRepository.log(user.id, 'CA_FIRC_RECORDED', 'order_payment_status', orderId, 'balance_firc_reference', null, reference);
  flash.set(req, 'success', 'Balance FIRC/eBRC reference recorded.');
  res.redirect(`/orders/${orderId}`);
}

async function recordFreightFirc(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  const reference = str(req.body.freight_firc_reference);
  if (!reference) {
    flash.set(req, 'error', 'Enter the FIRC/eBRC reference.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  if (!(await caFyLockGuard.allow(req, ((await orderPaymentStatusRepository.find(orderId)) || {}).freight_cleared_at ?? null, 'order_payment_status', orderId, 'freight_firc_reference'))) {
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const receivedAt = str(req.body.freight_firc_received_at) || todayYmd();
  await orderPaymentStatusRepository.setFreightFirc(orderId, reference, receivedAt);
  await auditLogRepository.log(user.id, 'CA_FIRC_RECORDED', 'order_payment_status', orderId, 'freight_firc_reference', null, reference);
  flash.set(req, 'success', 'Freight FIRC/eBRC reference recorded.');
  res.redirect(`/orders/${orderId}`);
}

async function recordBlOriginalsReceived(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const count = parseInt(req.body.bl_originals_count || 3, 10);
  await orderShippingRepository.recordBlOriginalsReceived(orderId, count || 3);
  flash.set(req, 'success', 'Original BL copies recorded as received from CHA.');
  res.redirect(`/orders/${orderId}`);
}

async function recordBlEndorsed(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  await orderShippingRepository.recordBlEndorsed(orderId, user.id);
  flash.set(req, 'success', 'Original BLs marked as endorsed by NexaCrest.');
  res.redirect(`/orders/${orderId}`);
}

/** Stage 9 gate: COO received, BL originals endorsed, complete document set couriered to buyer -> order closed. */
async function closeOrder(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  const trackingNumber = str(req.body.courier_tracking_number);
  if (trackingNumber === '') {
    flash.set(req, 'error', 'Courier tracking number is required to close the order.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  await orderShippingRepository.recordCourierSent(orderId, trackingNumber);
  await stageGateService.passAndUnlockNext(orderId, 9, user.id);
  await orderRepository.markComplete(orderId);
  flash.set(req, 'success', `Order closed — complete document set couriered to buyer (tracking: ${trackingNumber}).`);
  res.redirect(`/orders/${orderId}`);
}

/**
 * Spec Section 13 — "Stage/lock/order status flags" is explicitly named as
 * an Admin-editable field, distinct from the normal stage-gate progression
 * (stageGateService) which is the non-override path every order takes.
 * This is the escape hatch for a genuinely wrong status/lock flag — reason
 * mandatory, logged with old/new value.
 */
async function overrideStatusLock(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }

  const user = req.user;
  const newStatus = String(req.body.status || '');
  const newIsLocked = req.body.is_locked !== undefined;
  const reason = str(req.body.reason);

  if (!['active', 'complete', 'disputed', 'lost'].includes(newStatus)) {
    flash.set(req, 'error', 'Invalid status value.');
    res.redirect(`/orders/${orderId}`);
    return;
  }

  const oldIsLocked = !!order.is_locked;
  if (newStatus === order.status && newIsLocked === oldIsLocked) {
    flash.set(req, 'success', 'No change was made.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const reasonError = reasonValidator.check(reason);
  if (reasonError) {
    flash.set(req, 'error', reasonError);
    res.redirect(`/orders/${orderId}`);
    return;
  }

  await adminOverrideRepository.updateOrderStatusLock(orderId, newStatus, newIsLocked);
  if (newStatus !== order.status) {
    await auditLogRepository.log(user.id, 'FIELD_EDIT', 'orders', orderId, 'status', order.status, newStatus, reason);
  }
  if (newIsLocked !== oldIsLocked) {
    await auditLogRepository.log(user.id, 'LOCK_OVERRIDE', 'orders', orderId, 'is_locked', oldIsLocked ? '1' : '0', newIsLocked ? '1' : '0', reason);
  }
  flash.set(req, 'success', 'Order status/lock overridden.');
  res.redirect(`/orders/${orderId}`);
}

/**
 * Added 2026-09-19 — reporting had no way to distinguish "still in play"
 * from "buyer walked away" (spec items #7/#10, "quotation/PI lost").
 * Reason is mandatory and audit-logged, same pattern as every other
 * override action in this file; the order locks the same way closeOrder()
 * locks a completed one. Reversing a wrong "lost" call goes through the
 * existing overrideStatusLock() escape hatch above (now that 'lost' is in
 * its allowed-status whitelist) rather than a second bespoke action.
 */
async function markLost(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  if (order.status !== 'active') {
    flash.set(req, 'error', `Only an active order can be marked lost (this one is already "${order.status}").`);
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const reason = str(req.body.reason);
  const reasonError = reasonValidator.check(reason);
  if (reasonError) {
    flash.set(req, 'error', reasonError);
    res.redirect(`/orders/${orderId}`);
    return;
  }

  await orderRepository.markLost(orderId, reason, user.id);
  await auditLogRepository.log(user.id, 'ORDER_MARKED_LOST', 'orders', orderId, 'status', order.status, 'lost', reason);
  flash.set(req, 'success', 'Order marked as lost.');
  res.redirect(`/orders/${orderId}`);
}

module.exports = {
  index, archivedIndex, archive, unarchive, duplicateOrder, create, store, show, downloadDossier, generatePiFormLink,
  editDetails, updateDetails, addProduct, updateProduct, deleteProduct, duplicateProduct,
  recordBuyerPo, uploadBuyerPoDocument, recordAdvancePayment, markPaymentReportReviewed, clearAdvancePayment, updateProductionStatus,
  recordOcAcknowledgment, setDisputeButtonVisible, saveSupplierPo, createSupplier, confirmSupplierSigned, uploadSupplierPoDocument,
  saveFreightTerms, recordFreightPayment, clearFreightPayment,
  savePacking, saveShipping, recordBlIssued, recordScannedBlSent,
  recordBalancePayment, clearBalancePayment, recordBlOriginalsReceived, recordBlEndorsed,
  closeOrder, overrideStatusLock, markLost,
  recordAdvanceInrActual, deleteAdvanceInrActual, recordBalanceInrActual, deleteBalanceInrActual,
  recordFreightInrActual, deleteFreightInrActual,
  recordAssumedExchangeRate, recordAdvanceFirc, recordBalanceFirc, recordFreightFirc,
};
