'use strict';

const querystring = require('querystring');

const flash = require('../helpers/flash');
const csv = require('../helpers/csv');
const clientRepository = require('../repositories/clientRepository');
const lookupRepository = require('../repositories/lookupRepository');
const reportDefinitionRepository = require('../repositories/reportDefinitionRepository');
const reportRepository = require('../repositories/reportRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const orderSupplierPoRepository = require('../repositories/orderSupplierPoRepository');
const { todayIst } = require('../helpers/dbDateTime');

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
      canManageOrderFinancials: !!(req.permissions && req.permissions.manage_order_financials),
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

/**
 * Exports the page's "Full Audit Trail" section — the one of this
 * report's 3 sections (Stage Data, Documents, Full Audit Trail) that
 * can genuinely run long and is worth pulling into a spreadsheet;
 * Stage Data/Documents are both small, fixed-size tables already
 * visible at a glance on the page itself.
 */
function orderAuditCsvRows(audit) {
  const rows = audit.map((a) => ({
    When: a.created_at,
    User: a.user_name ?? 'System',
    Action: a.action_type,
    Field: a.field_name ?? '—',
    'Old -> New': (a.old_value !== null || a.new_value !== null) ? `${a.old_value ?? '—'} -> ${a.new_value ?? '—'}` : '—',
    Reason: a.reason ?? '—',
  }));

  return { headers: ['When', 'User', 'Action', 'Field', 'Old -> New', 'Reason'], rows };
}

async function order(req, res) {
  const orderId = parseInt(req.params.orderId, 10);
  const data = await reportRepository.perOrder(orderId);
  if (!data.order) {
    res.status(404).send('Order not found.');
    return;
  }

  if (req.query.format === 'csv') {
    const { headers, rows } = orderAuditCsvRows(data.audit);
    csv.stream(res, `order_${String(data.order.order_reference).replace(/[^A-Za-z0-9_-]+/g, '-')}_audit_trail.csv`, headers, rows);
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
      Type: r.dispute_type === 'complaint_damage' ? 'Complaint / Damage' : 'Dispute',
      Status: r.status,
      'Response Due': r.response_due_date ?? '',
      'Resolved At': r.resolved_at ?? '',
      'Days Open': r.days_open,
    }));
    csv.stream(res, 'disputes_report.csv', ['Order Ref', 'Client', 'Notice Date', 'From Party', 'Type', 'Status', 'Response Due', 'Resolved At', 'Days Open'], rows);
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

/**
 * 90-item backlog "CSV export for the 6 non-CA reports": Trends, Staff
 * Productivity, Supplier Performance, Conversion Rate, Sales
 * Performance, and the per-Order report were the 6 reports in the
 * non-CA Reports module with no CSV export at all, unlike every other
 * report here. Extracted as its own function (same for the other 5
 * below) purely to mirror the PHP side's testable-without-exit()
 * structure 1:1, even though csv.stream() here uses res.send(), not
 * process.exit(), so Node's own version has no such constraint.
 */
function trendsCsvRows(months) {
  const rows = months.map((m) => {
    const entries = Object.entries(m.fob_by_currency || {});
    const fobByCurrency = entries.length === 0
      ? '—'
      : entries.map(([cc, amt]) => `${cc} ${Number(amt).toFixed(2)}`).join('; ');
    return {
      Month: m.month,
      'Orders Created': m.orders_created,
      'Quotations Sent': m.quotations_sent,
      'PI Sent': m.pi_sent,
      Lost: m.lost,
      'Total FOB Value': fobByCurrency,
    };
  });

  return { headers: ['Month', 'Orders Created', 'Quotations Sent', 'PI Sent', 'Lost', 'Total FOB Value'], rows };
}

/** Month-over-month trend view — everything else in this module is either a snapshot or a single flat total. */
async function trends(req, res) {
  const months = await reportRepository.monthlyTrends(12);

  if (req.query.format === 'csv') {
    const { headers, rows } = trendsCsvRows(months);
    csv.stream(res, 'trends_report.csv', headers, rows);
    return;
  }

  res.renderView('reports/trends', { months }, 'layout/base');
}

