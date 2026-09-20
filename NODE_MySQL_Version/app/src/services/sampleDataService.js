'use strict';

const clientRepository = require('../repositories/clientRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const lookupRepository = require('../repositories/lookupRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const orderProductRepository = require('../repositories/orderProductRepository');
const orderRepository = require('../repositories/orderRepository');
const orderStageRepository = require('../repositories/orderStageRepository');
const sampleDataRepository = require('../repositories/sampleDataRepository');
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
 * Bounded scope (judgment call, flag for your review): two sample clients,
 * one order each — one left brand new at Stage 1 so a new user can practice
 * the create-order-and-generate-QT flow, one pushed through to Stage 5
 * (QT/PI/OC generated, buyer PO + advance payment recorded and cleared) so
 * there's something with real financials to see on the dashboard/reports.
 * Not an attempt to cover all 9 stages or every document type — easy to
 * extend the same way if you want a third order further along later.
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
  const currencies = await lookupRepository.currencies();
  const loadingPorts = await lookupRepository.ports('loading');
  const presets = await lookupRepository.paymentPresets();
  const incoterm = incoterms[0] || null;
  const currency = currencies[0] || null;
  const loadingPort = loadingPorts[0] || null;
  const preset = presets[0] || null;
  if (!incoterm || !currency || !preset) {
    throw new Error('No incoterm/currency/payment preset configured yet — set those up first (Company Settings), then load sample data.');
  }

  const clientAId = await createSampleClient(
    '[SAMPLE] Aurora Décor Imports',
    '124 Harbor Lane, Sample District, Test Country',
    userId
  );
  await createSampleOrder(clientAId, incoterm, currency, loadingPort, preset, userId, [
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
  const orderB1Id = await createSampleOrder(clientBId, incoterm, currency, loadingPort, preset, userId, [
    ['Outdoor stone planter, large', '60 x 60 x 70 cm', 'Natural finish'],
    ['Outdoor stone planter, medium', '40 x 40 x 50 cm', 'Natural finish'],
    ['Garden bench, stone composite', '150 x 45 x 45 cm', 'Sandblasted'],
  ]);
  await advanceSampleOrderToStage5(orderB1Id, userId);

  return { clients: 2, orders: 2 };
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

/** @param {Array<[string,string,string]>} productLines [description, dimensions, finish] */
async function createSampleOrder(clientId, incoterm, currency, loadingPort, preset, userId, productLines) {
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

function formatDate(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function addDays(d, days) {
  const copy = new Date(d);
  copy.setDate(copy.getDate() + days);
  return copy;
}

module.exports = { isLoaded, summary, load, clear };
