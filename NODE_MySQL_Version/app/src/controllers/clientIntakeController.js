'use strict';

const crypto = require('crypto');
const flash = require('../helpers/flash');
const env = require('../config/env');
const emailService = require('../services/emailService');
const clientIntakeRepository = require('../repositories/clientIntakeRepository');

/**
 * Port of App\Controllers\ClientIntakeController. Public, unauthenticated
 * quotation-stage intake form — the actual entry point into this system,
 * per the confirmed scope: a Zoho-qualified prospect is sent this form's
 * link by staff; filling it out is their onboarding. Submitting never
 * creates a client or an order — it only ever lands in the staff review
 * queue (clientIntakeReviewController).
 */

function show(req, res) {
  res.renderView('client_intake/form', {}, 'layout/bare');
}

async function submit(req, res) {
  const data = extractAndValidate(req);
  if (!data) {
    res.redirect('/quotation-details');
    return;
  }

  const id = await clientIntakeRepository.create(data, req.ip || null);
  const link = await issueCorrectionLink(id, data.email);

  res.renderView('client_intake/thank_you', { correctionLink: link }, 'layout/bare');
}

async function showEdit(req, res) {
  const submission = await clientIntakeRepository.findValidByToken(req.params.token || '');
  if (!submission) {
    res.renderView('client_intake/link_expired', {}, 'layout/bare');
    return;
  }
  res.renderView('client_intake/edit_form', { submission, token: req.params.token }, 'layout/bare');
}

async function updateSubmission(req, res) {
  const token = req.params.token || '';
  const submission = await clientIntakeRepository.findValidByToken(token);
  if (!submission) {
    res.renderView('client_intake/link_expired', {}, 'layout/bare');
    return;
  }

  const data = extractAndValidate(req);
  if (!data) {
    res.redirect(`/quotation-details/edit/${token}`);
    return;
  }

  await clientIntakeRepository.updateFromClient(submission.id, data);
  res.renderView('client_intake/thank_you', { correctionLink: null, updated: true }, 'layout/bare');
}

/** @returns {object|null} null means validation failed and a flash error was already set */
function extractAndValidate(req) {
  const data = {
    company_legal_name: String(req.body.company_legal_name || '').trim(),
    billing_address: String(req.body.billing_address || '').trim(),
    vat_eori_tax_no: String(req.body.vat_eori_tax_no || '').trim(),
    contact_person: String(req.body.contact_person || '').trim(),
    email: String(req.body.email || '').trim(),
    phone: String(req.body.phone || '').trim(),
    country_of_destination: String(req.body.country_of_destination || '').trim(),
    port_of_discharge_text: String(req.body.port_of_discharge_text || '').trim(),
    coo_type: String(req.body.coo_type || '').trim(),
    incoterm_preference: String(req.body.incoterm_preference || '').trim(),
    container_type_text: String(req.body.container_type_text || '').trim(),
    buyer_own_reference: String(req.body.buyer_own_reference || '').trim(),
    notes: String(req.body.notes || '').trim(),
  };

  // Required set per the business's own Quotation Form spec (Client_Forms.xlsx):
  // Company Legal Name, Billing Address, VAT/EORI/Tax Reg. No., Contact Person,
  // Email, Country of Destination, Incoterm.
  const required = ['company_legal_name', 'billing_address', 'vat_eori_tax_no', 'contact_person', 'email', 'country_of_destination', 'incoterm_preference'];
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
  return data;
}

/** Emails the client a one-time correction link and returns it, so the thank-you page can also show it directly. */
async function issueCorrectionLink(submissionId, email) {
  const rawToken = crypto.randomBytes(32).toString('hex');
  const expiresAt = new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 19).replace('T', ' ');
  await clientIntakeRepository.setAccessToken(submissionId, crypto.createHash('sha256').update(rawToken).digest('hex'), expiresAt);

  const link = `${env.get('APP_URL', '').replace(/\/+$/, '')}/quotation-details/edit/${rawToken}`;
  const body = 'Hello,\n\n'
    + "Thank you for your quotation request. We'll review it and get back to you within 24 hours.\n\n"
    + `Spotted a mistake in what you submitted? You can correct it yourself, as long as we haven't already processed it, at:\n${link}\n\n`
    + 'This link works for 7 days.\n\n'
    + 'NexaCrest International Private Limited';
  await emailService.sendPlainText(email, 'Your NexaCrest quotation request — correction link', body);

  return link;
}

module.exports = { show, submit, showEdit, updateSubmission };
