'use strict';

const crypto = require('crypto');
const db = require('../config/db');
const auditLogRepository = require('../repositories/auditLogRepository');
const notificationRepository = require('../repositories/notificationRepository');
const orderBuyerDetailRequestRepository = require('../repositories/orderBuyerDetailRequestRepository');
const permissionRepository = require('../repositories/permissionRepository');
const userRepository = require('../repositories/userRepository');
const selfApprovalRules = require('./selfApprovalRules');

/**
 * Owner decision D-337 (12 Nov 2026, "will go with recommendation"): an order
 * keeps its own copy of the buyer's details (rule R25), but that copy now
 * follows the client where it safely can.
 *
 * Case 1 — automatic. When staff save a client (client edit form, or a PI-stage
 * confirmation accepted), every RUNNING order of that client (not cancelled,
 * lost or complete) with NOTHING ISSUED takes the client's current details at
 * once (orderRepository.applyClientSnapshot), only when a field really differs.
 * Audited per order and field (ORDER_BUYER_DETAILS_REFRESHED); the holders of
 * approve_buyer_details_change get an FYI notification; no approval needed.
 *
 * "Issued" (hasIssuedDocument): any document of the order, of any type, that is
 * approved or sent, or superseded after having been approved or sent. A draft
 * or a document still in review is not issued; a deleted draft no longer
 * exists; a document superseded straight from draft / review (audit
 * DOCUMENT_SUPERSEDED with old value draft or in_review) does not count.
 * A superseded document without that audit row counts as issued (safe side).
 *
 * Case 2 — with approval. An order with an issued document shows the
 * differences and "Ask to update buyer details from the client" (manage_orders,
 * reason 10–500 characters). One PENDING request per order. A holder of
 * approve_buyer_details_change approves or refuses it (refusal needs a note of
 * 10+ characters); nobody decides their own request unless Admin › Self-approval
 * rules "buyer_details_change" allows it (default MD only). If the client's
 * details changed after the request (hash of the snapshot), the request LAPSES
 * and nothing is applied — also when the client is saved again while it waits.
 * Issued documents never change: they keep their stored render context and
 * files; only documents generated afterwards show the new details.
 *
 * Port of App\Services\BuyerDetailsRefreshService (same wording).
 */

const PERMISSION = 'approve_buyer_details_change';
const RULE = 'buyer_details_change';
const REASON_MIN = 10;
const REASON_MAX = 500;

/** The order's snapshot columns, in the order they are listed, with plain names. */
const LABELS = {
  company_legal_name: 'Company legal name',
  billing_address: 'Billing address',
  billing_address_line1: 'Billing address line 1',
  billing_address_line2: 'Billing address line 2',
  billing_city: 'Billing city',
  billing_postcode: 'Billing postcode',
  billing_country: 'Billing country',
  vat_eori_tax_no: 'VAT / EORI / tax no.',
  contact_person: 'Contact person',
  client_email: 'Email',
  client_phone: 'Phone',
  country_of_destination: 'Country of destination',
  consignee_same_as_buyer: 'Consignee same as buyer',
  consignee_name: 'Consignee name',
  consignee_address: 'Consignee address',
  consignee_address_line1: 'Consignee address line 1',
  consignee_address_line2: 'Consignee address line 2',
  consignee_city: 'Consignee city',
  consignee_postcode: 'Consignee postcode',
  consignee_country: 'Consignee country',
  consignee_vat_eori_tax_no: 'Consignee VAT / EORI / tax no.',
  consignee_contact_person: 'Consignee contact person',
  consignee_phone: 'Consignee phone',
  consignee_email: 'Consignee email',
  notify_party_same_as_consignee: 'Notify party same as consignee',
  notify_party: 'Notify party',
  notify_party_address_line1: 'Notify party address line 1',
  notify_party_address_line2: 'Notify party address line 2',
  notify_party_city: 'Notify party city',
  notify_party_postcode: 'Notify party postcode',
  notify_party_country: 'Notify party country',
  notify_party_contact_person: 'Notify party contact person',
  notify_party_phone: 'Notify party phone',
  notify_party_email: 'Notify party email',
};

