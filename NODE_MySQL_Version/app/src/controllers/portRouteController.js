'use strict';

const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const portRouteRepository = require('../repositories/portRouteRepository');
const lookupRepository = require('../repositories/lookupRepository');

/**
 * Port of App\Controllers\PortRouteController. 90-item backlog item 15:
 * admin screen for `port_routes` (the port-to-port sailing-days
 * reference document), gated on the same manage_ports permission as the
 * Ports screen itself. lookup() is the one action a plain order-creating
 * user also needs (gated on manage_orders instead, see server.js), so
 * the order form can auto-suggest a sailing lead time once both ports
 * are picked.
 */

async function index(req, res) {
  res.renderView('port_routes/index', {
    routes: await portRouteRepository.all(true),
  }, 'layout/base');
}

async function createForm(req, res) {
  res.renderView('port_routes/create', {
    loadingPorts: await lookupRepository.ports('loading'),
    dischargePorts: await lookupRepository.ports('discharge'),
  }, 'layout/base');
}

function collectFormData(req) {
  const loadingId = parseInt(req.body.port_of_loading_id, 10) || 0;
  const dischargeId = parseInt(req.body.port_of_discharge_id, 10) || 0;
  if (!loadingId || !dischargeId) {
    return { error: 'Select both a port of loading and a port of discharge.' };
  }

  const min = String(req.body.sailing_days_min || '') !== '' ? parseInt(req.body.sailing_days_min, 10) : null;
  const max = String(req.body.sailing_days_max || '') !== '' ? parseInt(req.body.sailing_days_max, 10) : null;
  if (min === null || max === null || Number.isNaN(min) || Number.isNaN(max) || min < 0 || max < min) {
    return { error: 'Enter a valid sailing-days range (min must be 0 or more, max must be at least min).' };
  }

  return {
    data: {
      port_of_loading_id: loadingId,
      port_of_discharge_id: dischargeId,
      sailing_days_min: min,
      sailing_days_max: max,
      notes: String(req.body.notes || '').trim() || null,
    },
  };
}

async function create(req, res) {
  const result = collectFormData(req);
  if (result.error) {
    flash.set(req, 'error', result.error);
    res.redirect('/port-routes/create');
    return;
  }

  let id;
  try {
    id = await portRouteRepository.create(result.data.port_of_loading_id, result.data.port_of_discharge_id, result.data.sailing_days_min, result.data.sailing_days_max, result.data.notes);
  } catch (err) {
    if (err && err.code === 'ER_DUP_ENTRY') {
      flash.set(req, 'error', 'A route between those two ports is already recorded — edit the existing one instead.');
      res.redirect('/port-routes/create');
      return;
    }
    throw err;
  }
  await auditLogRepository.log(req.user.id, 'PORT_ROUTE_ADDED', 'port_routes', id, null, null, `${result.data.sailing_days_min}-${result.data.sailing_days_max} days`);
  flash.set(req, 'success', 'Port route added.');
  res.redirect('/port-routes');
}

async function editForm(req, res) {
  const id = parseInt(req.params.id, 10);
  const route = await portRouteRepository.find(id);
  if (!route) {
    res.status(404).send('Port route not found.');
    return;
  }
  res.renderView('port_routes/edit', {
    route,
    loadingPorts: await lookupRepository.ports('loading'),
    dischargePorts: await lookupRepository.ports('discharge'),
  }, 'layout/base');
}

async function update(req, res) {
  const id = parseInt(req.params.id, 10);
  const route = await portRouteRepository.find(id);
  if (!route) {
    res.status(404).send('Port route not found.');
    return;
  }

  const result = collectFormData(req);
  if (result.error) {
    flash.set(req, 'error', result.error);
    res.redirect(`/port-routes/${id}/edit`);
    return;
  }

  try {
    await portRouteRepository.update(id, result.data.port_of_loading_id, result.data.port_of_discharge_id, result.data.sailing_days_min, result.data.sailing_days_max, result.data.notes);
  } catch (err) {
    if (err && err.code === 'ER_DUP_ENTRY') {
      flash.set(req, 'error', 'A route between those two ports is already recorded under a different entry.');
      res.redirect(`/port-routes/${id}/edit`);
      return;
    }
    throw err;
  }
  await auditLogRepository.log(req.user.id, 'PORT_ROUTE_UPDATED', 'port_routes', id, 'sailing_days', `${route.sailing_days_min}-${route.sailing_days_max}`, `${result.data.sailing_days_min}-${result.data.sailing_days_max}`);
  flash.set(req, 'success', 'Port route updated.');
  res.redirect('/port-routes');
}

async function toggleActive(req, res) {
  const id = parseInt(req.params.id, 10);
  const route = await portRouteRepository.find(id);
  if (!route) {
    flash.set(req, 'error', 'Port route not found.');
    res.redirect('/port-routes');
    return;
  }

  await portRouteRepository.toggleActive(id);
  const nowActive = !route.is_active;
  await auditLogRepository.log(
    req.user.id,
    nowActive ? 'PORT_ROUTE_REACTIVATED' : 'PORT_ROUTE_DEACTIVATED',
    'port_routes', id, 'is_active', String(Number(!!route.is_active)), String(Number(nowActive))
  );
  flash.set(req, 'success', `Port route ${nowActive ? 'reactivated' : 'deactivated'}.`);
  res.redirect('/port-routes');
}

/**
 * JSON lookup the order create/edit form's JS calls once both ports are
 * selected, to offer (never force — the fields stay freely editable) a
 * sailing lead-time suggestion.
 */
async function lookup(req, res) {
  const loadingId = parseInt(req.query.from, 10) || 0;
  const dischargeId = parseInt(req.query.to, 10) || 0;

  if (!loadingId || !dischargeId) {
    res.json({ found: false });
    return;
  }

  const route = await portRouteRepository.findByPorts(loadingId, dischargeId);
  if (!route) {
    res.json({ found: false });
    return;
  }
  res.json({ found: true, min: route.sailing_days_min, max: route.sailing_days_max });
}

module.exports = { index, createForm, create, editForm, update, toggleActive, lookup };
