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

/** D-294: the vendor's e-mail as it was before this save (the REGISTERED e-mail), kept on the request body. */
const EMAIL_BEFORE = Symbol('vendorEmailBefore');

/** Validates the form's bank section without saving — null when it is fine. */
async function bankInputError(kind, id, body) {
  const vendor = id !== null ? await vendorDetailsRepository.find(kind, id) : null;
  if (vendor && body && typeof body === 'object') body[EMAIL_BEFORE] = String(vendor.email || '').trim();
  const current = vendorDetailsRepository.bankDetails(vendor);
  return bankDetailsInput.collect(body, current, false).error;
}

/**
 * Owner decisions 11 Nov 2026, point 18 (D-294): bank details typed by staff (on the vendor's behalf,
 * e.g. from their e-mail) no longer replace the account in use. They become a change waiting for the
 * vendor's confirmation — a link e-mailed to their REGISTERED e-mail (the one on file before this save;
 * for a new record, the e-mail entered with it) — then for staff verification (vendorBankChangeService).
 */
async function applyStaffBankInput(kind, id, userId, body, req = null) {
  const vendor = await vendorDetailsRepository.find(kind, id);
  const current = vendorDetailsRepository.bankDetails(vendor);
  const result = bankDetailsInput.collect(body, current, false);
  if (result.error) return result.error;
  if (result.bank) {
    const registered = body && body[EMAIL_BEFORE] !== undefined ? body[EMAIL_BEFORE] : String((vendor && vendor.email) || '').trim();
    const r = await require('./vendorBankChangeService').propose(kind, id, result.bank, 'staff', userId, registered || null);
    if (req) {
      const { CONFIRM_DAYS } = require('./bankDetailsCheck');
      require('../helpers/flash').set(req, 'info', r.emailedTo
        ? `The new bank details are saved as a change: a confirmation link was e-mailed to ${r.emailedTo}. They are used only after they confirm (within ${CONFIRM_DAYS} days) and someone other than you verifies them.`
        : 'The new bank details are saved as a change, but there is no registered e-mail to send the confirmation link to — add their e-mail, then use "Send the link again" under Bank details changes.');
    }
  }
  return null;
}

/** Makes a fresh link (replacing any older one), emails it to the vendor when an email is on file, and returns it. */
async function sendDetailsLink(kind, id, userId) {
  const meta = vendorDetailsRepository.KINDS[kind];
  const vendor = await vendorDetailsRepository.find(kind, id);
  const link = `${env.get('APP_URL', '').replace(/\/+$/, '')}/vendor-details/${await vendorDetailsRepository.generateDetailsToken(kind, id)}`;
  const email = String(vendor.email || '').trim();
  let sent = false;
  if (email) {
    const rendered = await emailTemplateRepository.renderOrDefault(
      'vendor_details_link',
      { '{vendor_name}': String(vendor[meta.name]), '{vendor_details_link}': link },
      'NexaCrest — please confirm your company and bank details',
      'Hello,\n\n'
        + 'To set you up for payments from NexaCrest, please fill in your company and bank details on this secure link — no login needed:\n{vendor_details_link}\n\n'
        + 'Please keep a cancelled cheque or a letter from your bank ready. Our team may ask for it to check the account. You can use the link once, within 30 days.\n\n'
        + 'NexaCrest International Private Limited'
    );
    sent = await emailService.sendPlainText(email, rendered.subject, rendered.body, { kind: 'supplier_links', audience: 'supplier' });
  }
  const emailPolicy = require('./emailPolicy');
  await auditLogRepository.log(userId, 'VENDOR_DETAILS_LINK_GENERATED', meta.table, id, null, null, null,
    sent ? `Emailed to ${email}` : (email ? `Not e-mailed (${await emailPolicy.notSentReason('supplier_links', 'supplier')}) — hand the link over directly` : 'No email on file — hand the link over directly'));
  // D-280: emailedTo only when the e-mail really went out.
  return {
    link,
    emailedTo: sent ? email : null,
    email: email || null,
    message: await require('./emailDispatchService').linkMailOutcome('Vendor Details Link', email || null, sent, link, 'supplier_links', 'supplier'),
  };
}

function detailsLinkUrl(vendor) {
  const token = vendorDetailsRepository.liveDetailsToken(vendor);
  return token ? `${env.get('APP_URL', '').replace(/\/+$/, '')}/vendor-details/${token}` : null;
}

module.exports = { bankInputError, applyStaffBankInput, sendDetailsLink, detailsLinkUrl };
