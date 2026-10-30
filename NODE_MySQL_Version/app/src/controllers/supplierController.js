'use strict';

const flash = require('../helpers/flash');
const editFingerprintHelper = require('../helpers/editFingerprint');
const auditLogRepository = require('../repositories/auditLogRepository');
const lookupRepository = require('../repositories/lookupRepository');
const supplierFactoryAddressRepository = require('../repositories/supplierFactoryAddressRepository');
const supplierRepository = require('../repositories/supplierRepository');
const testModeService = require('../services/testModeService');
const vendorDetailsService = require('../services/vendorDetailsService');
const vendorDetailsRepository = require('../repositories/vendorDetailsRepository');
const bankDetailsInput = require('../helpers/bankDetailsInput');

/**
 * Port of App\Controllers\SupplierController — the Suppliers directory
 * (docs/schema.sql Section BZ), built the same way as the Logistics
 * Partners directory. Gated on manage_suppliers (Admin/MD/ED and Super
 * Admin by default). Editing or deleting a supplier here never changes an
 * existing order: order_supplier_po keeps its own snapshot of the
 * supplier's details.
 */

/**
 * Active Supplier Type options, plus any type a supplier already holds
 * whose option has since been deactivated (so editing never silently
 * drops it).
 */
async function typeOptions(alsoInclude = []) {
  const options = (await lookupRepository.dropdownOptions('supplier_type')).map((o) => o.option_value);
  for (const type of alsoInclude) {
    if (!options.includes(type)) {
      options.push(type);
    }
  }
  return options;
}

async function collectFormData(req, existingTypes = []) {
  const name = String(req.body.supplier_legal_name || '').trim();
  if (name === '') {
    return { error: 'Supplier legal name is required.' };
  }

  const allowedTypes = await typeOptions(existingTypes);
  const rawTypes = req.body['types[]'] ?? req.body.types ?? [];
  const types = [...new Set((Array.isArray(rawTypes) ? rawTypes : [rawTypes]).map((t) => String(t).trim()).filter((t) => t !== ''))];
  if (types.length === 0) {
    return { error: 'Select at least one supplier type.' };
  }
  for (const type of types) {
    if (!allowedTypes.includes(type)) {
      return { error: `"${type}" is not a valid supplier type.` };
    }
  }

  const email = String(req.body.email || '').trim();
  if (email !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    return { error: 'Enter a valid email address, or leave it blank.' };
  }

  const field = (key) => String(req.body[key] || '').trim() || null;
  return {
    data: {
      supplier_legal_name: name,
      types,
      address: field('address'),
      city: field('city'),
      state: field('state'),
      gstin: field('gstin'),
      pan: field('pan'),
      contact_person: field('contact_person'),
      contact_person_phone: field('contact_person_phone'),
      contact_person_whatsapp: field('contact_person_whatsapp'),
      phone: field('phone'),
      email: email || null,
      whatsapp_number: field('whatsapp_number'),
      notes: field('notes'),
    },
  };
}

async function index(req, res) {
  const type = String(req.query.type || '').trim();
  const search = String(req.query.q || '').trim();
  res.renderView('suppliers/index', {
    suppliers: await supplierRepository.all(true, type || null, search || null),
    usedIds: [...(await supplierRepository.idsUsedOnOrders())],
    supplierTypes: await typeOptions(),
    filterType: type,
    search,
  }, 'layout/base');
}

async function createForm(req, res) {
  res.renderView('suppliers/form', {
    bank: vendorDetailsRepository.bankDetails(null),
    bankAccountTypes: bankDetailsInput.ACCOUNT_TYPES,
    supplier: null,
    supplierTypes: await typeOptions(),
    factoryAddresses: [],
  }, 'layout/base');
}

async function create(req, res) {
  const result = await collectFormData(req);
  const error = result.error || (await vendorDetailsService.bankInputError('supplier', null, req.body));
  if (error) {
    flash.set(req, 'error', error);
    res.redirect('/suppliers/create');
    return;
  }

  const id = await supplierRepository.create(result.data, req.user.id);
  await vendorDetailsService.applyStaffBankInput('supplier', id, req.user.id, req.body);
  if (await testModeService.isEnabled()) {
    await supplierRepository.markTest(id);
  }
  await auditLogRepository.log(req.user.id, 'SUPPLIER_CREATED', 'suppliers', id, null, null, result.data.supplier_legal_name);
  flash.set(req, 'success', `"${result.data.supplier_legal_name}" added to the suppliers directory. Add its factory addresses below if it has any.`);
  res.redirect(`/suppliers/${id}/edit`);
}

async function editForm(req, res) {
  const id = parseInt(req.params.id, 10);
  const supplier = await supplierRepository.find(id);
  if (!supplier) {
    res.status(404).send('Supplier not found.');
    return;
  }
  res.renderView('suppliers/form', {
    supplier,
    editFingerprint: editFingerprintHelper.of(supplier, supplierRepository.EDIT_FIELDS),
    supplierTypes: await typeOptions(supplier.types),
    factoryAddresses: await supplierFactoryAddressRepository.forSupplier(id),
    usedOnAnOrder: await supplierRepository.isUsedOnAnOrder(id),
    bank: vendorDetailsRepository.bankDetails(supplier),
    bankAccountTypes: bankDetailsInput.ACCOUNT_TYPES,
    detailsLink: vendorDetailsService.detailsLinkUrl(supplier),
    vendorKind: 'supplier',
    vendor: supplier,
  }, 'layout/base');
}

