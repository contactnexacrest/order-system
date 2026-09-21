'use strict';

const flash = require('../helpers/flash');
const reasonValidator = require('../helpers/reasonValidator');
const clientRepository = require('../repositories/clientRepository');
const orderRepository = require('../repositories/orderRepository');
const adminOverrideRepository = require('../repositories/adminOverrideRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const referenceNumberService = require('../services/referenceNumberService');

// Port of App\Controllers\ClientController.

async function index(req, res) {
  const clients = await clientRepository.all();
  res.renderView('clients/index', { clients }, 'layout/base');
}

async function create(req, res) {
  res.renderView('clients/create', {}, 'layout/base');
}

async function store(req, res) {
  const user = req.user;

  const companyLegalName = String(req.body.company_legal_name || '').trim();
  const billingAddress = String(req.body.billing_address || '').trim();

  if (companyLegalName === '' || billingAddress === '') {
    flash.set(req, 'error', 'Company legal name and billing address are required.');
    res.redirect('/clients/create');
    return;
  }

  const clientUniqueNumber = await referenceNumberService.generateClientUniqueNumber();
  const clientId = await clientRepository.create(
    {
      company_legal_name: companyLegalName,
      billing_address: billingAddress,
      consignee_name: String(req.body.consignee_name || '').trim() || null,
      consignee_address: String(req.body.consignee_address || '').trim() || null,
      vat_eori_tax_no: String(req.body.vat_eori_tax_no || '').trim() || null,
      contact_person: String(req.body.contact_person || '').trim() || null,
      email: String(req.body.email || '').trim() || null,
      phone: String(req.body.phone || '').trim() || null,
      country_of_destination: String(req.body.country_of_destination || '').trim() || null,
      coo_type: String(req.body.coo_type || '').trim() || 'TBC',
      notify_party: String(req.body.notify_party || '').trim() || null,
    },
    user.id,
    clientUniqueNumber
  );

  flash.set(req, 'success', `Client created — Buyer Inquiry Ref ${clientUniqueNumber}.`);
  res.redirect(`/clients/${clientId}`);
}

async function show(req, res) {
  const client = await clientRepository.find(parseInt(req.params.id, 10));
  if (!client) {
    res.status(404).send('Client not found.');
    return;
  }
  const orders = await orderRepository.forClient(client.id);
  res.renderView('clients/show', { client, orders }, 'layout/base');
}

/**
 * Spec Section 13 — "Client unique numbers" is explicitly named as an
 * Admin-editable field. Reason mandatory, logged with old/new value.
 */
async function overrideUniqueNumber(req, res) {
  const clientId = parseInt(req.params.id, 10);
  const client = await clientRepository.find(clientId);
  if (!client) {
    res.status(404).send('Client not found.');
    return;
  }

  const user = req.user;
  const newNumber = String(req.body.client_unique_number || '').trim();
  const reason = String(req.body.reason || '').trim();

  if (newNumber === '' || newNumber === client.client_unique_number) {
    flash.set(req, 'success', 'No change was made.');
    res.redirect(`/clients/${clientId}`);
    return;
  }
  const reasonError = reasonValidator.check(reason);
  if (reasonError) {
    flash.set(req, 'error', reasonError);
    res.redirect(`/clients/${clientId}`);
    return;
  }

  await adminOverrideRepository.updateClientUniqueNumber(clientId, newNumber);
  await auditLogRepository.log(user.id, 'FIELD_EDIT', 'clients', clientId, 'client_unique_number', client.client_unique_number, newNumber, reason);
  flash.set(req, 'success', `Buyer Inquiry Ref overridden to ${newNumber}.`);
  res.redirect(`/clients/${clientId}`);
}

module.exports = { index, create, store, show, overrideUniqueNumber };
