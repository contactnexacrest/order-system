'use strict';

const flash = require('../helpers/flash');
const orderStorage = require('../helpers/orderStorage');
const auditLogRepository = require('../repositories/auditLogRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const disputeDocumentRepository = require('../repositories/disputeDocumentRepository');
const disputeReplyRepository = require('../repositories/disputeReplyRepository');
const disputeRepository = require('../repositories/disputeRepository');
const lookupRepository = require('../repositories/lookupRepository');
const orderRepository = require('../repositories/orderRepository');
const userRepository = require('../repositories/userRepository');
const fileUploadService = require('../services/fileUploadService');
const uiTemplateService = require('../services/uiTemplateService');
const workingDaysCalculator = require('../services/workingDaysCalculator');
const orderAccessService = require('../services/orderAccessService');

// Port of App\Controllers\DisputeController. Spec Section 16 — Dispute
// Management.


function todayYmd() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** Global dispute log, filterable by status. */
async function index(req, res) {
  if ((await uiTemplateService.resolve(req.user.ui_template_preference_id || null)) === 'kanban') {
    await indexKanban(req, res);
    return;
  }

  const status = String(req.query.status || '').trim() || null;
  res.renderView(
    'disputes/index',
    {
      disputes: await orderAccessService.visibleRows(await disputeRepository.all(status), req.user.id, req.permissions),
      statusFilter: status,
      statusOptions: await lookupRepository.dropdownOptions('dispute_status'),
    },
    'layout/base'
  );
}

/**
 * docs/schema.sql Section BQ — the Kanban UI template's Disputes board:
 * one column per dispute_status dropdown option, in the admin's own
 * configured order, plus a column for any legacy status value that no
 * longer appears in that list so nothing is dropped.
 */
async function indexKanban(req, res) {
  const all = await orderAccessService.visibleRows(await disputeRepository.all(), req.user.id, req.permissions);
  const statusOptions = await lookupRepository.dropdownOptions('dispute_status');
  const columns = {};
  for (const opt of statusOptions) {
    columns[opt.option_value] = { disputes: [] };
  }
  for (const d of all) {
    if (!columns[d.status]) {
      columns[d.status] = { disputes: [] };
    }
    columns[d.status].disputes.push(d);
  }

  res.renderView('disputes/index_kanban', { columns, totalCount: all.length }, 'layout/base');
}

async function forOrder(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }

  const disputes = await disputeRepository.forOrder(orderId);
  const replies = {};
  for (const d of disputes) {
    replies[d.id] = await disputeReplyRepository.forDispute(d.id);
  }

  const permissions = req.permissions || {};

  res.renderView(
    'disputes/order',
    {
      order,
      disputes,
      replies,
      canManage: !!permissions.manage_disputes,
      canRespond: !!permissions.respond_to_disputes,
      users: await userRepository.listActive(),
      statusOptions: await lookupRepository.dropdownOptions('dispute_status'),
    },
    'layout/base'
  );
}

async function postReply(req, res) {
  const disputeId = parseInt(req.params.disputeId, 10);
  const dispute = await disputeRepository.find(disputeId);
  if (!dispute) {
    res.status(404).send('Dispute not found.');
    return;
  }

  const body = String(req.body.body || '').trim();
  if (body === '') {
    flash.set(req, 'error', 'Write a reply before posting.');
    res.redirect(`/orders/${dispute.order_id}/disputes`);
    return;
  }

  await disputeReplyRepository.create(disputeId, req.user.id, body);
  await auditLogRepository.log(req.user.id, 'DISPUTE_REPLY_POSTED', 'disputes', disputeId);

  flash.set(req, 'success', 'Reply posted.');
  res.redirect(`/orders/${dispute.order_id}/disputes`);
}

