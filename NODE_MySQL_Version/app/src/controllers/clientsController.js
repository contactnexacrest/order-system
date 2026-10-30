'use strict';

const fs = require('fs');
const editFingerprintHelper = require('../helpers/editFingerprint');
const path = require('path');
const crypto = require('crypto');
const flash = require('../helpers/flash');
const fieldValidator = require('../helpers/fieldValidator');
const passwordHash = require('../helpers/passwordHash');
const reasonValidator = require('../helpers/reasonValidator');
const clientRepository = require('../repositories/clientRepository');
const clientLoginRepository = require('../repositories/clientLoginRepository');
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
const orderAccessService = require('../services/orderAccessService');
const env = require('../config/env');
const { todayIst } = require('../helpers/dbDateTime');

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

  const data = await collectClientFields(req, companyLegalName, billingAddress);
  const emailError = clientEmailError(data);
  if (emailError) {
    flash.set(req, 'error', emailError);
    flash.setOld(req, req.body);
    res.redirect('/clients/create');
    return;
  }
  const other = await clientRepository.findOtherWithEmail(data.email);
  if (other) {
    flash.set(req, 'error', clientRepository.duplicateEmailMessage(data.email, other));
    flash.setOld(req, req.body);
    res.redirect('/clients/create');
    return;
  }
  const clientUniqueNumber = await referenceNumberService.generateClientUniqueNumber();
  const clientId = await clientRepository.create(data, user.id, clientUniqueNumber);
  if (await testModeService.isEnabled()) {
    await clientRepository.markTest(clientId);
  }
  // External QA report L-23: client creation was never audit-logged.
  await auditLogRepository.log(user.id, 'CLIENT_CREATED', 'clients', clientId, 'company_legal_name', null, companyLegalName);

  flash.set(req, 'success', `Client created — Buyer Inquiry Ref ${clientUniqueNumber}.`);
  res.redirect(`/clients/${clientId}`);
}

/**
 * 90-item backlog Phase 6: "Duplicate" a client — re-onboard a
 * near-identical but genuinely distinct entity (a sister company, a
 * corrected re-entry) under a brand-new Buyer Inquiry Ref, never the
 * source's own. Pre-fills the ordinary New Client form from the source
 * client's current field values; duplicateStore() below refuses to save
 * unless at least one of those values was actually changed, since an
 * exact duplicate serves no purpose the original record doesn't already.
 */
async function duplicateForm(req, res) {
  const source = await clientRepository.find(parseInt(req.params.id, 10));
  if (!source) {
    res.status(404).send('Client not found.');
    return;
  }

  const pulledOld = flash.pullOld(req);
  res.renderView('clients/create', {
    old: Object.keys(pulledOld).length > 0 ? pulledOld : source,
    cooTypes: await lookupRepository.dropdownOptions('coo_type'),
    duplicateOf: source,
  }, 'layout/base');
}

async function duplicateStore(req, res) {
  const source = await clientRepository.find(parseInt(req.params.id, 10));
  if (!source) {
    res.status(404).send('Client not found.');
    return;
  }
  const user = req.user;

  const companyLegalName = String(req.body.company_legal_name || '').trim();
  const billingAddress = String(req.body.billing_address || '').trim();

  if (companyLegalName === '' || billingAddress === '') {
    flash.set(req, 'error', 'Company legal name and billing address are required.');
    flash.setOld(req, req.body);
    res.redirect(`/clients/${source.id}/duplicate`);
    return;
  }

  const data = await collectClientFields(req, companyLegalName, billingAddress);

  if (isIdenticalToSource(data, source)) {
    flash.set(req, 'error', 'Change at least one detail before saving a duplicate — if nothing is different, there is no need for a new client record.');
    flash.setOld(req, req.body);
    res.redirect(`/clients/${source.id}/duplicate`);
    return;
  }

  const emailError = clientEmailError(data);
  if (emailError) {
    flash.set(req, 'error', emailError);
    flash.setOld(req, req.body);
    res.redirect(`/clients/${source.id}/duplicate`);
    return;
  }
  const other = await clientRepository.findOtherWithEmail(data.email);
  if (other) {
    flash.set(req, 'error', clientRepository.duplicateEmailMessage(data.email, other));
    flash.setOld(req, req.body);
    res.redirect(`/clients/${source.id}/duplicate`);
    return;
  }

  const clientUniqueNumber = await referenceNumberService.generateClientUniqueNumber();
  const clientId = await clientRepository.create(data, user.id, clientUniqueNumber);
  await clientRepository.markDuplicateOf(clientId, source.id);
  if (await testModeService.isEnabled()) {
    await clientRepository.markTest(clientId);
  }
  await auditLogRepository.log(user.id, 'CLIENT_DUPLICATED', 'clients', clientId, 'duplicate_of_client_id', null, String(source.id));

  flash.set(req, 'success', `Client created as a duplicate of "${source.company_legal_name}" — Buyer Inquiry Ref ${clientUniqueNumber}.`);
  res.redirect(`/clients/${clientId}`);
}

