'use strict';

const flash = require('../helpers/flash');
const orderComplaintRepository = require('../repositories/orderComplaintRepository');
const orderComplaintReplyRepository = require('../repositories/orderComplaintReplyRepository');
const orderRepository = require('../repositories/orderRepository');
const orderComplaintService = require('../services/orderComplaintService');
const uiTemplateService = require('../services/uiTemplateService');

// Port of App\Controllers\ComplaintController. docs/schema.sql Section
// BX — Complaint / Process Issue management (distinct from Disputes).

const STATUSES = ['open', 'acknowledged', 'resolved'];

/** Global complaint log, filterable by status. */
async function index(req, res) {
  if ((await uiTemplateService.resolve(req.user.ui_template_preference_id || null)) === 'kanban') {
    await indexKanban(req, res);
    return;
  }

  const status = String(req.query.status || '').trim() || null;
  res.renderView(
    'complaints/index',
    {
      complaints: await orderComplaintRepository.all(status),
      statusFilter: status,
    },
    'layout/base'
  );
}

/** The Kanban UI template's Complaints board: Open / Acknowledged / Resolved columns. */
async function indexKanban(req, res) {
  const columns = {};
  for (const status of STATUSES) {
    columns[status] = await orderComplaintRepository.all(status);
  }

  res.renderView('complaints/index_kanban', { columns }, 'layout/base');
}

async function forOrder(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }

  const complaints = await orderComplaintRepository.forOrder(orderId);
  const replies = {};
  for (const c of complaints) {
    replies[c.id] = await orderComplaintReplyRepository.forComplaint(c.id);
  }

  const permissions = req.permissions || {};

  res.renderView(
    'complaints/order',
    {
      order,
      complaints,
      replies,
      canManage: !!permissions.manage_complaints,
      canRespond: !!permissions.respond_to_complaints,
    },
    'layout/base'
  );
}

async function create(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }

  const description = String(req.body.description || '').trim();
  if (description === '') {
    flash.set(req, 'error', 'A description is mandatory to log a complaint.');
    res.redirect(`/orders/${orderId}/complaints`);
    return;
  }

  await orderComplaintService.raiseAsStaff(orderId, order.client_id, req.user.id, description);

  flash.set(req, 'success', 'Complaint logged.');
  res.redirect(`/orders/${orderId}/complaints`);
}

async function postReply(req, res) {
  const complaintId = parseInt(req.params.complaintId, 10);
  const complaint = await orderComplaintRepository.find(complaintId);
  if (!complaint) {
    res.status(404).send('Complaint not found.');
    return;
  }

  const body = String(req.body.body || '').trim();
  if (body === '') {
    flash.set(req, 'error', 'Write a reply before posting.');
    res.redirect(`/orders/${complaint.order_id}/complaints`);
    return;
  }

  await orderComplaintService.replyAsStaff(complaintId, req.user.id, body);

  flash.set(req, 'success', 'Reply posted — the client has been emailed.');
  res.redirect(`/orders/${complaint.order_id}/complaints`);
}

async function updateStatus(req, res) {
  const complaintId = parseInt(req.params.complaintId, 10);
  const complaint = await orderComplaintRepository.find(complaintId);
  if (!complaint) {
    res.status(404).send('Complaint not found.');
    return;
  }

  const status = String(req.body.status || '');
  if (!STATUSES.includes(status)) {
    flash.set(req, 'error', 'Invalid complaint status.');
    res.redirect(`/orders/${complaint.order_id}/complaints`);
    return;
  }

  await orderComplaintService.updateStatus(complaintId, status, req.user.id);

  flash.set(req, 'success', 'Complaint status updated.');
  res.redirect(`/orders/${complaint.order_id}/complaints`);
}

module.exports = { index, forOrder, create, postReply, updateStatus };
