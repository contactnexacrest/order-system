'use strict';

const amendmentRepository = require('../repositories/amendmentRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const documentRepository = require('../repositories/documentRepository');
const notificationRepository = require('../repositories/notificationRepository');
const orderProductRepository = require('../repositories/orderProductRepository');
const orderRepository = require('../repositories/orderRepository');
const permissionRepository = require('../repositories/permissionRepository');
const referenceNumberService = require('./referenceNumberService');
const documentDataAssembler = require('./documentDataAssembler');
const documentGenerationService = require('./documentGenerationService');
const makerCheckerGuard = require('./makerCheckerGuard');

/**
 * Spec Section 8 — PAYMENT TERMS AMENDMENT SYSTEM (SC/AMD). Orchestrates
 * amendmentRepository + referenceNumberService + documentGenerationService
 * + orderRepository's own order-level payment-term snapshot columns. See
 * amendmentRepository's docblock for why original_terms_snapshot is
 * frozen once, at request time.
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
  // 90-item backlog "ports city field": same fix as
  // documentDataAssembler — Incoterms convention prints the CITY alone,
  // never the formal terminal name.
  const portOfLoading = order.port_of_loading_city || order.port_of_loading_name || 'Chennai';
  const portOfDischarge = order.port_of_discharge_city || order.port_of_discharge_name || order.port_of_discharge_text || 'To Be Confirmed';

  const qtDoc = await documentRepository.findLatestForOrderAndTypeCode(orderId, 'QT');
  const piDoc = await documentRepository.findLatestForOrderAndTypeCode(orderId, 'PI');
  const ocDoc = await documentRepository.findLatestForOrderAndTypeCode(orderId, 'OC');
  const products = await orderProductRepository.forOrder(orderId);
  const productSummary = products
    .map((p) => `${p.description} — ${p.quantity_is_tbc ? 'To Be Confirmed' : p.quantity} ${p.unit || ''}`)
    .join('; ');

  const snapshot = {
    quotation_ref: (qtDoc && qtDoc.document_reference) || null,
    // User-reported batch item 6: generated_at is a UTC-stored TIMESTAMP
    // (see config/db.js) — truncating it to its first 10 characters
    // before formatDate() took the raw UTC calendar date, which can be a
    // day behind the correct IST one. formatDate() itself now converts a
    // time-bearing value from UTC before reading its calendar date, so
    // the full value is passed through instead of a pre-truncated one.
    quotation_date: qtDoc ? documentDataAssembler.formatDate(qtDoc.generated_at) : null,
    pi_ref: (piDoc && piDoc.document_reference) || null,
    pi_date: piDoc ? documentDataAssembler.formatDate(piDoc.generated_at) : null,
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
    port_of_loading: portOfLoading,
    // Incoterms® 2020: FOB names the port of LOADING; CFR/CIF name the port
    // of DISCHARGE — same bug/fix as documentDataAssembler.js.
    incoterm_label: `${order.incoterm_code} ${isFob ? portOfLoading : portOfDischarge} — Incoterms® 2020`,
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
    effectiveFrom,
    requestedByUserId
  );

  await auditLogRepository.log(requestedByUserId, 'AMENDMENT_REQUESTED', 'amendments', amendmentId, 'reason', null, reason);

  // Notify whoever can actually approve it (approve_documents — the same
  // permission /amendments/:id/md-approve is gated on) so this isn't stuck
  // waiting on someone stumbling across it — mirrors the
  // reviewer-assignment pattern. Checked by permission, not a hardcoded
  // role name, since roles can be renamed via /admin/roles.
  const approverIds = await permissionRepository.usersWithPermission('approve_documents');
  for (const userId of approverIds) {
    await notificationRepository.create(userId, null, 'amendment_pending_md_approval', orderId, `Amendment ${reference} needs MD approval.`);
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
  // QA-5 maker-checker (AMD-05): whoever filed this amendment request must
  // not also be the one who MD-approves it, unless they're a Super Admin
  // or hold manage_permissions (owner decision).
  if (
    amendment.created_by !== null
    && amendment.created_by !== undefined
    && Number(amendment.created_by) === Number(mdUserId)
    && !(await makerCheckerGuard.selfApprovalAllowed(mdUserId))
  ) {
    throw new Error('You requested this amendment — a different privileged user must MD-approve it.');
  }
  await amendmentRepository.approveByMd(amendmentId, mdUserId);
  await auditLogRepository.log(mdUserId, 'AMENDMENT_MD_APPROVED', 'amendments', amendmentId);
}

async function rejectAmendment(amendmentId, userId) {
  // QA-4 P0.5: every sibling transition here (approveByMd, generateDocument,
  // attachSignedCopyAndActivate) re-validates the amendment's current status
  // before acting — this one didn't, and amendmentRepository.reject()
  // unconditionally overwrites status to 'rejected' with no WHERE-clause
  // guard of its own. Without this, rejecting an already-'active' amendment
  // (whose override was already applied to the order's payment terms via
  // attachSignedCopyAndActivate) would silently relabel it 'rejected' while
  // the order kept the amended terms.
  const amendment = await amendmentRepository.find(amendmentId);
  if (!amendment) {
    throw new Error(`Amendment ${amendmentId} not found`);
  }
  if (amendment.status !== 'pending') {
    throw new Error('Only a pending amendment can be rejected.');
  }
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
  // D-215 (5a): an order with its own schedule keeps its milestones — the balance is what is left after them.
  const milestonePct = await require('../repositories/paymentMilestoneRepository').totalPct(Number(amendment.order_id));
  const balancePct = advancePct !== null ? Math.round((100 - advancePct - milestonePct) * 100) / 100 : parseFloat(order.balance_pct);
  const finalAdvancePct = advancePct !== null ? advancePct : parseFloat(order.advance_pct);

  // Structured balance-trigger fields drive what PI/CI templates actually
  // render — fall back to the order's current values when this amendment
  // didn't change that side of the terms.
  const balanceTriggerOption = amendment.amended_balance_trigger_option || order.balance_trigger_option;
  const balanceDays = amendment.amended_balance_days !== null && amendment.amended_balance_days !== undefined
    ? parseInt(amendment.amended_balance_days, 10)
    : parseInt(order.balance_days, 10);

  // The order's own snapshotted balance_trigger_wording is custom free
  // text written for whichever option the order started with — it stays
  // valid only while that option is unchanged. If this amendment actually
  // flips the option, keeping the old wording would print text written
  // for the OTHER option alongside the new one, so it's cleared instead
  // and documentDataAssembler.balanceTriggerSentence() falls back to its
  // generic built-in sentence for the new option.
  const balanceTriggerWording = balanceTriggerOption === order.balance_trigger_option ? order.balance_trigger_wording : null;

  await orderRepository.applyAmendmentOverride(amendment.order_id, finalAdvancePct, balancePct, balanceTriggerOption, balanceDays, balanceTriggerWording, amendmentId);

  await auditLogRepository.log(userId, 'AMENDMENT_ACTIVATED', 'amendments', amendmentId, 'payment_terms', null, amendment.amendment_reference);
}

module.exports = { createRequest, approveByMd, rejectAmendment, generateDocument, attachSignedCopyAndActivate };