/** Every field store()/duplicateStore() can set, shared so both build it identically. */
/** QA Round 5, R5-28: the server refuses a malformed e-mail the browser would have stopped. */
function clientEmailError(data) {
  for (const [field, label] of [['email', 'Email'], ['consignee_email', 'Consignee email'], ['notify_party_email', 'Notify party email']]) {
    const error = fieldValidator.email(String(data[field] || ''), label);
    if (error) return error;
  }
  return null;
}

async function collectClientFields(req, companyLegalName, billingAddress) {
  return {
    company_legal_name: companyLegalName,
    billing_address: billingAddress,
    vat_eori_tax_no: String(req.body.vat_eori_tax_no || '').trim() || null,
    contact_person: String(req.body.contact_person || '').trim() || null,
    email: String(req.body.email || '').trim() || null,
    phone: String(req.body.phone || '').trim() || null,
    country_of_destination: String(req.body.country_of_destination || '').trim() || null,
    coo_type: (await lookupRepository.sanitizeOption('coo_type', String(req.body.coo_type || '').trim())) || 'To Be Confirmed',
    ...collectPartyFields(req),
  };
}

/** True only if every field in data (that also exists on source) matches source's current value — loose/string comparison so null vs. '' never counts as a difference. */
function isIdenticalToSource(data, source) {
  for (const [key, value] of Object.entries(data)) {
    if (!(key in source)) continue;
    const a = value === null || value === undefined ? '' : String(value);
    const b = source[key] === null || source[key] === undefined ? '' : String(source[key]);
    if (a !== b) return false;
  }
  return true;
}

async function show(req, res) {
  const client = await clientRepository.find(parseInt(req.params.id, 10));
  if (!client) {
    res.status(404).send('Client not found.');
    return;
  }
  // QA Round 6, R6-N01: only the orders this user may open.
  const orders = await orderAccessService.visibleRows(await orderRepository.forClient(client.id), req.user.id, req.permissions, 'id');
  // docs/schema.sql Section AV
  const impersonationGloballyEnabled = (await companySettingsRepository.get('client_impersonation_enabled')) === '1';
  const impersonationReasons = [];
  if (!impersonationGloballyEnabled) impersonationReasons.push('the global switch is off');
  if (!client.allow_staff_impersonation) impersonationReasons.push('it is not enabled for this client');
  if (!client.is_active) impersonationReasons.push('this client is deactivated');
  const impersonationUnavailableReason = impersonationReasons.join(', ');
  // 90-item backlog Phase 6 Item B
  const orderHistoryGlobalDefault = (await companySettingsRepository.get('client_portal_show_order_history')) !== '0';
  // External QA report M-02
  const documentDownloadsGlobalDefault = (await companySettingsRepository.get('client_portal_show_document_downloads')) !== '0';
  const additionalDocuments = await fileStoreRepository.additionalDocumentsForClient(client.id);

  // Item 2 — agreement status: never once force-expired, and (when an
  // expiry date is set) not past it. Renew is only offered within 30 days
  // of the current expiry date, or once it's already passed — a UI nudge,
  // not a security gate.
  const agreementForceExpired = !!client.agreement_force_expired;
  const agreementExpiryDate = client.agreement_expiry_date || null;
  const todayStr = todayIst();
  const agreementExpired = !!(agreementExpiryDate && agreementExpiryDate < todayStr);
  const agreementActive = !agreementForceExpired && !agreementExpired;
  const agreementRenewEligible = !!(agreementExpiryDate && new Date(agreementExpiryDate).getTime() <= Date.now() + 30 * 86400000);

  res.renderView('clients/show', {
    client, orders, impersonationGloballyEnabled, orderHistoryGlobalDefault, documentDownloadsGlobalDefault, impersonationUnavailableReason, additionalDocuments,
    agreementForceExpired, agreementExpiryDate, agreementExpired, agreementActive, agreementRenewEligible,
    duplicateOfClient: client.duplicate_of_client_id ? await clientRepository.find(client.duplicate_of_client_id) : null,
    duplicatedInto: await clientRepository.findDuplicatesOf(client.id),
    clientLogin: await clientLoginRepository.findByClientId(client.id),
  }, 'layout/base');
}

