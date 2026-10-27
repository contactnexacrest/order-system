'use strict';

const env = require('../config/env');
const bankDetailsInput = require('../helpers/bankDetailsInput');
const auditLogRepository = require('../repositories/auditLogRepository');
const emailTemplateRepository = require('../repositories/emailTemplateRepository');
const vendorDetailsRepository = require('../repositories/vendorDetailsRepository');
const emailService = require('./emailService');

/**
 * Port of App\Services\VendorDetailsService. Owner request (2026-10-27):
 * staff-side handling of a supplier's or logistics partner's bank details
 * and of the Vendor Details Link, shared by both directories.
 */

/** Validates the form's bank section without saving — null when it is fine. */
async function bankInputError(kind, id, body) {
  const current = vendorDetailsRepository.bankDetails(id !== null ? await vendorDetailsRepository.find(kind, id) : null);
  return bankDetailsInput.collect(body, current, false).error;
}

/**
 * Saves the form's bank section entered by staff (on the vendor's behalf,
 * e.g. from their email). "verified" is set only when staff tick that they
 * checked it; any change without the tick clears the mark.
 */
async function applyStaffBankInput(kind, id, userId, body) {
  const current = vendorDetailsRepository.bankDetails(await vendorDetailsRepository.find(kind, id));
  const result = bankDetailsInput.collect(body, current, false);
  if (result.error) return result.error;
  const verifiedTick = !!body.bank_verified;
  const tableName = vendorDetailsRepository.KINDS[kind].table;
  if (result.bank) {
    await vendorDetailsRepository.saveBankDetails(kind, id, result.bank, 'staff', verifiedTick ? userId : null);
    await auditLogRepository.log(userId, 'VENDOR_BANK_DETAILS_SAVED', tableName, id, 'bank_account_number', vendorDetailsRepository.maskAccount(current.bank_account_number), vendorDetailsRepository.maskAccount(result.bank.bank_account_number || current.bank_account_number), verifiedTick ? 'Entered by staff and marked verified' : 'Entered by staff');
  } else if (verifiedTick && current.has_bank && !current.bank_verified_at) {
    await vendorDetailsRepository.markBankVerified(kind, id, userId);
    await auditLogRepository.log(userId, 'VENDOR_BANK_DETAILS_VERIFIED', tableName, id, null, null, vendorDetailsRepository.maskAccount(current.bank_account_number));
  }
  return null;
}

/** Makes a fresh link (replacing any older one), emails it to the vendor when an email is on file, and returns it. */
async function sendDetailsLink(kind, id, userId) {
  const meta = vendorDetailsRepository.KINDS[kind];
  const vendor = await vendorDetailsRepository.find(kind, id);
  const link = `${env.get('APP_URL', '').replace(/\/+$/, '')}/vendor-details/${await vendorDetailsRepository.generateDetailsToken(kind, id)}`;
  const email = String(vendor.email || '').trim();
  if (email) {
    const rendered = await emailTemplateRepository.renderOrDefault(
      'vendor_details_link',
      { '{vendor_name}': String(vendor[meta.name]), '{vendor_details_link}': link },
      'NexaCrest — please confirm your company and bank details',
      'Hello,\n\n'
        + 'To set you up for payments from NexaCrest, please fill in your company and bank details on this secure link — no login needed:\n{vendor_details_link}\n\n'
        + 'Please keep a cancelled cheque or bank letter ready; our team may ask for it to verify the account. The link works once and for 30 days.\n\n'
        + 'NexaCrest International Private Limited'
    );
    await emailService.sendPlainText(email, rendered.subject, rendered.body);
  }
  await auditLogRepository.log(userId, 'VENDOR_DETAILS_LINK_GENERATED', meta.table, id, null, null, null, email ? `Emailed to ${email}` : 'No email on file — hand the link over directly');
  return { link, emailedTo: email || null };
}

function detailsLinkUrl(vendor) {
  const token = vendorDetailsRepository.liveDetailsToken(vendor);
  return token ? `${env.get('APP_URL', '').replace(/\/+$/, '')}/vendor-details/${token}` : null;
}

module.exports = { bankInputError, applyStaffBankInput, sendDetailsLink, detailsLinkUrl };
