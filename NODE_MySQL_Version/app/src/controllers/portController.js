'use strict';

const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const portRepository = require('../repositories/portRepository');

/**
 * Port of App\Controllers\PortController (90-item backlog #59). Admin
 * screen for `ports`, gated on manage_ports (same tier as
 * manage_hs_codes/manage_logistics_partners). See portRepository's
 * docblock for why there's no delete action.
 */
const ROLES = { loading: 'Loading only', discharge: 'Discharge only', both: 'Loading & Discharge' };

async function index(req, res) {
  res.renderView('ports/index', {
    ports: await portRepository.all(true),
    roles: ROLES,
  }, 'layout/base');
}

async function createForm(req, res) {
  res.renderView('ports/create', { roles: ROLES }, 'layout/base');
}

function collectFormData(req) {
  const name = String(req.body.name || '').trim();
  const portRole = String(req.body.port_role || '');

  if (name === '') {
    return { error: 'Port name is required.' };
  }
  if (!Object.prototype.hasOwnProperty.call(ROLES, portRole)) {
    return { error: 'Select a valid role (Loading, Discharge, or both).' };
  }

  return {
    data: {
      name,
      city: String(req.body.city || '').trim() || null,
      country: String(req.body.country || '').trim() || null,
      port_role: portRole,
    },
  };
}

async function create(req, res) {
  const result = collectFormData(req);
  if (result.error) {
    flash.set(req, 'error', result.error);
    res.redirect('/ports/create');
    return;
  }

  const existing = await portRepository.all(true);
  const nextSortOrder = existing.length > 0 ? Math.max(...existing.map((p) => p.sort_order)) + 1 : 1;
  const id = await portRepository.create(result.data.name, result.data.city, result.data.country, result.data.port_role, nextSortOrder);
  await auditLogRepository.log(req.user.id, 'PORT_ADDED', 'ports', id, null, null, `${result.data.name} (${result.data.port_role})`);
  flash.set(req, 'success', `"${result.data.name}" added to the ports list.`);
  res.redirect('/ports');
}

async function editForm(req, res) {
  const id = parseInt(req.params.id, 10);
  const port = await portRepository.find(id);
  if (!port) {
    res.status(404).send('Port not found.');
    return;
  }
  res.renderView('ports/edit', { port, roles: ROLES }, 'layout/base');
}

async function update(req, res) {
  const id = parseInt(req.params.id, 10);
  const port = await portRepository.find(id);
  if (!port) {
    res.status(404).send('Port not found.');
    return;
  }

  const result = collectFormData(req);
  if (result.error) {
    flash.set(req, 'error', result.error);
    res.redirect(`/ports/${id}/edit`);
    return;
  }

  await portRepository.update(id, result.data.name, result.data.city, result.data.country, result.data.port_role, port.sort_order);
  await auditLogRepository.log(req.user.id, 'PORT_UPDATED', 'ports', id, 'name', port.name, result.data.name);
  flash.set(req, 'success', `"${result.data.name}" updated.`);
  res.redirect('/ports');
}

async function toggleActive(req, res) {
  const id = parseInt(req.params.id, 10);
  const port = await portRepository.find(id);
  if (!port) {
    flash.set(req, 'error', 'Port not found.');
    res.redirect('/ports');
    return;
  }

  await portRepository.toggleActive(id);
  const nowActive = !port.is_active;
  await auditLogRepository.log(
    req.user.id,
    nowActive ? 'PORT_REACTIVATED' : 'PORT_DEACTIVATED',
    'ports', id, 'is_active', String(Number(!!port.is_active)), String(Number(nowActive))
  );
  flash.set(req, 'success', `"${port.name}" ${nowActive ? 'reactivated' : 'deactivated'}.`);
  res.redirect('/ports');
}

async function setDefault(req, res) {
  const id = parseInt(req.params.id, 10);
  const port = await portRepository.find(id);
  if (!port) {
    flash.set(req, 'error', 'Port not found.');
    res.redirect('/ports');
    return;
  }

  await portRepository.setDefault(id);
  await auditLogRepository.log(req.user.id, 'PORT_DEFAULT_CHANGED', 'ports', id, 'is_default', '0', '1');
  flash.set(req, 'success', `"${port.name}" set as the default for its role.`);
  res.redirect('/ports');
}

module.exports = { index, createForm, create, editForm, update, toggleActive, setDefault };