function staffCsvRows(rows) {
  const csvRows = rows.map((r) => {
    const entries = Object.entries(r.by_type || {});
    const byType = entries.length === 0 ? '—' : entries.map(([type, n]) => `${type}: ${n}`).join('; ');
    return {
      User: r.user_name,
      'Documents Generated (by type)': byType,
      'Documents Total': r.documents_total,
      'Audit Log Actions': r.audit_actions,
    };
  });

  return { headers: ['User', 'Documents Generated (by type)', 'Documents Total', 'Audit Log Actions'], rows: csvRows };
}

/** Staff productivity report — gated on view_staff_reports, not view_reports, since it shows individual activity. */
async function staff(req, res) {
  const dateFrom = str(req.query.date_from) || null;
  const dateTo = str(req.query.date_to) || null;
  const rows = await reportRepository.staffProductivity(dateFrom, dateTo);

  if (req.query.format === 'csv') {
    const { headers, rows: csvRows } = staffCsvRows(rows);
    csv.stream(res, 'staff_productivity_report.csv', headers, csvRows);
    return;
  }

  res.renderView('reports/staff', { rows, filters: { dateFrom, dateTo } }, 'layout/base');
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

/**
 * 90-item backlog item #1 ("today's incoming/supplier payments") —
 * distinct from payments() above, which filters orders by CREATED date
 * and shows their current status regardless of when a leg actually
 * cleared. This instead answers "what cleared/was paid on this specific
 * day", both directions: buyer legs cleared (order_payment_status) and
 * supplier legs paid (order_supplier_po).
 */
async function todaysPayments(req, res) {
  const date = str(req.query.date) || todayIst();

  // External QA report Q-RPT-1: supplier payment amounts are internal
  // INR cost data, not something a broad view_reports audience
  // (Viewer/Auditor included) should see by default — only
  // computed/shown/exported for whoever holds manage_supplier_payments
  // (Accounts/Admin/MD/ED), same tier that can now also act on these
  // payments.
  const canManageSupplierPayments = !!req.permissions.manage_supplier_payments;

  const incoming = await orderPaymentStatusRepository.clearedOnDate(date);
  const supplier = canManageSupplierPayments ? await orderSupplierPoRepository.paidOnDate(date) : [];

  if (req.query.format === 'csv') {
    const rows = [];
    for (const r of incoming) {
      if (r.advance_cleared_at && String(r.advance_cleared_at).slice(0, 10) === date) {
        rows.push({ Direction: 'Incoming', Leg: 'Advance', 'Order Ref': r.order_reference, Party: r.company_legal_name, Currency: r.currency_code, Amount: r.advance_amount });
      }
      if (r.balance_cleared_at && String(r.balance_cleared_at).slice(0, 10) === date) {
        rows.push({ Direction: 'Incoming', Leg: 'Balance', 'Order Ref': r.order_reference, Party: r.company_legal_name, Currency: r.currency_code, Amount: r.balance_amount });
      }
      if (r.freight_cleared_at && String(r.freight_cleared_at).slice(0, 10) === date) {
        rows.push({ Direction: 'Incoming', Leg: 'Freight', 'Order Ref': r.order_reference, Party: r.company_legal_name, Currency: r.currency_code, Amount: r.freight_amount });
      }
    }
    for (const r of supplier) {
      if (r.advance_paid_at === date) {
        rows.push({ Direction: 'Outgoing', Leg: 'Advance', 'Order Ref': r.order_reference, Party: r.supplier_legal_name, Currency: 'INR', Amount: r.advance_amount_inr });
      }
      if (r.balance_paid_at === date) {
        rows.push({ Direction: 'Outgoing', Leg: 'Balance', 'Order Ref': r.order_reference, Party: r.supplier_legal_name, Currency: 'INR', Amount: r.balance_amount_inr });
      }
    }
    csv.stream(res, `payments_${date}.csv`, ['Direction', 'Leg', 'Order Ref', 'Party', 'Currency', 'Amount'], rows);
    return;
  }

  const incomingRows = [];
  for (const r of incoming) {
    if (r.advance_cleared_at && String(r.advance_cleared_at).slice(0, 10) === date) {
      incomingRows.push({ order_id: r.order_id, order_reference: r.order_reference, company_legal_name: r.company_legal_name, currency_code: r.currency_code, leg: 'Advance', amount: r.advance_amount, cleared_at: r.advance_cleared_at });
    }
    if (r.balance_cleared_at && String(r.balance_cleared_at).slice(0, 10) === date) {
      incomingRows.push({ order_id: r.order_id, order_reference: r.order_reference, company_legal_name: r.company_legal_name, currency_code: r.currency_code, leg: 'Balance', amount: r.balance_amount, cleared_at: r.balance_cleared_at });
    }
    if (r.freight_cleared_at && String(r.freight_cleared_at).slice(0, 10) === date) {
      incomingRows.push({ order_id: r.order_id, order_reference: r.order_reference, company_legal_name: r.company_legal_name, currency_code: r.currency_code, leg: 'Freight', amount: r.freight_amount, cleared_at: r.freight_cleared_at });
    }
  }

  const supplierRows = [];
  for (const r of supplier) {
    if (r.advance_paid_at === date) {
      supplierRows.push({ order_id: r.order_id, order_reference: r.order_reference, supplier_legal_name: r.supplier_legal_name, leg: 'Advance', amount: r.advance_amount_inr, paid_at: r.advance_paid_at });
    }
    if (r.balance_paid_at === date) {
      supplierRows.push({ order_id: r.order_id, order_reference: r.order_reference, supplier_legal_name: r.supplier_legal_name, leg: 'Balance', amount: r.balance_amount_inr, paid_at: r.balance_paid_at });
    }
  }

  res.renderView('reports/todays_payments', { date, incomingRows, supplierRows, canManageSupplierPayments }, 'layout/base');
}

function defaultDateFrom() {
  // 90-item backlog "timezone": date-pure arithmetic on todayIst(), not
  // local-getter mutation (setDate/getDate) formatted back via
  // toISOString() — that mix shifts the result by a day near midnight IST,
  // the same root bug todayIst() itself fixes.
  const d = new Date(`${todayIst()}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() - 7);
  return d.toISOString().slice(0, 10);
}

function defaultDateTo() {
  return todayIst();
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

/** Debtors/receivables ageing report — closes a real gap flagged in the CA/Reports review: outstanding amounts existed, "how overdue" did not. */
async function ageing(req, res) {
  const data = await reportRepository.ageingReport();

  if (req.query.format === 'csv') {
    const rows = data.rows.map((r) => ({
      'Order Ref': r.order_reference,
      Client: r.company_legal_name,
      Leg: r.leg,
      Currency: r.currency_code,
      Amount: r.amount,
      'Due Date': r.due_date,
      'Days Overdue': r.days_overdue,
      Bucket: r.bucket,
    }));
    csv.stream(res, 'debtors_ageing_report.csv', ['Order Ref', 'Client', 'Leg', 'Currency', 'Amount', 'Due Date', 'Days Overdue', 'Bucket'], rows);
    return;
  }

  res.renderView('reports/ageing', data, 'layout/base');
}

/** Freight cost report — closes a real gap: freight terms/costs were recorded per order but never rolled up. */
async function freightCost(req, res) {
  const dateFrom = str(req.query.date_from) || null;
  const dateTo = str(req.query.date_to) || null;

  const data = await reportRepository.freightCostReport(dateFrom, dateTo);

  if (req.query.format === 'csv') {
    const rows = data.rows.map((r) => ({
      'Order Ref': r.order_reference,
      Client: r.company_legal_name,
      Incoterm: r.incoterm_code,
      Currency: r.currency_code,
      Forwarder: r.freight_forwarder_name ?? '',
      'Confirmed Rate': r.confirmed_freight_rate ?? '',
      Insurance: r.insurance_amount ?? '',
      Invoiced: r.freight_amount ?? '',
      Cleared: r.payment_cleared_at ? 'Yes' : 'No',
      Created: r.created_at,
    }));
    csv.stream(res, 'freight_cost_report.csv', ['Order Ref', 'Client', 'Incoterm', 'Currency', 'Forwarder', 'Confirmed Rate', 'Insurance', 'Invoiced', 'Cleared', 'Created'], rows);
    return;
  }

  res.renderView('reports/freight_cost', { rows: data.rows, byForwarder: data.by_forwarder, filters: { dateFrom, dateTo } }, 'layout/base');
}

/** Product/HS-code sales report — closes a real gap: no view anywhere of which products actually drive business. */
async function products(req, res) {
  const dateFrom = str(req.query.date_from) || null;
  const dateTo = str(req.query.date_to) || null;

  const data = await reportRepository.productSalesReport(dateFrom, dateTo);

  if (req.query.format === 'csv') {
    const rows = data.rows.map((r) => ({
      'HS Code': r.hs_code,
      Description: r.description,
      Currency: r.currency_code,
      'Order Count': r.order_count,
      'Total Quantity': r.total_quantity,
      'Total FOB Value': r.total_fob_value,
    }));
    csv.stream(res, 'product_sales_report.csv', ['HS Code', 'Description', 'Currency', 'Order Count', 'Total Quantity', 'Total FOB Value'], rows);
    return;
  }

  res.renderView('reports/products', { byHsCode: data.by_hs_code, filters: { dateFrom, dateTo } }, 'layout/base');
}

function suppliersCsvRows(rows) {
  const csvRows = rows.map((s) => ({
    Supplier: s.supplier_name,
    POs: s.po_count,
    Signed: s.signed_count,
    'Avg Signing Days': s.avg_signing_days ?? '—',
    'Delivery Tracked': s.delivery_tracked_count,
    'On-Time %': s.on_time_pct !== null ? `${s.on_time_pct}%` : '—',
  }));

  return { headers: ['Supplier', 'POs', 'Signed', 'Avg Signing Days', 'Delivery Tracked', 'On-Time %'], rows: csvRows };
}

/** Supplier performance report — closes a real gap: supplier POs were tracked per order but never rolled up. */
async function suppliers(req, res) {
  const rows = await reportRepository.supplierPerformanceReport();

  if (req.query.format === 'csv') {
    const { headers, rows: csvRows } = suppliersCsvRows(rows);
    csv.stream(res, 'supplier_performance_report.csv', headers, csvRows);
    return;
  }

  res.renderView('reports/suppliers', { rows }, 'layout/base');
}

/**
 * Order Profitability Sheet, rolled up across orders — gated on
 * manage_order_financials (not the general view_reports), same reasoning
 * as the order-page panel this mirrors: it surfaces per-order margin data.
 */
async function profitability(req, res) {
  const dateFrom = str(req.query.date_from) || null;
  const dateTo = str(req.query.date_to) || null;

  const data = await reportRepository.orderProfitabilityReport(dateFrom, dateTo);

  if (req.query.format === 'csv') {
    const rows = data.rows.map((r) => ({
      'Order Ref': r.order_reference,
      Client: r.company_legal_name,
      Created: r.created_at,
      'Revenue (INR)': r.revenue_inr,
      Estimated: r.revenue_is_estimated ? 'Yes' : 'No',
      'Total Cost (INR)': r.total_cost_inr,
      'Profit (INR)': r.profit_inr,
      'Margin %': r.margin_pct,
    }));
    csv.stream(res, 'order_profitability_report.csv', ['Order Ref', 'Client', 'Created', 'Revenue (INR)', 'Estimated', 'Total Cost (INR)', 'Profit (INR)', 'Margin %'], rows);
    return;
  }

  res.renderView('reports/profitability', { rows: data.rows, totals: data.totals, filters: { dateFrom, dateTo } }, 'layout/base');
}

/**
 * A Metric/Value pair table, same shape as queues()'s own
 * funnel_activity CSV, since this report (like that funnel section) is
 * one row per metric rather than one row per record.
 */
function conversionCsvRows(conversion) {
  const pct = (v) => (v !== null ? `${v}%` : '—');
  const rows = [
    { Metric: 'Quotations sent', Value: conversion.quotations_sent },
    { Metric: 'Quotation -> PI conversion', Value: pct(conversion.quotation_to_pi_pct) },
    { Metric: 'Quotation lost rate', Value: pct(conversion.quotation_lost_pct) },
    { Metric: 'PI sent', Value: conversion.pi_sent },
    { Metric: 'PI -> Confirmed Order conversion', Value: pct(conversion.pi_to_confirmed_pct) },
    { Metric: 'PI lost rate', Value: pct(conversion.pi_lost_pct) },
    { Metric: 'Overall Quotation -> Confirmed Order', Value: pct(conversion.overall_quotation_to_confirmed_pct) },
  ];

  return { headers: ['Metric', 'Value'], rows };
}

/** Conversion-rate report — closes a real gap: the funnel showed raw counts, never the actual conversion percentage. */
async function conversion(req, res) {
  const dateFrom = str(req.query.date_from) || null;
  const dateTo = str(req.query.date_to) || null;
  const conversionData = await reportRepository.conversionRateReport(dateFrom, dateTo);

  if (req.query.format === 'csv') {
    const { headers, rows } = conversionCsvRows(conversionData);
    csv.stream(res, 'conversion_report.csv', headers, rows);
    return;
  }

  res.renderView('reports/conversion', { conversion: conversionData, filters: { dateFrom, dateTo } }, 'layout/base');
}

/** @returns {[string,string]|null} [dateFrom, dateTo] for a named preset, calendar-based (not fiscal year) */
function presetRange(preset) {
  const today = new Date();
  const y = today.getFullYear();
  const m = today.getMonth(); // 0-based
  const fmt = (d) => d.toISOString().slice(0, 10);
  const lastDayOfMonth = (year, month) => new Date(Date.UTC(year, month + 1, 0));
  const firstDayOfMonth = (year, month) => new Date(Date.UTC(year, month, 1));

  switch (preset) {
    case 'this_month':
      return [fmt(firstDayOfMonth(y, m)), fmt(lastDayOfMonth(y, m))];
    case 'last_month':
      return [fmt(firstDayOfMonth(y, m - 1)), fmt(lastDayOfMonth(y, m - 1))];
    case 'this_quarter':
    case 'last_quarter': {
      let quarterStartMonth = Math.floor(m / 3) * 3;
      if (preset === 'last_quarter') quarterStartMonth -= 3;
      return [fmt(firstDayOfMonth(y, quarterStartMonth)), fmt(lastDayOfMonth(y, quarterStartMonth + 2))];
    }
    case 'this_half_year':
    case 'last_half_year': {
      let halfStartMonth = m <= 5 ? 0 : 6;
      if (preset === 'last_half_year') halfStartMonth -= 6;
      return [fmt(firstDayOfMonth(y, halfStartMonth)), fmt(lastDayOfMonth(y, halfStartMonth + 5))];
    }
    case 'this_year':
      return [`${y}-01-01`, `${y}-12-31`];
    case 'last_year':
      return [`${y - 1}-01-01`, `${y - 1}-12-31`];
    default:
      return null;
  }
}

/**
 * Sales Performance report — quotations/PIs sent, win/loss counts and
 * rate, FOB value won, and every lost order's own reason, for any
 * period, with an automatic comparison against the immediately
 * preceding period of equal length. A `preset` query param fills in
 * date_from/date_to for the common cases without a separate UI per
 * period — the underlying report is always just a date range.
 */
/**
 * Exports the page's "Lost Orders — Detail" table — the one genuinely
 * per-record section on this report; Key Metrics/FOB Value Won are both
 * single-row-per-period summaries already visible at a glance.
 */
function performanceCsvRows(report) {
  const rows = report.lost_orders.map((lo) => ({
    Order: lo.order_reference,
    Client: lo.company_legal_name,
    'Lost On': String(lo.lost_at),
    'Stage Reached': lo.reached_pi ? 'PI' : 'Quotation',
    Reason: lo.lost_reason ?? '—',
  }));

  return { headers: ['Order', 'Client', 'Lost On', 'Stage Reached', 'Reason'], rows };
}

async function performance(req, res) {
  const preset = str(req.query.preset);
  const preRange = preset !== '' ? presetRange(preset) : null;

  const dateFrom = preRange ? preRange[0] : (str(req.query.date_from) || null);
  const dateTo = preRange ? preRange[1] : (str(req.query.date_to) || null);

  const report = await reportRepository.salesPerformanceReport(dateFrom, dateTo);

  if (req.query.format === 'csv') {
    const { headers, rows } = performanceCsvRows(report);
    csv.stream(res, 'sales_performance_report.csv', headers, rows);
    return;
  }

  res.renderView('reports/performance', { report, filters: { dateFrom, dateTo, preset } }, 'layout/base');
}

module.exports = {
  index, client, order, aggregate, queues, saveDefinition, runDefinition, updateDefinition, deleteDefinition,
  payments, todaysPayments, disputes, amendments, trends, staff, ageing, freightCost, products, suppliers, conversion,
  profitability, performance, presetRange,
  trendsCsvRows, staffCsvRows, suppliersCsvRows, conversionCsvRows, performanceCsvRows, orderAuditCsvRows,
};
