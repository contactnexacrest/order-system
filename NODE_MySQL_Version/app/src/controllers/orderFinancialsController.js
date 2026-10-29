'use strict';

const flash = require('../helpers/flash');
const orderStorage = require('../helpers/orderStorage');
const reasonValidator = require('../helpers/reasonValidator');
const auditLogRepository = require('../repositories/auditLogRepository');
const caExportBenefitRepository = require('../repositories/caExportBenefitRepository');
const orderCostEntryRepository = require('../repositories/orderCostEntryRepository');
const orderExportBenefitSchemeRepository = require('../repositories/orderExportBenefitSchemeRepository');
const orderLcDetailRepository = require('../repositories/orderLcDetailRepository');
const orderInspectionFeeRepository = require('../repositories/orderInspectionFeeRepository');
const orderTravelExpenseRepository = require('../repositories/orderTravelExpenseRepository');
const orderProductRepository = require('../repositories/orderProductRepository');
const orderRepository = require('../repositories/orderRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const zohoSyncLogRepository = require('../repositories/zohoSyncLogRepository');
const caFyLockGuard = require('../services/caFyLockGuard');
const zohoBooksService = require('../services/zohoBooksService');
const benefitSettlementService = require('../services/benefitSettlementService');
const fileUploadService = require('../services/fileUploadService');
const { todayIst } = require('../helpers/dbDateTime');


/**
 * Port of App\Controllers\OrderFinancialsController (docs/schema.sql
 * Section AP) — order-page-embedded financial actions: recording a
 * government export benefit claim or an "other order cost" directly
 * against a specific order, without leaving its page or typing the order
 * reference by hand (unlike the general CA module's own
 * /ca/export-benefits screen). Every action here requires
 * manage_order_financials — deliberately stricter than the general
 * ca_module_view/inr_actual_edit permissions (see docs/seed.sql), since
 * this surfaces per-order margin data.
 */

async function addExportBenefit(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  const payment = await orderPaymentStatusRepository.find(orderId);
  if (!['shipped_docs_sent', 'complete'].includes(order.status) || !payment || !payment.balance_remittance_received_at) {
    flash.set(req, 'error', 'Government export benefits only apply once the order is complete and the CI (balance) remittance has been received.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const user = req.user;

  const schemeName = String(req.body.scheme_name || '').trim();
  if (schemeName === '') {
    flash.set(req, 'error', 'Select a scheme.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const claimedAmount = String(req.body.claimed_amount || '').trim();
  if (claimedAmount === '' || Number.isNaN(Number(claimedAmount)) || Number(claimedAmount) <= 0) {
    flash.set(req, 'error', 'Enter a valid claimed amount.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const claimedAt = String(req.body.claimed_at || '').trim();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(claimedAt)) {
    flash.set(req, 'error', 'Enter a valid claim date.');
    res.redirect(`/orders/${orderId}`);
    return;
  }

  if (!(await caFyLockGuard.allow(req, claimedAt, 'ca_export_benefits', 0, 'record'))) {
    res.redirect(`/orders/${orderId}`);
    return;
  }

  const referenceNumber = String(req.body.reference_number || '').trim() || null;
  const notes = String(req.body.notes || '').trim() || null;
  const currencyCode = String(req.body.currency_code || '').trim() || 'INR';

  const id = await caExportBenefitRepository.record(orderId, schemeName, referenceNumber, parseFloat(claimedAmount), claimedAt, currencyCode, notes, user.id);
  await auditLogRepository.log(user.id, 'CA_EXPORT_BENEFIT_RECORDED', 'ca_export_benefits', id, null, null, schemeName);
  flash.set(req, 'success', `${schemeName} claim recorded for this order.`);
  res.redirect(`/orders/${orderId}#step-13g-benefits`);
}

async function markExportBenefitReceived(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const benefitId = parseInt(req.params.benefitId, 10);
  const benefit = await caExportBenefitRepository.find(benefitId);
  if (!benefit || Number(benefit.order_id || 0) !== orderId) {
    res.status(404).send('Claim not found for this order.');
    return;
  }
  const user = req.user;

  const receivedAmount = String(req.body.received_amount || '').trim();
  if (receivedAmount === '' || Number.isNaN(Number(receivedAmount)) || Number(receivedAmount) < 0) {
    flash.set(req, 'error', 'Enter a valid received amount.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const receivedAt = String(req.body.received_at || '').trim();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(receivedAt)) {
    flash.set(req, 'error', 'Enter a valid received date.');
    res.redirect(`/orders/${orderId}`);
    return;
  }

  if (!(await caFyLockGuard.allow(req, receivedAt, 'ca_export_benefits', benefitId, 'mark_received'))) {
    res.redirect(`/orders/${orderId}`);
    return;
  }

  await caExportBenefitRepository.markReceived(benefitId, parseFloat(receivedAmount), receivedAt);
  await auditLogRepository.log(user.id, 'CA_EXPORT_BENEFIT_RECEIVED', 'ca_export_benefits', benefitId, null, null, receivedAmount);
  flash.set(req, 'success', 'Marked as received.');
  res.redirect(`/orders/${orderId}#step-13g-benefits`);
}

/**
 * Owner request (2026-10-27): a wrongly typed claim can be corrected or
 * deleted — reason required, the old and new figures audit-logged, and the
 * CA financial-year lock respected for both the old and new dates. Returns
 * the claim, or null once a response was sent.
 */
async function benefitForChange(req, res, field) {
  const orderId = parseInt(req.params.id, 10);
  const benefitId = parseInt(req.params.benefitId, 10);
  const benefit = await caExportBenefitRepository.find(benefitId);
  if (!benefit || Number(benefit.order_id || 0) !== orderId) {
    res.status(404).send('Benefit claim not found for this order.');
    return null;
  }
  const error = reasonValidator.check(req.body.reason);
  if (error) {
    flash.set(req, 'error', error);
    res.redirect(`/orders/${orderId}#step-13g-benefits`);
    return null;
  }
  if (!(await caFyLockGuard.allow(req, String(benefit.claimed_at), 'ca_export_benefits', benefitId, field))) {
    res.redirect(`/orders/${orderId}#step-13g-benefits`);
    return null;
  }
  return benefit;
}

function describeBenefit(b) {
  const money = (n) => Number(n).toFixed(2);
  return `claimed ${money(b.claimed_amount)} on ${b.claimed_at} (ref ${b.reference_number || '—'}), received ${b.received_amount !== null ? `${money(b.received_amount)} on ${b.received_at}` : 'nothing'}`;
}

async function correctExportBenefit(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const benefit = await benefitForChange(req, res, 'correct');
  if (!benefit) {
    return;
  }
  const back = `/orders/${orderId}#step-13g-benefits`;
  const isDate = (d) => /^\d{4}-\d{2}-\d{2}$/.test(d) && !Number.isNaN(new Date(d).getTime());
  const claimed = String(req.body.claimed_amount || '').trim();
  const claimedAt = String(req.body.claimed_at || '').trim();
  const received = String(req.body.received_amount || '').trim();
  const receivedAt = String(req.body.received_at || '').trim();
  if (Number.isNaN(Number(claimed)) || !(Number(claimed) > 0) || !isDate(claimedAt)) {
    flash.set(req, 'error', 'Enter the claimed amount and the date it was claimed — nothing was changed.');
    res.redirect(back);
    return;
  }
  if (received !== '' && (Number.isNaN(Number(received)) || Number(received) < 0 || !isDate(receivedAt))) {
    flash.set(req, 'error', 'Enter a valid received amount and date (or leave the received amount empty if nothing has arrived yet) — nothing was changed.');
    res.redirect(back);
    return;
  }
  if (!(await caFyLockGuard.allow(req, claimedAt, 'ca_export_benefits', benefit.id, 'correct'))
    || (received !== '' && !(await caFyLockGuard.allow(req, receivedAt, 'ca_export_benefits', benefit.id, 'correct')))) {
    res.redirect(back);
    return;
  }
  await caExportBenefitRepository.correct(benefit.id, String(req.body.reference_number || '').trim() || null, parseFloat(claimed), claimedAt, received !== '' ? parseFloat(received) : null, received !== '' ? receivedAt : null);
  const after = await caExportBenefitRepository.find(benefit.id);
  await auditLogRepository.log(req.user.id, 'CA_EXPORT_BENEFIT_CORRECTED', 'ca_export_benefits', benefit.id, benefit.scheme_name, describeBenefit(benefit), describeBenefit(after), String(req.body.reason).trim());
  flash.set(req, 'success', `${benefit.scheme_name} claim corrected.`);
  res.redirect(back);
}

async function deleteExportBenefit(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const benefit = await benefitForChange(req, res, 'delete');
  if (!benefit) {
    return;
  }
  // Owner decision 2026-10-29: Zoho Books must match the app. A claim whose
  // receipt is in Books goes only once that journal is removed: clearing
  // the receipt does that on the next sync.
  if (benefit.zoho_journal_id) {
    flash.set(req, 'error', `This ${benefit.scheme_name} receipt is in Zoho Books. First correct the claim and leave the received amount empty, then run the Zoho sync (it removes the journal there), then delete the claim.`);
    res.redirect(`/orders/${orderId}#step-13g-benefits`);
    return;
  }
  await caExportBenefitRepository.remove(benefit.id);
  await auditLogRepository.log(req.user.id, 'CA_EXPORT_BENEFIT_DELETED', 'ca_export_benefits', benefit.id, benefit.scheme_name, describeBenefit(benefit), null, String(req.body.reason).trim());
  flash.set(req, 'success', `${benefit.scheme_name} claim deleted.`);
  res.redirect(`/orders/${orderId}#step-13g-benefits`);
}

/**
 * Owner decision (2026-10-27): at Step 13g an eligible scheme the company
 * will not claim — or whose money will never come — is skipped with a
 * reason (scheme_name set), or every scheme still open at once (scheme_name
 * empty). action=undo clears one scheme's skip.
 */
async function skipExportBenefitClaim(req, res) {
  const orderId = parseInt(req.params.id, 10);
  if (!(await orderRepository.find(orderId))) {
    res.status(404).send('Order not found.');
    return;
  }
  const back = `/orders/${orderId}#step-13g-benefits`;
  const userId = req.user.id;
  const schemeName = String(req.body.scheme_name || '').trim();
  const state = await benefitSettlementService.state(orderId);
  const bySettlement = Object.fromEntries(state.schemes.map((r) => [r.scheme_name, r.settlement]));

  if (req.body.action === 'undo') {
    if (bySettlement[schemeName] !== 'skipped') {
      flash.set(req, 'error', 'That scheme is not skipped.');
      res.redirect(back);
      return;
    }
    await orderExportBenefitSchemeRepository.setClaimSkipped(orderId, schemeName, null, userId);
    await auditLogRepository.log(userId, 'EXPORT_BENEFIT_CLAIM_SKIP_UNDONE', 'orders', orderId, 'scheme_name', schemeName, null);
    flash.set(req, 'success', `${schemeName} is open again in Step 13g.`);
    res.redirect(back);
    return;
  }

  const reason = String(req.body.reason || '').trim();
  const error = reasonValidator.check(reason);
  if (error) {
    flash.set(req, 'error', error);
    res.redirect(back);
    return;
  }
  const open = [...state.unclaimed, ...state.waiting];
  const targets = schemeName === '' ? open : (open.includes(schemeName) ? [schemeName] : []);
  if (targets.length === 0) {
    flash.set(req, 'error', schemeName === '' ? 'Every eligible scheme is already received or skipped.' : `${schemeName} is not an eligible scheme still waiting on this order.`);
    res.redirect(back);
    return;
  }
  for (const name of targets) {
    await orderExportBenefitSchemeRepository.setClaimSkipped(orderId, name, reason, userId);
    await auditLogRepository.log(userId, 'EXPORT_BENEFIT_CLAIM_SKIPPED', 'orders', orderId, 'scheme_name', null, name, reason);
  }
  flash.set(req, 'success', `Skipped: ${targets.join(', ')}.`);
  res.redirect(back);
}

/**
 * Eligibility decision for a government export-benefit scheme —
 * separate from (and a precondition of) actually recording a claim
 * above. 'not_eligible' requires a reason, same deliberate "never
 * silently skipped" rule as orderComplianceTaskRepository's skip_reason.
 */
async function setExportBenefitSchemeStatus(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  const user = req.user;

  if (req.body.schemes && typeof req.body.schemes === 'object') {
    await saveAllExportBenefitSchemes(req, res, orderId, user.id);
    return;
  }

  const schemeName = String(req.body.scheme_name || '').trim();
  const status = String(req.body.status || '').trim();
  if (schemeName === '' || !Object.prototype.hasOwnProperty.call(orderExportBenefitSchemeRepository.STATUSES, status) || status === 'pending') {
    flash.set(req, 'error', 'Select a valid scheme and eligibility status.');
    res.redirect(`/orders/${orderId}#order-financials`);
    return;
  }

  const reason = String(req.body.reason || '').trim();
  if (status === 'not_eligible' && reason === '') {
    flash.set(req, 'error', 'A reason is required to mark a scheme Not Eligible.');
    res.redirect(`/orders/${orderId}#order-financials`);
    return;
  }

  await orderExportBenefitSchemeRepository.setStatus(orderId, schemeName, status, status === 'not_eligible' ? reason : null, user.id);
  await auditLogRepository.log(user.id, 'EXPORT_BENEFIT_SCHEME_STATUS_UPDATED', 'orders', orderId, `export_benefit_scheme:${schemeName}`, null, status, status === 'not_eligible' ? reason : null);
  flash.set(req, 'success', `${schemeName} eligibility updated.`);
  res.redirect(`/orders/${orderId}#order-financials`);
}

/**
 * Owner request (2026-10-26): every scheme's eligibility saved in one
 * submit instead of one Save per row. Rows still left Pending are
 * untouched; only rows whose status or reason actually changed are written
 * (and audit-logged). All or nothing — if any row is invalid, nothing is
 * saved and the error names each scheme to fix.
 */
async function saveAllExportBenefitSchemes(req, res, orderId, userId) {
  const repo = orderExportBenefitSchemeRepository;
  const current = {};
  for (const row of await repo.forOrder(orderId)) {
    current[row.scheme_name] = row;
  }

  const changes = [];
  const errors = [];
  for (const row of Object.values(req.body.schemes)) {
    const schemeName = String((row && row.scheme_name) || '').trim();
    const status = String((row && row.status) || '').trim();
    const reason = String((row && row.reason) || '').trim();
    if (!current[schemeName] || !Object.prototype.hasOwnProperty.call(repo.STATUSES, status)) {
      errors.push(`select a valid status for ${schemeName || 'every scheme'}`);
      continue;
    }
    if (status === 'pending') {
      continue;
    }
    if (status === 'not_eligible' && [...reason].length < reasonValidator.MIN_LENGTH) {
      errors.push(`${schemeName} needs a reason of at least ${reasonValidator.MIN_LENGTH} characters to be Not Eligible`);
      continue;
    }
    const storedReason = status === 'not_eligible' ? reason : null;
    if (current[schemeName].status === status && (current[schemeName].reason ?? null) === storedReason) {
      continue;
    }
    changes.push([schemeName, status, storedReason]);
  }

  if (errors.length) {
    flash.set(req, 'error', `Nothing saved — ${errors.join('; ')}.`);
    res.redirect(`/orders/${orderId}#order-financials`);
    return;
  }
  for (const [schemeName, status, storedReason] of changes) {
    await repo.setStatus(orderId, schemeName, status, storedReason, userId);
    await auditLogRepository.log(userId, 'EXPORT_BENEFIT_SCHEME_STATUS_UPDATED', 'orders', orderId, `export_benefit_scheme:${schemeName}`, current[schemeName].status, status, storedReason);
  }
  flash.set(req, 'success', changes.length
    ? `Export benefit eligibility saved for ${changes.map((c) => c[0]).join(', ')}.`
    : 'No changes to save.');
  res.redirect(`/orders/${orderId}#order-financials`);
}

async function addCostEntry(req, res) {
  const orderId = parseInt(req.params.id, 10);
  if (!(await orderRepository.find(orderId))) {
    res.status(404).send('Order not found.');
    return;
  }
  const user = req.user;

  const category = String(req.body.category || '').trim();
  if (!Object.prototype.hasOwnProperty.call(orderCostEntryRepository.CATEGORIES, category)) {
    flash.set(req, 'error', 'Select a valid cost category.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const amount = String(req.body.amount_inr || '').trim();
  if (amount === '' || Number.isNaN(Number(amount)) || Number(amount) <= 0) {
    flash.set(req, 'error', 'Enter a valid amount.');
    res.redirect(`/orders/${orderId}`);
    return;
  }
  const incurredAt = String(req.body.incurred_at || '').trim() || todayIst();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(incurredAt)) {
    flash.set(req, 'error', 'Enter a valid date.');
    res.redirect(`/orders/${orderId}`);
    return;
  }

  if (!(await caFyLockGuard.allow(req, incurredAt, 'order_cost_entries', 0, 'record'))) {
    res.redirect(`/orders/${orderId}`);
    return;
  }

  const description = String(req.body.description || '').trim() || null;
  // Owner decision 2026-10-29: what the cost was paid from (it goes to Zoho Books from there).
  const paidFrom = String(req.body.paid_from || '');
  if (!Object.prototype.hasOwnProperty.call(orderCostEntryRepository.PAID_FROM, paidFrom)) {
    flash.set(req, 'error', 'Choose what the cost was paid from.');
    res.redirect(`/orders/${orderId}#order-financials`);
    return;
  }

  const id = await orderCostEntryRepository.create(orderId, category, description, parseFloat(amount), incurredAt, user.id, paidFrom);
  await auditLogRepository.log(user.id, 'ORDER_COST_ENTRY_RECORDED', 'order_cost_entries', id, null, null, category);
  flash.set(req, 'success', 'Cost entry recorded.');
  res.redirect(`/orders/${orderId}#order-financials`);
}

/**
 * Export process steps 5-1a/5-2a/5-3a: when a client opts for a Letter
 * of Credit, record the final LC document + LC number against the
 * order; if a bank loan was taken against that LC, record the loan
 * amount/interest rate/interest paid, and keep that interest in sync
 * with a single order_cost_entries row (category bank_loan_interest) —
 * the same expense ledger every other order cost already uses, rather
 * than a second, parallel place to look.
 */
async function saveLcDetails(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  const user = req.user;
  const existing = await orderLcDetailRepository.find(orderId);

  const lcNumber = String(req.body.lc_number || '').trim() || null;

  for (const [field, label] of [
    ['bank_loan_amount', 'bank loan amount'],
    ['bank_loan_interest_rate', 'interest rate'],
    ['bank_loan_interest_paid', 'interest paid'],
  ]) {
    const raw = String(req.body[field] || '').trim();
    if (raw !== '' && (Number.isNaN(Number(raw)) || Number(raw) < 0)) {
      flash.set(req, 'error', `Enter a valid ${label} (or leave it blank).`);
      res.redirect(`/orders/${orderId}#order-financials`);
      return;
    }
  }
  const bankLoanAmount = String(req.body.bank_loan_amount || '').trim() !== '' ? parseFloat(req.body.bank_loan_amount) : null;
  const interestRate = String(req.body.bank_loan_interest_rate || '').trim() !== '' ? parseFloat(req.body.bank_loan_interest_rate) : null;
  const interestPaid = String(req.body.bank_loan_interest_paid || '').trim() !== '' ? parseFloat(req.body.bank_loan_interest_paid) : null;

  // External QA report N-11: an interest rate is a percentage, so
  // anything above 100 (the report's example: 250%) is never plausible
  // — refused outright, same as the negative-value check above.
  // Interest paid exceeding the loan amount is merely unusual, not
  // impossible (e.g. cumulative interest across renewed years), so
  // that one is only ever a warning, never a block, shown after the
  // save below.
  if (interestRate !== null && interestRate > 100) {
    flash.set(req, 'error', 'Enter a valid interest rate — it cannot exceed 100%.');
    res.redirect(`/orders/${orderId}#order-financials`);
    return;
  }
  const interestExceedsLoan = interestPaid !== null && bankLoanAmount !== null && interestPaid > bankLoanAmount;

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
      flash.set(req, 'error', `LC/loan details were not saved — the LC file upload failed: ${flash.describeException(e, 'upload failed — check the server error log.')}`);
      res.redirect(`/orders/${orderId}#order-financials`);
      return;
    }
  }

  // Keep the single bank_loan_interest cost entry in sync: create it the
  // first time an interest amount is entered, update it on every
  // subsequent edit, and remove it if the amount is cleared back out —
  // this order's expense ledger should never show a stale figure or a
  // duplicate row for the same loan.
  let interestCostEntryId = existing ? existing.interest_cost_entry_id : null;
  let staleCostEntryIdToDelete = null;
  if (interestPaid !== null && interestPaid > 0) {
    if (interestCostEntryId) {
      await orderCostEntryRepository.updateAmount(interestCostEntryId, interestPaid, 'Bank loan interest (LC order)');
    } else {
      interestCostEntryId = await orderCostEntryRepository.create(
        orderId,
        'bank_loan_interest',
        'Bank loan interest (LC order)',
        interestPaid,
        todayIst(),
        user.id
      );
    }
  } else if (interestCostEntryId) {
    // order_lc_details.interest_cost_entry_id FK must stop pointing at
    // this row BEFORE it's deleted, not after — the upsert below clears
    // it to null first; only once that's committed is it safe to delete
    // the now-unreferenced order_cost_entries row.
    staleCostEntryIdToDelete = interestCostEntryId;
    interestCostEntryId = null;
  }

  await orderLcDetailRepository.upsert(orderId, {
    lc_number: lcNumber,
    lc_file_id: lcFileId,
    bank_loan_amount: bankLoanAmount,
    bank_loan_interest_rate: interestRate,
    bank_loan_interest_paid: interestPaid,
    interest_cost_entry_id: interestCostEntryId,
    recorded_by: user.id,
  });
  if (staleCostEntryIdToDelete !== null) {
    await orderCostEntryRepository.remove(staleCostEntryIdToDelete);
  }
  await auditLogRepository.log(user.id, 'ORDER_LC_DETAILS_SAVED', 'order_lc_details', orderId, null, null, lcNumber);
  if (interestExceedsLoan) {
    flash.set(req, 'warning', 'LC / bank loan details saved — but the interest paid is more than the loan amount itself. Double-check this is correct.');
  } else {
    flash.set(req, 'success', 'LC / bank loan details saved.');
  }
  res.redirect(`/orders/${orderId}#order-financials`);
}

/**
 * Export process step 8a: who pays the 3rd-party pre-shipment
 * inspection fee for this order. When NexaCrest pays, the amount is
 * kept in sync with a single order_cost_entries row (category
 * inspection_fee), same pattern as the LC bank loan interest sync
 * above — if the client pays instead (or no amount is given), any
 * previously-synced expense entry is removed, since NexaCrest no
 * longer incurred that cost.
 */
async function saveInspectionFee(req, res) {
  const orderId = parseInt(req.params.id, 10);
  if (!(await orderRepository.find(orderId))) {
    res.status(404).send('Order not found.');
    return;
  }
  const user = req.user;
  const existing = await orderInspectionFeeRepository.find(orderId);

  const feePayer = String(req.body.fee_payer || '').trim();
  if (!['nexacrest', 'client'].includes(feePayer)) {
    flash.set(req, 'error', 'Select who pays the inspection fee.');
    res.redirect(`/orders/${orderId}#order-financials`);
    return;
  }
  const rawAmount = String(req.body.fee_amount || '').trim();
  if (rawAmount !== '' && (Number.isNaN(Number(rawAmount)) || Number(rawAmount) < 0)) {
    flash.set(req, 'error', 'Enter a valid fee amount (or leave it blank).');
    res.redirect(`/orders/${orderId}#order-financials`);
    return;
  }
  const feeAmount = rawAmount !== '' ? parseFloat(rawAmount) : null;
  // Owner decision 2026-10-29: when NexaCrest pays, what it was paid from (the fee goes to Zoho Books from there).
  const paidFrom = String(req.body.paid_from || '');
  if (feePayer === 'nexacrest' && feeAmount !== null && feeAmount > 0 && !Object.prototype.hasOwnProperty.call(orderCostEntryRepository.PAID_FROM, paidFrom)) {
    flash.set(req, 'error', 'Choose what the inspection fee was paid from.');
    res.redirect(`/orders/${orderId}#order-financials`);
    return;
  }

  let costEntryId = existing ? existing.cost_entry_id : null;
  let staleCostEntryIdToDelete = null;
  if (feePayer === 'nexacrest' && feeAmount !== null && feeAmount > 0) {
    if (costEntryId) {
      await orderCostEntryRepository.updateAmount(costEntryId, feeAmount, 'Pre-shipment inspection fee');
    } else {
      costEntryId = await orderCostEntryRepository.create(orderId, 'inspection_fee', 'Pre-shipment inspection fee', feeAmount, todayIst(), user.id);
    }
    await orderCostEntryRepository.setPaidFrom(costEntryId, paidFrom);
  } else if (costEntryId) {
    // A fee already in Zoho Books is removed there first (owner decision 2026-10-29).
    const zohoError = await removeFromZoho((await orderCostEntryRepository.find(costEntryId)) || {}, user.id);
    if (zohoError !== null) {
      flash.set(req, 'error', zohoError);
      res.redirect(`/orders/${orderId}#order-financials`);
      return;
    }
    // order_inspection_fees.cost_entry_id FK must stop pointing at this
    // row BEFORE it's deleted, not after — same ordering as the LC
    // interest sync above.
    staleCostEntryIdToDelete = costEntryId;
    costEntryId = null;
  }

  await orderInspectionFeeRepository.upsert(orderId, { fee_payer: feePayer, fee_amount: feeAmount, cost_entry_id: costEntryId, recorded_by: user.id });
  if (staleCostEntryIdToDelete !== null) {
    await orderCostEntryRepository.remove(staleCostEntryIdToDelete);
  }
  await auditLogRepository.log(user.id, 'ORDER_INSPECTION_FEE_SAVED', 'order_inspection_fees', orderId, 'fee_payer', null, feePayer);
  flash.set(req, 'success', 'Inspection fee details saved.');
  res.redirect(`/orders/${orderId}#order-financials`);
}

/**
 * docs/schema.sql Section BW — export process step 11a. The
 * distance-to-percentage mapping is a business judgement call
 * (5/7/10/15%), not a fixed formula, so staff chooses the percentage
 * themselves; this just computes and records the resulting amount
 * against the order's own FOB value and keeps it synced into
 * order_cost_entries.
 */
async function saveTravelExpenses(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  const user = req.user;
  const existing = await orderTravelExpenseRepository.find(orderId);

  const selectedPct = String(req.body.expense_pct || '').trim();
  const rawPct = selectedPct === 'other' ? String(req.body.expense_pct_other || '').trim() : selectedPct;
  if (rawPct === '' || Number.isNaN(Number(rawPct)) || Number(rawPct) <= 0 || Number(rawPct) > 100) {
    flash.set(req, 'error', 'Enter a valid travel expense percentage (greater than 0, up to 100).');
    res.redirect(`/orders/${orderId}#order-financials`);
    return;
  }
  const expensePct = parseFloat(rawPct);

  const rawDistance = String(req.body.factory_distance_km || '').trim();
  if (rawDistance !== '' && (Number.isNaN(Number(rawDistance)) || Number(rawDistance) < 0)) {
    flash.set(req, 'error', 'Enter a valid factory distance in km (or leave it blank).');
    res.redirect(`/orders/${orderId}#order-financials`);
    return;
  }
  const factoryDistanceKm = rawDistance !== '' ? parseFloat(rawDistance) : null;

  const fobTotal = await orderProductRepository.totalFobValue(orderId);

  /**
   * External QA report N-03: this used to write pct% of the FOB value —
   * which is in the order's OWN currency (e.g. USD) — straight into
   * order_cost_entries.amount_inr with no conversion, understating the
   * cost by the exchange rate on every foreign-currency order. Converts
   * at the same rate orderProfitabilityService's revenueInInr() falls
   * back to for an order with no per-leg actual yet
   * (advance_exchange_rate ?? balance_exchange_rate) — the best "the
   * order's exchange rate" available, since advance/balance each carry
   * their own rate rather than one shared order-wide rate (Batch 3 #3).
   * Refuses rather than silently store a wrong figure if neither is
   * known yet.
   */
  const currencyCode = order.currency_code || 'INR';
  let rate;
  if (currencyCode === 'INR') {
    rate = 1.0;
  } else {
    const payment = await orderPaymentStatusRepository.find(orderId);
    const advanceRate = payment && payment.advance_exchange_rate != null ? Number(payment.advance_exchange_rate) : null;
    const balanceRate = payment && payment.balance_exchange_rate != null ? Number(payment.balance_exchange_rate) : null;
    rate = advanceRate ?? balanceRate;
    if (rate == null) {
      flash.set(req, 'error', "Travel expenses need this order's exchange rate — record the advance or balance payment's exchange rate first.");
      res.redirect(`/orders/${orderId}#order-financials`);
      return;
    }
  }
  const expenseAmount = Math.round((expensePct / 100) * fobTotal * rate * 100) / 100;

  let costEntryId = existing ? existing.cost_entry_id : null;
  if (costEntryId) {
    await orderCostEntryRepository.updateAmount(costEntryId, expenseAmount, 'Travel expenses (factory visits)');
  } else {
    costEntryId = await orderCostEntryRepository.create(orderId, 'travel_expenses', 'Travel expenses (factory visits)', expenseAmount, todayIst(), user.id);
  }

  await orderTravelExpenseRepository.upsert(orderId, {
    factory_distance_km: factoryDistanceKm,
    expense_pct: expensePct,
    expense_amount: expenseAmount,
    cost_entry_id: costEntryId,
    recorded_by: user.id,
  });
  await auditLogRepository.log(user.id, 'ORDER_TRAVEL_EXPENSE_SAVED', 'order_travel_expenses', orderId, 'expense_pct', null, String(expensePct));
  flash.set(req, 'success', 'Travel expenses saved.');
  res.redirect(`/orders/${orderId}#order-financials`);
}

/**
 * Owner decision 2026-10-29: Zoho Books must match the app — a cost entry
 * already in Books is removed there first. Returns why it could not be, or
 * null.
 */
async function removeFromZoho(entry, userId) {
  if (!entry.zoho_expense_id) {
    return null;
  }
  try {
    await zohoBooksService.deleteRecord('expense', String(entry.zoho_expense_id));
  } catch (e) {
    return `This cost is in Zoho Books and could not be removed there, so nothing was changed: ${e.message}`;
  }
  await zohoSyncLogRepository.log('order_cost', 'order', Number(entry.order_id), 'cost', 'success', String(entry.zoho_expense_id), `Removed order cost "${orderCostEntryRepository.CATEGORIES[entry.category] || entry.category}" (INR ${Number(entry.amount_inr).toFixed(2)}) from Zoho Books: it was removed in the app.`, 'manual', userId);
  return null;
}

/**
 * Owner decision 2026-10-29: records the money the bank actually paid
 * under the LC — the date, the amount in the order currency, the rupees
 * credited and the bank charges deducted. It goes to Zoho Books as a
 * customer payment applied to the CI, so it can be corrected only until
 * then.
 */
async function recordLcReceived(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  const back = (type, message) => {
    flash.set(req, type, message);
    res.redirect(`/orders/${orderId}#lc-received`);
  };
  const lc = await orderLcDetailRepository.find(orderId);
  if ((order.payment_method || '') !== 'LC' || !lc || lc.lc_confirmed_at === null) {
    back('error', 'Record the LC and confirm it usable (Step 5) first.');
    return;
  }
  if (lc.lc_zoho_synced_at !== null) {
    back('error', 'This LC payment is already in Zoho Books, so it cannot be changed here. Ask the CA to correct it there.');
    return;
  }
  const currency = String(order.currency_code || 'INR');
  const receivedAt = String(req.body.lc_received_at || '').trim();
  const amount = String(req.body.lc_received_amount || '').trim();
  const inr = String(req.body.lc_inr_credited || '').trim();
  const charges = String(req.body.lc_bank_charges_inr || '').trim() || '0';
  const isNumeric = (v) => v !== '' && /^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/.test(v);
  const m = receivedAt.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  const d = m ? new Date(`${receivedAt}T00:00:00Z`) : null;
  const isRealDate = d && d.getUTCFullYear() === Number(m[1]) && d.getUTCMonth() + 1 === Number(m[2]) && d.getUTCDate() === Number(m[3]);
  if (!isRealDate || receivedAt > todayIst()) {
    back('error', 'Enter the date the bank paid (not in the future).');
    return;
  }
  if (!isNumeric(amount) || Number(amount) <= 0) {
    back('error', `Enter the amount paid under the LC (${currency}).`);
    return;
  }
  if (!isNumeric(inr) || Number(inr) <= 0) {
    back('error', 'Enter the rupees credited to the bank account.');
    return;
  }
  if (!isNumeric(charges) || Number(charges) < 0) {
    back('error', 'Enter the bank charges deducted (₹), or 0.');
    return;
  }
  if (!(await caFyLockGuard.allow(req, receivedAt, 'order_lc_details', orderId, 'record'))) {
    res.redirect(`/orders/${orderId}#lc-received`);
    return;
  }
  await orderLcDetailRepository.recordReceived(orderId, receivedAt, Number(amount), Number(inr), Number(charges), req.user.id);
  await auditLogRepository.log(req.user.id, 'ORDER_LC_PAYMENT_RECEIVED', 'order_lc_details', orderId, 'lc_received_amount', lc.lc_received_amount, amount, `${currency} ${amount} on ${receivedAt}: ₹${inr} credited, ₹${charges} bank charges`);
  back('success', `LC payment recorded: ${currency} ${amount}, ₹${inr} credited.`);
}

/**
 * Owner decision 2026-10-29 (CAFIN change confirmed twice): the LC
 * payment's FIRC / eBRC realization proof, like the other legs'.
 */
async function recordLcFirc(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const reference = String(req.body.lc_firc_reference || '').trim();
  const back = (type, message) => {
    flash.set(req, type, message);
    res.redirect(`/orders/${orderId}#lc-received`);
  };
  if (reference === '' || [...reference].length > 100) {
    back('error', 'Enter the FIRC/eBRC reference.');
    return;
  }
  const lc = await orderLcDetailRepository.find(orderId);
  if (!lc || lc.lc_received_at === null) {
    back('error', 'Record the LC payment itself before entering its FIRC/eBRC reference.');
    return;
  }
  if (!(await caFyLockGuard.allow(req, String(lc.lc_received_at), 'order_lc_details', orderId, 'lc_firc_reference'))) {
    res.redirect(`/orders/${orderId}#lc-received`);
    return;
  }
  const receivedAt = String(req.body.lc_firc_received_at || '').trim() || todayIst();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(receivedAt)) {
    back('error', 'Enter a valid FIRC/eBRC date.');
    return;
  }
  await orderLcDetailRepository.setFirc(orderId, reference, receivedAt);
  await auditLogRepository.log(req.user.id, 'CA_FIRC_RECORDED', 'order_lc_details', orderId, 'lc_firc_reference', lc.lc_firc_reference, reference);
  back('success', 'LC payment FIRC/eBRC reference recorded.');
}

async function deleteCostEntry(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const entryId = parseInt(req.params.entryId, 10);
  const entry = await orderCostEntryRepository.find(entryId);
  if (!entry || Number(entry.order_id) !== orderId) {
    res.status(404).send('Cost entry not found for this order.');
    return;
  }
  const user = req.user;

  if (!(await caFyLockGuard.allow(req, entry.incurred_at, 'order_cost_entries', entryId, 'delete'))) {
    res.redirect(`/orders/${orderId}`);
    return;
  }

  const zohoError = await removeFromZoho(entry, user.id);
  if (zohoError !== null) {
    flash.set(req, 'error', zohoError);
    res.redirect(`/orders/${orderId}#order-financials`);
    return;
  }
  await orderCostEntryRepository.remove(entryId);
  await auditLogRepository.log(user.id, 'ORDER_COST_ENTRY_DELETED', 'order_cost_entries', entryId, null, entry.category, null);
  flash.set(req, 'success', entry.zoho_expense_id ? 'Cost entry removed, here and in Zoho Books.' : 'Cost entry removed.');
  res.redirect(`/orders/${orderId}#order-financials`);
}

/**
 * Owner request (2026-10-26, t4): Step 12 can be skipped and marked complete
 * when there is nothing to record (reason required, audit-logged). No cost,
 * benefit or expense is written — the figures stay blank rather than
 * reading as 0. Export-benefit schemes still have to be answered before the
 * order can be marked fully settled.
 */
async function skipFinancials(req, res) {
  const orderId = parseInt(req.params.id, 10);
  if (!(await orderRepository.find(orderId))) {
    res.status(404).send('Order not found.');
    return;
  }
  const reason = String(req.body.skip_reason || '').trim();
  const error = reasonValidator.check(reason);
  if (error) {
    flash.set(req, 'error', error);
    res.redirect(`/orders/${orderId}#order-financials`);
    return;
  }
  await orderRepository.markFinancialsSkipped(orderId, req.user.id, reason);
  await auditLogRepository.log(req.user.id, 'ORDER_FINANCIALS_SKIPPED', 'orders', orderId, 'financials_skipped_at', null, new Date().toISOString().slice(0, 19).replace('T', ' '), reason);
  flash.set(req, 'success', 'Step 12 marked complete without financial figures — nothing was recorded as 0. You can still add figures later.');
  res.redirect(`/orders/${orderId}#order-financials`);
}

async function undoSkipFinancials(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  await orderRepository.clearFinancialsSkipped(orderId);
  await auditLogRepository.log(req.user.id, 'ORDER_FINANCIALS_SKIP_UNDONE', 'orders', orderId, 'financials_skipped_at', String(order.financials_skipped_at || ''), null);
  flash.set(req, 'success', 'Step 12 reopened.');
  res.redirect(`/orders/${orderId}#order-financials`);
}

/**
 * Owner request (2026-10-26): Step 12 in guided sub-steps. 12a — the
 * order's costs are marked complete (or reopened to add more).
 */
async function confirmCosts(req, res) {
  const orderId = parseInt(req.params.id, 10);
  if (!(await orderRepository.find(orderId))) {
    res.status(404).send('Order not found.');
    return;
  }
  const reopen = req.body.action === 'reopen';
  await orderRepository.setFinancialsCostsConfirmed(orderId, reopen ? null : req.user.id);
  await auditLogRepository.log(req.user.id, reopen ? 'ORDER_COSTS_REOPENED' : 'ORDER_COSTS_CONFIRMED', 'orders', orderId, 'financials_costs_confirmed_at', null, null);
  flash.set(req, 'success', reopen ? 'Costs reopened (12a).' : 'Costs marked complete (12a). Next: export benefit eligibility (12b).');
  res.redirect(`/orders/${orderId}#order-financials`);
}

/**
 * 12d — Step 12 marked complete once costs are confirmed (12a) and every
 * scheme's eligibility is answered (12b). Benefit money (12c) can still
 * arrive later and be recorded then.
 */
async function completeFinancials(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  if (req.body.action === 'reopen') {
    await orderRepository.setFinancialsCompleted(orderId, null);
    await auditLogRepository.log(req.user.id, 'ORDER_FINANCIALS_REOPENED', 'orders', orderId, 'financials_completed_at', null, null);
    flash.set(req, 'success', 'Step 12 reopened.');
    res.redirect(`/orders/${orderId}#order-financials`);
    return;
  }
  const missing = [];
  if (!order.financials_costs_confirmed_at) {
    missing.push('mark the costs complete (12a)');
  }
  if ((await orderExportBenefitSchemeRepository.summaryForOrder(orderId)).pending > 0) {
    missing.push("answer every scheme's eligibility (12b)");
  }
  if (missing.length) {
    flash.set(req, 'error', `Before completing Step 12: ${missing.join(' and ')}.`);
    res.redirect(`/orders/${orderId}#order-financials`);
    return;
  }
  await orderRepository.setFinancialsCompleted(orderId, req.user.id);
  await auditLogRepository.log(req.user.id, 'ORDER_FINANCIALS_COMPLETED', 'orders', orderId, 'financials_completed_at', null, new Date().toISOString().slice(0, 19).replace('T', ' '));
  flash.set(req, 'success', 'Step 12 complete. Benefit money still to come can be recorded in 12c whenever it arrives.');
  res.redirect(`/orders/${orderId}#order-financials`);
}

/**
 * 12c — one Save for the benefits table: a row with no claim yet and a
 * claimed amount records the claim; a row whose claim is still waiting and
 * has a received amount records the money. All rows are checked before
 * anything is written.
 */
async function saveExportBenefits(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  const back = () => res.redirect(`/orders/${orderId}#step-13g-benefits`);
  const fail = (message) => {
    flash.set(req, 'error', `${message} Nothing was saved.`);
    back();
  };
  const isDate = (d) => /^\d{4}-\d{2}-\d{2}$/.test(d);
  const isAmount = (a, allowZero) => a !== '' && !Number.isNaN(Number(a)) && (allowZero ? Number(a) >= 0 : Number(a) > 0);
  const text = (v) => String(v ?? '').trim();

  const claims = [];
  const receipts = [];
  for (const row of Object.values(req.body.benefits || {})) {
    const benefitId = parseInt(row.benefit_id, 10) || 0;
    const receivedAmount = text(row.received_amount);
    const receivedAt = text(row.received_at);
    if (benefitId === 0) {
      const claimedAmount = text(row.claimed_amount);
      if (claimedAmount === '') continue;
      const scheme = text(row.scheme_name);
      const claimedAt = text(row.claimed_at);
      if (scheme === '' || !isAmount(claimedAmount, false) || !isDate(claimedAt)) {
        fail(`${scheme}: enter the claimed amount and the date it was claimed.`);
        return;
      }
      claims.push({ scheme, reference: text(row.reference_number) || null, claimedAmount: parseFloat(claimedAmount), claimedAt, receivedAmount, receivedAt });
      continue;
    }
    const benefit = await caExportBenefitRepository.find(benefitId);
    if (!benefit || Number(benefit.order_id) !== orderId) {
      fail('A claim on this form does not belong to this order.');
      return;
    }
    if (receivedAmount !== '' && benefit.received_amount === null) {
      receipts.push({ benefitId, receivedAmount, receivedAt });
    }
  }
  for (const [amount, at, label] of [...claims.map((c) => [c.receivedAmount, c.receivedAt, c.scheme]), ...receipts.map((r) => [r.receivedAmount, r.receivedAt, 'A claim'])]) {
    if (amount !== '' && (!isAmount(amount, true) || !isDate(at))) {
      fail(`${label}: enter the received amount and the date it was received.`);
      return;
    }
  }
  if (!claims.length && !receipts.length) {
    flash.set(req, 'success', 'Nothing new to save in 12c.');
    back();
    return;
  }
  const payment = await orderPaymentStatusRepository.find(orderId);
  if (claims.length && (!['shipped_docs_sent', 'complete'].includes(order.status) || !payment || !payment.balance_remittance_received_at)) {
    fail('Claims can be recorded once the documents are sent (13f) and the balance remittance is received.');
    return;
  }
  for (const c of claims) {
    if (!(await caFyLockGuard.allow(req, c.claimedAt, 'ca_export_benefits', 0, 'record'))
      || (c.receivedAmount !== '' && !(await caFyLockGuard.allow(req, c.receivedAt, 'ca_export_benefits', 0, 'mark_received')))) {
      back();
      return;
    }
  }
  for (const r of receipts) {
    if (!(await caFyLockGuard.allow(req, r.receivedAt, 'ca_export_benefits', r.benefitId, 'mark_received'))) {
      back();
      return;
    }
  }

  for (const c of claims) {
    const id = await caExportBenefitRepository.record(orderId, c.scheme, c.reference, c.claimedAmount, c.claimedAt, 'INR', null, req.user.id);
    await auditLogRepository.log(req.user.id, 'CA_EXPORT_BENEFIT_RECORDED', 'ca_export_benefits', id, null, null, c.scheme);
    if (c.receivedAmount !== '') {
      receipts.push({ benefitId: id, receivedAmount: c.receivedAmount, receivedAt: c.receivedAt });
    }
  }
  for (const r of receipts) {
    await caExportBenefitRepository.markReceived(r.benefitId, parseFloat(r.receivedAmount), r.receivedAt);
    await auditLogRepository.log(req.user.id, 'CA_EXPORT_BENEFIT_RECEIVED', 'ca_export_benefits', r.benefitId, null, null, r.receivedAmount);
  }
  flash.set(req, 'success', `Benefits saved — ${claims.length} claim(s) recorded, ${receipts.length} receipt(s) recorded.`);
  back();
}

module.exports = { skipExportBenefitClaim, correctExportBenefit, deleteExportBenefit, confirmCosts, completeFinancials, saveExportBenefits, skipFinancials, undoSkipFinancials, addExportBenefit, markExportBenefitReceived, setExportBenefitSchemeStatus, addCostEntry, deleteCostEntry, saveLcDetails, recordLcReceived, recordLcFirc, saveInspectionFee, saveTravelExpenses };