const FLAGS = ['consignee_same_as_buyer', 'notify_party_same_as_consignee'];
/** Order statuses that never change automatically or by request. */
const CLOSED_FOR_AUTO = ['cancelled', 'lost', 'complete'];
const CLOSED_FOR_REQUEST = ['cancelled', 'lost'];

const MSG_ALREADY_WAITING = 'A request to update the buyer details is already waiting for approval.';
const MSG_LAPSED = 'The client\'s details changed again after this request — ask again so the approver sees the current details.';
const MSG_SELF = 'You asked for this change — someone else must approve it (Admin › Self-approval rules).';
const MSG_NOT_WAITING = 'This request is no longer waiting for approval.';
const MSG_NOTHING = 'The order already shows the client\'s current details — nothing to update.';

function orderRepository() { return require('../repositories/orderRepository'); }

// ---------------------------------------------------------------- comparing

function norm(v) {
  return v === null || v === undefined ? '' : String(v);
}

function flagValue(v) {
  return v === null || v === undefined ? 1 : Number(v);
}

/** How a value reads on screen and in messages. */
function display(field, v) {
  if (FLAGS.includes(field)) return Number(v) === 1 ? 'Yes' : 'No';
  const s = norm(v).trim();
  return s === '' ? '(blank)' : s;
}

/** The snapshot fields where the order differs from the client now: [{field, label, old, new}] */
function differences(order, snapshot) {
  const out = [];
  for (const [field, label] of Object.entries(LABELS)) {
    const oldV = order[field] === undefined ? null : order[field];
    const newV = snapshot[field] === undefined ? null : snapshot[field];
    const same = FLAGS.includes(field) ? flagValue(oldV) === flagValue(newV) : norm(oldV) === norm(newV);
    if (same) continue;
    out.push({ field, label, old: oldV === null ? null : String(oldV), new: newV === null ? null : String(newV) });
  }
  return out;
}

/** "Email: a@b.com → x@y.com; Phone: (blank) → +91-0000000000" */
function changesText(changes) {
  return changes.map((c) => `${c.label}: ${display(c.field, c.old)} → ${display(c.field, c.new)}`).join('; ');
}

function snapshotHash(snapshot) {
  const vals = {};
  for (const field of Object.keys(LABELS)) vals[field] = norm(snapshot[field]);
  return crypto.createHash('sha256').update(JSON.stringify(vals), 'utf8').digest('hex');
}

/** See the module docblock for what "issued" means. */
async function hasIssuedDocument(orderId) {
  const row = await db.queryOne(
    `SELECT 1 AS x FROM documents d
      WHERE d.order_id = :o
        AND (d.status IN ('approved', 'sent')
             OR (d.status = 'superseded' AND NOT EXISTS (
                   SELECT 1 FROM audit_log a
                    WHERE a.action_type = 'DOCUMENT_SUPERSEDED' AND a.entity_type = 'documents'
                      AND a.entity_id = d.id AND a.old_value IN ('draft', 'in_review'))))
      LIMIT 1`, { o: orderId });
  return row !== null;
}

/**
 * What the order page shows: the differences, whether a document is issued,
 * and the waiting request (if any). Null when there is nothing to show.
 */
async function noticeFor(order) {
  const pending = await orderBuyerDetailRequestRepository.pendingForOrder(Number(order.id));
  if (CLOSED_FOR_REQUEST.includes(String(order.status)) && pending === null) return null;
  const changes = differences(order, await orderRepository().clientSnapshot(Number(order.client_id)));
  if (changes.length === 0 && pending === null) return null;
  if (pending !== null) {
    try { pending.changes = JSON.parse(String(pending.changes_json)) || []; } catch (e) { pending.changes = []; }
  }
  return { changes, issued: await hasIssuedDocument(Number(order.id)), pending };
}

