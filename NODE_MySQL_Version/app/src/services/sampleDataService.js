'use strict';

const crypto = require('crypto');
const fs = require('fs');

const clientRepository = require('../repositories/clientRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const lookupRepository = require('../repositories/lookupRepository');
const orderAnnexureRepository = require('../repositories/orderAnnexureRepository');
const orderCrateRepository = require('../repositories/orderCrateRepository');
const orderFreightRepository = require('../repositories/orderFreightRepository');
const orderPackingRepository = require('../repositories/orderPackingRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const orderProductRepository = require('../repositories/orderProductRepository');
const orderRepository = require('../repositories/orderRepository');
const orderShippingRepository = require('../repositories/orderShippingRepository');
const orderStageRepository = require('../repositories/orderStageRepository');
const orderSupplierPoRepository = require('../repositories/orderSupplierPoRepository');
const sampleDataRepository = require('../repositories/sampleDataRepository');
const supplierRepository = require('../repositories/supplierRepository');
const referenceNumberService = require('./referenceNumberService');
const documentGenerationService = require('./documentGenerationService');
const stageGateService = require('./stageGateService');
const amendmentService = require('./amendmentService');
const amendmentRepository = require('../repositories/amendmentRepository');
const reviewWorkflowService = require('./reviewWorkflowService');
const documentReviewRepository = require('../repositories/documentReviewRepository');
const clientPortalService = require('./clientPortalService');
const piIntakeRepository = require('../repositories/piIntakeRepository');
const disputeRepository = require('../repositories/disputeRepository');
const disputeDocumentRepository = require('../repositories/disputeDocumentRepository');
const orderBuyerPoDocumentRepository = require('../repositories/orderBuyerPoDocumentRepository');
const orderSupplierPoDocumentRepository = require('../repositories/orderSupplierPoDocumentRepository');
const fileStoreRepository = require('../repositories/fileStoreRepository');
const userRepository = require('../repositories/userRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const workingDaysCalculator = require('./workingDaysCalculator');
const env = require('../config/env');

/**
 * Phase E follow-up — "can we have some sample records to play with, and
 * clear them through the UI whenever, any number of times, without ever
 * touching real data?" (user request, 2026-09-19).
 *
 * Deliberately reuses the exact same repository/service calls a real user's
 * request would make (orderRepository.create(), stageGateService, the same
 * documentGenerationService.generate() every real QT/PI/OC goes through,
 * amendmentService, reviewWorkflowService, clientPortalService, ...) rather
 * than inventing a parallel fake-data insertion path — the point of a
 * playground is that it behaves exactly like the real system, because it IS
 * the real system, just flagged is_sample_data = 1 and cleanly removable.
 * See sampleDataRepository for the deletion side.
 *
 * Scope ("cover all" follow-up, 2026-09-23 — extends the Task #17 scope to
 * every scenario a fixed 3-order walkthrough couldn't reach):
 *   - Client A (Aurora Décor Imports) — TWO orders, demonstrating multiple
 *     orders under one client (same buyer_inquiry_ref, since that's derived
 *     from the client's own unique number): Order A1 left at Stage 1 with no
 *     documents ("start from scratch"), Order A2 pushed to Stage 2 passed /
 *     awaiting PI ("a second, independently-progressing order").
 *   - Client B (Meridian Home Collections) — the single most heavily
 *     instrumented order: buyer PO reference AND an actual signed-copy
 *     upload, a PI-stage intake submission deliberately left pending staff
 *     review, client-portal auto-provisioning the moment advance clears, an
 *     Order-Confirmation review/reject/regenerate/approve cycle, a full
 *     payment-terms amendment lifecycle (request -> MD-approve -> generate
 *     -> signed copy uploaded -> active), a Supplier PO with its
 *     acknowledgment copy uploaded, the FOB auto-skip of the Freight
 *     Payment stage, and a quantity-shortfall-with-buyer-approval packing
 *     scenario — left at Stage 8, deliberately not closed.
 *   - Client C (Silverleaf Global Trading) — unchanged full 9-stage CIF
 *     closure (still the only order that pays through the CFR/CIF-only
 *     Freight Payment stage, contrasting with Order B's FOB auto-skip), now
 *     followed by a post-closure dispute: raised, evidence uploaded, and
 *     resolved.
 *   - Client D (Copperfield Trading Co.) — a new client whose only order is
 *     quoted and then marked lost.
 *
 * Further extended (2026-09-23 follow-up — Annexure A / product-line variety
 * had no sample coverage at all: every order above has include_annexure_a =
 * 0) with a fifth client and five more orders, judged individually on
 * whether the scenario actually needs Annexure A (a technical-specification
 * sheet — free text plus optional images) or is purely a product-line/
 * quantity variation that doesn't:
 *   - Client E (Regal Memorials & Monuments Inc.) — four orders:
 *     - Order E1: the SAME product at two different sizes as separate line
 *       items, WITH Annexure A giving each size its own written
 *       specification (no images — a size difference doesn't need a photo).
 *     - Order E2: two different products (a monument blank + a headstone),
 *       WITH Annexure A giving each its own specification AND a reference
 *       image.
 *     - Order E3: a single "complete monument set" product line, WITH
 *       Annexure A describing its components (base/die/cap/vase) and a
 *       reference image.
 *     - Order E4: two line items that are the same kind of product at two
 *       different set sizes (a 10-piece set vs a 12-piece set) — NO
 *       Annexure A, since this is a quantity/packaging distinction, not a
 *       technical drawing one.
 *   - Client F (Granite Quarry Direct Traders) — Order F1: a single raw,
 *     unprocessed block line item (HS code 2516.11, not the finished-goods
 *     default 6802.93) — NO Annexure A, since a raw block has no finish or
 *     technical spec to document.
 */

async function isLoaded() {
  return sampleDataRepository.isLoaded();
}

/** @returns {Promise<Array<object>>} */
async function summary() {
  return sampleDataRepository.summary();
}

/**
 * @returns {Promise<{clients:number, orders:number}>}
 * @throws {Error} if sample data is already loaded — clear it first.
 */
async function load(userId) {
  if (await sampleDataRepository.isLoaded()) {
    throw new Error('Sample data is already loaded. Clear it first if you want a fresh copy.');
  }

  const incoterms = await lookupRepository.incoterms();
  const fobIncoterm = incoterms[0] || null;
  const cifIncoterm = incoterms.find((t) => String(t.code).toUpperCase() === 'CIF') || incoterms[incoterms.length - 1] || null;

  const currencies = await lookupRepository.currencies();
  const currency = currencies[0] || null;
  const loadingPorts = await lookupRepository.ports('loading');
  const loadingPort = loadingPorts[0] || null;
  const presets = await lookupRepository.paymentPresets();
  const standardPreset = presets.find((p) => p.preset_name !== 'Established Buyer — Post-BL') || presets[0] || null;
  const establishedPreset = presets.find((p) => p.preset_name === 'Established Buyer — Post-BL') || standardPreset;

  if (!fobIncoterm || !cifIncoterm || !currency || !standardPreset || !establishedPreset) {
    throw new Error('No incoterm/currency/payment preset configured yet — set those up first (Company Settings), then load sample data.');
  }

  const reviewerUserId = await pickReviewerUserId(userId);
  const supplierId = await createSampleSupplier();

  // --- Client A: two orders under one client ---
  const clientAId = await createSampleClient(
    '[SAMPLE] Aurora Décor Imports',
    '124 Harbor Lane, Sample District, Test Country',
    userId
  );
  await createSampleOrder(clientAId, fobIncoterm, currency, loadingPort, standardPreset, userId, [
    ['Hand-carved decorative planter, Model A', '30 x 30 x 45 cm', 'Polished'],
    ['Hand-carved decorative planter, Model B', '25 x 25 x 40 cm', 'Matte'],
  ]);
  // Left exactly here — Stage 1, no documents yet — the
  // "start from scratch" sample order.

  const orderA2Id = await createSampleOrder(clientAId, fobIncoterm, currency, loadingPort, standardPreset, userId, [
    ['Hand-carved decorative planter, Model C', '35 x 35 x 50 cm', 'Polished'],
  ]);
  await advanceSampleOrderToStage2Pending(orderA2Id, userId);

  // --- Client B: FOB, "Standard — New Buyer" preset — the single order
  // carrying every scenario a fixed 3-order set couldn't reach ---
  const clientBId = await createSampleClient(
    '[SAMPLE] Meridian Home Collections',
    '77 Riverside Court, Sample District, Test Country',
    userId,
    'meridian.buyer@sample-client.test'
  );
  const orderB1Id = await createSampleOrder(clientBId, fobIncoterm, currency, loadingPort, standardPreset, userId, [
    ['Outdoor stone planter, large', '60 x 60 x 70 cm', 'Natural finish'],
    ['Outdoor stone planter, medium', '40 x 40 x 50 cm', 'Natural finish'],
    ['Garden bench, stone composite', '150 x 45 x 45 cm', 'Sandblasted'],
  ]);
  await advanceSampleOrderBJourney(orderB1Id, clientBId, supplierId, userId, reviewerUserId);

  // --- Client C: CIF, "Established Buyer — Post-BL" preset — the only
  // order that runs through the CFR/CIF Freight Payment stage, pushed all
  // the way to closure, then a post-closure dispute ---
  const clientCId = await createSampleClient(
    '[SAMPLE] Silverleaf Global Trading',
    '9 Customs Quay, Sample Port District, Test Country',
    userId
  );
  const orderC1Id = await createSampleOrder(clientCId, cifIncoterm, currency, loadingPort, establishedPreset, userId, [
    ['Natural stone kerb stone, large', '100 x 30 x 15 cm', 'Flamed'],
    ['Natural stone kerb stone, small', '60 x 30 x 15 cm', 'Flamed'],
  ], 'Rotterdam, Netherlands');
  await advanceSampleOrderToStage9(orderC1Id, supplierId, userId);
  await addSampleDispute(orderC1Id, userId);

  // --- Client D: a lost order ---
  const clientDId = await createSampleClient(
    '[SAMPLE] Copperfield Trading Co.',
    '15 Deadline Drive, Sample District, Test Country',
    userId
  );
  const orderD1Id = await createSampleOrder(clientDId, fobIncoterm, currency, loadingPort, standardPreset, userId, [
    ['Polished marble tabletop, round', '90 cm dia x 3 cm', 'High polish'],
  ]);
  await advanceAndLoseSampleOrder(orderD1Id, userId);

  // --- Client E: Annexure A / product-line variety (2026-09-23) ---
  const clientEId = await createSampleClient(
    '[SAMPLE] Regal Memorials & Monuments Inc.',
    '48 Cemetery Row, Sample Memorial District, Test Country',
    userId
  );

  // Order E1: same product, two sizes, Annexure A with a written spec per size (no images needed).
  const orderE1Id = await createSampleOrderCustomLines(clientEId, fobIncoterm, currency, loadingPort, standardPreset, userId, [
    { description: 'Granite Monument Slab', dimensions: '120 x 80 x 3 cm', finish: 'Polished', quantity: '25', unit: 'pcs', unit_price: '180.00' },
    { description: 'Granite Monument Slab', dimensions: '60 x 60 x 2 cm', finish: 'Polished', quantity: '40', unit: 'pcs', unit_price: '95.00' },
  ], true);
  await orderAnnexureRepository.createProduct(orderE1Id, {
    name: 'Granite Monument Slab — 120 x 80 x 3 cm',
    description: 'Large-format slab for base/kerb use.',
    dimensions: '120 x 80 x 3 cm',
    finish: 'Polished (mirror finish, top & face)',
    components: null,
    technical_notes: 'Thickness tolerance +/- 2mm; edges arrised.',
  });
  await orderAnnexureRepository.createProduct(orderE1Id, {
    name: 'Granite Monument Slab — 60 x 60 x 2 cm',
    description: 'Smaller-format slab — same material and finish as the 120 x 80 cm size.',
    dimensions: '60 x 60 x 2 cm',
    finish: 'Polished (mirror finish, top & face)',
    components: null,
    technical_notes: 'Thickness tolerance +/- 2mm; edges arrised.',
  });
  await advanceSampleOrderPastQuotation(orderE1Id, userId, true);

  // Order E2: monument blank + headstone, Annexure A with a spec AND a reference image for each.
  const orderE2Id = await createSampleOrderCustomLines(clientEId, fobIncoterm, currency, loadingPort, standardPreset, userId, [
    { description: 'Monument Blank', dimensions: '90 x 45 x 8 cm', finish: 'Polished front & top, rock-pitched sides', quantity: '12', unit: 'pcs', unit_price: '310.00' },
    { description: 'Headstone — Traditional Upright', dimensions: '60 x 30 x 8 cm', finish: 'Polished, all sides', quantity: '12', unit: 'pcs', unit_price: '260.00' },
  ], true);
  const orderE2 = await orderRepository.find(orderE2Id);
  const blankAnnexureId = await orderAnnexureRepository.createProduct(orderE2Id, {
    name: 'Monument Blank',
    description: 'Base blank supplied for on-site engraving by the buyer.',
    dimensions: '90 x 45 x 8 cm',
    finish: 'Polished front & top, rock-pitched sides',
    components: null,
    technical_notes: 'Top edge chamfered 10mm; back face left rough for mounting.',
  });
  await attachSampleAnnexureImage(
    orderE2Id,
    blankAnnexureId,
    `clients/${pathSafe(orderE2.client_unique_number)}/${pathSafe(orderE2.order_reference)}/annexure`,
    'Monument Blank - reference photo.png',
    userId
  );
  const headstoneAnnexureId = await orderAnnexureRepository.createProduct(orderE2Id, {
    name: 'Headstone — Traditional Upright',
    description: 'Standard upright headstone, same order as the monument blank above.',
    dimensions: '60 x 30 x 8 cm',
    finish: 'Polished, all sides',
    components: null,
    technical_notes: 'Serpentine-top profile; polished on all visible faces.',
  });
  await attachSampleAnnexureImage(
    orderE2Id,
    headstoneAnnexureId,
    `clients/${pathSafe(orderE2.client_unique_number)}/${pathSafe(orderE2.order_reference)}/annexure`,
    'Headstone - reference photo.png',
    userId
  );
  await advanceSampleOrderPastQuotation(orderE2Id, userId, true);

  // Order E3: a complete monument set, Annexure A describing its components + a reference image.
  const orderE3Id = await createSampleOrderCustomLines(clientEId, fobIncoterm, currency, loadingPort, standardPreset, userId, [
    { description: 'Complete Monument Set (Base + Die + Cap + Vase)', dimensions: 'Base 90x45x15cm; Die 60x30x8cm; Cap 66x33x10cm', finish: 'Polished front & top, rock-pitched sides', quantity: '6', unit: 'set', unit_price: '780.00' },
  ], true);
  const orderE3 = await orderRepository.find(orderE3Id);
  const monumentSetAnnexureId = await orderAnnexureRepository.createProduct(orderE3Id, {
    name: 'Complete Monument Set',
    description: 'Full monument assembly, matched from a single block for colour consistency.',
    dimensions: 'Base 90 x 45 x 15 cm; Die 60 x 30 x 8 cm; Cap 66 x 33 x 10 cm',
    finish: 'Polished front & top, rock-pitched sides',
    components: '1x Base, 1x Die (headstone), 1x Cap, 1x Vase',
    technical_notes: 'Assembled on-site by the buyer; all pieces cut from the same block for colour match.',
  });
  await attachSampleAnnexureImage(
    orderE3Id,
    monumentSetAnnexureId,
    `clients/${pathSafe(orderE3.client_unique_number)}/${pathSafe(orderE3.order_reference)}/annexure`,
    'Complete Monument Set - reference photo.png',
    userId
  );
  await advanceSampleOrderPastQuotation(orderE3Id, userId, true);

  // Order E4: same kind of product, two set sizes (10-piece vs 12-piece) — no Annexure A, a quantity distinction, not a technical one.
  const orderE4Id = await createSampleOrderCustomLines(clientEId, fobIncoterm, currency, loadingPort, standardPreset, userId, [
    { description: 'Granite Flower Vase — 10-Piece Set', dimensions: '20 x 20 x 30 cm each', finish: 'Polished', quantity: '10', unit: 'pcs', unit_price: '45.00' },
    { description: 'Granite Flower Vase — 12-Piece Set', dimensions: '18 x 18 x 28 cm each', finish: 'Polished', quantity: '12', unit: 'pcs', unit_price: '38.00' },
  ]);
  await advanceSampleOrderPastQuotation(orderE4Id, userId, false);

  // --- Client F: a raw, unprocessed block — no Annexure A, no finish to specify ---
  const clientFId = await createSampleClient(
    '[SAMPLE] Granite Quarry Direct Traders',
    '2 Quarry Access Road, Sample Industrial Zone, Test Country',
    userId
  );
  const orderF1Id = await createSampleOrderCustomLines(clientFId, fobIncoterm, currency, loadingPort, standardPreset, userId, [
    // 2516.11, not the finished-goods default 6802.93 — a raw/crude-trimmed
    // block is a materially different tariff classification (seed.sql's own
    // note: "Different product = verify HS Code before issuing").
    { description: 'Raw Granite Block — Absolute Black (unprocessed)', dimensions: 'approx. 300 x 150 x 150 cm (irregular, as-quarried)', finish: 'Natural / Unfinished (Raw Block)', quantity: '4', unit: 'blocks', unit_price: '95000.00', hs_code: '2516.11' },
  ]);
  await advanceSampleOrderPastQuotation(orderF1Id, userId, false);

  return { clients: 6, orders: 10 };
}

async function clear() {
  return sampleDataRepository.clearAll();
}

async function createSampleClient(companyName, billingAddress, userId, email = null) {
  const clientUniqueNumber = await referenceNumberService.generateClientUniqueNumber();
  const clientId = await clientRepository.create(
    {
      company_legal_name: companyName,
      billing_address: billingAddress,
      consignee_name: 'SAME',
      consignee_address: 'SAME',
      contact_person: 'Sample Contact',
      email,
      phone: null,
      country_of_destination: 'Test Country',
      coo_type: 'TBC',
      notify_party: null,
    },
    userId,
    clientUniqueNumber
  );
  await clientRepository.markSample(clientId);
  return clientId;
}

/**
 * Everything a sample order needs before its product lines exist: the
 * order shell itself (reference, client/incoterm/currency/preset linkage,
 * stage/payment-status initialization). Shared by both createSampleOrder()
 * (fixed-quantity 3-tuple lines) and createSampleOrderCustomLines() (full
 * per-line control — quantity, unit, price, HS code — for the
 * product/Annexure-A variety scenarios).
 *
 * @param {string|null} [portOfDischargeText] free-text discharge port — only Chennai (loading) is seeded by
 *   default, so a CFR/CIF sample order (which needs a discharge port to look believable) supplies its own
 *   text fallback rather than depending on a discharge port row existing.
 */
async function createSampleOrderShell(clientId, incoterm, currency, loadingPort, preset, userId, portOfDischargeText = null, includeAnnexureA = false) {
  const client = await clientRepository.find(clientId);
  const sequenceNo = await orderRepository.nextSequenceForClient(clientId);
  const orderRefFormat = (await companySettingsRepository.get('order_ref_format')) || 'SC/OC/{YYYY}/{NNN}';
  const now = new Date();
  const orderReference =
    orderRefFormat
      .replace(/\{YYYY\}/g, String(now.getFullYear()))
      .replace(/\{NNN\}/g, String(sequenceNo).padStart(3, '0'))
    + '-' + clientId;

  const quotationValidityDays = parseInt((await companySettingsRepository.get('quotation_validity_days')) || '30', 10);
  const quotationDate = formatDate(now);
  const validUntil = new Date(now);
  validUntil.setDate(validUntil.getDate() + quotationValidityDays);

  const orderId = await orderRepository.create(
    {
      order_reference: orderReference,
      client_id: clientId,
      sequence_no: sequenceNo,
      buyer_inquiry_ref: client.client_unique_number,
      payment_preset_id: parseInt(preset.id, 10),
      incoterm_id: parseInt(incoterm.id, 10),
      port_of_loading_id: loadingPort ? parseInt(loadingPort.id, 10) : null,
      port_of_discharge_text: portOfDischargeText,
      currency_id: parseInt(currency.id, 10),
      coo_type: client.coo_type ?? 'TBC',
      estimated_total_cbm: null,
      estimated_gross_weight_kg: null,
      estimated_net_weight_kg: null,
      indicative_freight_low: null,
      indicative_freight_high: null,
      indicative_insurance_amount: null,
      buyers_po_ref: 'NIL',
      quotation_date: quotationDate,
      quotation_valid_until: formatDate(validUntil),
      include_annexure_a: includeAnnexureA,
    },
    userId
  );
  await orderRepository.markSample(orderId);

  await orderStageRepository.initializeForOrder(orderId);
  await orderPaymentStatusRepository.initializeForOrder(orderId);

  return orderId;
}

/** @param {Array<[string,string,string]>} productLines [description, dimensions, finish] — fixed quantity '10' pcs @ 250.00, HS 6802.93. */
async function createSampleOrder(clientId, incoterm, currency, loadingPort, preset, userId, productLines, portOfDischargeText = null) {
  const orderId = await createSampleOrderShell(clientId, incoterm, currency, loadingPort, preset, userId, portOfDischargeText);

  let lineNo = 1;
  for (const [description, dimensions, finish] of productLines) {
    await orderProductRepository.add(
      orderId,
      lineNo++,
      description,
      dimensions,
      finish,
      '10',
      false,
      'pcs',
      '250.00',
      '6802.93'
    );
  }

  return orderId;
}

/**
 * Full per-line control (quantity, unit, price, HS code) for the
 * product/Annexure-A variety scenarios — same product at different sizes,
 * piece-count sets, raw blocks with a non-default HS code, etc.
 *
 * @param {Array<{description:string,dimensions:string,finish:string,quantity:string,unit:string,unit_price:string,hs_code?:string}>} productLines
 */
async function createSampleOrderCustomLines(clientId, incoterm, currency, loadingPort, preset, userId, productLines, includeAnnexureA = false) {
  const orderId = await createSampleOrderShell(clientId, incoterm, currency, loadingPort, preset, userId, null, includeAnnexureA);

  let lineNo = 1;
  for (const line of productLines) {
    await orderProductRepository.add(
      orderId,
      lineNo++,
      line.description,
      line.dimensions,
      line.finish,
      line.quantity,
      false,
      line.unit,
      line.unit_price,
      line.hs_code || '6802.93'
    );
  }

  return orderId;
}

/**
 * Replays exactly the calls ordersController/documentController make for
 * a real order: generate QT (passes Stage 1), record the buyer's PO
 * (passes Stage 2), generate PI, record + clear the advance payment
 * (passes Stage 3), generate OC, confirm buyer acknowledgement (passes
 * Stage 4) — leaving the order sitting at Stage 5 (Supplier PO) with a
 * believable paper trail and payment history to look at.
 */
async function advanceSampleOrderToStage5(orderId, userId) {
  await documentGenerationService.generate(orderId, 'QT', userId);
  await stageGateService.passAndUnlockNext(orderId, 1, userId);

  await orderRepository.setBuyersPoRef(orderId, 'SAMPLE-BUYER-PO-0001');
  await stageGateService.passAndUnlockNext(orderId, 2, userId);

  await documentGenerationService.generate(orderId, 'PI', userId);

  const fobTotal = await orderProductRepository.totalFobValue(orderId);
  const order = await orderRepository.find(orderId);
  const advanceAmount = Math.round(fobTotal * parseFloat(order.advance_pct) / 100 * 100) / 100;
  const balanceAmount = Math.round(fobTotal * parseFloat(order.balance_pct) / 100 * 100) / 100;
  const balanceDueDate = order.balance_trigger_option === 'A_BEFORE_SHIPMENT'
    ? null
    : formatDate(addDays(new Date(), parseInt(order.balance_days, 10)));

  await orderPaymentStatusRepository.recordAdvanceReceived(orderId, advanceAmount, formatDate(new Date()));
  await orderPaymentStatusRepository.markAdvanceCleared(orderId, formatDate(new Date()), userId);
  await orderPaymentStatusRepository.setBalanceAmount(orderId, balanceAmount, balanceDueDate);
  await stageGateService.passAndUnlockNext(orderId, 3, userId);

  await documentGenerationService.generate(orderId, 'OC', userId);
  await stageGateService.passAndUnlockNext(orderId, 4, userId);
}

/** Order A2 — a second, independently-progressing order for the same client, stopped partway (Stage 2 passed, awaiting PI). */
async function advanceSampleOrderToStage2Pending(orderId, userId) {
  await documentGenerationService.generate(orderId, 'QT', userId);
  await stageGateService.passAndUnlockNext(orderId, 1, userId);
  await orderRepository.setBuyersPoRef(orderId, 'SAMPLE-BUYER-PO-0003');
  await stageGateService.passAndUnlockNext(orderId, 2, userId);
}

/**
 * The product/Annexure-A variety orders (Client E/F, 2026-09-23) don't need
 * to demonstrate stage depth (that's what Orders A2/B/C/D already cover) —
 * just generate the Quotation (and Annexure A, for the orders whose product
 * lines actually need one — its own product entries must already exist by
 * the time this is called) and pass Stage 1.
 */
async function advanceSampleOrderPastQuotation(orderId, userId, generateAnnexure) {
  await documentGenerationService.generate(orderId, 'QT', userId);
  if (generateAnnexure) {
    await documentGenerationService.generate(orderId, 'ANNEXA', userId);
  }
  await stageGateService.passAndUnlockNext(orderId, 1, userId);
}

/**
 * Order B's walkthrough ("cover all", 2026-09-23) — everything a real
 * staff user would do for a FOB order that runs into a document
 * rejection, a payment-terms renegotiation, and a packing-quantity
 * shortfall, while also being the order whose client gets portal access
 * and whose PI-stage form is left sitting in the review queue. Leaves
 * the order at Stage 8 (Commercial Invoice) — active, not closed, so
 * Order C remains the only fully-closed sample order.
 */
async function advanceSampleOrderBJourney(orderId, clientId, supplierId, userId, reviewerUserId) {
  // --- Stage 1: Quotation ---
  await documentGenerationService.generate(orderId, 'QT', userId);
  await stageGateService.passAndUnlockNext(orderId, 1, userId);

  // A PI-stage intake link sent once the Quotation is out — the client
  // has submitted it, but nobody's actioned it yet, so it sits in the
  // PI Intake Review queue exactly like a real unresolved one.
  const piToken = await piIntakeRepository.createLink(orderId, userId);
  const piSubmission = await piIntakeRepository.findValidByToken(piToken);
  await piIntakeRepository.submit(piSubmission.id, {
    company_legal_name: 'Meridian Home Collections LLC',
    billing_address: '77 Riverside Court, Sample District, Test Country',
    consignee_name: 'SAME',
    consignee_address: 'SAME',
    vat_eori_tax_no: 'GB-SAMPLE-987654321',
    contact_person: 'Jordan Blake',
    email: 'meridian.buyer@sample-client.test',
    phone: '+1-555-0100-2002',
    notify_party: 'NIL',
    port_of_discharge_text: 'Long Beach, USA',
    country_of_destination: 'United States',
    incoterm_confirmed: 'FOB',
    container_type_text: null,
    payment_terms_confirmation: 'CONFIRMED — 30% advance T/T + 70% balance against scanned BL copy within 30 days.',
    quotation_acceptance_reference: 'We accept the Quotation as issued — no changes.',
    coo_type: 'Non-preferential',
    buyer_po_ref: null,
    changes_from_quotation: 'No changes.',
    special_document_requirements: null,
  }, '203.0.113.42');

  // --- Stage 2: Buyer PO — reference recorded AND the buyer's actual
  // signed copy attached (Stage 2 gate evidence beyond just a typed ref) ---
  await orderRepository.setBuyersPoRef(orderId, 'SAMPLE-BUYER-PO-0002');
  let order = await orderRepository.find(orderId);
  const buyerPoFileId = await attachSamplePlaceholderFile(
    null,
    orderId,
    `clients/${pathSafe(order.client_unique_number)}/${pathSafe(order.order_reference)}/buyer_po`,
    'Buyer PO SAMPLE-BUYER-PO-0002 (signed).pdf',
    'Buyer',
    'Buyer PO copy',
    userId
  );
  await orderBuyerPoDocumentRepository.attach(orderId, buyerPoFileId);
  await stageGateService.passAndUnlockNext(orderId, 2, userId);

  // --- Stage 3: PI / Production — advance recorded and cleared ---
  await documentGenerationService.generate(orderId, 'PI', userId);
  const fobTotal = await orderProductRepository.totalFobValue(orderId);
  order = await orderRepository.find(orderId);
  const advanceAmount = Math.round(fobTotal * parseFloat(order.advance_pct) / 100 * 100) / 100;
  const balanceAmount = Math.round(fobTotal * parseFloat(order.balance_pct) / 100 * 100) / 100;
  const balanceDueDate = order.balance_trigger_option === 'A_BEFORE_SHIPMENT'
    ? null
    : formatDate(addDays(new Date(), parseInt(order.balance_days, 10)));
  await orderPaymentStatusRepository.recordAdvanceReceived(orderId, advanceAmount, formatDate(new Date()));
  await orderPaymentStatusRepository.markAdvanceCleared(orderId, formatDate(new Date()), userId);
  await orderPaymentStatusRepository.setBalanceAmount(orderId, balanceAmount, balanceDueDate);
  await stageGateService.passAndUnlockNext(orderId, 3, userId);

  // Advance cleared -> the real trigger point for auto-provisioning client
  // portal access (clientPortalService.provisionIfNeeded()'s only
  // precondition is an email on file, which this client has).
  await clientPortalService.provisionIfNeeded(clientId, orderId);

  // --- Stage 4: Order Confirmation — a review/reject/regenerate/approve
  // cycle (Section 9) before it can pass ---
  const ocResult = await documentGenerationService.generate(orderId, 'OC', userId);
  await reviewWorkflowService.assignReviewers(ocResult.document_id, [reviewerUserId], userId);
  let reviews = await documentReviewRepository.forDocument(ocResult.document_id);
  await reviewWorkflowService.reject(
    reviews[0].id,
    reviewerUserId,
    'Buyer name on the OC does not match the signed Buyer PO exactly — please correct and resubmit.'
  );
  const ocResult2 = await documentGenerationService.generate(orderId, 'OC', userId); // new revision, regenerated from draft
  await reviewWorkflowService.assignReviewers(ocResult2.document_id, [reviewerUserId], userId);
  reviews = await documentReviewRepository.forDocument(ocResult2.document_id);
  await reviewWorkflowService.approve(reviews[0].id, reviewerUserId, 'Corrected — matches the Buyer PO. Approved.');
  await stageGateService.passAndUnlockNext(orderId, 4, userId);

  // --- Payment Terms Amendment (Section 8) — full lifecycle:
  // request -> MD-approve -> generate agreement -> signed copy uploaded
  // -> active (payment terms updated on the order) ---
  const amendmentId = await amendmentService.createRequest(
    orderId,
    'Buyer requested additional time on the balance payment due to an import financing delay at their bank.',
    'importer',
    null,
    null,
    '70% balance T/T against scanned BL copy within 45 days of BL date (extended from 30 days).',
    'B_AGAINST_BL',
    45,
    null,
    formatDate(new Date()),
    userId
  );
  await amendmentService.approveByMd(amendmentId, userId);
  await amendmentService.generateDocument(amendmentId, userId);
  const amendment = await amendmentRepository.find(amendmentId);
  order = await orderRepository.find(orderId);
  const signedCopyFileId = await attachSamplePlaceholderFile(
    null,
    orderId,
    `clients/${pathSafe(order.client_unique_number)}/${pathSafe(order.order_reference)}/amendments/${pathSafe(amendment.amendment_reference)}/signed`,
    `Countersigned Amendment ${amendment.amendment_reference}.pdf`,
    'Buyer',
    'Countersigned Payment Terms Amendment Agreement',
    userId
  );
  await amendmentService.attachSignedCopyAndActivate(amendmentId, signedCopyFileId, userId);

  // --- Stage 5: Supplier Purchase Order — with the supplier's signed
  // acknowledgment copy attached (Stage 5 gate evidence beyond the flag alone) ---
  const suppoTypeId = await documentGenerationService.documentTypeIdFor('SUPPO');
  const supplierPoReference = await referenceNumberService.generateDocumentRef(suppoTypeId);
  await orderSupplierPoRepository.create(orderId, supplierId, supplierPoReference, {
    material_stone_type: 'Natural Granite, Absolute Black',
    grade: 'Grade A',
    surface_finish: 'Natural/Sandblasted',
    dimensions: 'Per order — see Annexure',
    dimensional_tolerance: '+/- 2mm',
    quantity: '30',
    unit: 'pcs',
    colour_reference: 'Sample swatch on file',
    unit_price_inr: '15000.00',
    basic_value_inr: '450000.00',
    gst_rate_pct: '18.00',
    gst_amount_inr: '81000.00',
    total_payable_inr: '531000.00',
    advance_pct: '40.00',
    advance_amount_inr: '212400.00',
    balance_amount_inr: '318600.00',
    delivery_location: 'NexaCrest Factory, Chennai',
    required_delivery_date: formatDate(addDays(new Date(), 21)),
    packing_requirement: 'Export wooden crates, fumigated',
  });
  await documentGenerationService.generate(orderId, 'SUPPO', userId);
  const supplierPo = await orderSupplierPoRepository.findLatestForOrder(orderId);
  const supplierAckFileId = await attachSamplePlaceholderFile(
    null,
    orderId,
    `clients/${pathSafe(order.client_unique_number)}/${pathSafe(order.order_reference)}/supplier_po`,
    `Supplier PO ${supplierPoReference} (acknowledged).pdf`,
    'Supplier',
    'Supplier PO acknowledgment',
    userId
  );
  await orderSupplierPoDocumentRepository.attach(supplierPo.id, supplierAckFileId);
  await orderSupplierPoRepository.markSigned(supplierPo.id);
  await stageGateService.passAndUnlockNext(orderId, 5, userId);

  // --- FOB -> Freight Payment (Stage 6) auto-skipped — the direct
  // contrast with Order C's CIF order, which actually pays it ---
  await stageGateService.maybeAutoSkipFreightStage(orderId, userId);

  // --- Stage 7: Packing & BL Instruction — a quantity shortfall beyond
  // tolerance, resolved with the buyer's written approval on file
  // (mirrors ordersController.savePacking()'s own gate) ---
  const summary = await orderProductRepository.orderedQuantitySummary(orderId);
  const actualQty = summary.total - 5.0; // 5 of 30 pcs short — well beyond the default 5% tolerance
  const shortfallPct = Math.round(((summary.total - actualQty) / summary.total) * 100 * 100) / 100;
  const buyerApprovalFileId = await attachSamplePlaceholderFile(
    null,
    orderId,
    `clients/${pathSafe(order.client_unique_number)}/${pathSafe(order.order_reference)}/packing`,
    'Buyer approval - quantity shortfall.pdf',
    'Buyer',
    'Quantity shortfall approval',
    userId
  );
  await orderPackingRepository.upsert(orderId, {
    actual_quantity_packed: String(actualQty),
    crate_count: '2',
    total_net_weight_kg: '2100.00',
    total_gross_weight_kg: '2280.00',
    total_cbm: '12.400',
    packing_date: formatDate(new Date()),
    shortfall_pct: String(shortfallPct),
  });
  await orderPackingRepository.attachBuyerApproval(orderId, buyerApprovalFileId);

  const products = await orderProductRepository.forOrder(orderId);
  const crates = products.map((product, i) => ({
    crate_no: `C-${String(i + 1).padStart(3, '0')}`,
    marks_numbers: `NEXACREST/SAMPLE/${i + 1}`,
    product_description: product.description,
    dimensions_lwh_cm: '110 x 90 x 80',
    pcs: product.quantity,
    net_weight_kg: '700.00',
    gross_weight_kg: '760.00',
    cbm: '4.100',
    hs_code: product.hs_code || '6802.93',
  }));
  await orderCrateRepository.replaceForOrder(orderId, crates);
  await documentGenerationService.generate(orderId, 'PL', userId);

  await orderShippingRepository.upsert(orderId, {
    shipping_line: 'Sample Shipping Line',
    vessel_name: 'MV Sample Pioneer',
    voyage_number: 'SP-2026-009',
    etd: formatDate(addDays(new Date(), 3)),
    eta: formatDate(addDays(new Date(), 24)),
    container_type: '1x20FT',
    container_no: 'SAMU7654321',
    seal_no: 'SEAL000456',
  });
  await documentGenerationService.generate(orderId, 'BLI', userId);
  await orderShippingRepository.recordBl(orderId, 'SAMPLE-BL-0002', formatDate(new Date()));
  await stageGateService.passAndUnlockNext(orderId, 7, userId);

  // --- Stage 8: Commercial Invoice — generated, balance not yet cleared.
  // Deliberately left here, active and not closed, so Order C remains
  // the only fully-closed sample order. ---
  await documentGenerationService.generate(orderId, 'CI', userId);
}

/**
 * Order C's walkthrough (Task #17) — everything advanceSampleOrderToStage5()
 * does, then continues Stage 5 through Stage 9 by replaying the exact same
 * ordersController actions a real CIF order on the "Established Buyer —
 * Post-BL" preset would go through: Supplier PO, the CFR/CIF-only Freight
 * Payment stage, Packing + BL Instruction, Commercial Invoice + balance,
 * then Document Despatch & Closure. Leaves the order order.status =
 * 'complete' with every one of the nine document types generated at least
 * once.
 */
async function advanceSampleOrderToStage9(orderId, supplierId, userId) {
  await advanceSampleOrderToStage5(orderId, userId);

  // --- Stage 5: Supplier Purchase Order ---
  const suppoTypeId = await documentGenerationService.documentTypeIdFor('SUPPO');
  const supplierPoReference = await referenceNumberService.generateDocumentRef(suppoTypeId);
  await orderSupplierPoRepository.create(orderId, supplierId, supplierPoReference, {
    material_stone_type: 'Natural Granite, Kadapa Black',
    grade: 'Grade A',
    surface_finish: 'Flamed',
    dimensions: 'Per order — see Annexure',
    dimensional_tolerance: '+/- 2mm',
    quantity: '20',
    unit: 'pcs',
    colour_reference: 'Sample swatch on file',
    unit_price_inr: '15000.00',
    basic_value_inr: '300000.00',
    gst_rate_pct: '18.00',
    gst_amount_inr: '54000.00',
    total_payable_inr: '354000.00',
    advance_pct: '40.00',
    advance_amount_inr: '141600.00',
    balance_amount_inr: '212400.00',
    delivery_location: 'NexaCrest Factory, Chennai',
    required_delivery_date: formatDate(addDays(new Date(), 21)),
    packing_requirement: 'Export wooden crates, fumigated',
  });
  await documentGenerationService.generate(orderId, 'SUPPO', userId);
  const supplierPo = await orderSupplierPoRepository.findLatestForOrder(orderId);
  await orderSupplierPoRepository.markSigned(supplierPo.id);
  await stageGateService.passAndUnlockNext(orderId, 5, userId);
  await stageGateService.maybeAutoSkipFreightStage(orderId, userId);

  // --- Stage 6: Freight Payment (CFR/CIF only — this sample order is CIF,
  // so this stage actually runs rather than being auto-skipped) ---
  await orderFreightRepository.upsert(orderId, {
    confirmed_freight_rate: '1450.00',
    insurance_amount: '185.00',
    freight_forwarder_name: 'Sample Forwarder Logistics',
    freight_forwarder_contact: 'ops@sampleforwarder.test',
    gst_treatment: 'NIL',
  });
  await documentGenerationService.generate(orderId, 'FDN', userId);
  await orderPaymentStatusRepository.recordFreightReceived(orderId, 1635.00, formatDate(new Date()));
  await orderPaymentStatusRepository.markFreightCleared(orderId, formatDate(new Date()), userId);
  await stageGateService.passAndUnlockNext(orderId, 6, userId);

  // --- Stage 7: Packing & BL Instruction ---
  const summary = await orderProductRepository.orderedQuantitySummary(orderId);
  await orderPackingRepository.upsert(orderId, {
    actual_quantity_packed: String(summary.total),
    crate_count: '2',
    total_net_weight_kg: '3200.00',
    total_gross_weight_kg: '3450.00',
    total_cbm: '18.500',
    packing_date: formatDate(new Date()),
    shortfall_pct: '0.00',
  });
  const products = await orderProductRepository.forOrder(orderId);
  const crates = products.map((product, i) => ({
    crate_no: `C-${String(i + 1).padStart(3, '0')}`,
    marks_numbers: `NEXACREST/SAMPLE/${i + 1}`,
    product_description: product.description,
    dimensions_lwh_cm: '120 x 100 x 90',
    pcs: product.quantity,
    net_weight_kg: '1600.00',
    gross_weight_kg: '1725.00',
    cbm: '9.250',
    hs_code: product.hs_code || '6802.93',
  }));
  await orderCrateRepository.replaceForOrder(orderId, crates);
  await documentGenerationService.generate(orderId, 'PL', userId);

  await orderShippingRepository.upsert(orderId, {
    shipping_line: 'Sample Shipping Line',
    vessel_name: 'MV Sample Voyager',
    voyage_number: 'SV-2026-014',
    etd: formatDate(addDays(new Date(), 3)),
    eta: formatDate(addDays(new Date(), 27)),
    container_type: '1x40HC',
    container_no: 'SAMU1234567',
    seal_no: 'SEAL000123',
  });
  await documentGenerationService.generate(orderId, 'BLI', userId);
  await orderShippingRepository.recordBl(orderId, 'SAMPLE-BL-0001', formatDate(new Date()));
  await stageGateService.passAndUnlockNext(orderId, 7, userId);

  // --- Stage 8: Commercial Invoice & Balance ---
  await documentGenerationService.generate(orderId, 'CI', userId);
  const paymentStatus = await orderPaymentStatusRepository.find(orderId);
  const balanceAmount = paymentStatus && paymentStatus.balance_amount
    ? parseFloat(paymentStatus.balance_amount)
    : (await orderProductRepository.totalFobValue(orderId)) * 0.6;
  await orderPaymentStatusRepository.recordBalanceReceived(orderId, balanceAmount, formatDate(new Date()));
  await orderPaymentStatusRepository.markBalanceCleared(orderId, formatDate(new Date()), userId);
  await stageGateService.passAndUnlockNext(orderId, 8, userId);

  // --- Stage 9: Document Despatch & Closure ---
  await documentGenerationService.generate(orderId, 'COOPREP', userId);
  await orderShippingRepository.recordBlOriginalsReceived(orderId, 3);
  await orderShippingRepository.recordBlEndorsed(orderId, userId);
  await orderShippingRepository.recordScannedBlSent(orderId);
  await orderShippingRepository.recordCourierSent(orderId, 'SAMPLE-COURIER-TRACK-0001');
  await stageGateService.passAndUnlockNext(orderId, 9, userId);
  await orderRepository.markComplete(orderId);
}

/** A dispute raised after closure, with evidence attached, then resolved (Spec Section 16). */
async function addSampleDispute(orderId, userId) {
  const description = 'Buyer reports a shortage of 2 pieces found upon container destuffing at the destination port, against the Packing List quantity.';
  const noticeDate = formatDate(new Date());
  const responseDays = parseInt((await companySettingsRepository.get('dispute_response_days_n')) || '10', 10);
  const responseDueDate = await workingDaysCalculator.addWorkingDays(noticeDate, responseDays);

  const disputeId = await disputeRepository.create(orderId, noticeDate, 'Buyer', description, userId, responseDueDate);
  await auditLogRepository.log(userId, 'DISPUTE_RAISED', 'disputes', disputeId, null, null, description);

  const order = await orderRepository.find(orderId);
  const evidenceFileId = await attachSamplePlaceholderFile(
    null,
    orderId,
    `clients/${pathSafe(order.client_unique_number)}/${pathSafe(order.order_reference)}/disputes/${disputeId}`,
    'Buyer shortage claim - photos and destuffing report.pdf',
    'Buyer',
    'Dispute-related document',
    userId
  );
  await disputeDocumentRepository.attach(disputeId, evidenceFileId);

  const resolutionNotes = 'Verified against the Packing List and crate photographs; supplier confirmed a short-shipment of 2 pieces and issued a credit note applied to the buyer\'s next order. Buyer confirmed satisfaction with the resolution.';
  await disputeRepository.resolve(disputeId, resolutionNotes);
  await auditLogRepository.log(userId, 'DISPUTE_STATUS_CHANGED', 'disputes', disputeId, 'status', 'Open', 'Resolved', resolutionNotes);
}

/** A quoted order the buyer went cold on — marked lost (replays ordersController.markLost()'s own checks). */
async function advanceAndLoseSampleOrder(orderId, userId) {
  await documentGenerationService.generate(orderId, 'QT', userId);
  await stageGateService.passAndUnlockNext(orderId, 1, userId);

  const reason = 'Buyer stopped responding after 45 days despite repeated follow-ups; sourced from a domestic supplier instead per their email.';
  await orderRepository.markLost(orderId, reason, userId);
  await auditLogRepository.log(userId, 'ORDER_MARKED_LOST', 'orders', orderId, 'status', 'active', 'lost', reason);
}

/** Task #17 — a fresh, clearly-flagged supplier shared by every Stage-5+ sample order (see supplier is_sample_data / markSample()). */
async function createSampleSupplier() {
  const supplierId = await supplierRepository.create({
    supplier_legal_name: '[SAMPLE] Deccan Stone Quarries Pvt. Ltd.',
    address: 'Quarry Road, Sample Industrial Area, Test State',
    gstin: '29SAMPLE0000A1Z5',
    pan: 'SAMPL0000A',
    contact_person: 'Sample Supplier Contact',
    phone: '+91-00000-00000',
    supplier_type: 'Quarry',
  });
  await supplierRepository.markSample(supplierId);
  return supplierId;
}

/** Any active user other than the one running the load, so review-assignment scenarios have a distinct reviewer. Falls back to the same user if none exists. */
async function pickReviewerUserId(fallbackUserId) {
  const users = await userRepository.listActive();
  for (const user of users) {
    if (parseInt(user.id, 10) !== fallbackUserId) {
      return parseInt(user.id, 10);
    }
  }
  return fallbackUserId;
}

/**
 * fileUploadService can't be driven without a real multipart upload, so
 * sample "received" files replay its own two steps directly: write a
 * placeholder straight to the same storage path convention, then
 * fileStoreRepository.insertReceived() — never a shared file across rows,
 * since sampleDataRepository.clearAll() unlinks each file_store row's own
 * path individually.
 */
async function attachSamplePlaceholderFile(clientId, orderId, subPath, originalFilename, receivedFrom, documentTypeLabel, uploadedBy) {
  const storageBase = (env.get('STORAGE_BASE_PATH', '') || '').replace(/\/+$/, '');
  const targetDir = `${storageBase}/${subPath}`;
  fs.mkdirSync(targetDir, { recursive: true });
  const uuidFilename = `${crypto.randomBytes(16).toString('hex')}.pdf`;
  const targetPath = `${targetDir}/${uuidFilename}`;
  const placeholderContent = `%PDF-1.4\n% Sample placeholder document generated by the Sample Data Playground.\n% ${documentTypeLabel}\n`;
  fs.writeFileSync(targetPath, placeholderContent);

  return fileStoreRepository.insertReceived(
    clientId,
    orderId,
    targetPath,
    uuidFilename,
    originalFilename,
    Buffer.byteLength(placeholderContent),
    'application/pdf',
    uploadedBy,
    receivedFrom,
    documentTypeLabel
  );
}

/**
 * Annexure product images get base64-embedded straight into the generated
 * Annexure A PDF (see documentDataAssembler's annexure products block) —
 * unlike attachSamplePlaceholderFile()'s stand-in PDF text, this needs
 * genuinely valid, decodable image bytes so it actually renders rather than
 * showing as a broken image.
 */
async function attachSampleAnnexureImage(orderId, annexureProductId, subPath, originalFilename, uploadedBy) {
  const storageBase = (env.get('STORAGE_BASE_PATH', '') || '').replace(/\/+$/, '');
  const targetDir = `${storageBase}/${subPath}`;
  fs.mkdirSync(targetDir, { recursive: true });
  const uuidFilename = `${crypto.randomBytes(16).toString('hex')}.png`;
  const targetPath = `${targetDir}/${uuidFilename}`;
  // A minimal but genuinely valid 1x1 PNG.
  const pngBytes = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
  fs.writeFileSync(targetPath, pngBytes);

  const fileId = await fileStoreRepository.insertReceived(
    null,
    orderId,
    targetPath,
    uuidFilename,
    originalFilename,
    pngBytes.length,
    'image/png',
    uploadedBy,
    null,
    'Annexure product image'
  );
  await orderAnnexureRepository.addImage(annexureProductId, fileId);
}

function pathSafe(value) {
  return String(value ?? '').replace(/[^A-Za-z0-9_-]+/g, '-');
}

function formatDate(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function addDays(d, days) {
  const copy = new Date(d);
  copy.setDate(copy.getDate() + days);
  return copy;
}

module.exports = { isLoaded, summary, load, clear };
