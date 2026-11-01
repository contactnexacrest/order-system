'use strict';

const crypto = require('crypto');
const flash = require('../helpers/flash');
const env = require('../config/env');
const emailService = require('../services/emailService');
const rateLimiter = require('../helpers/rateLimiter');
const clientIntakeRepository = require('../repositories/clientIntakeRepository');
const emailTemplateRepository = require('../repositories/emailTemplateRepository');
const permissionRepository = require('../repositories/permissionRepository');
const notificationRepository = require('../repositories/notificationRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const lookupRepository = require('../repositories/lookupRepository');
const countryList = require('../helpers/countryList');
const { formatForDb } = require('../helpers/dbDateTime');
const db = require('../config/db');
const associateEventRepository = require('../repositories/associateEventRepository');
const associateInvitationRepository = require('../repositories/associateInvitationRepository');
const associateIntakeService = require('../services/associateIntakeService');
const associatePortalService = require('../services/associatePortalService');

// QA-5 INT-05: this form has no auth, no CAPTCHA, and no per-order token
// the way PI-intake does — a scripted flood from a single IP is otherwise
// unlimited. 5 submissions per 15 minutes is generous for a genuine
// prospect (who submits once) while making a burst-flood of the review
// queue impractical.
const RATE_LIMIT_BUCKET = 'quotation_intake_submit';
const RATE_LIMIT_MAX_REQUESTS = 5;
const RATE_LIMIT_WINDOW_MINUTES = 15;

/**
 * Port of App\Controllers\ClientIntakeController. Public, unauthenticated
 * quotation-stage intake form — the actual entry point into this system,
 * per the confirmed scope: a Zoho-qualified prospect is sent this form's
 * link by staff; filling it out is their onboarding. Submitting never
 * creates a client or an order — it only ever lands in the staff review
 * queue (clientIntakeReviewController).
 */

async function show(req, res) {
  // Owner decision 1 Nov 2026 (AS-5): the associate's link (?inv=) or personal code (?code=).
  const intro = await associateIntakeService.introducer(typeof req.query.code === 'string' ? req.query.code : null, typeof req.query.inv === 'string' ? req.query.inv : null);
  if (intro.invitation && intro.invitation.status === 'created') {
    await associateInvitationRepository.setStatus(Number(intro.invitation.id), 'opened');
    await associateEventRepository.add(Number(intro.associate.id), 'link_opened', `Your invitation link for ${intro.invitation.company_name} was opened.`, null, null, Number(intro.invitation.id), null, false);
  }
  res.renderView('client_intake/form', {
    introducer: intro.associate ? {
      name: String(intro.associate.name), code: intro.invitation ? '' : String(intro.associate.personal_code || ''), inv: String((intro.invitation && intro.invitation.token) || ''),
      company: String((intro.invitation && intro.invitation.company_name) || ''), country: String((intro.invitation && intro.invitation.country) || ''),
    } : null,
    introError: intro.error,
    wrapClass: 'intake-wrap',
    containerTypes: await lookupRepository.dropdownOptions('container_type'),
    cooTypes: await lookupRepository.dropdownOptions('coo_type'),
    incoterms: await lookupRepository.incoterms(),
    countries: countryList.names(),
  }, 'layout/bare');
}

async function submit(req, res) {
  if (await rateLimiter.tooManyRequests(RATE_LIMIT_BUCKET, req.ip || '', RATE_LIMIT_MAX_REQUESTS, RATE_LIMIT_WINDOW_MINUTES)) {
    flash.set(req, 'error', 'Too many submissions from this connection. Please wait a few minutes and try again.');
    res.redirect('/quotation-details');
    return;
  }

  const body = req.body || {};
  const postedInv = typeof body.associate_inv === 'string' ? body.associate_inv : '';
  const postedCode = typeof body.associate_code === 'string' ? body.associate_code : '';
  const backTo = `/quotation-details${postedInv ? `?inv=${encodeURIComponent(postedInv)}` : (postedCode ? `?code=${encodeURIComponent(postedCode)}` : '')}`;
  const data = await extractAndValidate(req);
  if (!data) {
    res.redirect(backTo);
    return;
  }
  // Owner decision 1 Nov 2026 (AS-5): introduced by an associate — their link or personal code.
  const intro = await associateIntakeService.introducer(postedCode, postedInv);
  const website = typeof body.website === 'string' ? body.website.trim() : '';
  if (intro.error !== null && (postedCode || postedInv)) {
    flash.set(req, 'error', intro.error);
    res.redirect('/quotation-details');
    return;
  }
  if ([...website].length > 255 || (website !== '' && !/^(https?:\/\/)?[A-Za-z0-9.-]+\.[A-Za-z]{2,}(\/\S*)?$/.test(website))) {
    flash.set(req, 'error', 'Enter your company website as an address (e.g. www.example.com), or leave it empty.');
    res.redirect(backTo);
    return;
  }

  const id = await clientIntakeRepository.create(data, req.ip || null);
  const link = await issueCorrectionLink(id, data.email);
  if (intro.associate) {
    const code = await associateIntakeService.attach(id, intro.associate, intro.invitation, data.company_legal_name, data.country_of_destination, data.email, true, website || null);
    req.session[associateIntakeService.SESSION_KEY] = id;
    await auditLogRepository.log(null, 'CLIENT_INTAKE_SUBMITTED', 'client_intake_submissions', id, 'associate_id', null, String(intro.associate.id), `Submitted by ${data.company_legal_name} via the public quotation-details form, introduced by associate ${intro.associate.name}.`);
    if (associatePortalService.showCodeOnScreen()) {
      flash.set(req, 'info', `Development only — the e-mail code is ${code}.`);
    }
    res.redirect('/quotation-details/verify');
    return;
  }
  if (website !== '') {
    await db.execute('UPDATE client_intake_submissions SET website = :w WHERE id = :id', { w: website, id });
  }
  await associateIntakeService.directContactCheck(id, data);

  // "SYSTEM MUST SEND NOTIFICATION FOR ALL ACTIONS ... THIS IS NON
  // NEGOTIABLE" — export process step 0: a prospect filling in the
  // public Quotation-stage intake form must notify staff, not just sit
  // silently in the review queue behind a nav badge.
  for (const staffUserId of await permissionRepository.usersWithPermission('manage_orders')) {
    await notificationRepository.create(staffUserId, null, 'client_intake_submitted', null, `New Quotation-stage intake submitted: ${data.company_legal_name}.`);
  }
  await auditLogRepository.log(null, 'CLIENT_INTAKE_SUBMITTED', 'client_intake_submissions', id, null, null, null, `Submitted by ${data.company_legal_name} via the public quotation-details form.`);

  res.renderView('client_intake/thank_you', { correctionLink: link }, 'layout/bare');
}

/** /invite/:token — an associate's one-off link: the quotation form, with the associate's name to confirm. */
async function invite(req, res) {
  res.redirect(`/quotation-details?inv=${encodeURIComponent(String(req.params.token || ''))}`);
}

/** The buyer's e-mail code and "X introduced us" (owner decision 1 Nov 2026, AS-5). */
async function showVerify(req, res) {
  const id = Number(req.session[associateIntakeService.SESSION_KEY] || 0);
  const s = id > 0 ? await associateIntakeService.submission(id) : null;
  if (!s || s.status !== 'pending' || !s.associate_id) {
    res.renderView('client_intake/link_expired', {}, 'layout/bare');
    return;
  }
  res.renderView('client_intake/verify', { s, wrapClass: 'intake-wrap' }, 'layout/bare');
}

async function verify(req, res) {
  const id = Number(req.session[associateIntakeService.SESSION_KEY] || 0);
  const s = id > 0 ? await associateIntakeService.submission(id) : null;
  if (!s) {
    res.renderView('client_intake/link_expired', {}, 'layout/bare');
    return;
  }
  if (await rateLimiter.tooManyRequests('quotation_intake_verify', String(id), 10, 30)) {
    flash.set(req, 'error', 'Too many attempts. Please wait and try again.');
    res.redirect('/quotation-details/verify');
    return;
  }
  let error = await associateIntakeService.verify(id, String((req.body || {}).code || ''), true);
  if (error !== null) {
    if (error.includes('expired')) {
      const code = await associateIntakeService.sendEmailCode(id, String(s.email));
      if (associatePortalService.showCodeOnScreen()) {
        error += ` Development only — the new code is ${code}.`;
      }
    }
    flash.set(req, 'error', error);
    res.redirect('/quotation-details/verify');
    return;
  }
  delete req.session[associateIntakeService.SESSION_KEY];
  for (const staffUserId of await permissionRepository.usersWithPermission('manage_orders')) {
    await notificationRepository.create(staffUserId, null, 'client_intake_submitted', null, `New Quotation-stage intake submitted: ${s.company_legal_name} (introduced by associate ${s.associate_name}).`);
  }
  res.renderView('client_intake/thank_you', { correctionLink: null }, 'layout/bare');
}

async function showEdit(req, res) {
  const submission = await clientIntakeRepository.findValidByToken(req.params.token || '');
  if (!submission) {
    res.renderView('client_intake/link_expired', {}, 'layout/bare');
    return;
  }
  const incoterms = await lookupRepository.incoterms();
  const incotermValue = submission.incoterm_preference || '';
  const countries = countryList.names();
  const isOtherCountry = (value) => value !== '' && value !== 'Not sure' && !countries.includes(value);
  res.renderView('client_intake/edit_form', {
    submission,
    token: req.params.token,
    wrapClass: 'intake-wrap',
    containerTypes: await lookupRepository.dropdownOptions('container_type'),
    cooTypes: await lookupRepository.dropdownOptions('coo_type'),
    incoterms,
    incotermIsOther: incotermValue !== '' && !incoterms.some((i) => i.code === incotermValue),
    countries,
    billingCountryIsOther: isOtherCountry(submission.billing_country || ''),
    countryOfDestinationIsOther: isOtherCountry(submission.country_of_destination || ''),
    consigneeCountryIsOther: isOtherCountry(submission.consignee_country || ''),
  }, 'layout/bare');
}

async function updateSubmission(req, res) {
  const token = req.params.token || '';
  const submission = await clientIntakeRepository.findValidByToken(token);
  if (!submission) {
    res.renderView('client_intake/link_expired', {}, 'layout/bare');
    return;
  }

  const data = await extractAndValidate(req);
  if (!data) {
    res.redirect(`/quotation-details/edit/${token}`);
    return;
  }

  await clientIntakeRepository.updateFromClient(submission.id, data);
  // External QA report N-08: this action never reached the audit log or
  // anyone's notifications.
  for (const staffUserId of await permissionRepository.usersWithPermission('manage_orders')) {
    await notificationRepository.create(staffUserId, null, 'client_intake_updated', null, `Quotation-stage intake corrected by the client: ${data.company_legal_name}.`);
  }
  await auditLogRepository.log(null, 'CLIENT_INTAKE_SUBMISSION_UPDATED_BY_CLIENT', 'client_intake_submissions', submission.id, null, null, null, 'Corrected by the client via the no-login edit link.');
  res.renderView('client_intake/thank_you', { correctionLink: null, updated: true }, 'layout/bare');
}

/** @returns {object|null} null means validation failed and a flash error was already set */
async function extractAndValidate(req) {
  // Point 8 — the Billing Address field used to be one free-text box; it's
  // now structured (Line 1/Line 2/City/Postcode/Country), same pattern as
  // the Consignee section just below it on this same form, since documents
  // need these as discrete fields. The flat billing_address column stays
  // NOT NULL (every older consumer — reports, the review screen fallback —
  // still reads it), so it's composed here from the structured parts
  // rather than collected directly from the client.
  const billingAddressLine1 = String(req.body.billing_address_line1 || '').trim();
  const billingAddressLine2 = String(req.body.billing_address_line2 || '').trim();
  const billingCity = String(req.body.billing_city || '').trim();
  const billingPostcode = String(req.body.billing_postcode || '').trim();
  const billingCountry = String(req.body.billing_country || '').trim();
  const composedBillingAddress = [billingAddressLine1, billingAddressLine2, billingCity, billingPostcode, billingCountry]
    .filter((part) => part !== '')
    .join(', ');

  const data = {
    company_legal_name: String(req.body.company_legal_name || '').trim(),
    billing_address: composedBillingAddress,
    billing_address_line1: billingAddressLine1,
    billing_address_line2: billingAddressLine2,
    billing_city: billingCity,
    billing_postcode: billingPostcode,
    billing_country: billingCountry,
    vat_eori_tax_no: String(req.body.vat_eori_tax_no || '').trim(),
    contact_person: String(req.body.contact_person || '').trim(),
    email: String(req.body.email || '').trim(),
    phone: String(req.body.phone || '').trim(),
    country_of_destination: String(req.body.country_of_destination || '').trim(),
    port_of_discharge_text: String(req.body.port_of_discharge_text || '').trim(),
    coo_type: await lookupRepository.sanitizeOption('coo_type', String(req.body.coo_type || '').trim()),
    incoterm_preference: String(req.body.incoterm_preference || '').trim(),
    container_type_text: String(req.body.container_type_text || '').trim(),
    buyer_own_reference: String(req.body.buyer_own_reference || '').trim(),
    notes: String(req.body.notes || '').trim(),
  };

  // Required set per the business's own Quotation Form spec (Client_Forms.xlsx):
  // Company Legal Name, Billing Address (now Line 1/City/Country),
  // VAT/EORI/Tax Reg. No., Contact Person, Email, Country of Destination,
  // Incoterm. Address Line 2/Postcode stay optional, same as the Consignee
  // section's own optionality on this form.
  const required = ['company_legal_name', 'billing_address_line1', 'billing_city', 'billing_country', 'vat_eori_tax_no', 'contact_person', 'email', 'country_of_destination', 'incoterm_preference'];
  for (const field of required) {
    if (data[field] === '') {
      flash.set(req, 'error', 'Please fill in all required fields (marked *).');
      return null;
    }
  }
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email)) {
    flash.set(req, 'error', `"${data.email}" doesn't look like a valid email address.`);
    return null;
  }
  return Object.assign(data, collectConsigneeFields(req));
}