/** The order page card: noticeFor() plus what this person may do, with display values ready. */
async function viewFor(order, req) {
  const notice = await noticeFor(order);
  if (notice === null) return null;
  const userId = Number(req.user.id);
  const withDisplay = (list) => list.map((c) => ({ ...c, oldText: display(c.field, c.old), newText: display(c.field, c.new) }));
  notice.changes = withDisplay(notice.changes);
  if (notice.pending) {
    notice.pending.changes = withDisplay(notice.pending.changes);
    notice.pending.createdShort = require('../helpers/dates').short(String(notice.pending.created_at).slice(0, 10));
    notice.canDecide = !!(req.permissions || {})[PERMISSION] && !(await selfDecisionRefused(notice.pending, userId));
    notice.isRequester = Number(notice.pending.requested_by || 0) === userId;
  }
  return notice;
}

// ---------------------------------------------------------------- applying

/** Copies the client's details onto the order and audits each changed field. Returns the fields that changed. */
async function apply(order, snapshot, userId, source) {
  const changes = differences(order, snapshot);
  if (changes.length === 0) return [];
  await orderRepository().applyClientSnapshot(Number(order.id), Number(order.client_id));
  for (const c of changes) {
    await auditLogRepository.log(userId, 'ORDER_BUYER_DETAILS_REFRESHED', 'orders', Number(order.id), c.field, c.old, c.new, `Source: ${source}`);
  }
  return changes;
}

async function userName(userId) {
  if (userId === null || userId === undefined) return 'someone';
  const u = await userRepository.findById(userId);
  return (u && u.name) || 'someone';
}

/** Holders of approve_buyer_details_change, without exceptUserId. */
async function approvers(exceptUserId) {
  return (await permissionRepository.usersWithPermission(PERMISSION)).map(Number).filter((id) => id !== Number(exceptUserId));
}

/**
 * Case 1, plus lapsing waiting requests: called right after a client is saved.
 * @returns {Promise<{refreshed: string[], lapsed: string[]}>} order references
 */
async function afterClientSaved(clientId, userId, source) {
  const snapshot = await orderRepository().clientSnapshot(clientId);
  const hash = snapshotHash(snapshot);
  const lapsed = [];
  for (const r of await orderBuyerDetailRequestRepository.pendingForClient(clientId)) {
    if (String(r.client_snapshot_hash) !== hash && await lapse(r, userId, `the client was saved again (${source})`)) {
      lapsed.push(String(r.order_reference));
    }
  }

  const ids = await db.query(
    "SELECT id FROM orders WHERE client_id = :c AND status NOT IN ('cancelled', 'lost', 'complete') ORDER BY id", { c: clientId });
  const refreshed = [];
  const who = await userName(userId);
  let clientName = snapshot.company_legal_name;
  if (clientName === null || clientName === undefined) {
    const client = await require('../repositories/clientRepository').find(clientId);
    clientName = (client && client.company_legal_name) || '';
  }
  for (const { id } of ids) {
    const orderId = Number(id);
    const order = await orderRepository().find(orderId);
    if (!order || CLOSED_FOR_AUTO.includes(String(order.status)) || await hasIssuedDocument(orderId)) continue;
    const changes = await apply(order, snapshot, userId, source);
    if (changes.length === 0) continue;
    refreshed.push(String(order.order_reference));
    const message = `Buyer details of ${clientName} changed by ${who} — also updated on draft order ${order.order_reference}: ${changesText(changes)}`;
    for (const approverId of await approvers(userId)) {
      await notificationRepository.create(approverId, null, 'buyer_details_refreshed', orderId, message);
    }
  }
  return { refreshed, lapsed };
}

/** The flash sentence for the client page ('' when no order changed). */
function flashText(result) {
  let text = '';
  if (result.refreshed.length) text += ` Buyer details also updated on draft order(s): ${result.refreshed.join(', ')}.`;
  if (result.lapsed.length) {
    text += ` A waiting request to update the buyer details lapsed on order(s) ${result.lapsed.join(', ')} — ask again so the approver sees the current details.`;
  }
  return text;
}

