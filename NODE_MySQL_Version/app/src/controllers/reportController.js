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
  const query = str(req.query.q);
  res.renderView(
    'reports/index',
    {
      clients: await clientRepository.all(),
      incoterms: await lookupRepository.incoterms(),
      stages: await lookupRepository.stagesMaster(),
      countries: await reportRepository.distinctCountries(),
      savedReports: await reportDefinitionRepository.visibleTo(user.id),
      searchQuery: query,
      searchResults: query !== '' ? await reportRepository.searchOrders(query) : [],
      canViewStaffReports: !!(req.permissions && req.permissions.view_staff_reports),
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
      summary: reportRepository.aggregateSummary(rows),
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

  if (req.query.format === 'csv') {
    const section = str(req.query.section) || 'queues';
    if (section === 'funnel') {
      const funnel = await reportRepository.funnelActivity(dateFrom, dateTo);
      const labels = {
        quotationsSent: 'Quotations sent (all)',
        quotationsLost: 'Quotations lost (marked lost before reaching PI)',
        quotationsWon: 'Quotations won (reached PI)',
        piSent: 'PI sent (all)',
        piLost: 'PI lost (marked lost after reaching PI, before CI)',
        amendments: 'Amendments',
      };
      const rows = Object.keys(labels).map((key) => ({ Metric: labels[key], Count: funnel[key] }));
      csv.stream(res, `funnel_activity_${dateFrom}_to_${dateTo}.csv`, ['Metric', 'Count'], rows);
      return;
    }

    const queueData = await reportRepository.operationsQueues();
    const bucketLabels = {
      quotationAwaitingSend: 'Quotation drafted, not yet sent to buyer',
      buyerPoAwaited: "Quotation sent - waiting on buyer's PO",
      orderAcceptanceAwaitingSend: 'Our Order-Acceptance (PO) not yet sent to buyer',
      piStage: 'Sitting at PI stage',
      ocAwaitingSend: 'Order Confirmation drafted, not yet sent',
      ocAwaitingAck: "Order Confirmation sent - awaiting buyer's acknowledgement",
      supplierPoNeeded: 'Reached Supplier PO stage - nothing drafted for our supplier yet',
      blAwaitingSend: 'CI issued - scanned BL not yet sent to buyer',
      balanceAwaited: 'Scanned BL sent - balance payment not yet received',
      hardCopyAwaited: 'Balance received - hard-copy document set not yet couriered',
    };
    const rows = [];
    for (const [key, label] of Object.entries(bucketLabels)) {
      for (const o of queueData[key]) {
        rows.push({ Queue: label, 'Order Ref': o.order_reference, Client: o.company_legal_name, Created: o.created_at });
      }
    }
    csv.stream(res, 'operations_queues.csv', ['Queue', 'Order Ref', 'Client', 'Created'], rows);
    return;
  }

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
      dateFilterQuery: querystring.stringify({ date_from: dateFrom, date_to: dateTo }),
    },
    'layout/base'
  );
}

/** Cross-order dispute report — closes the gap where disputes only ever showed up as generic audit-log rows. */
async function disputes(req, res) {
  const dateFrom = str(req.query.date_from) || null;
  const dateTo = str(req.query.date_to) || null;

  const data = await reportRepository.disputesReport(dateFrom, dateTo);

  if (req.query.format === 'csv') {
    const rows = data.rows.map((r) => ({
      'Order Ref': r.order_reference,
      Client: r.company_legal_name,
      'Notice Date': r.notice_date,
      'From Party': r.from_party ?? '',
      Status: r.status,
      'Response Due': r.response_due_date ?? '',
      'Resolved At': r.resolved_at ?? '',
      'Days Open': r.days_open,
    }));
    csv.stream(res, 'disputes_report.csv', ['Order Ref', 'Client', 'Notice Date', 'From Party', 'Status', 'Response Due', 'Resolved At', 'Days Open'], rows);
    return;
  }

  res.renderView(
    'reports/disputes',
    {
      rows: data.rows,
      byStatus: data.by_status,
      openCount: data.open_count,
      avgResolutionDays: data.avg_resolution_days,
      filters: { dateFrom, dateTo },
    },
    'layout/base'
  );
}