async function editForm(req, res) {
  const client = await clientRepository.find(parseInt(req.params.id, 10));
  if (!client) {
    res.status(404).send('Client not found.');
    return;
  }
  res.renderView('clients/edit', { client, editFingerprint: editFingerprintHelper.of(client, editFields()), isSuperAdmin: await superAdminService.isEffective(req.user.id), cooTypes: await lookupRepository.dropdownOptions('coo_type') }, 'layout/base');
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
    coo_type: (await lookupRepository.sanitizeOption('coo_type', String(req.body.coo_type || '').trim())) || null,
    ...collectPartyFields(req),
  };

  const emailError = clientEmailError(data);
  if (emailError) {
    flash.set(req, 'error', emailError);
    res.redirect(`/clients/${clientId}/edit`);
    return;
  }
  // QA Round 6, R6-N27: someone else saved this client since the form was opened.
  const conflict = await editFingerprintHelper.conflict(req.body, client, editFields(), 'clients', clientId, 'client');
  if (conflict) {
    flash.set(req, 'error', conflict);
    res.redirect(`/clients/${clientId}/edit`);
    return;
  }
  const other = await clientRepository.findOtherWithEmail(data.email, clientId);
  if (other) {
    flash.set(req, 'error', clientRepository.duplicateEmailMessage(data.email, other));
    res.redirect(`/clients/${clientId}/edit`);
    return;
  }

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
/**
 * QA Round 6, R6-N27: the columns the client edit form saves (its edit
 * fingerprint) — the same keys update() builds data from. Port of
 * ClientController::editFields().
 */
function editFields() {
  return ['company_legal_name', 'billing_address', 'vat_eori_tax_no', 'contact_person', 'email', 'phone', 'country_of_destination', 'coo_type',
    ...Object.keys(collectPartyFields({ body: {} }))];
}

