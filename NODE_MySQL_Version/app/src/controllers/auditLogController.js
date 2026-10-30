'use strict';

const querystring = require('querystring');
const auditLogRepository = require('../repositories/auditLogRepository');
const orderRepository = require('../repositories/orderRepository');
const csv = require('../helpers/csv');

// Port of App\Controllers\AuditLogController. Spec Section 14/17 — Audit
// Log viewer. Read-only; no delete anywhere.

const PAGE_SIZE = 100;

/** http_build_query(array_merge($filters, ['page' => N])) equivalent, skipping null/empty values like PHP's array_filter would leave out unset keys. */
function pageQuery(filters, page) {
  const params = {};
  for (const [k, v] of Object.entries(filters)) {
    if (v !== null && v !== undefined && v !== '') params[k] = v;
  }
  params.page = page;
  return querystring.stringify(params);
}

async function index(req, res) {
  const entityType = String(req.query.entity_type || '').trim() || null;
  const entityId = req.query.entity_id !== undefined && req.query.entity_id !== '' ? (parseInt(req.query.entity_id, 10) || null) : null;
  const userId = req.query.user_id !== undefined && req.query.user_id !== '' ? (parseInt(req.query.user_id, 10) || null) : null;
  const actionType = String(req.query.action_type || '').trim() || null;
  const dateFrom = String(req.query.date_from || '').trim() || null;
  const dateTo = String(req.query.date_to || '').trim() || null;
  // QA Round 6, R6-N30: a page that is not a number ("abc") is page 1, not a LIMIT NaN error.
  const page = Math.max(1, parseInt(req.query.page, 10) || 1);

  const rows = await auditLogRepository.search({
    entityType,
    entityId,
    userId,
    actionType,
    dateFrom,
    dateTo,
    limit: PAGE_SIZE,
    offset: PAGE_SIZE * (page - 1),
  });

  const filters = { entity_type: entityType, entity_id: entityId, user_id: userId, action_type: actionType, date_from: dateFrom, date_to: dateTo };

  res.renderView(
    'audit_log/index',
    {
      rows,
      page,
      pageSize: PAGE_SIZE,
      actionTypes: await auditLogRepository.distinctActionTypes(),
      filters,
      prevPageQuery: page > 1 ? pageQuery(filters, page - 1) : null,
      nextPageQuery: rows.length === PAGE_SIZE ? pageQuery(filters, page + 1) : null,
    },
    'layout/base'
  );
}

/**
 * Everything logged against this order — its own row, plus every
 * document/dispute/amendment/email_log row that belongs to it. See
 * auditLogRepository.forOrder()'s docblock for why this needed its own
 * query rather than reusing search()'s single entity_type filter.
 */
async function forOrder(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }

  const rows = await auditLogRepository.forOrder(orderId);

  // External QA report M-03: "One-click export of an order's audit log
  // (privileged only)." Reuses this page's own existing view_audit_log
  // permission gate rather than adding a separate export route — same
  // ?format=csv-on-the-same-GET convention reportController already uses.
  if (req.query.format === 'csv') {
    csv.stream(
      res,
      `audit_log_order_${String(order.order_reference).replace(/[^A-Za-z0-9_-]+/g, '-')}.csv`,
      CSV_HEADERS,
      orderAuditLogCsvRows(rows)
    );
    return;
  }

  res.renderView('audit_log/order', { order, rows }, 'layout/base');
}

const CSV_HEADERS = ['When', 'User', 'Action', 'Entity', 'Field', 'Old', 'New', 'Reason'];

/** Pure row-mapping, exported so it can be unit-tested directly — same pattern as reportController's own orderAuditCsvRows(). */
function orderAuditLogCsvRows(rows) {
  return rows.map((r) => ({
    When: r.created_at,
    User: r.user_name || 'system',
    Action: r.action_type,
    Entity: (r.entity_type || '') + (r.entity_id ? ` #${r.entity_id}` : ''),
    Field: r.field_name || '',
    Old: r.old_value || '',
    New: r.new_value || '',
    Reason: r.reason || '',
  }));
}

module.exports = { index, forOrder, orderAuditLogCsvRows };
