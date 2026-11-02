'use strict';

const db = require('../config/db');
const flash = require('../helpers/flash');
const csv = require('../helpers/csv');
const auditLogRepository = require('../repositories/auditLogRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const fileUploadService = require('../services/fileUploadService');
const moneyVisibility = require('../services/moneyVisibility');
const complianceTrackingService = require('../services/complianceTrackingService');

/**
 * Port of ComplianceTrackingController.php. Owner decision (2 Nov 2026, gap-list Q-6):
 * ECGC buyer limit and shipment declaration, incentive claim tracking, and the
 * Missing eBRC / FIRC report.
 */

const DATE = /^\d{4}-\d{2}-\d{2}$/;

function back(req, res, url) {
  return res.redirect(url);
}

async function saveClientEcgc(req, res) {
  const clientId = Number(req.params.id);
  const client = await db.queryOne('SELECT * FROM clients WHERE id = :id', { id: clientId });
  if (!client) return res.status(404).send('Client not found.');
  const url = `/clients/${clientId}#ecgc`;
  const b = req.body || {};
  const amount = String(b.ecgc_limit_amount || '').trim();
  const currency = String(b.ecgc_limit_currency || '').trim().toUpperCase();
  const approvedOn = String(b.ecgc_limit_approved_on || '').trim();
  const validTo = String(b.ecgc_limit_valid_to || '').trim();
  const ref = String(b.ecgc_limit_ref || '').trim();
  let error = null;
  if (amount === '' || !/^\d+(\.\d+)?$/.test(amount) || Number(amount) > 9999999999) {
    error = 'Enter the credit limit ECGC approved for this buyer (a number, 0 or more).';
  } else if (!/^[A-Z]{3}$/.test(currency)) {
    error = 'Choose the currency of the ECGC limit.';
  } else if (approvedOn !== '' && !DATE.test(approvedOn)) {
    error = 'Enter the approval date as a date.';
  } else if (validTo !== '' && !DATE.test(validTo)) {
    error = 'Enter the "valid until" date as a date.';
  } else if (validTo !== '' && approvedOn !== '' && validTo < approvedOn) {
    error = 'The limit cannot end before it was approved.';
  } else if ([...ref].length > 100) {
    error = 'The ECGC approval reference is too long (100 characters at most).';
  }
  if (error) {
    flash.set(req, 'error', error);
    return back(req, res, url);
  }
  let fileId = client.ecgc_limit_file_id || null;
  if (req.file) {
    try {
      fileId = await fileUploadService.handleUpload(req, 'ecgc_limit_file', 'ecgc_limit_letter', `clients/${client.client_unique_number || clientId}/ecgc`, clientId, null, req.user.id, null, 'ECGC', 'ECGC credit-limit approval');
    } catch (e) {
      flash.set(req, 'error', `The approval letter could not be saved: ${e.message}`);
      return back(req, res, url);
    }
  }
  const old = `${client.ecgc_limit_currency || ''} ${client.ecgc_limit_amount ?? '—'}`;
  await db.execute(
    'UPDATE clients SET ecgc_limit_amount = :a, ecgc_limit_currency = :c, ecgc_limit_approved_on = :ap, ecgc_limit_valid_to = :vt, ecgc_limit_ref = :r, ecgc_limit_file_id = :f WHERE id = :id',
    { a: Math.round(Number(amount) * 100) / 100, c: currency, ap: approvedOn || null, vt: validTo || null, r: ref || null, f: fileId, id: clientId }
  );
  await auditLogRepository.log(req.user.id, 'CLIENT_ECGC_LIMIT_SET', 'clients', clientId, 'ecgc_limit_amount', old, `${currency} ${Number(amount).toFixed(2)}`, ref !== '' ? `ECGC ref ${ref}` : null);
  flash.set(req, 'success', 'ECGC credit limit saved.');
  return back(req, res, url);
}

async function declareShipment(req, res) {
  const orderId = Number(req.params.id);
  const order = await db.queryOne('SELECT id, ecgc_declared_on FROM orders WHERE id = :id', { id: orderId });
  if (!order) return res.status(404).send('Order not found.');
  const url = `/orders/${orderId}#ecgc-cover`;
  const on = String((req.body || {}).ecgc_declared_on || '').trim();
  const ref = String((req.body || {}).ecgc_declaration_ref || '').trim();
  if (!DATE.test(on) || on > complianceTrackingService.todayStr()) {
    flash.set(req, 'error', 'Enter the date the shipment was declared to ECGC (not in the future).');
    return back(req, res, url);
  }
  if ([...ref].length < 3 || [...ref].length > 100) {
    flash.set(req, 'error', 'Enter the ECGC declaration reference (3 to 100 characters).');
    return back(req, res, url);
  }
  await db.execute('UPDATE orders SET ecgc_declared_on = :d, ecgc_declaration_ref = :r WHERE id = :id', { d: on, r: ref, id: orderId });
  const prev = order.ecgc_declared_on ? String(order.ecgc_declared_on instanceof Date ? order.ecgc_declared_on.toISOString().slice(0, 10) : order.ecgc_declared_on).slice(0, 10) : null;
  await auditLogRepository.log(req.user.id, 'ORDER_ECGC_DECLARED', 'orders', orderId, 'ecgc_declared_on', prev, on, `ECGC declaration ${ref}`);
  flash.set(req, 'success', 'Shipment declaration to ECGC recorded.');
  return back(req, res, url);
}

async function saveClaimTracking(req, res) {
  const orderId = Number(req.params.id);
  const claim = await db.queryOne('SELECT * FROM ca_export_benefits WHERE id = :id', { id: Number(req.params.benefitId) });
  if (!claim || Number(claim.order_id) !== orderId) return res.status(404).send('Claim not found.');
  const b = req.body || {};
  const error = await complianceTrackingService.saveClaimTracking(claim, b);
  if (error) {
    flash.set(req, 'error', error);
  } else {
    await auditLogRepository.log(req.user.id, 'EXPORT_BENEFIT_CLAIM_TRACKED', 'ca_export_benefits', Number(claim.id), 'claim_status',
      String(claim.claim_status || 'filed'), String(b.claim_status || ''),
      `${claim.scheme_name} — sanctioned ${String(b.sanctioned_amount || '').trim() || '—'}, deadline ${String(b.claim_deadline || '').trim() || 'default'}`);
    flash.set(req, 'success', `${claim.scheme_name} claim updated.`);
  }
  return res.redirect(`/orders/${orderId}#step-13g-benefits`);
}

async function missingEbrcReport(req, res) {
  const rows = await complianceTrackingService.missingFircReport();
  const showAmounts = moneyVisibility.can(req.permissions, moneyVisibility.BUYER_PAYMENTS || 'buyer_payments');
  if ((req.query || {}).format === 'csv') {
    const headers = ['Order', 'Buyer', 'Payment', 'Received on', 'Days since receipt', 'Owner'];
    if (showAmounts) headers.splice(3, 0, 'Currency', 'Amount');
    const out = rows.map((r) => {
      const line = [r.order_reference, r.client, r.label, r.received_at, r.days, r.owners];
      if (showAmounts) line.splice(3, 0, r.currency, Number(r.amount).toFixed(2));
      return line;
    });
    return csv.stream(res, `missing-ebrc-firc-${complianceTrackingService.todayStr()}.csv`, headers, out);
  }
  const alertDays = parseInt((await companySettingsRepository.get('fema_realization_alert_days')) || '270', 10) || 270;
  return res.renderView('reports/missing_ebrc', {
    title: 'Missing eBRC / FIRC',
    rows,
    showAmounts,
    alertDays,
    orderCount: new Set(rows.map((r) => r.order_id)).size,
  }, 'layout/base');
}

module.exports = { saveClientEcgc, declareShipment, saveClaimTracking, missingEbrcReport };