/**
 * "Update buyer details from the client" on an order with nothing issued (no approval needed).
 * @returns {Promise<{error: string|null, changes: object[]}>}
 */
async function refreshOrder(orderId, userId) {
  const order = await orderRepository().find(orderId);
  if (!order || CLOSED_FOR_AUTO.includes(String(order.status))) {
    return { error: 'The buyer details of this order can no longer be updated.', changes: [] };
  }
  if (await hasIssuedDocument(orderId)) {
    return { error: 'This order already has an issued document — ask for approval to update the buyer details.', changes: [] };
  }
  const snapshot = await orderRepository().clientSnapshot(Number(order.client_id));
  const changes = await apply(order, snapshot, userId, 'order page (nothing issued yet)');
  if (changes.length === 0) return { error: MSG_NOTHING, changes: [] };
  const message = `Buyer details of ${snapshot.company_legal_name || ''} updated by ${await userName(userId)} on draft order ${order.order_reference}: ${changesText(changes)}`;
  for (const approverId of await approvers(userId)) {
    await notificationRepository.create(approverId, null, 'buyer_details_refreshed', orderId, message);
  }
  return { error: null, changes };
}

// ---------------------------------------------------------------- requests

function reasonError(reason, what = 'reason') {
  const len = [...String(reason || '').trim()].length;
  if (len < REASON_MIN || len > REASON_MAX) return `Give the ${what} (${REASON_MIN} to ${REASON_MAX} characters).`;
  return null;
}

/** Case 2: ask to copy the client's details onto an order with an issued document. Returns the error or null. */
async function request(orderId, userId, reasonIn) {
  const reason = String(reasonIn || '').trim();
  const e = reasonError(reason);
  if (e !== null) return e;
  const order = await orderRepository().find(orderId);
  if (!order || CLOSED_FOR_REQUEST.includes(String(order.status))) return 'The buyer details of this order can no longer be updated.';
  if (await orderBuyerDetailRequestRepository.pendingForOrder(orderId)) return MSG_ALREADY_WAITING;
  const snapshot = await orderRepository().clientSnapshot(Number(order.client_id));
  const changes = differences(order, snapshot);
  if (changes.length === 0) return MSG_NOTHING;
  if (!(await hasIssuedDocument(orderId))) {
    return 'Nothing has been issued on this order yet — use "Update buyer details from the client" (no approval needed).';
  }
  const id = await orderBuyerDetailRequestRepository.create(orderId, userId, reason, JSON.stringify(changes), snapshotHash(snapshot));
  if (id === 0) return MSG_ALREADY_WAITING;
  await auditLogRepository.log(userId, 'BUYER_DETAILS_CHANGE_REQUESTED', 'orders', orderId, 'buyer_details', null, changesText(changes), `Request #${id} — ${reason}`);
  const message = `Approval needed: update the buyer details of order ${order.order_reference} (${snapshot.company_legal_name || ''}) from the client — asked by `
    + `${await userName(userId)}: ${changesText(changes)}. Reason: ${reason}`;
  for (const approverId of await approvers(userId)) {
    await notificationRepository.create(approverId, null, 'buyer_details_change_requested', orderId, message);
  }
  return null;
}

/** Whether this person may NOT decide this request (permission is checked by the route). */
async function selfDecisionRefused(req, userId) {
  return req.requested_by !== null && req.requested_by !== undefined && Number(req.requested_by) === Number(userId)
    && !(await selfApprovalRules.allowed(RULE, Number(userId)));
}

async function loadPending(requestId, orderId) {
  const r = await orderBuyerDetailRequestRepository.find(requestId);
  if (!r || Number(r.order_id) !== orderId || r.status !== 'PENDING') return [null, MSG_NOT_WAITING];
  return [r, null];
}

