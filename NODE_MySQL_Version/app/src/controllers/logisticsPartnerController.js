'use strict';

const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const logisticsPartnerRepository = require('../repositories/logisticsPartnerRepository');

/**
 * Port of App\Controllers\LogisticsPartnerController (docs/schema.sql
 * Section AQ). Gated on manage_logistics_partners, same tier as
 * manage_hs_codes — Admin/MD/ED and Super Admin only by default.
 */

async function index(req, res) {
  const serviceType = String(req.query.service_type || '').trim();
  res.renderView('logistics_partners/index', {
    partners: await logisticsPartnerRepository.all(true, serviceType || null),
    serviceTypes: logisticsPartnerRepository.SERVICE_TYPES,
    filterServiceType: serviceType,
  }, 'layout/base');
}

async function createForm(req, res) {
  res.renderView('logistics_partners/create', {
    serviceTypes: logisticsPartnerRepository.SERVICE_TYPES,
  }, 'layout/base');
}

function collectFormData(req) {
  const partnerName = String(req.body.partner_name || '').trim();
  const serviceType = String(req.body.service_type || '').trim();

  if (partnerName === '') {
    return { error: 'Partner name is required.' };
  }
  if (!Object.prototype.hasOwnProperty.call(logisticsPartnerRepository.SERVICE_TYPES, serviceType)) {
    return { error: 'Select a valid service type.' };
  }

  return {
    data: {
      partner_name: partnerName,
      service_type: serviceType,
      address: String(req.body.address || '').trim() || null,
      city: String(req.body.city || '').trim() || null,
      state: String(req.body.state || '').trim() || null,
      phone: String(req.body.phone || '').trim() || null,
      whatsapp_number: String(req.body.whatsapp_number || '').trim() || null,
      email: String(req.body.email || '').trim() || null,
      contact_person_name: String(req.body.contact_person_name || '').trim() || null,
      contact_person_phone: String(req.body.contact_person_phone || '').trim() || null,
      contact_person_whatsapp: String(req.body.contact_person_whatsapp || '').trim() || null,
      gstin: String(req.body.gstin || '').trim() || null,
      pan: String(req.body.pan || '').trim() || null,
      notes: String(req.body.notes || '').trim() || null,
    },
  };
}

async function create(req, res) {
  const result = collectFormData(req);
  if (result.error) {
    flash.set(req, 'error', result.error);
    res.redirect('/logistics-partners/create');
    return;
  }

  const id = await logisticsPartnerRepository.create(result.data, req.user.id);
  await auditLogRepository.log(req.user.id, 'LOGISTICS_PARTNER_ADDED', 'logistics_partners', id, null, null, result.data.partner_name);
  flash.set(req, 'success', `"${result.data.partner_name}" added to the logistics partners directory.`);
  res.redirect('/logistics-partners');
}

async function editForm(req, res) {
  const id = parseInt(req.params.id, 10);
  const partner = await logisticsPartnerRepository.find(id);
  if (!partner) {
    res.status(404).send('Logistics partner not found.');
    return;
  }
  res.renderView('logistics_partners/edit', {
    partner,
    serviceTypes: logisticsPartnerRepository.SERVICE_TYPES,
  }, 'layout/base');
}

async function update(req, res) {
  const id = parseInt(req.params.id, 10);
  const partner = await logisticsPartnerRepository.find(id);
  if (!partner) {
    res.status(404).send('Logistics partner not found.');
    return;
  }

  const result = collectFormData(req);
  if (result.error) {
    flash.set(req, 'error', result.error);
    res.redirect(`/logistics-partners/${id}/edit`);
    return;
  }

  await logisticsPartnerRepository.update(id, result.data);
  await auditLogRepository.log(req.user.id, 'LOGISTICS_PARTNER_UPDATED', 'logistics_partners', id, 'partner_name', partner.partner_name, result.data.partner_name);
  flash.set(req, 'success', `"${result.data.partner_name}" updated.`);
  res.redirect('/logistics-partners');
}

async function toggleActive(req, res) {
  const id = parseInt(req.params.id, 10);
  const partner = await logisticsPartnerRepository.find(id);
  if (!partner) {
    flash.set(req, 'error', 'Logistics partner not found.');
    res.redirect('/logistics-partners');
    return;
  }

  await logisticsPartnerRepository.toggleActive(id);
  const nowActive = !partner.is_active;
  await auditLogRepository.log(
    req.user.id,
    nowActive ? 'LOGISTICS_PARTNER_REACTIVATED' : 'LOGISTICS_PARTNER_DEACTIVATED',
    'logistics_partners', id, 'is_active', String(Number(!!partner.is_active)), String(Number(nowActive))
  );
  flash.set(req, 'success', `"${partner.partner_name}" ${nowActive ? 'reactivated' : 'deactivated'}.`);
  res.redirect('/logistics-partners');
}

async function remove(req, res) {
  const id = parseInt(req.params.id, 10);
  const partner = await logisticsPartnerRepository.find(id);
  if (!partner) {
    flash.set(req, 'error', 'Logistics partner not found.');
    res.redirect('/logistics-partners');
    return;
  }

  await logisticsPartnerRepository.remove(id);
  await auditLogRepository.log(req.user.id, 'LOGISTICS_PARTNER_DELETED', 'logistics_partners', id, 'partner_name', partner.partner_name, null);
  flash.set(req, 'success', `"${partner.partner_name}" removed from the directory.`);
  res.redirect('/logistics-partners');
}

module.exports = { index, createForm, create, editForm, update, toggleActive, remove };
