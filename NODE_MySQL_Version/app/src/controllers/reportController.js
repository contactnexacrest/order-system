'use strict';

const querystring = require('querystring');

const flash = require('../helpers/flash');
const csv = require('../helpers/csv');
const clientRepository = require('../repositories/clientRepository');
const lookupRepository = require('../repositories/lookupRepository');
const reportDefinitionRepository = require('../repositories/reportDefinitionRepository');
const reportRepository = require('../repositories/reportRepository');

// Port of App\Controllers\ReportController. Spec Section 16 — REPORTS:
// per-client, per-order, aggregate, CSV export, saved report definitions.

function str(v, fallback = '') {
  return String(v ?? fallback).trim();
}

async function index(req, res) {
  const user = req.user;
  res.renderView(
    'reports/index',
    {
      clients: await clientRepository.all(),
      incoterms: await lookupRepository.incoterms(),
      stages: await lookupRepository.stagesMaster(),
      countries: await reportRepository.distinctCountries(),
      savedReports: await reportDefinitionRepository.visibleTo(user.id),
    },
    'layout/base'
  );
}

async function client(req, res) {
  const clientId = parseInt(req.params.clientId, 10);
  const data = await reportRepository.perClient(clientId);
  if (!data.client) {
    res.status(404).send('Client not found.');
    return;
  }

  if (req.query.format === 'csv') {
    const rows = data.orders.map((o) => ({
      'Order Ref': o.order_reference,
      Status: o.status,
      Stage: o.current_stage_name ?? 'Quotation',
      Incoterm: o.incoterm_code,
      Currency: o.currency_code,
      Created: o.created_at,
    }));
    csv.stream(
      res,
      `client_${String(data.client.client_unique_number).replace(/[^A-Za-z0-9_-]+/g, '-')}_orders.csv`,
      ['Order Ref', 'Status', 'Stage', 'Incoterm', 'Currency', 'Created'],
      rows
    );
    return;
  }

  const canViewFullEmail = !!(req.permissions && req.permissions.view_client_email_full);

  res.renderView('reports/client', Object.assign({}, data, { canViewFullEmail }), 'layout/base');
}

async function order(req, res) {
  const orderId = parseInt(req.params.orderId, 10);
  const data = await reportRepository.perOrder(orderId);
  if (!data.order) {
    res.status(404).send('Order not found.');
    return;
  }
  res.renderView('reports/order', data, 'layout/base');
}

async function aggregate(req, res) {
  const dateFrom = str(req.query.date_from) || null;
  const dateTo = str(req.query.date_to) || null;
  const stageId = req.query.stage_id ? parseInt(req.query.stage_id, 10) : null;
  const incotermId = req.query.incoterm_id ? parseInt(req.query.incoterm_id, 10) : null;
  const country = str(req.query.country) || null;

  const rows = await reportRepository.aggregate(dateFrom, dateTo, stageId, incotermId, country);

  if (req.query.format === 'csv') {
    const csvRows = rows.map((r) => ({
      'Order Ref': r.order_reference,
      Client: r.company_legal_name,
      Country: r.country_of_destination,
      Incoterm: r.incoterm_code,
      Currency: r.currency_code,
      Stage: r.current_stage_name ?? 'Quotation',
      Status: r.status,
      'Total FOB Value': r.total_fob_value,
      Created: r.created_at,
    }));
    csv.stream(
      res,
      'aggregate_report.csv',
      ['Order Ref', 'Client', 'Country', 'Incoterm', 'Currency', 'Stage', 'Status', 'Total FOB Value', 'Created'],
      csvRows
    );
    return;
  }

  const canManageDefinitions = !!(req.permissions && req.permissions.manage_report_definitions);

  // http_build_query(array_filter([...])) equivalent — skips null/empty
  // values, used to build the "Export CSV" link's query string without
  // re-deriving it in the template (Nunjucks has no http_build_query).
  const filterParams = {};
  if (dateFrom) filterParams.date_from = dateFrom;
  if (dateTo) filterParams.date_to = dateTo;
  if (stageId) filterParams.stage_id = stageId;
  if (incotermId) filterParams.incoterm_id = incotermId;
  if (country) filterParams.country = country;
  const filterQuery = querystring.stringify(filterParams);

  res.renderView(
    'reports/aggregate',
    {
      rows,
      incoterms: await lookupRepository.incoterms(),
      stages: await lookupRepository.stagesMaster(),
      countries: await reportRepository.distinctCountries(),
      filters: { dateFrom, dateTo, stageId, incotermId, country },
      filterQuery,
      canManageDefinitions,
    },
    'layout/base'
  );
}

