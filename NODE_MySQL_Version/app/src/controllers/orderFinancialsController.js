'use strict';

const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const caExportBenefitRepository = require('../repositories/caExportBenefitRepository');
const orderCostEntryRepository = require('../repositories/orderCostEntryRepository');
const orderRepository = require('../repositories/orderRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const caFyLockGuard = require('../services/caFyLockGuard');

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
  if (order.status !== 'complete' || !payment || !payment.balance_remittance_received_at) {
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
  res.redirect(`/orders/${orderId}#order-financials`);
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
  const incurredAt = String(req.body.incurred_at || '').trim() || new Date().toISOString().slice(0, 10);
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

  const id = await orderCostEntryRepository.create(orderId, category, description, parseFloat(amount), incurredAt, user.id);
  await auditLogRepository.log(user.id, 'ORDER_COST_ENTRY_RECORDED', 'order_cost_entries', id, null, null, category);
  flash.set(req, 'success', 'Cost entry recorded.');
  res.redirect(`/orders/${orderId}#order-financials`);
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

  await orderCostEntryRepository.remove(entryId);
  await auditLogRepository.log(user.id, 'ORDER_COST_ENTRY_DELETED', 'order_cost_entries', entryId, null, entry.category, null);
  flash.set(req, 'success', 'Cost entry removed.');
  res.redirect(`/orders/${orderId}#order-financials`);
}

module.exports = { addExportBenefit, markExportBenefitReceived, addCostEntry, deleteCostEntry };
