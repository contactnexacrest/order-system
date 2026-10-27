'use strict';

const flash = require('../helpers/flash');
const bankDetailsInput = require('../helpers/bankDetailsInput');
const auditLogRepository = require('../repositories/auditLogRepository');
const vendorDetailsRepository = require('../repositories/vendorDetailsRepository');

/**
 * Port of App\Controllers\VendorDetailsController. Owner request
 * (2026-10-27): public, no-login Vendor Details Link — a supplier or
 * logistics partner fills in their own company and bank details. One use:
 * the link is cleared on submit. Their bank details land as "not verified"
 * until staff check them; the existing account number is never shown back
 * (only its last four digits).
 */
async function vendorOrExpired(req, res, token) {
  const found = await vendorDetailsRepository.findByValidDetailsToken(token);
  if (!found) {
    res.renderView('vendor_details/link_expired', {}, 'layout/bare');
    return null;
  }
  return found;
}

async function show(req, res) {
  const token = req.params.token;
  const found = await vendorOrExpired(req, res, token);
  if (!found) {
    return;
  }
  const [kind, vendor] = found;
  res.renderView('vendor_details/show', {
    wrapClass: 'intake-wrap',
    token,
    kind,
    meta: vendorDetailsRepository.KINDS[kind],
    vendor,
    bank: vendorDetailsRepository.bankDetails(vendor),
    bankAccountTypes: bankDetailsInput.ACCOUNT_TYPES,
  }, 'layout/bare');
}

async function submit(req, res) {
  const token = req.params.token;
  const found = await vendorOrExpired(req, res, token);
  if (!found) {
    return;
  }
  const [kind, vendor] = found;
  const meta = vendorDetailsRepository.KINDS[kind];
  const body = req.body;

  const email = String(body.email || '').trim();
  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    flash.set(req, 'error', 'Enter a valid email address.');
    res.redirect(`/vendor-details/${token}`);
    return;
  }
  const result = bankDetailsInput.collect(body, vendorDetailsRepository.bankDetails(vendor), true);
  if (result.error) {
    flash.set(req, 'error', `${result.error} Nothing was saved.`);
    res.redirect(`/vendor-details/${token}`);
    return;
  }

  const details = {};
  for (const f of [...vendorDetailsRepository.DETAIL_FIELDS, meta.contact]) {
    if (Object.prototype.hasOwnProperty.call(body, f)) {
      details[f] = String(body[f] || '').trim() || null;
    }
  }
  await vendorDetailsRepository.saveDetailsFromVendor(kind, vendor.id, details);
  if (result.bank) {
    await vendorDetailsRepository.saveBankDetails(kind, vendor.id, result.bank, 'vendor', null);
  }
  await vendorDetailsRepository.markDetailsSubmitted(kind, vendor.id);
  const after = vendorDetailsRepository.bankDetails(await vendorDetailsRepository.find(kind, vendor.id));
  await auditLogRepository.log(
    null,
    'VENDOR_DETAILS_SUBMITTED',
    meta.table,
    vendor.id,
    'bank_account_number',
    null,
    vendorDetailsRepository.maskAccount(after.bank_account_number),
    `${vendor[meta.name]} filled in their details through the Vendor Details Link — verify the bank account before paying.`
  );
  res.renderView('vendor_details/thanks', { vendor, meta }, 'layout/bare');
}

module.exports = { show, submit };