/**
 * Added 2026-09-19 — operations queue / funnel dashboard (spec items #1-16
 * from the "quotation/PI/CI/BL/PO queue" ask). Snapshot buckets have no date
 * filter (they're "what's sitting in queue right now"); the funnel activity
 * section is date-ranged, defaulting to the last 7 days so the page is
 * useful with zero clicks.
 */
async function queues(req, res) {
  const dateFrom = str(req.query.date_from) || defaultDateFrom();
  const dateTo = str(req.query.date_to) || defaultDateTo();

  const [queueData, funnel] = await Promise.all([
    reportRepository.operationsQueues(),
    reportRepository.funnelActivity(dateFrom, dateTo),
  ]);

  res.renderView(
    'reports/queues',
    {
      queues: queueData,
      funnel,
      filters: { dateFrom, dateTo },
    },
    'layout/base'
  );
}

function defaultDateFrom() {
  const d = new Date();
  d.setDate(d.getDate() - 7);
  return d.toISOString().slice(0, 10);
}

function defaultDateTo() {
  return new Date().toISOString().slice(0, 10);
}

async function saveDefinition(req, res) {
  const user = req.user;
  const name = str(req.body.name);
  const reportType = String(req.body.report_type || 'aggregate');
  const visibility = req.body.visibility === 'shared' ? 'shared' : 'private';

  if (name === '') {
    flash.set(req, 'error', 'A name is required to save a report.');
    res.redirect('/reports');
    return;
  }

  const filters = {
    date_from: str(req.body.date_from) || null,
    date_to: str(req.body.date_to) || null,
    stage_id: req.body.stage_id ? parseInt(req.body.stage_id, 10) : null,
    incoterm_id: req.body.incoterm_id ? parseInt(req.body.incoterm_id, 10) : null,
    country: str(req.body.country) || null,
  };

  await reportDefinitionRepository.create(name, reportType, user.id, visibility, filters, []);
  flash.set(req, 'success', `Report "${name}" saved.`);
  res.redirect('/reports');
}

async function runDefinition(req, res) {
  const id = parseInt(req.params.reportId, 10);
  const def = await reportDefinitionRepository.find(id);
  if (!def) {
    res.status(404).send('Saved report not found.');
    return;
  }
  await reportDefinitionRepository.markRun(id);
  const f = def.filters_json || {};
  const params = {};
  if (f.date_from) params.date_from = f.date_from;
  if (f.date_to) params.date_to = f.date_to;
  if (f.stage_id) params.stage_id = f.stage_id;
  if (f.incoterm_id) params.incoterm_id = f.incoterm_id;
  if (f.country) params.country = f.country;
  const qs = querystring.stringify(params);
  res.redirect('/reports/aggregate' + (qs !== '' ? `?${qs}` : ''));
}

async function updateDefinition(req, res) {
  const id = parseInt(req.params.reportId, 10);
  const user = req.user;
  const def = await reportDefinitionRepository.find(id);
  if (!def) {
    flash.set(req, 'error', 'Saved report not found.');
    res.redirect('/reports');
    return;
  }
  if (parseInt(def.owner_user_id, 10) !== parseInt(user.id, 10)) {
    flash.set(req, 'error', 'You can only edit your own saved reports.');
    res.redirect('/reports');
    return;
  }

  const name = String(req.body.name || '').trim();
  if (name === '') {
    flash.set(req, 'error', 'A name is required.');
    res.redirect('/reports');
    return;
  }
  const visibility = String(req.body.visibility || '') === 'shared' ? 'shared' : 'private';

  await reportDefinitionRepository.updateNameVisibility(id, name, visibility);
  flash.set(req, 'success', `"${name}" updated.`);
  res.redirect('/reports');
}

async function deleteDefinition(req, res) {
  const id = parseInt(req.params.reportId, 10);
  const user = req.user;
  const def = await reportDefinitionRepository.find(id);
  if (def && parseInt(def.owner_user_id, 10) === parseInt(user.id, 10)) {
    await reportDefinitionRepository.delete(id);
    flash.set(req, 'success', 'Saved report deleted.');
  } else {
    flash.set(req, 'error', 'You can only delete your own saved reports.');
  }
  res.redirect('/reports');
}

module.exports = { index, client, order, aggregate, queues, saveDefinition, runDefinition, updateDefinition, deleteDefinition };