/** Cross-order amendment report — closes the gap where amendments only ever showed a bare count in the funnel section. */
async function amendments(req, res) {
  const dateFrom = str(req.query.date_from) || null;
  const dateTo = str(req.query.date_to) || null;

  const data = await reportRepository.amendmentsReport(dateFrom, dateTo);

  if (req.query.format === 'csv') {
    const rows = data.rows.map((r) => ({
      'Amendment Ref': r.amendment_reference,
      'Order Ref': r.order_reference,
      Client: r.company_legal_name,
      'Requested By': r.requested_by,
      Status: r.status,
      Reason: r.reason,
      Currency: r.currency_code,
      'Amended Advance': r.amended_advance_amount ?? '',
      'Amended Balance': r.amended_balance_amount ?? '',
      'Effective From': r.effective_from ?? '',
      Created: r.created_at,
    }));
    csv.stream(res, 'amendments_report.csv', ['Amendment Ref', 'Order Ref', 'Client', 'Requested By', 'Status', 'Reason', 'Currency', 'Amended Advance', 'Amended Balance', 'Effective From', 'Created'], rows);
    return;
  }

  res.renderView(
    'reports/amendments',
    {
      rows: data.rows,
      byStatus: data.by_status,
      byRequestedBy: data.by_requested_by,
      filters: { dateFrom, dateTo },
    },
    'layout/base'
  );
}

/** Month-over-month trend view — everything else in this module is either a snapshot or a single flat total. */
async function trends(req, res) {
  res.renderView('reports/trends', { months: await reportRepository.monthlyTrends(12) }, 'layout/base');
}

/** Staff productivity report — gated on view_staff_reports, not view_reports, since it shows individual activity. */
async function staff(req, res) {
  const dateFrom = str(req.query.date_from) || null;
  const dateTo = str(req.query.date_to) || null;
  res.renderView(
    'reports/staff',
    { rows: await reportRepository.staffProductivity(dateFrom, dateTo), filters: { dateFrom, dateTo } },
    'layout/base'
  );
}

/**
 * Consolidated financial/payments report (added to close a real gap:
 * neither the dashboard's two overdue lists nor the per-client report's
 * own totals ever showed collected-vs-outstanding across the whole
 * business). Filtered the same way as the Aggregate Report (order
 * created_at date range) for predictable, consistent semantics.
 */
async function payments(req, res) {
  const dateFrom = str(req.query.date_from) || null;
  const dateTo = str(req.query.date_to) || null;

  const data = await reportRepository.paymentsReport(dateFrom, dateTo);

  if (req.query.format === 'csv') {
    const rows = data.rows.map((r) => ({
      'Order Ref': r.order_reference,
      Client: r.company_legal_name,
      Currency: r.currency_code,
      Status: r.status,
      'Advance Invoiced': r.advance_amount ?? '',
      'Advance Cleared': r.advance_cleared_at ? 'Yes' : (r.advance_amount !== null ? 'No' : ''),
      'Advance Outstanding': r.advance_outstanding !== null ? r.advance_outstanding.toFixed(2) : '',
      'Balance Invoiced': r.balance_amount ?? '',
      'Balance Cleared': r.balance_cleared_at ? 'Yes' : (r.balance_amount !== null ? 'No' : ''),
      'Balance Outstanding': r.balance_outstanding !== null ? r.balance_outstanding.toFixed(2) : '',
      'Freight Invoiced': r.freight_amount ?? '',
      'Freight Cleared': r.freight_cleared_at ? 'Yes' : (r.freight_amount !== null ? 'No' : ''),
      'Freight Outstanding': r.freight_outstanding !== null ? r.freight_outstanding.toFixed(2) : '',
      Created: r.created_at,
    }));
    csv.stream(res, 'payments_report.csv', [
      'Order Ref', 'Client', 'Currency', 'Status',
      'Advance Invoiced', 'Advance Cleared', 'Advance Outstanding',
      'Balance Invoiced', 'Balance Cleared', 'Balance Outstanding',
      'Freight Invoiced', 'Freight Cleared', 'Freight Outstanding',
      'Created',
    ], rows);
    return;
  }

  res.renderView(
    'reports/payments',
    { rows: data.rows, byCurrency: data.by_currency, filters: { dateFrom, dateTo } },
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

module.exports = {
  index, client, order, aggregate, queues, saveDefinition, runDefinition, updateDefinition, deleteDefinition,
  payments, disputes, amendments, trends, staff,
};