function collectPartyFields(req) {
  const consigneeSameAsBuyer = !!req.body.consignee_same_as_buyer;
  const notifySameAsConsignee = !!req.body.notify_party_same_as_consignee;

  const fields = {
    billing_address_line1: String(req.body.billing_address_line1 || '').trim() || null,
    billing_address_line2: String(req.body.billing_address_line2 || '').trim() || null,
    billing_city: String(req.body.billing_city || '').trim() || null,
    billing_postcode: String(req.body.billing_postcode || '').trim() || null,
    billing_country: String(req.body.billing_country || '').trim() || null,
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
 * Item 2 — the actual signed-agreement file, with an expiry date so
 * downstream orders know how long the footer text above should keep
 * printing. Uploading always starts the agreement fresh: force_expired is
 * cleared regardless of its previous state.
 */
async function uploadAgreement(req, res) {
  const clientId = parseInt(req.params.id, 10);
  const client = await clientRepository.find(clientId);
  if (!client) {
    res.status(404).send('Client not found.');
    return;
  }

  const expiryDate = String(req.body.agreement_expiry_date || '').trim() || null;

  try {
    const fileId = await fileUploadService.handleUpload(
      req,
      'agreement_file',
      'client_agreement_file',
      `clients/${fileUploadService.sanitizePathSegment(client.client_unique_number)}/agreement`,
      clientId,
      null,
      req.user.id
    );
    const file = await fileStoreRepository.find(fileId);
    await clientRepository.setAgreementFile(clientId, file.server_path, file.original_filename, expiryDate);
    await auditLogRepository.log(req.user.id, 'CLIENT_AGREEMENT_FILE_UPLOADED', 'clients', clientId, 'agreement_file_original_name', null, file.original_filename);
    flash.set(req, 'success', 'Agreement file uploaded.');
  } catch (e) {
    flash.setErrorFromException(req, e);
  }
  res.redirect(`/clients/${clientId}`);
}

/**
 * Manually ends an agreement immediately, regardless of its expiry date —
 * e.g. the client breached a term and staff don't want to wait for the
 * natural expiry.
 */
async function forceExpireAgreement(req, res) {
  const clientId = parseInt(req.params.id, 10);
  const client = await clientRepository.find(clientId);
  if (!client) {
    res.status(404).send('Client not found.');
    return;
  }

  await clientRepository.setAgreementForceExpired(clientId, true);
  await auditLogRepository.log(req.user.id, 'CLIENT_AGREEMENT_FORCE_EXPIRED', 'clients', clientId, 'agreement_force_expired', '0', '1');
  flash.set(req, 'success', 'Agreement force-expired. The footer will stop printing on new documents immediately.');
  res.redirect(`/clients/${clientId}`);
}

/**
 * Resets the expiry date (and, optionally, the file) and clears
 * force_expired. The UI only shows this button within 30 days of the
 * current expiry date or after it's passed (see clients/show.njk) — that's
 * a staff workflow nudge, not a security gate, so nothing here enforces
 * it server-side.
 */
async function renewAgreement(req, res) {
  const clientId = parseInt(req.params.id, 10);
  const client = await clientRepository.find(clientId);
  if (!client) {
    res.status(404).send('Client not found.');
    return;
  }

  const expiryDate = String(req.body.agreement_expiry_date || '').trim() || null;

  try {
    if (req.file && req.file.buffer) {
      const fileId = await fileUploadService.handleUpload(
        req,
        'agreement_file',
        'client_agreement_file',
        `clients/${fileUploadService.sanitizePathSegment(client.client_unique_number)}/agreement`,
        clientId,
        null,
        req.user.id
      );
      const file = await fileStoreRepository.find(fileId);
      await clientRepository.renewAgreement(clientId, expiryDate, file.server_path, file.original_filename);
    } else {
      await clientRepository.renewAgreement(clientId, expiryDate);
    }
    await auditLogRepository.log(req.user.id, 'CLIENT_AGREEMENT_RENEWED', 'clients', clientId, 'agreement_expiry_date', client.agreement_expiry_date ?? null, expiryDate);
    flash.set(req, 'success', 'Agreement renewed.');
  } catch (e) {
    flash.setErrorFromException(req, e);
  }
  res.redirect(`/clients/${clientId}`);
}

/** Streams the agreement file directly from its stored path — never public, always permission-gated by the route. */
async function downloadAgreement(req, res) {
  const clientId = parseInt(req.params.id, 10);
  const client = await clientRepository.find(clientId);
  if (!client || !client.agreement_file_path || !fs.existsSync(client.agreement_file_path)) {
    res.status(404).send('File is missing from storage.');
    return;
  }

  const safeDownloadName = String(client.agreement_file_original_name || 'agreement')
    .replace(/[/\\]/g, '-')
    // eslint-disable-next-line no-control-regex
    .replace(/[\x00-\x1F\x7F"]/g, '');
  const agreementMimeTypes = { '.pdf': 'application/pdf', '.doc': 'application/msword', '.docx': 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' };
  const contentType = agreementMimeTypes[path.extname(client.agreement_file_path).toLowerCase()] || 'application/octet-stream';
  res.setHeader('Content-Type', contentType);
  res.setHeader('Content-Disposition', `attachment; filename="${safeDownloadName}"`);
  res.setHeader('Content-Length', String(fs.statSync(client.agreement_file_path).size));
  fs.createReadStream(client.agreement_file_path).pipe(res);
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
 * 90-item backlog Phase 6 Item B — per-client override of whether this
 * client's own "My Orders" portal dashboard shows their full order
 * history (completed/lost orders included) or active orders only. ''
 * posted = clear the override back to null (inherit the global
 * client_portal_show_order_history switch).
 */
async function setOrderHistoryVisibilityOverride(req, res) {
  const clientId = parseInt(req.params.id, 10);
  const client = await clientRepository.find(clientId);
  if (!client) {
    res.status(404).send('Client not found.');
    return;
  }

  const raw = req.body.order_history_visibility_override ?? '';
  const newValue = raw === '' ? null : raw === '1';
  await clientRepository.setOrderHistoryVisibilityOverride(clientId, newValue);
  const user = req.user;
  const oldOverride = client.order_history_visibility_override;
  await auditLogRepository.log(
    user.id, 'CLIENT_ORDER_HISTORY_VISIBILITY_CHANGED', 'clients', clientId,
    'order_history_visibility_override',
    oldOverride === null || oldOverride === undefined ? null : String(Number(oldOverride)),
    newValue === null ? null : String(Number(newValue))
  );
  flash.set(req, 'success', `Order history visibility for ${client.company_legal_name} updated.`);
  res.redirect(`/clients/${clientId}`);
}

/**
 * External QA report M-02: same global + per-client override pattern as
 * setOrderHistoryVisibilityOverride() above, for document-download
 * visibility (company_settings client_portal_show_document_downloads /
 * clients.document_downloads_visibility_override).
 */
async function setDocumentDownloadsVisibilityOverride(req, res) {
  const clientId = parseInt(req.params.id, 10);
  const client = await clientRepository.find(clientId);
  if (!client) {
    res.status(404).send('Client not found.');
    return;
  }

  const raw = req.body.document_downloads_visibility_override ?? '';
  const newValue = raw === '' ? null : raw === '1';
  await clientRepository.setDocumentDownloadsVisibilityOverride(clientId, newValue);
  const user = req.user;
  const oldOverride = client.document_downloads_visibility_override;
  await auditLogRepository.log(
    user.id, 'CLIENT_DOCUMENT_DOWNLOADS_VISIBILITY_CHANGED', 'clients', clientId,
    'document_downloads_visibility_override',
    oldOverride === null || oldOverride === undefined ? null : String(Number(oldOverride)),
    newValue === null ? null : String(Number(newValue))
  );
  flash.set(req, 'success', `Document download visibility for ${client.company_legal_name} updated.`);
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
 * For a non-technical client who's locked out of the portal and can't
 * follow their own "Forgot password" email link: the client's own
 * /client/account "Change Password" form requires their CURRENT
 * password, so even staff impersonating them cannot set a new one —
 * there was no path at all before this. Mirrors
 * userController.forceResetPassword() exactly (same temp-password
 * generation, same reason requirement, same "shown once, hand it over
 * directly" flash) but against client_logins instead of users. Requires
 * client_logins to already exist (clientLoginRepository's own docblock:
 * that row is created ONLY by clientPortalService.provisionIfNeeded(),
 * never here) — this resets an existing portal account, it does not
 * provision one.
 */
async function resetPortalPassword(req, res) {
  const clientId = parseInt(req.params.id, 10);
  const client = await clientRepository.find(clientId);
  if (!client) {
    res.status(404).send('Client not found.');
    return;
  }

  const login = await clientLoginRepository.findByClientId(clientId);
  if (!login) {
    flash.set(req, 'error', `${client.company_legal_name} has no client-portal account yet — portal access is provisioned automatically once this client's first order's advance payment clears.`);
    res.redirect(`/clients/${clientId}`);
    return;
  }

  const reason = String(req.body.reason || '').trim();
  const reasonError = reasonValidator.check(reason);
  if (reasonError) {
    flash.set(req, 'error', reasonError);
    res.redirect(`/clients/${clientId}`);
    return;
  }

  const tempPassword = generateTempPassword();
  await clientLoginRepository.updatePassword(clientId, await passwordHash.hash(tempPassword), true);

  const user = req.user;
  await auditLogRepository.log(user.id, 'CLIENT_PASSWORD_ADMIN_RESET', 'clients', clientId, null, null, null, reason);

  flash.set(req, 'success', `Password reset for ${client.company_legal_name}'s client-portal account. One-time temporary password: ${tempPassword} — give this to them directly; they'll be asked to change it on first login.`);
  res.redirect(`/clients/${clientId}`);
}

// Same shape as userController.js's own generateTempPassword() — 12
// random bytes -> 16-char base64url, always contains mixed case +
// digits, no dependency on a symbol since force_password_change means
// it only has to survive one login.
function generateTempPassword() {
  return crypto.randomBytes(12).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
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
    flash.setErrorFromException(req, e);
  }
  res.redirect(`/clients/${clientId}`);
}

/**
 * Soft delete only — the row is deactivated, the file on disk is never
 * touched.
 *
 * External QA report L-18: "deleteAdditionalDocument (order + client)
 * still takes no reason; no 'show removed' view." Now requires a reason
 * (reasonValidator.MIN_LENGTH, same standing instruction as every other
 * reason field in this app), logged on the audit entry — see
 * showRemovedAdditionalDocuments() for the "show removed" half.
 */
async function deleteAdditionalDocument(req, res) {
  const clientId = parseInt(req.params.id, 10);
  const fileId = parseInt(req.params.fileId, 10);
  const file = await fileStoreRepository.find(fileId);
  if (!file || file.client_id !== clientId || !file.is_additional_document) {
    flash.set(req, 'error', 'Document not found.');
    res.redirect(`/clients/${clientId}`);
    return;
  }

  const reason = String(req.body.reason || '').trim();
  const reasonError = reasonValidator.check(reason);
  if (reasonError) {
    flash.set(req, 'error', reasonError);
    res.redirect(`/clients/${clientId}`);
    return;
  }

  await fileStoreRepository.deactivate(fileId);
  await auditLogRepository.log(req.user.id, 'CLIENT_ADDITIONAL_DOCUMENT_REMOVED', 'file_store', fileId, 'document_type_label', file.document_type_label, null, reason);
  flash.set(req, 'success', `"${file.document_type_label}" removed. The file itself is never deleted from disk.`);
  res.redirect(`/clients/${clientId}`);
}

/** External QA report L-18: "no screen to see removed files" — a read-only list of this client's deactivated additional documents, newest first. */
async function showRemovedAdditionalDocuments(req, res) {
  const clientId = parseInt(req.params.id, 10);
  const client = await clientRepository.find(clientId);
  if (!client) {
    res.status(404).send('Client not found.');
    return;
  }
  res.renderView('clients/removed_additional_documents', {
    client,
    removedDocuments: await fileStoreRepository.removedAdditionalDocumentsForClient(clientId),
  });
}

module.exports = {
  index, inactiveIndex, create, store, show, editForm, update, updateAgreementFooter, toggleActive, overrideUniqueNumber,
  setImpersonationAllowed, setOrderHistoryVisibilityOverride, setDocumentDownloadsVisibilityOverride, impersonate, resetPortalPassword, uploadAdditionalDocument, deleteAdditionalDocument, showRemovedAdditionalDocuments,
  uploadAgreement, forceExpireAgreement, renewAgreement, downloadAgreement,
  duplicateForm, duplicateStore,
};