async function create(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const noticeDate = String(req.body.notice_date || '').trim() || todayYmd();
  const fromParty = String(req.body.from_party || '').trim() || null;
  const description = String(req.body.description || '').trim();
  const assignedTo = req.body.assigned_to !== undefined && req.body.assigned_to !== '' ? parseInt(req.body.assigned_to, 10) : null;

  if (description === '') {
    flash.set(req, 'error', 'A description is mandatory to raise a dispute.');
    res.redirect(`/orders/${orderId}/disputes`);
    return;
  }

  // The seeded Dispute Resolution clause promises "ten (10) WORKING days"
  // on every QT/PI/OC/BUYERPO — calendar-day arithmetic here would
  // silently count Sundays/holidays as working days and print a due date
  // the clause doesn't actually support.
  const responseDays = parseInt((await companySettingsRepository.get('dispute_response_days_n')) || '10', 10);
  const responseDueDate = await workingDaysCalculator.addWorkingDays(noticeDate, responseDays);

  const disputeId = await disputeRepository.create(orderId, noticeDate, fromParty, description, assignedTo, responseDueDate);
  await auditLogRepository.log(req.user.id, 'DISPUTE_RAISED', 'disputes', disputeId, null, null, description);

  flash.set(req, 'success', `Dispute logged — response due by ${responseDueDate}.`);
  res.redirect(`/orders/${orderId}/disputes`);
}

async function updateStatus(req, res) {
  const disputeId = parseInt(req.params.disputeId, 10);
  const dispute = await disputeRepository.find(disputeId);
  if (!dispute) {
    res.status(404).send('Dispute not found.');
    return;
  }

  const status = String(req.body.status || '');
  const resolutionNotes = String(req.body.resolution_notes || '').trim();

  // QA-4 P0.5: this took req.body.status straight into the DB with no check
  // against the dispute_status dropdown at all (status is a bare VARCHAR(30),
  // not a DB-level enum) — any value, including one that doesn't exist in
  // the dropdown Admin manages, would silently save and then desync every
  // report/filter that matches on the exact option string.
  const validStatuses = (await lookupRepository.dropdownOptions('dispute_status')).map((row) => row.option_value);
  if (!validStatuses.includes(status)) {
    flash.set(req, 'error', 'Invalid dispute status.');
    res.redirect(`/orders/${dispute.order_id}/disputes`);
    return;
  }

  if (status === 'Resolved') {
    await disputeRepository.resolve(disputeId, resolutionNotes || 'Resolved.');
  } else {
    await disputeRepository.updateStatus(disputeId, status);
  }
  await auditLogRepository.log(req.user.id, 'DISPUTE_STATUS_CHANGED', 'disputes', disputeId, 'status', dispute.status, status, resolutionNotes || null);

  flash.set(req, 'success', 'Dispute status updated.');
  res.redirect(`/orders/${dispute.order_id}/disputes`);
}

async function uploadDocument(req, res) {
  const disputeId = parseInt(req.params.disputeId, 10);
  const dispute = await disputeRepository.find(disputeId);
  if (!dispute) {
    res.status(404).send('Dispute not found.');
    return;
  }
  const order = await orderRepository.find(dispute.order_id);

  try {
    const fileId = await fileUploadService.handleUpload(
      req,
      'document',
      'dispute_document',
      orderStorage.folder(order, 'disputes', String(disputeId)),
      null,
      dispute.order_id,
      req.user.id,
      null,
      String(req.body.received_from || '').trim() || null,
      'Dispute-related document'
    );
    const documentId = await disputeDocumentRepository.attach(disputeId, fileId);
    // External QA report N-08: this action never reached the audit
    // log, so it never notified anyone either.
    await auditLogRepository.log(req.user.id, 'DISPUTE_DOCUMENT_UPLOADED', 'dispute_documents', documentId, null, null, null);
    flash.set(req, 'success', 'Document attached to dispute.');
  } catch (e) {
    flash.setErrorFromException(req, e);
  }

  res.redirect(`/orders/${dispute.order_id}/disputes`);
}

module.exports = { index, forOrder, create, updateStatus, uploadDocument, postReply };
