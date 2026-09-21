'use strict';

const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const disputeDocumentRepository = require('../repositories/disputeDocumentRepository');
const disputeRepository = require('../repositories/disputeRepository');
const lookupRepository = require('../repositories/lookupRepository');
const orderRepository = require('../repositories/orderRepository');
const userRepository = require('../repositories/userRepository');
const fileUploadService = require('../services/fileUploadService');
const workingDaysCalculator = require('../services/workingDaysCalculator');

// Port of App\Controllers\DisputeController. Spec Section 16 — Dispute
// Management.

function sanitizePathSegment(value) {
  return String(value == null ? '' : value).replace(/[^A-Za-z0-9_-]+/g, '-');
}

function todayYmd() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** Global dispute log, filterable by status. */
async function index(req, res) {
  const status = String(req.query.status || '').trim() || null;
  res.renderView(
    'disputes/index',
    {
      disputes: await disputeRepository.all(status),
      statusFilter: status,
      statusOptions: await lookupRepository.dropdownOptions('dispute_status'),
    },
    'layout/base'
  );
}

async function forOrder(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }

  res.renderView(
    'disputes/order',
    {
      order,
      disputes: await disputeRepository.forOrder(orderId),
      users: await userRepository.listActive(),
      statusOptions: await lookupRepository.dropdownOptions('dispute_status'),
    },
    'layout/base'
  );
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
      `clients/${sanitizePathSegment(order.client_unique_number)}/${sanitizePathSegment(order.order_reference)}/disputes/${disputeId}`,
      null,
      dispute.order_id,
      req.user.id,
      null,
      String(req.body.received_from || '').trim() || null,
      'Dispute-related document'
    );
    await disputeDocumentRepository.attach(disputeId, fileId);
    flash.set(req, 'success', 'Document attached to dispute.');
  } catch (e) {
    flash.set(req, 'error', e.message);
  }

  res.redirect(`/orders/${dispute.order_id}/disputes`);
}

module.exports = { index, forOrder, create, updateStatus, uploadDocument };
