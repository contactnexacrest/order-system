'use strict';

const querystring = require('querystring');
const auditLogRepository = require('../repositories/auditLogRepository');

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
  const entityId = req.query.entity_id !== undefined && req.query.entity_id !== '' ? parseInt(req.query.entity_id, 10) : null;
  const userId = req.query.user_id !== undefined && req.query.user_id !== '' ? parseInt(req.query.user_id, 10) : null;
  const actionType = String(req.query.action_type || '').trim() || null;
  const dateFrom = String(req.query.date_from || '').trim() || null;
  const dateTo = String(req.query.date_to || '').trim() || null;
  const page = Math.max(1, parseInt(req.query.page || 1, 10));

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

module.exports = { index };
