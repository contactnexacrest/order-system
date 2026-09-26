'use strict';

const flash = require('../helpers/flash');
const reasonValidator = require('../helpers/reasonValidator');
const clientRepository = require('../repositories/clientRepository');
const orderRepository = require('../repositories/orderRepository');
const adminOverrideRepository = require('../repositories/adminOverrideRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const referenceNumberService = require('../services/referenceNumberService');
const testModeService = require('../services/testModeService');
const superAdminService = require('../services/superAdminService');
const env = require('../config/env');

// Port of App\Controllers\ClientController.

async function index(req, res) {
  const clients = await clientRepository.all();
  const quotationFormLink = `${env.get('APP_URL', '').replace(/\/+$/, '')}/quotation-details`;
  res.renderView('clients/index', { clients, quotationFormLink }, 'layout/base');
}

async function inactiveIndex(req, res) {
  const clients = await clientRepository.allInactive();
  res.renderView('clients/inactive', { clients }, 'layout/base');
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
  if (await testModeService.isEnabled()) {
    await clientRepository.markTest(clientId);
  }

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

async function editForm(req, res) {
  const client = await clientRepository.find(parseInt(req.params.id, 10));
  if (!client) {
    res.status(404).send('Client not found.');
    return;
  }
  res.renderView('clients/edit', { client }, 'layout/base');
}

/**
 * Every field except client_unique_number, which stays behind the
 * separate reason-required override below (Spec Section 13 names it
 * explicitly as admin-only, logged with old/new value — general edit
 * shouldn't quietly bypass that).
 */
async function update(req, res) {
  const clientId = parseInt(req.params.id, 10);
  const client = await clientRepository.find(clientId);
  if (!client) {
    res.status(404).send('Client not found.');
    return;
  }

  const companyLegalName = String(req.body.company_legal_name || '').trim();
  const billingAddress = String(req.body.billing_address || '').trim();
  if (companyLegalName === '' || billingAddress === '') {
    flash.set(req, 'error', 'Company legal name and billing address are required.');
    res.redirect(`/clients/${clientId}/edit`);
    return;
  }

  let overrideReason = null;
  if (client.is_data_locked) {
    const isSuperAdmin = await superAdminService.isEffective(req.user.id);
    const overrideChecked = !!req.body.override_lock;
    const reasonError = reasonValidator.check(req.body.override_reason);
    if (!isSuperAdmin || !overrideChecked || reasonError) {
      flash.set(req, 'error', "This client's details are locked and can no longer be edited. A Super Admin can override this only for a genuine staff data-entry error, with a reason.");
      res.redirect(`/clients/${clientId}/edit`);
      return;
    }
    overrideReason = String(req.body.override_reason).trim();
  }

  const data = {
    company_legal_name: companyLegalName,
    billing_address: billingAddress,
    consignee_name: String(req.body.consignee_name || '').trim() || null,
    consignee_address: String(req.body.consignee_address || '').trim() || null,
    vat_eori_tax_no: String(req.body.vat_eori_tax_no || '').trim() || null,
    contact_person: String(req.body.contact_person || '').trim() || null,
    email: String(req.body.email || '').trim() || null,
    phone: String(req.body.phone || '').trim() || null,
    country_of_destination: String(req.body.country_of_destination || '').trim() || null,
    coo_type: String(req.body.coo_type || '').trim() || null,
    notify_party: String(req.body.notify_party || '').trim() || null,
  };

  const user = req.user;
  const changes = Object.keys(data)
    .map((field) => [field, client[field], data[field]])
    .filter(([, oldVal, newVal]) => String(oldVal ?? '') !== String(newVal ?? ''));

  await clientRepository.update(clientId, data);

  if (overrideReason) {
    await auditLogRepository.log(user.id, 'CLIENT_LOCK_OVERRIDDEN', 'clients', clientId, null, null, null, overrideReason);
  }
  for (const [field, oldVal, newVal] of changes) {
    await auditLogRepository.log(
      user.id, 'CLIENT_UPDATED', 'clients', clientId, field,
      oldVal !== null && oldVal !== undefined ? String(oldVal) : null,
      newVal !== null && newVal !== undefined ? String(newVal) : null,
      overrideReason
    );
  }

  flash.set(req, 'success', `${companyLegalName} updated.`);
  res.redirect(`/clients/${clientId}`);
}

/**
 * Deactivate/reactivate only — never a deletion path. A client with
 * orders on file must stay in the database indefinitely (same reasoning
 * as order archiving); this only removes them from the default
 * /clients list (clientRepository.all() filters is_active), never from
 * search-by-direct-URL, and never touches their orders.
 */
async function toggleActive(req, res) {
  const clientId = parseInt(req.params.id, 10);
  const client = await clientRepository.find(clientId);
  if (!client) {
    res.status(404).send('Client not found.');
    return;
  }

  const user = req.user;
  const newState = !client.is_active;
  await clientRepository.setActive(clientId, newState);
  await auditLogRepository.log(
    user.id,
    newState ? 'CLIENT_REACTIVATED' : 'CLIENT_DEACTIVATED',
    'clients',
    clientId,
    'is_active',
    client.is_active ? '1' : '0',
    newState ? '1' : '0'
  );

  flash.set(req, 'success', newState
    ? `${client.company_legal_name} reactivated — visible in the main Clients list again.`
    : `${client.company_legal_name} deactivated — hidden from the main Clients list. Nothing was deleted; their orders are untouched.`);
  res.redirect('/clients');
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

module.exports = { index, inactiveIndex, create, store, show, editForm, update, toggleActive, overrideUniqueNumber };
