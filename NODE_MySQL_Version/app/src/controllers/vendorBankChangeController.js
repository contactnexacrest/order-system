'use strict';

const flash = require('../helpers/flash');
const vendorBankChangeRepository = require('../repositories/vendorBankChangeRepository');
const vendorDetailsRepository = require('../repositories/vendorDetailsRepository');
const vendorBankChangeService = require('../services/vendorBankChangeService');

/**
 * Port of VendorBankChangeController.php. Owner decisions 11 Nov 2026, point 18
 * (D-294): a supplier or logistics partner confirms new bank details through the
 * no-login link e-mailed to their registered e-mail (Confirm / "This is not us —
 * cancel it"); staff then verify them on the supplier's page.
 */

async function changeOrExpired(req, res, token) {
  const c = await vendorBankChangeRepository.findByToken(token);
  if (!c) {
    res.renderView('vendor_details/link_expired', {}, 'layout/bare');
    return null;
  }
  return c;
}

async function show(req, res) {
  const token = req.params.token;
  const c = await changeOrExpired(req, res, token);
  if (!c) return;
  const vendor = await vendorDetailsRepository.find(c.vendor_kind, Number(c.vendor_id));
  const current = vendorDetailsRepository.bankDetails(vendor);
  res.renderView('vendor_details/bank_confirm', {
    wrapClass: 'intake-wrap', token, c, meta: vendorDetailsRepository.KINDS[c.vendor_kind], vendor, current,
    currentMasked: vendorDetailsRepository.maskAccount(current.bank_account_number),
  }, 'layout/bare');
}

async function confirm(req, res) {
  const token = req.params.token;
  const c = await changeOrExpired(req, res, token);
  if (!c) return;
  const error = await vendorBankChangeService.confirm(c, (req.body || {}).last4, !!(req.body || {}).ours);
  if (error) {
    flash.set(req, 'error', error);
    res.redirect(`/vendor-bank-confirm/${token}`);
    return;
  }
  res.renderView('vendor_details/bank_confirm_done', { confirmed: true }, 'layout/bare');
}

async function notUs(req, res) {
  const token = req.params.token;
  const c = await changeOrExpired(req, res, token);
  if (!c) return;
  await vendorBankChangeService.notUs(c);
  res.renderView('vendor_details/bank_confirm_done', { confirmed: false }, 'layout/bare');
}

const MESSAGES = {
  verify: 'Bank details verified. They are used from the date shown; until then payments go to the account on file.',
  check: 'First check recorded. A different person now verifies it.',
  reject: 'Bank change rejected.',
  resend: 'Confirmation link e-mailed again.',
};

function act(kind, action) {
  return async (req, res) => {
    const id = Number(req.params.id);
    const changeId = Number(req.params.changeId);
    const userId = Number(req.user.id);
    let error;
    if (action === 'verify' || action === 'check') {
      error = await vendorBankChangeService.verify(changeId, kind, id, userId, req.body || {}, action === 'check');
    } else if (action === 'reject') {
      error = await vendorBankChangeService.reject(changeId, kind, id, userId, (req.body || {}).reason);
    } else {
      const c = await vendorBankChangeRepository.find(changeId);
      error = !c || c.vendor_kind !== kind || Number(c.vendor_id) !== id ? 'Bank change not found.' : await vendorBankChangeService.resend(changeId, userId);
    }
    flash.set(req, error ? 'error' : 'success', error || MESSAGES[action]);
    res.redirect(`${vendorBankChangeService.EDIT[kind](id)}#bank-changes`);
  };
}

module.exports = {
  show, confirm, notUs,
  supplierVerify: act('supplier', 'verify'), supplierCheck: act('supplier', 'check'), supplierReject: act('supplier', 'reject'), supplierResend: act('supplier', 'resend'),
  partnerVerify: act('logistics_partner', 'verify'), partnerCheck: act('logistics_partner', 'check'), partnerReject: act('logistics_partner', 'reject'), partnerResend: act('logistics_partner', 'resend'),
};
