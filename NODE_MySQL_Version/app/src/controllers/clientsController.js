'use strict';

const flash = require('../helpers/flash');
const reasonValidator = require('../helpers/reasonValidator');
const clientRepository = require('../repositories/clientRepository');
const lookupRepository = require('../repositories/lookupRepository');
const orderRepository = require('../repositories/orderRepository');
const adminOverrideRepository = require('../repositories/adminOverrideRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const fileStoreRepository = require('../repositories/fileStoreRepository');
const referenceNumberService = require('../services/referenceNumberService');
const testModeService = require('../services/testModeService');
const superAdminService = require('../services/superAdminService');
const clientPortalService = require('../services/clientPortalService');
const fileUploadService = require('../services/fileUploadService');
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
  // Batch 3 #4 — if this page is being shown again after a validation failure on submit,
  // re-populate the form from what was typed rather than making the user retype everything.
  res.renderView('clients/create', { old: flash.pullOld(req), cooTypes: await lookupRepository.dropdownOptions('coo_type') }, 'layout/base');
}

async function store(req, res) {
  const user = req.user;

  const companyLegalName = String(req.body.company_legal_name || '').trim();
  const billingAddress = String(req.body.billing_address || '').trim();

  if (companyLegalName === '' || billingAddress === '') {
    flash.set(req, 'error', 'Company legal name and billing address are required.');
    flash.setOld(req, req.body);
    res.redirect('/clients/create');
    return;
  }

  const clientUniqueNumber = await referenceNumberService.generateClientUniqueNumber();
  const clientId = await clientRepository.create(
    {
      company_legal_name: companyLegalName,
      billing_address: billingAddress,
      vat_eori_tax_no: String(req.body.vat_eori_tax_no || '').trim() || null,
      contact_person: String(req.body.contact_person || '').trim() || null,
      email: String(req.body.email || '').trim() || null,
      phone: String(req.body.phone || '').trim() || null,
      country_of_destination: String(req.body.country_of_destination || '').trim() || null,
      coo_type: String(req.body.coo_type || '').trim() || 'To Be Confirmed',
      ...collectPartyFields(req),
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
  // docs/schema.sql Section AV
  const impersonationGloballyEnabled = (await companySettingsRepository.get('client_impersonation_enabled')) === '1';
  const impersonationReasons = [];
  if (!impersonationGloballyEnabled) impersonationReasons.push('the global switch is off');
  if (!client.allow_staff_impersonation) impersonationReasons.push('it is not enabled for this client');
  if (!client.is_active) impersonationReasons.push('this client is deactivated');
  const impersonationUnavailableReason = impersonationReasons.join(', ');
  const additionalDocuments = await fileStoreRepository.additionalDocumentsForClient(client.id);
  res.renderView('clients/show', { client, orders, impersonationGloballyEnabled, impersonationUnavailableReason, additionalDocuments }, 'layout/base');
}

async function editForm(req, res) {
  const client = await clientRepository.find(parseInt(req.params.id, 10));
  if (!client) {
    res.status(404).send('Client not found.');
    return;
  }
  res.renderView('clients/edit', { client, isSuperAdmin: await superAdminService.isEffective(req.user.id), cooTypes: await lookupRepository.dropdownOptions('coo_type') }, 'layout/base');
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
    vat_eori_tax_no: String(req.body.vat_eori_tax_no || '').trim() || null,
    contact_person: String(req.body.contact_person || '').trim() || null,
    email: String(req.body.email || '').trim() || null,
    phone: String(req.body.phone || '').trim() || null,
    country_of_destination: String(req.body.country_of_destination || '').trim() || null,
    coo_type: String(req.body.coo_type || '').trim() || null,
    ...collectPartyFields(req),
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
 * Billing address line fields, plus the Consignee/Notify Party "Same as"
 * blocks: each one's own fields are read from req.body only when its
 * checkbox is off, since the checked state leaves them disabled client-
 * side (so the browser never submits them) — this keeps a stale,
 * previously-typed value from silently overwriting a flip back to
 * same-as-buyer/consignee from this same request.
 */
function collectPartyFields(req) {
  const consigneeSameAsBuyer = !!req.body.consignee_same_as_buyer;
  const notifySameAsConsignee = !!req.body.notify_party_same_as_consignee;

  const fields = {
    billing_address_line1: String(req.body.billing_address_line1 || '').trim() || null,
    billing_address_line2: String(req.body.billing_address_line2 || '').trim() || null,
    billing_city: String(req.body.billing_city || '').trim() || null,
    billing_postcode: String(req.body.billing_postcode || '').trim() || null,
    consignee_same_as_buyer: consigneeSameAsBuyer ? 1 : 0,
    notify_party_same_as_consignee: notifySameAsConsignee ? 1 : 0,
  };

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

  if (notifySameAsConsignee) {
    fields.notify_party = null;
    fields.notify_party_address_line1 = null;
    fields.notify_party_address_line2 = null;
    fields.notify_party_city = null;
    fields.notify_party_postcode = null;
    fields.notify_party_country = null;
    fields.notify_party_contact_person = null;
    fields.notify_party_phone = null;
    fields.notify_party_email = null;
  } else {
    fields.notify_party = String(req.body.notify_party || '').trim() || null;
    fields.notify_party_address_line1 = String(req.body.notify_party_address_line1 || '').trim() || null;
    fields.notify_party_address_line2 = String(req.body.notify_party_address_line2 || '').trim() || null;
    fields.notify_party_city = String(req.body.notify_party_city || '').trim() || null;
    fields.notify_party_postcode = String(req.body.notify_party_postcode || '').trim() || null;
    fields.notify_party_country = String(req.body.notify_party_country || '').trim() || null;
    fields.notify_party_contact_person = String(req.body.notify_party_contact_person || '').trim() || null;
    fields.notify_party_phone = String(req.body.notify_party_phone || '').trim() || null;
    fields.notify_party_email = String(req.body.notify_party_email || '').trim() || null;
  }

  return fields;
}

/**
 * Batch 3 #12 — deliberately its own endpoint, bypassing is_data_locked
 * entirely: a client-level agreement T&C footer is a staff-authored
 * annotation of an externally-negotiated term, not a client-submitted
 * identity detail, so it must stay editable at any time, lock or no lock.
 */
async function updateAgreementFooter(req, res) {
  const clientId = parseInt(req.params.id, 10);
  const client = await clientRepository.find(clientId);
  if (!client) {
    res.status(404).send('Client not found.');
    return;
  }

  const user = req.user;
  const oldText = client.agreement_footer_text ?? null;
  const newText = String(req.body.agreement_footer_text || '').trim() || null;

  await clientRepository.updateAgreementFooterText(clientId, newText);

  if (String(oldText ?? '') !== String(newText ?? '')) {
    await auditLogRepository.log(user.id, 'CLIENT_UPDATED', 'clients', clientId, 'agreement_footer_text', oldText, newText, null);
  }

  flash.set(req, 'success', 'Agreement T&C footer updated.');
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

/** docs/schema.sql Section AV — the per-client gate; manage_company_settings tier, same as other global/client-config toggles. */
async function setImpersonationAllowed(req, res) {
  const clientId = parseInt(req.params.id, 10);
  const client = await clientRepository.find(clientId);
  if (!client) {
    res.status(404).send('Client not found.');
    return;
  }

  const allow = !!req.body.allow_staff_impersonation;
  await clientRepository.setAllowStaffImpersonation(clientId, allow);
  const user = req.user;
  await auditLogRepository.log(
    user.id, 'CLIENT_IMPERSONATION_ALLOWED_CHANGED', 'clients', clientId,
    'allow_staff_impersonation', client.allow_staff_impersonation ? '1' : '0', allow ? '1' : '0'
  );
  flash.set(req, 'success', allow
    ? `Staff can now log in as ${client.company_legal_name} (if the global switch and the impersonate_client permission also allow it).`
    : `Staff can no longer log in as ${client.company_legal_name}.`);
  res.redirect(`/clients/${clientId}`);
}

/**
 * docs/schema.sql Section AV — re-checks all three gates itself (global
 * switch, per-client flag, client active state) rather than relying
 * solely on the route-level impersonate_client permission check, matching
 * this project's established pattern for security-sensitive actions.
 */
async function impersonate(req, res) {
  const clientId = parseInt(req.params.id, 10);
  const client = await clientRepository.find(clientId);
  if (!client) {
    res.status(404).send('Client not found.');
    return;
  }

  if ((await companySettingsRepository.get('client_impersonation_enabled')) !== '1') {
    flash.set(req, 'error', 'Staff client-portal impersonation is switched off (Company Settings).');
    res.redirect(`/clients/${clientId}`);
    return;
  }
  if (!client.allow_staff_impersonation) {
    flash.set(req, 'error', `Impersonation isn't enabled for ${client.company_legal_name} — turn it on below first.`);
    res.redirect(`/clients/${clientId}`);
    return;
  }
  if (!client.is_active) {
    flash.set(req, 'error', 'This client is deactivated and cannot be impersonated.');
    res.redirect(`/clients/${clientId}`);
    return;
  }

  const user = req.user;
  await clientPortalService.startImpersonation(req, user.id, clientId);
  res.redirect('/client');
}

/**
 * Batch 3 #13b — a free-form extra document attached to this client
 * (not tied to one specific order), e.g. a standing NDA or a general
 * compliance certificate. Reuses file_store/fileUploadService rather
 * than a new table — see schema.sql Section AZ.
 */
async function uploadAdditionalDocument(req, res) {
  const clientId = parseInt(req.params.id, 10);
  const client = await clientRepository.find(clientId);
  if (!client) {
    res.status(404).send('Client not found.');
    return;
  }

  const title = String(req.body.title || '').trim();
  if (title === '') {
    flash.set(req, 'error', 'A title is required for the document.');
    res.redirect(`/clients/${clientId}`);
    return;
  }
  const notes = String(req.body.notes || '').trim() || null;

  try {
    const fileId = await fileUploadService.handleUpload(
      req,
      'document',
      'client_additional_document',
      `clients/${fileUploadService.sanitizePathSegment(client.client_unique_number)}/additional_documents`,
      clientId,
      null,
      req.user.id,
      null,
      String(req.body.received_from || '').trim() || null,
      title
    );
    await fileStoreRepository.markAsAdditionalDocument(fileId, notes);
    await auditLogRepository.log(req.user.id, 'CLIENT_ADDITIONAL_DOCUMENT_ADDED', 'file_store', fileId, 'document_type_label', null, title);
    flash.set(req, 'success', `"${title}" attached to this client.`);
  } catch (e) {
    flash.set(req, 'error', e.message);
  }
  res.redirect(`/clients/${clientId}`);
}

/** Soft delete only — the row is deactivated, the file on disk is never touched. */
async function deleteAdditionalDocument(req, res) {
  const clientId = parseInt(req.params.id, 10);
  const fileId = parseInt(req.params.fileId, 10);
  const file = await fileStoreRepository.find(fileId);
  if (!file || file.client_id !== clientId || !file.is_additional_document) {
    flash.set(req, 'error', 'Document not found.');
    res.redirect(`/clients/${clientId}`);
    return;
  }

  await fileStoreRepository.deactivate(fileId);
  await auditLogRepository.log(req.user.id, 'CLIENT_ADDITIONAL_DOCUMENT_REMOVED', 'file_store', fileId, 'document_type_label', file.document_type_label, null);
  flash.set(req, 'success', `"${file.document_type_label}" removed. The file itself is never deleted from disk.`);
  res.redirect(`/clients/${clientId}`);
}

module.exports = {
  index, inactiveIndex, create, store, show, editForm, update, updateAgreementFooter, toggleActive, overrideUniqueNumber,
  setImpersonationAllowed, impersonate, uploadAdditionalDocument, deleteAdditionalDocument,
};