async function lapse(r, userId, why) {
  if (!(await orderBuyerDetailRequestRepository.decide(Number(r.id), 'LAPSED', userId === undefined ? null : userId, `Lapsed — ${why}`))) return false;
  const order = await orderRepository().find(Number(r.order_id));
  await auditLogRepository.log(userId, 'BUYER_DETAILS_CHANGE_LAPSED', 'orders', Number(r.order_id), 'buyer_details', null, null, `Request #${r.id} lapsed — ${why}; nothing applied`);
  if (r.requested_by !== null && r.requested_by !== undefined) {
    await notificationRepository.create(Number(r.requested_by), null, 'buyer_details_change_decided', Number(r.order_id),
      `Your request to update the buyer details of order ${(order && order.order_reference) || ''} lapsed — the client's details changed again after it. Ask again so the approver sees the current details.`);
  }
  return true;
}

/** Approve: applies the client's details, unless they changed since the request (then LAPSED). Returns the error or null. */
async function approve(requestId, orderId, userId, noteIn) {
  const [r, err] = await loadPending(requestId, orderId);
  if (!r) return err;
  if (await selfDecisionRefused(r, userId)) return MSG_SELF;
  const note = String(noteIn || '').trim();
  if ([...note].length > REASON_MAX) return `Keep the note to ${REASON_MAX} characters.`;
  const order = await orderRepository().find(orderId);
  if (!order) return MSG_NOT_WAITING;
  const snapshot = await orderRepository().clientSnapshot(Number(order.client_id));
  if (snapshotHash(snapshot) !== String(r.client_snapshot_hash)) {
    await lapse(r, userId, 'the client\'s details changed after the request');
    return MSG_LAPSED;
  }
  if (!(await orderBuyerDetailRequestRepository.decide(requestId, 'APPROVED', userId, note !== '' ? note : null))) return MSG_NOT_WAITING;
  const changes = await apply(order, snapshot, userId, `approved request #${requestId}`);
  await auditLogRepository.log(userId, 'BUYER_DETAILS_CHANGE_APPROVED', 'orders', orderId, 'buyer_details', null, changesText(changes), `Request #${requestId}${note !== '' ? ` — ${note}` : ''}`);
  if (r.requested_by !== null && r.requested_by !== undefined) {
    await notificationRepository.create(Number(r.requested_by), null, 'buyer_details_change_decided', orderId,
      `Your request to update the buyer details of order ${order.order_reference} was approved by ${await userName(userId)} — documents generated from now on show the new details.`);
  }
  return null;
}

/** Refuse with a note (10–500 characters). Returns the error or null. */
async function refuse(requestId, orderId, userId, noteIn) {
  const [r, err] = await loadPending(requestId, orderId);
  if (!r) return err;
  if (await selfDecisionRefused(r, userId)) return MSG_SELF;
  const note = String(noteIn || '').trim();
  const e = reasonError(note, 'note saying why it is refused');
  if (e !== null) return e;
  if (!(await orderBuyerDetailRequestRepository.decide(requestId, 'REFUSED', userId, note))) return MSG_NOT_WAITING;
  const order = await orderRepository().find(orderId);
  await auditLogRepository.log(userId, 'BUYER_DETAILS_CHANGE_REFUSED', 'orders', orderId, 'buyer_details', null, null, `Request #${requestId} — ${note}`);
  if (r.requested_by !== null && r.requested_by !== undefined) {
    await notificationRepository.create(Number(r.requested_by), null, 'buyer_details_change_decided', orderId,
      `Your request to update the buyer details of order ${(order && order.order_reference) || ''} was refused by ${await userName(userId)}: ${note}`);
  }
  return null;
}

module.exports = {
  PERMISSION, RULE, REASON_MIN, REASON_MAX, LABELS,
  MSG_ALREADY_WAITING, MSG_LAPSED, MSG_SELF, MSG_NOT_WAITING, MSG_NOTHING,
  display, differences, changesText, snapshotHash, hasIssuedDocument, noticeFor, viewFor,
  afterClientSaved, flashText, refreshOrder, reasonError, request, selfDecisionRefused, approve, refuse,
};
