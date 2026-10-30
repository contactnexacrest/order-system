'use strict';

const fs = require('fs');
const recordCheck = require('../helpers/recordCheck');
const orderStorage = require('../helpers/orderStorage');
const JSZip = require('jszip');
const db = require('../config/db');
const { parseDbDateTime } = require('../helpers/dbDateTime');
const flash = require('../helpers/flash');
const fieldValidator = require('../helpers/fieldValidator');
const orderLineValidator = require('../helpers/orderLineValidator');
const reasonValidator = require('../helpers/reasonValidator');
const adminOverrideRepository = require('../repositories/adminOverrideRepository');
const amendmentRepository = require('../repositories/amendmentRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const caFyLockRepository = require('../repositories/caFyLockRepository');
const caExpenseRepository = require('../repositories/caExpenseRepository');
const caExportBenefitRepository = require('../repositories/caExportBenefitRepository');
const caFyLockGuard = require('../services/caFyLockGuard');
const orderAssigneeRepository = require('../repositories/orderAssigneeRepository');
const orderAccessService = require('../services/orderAccessService');
const paymentTermsApprovalService = require('../services/paymentTermsApprovalService');
const orderAssignmentService = require('../services/orderAssignmentService');
const orderDuplicationService = require('../services/orderDuplicationService');
const orderEditGuard = require('../services/orderEditGuard');
const encryptionService = require('../services/encryptionService');
const clientPaymentReportRepository = require('../repositories/clientPaymentReportRepository');
const orderCancellationRepository = require('../repositories/orderCancellationRepository');
const makerCheckerGuard = require('../services/makerCheckerGuard');
const orderCommentRepository = require('../repositories/orderCommentRepository');
const orderComplianceTaskRepository = require('../repositories/orderComplianceTaskRepository');
const orderExportBenefitSchemeRepository = require('../repositories/orderExportBenefitSchemeRepository');
const orderInspectionFeeRepository = require('../repositories/orderInspectionFeeRepository');
const clientIntakeRepository = require('../repositories/clientIntakeRepository');
const clientRepository = require('../repositories/clientRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const hsCodeRepository = require('../repositories/hsCodeRepository');
const orderOcAcknowledgmentRepository = require('../repositories/orderOcAcknowledgmentRepository');
const disputeRepository = require('../repositories/disputeRepository');
const orderComplaintRepository = require('../repositories/orderComplaintRepository');
const documentCrossVerificationRepository = require('../repositories/documentCrossVerificationRepository');
const documentRepository = require('../repositories/documentRepository');
const documentReviewRepository = require('../repositories/documentReviewRepository');
const emailLogRepository = require('../repositories/emailLogRepository');
const lookupRepository = require('../repositories/lookupRepository');
const uiTemplateService = require('../services/uiTemplateService');
const orderCostEntryRepository = require('../repositories/orderCostEntryRepository');
const orderLcDetailRepository = require('../repositories/orderLcDetailRepository');
const orderTravelExpenseRepository = require('../repositories/orderTravelExpenseRepository');
const orderFeedbackRepository = require('../repositories/orderFeedbackRepository');
const testimonialSuggestionService = require('../services/testimonialSuggestionService');
const orderLedgerService = require('../services/orderLedgerService');
const orderCrateRepository = require('../repositories/orderCrateRepository');
const orderFreightRepository = require('../repositories/orderFreightRepository');
const orderPackingRepository = require('../repositories/orderPackingRepository');
const notificationRepository = require('../repositories/notificationRepository');
const permissionRepository = require('../repositories/permissionRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const orderProductionRepository = require('../repositories/orderProductionRepository');
const orderProductRepository = require('../repositories/orderProductRepository');
const orderRepository = require('../repositories/orderRepository');
const orderCommentService = require('../services/orderCommentService');
const orderBlEndorsementRepository = require('../repositories/orderBlEndorsementRepository');
const orderShippingRepository = require('../repositories/orderShippingRepository');
const orderDraftBlReviewRepository = require('../repositories/orderDraftBlReviewRepository');
const orderStageRepository = require('../repositories/orderStageRepository');
const orderSupplierPoRepository = require('../repositories/orderSupplierPoRepository');
const orderBuyerPoDocumentRepository = require('../repositories/orderBuyerPoDocumentRepository');
const fileStoreRepository = require('../repositories/fileStoreRepository');
const orderSupplierPoDocumentRepository = require('../repositories/orderSupplierPoDocumentRepository');
const piIntakeRepository = require('../repositories/piIntakeRepository');
const supplierRepository = require('../repositories/supplierRepository');
const logisticsPartnerRepository = require('../repositories/logisticsPartnerRepository');
const vendorDetailsRepository = require('../repositories/vendorDetailsRepository');
const orderVendorBillRepository = require('../repositories/orderVendorBillRepository');
const orderExpenseRepository = require('../repositories/orderExpenseRepository');
const supplierFactoryAddressRepository = require('../repositories/supplierFactoryAddressRepository');
const benefitSettlementService = require('../services/benefitSettlementService');
const userRepository = require('../repositories/userRepository');
const env = require('../config/env');
const emailService = require('../services/emailService');
const emailTemplateRepository = require('../repositories/emailTemplateRepository');
const referenceNumberService = require('../services/referenceNumberService');
const stageGateService = require('../services/stageGateService');
const documentGenerationService = require('../services/documentGenerationService');
const orderProfitabilityService = require('../services/orderProfitabilityService');
const clientPortalService = require('../services/clientPortalService');
const fileUploadService = require('../services/fileUploadService');
const testModeService = require('../services/testModeService');
const wetSignatureGuardService = require('../services/wetSignatureGuardService');
const { todayIst } = require('../helpers/dbDateTime');
const dates = require('../helpers/dates');

// Port of App\Controllers\OrderController.

function todayYmd() {
  return todayIst();
}

function addDaysYmd(fromYmd, days) {
  const d = fromYmd ? new Date(`${fromYmd}T00:00:00Z`) : new Date();
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
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
  const seesAllOrders = !!req.permissions.view_all_orders;
  if ((await uiTemplateService.resolve(req.user.ui_template_preference_id || null)) === 'kanban') {
    await indexKanban(req, res, seesAllOrders);
    return;
  }

  const all = await orderRepository.allVisibleTo(req.user.id, seesAllOrders);
  // Default to the Active tab, not All — most orders in the system at
  // any time are still in play, and staff opening this screen want to
  // see what's actually moving, not a completed/lost order buried in the
  // same list. "All" is now its own explicit choice (?status=all),
  // distinct from no query param at all, so a deliberate click on "All"
  // is remembered for that pageview exactly like every other tab.
  const statusFilter = String(req.query.status || '').trim() || 'active';

  const counts = { all: all.length, active: 0, overdue: 0, shipped_docs_sent: 0, complete: 0, lost: 0, cancelled: 0 };
  for (const o of all) {
    if (o.is_overdue) counts.overdue++;
    if (Object.prototype.hasOwnProperty.call(counts, o.status)) counts[o.status]++;
  }

  let filtered = all;
  if (statusFilter === 'overdue') {
    filtered = all.filter((o) => !!o.is_overdue);
  } else if (['active', 'shipped_docs_sent', 'complete', 'lost', 'cancelled'].includes(statusFilter)) {
    filtered = all.filter((o) => o.status === statusFilter);
  } else if (statusFilter !== 'all') {
    filtered = all.filter((o) => o.status === 'active');
  }

  // External QA report U-02: with ~200 orders this page was 17,600px tall
  // on desktop (52,000px on a phone) — one unpaginated card-grid of every
  // matching order at once, only getting worse as data grows. Paginated
  // in-JS (not pushed down into the SQL itself) since orderRepository.all()
  // already has to compute every status's own total count for the filter
  // chips above regardless of which page is showing — the one query it
  // already runs stays the single source of truth for both.
  const ORDERS_PER_PAGE = 25;
  const totalFiltered = filtered.length;
  const totalPages = Math.max(1, Math.ceil(totalFiltered / ORDERS_PER_PAGE));
  const page = Math.max(1, Math.min(totalPages, parseInt(req.query.page, 10) || 1));
  const pageSlice = filtered.slice((page - 1) * ORDERS_PER_PAGE, page * ORDERS_PER_PAGE);

  // Precomputed here (not in the template) since Nunjucks has no min/max
  // filter — how many of the 9 mini-stage-track segments render filled.
  const stageTotal = 9;
  const orders = pageSlice.map((o) => ({
    ...o,
    filled_segments: (o.status === 'complete' || o.status === 'shipped_docs_sent') ? stageTotal : ((o.status === 'lost' || o.status === 'cancelled') ? Math.max(o.current_stage_number || 0, 1) : (o.current_stage_number || 0)),
  }));

  res.renderView('orders/index', {
    orders,
    statusFilter,
    counts,
    page,
    totalPages,
    totalFiltered,
    seesAllOrders,
  }, 'layout/base');
}

/**
 * docs/schema.sql Section BQ — the Kanban UI template's Orders list:
 * every active order as a card in its own Stage 1-9 column (the
 * pipeline staff actually work through), plus a "Docs Sent" and a
 * "Complete" column for the two closure phases. Lost/Cancelled orders
 * aren't given full board columns of their own — they're dead ends,
 * not pipeline stages — so they're rolled up into a compact summary
 * instead, same as every other real Kanban board treats a "won/lost"
 * outcome. Deliberately unpaginated and unfiltered by status: the
 * whole point of a board is seeing the entire active pipeline at a
 * glance, which the existing card-grid list already offers filtered/
 * paginated for anyone who wants that instead.
 */
async function indexKanban(req, res, seesAllOrders) {
  const all = await orderRepository.allVisibleTo(req.user.id, seesAllOrders);
  const stages = await lookupRepository.stagesMaster();

  const columns = {};
  for (const s of stages) {
    columns[s.stage_number] = { name: s.stage_name, orders: [] };
  }
  const shippedDocsSent = [];
  const complete = [];
  let lostCount = 0;
  let cancelledCount = 0;

  for (const o of all) {
    if (o.status === 'active') {
      const stageNum = (o.current_stage_number || 1) || 1;
      if (columns[stageNum]) {
        columns[stageNum].orders.push(o);
      }
    } else if (o.status === 'shipped_docs_sent') {
      shippedDocsSent.push(o);
    } else if (o.status === 'complete') {
      complete.push(o);
    } else if (o.status === 'lost') {
      lostCount++;
    } else if (o.status === 'cancelled') {
      cancelledCount++;
    }
  }

  res.renderView('orders/index_kanban', {
    columns,
    shippedDocsSent,
    complete,
    lostCount,
    cancelledCount,
    totalActive: all.filter((o) => o.status === 'active').length,
    seesAllOrders,
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
  const preselectedClientId = req.query.client_id ? (parseInt(req.query.client_id, 10) || null) : null;
  const incoterms = await lookupRepository.incoterms();

  // Batch 3 #4 — if we're showing this page again after a validation failure on submit,
  // re-populate the whole form (including product rows) instead of making the user
  // retype everything.
  const old = flash.pullOld(req);

  // 90-item backlog #41/#46: the first time staff open this form for a
  // client just converted from a Quotation-intake submission (no
  // validation-retry `old` data yet), prefill the commercial terms the
  // buyer actually asked for — otherwise Incoterm/container type
  // silently default away from what was requested.
  //
  // 90-item backlog "feedback-loop fix": the guard above only checked
  // "no validation-retry data yet", so this kept firing on EVERY later
  // New Order form for the same client too — a second, third, Nth order
  // all silently re-prefilled from that client's very first intake,
  // feeding the same stale commercial terms back in indefinitely even
  // once the buyer's actual preference had moved on. Added
  // clientHasNoOrdersYet so this only ever applies to the client's
  // genuinely first order, immediately following conversion — once they
  // have any order on record, staff type this order's own terms instead
  // of inheriting an aging intake.
  const clientHasNoOrdersYet = preselectedClientId
    ? (await orderRepository.forClient(preselectedClientId)).length === 0
    : false;
  if (Object.keys(old).length === 0 && preselectedClientId && clientHasNoOrdersYet) {
    const intake = await clientIntakeRepository.latestConvertedForClient(preselectedClientId);
    if (intake) {
      if (intake.incoterm_preference) {
        const match = incoterms.find(
          (i) => String(i.code).trim().toLowerCase() === String(intake.incoterm_preference).trim().toLowerCase()
        );
        if (match) old.incoterm_id = match.id;
      }
      if (intake.container_type_text) old.container_type = intake.container_type_text;
      if (intake.port_of_discharge_text) old.port_of_discharge_text = intake.port_of_discharge_text;
    }
  }

  // 90-item backlog "default weight/volume values": Total CBM/Gross
  // Weight/Net Weight used to start every New Order form blank, same as a
  // brand-new Package Type/Package Count did before those gained a
  // configurable default — staff typed the same typical figure on nearly
  // every order anyway. Only fills a gap; never overwrites a value
  // already in `old` (a validation retry, or the intake-prefill block
  // above, both win as-is).
  // Owner report (2026-10-26): start from the COO type the client gave us
  // (Quotation/PI details or their client record).
  if (preselectedClientId && old.coo_type === undefined) {
    const clientCoo = String(((await clientRepository.find(preselectedClientId)) || {}).coo_type || '');
    if (clientCoo !== '' && clientCoo !== 'To Be Confirmed') old.coo_type = clientCoo;
  }
  if (old.estimated_total_cbm === undefined) old.estimated_total_cbm = await companySettingsRepository.get('default_estimated_total_cbm');
  if (old.estimated_gross_weight_kg === undefined) old.estimated_gross_weight_kg = await companySettingsRepository.get('default_estimated_gross_weight_kg');
  if (old.estimated_net_weight_kg === undefined) old.estimated_net_weight_kg = await companySettingsRepository.get('default_estimated_net_weight_kg');

  res.renderView(
    'orders/create',
    {
      clients: await clientRepository.all(),
      incoterms,
      currencies: await lookupRepository.currencies(),
      loadingPorts: await lookupRepository.ports('loading'),
      dischargePorts: await lookupRepository.ports('discharge'),
      paymentPresets: await lookupRepository.paymentPresets(),
      cooTypes: await lookupRepository.dropdownOptions('coo_type'),
      containerTypes: await lookupRepository.dropdownOptions('container_type'),
      units: await lookupRepository.dropdownOptions('unit'),
      packageTypes: await lookupRepository.dropdownOptions('package_type'),
      surfaceFinishes: await lookupRepository.dropdownOptions('surface_finish'),
      hsCodes: await hsCodeRepository.active(),
      preselectedClientId,
      old,
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
    flash.setOld(req, body);
    res.redirect('/orders/create');
    return;
  }
  // QA-5 ORD-04: the create-order dropdown only ever lists active clients
  // (clientRepository.all()), but find() here has no is_active filter — a
  // direct/forged POST, or a client deactivated in the moment between page
  // load and submit, otherwise sailed straight through to a new order for
  // a client the business has already cut off.
  if (!client.is_active) {
    flash.set(req, 'error', 'This client is not active — reactivate them before creating a new order.');
    flash.setOld(req, body);
    res.redirect('/orders/create');
    return;
  }

  const incotermId = parseInt(body.incoterm_id || 0, 10);
  const currencyId = parseInt(body.currency_id || 0, 10);
  const paymentPresetId = parseInt(body.payment_preset_id || 0, 10);
  if (!incotermId || !currencyId || !paymentPresetId) {
    flash.set(req, 'error', 'Incoterm, currency, and a payment preset are all required.');
    flash.setOld(req, body);
    res.redirect(`/orders/create?client_id=${clientId}`);
    return;
  }
  // QA Round 5, R5-29: a wrong number is refused with a message, never a database error.
  const numberError = fieldValidator.orderNumbers(body);
  if (numberError) {
    flash.set(req, 'error', numberError);
    flash.setOld(req, body);
    res.redirect(`/orders/create?client_id=${clientId}`);
    return;
  }

  const descriptions = [].concat(body.product_description || []);
  const hasAtLeastOneProduct = descriptions.some((d) => str(d) !== '');
  if (!hasAtLeastOneProduct) {
    flash.set(req, 'error', 'At least one product line (with a description) is required.');
    flash.setOld(req, body);
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
        flash.setOld(req, body);
        res.redirect(`/orders/create?client_id=${clientId}`);
        return;
      }
    }
  }

  // QA-5 ORD-05/ORD-06: quantity/unit price used to be passed straight
  // through as raw strings all the way to a DECIMAL column bind — a
  // non-numeric value crashed with an unhandled
  // ER_TRUNCATED_WRONG_VALUE_FOR_FIELD deep in orderProductRepository.add(),
  // by which point the order/stage/payment-status rows were already
  // committed, and a negative quantity was never rejected at all. Checked
  // here, same "before anything is written" placement as the HS-code check
  // above.
  {
    const quantitiesInput = [].concat(body.product_quantity || []);
    const unitPricesInput = [].concat(body.product_unit_price || []);
    const quantityTbcInput = body.product_quantity_tbc || {};
    for (let i = 0; i < descriptions.length; i++) {
      if (str(descriptions[i]) === '') continue;
      const quantityError = orderLineValidator.checkLine(quantitiesInput[i], !!quantityTbcInput[i], unitPricesInput[i]);
      if (quantityError) {
        flash.set(req, 'error', `Product line ${i + 1}: ${quantityError}`);
        flash.setOld(req, body);
        res.redirect(`/orders/create?client_id=${clientId}`);
        return;
      }
      const priceError = orderLineValidator.checkUnitPrice(unitPricesInput[i]);
      if (priceError) {
        flash.set(req, 'error', `Product line ${i + 1}: ${priceError}`);
        flash.setOld(req, body);
        res.redirect(`/orders/create?client_id=${clientId}`);
        return;
      }
    }
  }

  const portOfDischargeId = body.port_of_discharge_id ? parseInt(body.port_of_discharge_id, 10) : null;
  const portOfDischargeText = str(body.port_of_discharge_text);
  // External QA report R-13: validated here, before the (synchronous)
  // createWithNextSequence builder callback below, since that callback
  // cannot itself await.
  const sanitizedCooType = await lookupRepository.sanitizeOption('coo_type', str(body.coo_type));

  const orderRefFormat = (await companySettingsRepository.get('order_ref_format')) || 'SC/ORD/{YY}/{NNN}';
  const testModeEnabled = await testModeService.isEnabled();
  const quotationValidityDays = parseInt((await companySettingsRepository.get('quotation_validity_days')) || '30', 10);

  // QA-5 ORD-05: the order row, its stage/payment-status rows, and every
  // product line used to be inserted as separate, unwrapped statements —
  // any failure partway through (the crash this defect was originally
  // reported for, or any other insert error) left a real, visible
  // "half-created" order behind with no products or stages. The input
  // validation above now rules out the specific known trigger, but this
  // transaction is what actually guarantees the "no half-created order"
  // property in general: any failure anywhere in this block rolls back the
  // order row itself along with everything else.
  const { orderId, orderReference } = await db.transaction(async (trx) => {
    // QA-5 CONC-03: sequence-number reservation and the order INSERT now
    // happen atomically inside createWithNextSequence() (a single
    // transaction holding a row lock for this client) — see its docblock in
    // orderRepository.js. order_reference embeds the sequence number, so it
    // can only be built once buildFields(sequenceNo) is called with the
    // safely-reserved value.
    const result = await orderRepository.createWithNextSequence(
      clientId,
      (sequenceNo) => ({
        order_reference: testModeService.applyReferencePrefix(
          orderRefFormat
            .replace(/\{YYYY\}/g, String(new Date().getFullYear()))
            .replace(/\{YY\}/g, String(new Date().getFullYear()).slice(2))
            .replace(/\{NNN\}/g, String(sequenceNo).padStart(3, '0')) + `-${clientId}`, // client suffix keeps this globally unique even though the format string isn't scoped per-client
          testModeEnabled
        ),
        buyer_inquiry_ref: client.client_unique_number,
        payment_preset_id: paymentPresetId,
        incoterm_id: incotermId,
        port_of_loading_id: body.port_of_loading_id ? parseInt(body.port_of_loading_id, 10) : null,
        port_of_discharge_id: portOfDischargeId,
        port_of_discharge_text: portOfDischargeId ? null : portOfDischargeText || null,
        currency_id: currencyId,
        coo_type: sanitizedCooType || client.coo_type || 'To Be Confirmed',
        include_annexure_a: !!body.include_annexure_a,
        special_requirements: str(body.special_requirements) || null,
        container_type: str(body.container_type) || null,
        estimated_total_cbm: str(body.estimated_total_cbm),
        estimated_gross_weight_kg: str(body.estimated_gross_weight_kg),
        estimated_net_weight_kg: str(body.estimated_net_weight_kg),
        estimated_package_count: str(body.estimated_package_count) || null,
        estimated_package_type: str(body.estimated_package_type) || null,
        est_lead_time_text: str(body.est_lead_time_text) || null,
        lead_time_min_days: body.lead_time_min_days !== undefined && body.lead_time_min_days !== '' ? parseInt(body.lead_time_min_days, 10) : null,
        lead_time_max_days: body.lead_time_max_days !== undefined && body.lead_time_max_days !== '' ? parseInt(body.lead_time_max_days, 10) : null,
        sailing_lead_time_min_days: body.sailing_lead_time_min_days !== undefined && body.sailing_lead_time_min_days !== '' ? parseInt(body.sailing_lead_time_min_days, 10) : null,
        sailing_lead_time_max_days: body.sailing_lead_time_max_days !== undefined && body.sailing_lead_time_max_days !== '' ? parseInt(body.sailing_lead_time_max_days, 10) : null,
        indicative_freight_amount: str(body.indicative_freight_amount),
        indicative_insurance_amount: str(body.indicative_insurance_amount),
        buyers_po_ref: 'NIL',
        quotation_date: todayYmd(),
        quotation_valid_until: addDaysYmd(todayYmd(), quotationValidityDays),
      }),
      user.id,
      trx
    );
    const { orderId } = result;
    if (testModeEnabled) {
      await orderRepository.markTest(orderId, trx);
    }

    await orderStageRepository.initializeForOrder(orderId, trx);
    await orderPaymentStatusRepository.initializeForOrder(orderId, trx);

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
        str(hsCodes[i]),
        trx
      );
    }

    return result;
  });

  // External QA report L-23: order creation was never audit-logged.
  await auditLogRepository.log(user.id, 'ORDER_CREATED', 'orders', orderId, 'order_reference', null, orderReference);

  // QA Round 5, R5-03: terms needing MD/ED approval — tell the approvers.
  if (await paymentTermsApprovalService.isPending(orderId)) {
    for (const approverUserId of await permissionRepository.usersWithPermission('approve_payment_terms')) {
      await notificationRepository.create(approverUserId, null, 'payment_terms_approval_needed', orderId, `Order ${orderReference}: payment terms need your approval before the Quotation can be generated.`);
    }
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
  // Owner request (2026-10-25): show the configured default instead of an
  // "e.g." placeholder when this order has no estimate saved — the same
  // default the New Order form pre-fills. Only fills a gap; a saved value
  // always wins.
  for (const field of ['estimated_total_cbm', 'estimated_gross_weight_kg', 'estimated_net_weight_kg']) {
    if (order[field] === null || order[field] === undefined || order[field] === '') {
      order[field] = await companySettingsRepository.get(`default_${field}`);
    }
  }
  // QA Round 5, R5-29: after a refused save, show what was typed, not the saved values.
  for (const [field, value] of Object.entries(flash.pullOld(req) || {})) {
    if (Object.prototype.hasOwnProperty.call(order, field) && !Array.isArray(value)) {
      order[field] = value;
    }
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
      packageTypes: await lookupRepository.dropdownOptions('package_type'),
      isPostConfirmation: orderEditGuard.isPostConfirmation(order.current_stage_number),
      canOverride: orderEditGuard.canOverride(req),
      isLocked: !!order.is_locked,
      canOverrideLock: orderEditGuard.canOverrideLock(req),
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
    flash.setOld(req, body);
    res.redirect(`/orders/${orderId}/edit`);
    return;
  }
  // QA Round 5, R5-29: a wrong number is refused with a message, never a database error.
  const numberError = fieldValidator.orderNumbers(body);
  if (numberError) {
    flash.set(req, 'error', numberError);
    flash.setOld(req, body);
    res.redirect(`/orders/${orderId}/edit`);
    return;
  }

  const reason = str(body.reason);
  if (!(await orderEditGuard.allow(req, order.current_stage_number, !!order.is_locked, reason, 'orders', orderId, 'order_details'))) {
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
    coo_type: (await lookupRepository.sanitizeOption('coo_type', str(body.coo_type))) || 'To Be Confirmed',
    container_type: str(body.container_type) || null,
    buyers_po_ref: str(body.buyers_po_ref) || 'NIL',
    special_requirements: str(body.special_requirements) || null,
    est_lead_time_text: str(body.est_lead_time_text) || null,
    lead_time_min_days: body.lead_time_min_days !== undefined && body.lead_time_min_days !== '' ? parseInt(body.lead_time_min_days, 10) : null,
    lead_time_max_days: body.lead_time_max_days !== undefined && body.lead_time_max_days !== '' ? parseInt(body.lead_time_max_days, 10) : null,
    sailing_lead_time_min_days: body.sailing_lead_time_min_days !== undefined && body.sailing_lead_time_min_days !== '' ? parseInt(body.sailing_lead_time_min_days, 10) : null,
    sailing_lead_time_max_days: body.sailing_lead_time_max_days !== undefined && body.sailing_lead_time_max_days !== '' ? parseInt(body.sailing_lead_time_max_days, 10) : null,
    estimated_total_cbm: str(body.estimated_total_cbm),
    estimated_gross_weight_kg: str(body.estimated_gross_weight_kg),
    estimated_net_weight_kg: str(body.estimated_net_weight_kg),
    estimated_package_count: str(body.estimated_package_count) || null,
    estimated_package_type: str(body.estimated_package_type) || null,
    indicative_freight_amount: str(body.indicative_freight_amount),
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

  // QA Round 6, R6-N14/N15: quantity, price and line value within what can be stored.
  const lineError = orderLineValidator.checkLine(str(body.quantity), !!body.quantity_is_tbc, str(body.unit_price));
  if (lineError) {
    flash.set(req, 'error', `Product line: ${lineError}`);
    res.redirect(`/orders/${orderId}`);
    return;
  }

  const reason = str(body.reason);
  if (!(await orderEditGuard.allow(req, order.current_stage_number, !!order.is_locked, reason, 'order_products', orderId, 'product_added'))) {
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

  // QA Round 6, R6-N14/N15: quantity, price and line value within what can be stored.
  const lineError = orderLineValidator.checkLine(str(body.quantity), !!body.quantity_is_tbc, str(body.unit_price));
  if (lineError) {
    flash.set(req, 'error', `Product line: ${lineError}`);
    res.redirect(`/orders/${orderId}`);
    return;
  }

  const reason = str(body.reason);
  if (!(await orderEditGuard.allow(req, order.current_stage_number, !!order.is_locked, reason, 'order_products', productId, 'product_line'))) {
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
  if (!(await orderEditGuard.allow(req, order.current_stage_number, !!order.is_locked, reason, 'order_products', productId, 'product_removed'))) {
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
  if (!(await orderEditGuard.allow(req, order.current_stage_number, !!order.is_locked, reason, 'order_products', productId, 'product_duplicated'))) {
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
  // Archiving only removes an order from the default listing, and a
  // guessed/bookmarked URL must not bypass the order-assignment scoping
  // the Orders list and Kanban board apply — both checked in
  // orderAccessService, shared with the file download route. Visibility
  // only: never decides who may act on a step.
  const accessDenied = await orderAccessService.denialReason(order, req.user && req.user.id, req.permissions);
  if (accessDenied !== null) {
    res.status(403).send(accessDenied);
    return;
  }
  const seesAllOrders = !!req.permissions.view_all_orders;
  const canAssignOwner = !!req.permissions.assign_order_owner;

  const stages = await orderStageRepository.forOrder(orderId);
  const stageByNumber = {};
  for (const s of stages) stageByNumber[s.stage_number] = s;

  // Owner decision (2026-10-28): CAFIN is visible only to holders of the
  // "View & download CAFIN" permission — hidden from the document list too.
  const canViewCafin = !!req.permissions.view_cafin;
  let documents = await documentRepository.forOrder(orderId);
  if (!canViewCafin) documents = documents.filter((d) => d.document_type_code !== 'CAFIN');
  const reviewsByDocument = {};
  const crossVerificationsByDocument = {};
  for (const d of documents) {
    reviewsByDocument[d.id] = await documentReviewRepository.forDocument(d.id);
    crossVerificationsByDocument[d.id] = await documentCrossVerificationRepository.forDocument(d.id);
  }

  // External QA report M-08: "Regenerating an earlier-stage document
  // after later stages passed: mandatory confirmation ... App only
  // warns." Precomputed once per already-generated type (clicking its
  // Generate button would be a regeneration) so the page's own JS can
  // show a specific, named confirm() dialog before the POST even goes
  // out, instead of only warning after the fact.
  const regenerationRisk = {};
  for (const existingCode of [...new Set(documents.map((d) => d.document_type_code))]) {
    const downstream = await documentGenerationService.downstreamDocumentsAtRisk(orderId, existingCode);
    if (downstream.length) {
      regenerationRisk[existingCode] = downstream;
    }
  }

  const disputes = await disputeRepository.forOrder(orderId);
  const complaints = await orderComplaintRepository.forOrder(orderId);

  // External QA report M-09: formal cancellation workflow, distinct from
  // Mark as Lost (which only ever applies pre-money, while the order is
  // still 'active' with no BL on file). A request here never changes the
  // order's own status; only an approval does.
  const pendingCancellation = await orderCancellationRepository.findPendingForOrder(orderId);
  const cancellationHistory = await orderCancellationRepository.forOrder(orderId);
  const approvedCancellation = cancellationHistory.find((c) => c.status === 'approved') || cancellationHistory[0] || null;

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

  // docs/schema.sql Section BT — export process step 8a: whether this
  // order even has a 3rd-party inspection to track a fee for reuses the
  // existing Compliance Checklist's own "Pre-Shipment Inspection" row
  // rather than a second, duplicate flag — not yet marked 'skipped'
  // (not applicable) means it's still relevant.
  const complianceTasksForOrder = await orderComplianceTaskRepository.forOrder(orderId);
  const pendingInspectionTask = complianceTasksForOrder.some((t) => t.task_type_name === 'Pre-Shipment Inspection' && t.status !== 'skipped');
  const canManageOrderFinancialsForInspection = !!req.permissions.manage_order_financials;
  const exportBenefitSchemesForOrder = await orderExportBenefitSchemeRepository.forOrder(orderId);

  const duplicatedFromOrder = order.duplicated_from_order_id
    ? await orderRepository.find(order.duplicated_from_order_id)
    : null;
  const duplicatedIntoOrders = await orderRepository.findOrdersDuplicatedFrom(orderId);

  const activeUsers = await userRepository.listActive();
  const usersById = {};
  for (const u of activeUsers) usersById[u.id] = u.name;

  // Phase 6: precomputed here (not inside the .njk) since nunjucks macros
  // can't await a repository call — mirrors the PHP view's inline closures,
  // which call CaFyLockRepository::lockMessageForDate() directly.
  const paymentForLocks = await orderPaymentStatusRepository.find(orderId);
  // Batch 3 #3: each leg's exchange-rate widget reuses that SAME leg's own
  // lock message (no more combined exchangeRate key) — same per-leg FY
  // lock as its INR Actual widget.
  const caLockMessages = { advance: null, balance: null, freight: null };
  if (paymentForLocks) {
    caLockMessages.advance = await caFyLockRepository.lockMessageForDate(paymentForLocks.advance_cleared_at);
    caLockMessages.balance = await caFyLockRepository.lockMessageForDate(paymentForLocks.balance_cleared_at);
    caLockMessages.freight = await caFyLockRepository.lockMessageForDate(paymentForLocks.freight_cleared_at);
  }
  const piIntake = await piIntakeRepository.latestForOrder(orderId);
  let piFormFullLink = null;
  if (
    piIntake
    && ['awaiting_client', 'rejected'].includes(piIntake.status)
    && parseDbDateTime(piIntake.access_token_expires_at).getTime() > Date.now()
  ) {
    piFormFullLink = `${env.get('APP_URL', '').replace(/\/+$/, '')}/pi-details/${piIntake.access_token_plain}`;
  }

  // 90-item backlog #75: a public, no-login link covering both this
  // stage's OC acknowledgement and the client-portal Buyer PO upload.
  // QA-5 L-24: buyer_action_token_plain is encrypted at rest
  // (encryptionService) — decrypt it here, the one place it's ever
  // rendered back to staff.
  let buyerActionFullLink = null;
  const buyerActionTokenPlain = encryptionService.decrypt(order.buyer_action_token_plain);
  const buyerActionLinkValid = !!buyerActionTokenPlain
    && !!order.buyer_action_token_expires_at
    && parseDbDateTime(order.buyer_action_token_expires_at).getTime() > Date.now();
  if (buyerActionLinkValid) {
    buyerActionFullLink = `${env.get('APP_URL', '').replace(/\/+$/, '')}/buyer-action/${buyerActionTokenPlain}`;
  }

  // Owner request (2026-10-27): the Payment Action Link (5d / 9c / 11c),
  // decrypted the same way for staff to re-copy.
  let paymentActionFullLink = null;
  const paymentActionTokenPlain = encryptionService.decrypt(order.payment_action_token_plain);
  if (paymentActionTokenPlain && order.payment_action_token_expires_at
    && parseDbDateTime(order.payment_action_token_expires_at).getTime() > Date.now()) {
    paymentActionFullLink = `${env.get('APP_URL', '').replace(/\/+$/, '')}/payment-action/${paymentActionTokenPlain}`;
  }

  // Owner request (2026-10-26, t4): the Step 7c Supplier Action Link,
  // decrypted the same way for staff to re-copy.
  let supplierActionFullLink = null;
  const supplierActionTokenPlain = supplierPo ? encryptionService.decrypt(supplierPo.supplier_action_token_plain) : null;
  if (supplierActionTokenPlain && supplierPo.supplier_action_token_expires_at
    && parseDbDateTime(supplierPo.supplier_action_token_expires_at).getTime() > Date.now()) {
    supplierActionFullLink = `${env.get('APP_URL', '').replace(/\/+$/, '')}/supplier-action/${supplierActionTokenPlain}`;
  }

  const supplierFactoryAddressesBySupplier = await supplierFactoryAddressRepository.allGroupedBySupplier();
  // User-reported batch item 12: a flat supplierId -> [addressText] map
  // alongside the full-row one above, embedded per <option> for the
  // Delivery Location dropdown's client-side repopulation — Nunjucks has
  // no built-in way to pluck one field out of a list of objects inline.
  const supplierFactoryAddressTextsBySupplier = {};
  for (const [supplierId, addresses] of Object.entries(supplierFactoryAddressesBySupplier)) {
    supplierFactoryAddressTextsBySupplier[supplierId] = addresses.map((a) => a.address);
  }

  const assigneesForView = await orderAssigneeRepository.forOrder(orderId);

  // Owner decisions (2026-10-27): bill first, then pay — the supplier's bills
  // (7e / 10a) and the logistics partners' bills (Group C), and the order's
  // actual expenses with receipts (12a).
  const vendorBills = await orderVendorBillRepository.forOrder(orderId);
  const logisticsBanks = {};
  for (const partnerId of [...new Set(vendorBills.filter((b) => b.logistics_partner_id).map((b) => Number(b.logistics_partner_id)))]) {
    logisticsBanks[partnerId] = vendorDetailsRepository.bankDetails(await vendorDetailsRepository.find('logistics_partner', partnerId));
  }
  const canManageOrderFinancialsForBills = !!req.permissions.manage_order_financials;

  // Owner decision 2026-10-30: the team incentive box (Step 12d) — manage_incentives only.
  const canManageIncentives = !!req.permissions.manage_incentives;
  let incentiveView = {};
  if (canManageIncentives) {
    const incentiveService = require('../services/incentiveService');
    const incentiveRepository = require('../repositories/incentiveRepository');
    if (req.user) {
      await incentiveService.lockIfDue(orderId, req.user.id);
    }
    incentiveView = {
      incentiveRow: await incentiveRepository.forOrder(orderId),
      incentiveCalc: await incentiveService.calculate(orderId),
      incentivePayouts: await incentiveRepository.payoutsForOrder(orderId),
      incentiveUsers: await userRepository.listActive(),
    };
  }

  // Owner decision 2026-10-30: each stage's gating button says, before the
  // click, which of the stage's documents still need generating, approving or
  // sending (the rule stageDocumentBlockedBy() enforces), and stays disabled
  // until they are done.
  const stageGate = {};
  for (const stage of [2, 3, 4, 5, 6, 7, 8]) {
    stageGate[stage] = await documentGenerationService.stageDocumentGate(orderId, stage);
  }
  const stageGateMailOn = await require('../services/emailDispatchService').mailSendingEnabled();
  const gateEscape = (v) => String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  const stageGateDisabled = (stage) => (stageGate[stage].length ? ' disabled title="See the note above: this stage\'s document is not finished yet."' : '');
  const stageGateNotice = (stage, action) => {
    if (!stageGate[stage].length) return '';
    const items = stageGate[stage].map((d) => {
      let todo;
      if (d.todo === 'generate') todo = 'not generated yet — generate it, then get it approved' + (d.buyer_facing && stageGateMailOn ? ' and sent to the buyer' : '');
      else if (d.todo === 'approve') todo = 'waiting for approval';
      else if (d.todo === 'approve_send') todo = 'waiting for approval, then sending to the buyer';
      else todo = 'approved — waiting to be sent to the buyer';
      return '<strong>' + gateEscape(d.name) + '</strong>: ' + todo;
    });
    return '<p class="review-banner info stage-gate">&#8505; <span>Before you can ' + gateEscape(action) + ': ' + items.join('; ')
      + '. <a href="/reviews">Open Document Review &amp; Approval</a></span></p>';
  };

  res.renderView(
    'orders/show',
    {
      stageGateDisabled,
      stageGateNotice,
      canManageIncentives,
      ...incentiveView,
      vendorBills,
      logisticsBanks,
      logisticsPaymentBlocked: await require('./vendorBillController').logisticsPaymentBlockedReason(order),
      orderExpenses: canManageOrderFinancialsForBills ? await orderExpenseRepository.forOrder(orderId) : [],
      expenseSummary: canManageOrderFinancialsForBills ? await orderExpenseRepository.summary(orderId) : null,
      vendorBillLegs: orderVendorBillRepository.SUPPLIER_LEGS,
      logisticsServices: orderVendorBillRepository.LOGISTICS_SERVICES,
      paymentModes: orderVendorBillRepository.PAYMENT_MODES,
      expenseCategories: orderExpenseRepository.CATEGORIES,
      expensePaidBy: orderExpenseRepository.PAID_BY,
      order,
      canAssignOwner,
      seesAllOrders,
      assignees: assigneesForView,
      assignedUserIds: assigneesForView.map((a) => a.user_id),
      leadTimeDisplayUnit: (await companySettingsRepository.get('lead_time_display_unit')) || 'days',
      // Owner decision: with email sending off there is no "Send to Buyer"
      // action — a document counts as sent once approved
      // (emailDispatchService.markSentIfMailDisabled()).
      mailSendingEnabled: (await companySettingsRepository.get('mail_sending_enabled')) !== '0',
      buyerPoCopyRequired: (await companySettingsRepository.get('wet_signature_required_buyer_po')) === '1',
      supplierPoCopyRequired: (await companySettingsRepository.get('wet_signature_required_supplier_po')) === '1',
      products: await orderProductRepository.forOrder(orderId),
      hsCodes: await hsCodeRepository.active(),
      isPostConfirmationOrder: orderEditGuard.isPostConfirmation(order.current_stage_number),
      canOverrideOrderEdit: orderEditGuard.canOverride(req),
      // 90-item backlog Phase 6 Item 4 — the stricter lock check sits in front of the post-confirmation one.
      isLockedOrder: !!order.is_locked,
      canOverrideLockEdit: orderEditGuard.canOverrideLock(req),
      canManageOrders: !!req.permissions.manage_orders,
      // 90-item backlog #57 / Phase 6 Item 3: now a real server-side hard
      // gate too (see documentGenerationService.closureBlockedBy() and
      // closeOrder() below), not just a display-only hint.
      closeBlockedBy: await documentGenerationService.closureBlockedBy(orderId),
      stages,
      stageByNumber,
      payment: await orderPaymentStatusRepository.find(orderId),
      documents,
      regenerationRisk,
      pendingCancellation,
      approvedCancellation,
      reviewsByDocument,
      crossVerificationsByDocument,
      emailLogByDocument,
      activeUsers,
      fobTotal: await orderProductRepository.totalFobValue(orderId),
      suppliers: await supplierRepository.all(),
      logisticsPartners: await logisticsPartnerRepository.all(),
      logisticsServiceTypes: logisticsPartnerRepository.SERVICE_TYPES,
      supplierPo,
      // Owner request (2026-10-27): who to pay in 7e / 10a.
      supplierBank: supplierPo && supplierPo.supplier_id ? vendorDetailsRepository.bankDetails(await vendorDetailsRepository.find('supplier', supplierPo.supplier_id)) : null,
      buyerPoDocuments: await orderBuyerPoDocumentRepository.forOrder(orderId),
      supplierPoDocuments: supplierPo ? await orderSupplierPoDocumentRepository.forSupplierPo(supplierPo.id) : [],
      freight: await orderFreightRepository.find(orderId),
      packing: await orderPackingRepository.find(orderId),
      orderedQuantitySummary: await orderProductRepository.orderedQuantitySummary(orderId),
      shortfallTolerance: (await companySettingsRepository.get('quantity_shortfall_tolerance_pct')) ?? '5',
      crates: await orderCrateRepository.forOrder(orderId),
      shipping: await orderShippingRepository.find(orderId),
      draftBlReviews: await orderDraftBlReviewRepository.forOrder(orderId),
      production: await orderProductionRepository.find(orderId),
      units: await lookupRepository.dropdownOptions('unit'),
      surfaceFinishes: await lookupRepository.dropdownOptions('surface_finish'),
      gstRates: await lookupRepository.dropdownOptions('gst_rate'),
      dimensionalTolerances: await lookupRepository.dropdownOptions('dimensional_tolerance'),
      supplierFactoryAddressesBySupplier,
      supplierFactoryAddressTextsBySupplier,
      amendmentCount: (await amendmentRepository.forOrder(orderId)).length,
      complianceTasks: complianceTasksForOrder,
      complianceStatuses: orderComplianceTaskRepository.STATUSES,
      complianceNaReason: orderComplianceTaskRepository.NOT_APPLICABLE_REASON,
      schemeNotEligibleReason: orderExportBenefitSchemeRepository.NOT_ELIGIBLE_REASON,
      pendingInspectionTask,
      exportBenefitEligibility: exportBenefitSchemesForOrder,
      benefitSettlement: await benefitSettlementService.state(orderId),
      exportBenefitEligibilityStatuses: orderExportBenefitSchemeRepository.STATUSES,
      orderInspectionFee: canManageOrderFinancialsForInspection ? await orderInspectionFeeRepository.find(orderId) : null,
      openDisputeCount: disputes.filter((d) => d.status !== 'Resolved').length,
      openComplaintCount: complaints.filter((c) => c.status !== 'resolved').length,
      piIntake,
      piFormFullLink,
      buyerActionLinkValid,
      buyerActionFullLink,
      paymentActionFullLink,
      supplierActionFullLink,
      clientPaymentReports: await clientPaymentReportRepository.forOrder(orderId),
      ocAcknowledgment: await orderOcAcknowledgmentRepository.find(orderId),
      ocDocGenerated: documents.some((d) => d.document_type_code === 'OC'),
      // 90-item backlog #52/#53: confirmSupplierSigned()/recordBalancePayment()
      // now require their commercial document to actually exist — these
      // drive the matching UI hints (closest existing precedent: ocDocGenerated above).
      suppoDocGenerated: documents.some((d) => d.document_type_code === 'SUPPO'),
      ciDocGenerated: documents.some((d) => d.document_type_code === 'CI'),
      comments: await orderCommentRepository.forOrder(orderId),
      canViewInrActual: !!req.permissions.inr_actual_view,
      canEditInrActual: !!req.permissions.inr_actual_edit,
      canDeleteInrActual: !!req.permissions.inr_actual_delete,
      canOverrideFyLock: caFyLockGuard.canOverride(req),
      caLockMessages,
      usersById,
      // Point 2 follow-up — a RODTEP/export-benefit claim or an expense
      // (ECGC insurance, third-party inspection, ...) can be linked to
      // the specific order it relates to; this surfaces both here so the
      // order page shows every financial detail tied to it at a glance,
      // not just in the separate CA module list screens.
      canViewCaLinks: !!req.permissions.ca_module_view,
      // Owner decision 2026-10-29: the per-order "App vs Zoho" check, for whoever runs the Zoho sync.
      canCheckZoho: !!req.permissions.ca_module_manage,
      linkedExportBenefits: req.permissions.ca_module_view ? await caExportBenefitRepository.forOrder(orderId) : [],
      // Owner request (2026-10-26): Step 12c lists this order's claims for manage_order_financials holders.
      orderBenefitClaims: req.permissions.manage_order_financials ? await caExportBenefitRepository.forOrder(orderId) : [],
      linkedCaExpenses: req.permissions.ca_module_view ? await caExpenseRepository.forOrderCounted(orderId) : [],
      canManageCaInternalDoc: !!req.permissions.ca_internal_doc_manage,
      canViewCafin,
      paymentTermsApproval: await paymentTermsApprovalService.state(orderId),
      canApprovePaymentTerms: !!req.permissions.approve_payment_terms,
      caInternalDoc: canViewCafin ? await documentRepository.findLatestForOrderAndTypeCode(orderId, 'CAFIN') : null,
      // Reorder-to-supplier linking + Order Profitability Sheet (docs/schema.sql
      // Section AP) — gated on the new, deliberately stricter
      // manage_order_financials permission rather than ca_module_view, per
      // the user's own ask that this specific section stay
      // Super-Admin-plus-explicitly-granted-roles only.
      canManageOrderFinancials: !!req.permissions.manage_order_financials,
      // Owner decision (2026-10-26): the full ledger only for manage_order_financials holders.
      ledger: await orderLedgerService.forOrder(orderId, order.currency_code || '', !!req.permissions.manage_order_financials),
      orderCostEntries: req.permissions.manage_order_financials ? await orderCostEntryRepository.forOrder(orderId) : [],
      costEntryCategories: orderCostEntryRepository.CATEGORIES,
      costEntryPaidFrom: orderCostEntryRepository.PAID_FROM,
      costEntryNotInZoho: orderCostEntryRepository.NOT_IN_ZOHO,
      // Not gated on manage_order_financials like the cost-entry fields
      // above — Step 5d/5e (LC payment leg) needs this for anyone viewing
      // the order page, not just Order Financials staff.
      orderLcDetails: await orderLcDetailRepository.find(orderId),
      orderTravelExpenses: req.permissions.manage_order_financials ? await orderTravelExpenseRepository.find(orderId) : null,
      profitability: req.permissions.manage_order_financials ? await orderProfitabilityService.computeForOrder(orderId) : null,
      exportBenefitSchemes: await lookupRepository.dropdownOptions('export_benefit_scheme'),
      duplicatedFromOrder,
      duplicatedIntoOrders,
      additionalDocuments: await fileStoreRepository.additionalDocumentsForOrder(orderId),
      blEndorsement: await orderBlEndorsementRepository.find(orderId),
      blEndorsementDoc: await documentRepository.findLatestForOrderAndTypeCode(orderId, 'BLE'),
      containerTypes: await lookupRepository.dropdownOptions('container_type'),
      // docs/schema.sql Section BV — rating/comments aren't financial
      // data, so this isn't gated behind manage_order_financials.
      orderFeedback: await orderFeedbackRepository.find(orderId),
      testimonialSuggestion: await testimonialSuggestionService.forOrder(orderId),
    },
    'layout/base'
  );
}

/**
 * Order ownership / assignment visibility — adds a staff member to an
 * order's assignee list. Route-gated to assign_order_owner (Admin/MD/ED
 * only); this function doesn't re-check it. Visibility-only: never
 * touches stage-gate or action-permission checks elsewhere on this
 * order.
 */
async function addAssignee(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    flash.set(req, 'error', 'Order not found.');
    res.redirect('/orders');
    return;
  }

  const userId = parseInt(req.body.user_id, 10) || 0;
  const user = userId > 0 ? await userRepository.findById(userId) : null;
  if (!user || !user.is_active) {
    flash.set(req, 'error', 'Select a valid, active user to assign this order to.');
    res.redirect(`/orders/${orderId}`);
    return;
  }

  try {
    await orderAssignmentService.addAssignee(orderId, userId, req.user.id);
    flash.set(req, 'success', `${user.name} added as an assignee on this order.`);
  } catch (e) {
    flash.setErrorFromException(req, e);
  }
  res.redirect(`/orders/${orderId}`);
}

/**
 * Order ownership / assignment visibility — removes a staff member from
 * an order's assignee list. Route-gated to assign_order_owner (Admin/MD/
 * ED only); this function doesn't re-check it.
 */
async function removeAssignee(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const userId = parseInt(req.params.userId, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    flash.set(req, 'error', 'Order not found.');
    res.redirect('/orders');
    return;
  }

  try {
    await orderAssignmentService.removeAssignee(orderId, userId, req.user.id);
    flash.set(req, 'success', 'Assignee removed from this order.');
  } catch (e) {
    flash.setErrorFromException(req, e);
  }
  res.redirect(`/orders/${orderId}`);
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
/**
 * Owner decision (2026-10-26): Step 5c (Generate PI) stays locked until the
 * client's PI-stage form is applied (5b) — so when a client won't use the
 * form, staff record that here (reason required, audit-logged), which
 * completes 5a/5b and unlocks 5c.
 */
async function skipPiForm(req, res) {
  const orderId = parseInt(req.params.id, 10);
  if (!(await orderRepository.find(orderId))) {
    res.status(404).send('Order not found.');
    return;
  }
  const reason = str(req.body.skip_reason);
  const error = reasonValidator.check(reason);
  if (error) {
    flash.set(req, 'error', error);
    res.redirect(`/orders/${orderId}#step-5b-pi-intake-apply`);
    return;
  }
  await orderRepository.markPiFormSkipped(orderId, req.user.id, reason);
  await auditLogRepository.log(req.user.id, 'PI_FORM_SKIPPED', 'orders', orderId, 'pi_form_skipped_at', null, new Date().toISOString().slice(0, 19).replace('T', ' '), reason);
  flash.set(req, 'success', 'PI-stage form skipped — Step 5c (Generate Proforma Invoice) is now open.');
  res.redirect(`/orders/${orderId}`);
}

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
    // 90-item backlog #37: admin-editable (Admin > Settings > Email
    // Templates) — this exact original wording is the fallback.
    const rendered = await emailTemplateRepository.renderOrDefault(
      'pi_form_link',
      { '{order_reference}': order.order_reference, '{pi_form_link}': link },
      'NexaCrest — confirm your PI-stage details',
      'Hello,\n\n'
        + 'Thank you for accepting our Quotation for order {order_reference}.\n\n'
        + 'To issue your Proforma Invoice, please confirm your shipping/consignee details at:\n{pi_form_link}\n\n'
        + 'This link works for 30 days.\n\n'
        + 'NexaCrest International Private Limited'
    );
    await emailService.sendPlainText(order.client_email, rendered.subject, rendered.body);
  }

  await auditLogRepository.log(user.id, 'PI_INTAKE_LINK_GENERATED', 'orders', orderId, null, null, null, 'PI-stage intake link generated' + (order.client_email ? ' and emailed to client' : ' — no client email on file, hand this link over directly'));
  flash.set(req, 'success', `PI-stage form link${order.client_email ? ` emailed to ${order.client_email}` : ' generated'}: ${link}`);
  res.redirect(`/orders/${orderId}`);
}

/**
 * Port of App\Controllers\OrderController::generateBuyerActionLink.
 * 90-item backlog #75: generates (or regenerates) the public, no-login
 * Buyer Action link — lets the buyer acknowledge their Order Confirmation
 * and upload their signed Buyer PO copy without logging into the client
 * portal. Same staff-initiated, flash-back-the-link pattern as
 * generatePiFormLink() above; see docs/schema.sql Section BG and
 * buyerActionController's docblock.
 */
async function generateBuyerActionLink(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }

  const user = req.user;
  const rawToken = await orderRepository.generateBuyerActionToken(orderId);
  const link = `${env.get('APP_URL', '').replace(/\/+$/, '')}/buyer-action/${rawToken}`;

  if (order.client_email) {
    // 90-item backlog #37: admin-editable (Admin > Settings > Email
    // Templates) — this exact original wording is the fallback.
    const rendered = await emailTemplateRepository.renderOrDefault(
      'buyer_action_link',
      { '{order_reference}': order.order_reference, '{buyer_action_link}': link },
      'NexaCrest — acknowledge your order / upload your Buyer PO',
      'Hello,\n\n'
        + 'For order {order_reference}, please use this link to download your Purchase Order, upload the signed copy with your PO number, and acknowledge your Order Confirmation once you receive it:\n{buyer_action_link}\n\n'
        + 'This link works for 90 days.\n\n'
        + 'NexaCrest International Private Limited'
    );
    await emailService.sendPlainText(order.client_email, rendered.subject, rendered.body);
  }

  await auditLogRepository.log(user.id, 'BUYER_ACTION_LINK_GENERATED', 'orders', orderId, null, null, null, 'Buyer Action link generated' + (order.client_email ? ' and emailed to client' : ' — no client email on file, hand this link over directly'));
  flash.set(req, 'success', `Buyer Action link${order.client_email ? ` emailed to ${order.client_email}` : ' generated'}: ${link}`);
  // Owner request (2026-10-26): sent from Step 4b or 6b — return to that card.
  const returnTo = /^step-[a-z0-9-]+$/.test(String(req.body.return_to || '')) ? `#${req.body.return_to}` : '';
  res.redirect(`/orders/${orderId}${returnTo}`);
}

/**
 * Owner request (2026-10-27): the Payment Action Link — the client sends
 * their payment details (advance / freight / balance) there. Generated from
 * 5d, 9c or 11c like the Buyer Action Link from 4b; the same link also goes
 * out automatically inside the PI, Debit Note and CI emails.
 */
async function generatePaymentActionLink(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }

  const user = req.user;
  const link = `${env.get('APP_URL', '').replace(/\/+$/, '')}/payment-action/${await orderRepository.generatePaymentActionToken(orderId)}`;
  const clientEmail = order.client_email || ((await clientRepository.find(order.client_id)) || {}).email || null;
  if (clientEmail) {
    const rendered = await emailTemplateRepository.renderOrDefault(
      'payment_action_link',
      { '{order_reference}': order.order_reference, '{payment_action_link}': link },
      'NexaCrest — send us your payment details for order {order_reference}',
      'Hello,\n\n'
        + 'For order {order_reference}: once you have made the payment, please send us the payment screenshot together with the transaction details (Transaction ID / UTR, amount and date) on this secure link — no login needed:\n{payment_action_link}\n\n'
        + 'Our team will check them against our bank statement and confirm. The same link works for every payment on this order.\n\n'
        + 'NexaCrest International Private Limited'
    );
    await emailService.sendPlainText(clientEmail, rendered.subject, rendered.body);
  }

  await auditLogRepository.log(user.id, 'PAYMENT_ACTION_LINK_GENERATED', 'orders', orderId, null, null, null, 'Payment Action link generated' + (clientEmail ? ' and emailed to client' : ' — no client email on file, hand this link over directly'));
  flash.set(req, 'success', `Payment Action link${clientEmail ? ` emailed to ${clientEmail}` : ' generated'}: ${link}`);
  const returnTo = /^step-[a-z0-9-]+$/.test(String(req.body.return_to || '')) ? `#${req.body.return_to}` : '';
  res.redirect(`/orders/${orderId}${returnTo}`);
}

/**
 * Real gap this closes: no way existed to hand over (or archive)
 * everything on file for one order in a single action — every generated
 * document and every received/uploaded file (dispute evidence, buyer PO
 * copy, supplier PO acknowledgment, ...) had to be downloaded one at a
 * time. file_store.order_id is already set for both origins
 * (insertGenerated() and insertReceived()), so
 * fileStoreRepository.forOrderExcludingInternalCaDocs() (forOrder() minus
 * the internal-only CA Financial Annexure, see that method's docblock —
 * this route is gated only on manage_orders, far broader than the
 * ca_module_view that document's data needs everywhere else) is
 * everything the dossier needs — no separate joins through
 * documents/dispute_documents/orderBuyerPoDocuments/etc.
 */
async function downloadDossier(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }

  const files = await fileStoreRepository.forOrderExcludingInternalCaDocs(orderId);
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

/**
 * Owner decision 2026-10-30: corrects the date the buyer's PO was received
 * after Step 4d (it is Day 1 of an associate's 10 days), with a reason,
 * audit-logged. An incentive already fixed keeps its figures until it is
 * worked out again on the order's incentive card.
 */
async function correctBuyerPoReceivedDate(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order || String(order.buyers_po_ref || '') === '' || order.buyers_po_ref === 'NIL') {
    flash.set(req, 'error', "Confirm the buyer's PO received (Step 4d) first.");
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const receivedOn = str(req.body.buyer_po_received_on);
  const orderDate = String(order.created_at || '').slice(0, 10);
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(receivedOn);
  const realDate = m && (() => {
    const d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
    return d.getUTCFullYear() === Number(m[1]) && d.getUTCMonth() === Number(m[2]) - 1 && d.getUTCDate() === Number(m[3]);
  })();
  if (!realDate || receivedOn > todayIst() || receivedOn < orderDate) {
    flash.set(req, 'error', `Enter the date the buyer's PO was received: not in the future and not before the order was created (${orderDate}).`);
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const reason = str(req.body.reason);
  const reasonError = reasonValidator.check(reason);
  if (reasonError !== null) {
    flash.set(req, 'error', reasonError);
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const old = order.buyer_po_received_on ? String(order.buyer_po_received_on).slice(0, 10) : null;
  if (old === receivedOn) {
    flash.set(req, 'success', 'No change: that is already the date on file.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  await orderRepository.setBuyerPoReceivedOn(orderId, receivedOn);
  await auditLogRepository.log(req.user.id, 'BUYER_PO_RECEIVED_DATE_CORRECTED', 'orders', orderId, 'buyer_po_received_on', old, receivedOn, reason);
  const incentive = await require('../repositories/incentiveRepository').forOrder(orderId);
  flash.set(req, 'success', `Date the buyer's PO was received corrected to ${dates.short(receivedOn)}.`
    + (incentive && incentive.locked_at ? ' This order\'s incentive is already fixed; use "Work it out again" on its incentive card for the new date to count.' : ''));
  res.redirect(`/orders/${orderId}`);
}

/** Stage 1->2 manual gate: buyer's signed PO received. */
async function recordBuyerPo(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  if (!(await stageGateService.isUnlocked(orderId, 2))) {
    flash.set(req, 'error', 'Stage 2 has not been unlocked for this order yet — complete Stage 1 first.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  // Owner decision (2026-10-25): this stage completes only once its
  // document is approved (email off) / sent (email on).
  const stageDocsBlockedBy = await documentGenerationService.stageDocumentBlockedBy(orderId, 2);
  if (stageDocsBlockedBy.length) {
    flash.set(req, 'error', await documentGenerationService.stageDocumentBlockedMessage(stageDocsBlockedBy));
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const ref = str(req.body.buyers_po_ref);
  if (ref === '') {
    flash.set(req, 'error', "Buyer's PO / reference number is required to confirm this gate.");
    res.redirect(`/orders/${orderId}`);
    return;
  }
  // Owner decision 2026-10-30: the day the PO arrived is the associate
  // agreement's Day 1, so it is recorded here (not the click date).
  const receivedOn = str(req.body.buyer_po_received_on);
  const orderDate = String((await orderRepository.find(orderId))?.created_at || '').slice(0, 10);
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(receivedOn);
  const realDate = m && (() => {
    const d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
    return d.getUTCFullYear() === Number(m[1]) && d.getUTCMonth() === Number(m[2]) - 1 && d.getUTCDate() === Number(m[3]);
  })();
  if (!realDate || receivedOn > todayIst() || receivedOn < orderDate) {
    flash.set(req, 'error', `Enter the date the buyer's PO was received: not in the future and not before the order was created (${orderDate}).`);
    res.redirect(`/orders/${orderId}`);
    return;
  }
  if (await wetSignatureGuardService.buyerPoBlocked(orderId)) {
    flash.set(req, 'error', 'Wet-signature required: upload the buyer\'s signed PO copy ("Attach Buyer PO copy") before confirming this gate.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  await orderRepository.setBuyersPoRef(orderId, ref, receivedOn);
  await stageGateService.passAndUnlockNext(orderId, 2, user.id);
  flash.set(req, 'success', `Buyer PO recorded (${ref}, received ${dates.short(receivedOn)}). Stage 3 (PI / Production) unlocked.`);
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
      orderStorage.forReceived(order, 'buyer_po'),
      null,
      orderId,
      req.user.id,
      null,
      'buyer',
      'Buyer PO copy'
    );
    const documentId = await orderBuyerPoDocumentRepository.attach(orderId, fileId);
    // External QA report N-08: this action never reached the audit log,
    // so it never notified anyone either.
    await auditLogRepository.log(req.user.id, 'ORDER_BUYER_PO_DOCUMENT_UPLOADED', 'order_buyer_po_documents', documentId, null, null, null);
    flash.set(req, 'success', 'Buyer PO copy attached.');
  } catch (e) {
    flash.setErrorFromException(req, e);
  }
  res.redirect(`/orders/${orderId}`);
}

/**
 * Batch 3 #13b — a free-form extra document attached to this order
 * that doesn't fit any fixed document type and isn't part of the
 * order progress chat (that's for back-and-forth discussion; this is
 * for a standalone file, e.g. a buyer-supplied certificate template).
 * Reuses file_store/fileUploadService rather than a new table — see
 * schema.sql Section AZ.
 */
async function uploadAdditionalDocument(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }

  const title = String(req.body.title || '').trim();
  if (title === '') {
    flash.set(req, 'error', 'A title is required for the document.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const notes = String(req.body.notes || '').trim() || null;

  try {
    const fileId = await fileUploadService.handleUpload(
      req,
      'document',
      'order_additional_document',
      orderStorage.folder(order, 'additional_documents'),
      null,
      orderId,
      req.user.id,
      null,
      String(req.body.received_from || '').trim() || null,
      title
    );
    await fileStoreRepository.markAsAdditionalDocument(fileId, notes);
    await auditLogRepository.log(req.user.id, 'ORDER_ADDITIONAL_DOCUMENT_ADDED', 'file_store', fileId, 'document_type_label', null, title);
    flash.set(req, 'success', `"${title}" attached to this order.`);
  } catch (e) {
    flash.setErrorFromException(req, e);
  }
  res.redirect(`/orders/${orderId}`);
}

/**
 * Soft delete only — the row is deactivated, the file on disk is never
 * touched.
 *
 * External QA report L-18: "deleteAdditionalDocument (order + client)
 * still takes no reason; no 'show removed' view." Now requires a reason
 * (reasonValidator.MIN_LENGTH, same standing instruction as every other
 * reason field in this app), logged on the audit entry — see
 * showRemovedAdditionalDocuments() for the "show removed" half.
 */
async function deleteAdditionalDocument(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const fileId = parseInt(req.params.fileId, 10);
  const file = await fileStoreRepository.find(fileId);
  if (!file || file.order_id !== orderId || !file.is_additional_document) {
    flash.set(req, 'error', 'Document not found.');
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

  await fileStoreRepository.deactivate(fileId);
  await auditLogRepository.log(req.user.id, 'ORDER_ADDITIONAL_DOCUMENT_REMOVED', 'file_store', fileId, 'document_type_label', file.document_type_label, null, reason);
  flash.set(req, 'success', `"${file.document_type_label}" removed. The file itself is never deleted from disk.`);
  res.redirect(`/orders/${orderId}`);
}

/** External QA report L-18: "no screen to see removed files" — a read-only list of this order's deactivated additional documents, newest first. */
async function showRemovedAdditionalDocuments(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  res.renderView('orders/removed_additional_documents', {
    order,
    removedDocuments: await fileStoreRepository.removedAdditionalDocumentsForOrder(orderId),
  });
}

/** "Received USD 1,800.00 at today's exchange rate of ₹83.2500 (= ₹149,850.00)." — the remittance + rate just recorded. */
async function remittanceStatement(orderId, amount, rate) {
  const order = await orderRepository.find(orderId);
  const fmt = (n, digits) => Number(n).toLocaleString('en-US', { minimumFractionDigits: digits, maximumFractionDigits: digits });
  return `Received ${(order && order.currency_code) || ''} ${fmt(amount, 2)} at today's exchange rate of ₹${fmt(rate, 4)} (= ₹${fmt(amount * rate, 2)}).`;
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
  // Owner request (2026-10-26): the day's exchange rate is recorded
  // together with the remittance itself.
  const rate = parseFloat(req.body.advance_exchange_rate || 0);
  if (!(rate > 0)) {
    flash.set(req, 'error', "Enter today's exchange rate (₹ per 1 unit of the order currency) — it is recorded together with the remittance.");
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const boundsError = fieldValidator.moneyAmount(amount, 'The advance amount') || fieldValidator.exchangeRate(rate);
  if (boundsError) {
    flash.set(req, 'error', boundsError);
    res.redirect(`/orders/${orderId}`);
    return;
  }

  // Owner report (2026-10-27, blocker): a mistyped advance flowed straight
  // into the Order Confirmation. An advance can never exceed the whole
  // order, and an amount that differs from the advance due must be
  // confirmed on purpose.
  const orderForAdvance = await orderRepository.find(orderId);
  const fobTotal = parseFloat(await orderProductRepository.totalFobValue(orderId)) || 0;
  const advancePctNum = parseFloat((orderForAdvance && orderForAdvance.advance_pct) || 0);
  const advanceDue = Math.round(fobTotal * advancePctNum) / 100;
  const currency = (orderForAdvance && orderForAdvance.currency_code) || '';
  const money = (n) => Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  if (fobTotal > 0 && amount > fobTotal + 0.005) {
    flash.set(req, 'error', `${currency} ${money(amount)} is more than the whole order value (${currency} ${money(fobTotal)}). Check the amount the bank received.`);
    res.redirect(`/orders/${orderId}`);
    return;
  }
  if (advanceDue > 0 && Math.abs(amount - advanceDue) > Math.max(1, advanceDue * 0.01) && !req.body.confirm_amount_differs) {
    const pctLabel = advancePctNum.toFixed(2).replace(/\.?0+$/, '');
    flash.set(req, 'error', `The advance due on this order is ${currency} ${money(advanceDue)} (${pctLabel}% of ${currency} ${money(fobTotal)}). You entered ${currency} ${money(amount)} — if that really is what the bank received, tick "The amount received differs from the advance due" and save again.`);
    res.redirect(`/orders/${orderId}`);
    return;
  }
  // QA Round 6, R6-N22: saved together or not at all.
  await orderPaymentStatusRepository.recordLegReceivedWithRate(orderId, 'advance', amount, receivedAt, rate, req.user ? req.user.id : null);
  await auditLogRepository.log(req.user ? req.user.id : null, 'CA_EXCHANGE_RATE_RECORDED', 'order_payment_status', orderId, 'advance_exchange_rate', null, String(rate));
  // External QA report L-23: advance payment recording was never audit-logged.
  await auditLogRepository.log(req.user ? req.user.id : null, 'ADVANCE_PAYMENT_RECORDED', 'order_payment_status', orderId, 'advance_amount', null, String(amount));

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

  flash.set(req, 'success', `${await remittanceStatement(orderId, amount, rate)} ${'Advance remittance recorded. Mark it cleared once your bank confirms receipt.'}`);
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
  // External QA report N-08: this action never reached the audit log,
  // so it never notified anyone either.
  await auditLogRepository.log(user.id, 'CLIENT_PAYMENT_REPORT_REVIEWED', 'client_payment_reports', reportId, null, null, null);
  flash.set(req, 'success', 'Payment report marked reviewed.');
  res.redirect(`/orders/${orderId}`);
}

/** Stage 2->3 gate: advance payment marked cleared in NexaCrest's bank account. */
async function clearAdvancePayment(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  if (!(await stageGateService.isUnlocked(orderId, 3))) {
    flash.set(req, 'error', 'Stage 3 has not been unlocked for this order yet — complete Stage 2 first.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  // Owner decision (2026-10-25): this stage completes only once its
  // document is approved (email off) / sent (email on).
  const stageDocsBlockedBy = await documentGenerationService.stageDocumentBlockedBy(orderId, 3);
  if (stageDocsBlockedBy.length) {
    flash.set(req, 'error', await documentGenerationService.stageDocumentBlockedMessage(stageDocsBlockedBy));
    res.redirect(`/orders/${orderId}`);
    return;
  }
  // QA-5 (GATE-05/GATE-10): the stage-unlock check above only proves Stage
  // 2 was passed — it says nothing about whether an advance amount was
  // ever actually recorded via recordAdvancePayment(), and doesn't catch
  // a nonexistent order id either (which used to reach order.balance_pct
  // below and throw a TypeError / 500). Without the amount check, clearing
  // a never-recorded (NULL) advance would still unlock Stage 4 and
  // provision client portal access with zero money having been received.
  const existingPayment = await orderPaymentStatusRepository.find(orderId);
  if (!existingPayment || existingPayment.advance_amount === null) {
    flash.set(req, 'error', 'Record the advance remittance before marking it cleared.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const clearedAt = str(req.body.advance_cleared_at) || todayYmd();

  const fobTotal = await orderProductRepository.totalFobValue(orderId);
  const order = await orderRepository.find(orderId);
  const balanceAmount = orderPaymentStatusRepository.balanceDue(fobTotal, order.advance_pct, existingPayment);
  const balanceDueDate =
    order.balance_trigger_option === 'A_BEFORE_SHIPMENT'
      ? null // due date is event-triggered (shipment readiness), not a fixed date, for this preset
      : addDaysYmd(todayYmd(), parseInt(order.balance_days, 10));

  await orderPaymentStatusRepository.markAdvanceCleared(orderId, clearedAt, user.id);
  await orderPaymentStatusRepository.setBalanceAmount(orderId, balanceAmount, balanceDueDate);
  // External QA report L-23: advance payment clearing was never audit-logged.
  await auditLogRepository.log(user.id, 'ADVANCE_PAYMENT_CLEARED', 'order_payment_status', orderId, 'advance_cleared_at', null, clearedAt);
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

/**
 * Step 5 LC leg (order.payment_method = 'LC'): records the LC that
 * replaces the cash advance entirely — issuing bank, LC number, LC
 * amount, charges billed to the client, and (optionally) the final LC
 * document. This leg's equivalent of recordAdvancePayment() — purely
 * an informational entry, no stage gate of its own; confirmLcUsable()
 * below is the real gate.
 */
async function recordLcDetails(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  const user = req.user;

  const lcAmount = parseFloat(req.body.lc_amount || 0);
  if (!(lcAmount > 0)) {
    flash.set(req, 'error', 'Enter the LC amount.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const lcChargesRaw = str(req.body.lc_charges_amount);
  if (lcChargesRaw !== '' && (Number.isNaN(parseFloat(lcChargesRaw)) || parseFloat(lcChargesRaw) < 0)) {
    flash.set(req, 'error', 'Enter a valid LC charges amount (or leave it blank).');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const lcNumber = str(req.body.lc_number) || null;
  const lcBankName = str(req.body.lc_bank_name) || null;
  const lcCharges = lcChargesRaw !== '' ? parseFloat(lcChargesRaw) : null;

  const existing = await orderLcDetailRepository.find(orderId);
  let lcFileId = existing ? existing.lc_file_id : null;
  if (req.file && req.file.buffer) {
    try {
      lcFileId = await fileUploadService.handleUpload(
        req,
        'lc_file',
        'order_lc_copy',
        orderStorage.folder(order, 'lc'),
        null,
        orderId,
        user.id,
        null,
        null,
        'Letter of Credit'
      );
    } catch (e) {
      flash.set(req, 'error', `LC details were not saved — the LC file upload failed: ${flash.describeException(e, 'upload failed — check the server error log.')}`);
      res.redirect(`/orders/${orderId}`);
      return;
    }
  }

  await orderLcDetailRepository.recordLegDetails(orderId, lcNumber, lcBankName, lcAmount, lcCharges, lcFileId, user.id);
  await auditLogRepository.log(user.id, 'LC_DETAILS_RECORDED', 'order_lc_details', orderId, 'lc_amount', null, String(lcAmount));

  // Same real-money-moving trigger as recordAdvancePayment() — see
  // docs/schema.sql Section AC.
  await clientRepository.lockData(order.client_id, 'Auto-locked: LC details recorded before client PI-details consent');

  flash.set(req, 'success', 'LC details recorded. Confirm it usable once the advising bank confirms.');
  res.redirect(`/orders/${orderId}`);
}

/**
 * Step 5 LC leg's equivalent of clearAdvancePayment() — the real Stage
 * 2->3 gate when this order's payment_method is LC. Mirrors
 * clearAdvancePayment() exactly (same stage check, same balance
 * computation, same client-portal provisioning), just reading the LC
 * leg's own confirmation instead of the cash advance's.
 */
async function confirmLcUsable(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  if (!(await stageGateService.isUnlocked(orderId, 3))) {
    flash.set(req, 'error', 'Stage 3 has not been unlocked for this order yet — complete Stage 2 first.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  // Owner decision (2026-10-25): this stage completes only once its
  // document is approved (email off) / sent (email on).
  const stageDocsBlockedBy = await documentGenerationService.stageDocumentBlockedBy(orderId, 3);
  if (stageDocsBlockedBy.length) {
    flash.set(req, 'error', await documentGenerationService.stageDocumentBlockedMessage(stageDocsBlockedBy));
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const existingLc = await orderLcDetailRepository.find(orderId);
  if (!existingLc || existingLc.lc_amount === null) {
    flash.set(req, 'error', 'Record the LC details before confirming it usable.');
    res.redirect(`/orders/${orderId}`);
    return;
  }

  const fobTotal = await orderProductRepository.totalFobValue(orderId);
  const order = await orderRepository.find(orderId);
  const balanceAmount = Math.round(fobTotal * (parseFloat(order.balance_pct) / 100) * 100) / 100;
  const balanceDueDate =
    order.balance_trigger_option === 'A_BEFORE_SHIPMENT'
      ? null
      : addDaysYmd(todayYmd(), parseInt(order.balance_days, 10));

  await orderLcDetailRepository.confirmUsable(orderId, user.id);
  await orderPaymentStatusRepository.setBalanceAmount(orderId, balanceAmount, balanceDueDate);
  await auditLogRepository.log(user.id, 'LC_CONFIRMED_USABLE', 'order_lc_details', orderId, 'lc_confirmed_at', null, new Date().toISOString().slice(0, 19).replace('T', ' '));
  await stageGateService.passAndUnlockNext(orderId, 3, user.id);

  const provisionStatus = await clientPortalService.provisionIfNeeded(order.client_id, orderId);

  const baseMessage = 'LC confirmed usable. Stage 4 unlocked — you can now generate the Order Confirmation.';
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
  const user = req.user;
  const before = await orderRepository.find(orderId);
  // QA Round 6, R6-N06: a missing order is "not found", not a crash.
  if (!before) {
    res.status(404).send('Order not found.');
    return;
  }
  // QA Round 5, R5-01: on a locked (fully settled) order this is a
  // locked-order edit like any other — "Override locked data", a reason,
  // and an ORDER_EDITED_WHILE_LOCKED audit row.
  if (before.is_locked && !(await orderEditGuard.allow(req, null, true, str(req.body.reason).trim(), 'orders', orderId, 'production_status'))) {
    res.redirect(`/orders/${orderId}#step-production`);
    return;
  }
  const text = str(req.body.production_status_text);
  if (text !== '' && text !== (before.production_status_text || '')) {
    await orderRepository.setProductionStatus(orderId, text);
    await auditLogRepository.log(user.id, 'PRODUCTION_STATUS_UPDATED', 'orders', orderId, 'production_status_text', before.production_status_text || null, text);
  }

  const shipmentTextInput = str(req.body.est_shipment_date_text);
  const shipmentDateInput = str(req.body.est_shipment_date);
  const resolvedDate = shipmentDateInput !== '' && /^\d{4}-\d{2}-\d{2}$/.test(shipmentDateInput)
    ? shipmentDateInput
    : (before.est_shipment_date || null);
  const resolvedText = shipmentTextInput !== '' ? shipmentTextInput : (before.est_shipment_date_text || '');
  const textChanged = resolvedText !== '' && resolvedText !== (before.est_shipment_date_text || '');
  const dateChanged = (resolvedDate || null) !== (before.est_shipment_date || null);
  if (textChanged || dateChanged) {
    await orderRepository.setEstShipmentDate(orderId, resolvedText, resolvedDate);
    await auditLogRepository.log(user.id, 'EST_SHIPMENT_DATE_UPDATED', 'orders', orderId, 'est_shipment_date_text', before.est_shipment_date_text || null, resolvedText);

    // Export process step 8: "client gets notified - he can able to see
    // this in his portal" — reuses the existing order-comment-to-client
    // channel (orderCommentService.postAsStaff()), which already shows
    // in the client's portal thread AND emails them when an address is
    // on file, rather than building a second, parallel notification
    // mechanism for this one field.
    const dateDisplay = resolvedDate
      ? new Date(resolvedDate + 'T00:00:00').toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
      : resolvedText;
    try {
      await orderCommentService.postAsStaff(orderId, user.id, `The estimated shipment date for your order has been updated to: ${dateDisplay}.`, []);
    } catch (e) {
      // Never block the actual production/shipment-date update over a notification failure.
    }
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
  if (!(await stageGateService.isUnlocked(orderId, 4))) {
    flash.set(req, 'error', 'Stage 4 has not been unlocked for this order yet — complete Stage 3 first.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  // Owner decision (2026-10-25): this stage completes only once its
  // document is approved (email off) / sent (email on).
  const stageDocsBlockedBy = await documentGenerationService.stageDocumentBlockedBy(orderId, 4);
  if (stageDocsBlockedBy.length) {
    flash.set(req, 'error', await documentGenerationService.stageDocumentBlockedMessage(stageDocsBlockedBy));
    res.redirect(`/orders/${orderId}`);
    return;
  }

  // docs/schema.sql Section AU: off by default (today's behaviour) —
  // manage_orders alone, already required to reach this route, is enough.
  // When an admin turns this on, a second, narrower permission is also
  // required, so the override can be restricted to specific roles without
  // touching code.
  if ((await companySettingsRepository.get('oc_ack_override_restricted')) === '1') {
    if (!(req.permissions && req.permissions.override_buyer_acknowledgment)) {
      flash.set(req, 'error', 'Recording a buyer acknowledgment manually has been restricted — you do not hold the required permission.');
      res.redirect(`/orders/${orderId}`);
      return;
    }
  }

  const ack = await orderOcAcknowledgmentRepository.find(orderId);
  if (ack && ack.acknowledged_at !== null) {
    flash.set(req, 'error', "This order's Order Confirmation has already been acknowledged.");
    res.redirect(`/orders/${orderId}`);
    return;
  }
  if (!ack) {
    // No OC send has ever completed for this order (e.g. mail sending is
    // misconfigured, or a send is still stuck awaiting Level-2 approval) —
    // there is nothing yet for recordSent() to have created. The OC must
    // at least have been generated so there's a real document to point
    // this record at.
    const ocDoc = await documentRepository.findLatestForOrderAndTypeCode(orderId, 'OC');
    if (!ocDoc) {
      flash.set(req, 'error', 'Generate the Order Confirmation for this order first.');
      res.redirect(`/orders/${orderId}`);
      return;
    }
    await orderOcAcknowledgmentRepository.ensureRowExists(orderId, ocDoc.id);
  }

  const note = String(req.body.acknowledged_note || '').trim();
  const reasonError = reasonValidator.check(note);
  if (reasonError) {
    flash.set(req, 'error', `Describe the evidence (e.g. quote the buyer's email reply): ${reasonError}`);
    res.redirect(`/orders/${orderId}`);
    return;
  }

  const user = req.user;
  if (!(await orderOcAcknowledgmentRepository.markAcknowledged(orderId, 'staff_recorded_email', note, user.id))) {
    // QA-5 EML-06: lost the race to another acknowledgment path (client
    // portal, or the 48h auto-confirm job) between this action's own
    // pre-check above and this write.
    flash.set(req, 'error', 'This order was already acknowledged by another path a moment ago.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  await stageGateService.passAndUnlockNext(orderId, 4, user.id);
  await auditLogRepository.log(user.id, 'OC_ACKNOWLEDGED_VIA_EMAIL', 'orders', orderId, null, null, null, note);
  flash.set(req, 'success', 'Buyer acknowledgement recorded. Stage 5 (Supplier PO) unlocked.');
  res.redirect(`/orders/${orderId}`);
}

/**
 * Step 16 (Order Feedback) for a non-technical client who tells their
 * NexaCrest contact how the order went over the phone or in person
 * rather than using the portal — there was no way for staff to record
 * that before this. Same gate and the same
 * orderFeedbackRepository.upsert() the client's own
 * clientPortalController.submitFeedback() uses; logged under its own
 * action type so the audit trail still shows it was staff-entered, not
 * a genuine client-portal submission.
 */
async function recordFeedback(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  if (!['shipped_docs_sent', 'complete'].includes(order.status)) {
    flash.set(req, 'error', "Feedback can be recorded once this order's documents have been sent to the client.");
    res.redirect(`/orders/${orderId}`);
    return;
  }

  const rating = parseInt(req.body.rating, 10);
  if (!(rating >= 1 && rating <= 5)) {
    flash.set(req, 'error', 'Choose a rating between 1 and 5 stars.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const comments = String(req.body.comments || '').trim() || null;

  await orderFeedbackRepository.upsert(orderId, order.client_id, rating, comments);

  const user = req.user;
  await auditLogRepository.log(user.id, 'ORDER_FEEDBACK_RECORDED_BY_STAFF', 'orders', orderId, null, null, String(rating), "Feedback recorded by staff on the client's behalf (not submitted via the client portal).");

  flash.set(req, 'success', 'Feedback recorded for this order.');
  res.redirect(`/orders/${orderId}`);
}

/** docs/schema.sql Section AF — per-order, staff-controlled, default off. */
/**
 * QA Round 5, R5-03 (owner decision 2026-10-28, Option A): MD/ED approve
 * the payment terms of an order on a "Requires MD approval" preset — the
 * Quotation can't be generated or sent until they do.
 */
async function approvePaymentTerms(req, res) {
  const orderId = parseInt(req.params.id, 10);
  if (!(await orderRepository.find(orderId))) {
    res.status(404).send('Order not found.');
    return;
  }
  if (!(await paymentTermsApprovalService.isPending(orderId))) {
    flash.set(req, 'warning', 'These payment terms do not need approval, or are already approved.');
  } else {
    await paymentTermsApprovalService.approve(orderId, req.user.id);
    flash.set(req, 'success', 'Payment terms approved — the Quotation can now be generated.');
  }
  res.redirect(`/orders/${orderId}#step-1-quotation-prepare`);
}

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
  // QA-4 P0.5: confirmSupplierSigned() (below) already gates on Stage 5
  // being unlocked, but this function — which actually creates the
  // order_supplier_po row and mints its reference number ahead of
  // generating the SUPPO document — never did, so holding manage_orders
  // alone was enough to save/generate a Supplier PO for an order still at
  // Stage 1, before a Buyer PO, PI or OC even existed.
  if (!(await stageGateService.isUnlocked(orderId, 5))) {
    flash.set(req, 'error', 'Stage 5 has not been unlocked for this order yet — complete Stage 4 first.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const supplierId = parseInt(req.body.supplier_id || 0, 10);
  const supplier = supplierId ? await supplierRepository.find(supplierId) : null;
  if (!supplier || !Number(supplier.is_active)) {
    flash.set(req, 'error', 'Select an active supplier from the Suppliers directory first.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  // A supplier can hold several types; the Supplier PO records the ONE
  // that applies to this order (picked automatically when it has one).
  let supplierType = str(req.body.supplier_type);
  if (supplier.types.length === 1) {
    supplierType = supplier.types[0];
  } else if (supplier.types.length > 0 && !supplier.types.includes(supplierType)) {
    flash.set(req, 'error', `Choose which of ${supplier.supplier_legal_name}'s supplier types applies to this order.`);
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
    supplier_type: supplierType || null,
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
    // Owner decision (2026-10-27): 0% advance is saved as 0, not left blank.
    advance_pct: str(body.advance_pct) !== '' ? str(body.advance_pct) : null,
    advance_amount_inr: str(body.advance_amount_inr) !== '' ? str(body.advance_amount_inr) : null,
    balance_amount_inr: str(body.balance_amount_inr) || null,
    delivery_location: str(body.delivery_location) || null,
    required_delivery_date: str(body.required_delivery_date) || null,
    packing_requirement: str(body.packing_requirement) || null,
  });
  // External QA report L-23: saving Supplier PO terms was never audit-logged.
  await auditLogRepository.log(req.user ? req.user.id : null, 'SUPPLIER_PO_SAVED', 'orders', orderId, 'supplier_po_reference', null, supplierPoReference);

  flash.set(req, 'success', `Supplier PO terms saved (${supplierPoReference}). Generate the Supplier PO document below.`);
  res.redirect(`/orders/${orderId}`);
}

/** Stage 5->6 gate: supplier signs, stamps and returns the Supplier PO. Auto-skips Stage 6 for FOB orders. */
async function confirmSupplierSigned(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  if (!(await stageGateService.isUnlocked(orderId, 5))) {
    flash.set(req, 'error', 'Stage 5 has not been unlocked for this order yet — complete Stage 4 first.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const supplierPo = await orderSupplierPoRepository.findLatestForOrder(orderId);
  if (!supplierPo) {
    flash.set(req, 'error', 'Save the Supplier PO terms and generate the document before confirming signature.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  // 90-item backlog #52: the supplier can't have signed and returned a
  // document that was never actually generated — require the SUPPO
  // document to exist (same "generated" bar as the OC check above), not
  // just the internal order_supplier_po terms row.
  if (!(await documentRepository.findLatestForOrderAndTypeCode(orderId, 'SUPPO'))) {
    flash.set(req, 'error', 'Generate the Supplier PO document for this order before confirming signature.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  // Owner decision (2026-10-25): ...and approved (email off) / sent (email
  // on) — after the more specific "generate it first" checks.
  const stageDocsBlockedBy = await documentGenerationService.stageDocumentBlockedBy(orderId, 5);
  if (stageDocsBlockedBy.length) {
    flash.set(req, 'error', await documentGenerationService.stageDocumentBlockedMessage(stageDocsBlockedBy));
    res.redirect(`/orders/${orderId}`);
    return;
  }
  if (await wetSignatureGuardService.supplierPoBlocked(supplierPo.id)) {
    flash.set(req, 'error', 'Wet-signature required: upload the supplier\'s signed acknowledgment copy before confirming this gate.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  await orderSupplierPoRepository.markSigned(supplierPo.id);
  // External QA report L-23: supplier PO signature confirmation was never audit-logged.
  await auditLogRepository.log(user.id, 'SUPPLIER_PO_SIGNED', 'order_supplier_po', supplierPo.id, 'supplier_po_reference', null, supplierPo.supplier_po_reference || null);
  await stageGateService.passAndUnlockNext(orderId, 5, user.id);
  await stageGateService.maybeAutoSkipFreightStage(orderId, user.id);
  flash.set(req, 'success', 'Supplier PO signature confirmed.');
  res.redirect(`/orders/${orderId}`);
}

/**
 * External QA report R-11 (SPO-PAY-01): validates the paid date is a
 * real calendar date, not more than today, and resolves the amount
 * actually paid (required, must be positive) — before this, neither
 * was checked at all: 2026-02-30 crashed, a date in 2099 was silently
 * accepted, and no amount was ever captured.
 *
 * @returns {{date: string, amount: number}|null} null after already
 *   setting an error flash + redirecting.
 */
function validateSupplierPoPayment(req, res, orderId) {
  let date = str(req.body.paid_date);
  if (date === '') {
    date = todayYmd();
  } else {
    const m = date.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    const isRealDate = m && new Date(`${date}T00:00:00Z`).getUTCFullYear() === Number(m[1])
      && new Date(`${date}T00:00:00Z`).getUTCMonth() + 1 === Number(m[2])
      && new Date(`${date}T00:00:00Z`).getUTCDate() === Number(m[3]);
    if (!isRealDate) {
      flash.set(req, 'error', 'Enter a valid paid date.');
      res.redirect(`/orders/${orderId}`);
      return null;
    }
    if (date > todayYmd()) {
      flash.set(req, 'error', 'The paid date cannot be in the future.');
      res.redirect(`/orders/${orderId}`);
      return null;
    }
  }

  const amountRaw = str(req.body.amount_paid_inr);
  const amount = Number(amountRaw);
  if (amountRaw === '' || Number.isNaN(amount) || amount <= 0) {
    flash.set(req, 'error', 'Enter the amount actually paid.');
    res.redirect(`/orders/${orderId}`);
    return null;
  }

  return { date, amount };
}

/**
 * Order-page polish round: this action only ever checked that a
 * Supplier PO row existed — never that Stage 5 was actually unlocked,
 * the same isUnlocked(5) gate confirmSupplierSigned() already requires.
 * A direct POST on an order still at Stage 1 could mark the supplier's
 * advance as paid before Stage 4 even passed.
 */
async function markSupplierAdvancePaid(req, res) {
  const orderId = parseInt(req.params.id, 10);
  if (!(await stageGateService.isReachedOrPassed(orderId, 5))) {
    flash.set(req, 'error', 'Stage 5 has not been unlocked for this order yet — complete Stage 4 first.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const supplierPo = await orderSupplierPoRepository.findLatestForOrder(orderId);
  if (!supplierPo) {
    flash.set(req, 'error', 'No Supplier PO found for this order.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const validated = validateSupplierPoPayment(req, res, orderId);
  if (!validated) {
    return;
  }
  const { date, amount } = validated;
  await orderSupplierPoRepository.markAdvancePaid(supplierPo.id, date, req.user.id, amount);
  // External QA report N-08: this action never reached the audit log,
  // so it never notified anyone either.
  await auditLogRepository.log(req.user.id, 'SUPPLIER_ADVANCE_PAID', 'order_supplier_po', supplierPo.id, 'advance_paid_date', null, date);
  flash.set(req, 'success', 'Supplier advance payment recorded.');
  res.redirect(`/orders/${orderId}`);
}

/** Order-page polish round: same missing isUnlocked(5) gate as markSupplierAdvancePaid() above — see its docblock. */
async function markSupplierBalancePaid(req, res) {
  const orderId = parseInt(req.params.id, 10);
  if (!(await stageGateService.isReachedOrPassed(orderId, 5))) {
    flash.set(req, 'error', 'Stage 5 has not been unlocked for this order yet — complete Stage 4 first.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const supplierPo = await orderSupplierPoRepository.findLatestForOrder(orderId);
  if (!supplierPo) {
    flash.set(req, 'error', 'No Supplier PO found for this order.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  // Owner report (2026-10-27): the supplier advance was never recorded —
  // the balance waits for it whenever the terms plan an advance.
  if (parseFloat(supplierPo.advance_amount_inr || 0) > 0 && !supplierPo.advance_paid_at) {
    flash.set(req, 'error', `Record the supplier advance (Step 7e) first — the Supplier PO terms plan an advance of ₹${Number(supplierPo.advance_amount_inr).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}.`);
    res.redirect(`/orders/${orderId}#step-7e-supplier-advance-paid`);
    return;
  }
  const validated = validateSupplierPoPayment(req, res, orderId);
  if (!validated) {
    return;
  }
  const { date, amount } = validated;
  await orderSupplierPoRepository.markBalancePaid(supplierPo.id, date, req.user.id, amount);
  // External QA report N-08: this action never reached the audit log,
  // so it never notified anyone either.
  await auditLogRepository.log(req.user.id, 'SUPPLIER_BALANCE_PAID', 'order_supplier_po', supplierPo.id, 'balance_paid_date', null, date);
  flash.set(req, 'success', 'Supplier balance payment recorded.');
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
/**
 * Owner request (2026-10-26, t4): Step 7c — generate (or regenerate) the
 * no-login Supplier Action Link so the supplier can upload their signed PO
 * / acknowledgment themselves; emailed to the supplier when the PO has
 * their email. Staff can still upload it themselves.
 */
async function generateSupplierActionLink(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  const supplierPo = await orderSupplierPoRepository.findLatestForOrder(orderId);
  if (!order || !supplierPo) {
    flash.set(req, 'error', 'Save the Supplier PO terms before generating a Supplier Action Link.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const rawToken = await orderSupplierPoRepository.generateActionToken(supplierPo.id);
  const link = `${env.get('APP_URL', '').replace(/\/+$/, '')}/supplier-action/${rawToken}`;
  const email = String(supplierPo.supplier_email || '').trim();
  if (email !== '') {
    const rendered = await emailTemplateRepository.renderOrDefault(
      'supplier_action_link',
      { '{supplier_po_reference}': String(supplierPo.supplier_po_reference), '{supplier_action_link}': link },
      'NexaCrest — please upload your signed PO {supplier_po_reference}',
      'Hello,\n\n'
        + 'Please upload the signed and stamped purchase order {supplier_po_reference} (or your written acknowledgment of it) here:\n{supplier_action_link}\n\n'
        + 'This link works for 60 days.\n\n'
        + 'NexaCrest International Private Limited'
    );
    await emailService.sendPlainText(email, rendered.subject, rendered.body);
  }
  await auditLogRepository.log(req.user.id, 'SUPPLIER_ACTION_LINK_GENERATED', 'order_supplier_po', supplierPo.id, null, null, null, 'Supplier Action link generated' + (email !== '' ? ' and emailed to the supplier' : ' — no supplier email on the PO, hand this link over directly'));
  flash.set(req, 'success', `Supplier Action link${email !== '' ? ` emailed to ${email}` : ' generated'}: ${link}`);
  res.redirect(`/orders/${orderId}#step-7c-supplier-po-attach`);
}

/** Owner request (2026-10-26, t4): Step 7c — the supplier did not acknowledge the PO; reason required, audit-logged. */
async function skipSupplierPoAck(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const supplierPo = await orderSupplierPoRepository.findLatestForOrder(orderId);
  if (!supplierPo) {
    flash.set(req, 'error', 'Save the Supplier PO terms first.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const reason = str(req.body.skip_reason);
  const error = reasonValidator.check(reason);
  if (error) {
    flash.set(req, 'error', error);
    res.redirect(`/orders/${orderId}#step-7c-supplier-po-attach`);
    return;
  }
  await orderSupplierPoRepository.markAckSkipped(supplierPo.id, req.user.id, reason);
  await auditLogRepository.log(req.user.id, 'SUPPLIER_PO_ACK_SKIPPED', 'order_supplier_po', supplierPo.id, 'ack_skipped_at', null, new Date().toISOString().slice(0, 19).replace('T', ' '), reason);
  flash.set(req, 'success', 'Recorded that the supplier did not acknowledge — Step 7d (confirm the PO) is now open.');
  res.redirect(`/orders/${orderId}#step-7d-supplier-po-signed`);
}

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
      orderStorage.forReceived(order, 'supplier_po'),
      null,
      orderId,
      req.user.id,
      null,
      'supplier',
      'Supplier PO acknowledgment'
    );
    const documentId = await orderSupplierPoDocumentRepository.attach(supplierPo.id, fileId);
    // External QA report N-08: this action never reached the audit
    // log, so it never notified anyone either.
    await auditLogRepository.log(req.user.id, 'SUPPLIER_PO_DOCUMENT_UPLOADED', 'order_supplier_po_documents', documentId, null, null, null);
    flash.set(req, 'success', 'Supplier PO acknowledgment attached.');
  } catch (e) {
    flash.setErrorFromException(req, e);
  }
  res.redirect(`/orders/${orderId}`);
}

/**
 * Stage 6 (CFR/CIF only): record NexaCrest's agreed freight/insurance
 * terms before generating the FDN. Order-page polish round: this
 * action had no stage check at all — on an order still at Stage 1, a
 * direct POST with just manage_shipping could save freight terms
 * before Stage 5 ever passed. Mirrors the same isUnlocked(6) guard
 * recordFreightPayment() already uses below.
 */
async function saveFreightTerms(req, res) {
  const orderId = parseInt(req.params.id, 10);
  if (!(await stageGateService.isUnlocked(orderId, 6))) {
    flash.set(req, 'error', 'Stage 6 has not been unlocked for this order yet — complete Stage 5 first.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const body = req.body;
  // Owner request (2026-10-27): the forwarder is picked from the Logistics
  // Partners directory (like the supplier in 7a from the Suppliers
  // directory); its name and contact are copied onto this order.
  // QA Round 5, R5-29: a wrong number is refused with a message, never a database error.
  const numberError = fieldValidator.decimal(str(body.confirmed_freight_rate), 'Confirmed freight rate')
    || fieldValidator.decimal(str(body.insurance_amount), 'Insurance amount');
  if (numberError) {
    flash.set(req, 'error', numberError);
    res.redirect(`/orders/${orderId}#step-9a-freight-terms`);
    return;
  }
  const partner = await logisticsPartnerRepository.find(parseInt(body.freight_forwarder_partner_id, 10) || 0);
  if (!partner || !Number(partner.is_active)) {
    flash.set(req, 'error', 'Choose the freight forwarder from the Logistics Partners directory.');
    res.redirect(`/orders/${orderId}#step-9a-freight-terms`);
    return;
  }
  const contact = [partner.contact_person_name, partner.contact_person_phone || partner.phone, partner.email].filter(Boolean).join(' · ');
  await orderFreightRepository.upsert(orderId, {
    confirmed_freight_rate: str(body.confirmed_freight_rate) || null,
    // Q-R5-2: only a CIF seller insures — nothing is stored for FOB/CFR.
    insurance_amount: String(((await orderRepository.find(orderId)) || {}).incoterm_code || '').toUpperCase() === 'CIF'
      ? (str(body.insurance_amount) || null)
      : null,
    freight_forwarder_name: partner.partner_name,
    freight_forwarder_contact: contact || null,
    freight_forwarder_partner_id: partner.id,
  });
  // External QA report N-08: this action never reached the audit log,
  // so it never notified anyone either.
  await auditLogRepository.log(req.user.id, 'ORDER_FREIGHT_TERMS_SAVED', 'order_freight', orderId, null, null, null);
  flash.set(req, 'success', 'Freight & insurance terms saved. Generate the Debit Note below.');
  res.redirect(`/orders/${orderId}`);
}

/**
 * External QA report L-35 (OOS-01): on an order still at Stage 1, this
 * would accept a freight payment with no stage check at all — freight
 * is a Stage 6 ("Freight Payment") activity, so Stage 6 must actually be
 * unlocked first (Stage 5 — Supplier PO — passed).
 */
async function recordFreightPayment(req, res) {
  const orderId = parseInt(req.params.id, 10);
  if (!(await stageGateService.isUnlocked(orderId, 6))) {
    flash.set(req, 'error', 'Stage 6 has not been unlocked for this order yet — complete Stage 5 first.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const amount = parseFloat(req.body.freight_amount || 0);
  const receivedAt = str(req.body.freight_received_at) || todayYmd();
  if (!(amount > 0)) {
    flash.set(req, 'error', 'Enter the freight & insurance amount actually received.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  // Owner request (2026-10-26): the day's exchange rate is recorded
  // together with the remittance itself.
  const rate = parseFloat(req.body.freight_exchange_rate || 0);
  if (!(rate > 0)) {
    flash.set(req, 'error', "Enter today's exchange rate (₹ per 1 unit of the order currency) — it is recorded together with the remittance.");
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const boundsError = fieldValidator.moneyAmount(amount, 'The freight amount') || fieldValidator.exchangeRate(rate);
  if (boundsError) {
    flash.set(req, 'error', boundsError);
    res.redirect(`/orders/${orderId}`);
    return;
  }

  // QA Round 6, R6-N22: saved together or not at all.
  await orderPaymentStatusRepository.recordLegReceivedWithRate(orderId, 'freight', amount, receivedAt, rate, req.user ? req.user.id : null);
  await auditLogRepository.log(req.user ? req.user.id : null, 'CA_EXCHANGE_RATE_RECORDED', 'order_payment_status', orderId, 'freight_exchange_rate', null, String(rate));
  // External QA report N-08: this action never reached the audit log,
  // so it never notified anyone either.
  await auditLogRepository.log(req.user.id, 'FREIGHT_PAYMENT_RECORDED', 'order_payment_status', orderId, 'freight_amount', null, String(amount));
  flash.set(req, 'success', `${await remittanceStatement(orderId, amount, rate)} ${'Freight remittance recorded. Mark it cleared once your bank confirms receipt.'}`);
  res.redirect(`/orders/${orderId}`);
}

/** Stage 6->7 gate: freight & insurance payment cleared in NexaCrest's bank account. */
async function clearFreightPayment(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  if (!(await stageGateService.isUnlocked(orderId, 6))) {
    flash.set(req, 'error', 'Stage 6 has not been unlocked for this order yet — complete Stage 5 first.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  // Owner decision (2026-10-25): this stage completes only once its
  // document is approved (email off) / sent (email on).
  const stageDocsBlockedBy = await documentGenerationService.stageDocumentBlockedBy(orderId, 6);
  if (stageDocsBlockedBy.length) {
    flash.set(req, 'error', await documentGenerationService.stageDocumentBlockedMessage(stageDocsBlockedBy));
    res.redirect(`/orders/${orderId}`);
    return;
  }
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
  // External QA report L-35 (OOS-01): had no stage check at all —
  // packing is a Stage 7 activity (see this function's own docblock), so
  // Stage 7 must actually be unlocked first (Stage 6 — freight payment
  // cleared — passed).
  if (!(await stageGateService.isUnlocked(orderId, 7))) {
    flash.set(req, 'error', 'Stage 7 has not been unlocked for this order yet — complete Stage 6 first.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
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
    actual_quantity_unit: str(body.actual_quantity_unit) || (summary.comparable ? summary.unit : null),
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
        orderStorage.forReceived(order, 'packing_bl'),
        null,
        orderId,
        req.user.id,
        null,
        'Buyer',
        'Quantity shortfall approval'
      );
      await orderPackingRepository.attachBuyerApproval(orderId, fileId);
    } catch (e) {
      flash.set(req, 'error', `Packing figures saved, but the approval upload failed: ${flash.describeException(e, 'upload failed — check the server error log.')}`);
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
  const orderProducts = await orderProductRepository.forOrder(orderId);

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
      // Taken from the crate's product, never from the form (t4).
      hs_code: orderCrateRepository.hsCodeFor(orderProducts, str(descs[i]) || null),
    });
  }
  await orderCrateRepository.replaceForOrder(orderId, crates);

  // External QA report N-08: this action never reached the audit log,
  // so it never notified anyone either.
  await auditLogRepository.log(req.user.id, 'ORDER_PACKING_SAVED', 'order_packing', orderId, null, null, null);
  flash.set(req, 'success', 'Packing figures and crate breakdown saved. Generate the Packing List below.');
  res.redirect(`/orders/${orderId}`);
}

/**
 * Stage 7: record shipping/vessel details, ahead of generating the BL
 * Instruction Sheet.
 *
 * External QA report L-35 (OOS-01): had no stage check at all — an
 * order still at Stage 1 could have vessel/container details stored
 * here. Stage 7 must actually be unlocked first (Stage 6 — freight
 * payment cleared — passed).
 */
async function saveShipping(req, res) {
  const orderId = parseInt(req.params.id, 10);
  if (!(await stageGateService.isUnlocked(orderId, 7))) {
    flash.set(req, 'error', 'Stage 7 has not been unlocked for this order yet — complete Stage 6 first.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const body = req.body;
  // QA Round 5, R5-28: the server applies the form's own "1 or more" rule.
  const countError = fieldValidator.wholeNumber(str(body.container_count), 'Number of containers', 1);
  if (countError) {
    flash.set(req, 'error', countError);
    res.redirect(`/orders/${orderId}`);
    return;
  }
  await orderShippingRepository.upsert(orderId, {
    shipping_line: str(body.shipping_line) || null,
    vessel_name: str(body.vessel_name) || null,
    voyage_number: str(body.voyage_number) || null,
    etd: str(body.etd) || null,
    eta: str(body.eta) || null,
    container_type: str(body.ship_container_type) || null,
    container_count: str(body.container_count) || null,
    container_no: str(body.container_no) || null,
    seal_no: str(body.seal_no) || null,
  });
  // External QA report N-08: this action never reached the audit log,
  // so it never notified anyone either.
  await auditLogRepository.log(req.user.id, 'ORDER_SHIPPING_DETAILS_SAVED', 'order_shipping', orderId, null, null, null);
  flash.set(req, 'success', 'Shipping details saved. Generate the BL Instruction Sheet below.');
  res.redirect(`/orders/${orderId}`);
}

/**
 * Export process steps 9h-9j: the BL Instruction Sheet + packing list
 * have been sent to the CHA — this logs the draft BL the CHA sent
 * back, as the next review round for this order. Refuses a new upload
 * while the current round is still 'pending' — the existing draft must
 * be approved or sent back for revision first, so at most one round is
 * ever awaiting a decision at a time.
 */
async function uploadDraftBl(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  const user = req.user;

  const latest = await orderDraftBlReviewRepository.latestForOrder(orderId);
  if (latest && latest.status === 'pending') {
    flash.set(req, 'error', `Round ${latest.round_number}'s draft is still awaiting your review — approve it or request a revision before logging another.`);
    res.redirect(`/orders/${orderId}`);
    return;
  }

  if (!req.file || !req.file.buffer) {
    flash.set(req, 'error', 'Attach the draft BL the CHA sent before logging this round.');
    res.redirect(`/orders/${orderId}`);
    return;
  }

  let fileId;
  try {
    fileId = await fileUploadService.handleUpload(
      req,
      'draft_bl',
      'draft_bl',
      orderStorage.forReceived(order, 'packing_bl'),
      null,
      orderId,
      user.id,
      null,
      'CHA',
      'Draft Bill of Lading'
    );
  } catch (e) {
    flash.set(req, 'error', `Draft BL upload failed: ${flash.describeException(e, 'upload failed — check the server error log.')}`);
    res.redirect(`/orders/${orderId}`);
    return;
  }

  const roundId = await orderDraftBlReviewRepository.createRound(orderId, fileId, user.id);
  await orderShippingRepository.attachDraftBl(orderId, fileId);
  await auditLogRepository.log(user.id, 'DRAFT_BL_UPLOADED', 'order_draft_bl_reviews', roundId, null, null, String(orderId));
  flash.set(req, 'success', 'Draft BL logged for review.');
  res.redirect(`/orders/${orderId}`);
}

/** Export process step 9j: staff's decision on a CHA draft BL round — approve it, or send it back with notes for a revision. */
async function reviewDraftBl(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const roundId = parseInt(req.params.roundId, 10);
  const round = await orderDraftBlReviewRepository.find(roundId);
  if (!round || Number(round.order_id) !== orderId) {
    res.status(404).send('Draft BL round not found for this order.');
    return;
  }
  if (round.status !== 'pending') {
    flash.set(req, 'error', 'This round has already been reviewed.');
    res.redirect(`/orders/${orderId}`);
    return;
  }

  const decision = str(req.body.decision);
  if (!['approved', 'revision_requested'].includes(decision)) {
    flash.set(req, 'error', 'Select a decision.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const notes = str(req.body.notes) || null;
  if (decision === 'revision_requested' && notes === null) {
    flash.set(req, 'error', "Explain what needs to change before sending this draft back for revision.");
    res.redirect(`/orders/${orderId}`);
    return;
  }

  const user = req.user;
  await orderDraftBlReviewRepository.review(roundId, decision, notes, user.id);
  if (decision === 'approved') {
    await orderShippingRepository.approveDraftBl(orderId, user.id);
  }
  await auditLogRepository.log(user.id, 'DRAFT_BL_REVIEWED', 'order_draft_bl_reviews', roundId, 'status', 'pending', decision);
  flash.set(req, 'success', decision === 'approved' ? `Round ${round.round_number} draft BL approved.` : `Revision requested on round ${round.round_number} — upload the CHA's corrected draft once received.`);
  res.redirect(`/orders/${orderId}`);
}

/** Stage 7->8 gate: BL issued (vessel departs) — also the date CI must match. */
async function recordBlIssued(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  if (!(await stageGateService.isUnlocked(orderId, 7))) {
    flash.set(req, 'error', 'Stage 7 has not been unlocked for this order yet — complete Stage 6 first.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  // Owner decision (2026-10-25): this stage completes only once its
  // document is approved (email off) / sent (email on).
  const stageDocsBlockedBy = await documentGenerationService.stageDocumentBlockedBy(orderId, 7);
  if (stageDocsBlockedBy.length) {
    flash.set(req, 'error', await documentGenerationService.stageDocumentBlockedMessage(stageDocsBlockedBy));
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const blNumber = str(req.body.bl_number);
  const blDate = str(req.body.bl_date) || todayYmd();
  if (blNumber === '') {
    flash.set(req, 'error', 'BL number is required to confirm this gate.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  await orderShippingRepository.recordBl(orderId, blNumber, blDate);
  // External QA report L-23: BL issuance was never audit-logged.
  await auditLogRepository.log(user.id, 'BL_ISSUED', 'orders', orderId, 'bl_number', null, blNumber);

  if (req.file && req.file.buffer) {
    const order = await orderRepository.find(orderId);
    try {
      const fileId = await fileUploadService.handleUpload(
        req,
        'bl_copy',
        'bl_copy',
        orderStorage.forReceived(order, 'packing_bl'),
        null,
        orderId,
        user.id,
        null,
        'Shipping Line',
        'Scanned Bill of Lading'
      );
      await orderShippingRepository.attachBlCopy(orderId, fileId);
    } catch (e) {
      flash.set(req, 'error', `Bill of Lading recorded (${blNumber}), but the copy upload failed: ${flash.describeException(e, 'upload failed — check the server error log.')}`);
      res.redirect(`/orders/${orderId}`);
      return;
    }
  }

  await stageGateService.passAndUnlockNext(orderId, 7, user.id);
  flash.set(req, 'success', `Bill of Lading recorded (${blNumber}). Stage 8 (Commercial Invoice) unlocked.`);
  res.redirect(`/orders/${orderId}`);
}

/** Lets staff attach the scanned BL copy later, when it wasn't available yet at bl-issued time. */
async function attachBlCopy(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    flash.set(req, 'error', 'Order not found.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  try {
    const fileId = await fileUploadService.handleUpload(
      req,
      'bl_copy',
      'bl_copy',
      orderStorage.forReceived(order, 'packing_bl'),
      null,
      orderId,
      req.user.id,
      null,
      'Shipping Line',
      'Scanned Bill of Lading'
    );
    await orderShippingRepository.attachBlCopy(orderId, fileId);
    // External QA report N-08: this action never reached the audit
    // log, so it never notified anyone either.
    await auditLogRepository.log(req.user.id, 'ORDER_BL_COPY_ATTACHED', 'order_shipping', orderId, null, null, null);
    flash.set(req, 'success', 'Scanned BL copy attached.');
  } catch (e) {
    flash.set(req, 'error', `Could not attach the scanned BL copy: ${flash.describeException(e, 'upload failed — check the server error log.')}`);
  }
  res.redirect(`/orders/${orderId}`);
}

/**
 * Owner decision 2026-10-29: records the shipping bill — its number,
 * date and the customs exchange rate printed on it. The CI and Debit
 * Note go to Zoho Books at that rate, so the GST return matches the
 * shipping bill. Can be corrected later; an invoice already in Zoho
 * Books keeps the rate it was sent at.
 */
async function recordShippingBill(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    flash.set(req, 'error', 'Order not found.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const number = str(req.body.shipping_bill_no);
  const date = str(req.body.shipping_bill_date);
  const rate = str(req.body.customs_exchange_rate);
  const m = date.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  const d = m ? new Date(`${date}T00:00:00Z`) : null;
  const isRealDate = d && d.getUTCFullYear() === Number(m[1]) && d.getUTCMonth() + 1 === Number(m[2]) && d.getUTCDate() === Number(m[3]);
  let error = null;
  if (number === '' || [...number].length > 30) {
    error = 'Enter the shipping bill number (up to 30 characters).';
  } else if (!isRealDate || date > todayYmd()) {
    error = 'Enter the shipping bill date (not in the future).';
  } else if (!/^\d+(\.\d+)?$/.test(rate) || Math.round(Number(rate) * 10000) <= 0 || Number(rate) > 100000) { // QA Round 6, R6-N23: at least 0.0001 once stored
    error = `Enter the customs exchange rate printed on the shipping bill (rupees for 1 ${order.currency_code || 'unit'}).`;
  }
  if (error !== null) {
    flash.set(req, 'error', error);
    res.redirect(`/orders/${orderId}#shipping-bill`);
    return;
  }
  const before = await orderShippingRepository.find(orderId);
  await orderShippingRepository.recordShippingBill(orderId, number, date, Number(rate));
  await auditLogRepository.log(req.user.id, 'ORDER_SHIPPING_BILL_RECORDED', 'order_shipping', orderId, 'customs_exchange_rate', before?.customs_exchange_rate ?? null, rate, `Shipping bill ${number} dated ${date}`);
  flash.set(req, 'success', `Shipping bill ${number} recorded (customs rate ${rate}).`);
  res.redirect(`/orders/${orderId}#shipping-bill`);
}

async function recordScannedBlSent(req, res) {
  const orderId = parseInt(req.params.id, 10);
  await orderShippingRepository.recordScannedBlSent(orderId);
  // External QA report N-08: this action never reached the audit log,
  // so it never notified anyone either.
  await auditLogRepository.log(req.user.id, 'ORDER_SCANNED_BL_SENT', 'order_shipping', orderId, null, null, null);
  flash.set(req, 'success', 'Scanned BL copy marked as sent to buyer.');
  res.redirect(`/orders/${orderId}`);
}

async function recordBalancePayment(req, res) {
  const orderId = parseInt(req.params.id, 10);
  // 90-item backlog #53: this action had no stage gate at all (unlike
  // clearBalancePayment just below, which does check Stage 8) and no
  // check that the Commercial Invoice was ever actually generated — the
  // buyer can't be remitting against an invoice they were never sent.
  // Same "generated" bar as confirmSupplierSigned's SUPPO check above.
  if (!(await stageGateService.isUnlocked(orderId, 8))) {
    flash.set(req, 'error', 'Stage 8 has not been unlocked for this order yet.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  if (!(await documentRepository.findLatestForOrderAndTypeCode(orderId, 'CI'))) {
    flash.set(req, 'error', 'Generate the Commercial Invoice for this order before recording the balance remittance.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const amount = parseFloat(req.body.balance_amount || 0);
  const receivedAt = str(req.body.balance_received_at) || todayYmd();
  if (!(amount > 0)) {
    flash.set(req, 'error', 'Enter the balance amount actually received.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  // Owner request (2026-10-26): the day's exchange rate is recorded
  // together with the remittance itself.
  const rate = parseFloat(req.body.balance_exchange_rate || 0);
  if (!(rate > 0)) {
    flash.set(req, 'error', "Enter today's exchange rate (₹ per 1 unit of the order currency) — it is recorded together with the remittance.");
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const boundsError = fieldValidator.moneyAmount(amount, 'The balance amount') || fieldValidator.exchangeRate(rate);
  if (boundsError) {
    flash.set(req, 'error', boundsError);
    res.redirect(`/orders/${orderId}`);
    return;
  }

  // QA Round 6, R6-N22: saved together or not at all.
  await orderPaymentStatusRepository.recordLegReceivedWithRate(orderId, 'balance', amount, receivedAt, rate, req.user ? req.user.id : null);
  await auditLogRepository.log(req.user ? req.user.id : null, 'CA_EXCHANGE_RATE_RECORDED', 'order_payment_status', orderId, 'balance_exchange_rate', null, String(rate));
  // External QA report L-23: balance payment recording was never audit-logged.
  await auditLogRepository.log(req.user ? req.user.id : null, 'BALANCE_PAYMENT_RECORDED', 'order_payment_status', orderId, 'balance_amount', null, String(amount));
  flash.set(req, 'success', `${await remittanceStatement(orderId, amount, rate)} ${'Balance remittance recorded. Mark it cleared once your bank confirms receipt.'}`);
  res.redirect(`/orders/${orderId}`);
}

/** Stage 8->9 gate: balance payment cleared — triggers COO prep / document despatch / closure. */
async function clearBalancePayment(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  if (!(await stageGateService.isUnlocked(orderId, 8))) {
    flash.set(req, 'error', 'Stage 8 has not been unlocked for this order yet — complete Stage 7 first.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  // Owner decision (2026-10-25): this stage completes only once its
  // document is approved (email off) / sent (email on).
  const stageDocsBlockedBy = await documentGenerationService.stageDocumentBlockedBy(orderId, 8);
  if (stageDocsBlockedBy.length) {
    flash.set(req, 'error', await documentGenerationService.stageDocumentBlockedMessage(stageDocsBlockedBy));
    res.redirect(`/orders/${orderId}`);
    return;
  }
  // QA-5 (GATE-04): being unlocked only proves Stage 7 was passed — it
  // says nothing about whether a balance amount was ever actually
  // recorded (normally set automatically by clearAdvancePayment(), but an
  // admin stage override could reach Stage 8 without it). Without this,
  // clearing a never-recorded (NULL) balance would still unlock Stage 9.
  const existingPayment = await orderPaymentStatusRepository.find(orderId);
  if (!existingPayment || existingPayment.balance_amount === null) {
    flash.set(req, 'error', 'The balance amount has not been recorded for this order yet — it cannot be marked cleared.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  // External QA report N-05: clearAdvancePayment() pre-fills balance_amount
  // automatically, so the check above alone lets a user clear the balance
  // without ever actually recording the remittance via
  // recordBalancePayment() — balance_remittance_received_at then stays
  // NULL forever, and every later export-benefit claim is refused with a
  // misleading message (see orderFinancialsController.addExportBenefit's
  // own gate on that field).
  if (existingPayment.balance_remittance_received_at === null) {
    flash.set(req, 'error', 'Record the balance remittance before marking it cleared.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const clearedAt = str(req.body.balance_cleared_at) || todayYmd();
  await orderPaymentStatusRepository.markBalanceCleared(orderId, clearedAt, user.id);
  // External QA report L-23: balance payment clearing was never audit-logged.
  await auditLogRepository.log(user.id, 'BALANCE_PAYMENT_CLEARED', 'order_payment_status', orderId, 'balance_cleared_at', null, clearedAt);
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

/**
 * External QA report L-35 (OOS-01): "the advance FIRC, exchange rate
 * and INR-credited amount (the advance itself was never recorded)" —
 * none of these 3 per-leg CA fields checked that the leg's own payment
 * (advance/balance/freight_amount) had actually been recorded first.
 * Shared by every FIRC/exchange-rate/INR-actual handler below.
 */
function legAmountRecorded(paymentStatus, leg) {
  return !!paymentStatus && paymentStatus[`${leg}_amount`] !== null && paymentStatus[`${leg}_amount`] !== undefined;
}

async function recordAdvanceInrActual(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  const amount = parseFloat(req.body.advance_inr_actual || 0);
  if (!(amount > 0)) {
    flash.set(req, 'error', 'Enter the actual INR amount credited to the bank.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const paymentStatus = await orderPaymentStatusRepository.find(orderId);
  if (!legAmountRecorded(paymentStatus, 'advance')) {
    flash.set(req, 'error', 'Record the advance payment itself before entering its INR actual amount.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  if (!(await caFyLockGuard.allow(req, paymentStatus.advance_cleared_at ?? null, 'order_payment_status', orderId, 'advance_inr_actual'))) {
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
  // QA Round 6, R6-N04: nothing to remove is not a removal.
  if ((((await orderPaymentStatusRepository.find(orderId)) || {}).advance_inr_actual ?? null) === null) {
    flash.set(req, 'error', 'No advance INR actual amount is recorded on this order — nothing was removed.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
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
  const paymentStatus = await orderPaymentStatusRepository.find(orderId);
  if (!legAmountRecorded(paymentStatus, 'balance')) {
    flash.set(req, 'error', 'Record the balance payment itself before entering its INR actual amount.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  if (!(await caFyLockGuard.allow(req, paymentStatus.balance_cleared_at ?? null, 'order_payment_status', orderId, 'balance_inr_actual'))) {
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
  // QA Round 6, R6-N04: nothing to remove is not a removal.
  if ((((await orderPaymentStatusRepository.find(orderId)) || {}).balance_inr_actual ?? null) === null) {
    flash.set(req, 'error', 'No balance INR actual amount is recorded on this order — nothing was removed.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
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
  const paymentStatus = await orderPaymentStatusRepository.find(orderId);
  if (!legAmountRecorded(paymentStatus, 'freight')) {
    flash.set(req, 'error', 'Record the freight payment itself before entering its INR actual amount.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  if (!(await caFyLockGuard.allow(req, paymentStatus.freight_cleared_at ?? null, 'order_payment_status', orderId, 'freight_inr_actual'))) {
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
  // QA Round 6, R6-N04: nothing to remove is not a removal.
  if ((((await orderPaymentStatusRepository.find(orderId)) || {}).freight_inr_actual ?? null) === null) {
    flash.set(req, 'error', 'No freight INR actual amount is recorded on this order — nothing was removed.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
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
// CA / Accounting module (Phase 2, Batch 3 #3) — exchange rate, one per
// settlement leg (advance/balance/freight), for the register's forex
// gain/loss column — each leg can genuinely clear on a different date
// with a different market rate, so a single order-wide rate (the
// original Phase 2 design) overstated/understated gain/loss for
// whichever leg didn't match it. Same inr_actual_edit gating as Phase 1.
// ----------------------------------------------------------------

async function recordLegExchangeRate(req, res, leg) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  const rate = parseFloat(req.body[`${leg}_exchange_rate`] || 0);
  if (!(rate > 0)) {
    flash.set(req, 'error', 'Enter the exchange rate for this leg.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const rateError = fieldValidator.exchangeRate(rate);
  if (rateError) {
    flash.set(req, 'error', rateError);
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const ops = await orderPaymentStatusRepository.find(orderId);
  if (!legAmountRecorded(ops, leg)) {
    flash.set(req, 'error', `Record the ${leg} payment itself before entering its exchange rate.`);
    res.redirect(`/orders/${orderId}`);
    return;
  }
  if (!(await caFyLockGuard.allow(req, ops[`${leg}_cleared_at`] ?? null, 'order_payment_status', orderId, `${leg}_exchange_rate`))) {
    res.redirect(`/orders/${orderId}`);
    return;
  }
  // Owner decision (2026-10-26, t4): the rate is recorded together with the
  // remittance; changing it afterwards is a correction of a typing mistake
  // and needs a reason, kept in the audit log.
  const previous = ops[`${leg}_exchange_rate`] ?? null;
  const reason = str(req.body.correction_reason).trim();
  if (previous !== null) {
    const error = reasonValidator.check(reason);
    if (error !== null) {
      flash.set(req, 'error', `Correcting a recorded exchange rate needs a reason. ${error}`);
      res.redirect(`/orders/${orderId}`);
      return;
    }
  }
  await orderPaymentStatusRepository.setLegExchangeRate(orderId, leg, rate, user.id);
  await auditLogRepository.log(user.id, previous !== null ? 'CA_EXCHANGE_RATE_CORRECTED' : 'CA_EXCHANGE_RATE_RECORDED', 'order_payment_status', orderId, `${leg}_exchange_rate`, previous !== null ? String(previous) : null, String(rate), previous !== null ? reason : null);
  flash.set(req, 'success', `${leg.charAt(0).toUpperCase()}${leg.slice(1)} exchange rate ${previous !== null ? 'corrected' : 'recorded'}.`);
  res.redirect(`/orders/${orderId}`);
}

async function recordAdvanceExchangeRate(req, res) {
  await recordLegExchangeRate(req, res, 'advance');
}

async function recordBalanceExchangeRate(req, res) {
  await recordLegExchangeRate(req, res, 'balance');
}

async function recordFreightExchangeRate(req, res) {
  await recordLegExchangeRate(req, res, 'freight');
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
  const paymentStatus = await orderPaymentStatusRepository.find(orderId);
  if (!legAmountRecorded(paymentStatus, 'advance')) {
    flash.set(req, 'error', 'Record the advance payment itself before entering its FIRC/eBRC reference.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  if (!(await caFyLockGuard.allow(req, paymentStatus.advance_cleared_at ?? null, 'order_payment_status', orderId, 'advance_firc_reference'))) {
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
  const paymentStatus = await orderPaymentStatusRepository.find(orderId);
  if (!legAmountRecorded(paymentStatus, 'balance')) {
    flash.set(req, 'error', 'Record the balance payment itself before entering its FIRC/eBRC reference.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  if (!(await caFyLockGuard.allow(req, paymentStatus.balance_cleared_at ?? null, 'order_payment_status', orderId, 'balance_firc_reference'))) {
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
  const paymentStatus = await orderPaymentStatusRepository.find(orderId);
  if (!legAmountRecorded(paymentStatus, 'freight')) {
    flash.set(req, 'error', 'Record the freight payment itself before entering its FIRC/eBRC reference.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  if (!(await caFyLockGuard.allow(req, paymentStatus.freight_cleared_at ?? null, 'order_payment_status', orderId, 'freight_firc_reference'))) {
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const receivedAt = str(req.body.freight_firc_received_at) || todayYmd();
  await orderPaymentStatusRepository.setFreightFirc(orderId, reference, receivedAt);
  await auditLogRepository.log(user.id, 'CA_FIRC_RECORDED', 'order_payment_status', orderId, 'freight_firc_reference', null, reference);
  flash.set(req, 'success', 'Freight FIRC/eBRC reference recorded.');
  res.redirect(`/orders/${orderId}`);
}

/**
 * Order-page polish round: all four BL-originals/endorsement actions
 * below (Stage 9 — "COO received + BL originals endorsed", per
 * stageGateService's own docblock) had no stage check at all — a
 * direct POST with just manage_shipping could record/endorse BL
 * originals on an order still at Stage 1. Same isUnlocked(9) guard
 * closeOrder() already requires further down this file.
 */
async function recordBlOriginalsReceived(req, res) {
  const orderId = parseInt(req.params.id, 10);
  if (!(await stageGateService.isUnlocked(orderId, 9))) {
    flash.set(req, 'error', 'Stage 9 has not been unlocked for this order yet — complete Stage 8 first.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const count = parseInt(req.body.bl_originals_count || 3, 10);
  await orderShippingRepository.recordBlOriginalsReceived(orderId, count || 3);
  // External QA report N-08: this action never reached the audit log,
  // so it never notified anyone either.
  await auditLogRepository.log(req.user.id, 'ORDER_BL_ORIGINALS_RECEIVED', 'order_shipping', orderId, 'bl_originals_received_count', null, String(count || 3));
  flash.set(req, 'success', 'Original BL copies recorded as received from CHA.');
  res.redirect(`/orders/${orderId}`);
}

/** Order-page polish round: same missing isUnlocked(9) gate — see recordBlOriginalsReceived()'s docblock above. */
async function recordBlEndorsed(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  if (!(await stageGateService.isUnlocked(orderId, 9))) {
    flash.set(req, 'error', 'Stage 9 has not been unlocked for this order yet — complete Stage 8 first.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  await orderShippingRepository.recordBlEndorsed(orderId, user.id);
  // External QA report N-08: this action never reached the audit log,
  // so it never notified anyone either.
  await auditLogRepository.log(user.id, 'ORDER_BL_ENDORSED', 'order_shipping', orderId, null, null, null);
  flash.set(req, 'success', 'Original BLs marked as endorsed by NexaCrest.');
  res.redirect(`/orders/${orderId}`);
}

/**
 * docs/schema.sql Section BC — the CRUD save behind the BL Endorsement
 * print feature. Pre-fills onto order_shipping's own vessel_name/
 * voyage_number/bl_number when the form is first shown (see the view),
 * but what's actually generated always reads from this independently-
 * editable row, never back from order_shipping.
 */
/** Order-page polish round: same missing isUnlocked(9) gate — see recordBlOriginalsReceived()'s docblock above. */
async function saveBlEndorsement(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  if (!(await stageGateService.isUnlocked(orderId, 9))) {
    flash.set(req, 'error', 'Stage 9 has not been unlocked for this order yet — complete Stage 8 first.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  await orderBlEndorsementRepository.upsert(
    orderId,
    {
      bl_number: String(req.body.bl_number || '').trim() || null,
      vessel_voyage: String(req.body.vessel_voyage || '').trim() || null,
      port_of_loading: String(req.body.port_of_loading || '').trim() || null,
      port_of_discharge: String(req.body.port_of_discharge || '').trim() || null,
      date_of_endorsement: String(req.body.date_of_endorsement || '').trim() || null,
    },
    user.id
  );
  // External QA report N-08: this action never reached the audit log,
  // so it never notified anyone either.
  await auditLogRepository.log(user.id, 'ORDER_BL_ENDORSEMENT_SAVED', 'order_bl_endorsements', orderId, null, null, null);
  flash.set(req, 'success', 'BL Endorsement details saved.');
  res.redirect(`/orders/${orderId}`);
}

/** Order-page polish round: same missing isUnlocked(9) gate — see recordBlOriginalsReceived()'s docblock above. */
async function generateBlEndorsement(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  if (!(await stageGateService.isUnlocked(orderId, 9))) {
    flash.set(req, 'error', 'Stage 9 has not been unlocked for this order yet — complete Stage 8 first.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  try {
    await documentGenerationService.generateBlEndorsement(orderId, user.id);
    // External QA report N-08: this action never reached the audit
    // log, so it never notified anyone either.
    await auditLogRepository.log(user.id, 'ORDER_BL_ENDORSEMENT_GENERATED', 'orders', orderId, null, null, null);
    flash.set(req, 'success', 'BL Endorsement generated — print it and physically endorse all 3 original BLs.');
  } catch (err) {
    flash.setErrorFromException(req, err);
  }
  res.redirect(`/orders/${orderId}`);
}

/** Stage 9 gate: COO received, BL originals endorsed, complete document set couriered to buyer -> order closed. */
/**
 * 90-item backlog Phase 6 Item 3 — BLI + COOPREP are now a real,
 * server-side hard gate (see documentGenerationService.closureBlockedBy()'s
 * docblock for why this applies unconditionally, dispute or not). The
 * one deliberately narrow escape hatch mirrors every other locked-data
 * override in this controller: edit_locked_data permission (Super Admin
 * bypasses automatically) plus a mandatory reason, both re-checked here
 * rather than trusted from the form.
 */
async function closeOrder(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  if (!(await stageGateService.isUnlocked(orderId, 9))) {
    flash.set(req, 'error', 'Stage 9 has not been unlocked for this order yet — complete Stage 8 first.');
    res.redirect(`/orders/${orderId}`);
    return;
  }

  const overrideRequested = !!req.body.override_gate;
  const overrideReason = str(req.body.override_reason).trim();
  let allowOverride = false;
  if (overrideRequested) {
    if (!req.permissions || !req.permissions.edit_locked_data) {
      flash.set(req, 'error', 'You do not have permission to override the closure document requirement.');
      res.redirect(`/orders/${orderId}`);
      return;
    }
    if (overrideReason.length < 10) {
      flash.set(req, 'error', 'A reason (at least 10 characters) is required to close an order without both required documents approved.');
      res.redirect(`/orders/${orderId}`);
      return;
    }
    allowOverride = true;
  }

  if (!allowOverride) {
    const blockedBy = await documentGenerationService.closureBlockedBy(orderId);
    if (blockedBy.length > 0) {
      flash.set(req, 'error', `Before closing: generate and get approval for ${blockedBy.join(' and ')}. A user with the "Override locked data" permission can force this through with a reason if absolutely necessary.`);
      res.redirect(`/orders/${orderId}`);
      return;
    }
  }

  const trackingNumber = str(req.body.courier_tracking_number);
  if (trackingNumber === '') {
    flash.set(req, 'error', 'Courier tracking number is required to close the order.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  await orderShippingRepository.recordCourierSent(orderId, trackingNumber);
  await stageGateService.passAndUnlockNext(orderId, 9, user.id);
  await orderRepository.markShippedDocsSent(orderId);
  if (allowOverride) {
    await auditLogRepository.log(user.id, 'CLOSE_ORDER_DOCUMENT_GATE_OVERRIDE', 'orders', orderId, 'status', null, 'shipped_docs_sent', overrideReason);
  }
  flash.set(req, 'success', `Documents sent to buyer (tracking: ${trackingNumber}) — this order now shows as done to the client. Apply for any government export benefits, then use "Mark Fully Settled" below once there's nothing left outstanding.`);
  res.redirect(`/orders/${orderId}`);
}

/**
 * docs/schema.sql Section BU — export process step 11e, the real final
 * lock. Only reachable from 'shipped_docs_sent' (documents must already
 * be out — see closeOrder() above). Blocks on any government export
 * benefit claim linked to this order that's still awaiting receipt
 * (step 11d: "once we receive the benefits — record in system — then
 * we mark the order a complete close"), with the same edit_locked_data
 * + reason escape hatch as the closeOrder() document-gate override, for
 * the rare case a benefit genuinely never arrives and the order needs
 * closing anyway.
 */
async function markFullySettled(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  if (order.status !== 'shipped_docs_sent') {
    flash.set(req, 'error', 'This order must be in "Documents Sent" status (closeOrder, Stage 9) before it can be marked fully settled.');
    res.redirect(`/orders/${orderId}`);
    return;
  }

  const overrideRequested = !!req.body.override_gate;
  const overrideReason = str(req.body.override_reason).trim();
  let allowOverride = false;
  if (overrideRequested) {
    if (!req.permissions || !req.permissions.edit_locked_data) {
      flash.set(req, 'error', 'You do not have permission to override the outstanding-benefits requirement.');
      res.redirect(`/orders/${orderId}`);
      return;
    }
    if (overrideReason.length < 10) {
      flash.set(req, 'error', 'A reason (at least 10 characters) is required to fully settle an order with a benefit claim still outstanding.');
      res.redirect(`/orders/${orderId}`);
      return;
    }
    allowOverride = true;
  }

  if (!allowOverride) {
    // Owner decision (2026-10-27): Step 13g — every eligible scheme
    // received, or skipped with a reason (a skipped scheme's open claim no
    // longer blocks).
    const benefits = await benefitSettlementService.state(orderId);
    if (benefits.waiting.length > 0) {
      flash.set(req, 'error', `This order has a government export benefit claim still awaiting receipt (${benefits.waiting.join(', ')}) — record it as received in Step 13g, or skip it there with a reason. A user with the "Override locked data" permission can also force this through with a reason if absolutely necessary.`);
      res.redirect(`/orders/${orderId}#step-13g-benefits`);
      return;
    }

    const complianceTasks = await orderComplianceTaskRepository.forOrder(orderId);
    const outstandingComplianceTasks = complianceTasks.filter((ct) => ct.status === 'not_started' || ct.status === 'pending_approval');
    if (outstandingComplianceTasks.length > 0) {
      flash.set(req, 'error', 'This order still has an unresolved Compliance Checklist task — approve or mark it Not Applicable (with a reason) first, or a user with the "Override locked data" permission can force this through with a reason if absolutely necessary.');
      res.redirect(`/orders/${orderId}#compliance-checklist`);
      return;
    }

    const exportBenefitSchemes = await orderExportBenefitSchemeRepository.forOrder(orderId);
    const pendingExportBenefitSchemes = exportBenefitSchemes.filter((s) => s.status === 'pending');
    if (pendingExportBenefitSchemes.length > 0) {
      flash.set(req, 'error', 'This order still has a government export-benefit scheme with no eligibility decision — mark it Eligible or Not Eligible first, or a user with the "Override locked data" permission can force this through with a reason if absolutely necessary.');
      res.redirect(`/orders/${orderId}#order-financials`);
      return;
    }

    if (benefits.unclaimed.length > 0) {
      flash.set(req, 'error', `An eligible government export benefit has not been claimed yet (${benefits.unclaimed.join(', ')}) — record the claim and the money received in Step 13g, or skip it there with a reason. A user with the "Override locked data" permission can also force this through with a reason if absolutely necessary.`);
      res.redirect(`/orders/${orderId}#step-13g-benefits`);
      return;
    }
  }

  await orderRepository.markComplete(orderId);
  await auditLogRepository.log(user.id, 'ORDER_FULLY_SETTLED', 'orders', orderId, 'status', 'shipped_docs_sent', 'complete', allowOverride ? overrideReason : null);
  flash.set(req, 'success', 'Order fully settled and closed.');
  // Owner decision (2026-10-28): every settled order gets its final
  // CAFIN automatically — all payments, bills, expenses and benefits
  // are final by now. Never blocks settlement: if it fails, staff
  // are told to generate it from the CAFIN box.
  try {
    const cafin = await documentGenerationService.generateCaInternalAnnexure(orderId, user.id);
    await auditLogRepository.log(user.id, 'CA_INTERNAL_DOC_AUTO_GENERATED', 'orders', orderId, 'document_reference', null, cafin.document_reference, 'Generated automatically when the order was fully settled');
    flash.set(req, 'success', `Final internal financial annexure generated automatically: ${cafin.document_reference}.`);
  } catch (e) {
    console.error(`[CAFIN AUTO-GENERATION FAILED] order=${orderId} — ${e && e.message}`);
    flash.set(req, 'warning', 'The order is settled, but the internal financial annexure (CAFIN) could not be generated automatically — generate it from the CAFIN box at the top of the order.');
  }
  res.redirect(`/orders/${orderId}`);
}

/**
 * Compliance/pre-closure task checklist (docs/schema.sql Section AR) —
 * "the person who has permission to close the order must able to see this
 * otherwise no meaning for this." Gated on close_orders, same as the view
 * itself; re-checked here (not just route-level/UI-level) per the existing
 * defense-in-depth convention (see wetSignatureGuardService).
 */
async function updateComplianceTask(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  const respond = (ok, message) => {
    flash.set(req, ok ? 'success' : 'error', message);
    res.redirect(`/orders/${orderId}#compliance-checklist`);
  };
  if (!req.permissions || !req.permissions.close_orders) {
    respond(false, 'You do not have permission to update the compliance checklist.');
    return;
  }

  // Owner request (2026-10-26, t4): one Save button per checklist card
  // posts every row as tasks[i][...]; each row lands in its own task.
  if (req.body.tasks && typeof req.body.tasks === 'object') {
    await saveComplianceChecklist(req, res, orderId, user, Object.values(req.body.tasks), str(req.body.phase));
    return;
  }

  const taskTypeId = parseInt(req.body.task_type_id, 10) || 0;
  const status = str(req.body.status);
  if (taskTypeId <= 0 || !Object.prototype.hasOwnProperty.call(orderComplianceTaskRepository.STATUSES, status)) {
    respond(false, 'Select a valid compliance task and status.');
    return;
  }

  const skipReason = str(req.body.skip_reason);
  if (status === 'skipped' && skipReason === '') {
    respond(false, 'A reason is required to mark a compliance task as not applicable/skipped.');
    return;
  }

  await orderComplianceTaskRepository.setStatus(orderId, taskTypeId, status, status === 'skipped' ? skipReason : null, user.id);
  await auditLogRepository.log(user.id, 'COMPLIANCE_TASK_STATUS_UPDATED', 'orders', orderId, `compliance_task:${taskTypeId}`, null, status, status === 'skipped' ? skipReason : null);
  respond(true, `Saved — ${orderComplianceTaskRepository.STATUSES[status]}.`);

}

/**
 * Saves a whole compliance checklist card at once. All-or-nothing: if any
 * row is invalid (unknown status, or Skipped without a reason) nothing is
 * written. Only rows whose status or reason changed are updated, so
 * untouched rows keep their resolved-by stamp.
 */
async function saveComplianceChecklist(req, res, orderId, user, rows, phase) {
  const anchor = phase === 'shipment' ? 'compliance-checklist-shipment' : 'compliance-checklist';
  const fail = (message) => {
    flash.set(req, 'error', message);
    res.redirect(`/orders/${orderId}#${anchor}`);
  };
  const current = new Map();
  for (const ct of await orderComplianceTaskRepository.forOrder(orderId)) {
    current.set(Number(ct.task_type_id), ct);
  }

  const changes = [];
  for (const row of rows) {
    const taskTypeId = parseInt((row || {}).task_type_id, 10) || 0;
    const status = str(row.status);
    let reason = str(row.skip_reason).trim();
    if (!current.has(taskTypeId) || !Object.prototype.hasOwnProperty.call(orderComplianceTaskRepository.STATUSES, status)) {
      fail('Select a valid status for every compliance task.');
      return;
    }
    if (status === 'skipped' && reason.length < reasonValidator.MIN_LENGTH) {
      fail(`"${current.get(taskTypeId).task_type_name}" is marked Skipped — give a reason of at least ${reasonValidator.MIN_LENGTH} characters. Nothing was saved.`);
      return;
    }
    reason = status === 'skipped' ? reason : null;
    const before = current.get(taskTypeId);
    if (before.status !== status || (status === 'skipped' && String(before.skip_reason || '') !== reason)) {
      changes.push([taskTypeId, before.status, status, reason]);
    }
  }

  for (const [taskTypeId, from, status, reason] of changes) {
    await orderComplianceTaskRepository.setStatus(orderId, taskTypeId, status, reason, user.id);
    await auditLogRepository.log(user.id, 'COMPLIANCE_TASK_STATUS_UPDATED', 'orders', orderId, `compliance_task:${taskTypeId}`, from, status, reason);
  }
  flash.set(req, 'success', changes.length === 0 ? 'Nothing changed on the checklist.' : `Checklist saved — ${changes.length} task(s) updated.`);
  res.redirect(`/orders/${orderId}#${anchor}`);
}

/**
 * Repeat-order shortcut: skips every currently-outstanding (not yet
 * approved/skipped) compliance task on this order in one submit, with
 * one shared reason — the same deliberate "skipped requires a reason"
 * rule as a single task, just applied to the whole remaining set at
 * once instead of clicking through each one.
 */
async function markAllComplianceTasksNotApplicable(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  if (!req.permissions || !req.permissions.close_orders) {
    flash.set(req, 'error', 'You do not have permission to update the compliance checklist.');
    res.redirect(`/orders/${orderId}`);
    return;
  }

  const skipReason = str(req.body.skip_reason).trim();
  if (skipReason === '') {
    flash.set(req, 'error', 'A reason is required to mark the remaining compliance tasks Not Applicable.');
    res.redirect(`/orders/${orderId}#compliance-checklist`);
    return;
  }

  // Each checklist card (Step 7 / Step 10) offers this for its own phase.
  const phase = str(req.body.phase);
  const tasks = await orderComplianceTaskRepository.forOrder(orderId);
  const outstanding = tasks.filter((ct) => (ct.status === 'not_started' || ct.status === 'pending_approval')
    && (phase === '' || ct.phase === phase));
  for (const ct of outstanding) {
    await orderComplianceTaskRepository.setStatus(orderId, ct.task_type_id, 'skipped', skipReason, user.id);
    await auditLogRepository.log(user.id, 'COMPLIANCE_TASK_STATUS_UPDATED', 'orders', orderId, `compliance_task:${ct.task_type_id}`, ct.status, 'skipped', skipReason);
  }
  flash.set(req, 'success', `${outstanding.length} outstanding compliance task(s) marked Not Applicable.`);
  res.redirect(`/orders/${orderId}#compliance-checklist`);
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

  if (!['active', 'shipped_docs_sent', 'complete', 'disputed', 'lost', 'cancelled'].includes(newStatus)) {
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
  // 90-item backlog Phase 6 Item 3 — once a real Bill of Lading exists,
  // the goods have actually shipped, so "lost" (buyer walked away,
  // nothing ever shipped) no longer describes this order's reality.
  // Closes a side door around Close Order's own BLI/COOPREP requirement:
  // without this, staff could mark an already-shipped order "lost" and
  // skip that paperwork entirely.
  const shipping = await orderShippingRepository.find(orderId);
  if (shipping && shipping.bl_number) {
    flash.set(req, 'error', 'This order already has a Bill of Lading on file — the goods have shipped, so it can no longer be marked "lost". Use Close Order instead.');
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

/**
 * External QA report M-09: "Formal order cancellation workflow (reason,
 * approval, money/refund handling, audit) distinct from 'mark lost'."
 * markLost() above only covers a pre-money walk-away (status must still
 * be 'active', no BL yet) — this covers cancelling an order that's
 * already further along. A request here never changes the order's own
 * status by itself; only approveCancellation() does, once a privileged
 * second person signs off (close_orders to request, edit_locked_data to
 * decide — same maker-checker separation as every other approval here).
 */
async function requestCancellation(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  if (['shipped_docs_sent', 'complete', 'lost', 'cancelled'].includes(order.status)) {
    flash.set(req, 'error', `An order that is already "${order.status}" cannot have a cancellation requested.`);
    res.redirect(`/orders/${orderId}`);
    return;
  }
  if (await orderCancellationRepository.findPendingForOrder(orderId)) {
    flash.set(req, 'error', 'This order already has a cancellation request awaiting a decision.');
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

  const cancellationId = await orderCancellationRepository.create(orderId, reason, user.id);
  // External QA report N-07: nobody who can actually approve a
  // cancellation (edit_locked_data, the same tier the approval decision
  // itself is gated on) was ever told one was pending — only the
  // catch-all notification email below fired.
  for (const approverUserId of await permissionRepository.usersWithPermission('edit_locked_data')) {
    await notificationRepository.create(approverUserId, null, 'order_cancellation_requested', orderId, `Cancellation requested for order ${order.order_reference} — awaiting approval.`);
  }
  await auditLogRepository.log(user.id, 'ORDER_CANCELLATION_REQUESTED', 'order_cancellations', cancellationId, null, null, reason);
  flash.set(req, 'success', 'Cancellation requested — awaiting approval.');
  res.redirect(`/orders/${orderId}`);
}

async function approveCancellation(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const cancellationId = parseInt(req.params.cancellationId, 10);
  const user = req.user;
  const cancellation = await orderCancellationRepository.find(cancellationId);
  if (!cancellation || cancellation.order_id !== orderId || cancellation.status !== 'pending') {
    flash.set(req, 'error', 'That cancellation request is no longer pending.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  // QA-5 maker-checker: the person who requested the cancellation must
  // not also be the one who approves it. External QA report N-04: unlike
  // other maker-checker checks, this one does NOT exempt
  // manage_permissions holders — only Super Admin — because every default
  // role that can approve a cancellation (Admin, MD, ED) also holds
  // manage_permissions, which made the rule a no-op.
  if (cancellation.requested_by === user.id && !(await makerCheckerGuard.selfApprovalAllowedStrict(user.id))) {
    flash.set(req, 'error', 'You requested this cancellation — a different privileged person must approve it.');
    res.redirect(`/orders/${orderId}`);
    return;
  }

  let refundStatus = String(req.body.refund_status || 'not_applicable');
  if (!['not_applicable', 'pending', 'completed'].includes(refundStatus)) {
    refundStatus = 'not_applicable';
  }
  const refundAmountRaw = str(req.body.refund_amount);
  const refundAmount = refundAmountRaw !== '' && !Number.isNaN(Number(refundAmountRaw)) ? Number(refundAmountRaw) : null;
  const decisionNotes = str(req.body.decision_notes) || null;

  await orderCancellationRepository.approve(cancellationId, user.id, decisionNotes, refundAmount, refundStatus);
  const freshOrder = await orderRepository.find(orderId);
  await orderRepository.cancel(orderId);
  // External QA report N-07: the requester was never told what happened
  // to their own request — only the catch-all notification email below
  // fired.
  await notificationRepository.create(cancellation.requested_by, null, 'order_cancellation_approved', orderId, `Your cancellation request for order ${freshOrder.order_reference} was approved.`);
  await auditLogRepository.log(user.id, 'ORDER_CANCELLATION_APPROVED', 'orders', orderId, 'status', freshOrder.status, 'cancelled', decisionNotes);
  flash.set(req, 'success', 'Cancellation approved — order is now cancelled.');
  res.redirect(`/orders/${orderId}`);
}

async function rejectCancellation(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const cancellationId = parseInt(req.params.cancellationId, 10);
  const user = req.user;
  const cancellation = await orderCancellationRepository.find(cancellationId);
  if (!cancellation || cancellation.order_id !== orderId || cancellation.status !== 'pending') {
    flash.set(req, 'error', 'That cancellation request is no longer pending.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  // External QA report N-04: strict variant, no manage_permissions
  // exemption — see approveCancellation() above.
  if (cancellation.requested_by === user.id && !(await makerCheckerGuard.selfApprovalAllowedStrict(user.id))) {
    flash.set(req, 'error', 'You requested this cancellation — a different privileged person must decide on it.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const decisionNotes = str(req.body.decision_notes);
  const reasonError = reasonValidator.check(decisionNotes);
  if (reasonError) {
    flash.set(req, 'error', reasonError);
    res.redirect(`/orders/${orderId}`);
    return;
  }

  await orderCancellationRepository.reject(cancellationId, user.id, decisionNotes);
  // External QA report N-07: the requester was never told what happened
  // to their own request — only the catch-all notification email below
  // fired.
  const freshOrder = await orderRepository.find(orderId);
  await notificationRepository.create(cancellation.requested_by, null, 'order_cancellation_rejected', orderId, `Your cancellation request for order ${freshOrder.order_reference} was rejected: ${decisionNotes}`);
  await auditLogRepository.log(user.id, 'ORDER_CANCELLATION_REJECTED', 'order_cancellations', cancellationId, null, 'pending', 'rejected', decisionNotes);
  flash.set(req, 'success', 'Cancellation request rejected — order is unaffected.');
  res.redirect(`/orders/${orderId}`);
}

/**
 * Recording that the refund money has actually moved is a separate,
 * lower-bar step from the approval decision itself (the bank transfer
 * happens outside this system on its own timeline) — gated on
 * close_orders, same tier as requesting the cancellation, not the
 * stricter edit_locked_data the approval decision itself needs.
 */
async function completeCancellationRefund(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const cancellationId = parseInt(req.params.cancellationId, 10);
  const user = req.user;
  const cancellation = await orderCancellationRepository.find(cancellationId);
  if (!cancellation || cancellation.order_id !== orderId || cancellation.status !== 'approved' || cancellation.refund_status !== 'pending') {
    flash.set(req, 'error', 'There is no pending refund to complete for this cancellation.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const refundNotes = str(req.body.refund_notes) || null;

  await orderCancellationRepository.completeRefund(cancellationId, user.id, refundNotes);
  await auditLogRepository.log(user.id, 'ORDER_CANCELLATION_REFUND_COMPLETED', 'order_cancellations', cancellationId, 'refund_status', 'pending', 'completed', refundNotes);
  flash.set(req, 'success', 'Refund marked as completed.');
  res.redirect(`/orders/${orderId}`);
}

module.exports = {
  index, archivedIndex, archive, unarchive, duplicateOrder, create, store, show, addAssignee, removeAssignee, downloadDossier, generatePiFormLink, generateBuyerActionLink, generatePaymentActionLink,
  editDetails, updateDetails, addProduct, updateProduct, deleteProduct, duplicateProduct,
  recordBuyerPo, correctBuyerPoReceivedDate, uploadBuyerPoDocument, recordAdvancePayment, markPaymentReportReviewed, clearAdvancePayment,
  recordLcDetails, confirmLcUsable, updateProductionStatus,
  recordOcAcknowledgment, recordFeedback, setDisputeButtonVisible, saveSupplierPo, skipPiForm, confirmSupplierSigned, uploadSupplierPoDocument, generateSupplierActionLink, skipSupplierPoAck, markSupplierAdvancePaid, markSupplierBalancePaid,
  saveFreightTerms, recordFreightPayment, clearFreightPayment,
  savePacking, saveShipping, uploadDraftBl, reviewDraftBl, recordBlIssued, attachBlCopy, recordShippingBill, recordScannedBlSent,
  recordBalancePayment, clearBalancePayment, recordBlOriginalsReceived, recordBlEndorsed,
  saveBlEndorsement, generateBlEndorsement,
  approvePaymentTerms, closeOrder, markFullySettled, overrideStatusLock, markLost, updateComplianceTask, markAllComplianceTasksNotApplicable,
  requestCancellation, approveCancellation, rejectCancellation, completeCancellationRefund,
  recordAdvanceInrActual, deleteAdvanceInrActual, recordBalanceInrActual, deleteBalanceInrActual,
  recordFreightInrActual, deleteFreightInrActual,
  recordAdvanceExchangeRate, recordBalanceExchangeRate, recordFreightExchangeRate, recordAdvanceFirc, recordBalanceFirc, recordFreightFirc,
  uploadAdditionalDocument, deleteAdditionalDocument, showRemovedAdditionalDocuments,
};