/**
 * Section BB — lets the client self-serve the same Consignee "Same as
 * Buyer?" split staff already had on the admin Clients form (see
 * clientController.collectPartyFields()). Never Notify Party here — the
 * Quotation stage doesn't show a Notify Party section on any document
 * (docs/SOP/01-stage1-enquiry-quotation.md), so there's nothing for this
 * form to collect.
 */
function collectConsigneeFields(req) {
  const consigneeSameAsBuyer = Boolean(req.body.consignee_same_as_buyer);
  const fields = { consignee_same_as_buyer: consigneeSameAsBuyer ? 1 : 0 };

  if (consigneeSameAsBuyer) {
    fields.consignee_name = null;
    fields.consignee_address_line1 = null;
    fields.consignee_address_line2 = null;
    fields.consignee_city = null;
    fields.consignee_postcode = null;
    fields.consignee_country = null;
    fields.consignee_vat_eori_tax_no = null;
    fields.consignee_contact_person = null;
    fields.consignee_phone = null;
    fields.consignee_email = null;
  } else {
    fields.consignee_name = String(req.body.consignee_name || '').trim() || null;
    fields.consignee_address_line1 = String(req.body.consignee_address_line1 || '').trim() || null;
    fields.consignee_address_line2 = String(req.body.consignee_address_line2 || '').trim() || null;
    fields.consignee_city = String(req.body.consignee_city || '').trim() || null;
    fields.consignee_postcode = String(req.body.consignee_postcode || '').trim() || null;
    fields.consignee_country = String(req.body.consignee_country || '').trim() || null;
    fields.consignee_vat_eori_tax_no = String(req.body.consignee_vat_eori_tax_no || '').trim() || null;
    fields.consignee_contact_person = String(req.body.consignee_contact_person || '').trim() || null;
    fields.consignee_phone = String(req.body.consignee_phone || '').trim() || null;
    fields.consignee_email = String(req.body.consignee_email || '').trim() || null;
  }

  return fields;
}

