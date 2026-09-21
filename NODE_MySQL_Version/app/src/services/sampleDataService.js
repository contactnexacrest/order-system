'use strict';

const clientRepository = require('../repositories/clientRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const lookupRepository = require('../repositories/lookupRepository');
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

/**
 * Phase E follow-up — "can we have some sample records to play with, and
 * clear them through the UI whenever, any number of times, without ever
 * touching real data?" (user request, 2026-09-19).
 *
 * Deliberately reuses the exact same repository/service calls a real user's
 * request would make (orderRepository.create(), stageGateService, the same
 * documentGenerationService.generate() every real QT/PI/OC goes through,
 * ...) rather than inventing a parallel fake-data insertion path — the
 * point of a playground is that it behaves exactly like the real system,
 * because it IS the real system, just flagged is_sample_data = 1 and
 * cleanly removable. See sampleDataRepository for the deletion side.
 *
 * Scope (Task #17 follow-up, 2026-09-21 — extends the original two-order
 * bounded scope, which this file's own docblock flagged as "easy to extend
 * ... if you want a third order further along later"):
 *   - Order A: brand new, left at Stage 1 (no documents) — the
 *     "start from scratch" walkthrough.
 *   - Order B: FOB, "Standard — New Buyer" preset, pushed to Stage 5
 *     (QT/PI/OC generated, buyer PO + advance recorded and cleared) — the
 *     "financials worth looking at on the dashboard" walkthrough.
 *   - Order C: CIF, "Established Buyer — Post-BL" preset (the tier requiring
 *     MD approval and a BL-triggered balance — SOP Tier B), pushed all the
 *     way through Stage 9 to a fully closed order — the "see every document
 *     type and the complete 9-stage lifecycle, including the CFR/CIF-only
 *     Freight Payment stage" walkthrough. Still not an attempt to cover
 *     every possible scenario (a dispute, an amendment, a quantity-shortfall
 *     buyer-approval upload are all real but separate scenarios) — those
 *     are each exercised by their own feature's own live testing, and
 *     bolting all of them onto the fixed "load sample data" button would
 *     make it slower and harder to reason about for the thing it's actually
 *     for: a new user's first walkthrough of the system.
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

  const clientAId = await createSampleClient(
    '[SAMPLE] Aurora Décor Imports',
    '124 Harbor Lane, Sample District, Test Country',
    userId
  );
  await createSampleOrder(clientAId, fobIncoterm, currency, loadingPort, standardPreset, userId, [
    ['Hand-carved decorative planter, Model A', '30 x 30 x 45 cm', 'Polished'],
    ['Hand-carved decorative planter, Model B', '25 x 25 x 40 cm', 'Matte'],
  ]);
  // Left exactly here — Stage 1, no documents yet — as the
  // "start from scratch" sample order.

  const clientBId = await createSampleClient(
    '[SAMPLE] Meridian Home Collections',
    '77 Riverside Court, Sample District, Test Country',
    userId
  );
  const orderB1Id = await createSampleOrder(clientBId, fobIncoterm, currency, loadingPort, standardPreset, userId, [
    ['Outdoor stone planter, large', '60 x 60 x 70 cm', 'Natural finish'],
    ['Outdoor stone planter, medium', '40 x 40 x 50 cm', 'Natural finish'],
    ['Garden bench, stone composite', '150 x 45 x 45 cm', 'Sandblasted'],
  ]);
  await advanceSampleOrderToStage5(orderB1Id, userId);

  const clientCId = await createSampleClient(
    '[SAMPLE] Silverleaf Global Trading',
    '9 Customs Quay, Sample Port District, Test Country',
    userId
  );
  const orderC1Id = await createSampleOrder(clientCId, cifIncoterm, currency, loadingPort, establishedPreset, userId, [
    ['Natural stone kerb stone, large', '100 x 30 x 15 cm', 'Flamed'],
    ['Natural stone kerb stone, small', '60 x 30 x 15 cm', 'Flamed'],
  ], 'Rotterdam, Netherlands');
  await advanceSampleOrderToStage9(orderC1Id, userId);

  return { clients: 3, orders: 3 };
}

async function clear() {
  return sampleDataRepository.clearAll();
}

async function createSampleClient(companyName, billingAddress, userId) {
  const clientUniqueNumber = await referenceNumberService.generateClientUniqueNumber();
  const clientId = await clientRepository.create(
    {
      company_legal_name: companyName,
      billing_address: billingAddress,
      consignee_name: 'SAME',
      consignee_address: 'SAME',
      contact_person: 'Sample Contact',
      email: null,
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
 * @param {Array<[string,string,string]>} productLines [description, dimensions, finish]
 * @param {string|null} [portOfDischargeText] free-text discharge port — only Chennai (loading) is seeded by
 *   default, so a CFR/CIF sample order (which needs a discharge port to look believable) supplies its own
 *   text fallback rather than depending on a discharge port row existing.
 */
async function createSampleOrder(clientId, incoterm, currency, loadingPort, preset, userId, productLines, portOfDischargeText = null) {
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
    },
    userId
  );
  await orderRepository.markSample(orderId);

  await orderStageRepository.initializeForOrder(orderId);
  await orderPaymentStatusRepository.initializeForOrder(orderId);

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
async function advanceSampleOrderToStage9(orderId, userId) {
  await advanceSampleOrderToStage5(orderId, userId);

  // --- Stage 5: Supplier Purchase Order ---
  const supplierId = await createSampleSupplier();
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

/** Task #17 — a fresh, clearly-flagged supplier for the Stage-5+ sample order (see supplier is_sample_data / markSample()). */
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

function formatDate(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function addDays(d, days) {
  const copy = new Date(d);
  copy.setDate(copy.getDate() + days);
  return copy;
}

module.exports = { isLoaded, summary, load, clear };