async function update(req, res) {
  const id = parseInt(req.params.id, 10);
  const supplier = await supplierRepository.find(id);
  if (!supplier) {
    res.status(404).send('Supplier not found.');
    return;
  }

  // QA Round 6, R6-N27: someone else saved this supplier since the form was opened.
  const conflict = await editFingerprintHelper.conflict(req.body, supplier, supplierRepository.EDIT_FIELDS, 'suppliers', id, 'supplier');
  if (conflict) {
    flash.set(req, 'error', conflict);
    res.redirect(`/suppliers/${id}/edit`);
    return;
  }
  const result = await collectFormData(req, supplier.types);
  if (result.error) {
    flash.set(req, 'error', result.error);
    res.redirect(`/suppliers/${id}/edit`);
    return;
  }

  const bankError = await vendorDetailsService.bankInputError('supplier', id, req.body);
  if (bankError) {
    flash.set(req, 'error', bankError);
    res.redirect(`/suppliers/${id}/edit#bank-details`);
    return;
  }

  await supplierRepository.update(id, result.data);
  await vendorDetailsService.applyStaffBankInput('supplier', id, req.user.id, req.body);
  await auditLogRepository.log(req.user.id, 'SUPPLIER_UPDATED', 'suppliers', id, 'supplier_legal_name', supplier.supplier_legal_name, result.data.supplier_legal_name);
  flash.set(req, 'success', `"${result.data.supplier_legal_name}" updated. Orders that already saved Supplier PO terms keep the details they recorded.`);
  res.redirect('/suppliers');
}

async function toggleActive(req, res) {
  const id = parseInt(req.params.id, 10);
  const supplier = await supplierRepository.find(id);
  if (!supplier) {
    flash.set(req, 'error', 'Supplier not found.');
    res.redirect('/suppliers');
    return;
  }

  await supplierRepository.toggleActive(id);
  const nowActive = !Number(supplier.is_active);
  await auditLogRepository.log(req.user.id, nowActive ? 'SUPPLIER_REACTIVATED' : 'SUPPLIER_DEACTIVATED', 'suppliers', id, 'is_active', String(Number(supplier.is_active)), String(Number(nowActive)));
  flash.set(req, 'success', `"${supplier.supplier_legal_name}" ${nowActive ? 'reactivated.' : 'deactivated — it no longer appears in the Supplier PO supplier list.'}`);
  res.redirect('/suppliers');
}

async function remove(req, res) {
  const id = parseInt(req.params.id, 10);
  const supplier = await supplierRepository.find(id);
  if (!supplier) {
    flash.set(req, 'error', 'Supplier not found.');
    res.redirect('/suppliers');
    return;
  }
  if (await supplierRepository.isUsedOnAnOrder(id)) {
    flash.set(req, 'error', `"${supplier.supplier_legal_name}" has been used on an order, so it can't be deleted — deactivate it instead to hide it from new Supplier POs.`);
    res.redirect('/suppliers');
    return;
  }

  await supplierRepository.remove(id);
  await auditLogRepository.log(req.user.id, 'SUPPLIER_DELETED', 'suppliers', id, 'supplier_legal_name', supplier.supplier_legal_name, null);
  flash.set(req, 'success', `"${supplier.supplier_legal_name}" removed from the directory.`);
  res.redirect('/suppliers');
}

async function addFactoryAddress(req, res) {
  const id = parseInt(req.params.id, 10);
  if (!(await supplierRepository.find(id))) {
    flash.set(req, 'error', 'Supplier not found.');
    res.redirect('/suppliers');
    return;
  }
  const address = String(req.body.address || '').trim();
  if (address === '') {
    flash.set(req, 'error', 'Factory address is required.');
    res.redirect(`/suppliers/${id}/edit#factory-addresses`);
    return;
  }
  const addressId = await supplierFactoryAddressRepository.create(id, address, String(req.body.label || '').trim() || null);
  await auditLogRepository.log(req.user.id, 'SUPPLIER_FACTORY_ADDRESS_CREATED', 'supplier_factory_addresses', addressId, null, null, address);
  flash.set(req, 'success', 'Factory address added.');
  res.redirect(`/suppliers/${id}/edit#factory-addresses`);
}

async function deleteFactoryAddress(req, res) {
  const id = parseInt(req.params.id, 10);
  const addressId = parseInt(req.params.addressId, 10);
  const address = await supplierFactoryAddressRepository.find(addressId);
  if (!address || Number(address.supplier_id) !== id) {
    flash.set(req, 'error', 'Factory address not found.');
    res.redirect(`/suppliers/${id}/edit#factory-addresses`);
    return;
  }
  await supplierFactoryAddressRepository.remove(addressId);
  await auditLogRepository.log(req.user.id, 'SUPPLIER_FACTORY_ADDRESS_DELETED', 'supplier_factory_addresses', addressId, 'address', address.address, null);
  flash.set(req, 'success', 'Factory address removed. Supplier POs already saved keep the delivery location they recorded.');
  res.redirect(`/suppliers/${id}/edit#factory-addresses`);
}

/** Owner request (2026-10-27): send the supplier the Vendor Details Link (company + bank details). */
async function sendDetailsLink(req, res) {
  const id = parseInt(req.params.id, 10);
  if (!(await supplierRepository.find(id))) {
    res.status(404).send('Supplier not found.');
    return;
  }
  const sent = await vendorDetailsService.sendDetailsLink('supplier', id, req.user.id);
  flash.set(req, 'success', `Vendor Details Link ${sent.emailedTo ? `emailed to ${sent.emailedTo}` : 'generated'}: ${sent.link}`);
  res.redirect(`/suppliers/${id}/edit#vendor-details-link`);
}

module.exports = {
  sendDetailsLink,
  index, createForm, create, editForm, update, toggleActive, remove, addFactoryAddress, deleteFactoryAddress,
};
