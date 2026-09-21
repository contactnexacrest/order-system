'use strict';

const amendmentRepository = require('../repositories/amendmentRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const documentRepository = require('../repositories/documentRepository');
const notificationRepository = require('../repositories/notificationRepository');
const orderProductRepository = require('../repositories/orderProductRepository');
const orderRepository = require('../repositories/orderRepository');
const userRepository = require('../repositories/userRepository');
const referenceNumberService = require('./referenceNumberService');
const documentDataAssembler = require('./documentDataAssembler');
const documentGenerationService = require('./documentGenerationService');

/**
 * Spec Section 8 — PAYMENT TERMS AMENDMENT SYSTEM (SC/AMD). Orchestrates
 * amendmentRepository + referenceNumberService + documentGenerationService
 * + orderRepository's override columns. See amendmentRepository's docblock
 * for why original_terms_snapshot is frozen once, at request time.
 */

function trimTrailingZeros(numStr) {
  if (numStr.indexOf('.') === -1) return numStr;
  return numStr.replace(/0+$/, '').replace(/\.$/, '');
}

const formatMoney = documentDataAssembler.formatMoney;

async function createRequest(
  orderId,
  reason,
  requestedBy,
  amendedAdvancePct,
  amendedAdvanceAmount,
  amendedBalanceTerms,
  amendedBalanceTriggerOption,
  amendedBalanceDays,
  amendedBalanceAmount,
  effectiveFrom,
  requestedByUserId
) {
  const order = await orderRepository.find(orderId);
  if (!order) {
    throw new Error(`Order ${orderId} not found`);
  }

  const reference = await referenceNumberService.generateAmendmentRef();
  if (reference === null || reference === undefined) {
    throw new Error('AMD document type has no ref_format configured — cannot mint an amendment reference.');
  }

  const fobValue = await orderProductRepository.totalFobValue(orderId);
  const advancePct = parseFloat(order.advance_pct);
  const balancePct = parseFloat(order.balance_pct);
  const advanceAmount = Math.round((fobValue * advancePct) / 100 * 100) / 100;
  const isFob = String(order.incoterm_code).toUpperCase() === 'FOB';
  const portOfDischarge = order.port_of_discharge_name || order.port_of_discharge_text || 'TBC';

  const qtDoc = await documentRepository.findLatestForOrderAndTypeCode(orderId, 'QT');
  const piDoc = await documentRepository.findLatestForOrderAndTypeCode(orderId, 'PI');
  const ocDoc = await documentRepository.findLatestForOrderAndTypeCode(orderId, 'OC');
  const products = await orderProductRepository.forOrder(orderId);
  const productSummary = products
    .map((p) => `${p.description} — ${p.quantity_is_tbc ? 'TBC' : p.quantity} ${p.unit || ''}`)
    .join('; ');

  const snapshot = {
    quotation_ref: (qtDoc && qtDoc.document_reference) || null,
    quotation_date: qtDoc ? documentDataAssembler.formatDate(String(qtDoc.generated_at).substring(0, 10)) : null,
    pi_ref: (piDoc && piDoc.document_reference) || null,
    pi_date: piDoc ? documentDataAssembler.formatDate(String(piDoc.generated_at).substring(0, 10)) : null,
    oc_ref: (ocDoc && ocDoc.document_reference) || null,
    product_summary: productSummary,
    total_fob_value: formatMoney(fobValue),
    advance_trigger_text: order.advance_trigger_text,
    advance_terms_text: `${trimTrailingZeros(advancePct.toFixed(2))}% advance T/T on FOB Value ${order.advance_trigger_text}`,
    advance_amount: formatMoney(advanceAmount),
    balance_terms_text: `${trimTrailingZeros(balancePct.toFixed(2))}% balance T/T on FOB Value — ${documentDataAssembler.balanceTriggerSentence(order.balance_trigger_option || null, order.balance_days != null ? parseInt(order.balance_days, 10) : null)}`,
    balance_amount: formatMoney(fobValue - advanceAmount),
    freight_terms: order.incoterm_code,
    currency: order.currency_code,
    port_of_loading: order.port_of_loading_name || 'Chennai, India',
    // Incoterms® 2020: FOB names the port of LOADING; CFR/CIF name the port
    // of DISCHARGE — same bug/fix as documentDataAssembler.js.
    incoterm_label: `${order.incoterm_code} ${isFob ? (order.port_of_loading_name || 'Chennai, India') : portOfDischarge} — Incoterms® 2020`,
    lut_number: await companySettingsRepository.get('lut_number'),
    gstin: await companySettingsRepository.get('gstin'),
    iec_pan: await companySettingsRepository.get('iec_pan'),
  };

  const amendmentId = await amendmentRepository.create(
    reference,
    orderId,
    reason,
    requestedBy,
    snapshot,
    amendedAdvancePct,
    amendedAdvanceAmount,
    amendedBalanceTerms,
    amendedBalanceTriggerOption,
    amendedBalanceDays,
    amendedBalanceAmount,
    effectiveFrom
  );

  await auditLogRepository.log(requestedByUserId, 'AMENDMENT_REQUESTED', 'amendments', amendmentId, 'reason', null, reason);

  // Notify every MD/Admin so approval isn't stuck waiting on someone
  // stumbling across it — mirrors the reviewer-assignment pattern.
  const activeUsers = await userRepository.listActive();
  for (const user of activeUsers) {
    if (user.role_name === 'Admin' || user.role_name === 'Managing Director') {
      await notificationRepository.create(user.id, null, 'amendment_pending_md_approval', orderId, `Amendment ${reference} needs MD approval.`);
    }
  }

  return amendmentId;
}

async function approveByMd(amendmentId, mdUserId) {
  const amendment = await amendmentRepository.find(amendmentId);
  if (!amendment) {
    throw new Error(`Amendment ${amendmentId} not found`);
  }
  if (amendment.status !== 'pending') {
    throw new Error('Only a pending amendment can be MD-approved.');
  }
  await amendmentRepository.approveByMd(amendmentId, mdUserId);
  await auditLogRepository.log(mdUserId, 'AMENDMENT_MD_APPROVED', 'amendments', amendmentId);
}

async function rejectAmendment(amendmentId, userId) {
  await amendmentRepository.reject(amendmentId);
  await auditLogRepository.log(userId, 'AMENDMENT_REJECTED', 'amendments', amendmentId);
}

async function generateDocument(amendmentId, userId) {
  const amendment = await amendmentRepository.find(amendmentId);
  if (!amendment) {
    throw new Error(`Amendment ${amendmentId} not found`);
  }
  if (!['md_approved', 'signed', 'active'].includes(amendment.status)) {
    throw new Error('An amendment must be MD-approved before its agreement document can be generated.');
  }
  return documentGenerationService.generateAmendment(amendmentId, userId);
}

/**
 * Section 8: "Payment terms updated in system ONLY after signed copy is
 * uploaded." — this is that moment. Applies the override to the order
 * (orderRepository.applyAmendmentOverride) so every future document for it
 * picks up the new terms, and marks the amendment 'active'.
 */
async function attachSignedCopyAndActivate(amendmentId, signedCopyFileId, userId) {
  const amendment = await amendmentRepository.find(amendmentId);
  if (!amendment) {
    throw new Error(`Amendment ${amendmentId} not found`);
  }
  if (!['md_approved', 'signed'].includes(amendment.status)) {
    throw new Error('This amendment is not awaiting a signed copy (must be MD-approved first, and not already active).');
  }
  if (amendment.document_id === null || amendment.document_id === undefined) {
    throw new Error('Generate the Amendment Agreement document before uploading the signed copy.');
  }

  await amendmentRepository.activate(amendmentId, signedCopyFileId);

  const order = await orderRepository.find(amendment.order_id);

  const advancePct = amendment.amended_advance_pct !== null && amendment.amended_advance_pct !== undefined ? parseFloat(amendment.amended_advance_pct) : null;
  // Amended balance % isn't stored as its own column (only the structured
  // trigger option/days + free-text prose are) — the two percentages must
  // still sum to 100, so derive balance_pct from whichever side actually
  // changed rather than leaving the old value in place alongside a new
  // advance_pct.
  const balancePct = advancePct !== null ? Math.round((100 - advancePct) * 100) / 100 : parseFloat(order.balance_pct);
  const finalAdvancePct = advancePct !== null ? advancePct : parseFloat(order.advance_pct);

  // Structured balance-trigger fields drive what PI/CI templates actually
  // render (see orderRepository.find()'s COALESCE) — fall back to the
  // order's current values when this amendment didn't change that side of
  // the terms.
  const balanceTriggerOption = amendment.amended_balance_trigger_option || order.balance_trigger_option;
  const balanceDays = amendment.amended_balance_days !== null && amendment.amended_balance_days !== undefined
    ? parseInt(amendment.amended_balance_days, 10)
    : parseInt(order.balance_days, 10);

  await orderRepository.applyAmendmentOverride(amendment.order_id, finalAdvancePct, balancePct, balanceTriggerOption, balanceDays, amendmentId);

  await auditLogRepository.log(userId, 'AMENDMENT_ACTIVATED', 'amendments', amendmentId, 'payment_terms', null, amendment.amendment_reference);
}

module.exports = { createRequest, approveByMd, rejectAmendment, generateDocument, attachSignedCopyAndActivate };