/**
 * Emails the client a one-time correction link and returns it, so the
 * thank-you page can also show it directly. Exported — also called by
 * clientIntakeReviewController.resendLink() (90-item backlog
 * "resend-lost-intake-link flow") to re-issue a fresh token/expiry when
 * the client never got (or lost) the original.
 */
async function issueCorrectionLink(submissionId, email) {
  const rawToken = crypto.randomBytes(32).toString('hex');
  // External QA report L-21/L-02: toISOString() is always UTC — see
  // dbDateTime.formatForDb()'s docblock.
  const expiresAt = formatForDb(new Date(Date.now() + 7 * 86400000));
  await clientIntakeRepository.setAccessToken(submissionId, crypto.createHash('sha256').update(rawToken).digest('hex'), expiresAt);

  const link = `${env.get('APP_URL', '').replace(/\/+$/, '')}/quotation-details/edit/${rawToken}`;

  // 90-item backlog #37: admin-editable (Admin > Settings > Email
  // Templates) — this exact original wording is the fallback.
  const rendered = await emailTemplateRepository.renderOrDefault(
    'quotation_correction_link',
    { '{correction_link}': link },
    'Your NexaCrest quotation request — correction link',
    'Hello,\n\n'
      + "Thank you for your quotation request. We'll review it and get back to you within 24 hours.\n\n"
      + "Spotted a mistake in what you submitted? You can correct it yourself, as long as we haven't already processed it, at:\n{correction_link}\n\n"
      + 'This link works for 7 days.\n\n'
      + 'NexaCrest International Private Limited'
  );
  await emailService.sendPlainText(email, rendered.subject, rendered.body);

  return link;
}

module.exports = { show, submit, invite, showVerify, verify, showEdit, updateSubmission, issueCorrectionLink };
