'use strict';

const db = require('../config/db');
const auditLogRepository = require('../repositories/auditLogRepository');

/**
 * QA Round 5, R5-03 (owner decision 2026-10-28, Option A): an order on a
 * payment preset marked "Requires MD approval" needs its payment terms
 * approved — by someone holding "Approve payment terms" (see
 * approverRoleNames()) — before its Quotation can be generated or sent. The preset's
 * flag is read live, so unticking it in Admin → Payment Presets lifts the
 * requirement for orders not yet quoted. Port of PaymentTermsApprovalService.php.
 */
async function state(orderId) {
  const row = await db.queryOne(
    // D-262: the flag frozen with the order once its PI is approved, and the preset name the order was made with.
    `SELECT COALESCE(o.payment_terms_requires_md_approval, pp.requires_md_approval) AS requires_md_approval,
            COALESCE(o.preset_name, pp.preset_name) AS preset_name, o.payment_terms_approved_at, u.name AS approved_by_name
     FROM orders o
     JOIN payment_presets pp ON pp.id = o.payment_preset_id
     LEFT JOIN users u ON u.id = o.payment_terms_approved_by
     WHERE o.id = :id`,
    { id: orderId }
  );
  const required = !!row && Number(row.requires_md_approval) === 1;
  return {
    required,
    approved: required && row.payment_terms_approved_at !== null,
    approved_at: row ? row.payment_terms_approved_at : null,
    approved_by_name: row ? row.approved_by_name : null,
    preset_name: row ? row.preset_name : null,
  };
}

async function isPending(orderId) {
  const s = await state(orderId);
  return s.required && !s.approved;
}

async function pendingMessage(orderId) {
  const preset = (await state(orderId)).preset_name || 'this payment preset';
  return `The payment terms on this order ("${preset}") need approval before the Quotation can be generated or sent — approve them in Step 1, or ask ${await whoCanApprove()}.`;
}

/**
 * D-269: the roles that hold "Approve payment terms" (enabled), in role
 * order — read live, so the wording stays true when Roles & Permissions
 * changes. Personal grants and Super Admins are not roles; the wording
 * ("someone who can approve payment terms") covers them.
 * @returns {Promise<string[]>}
 */
async function approverRoleNames() {
  const rows = await db.query(
    `SELECT r.id, r.name FROM roles r
     WHERE EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
                   WHERE rp.role_id = r.id AND rp.is_enabled = 1 AND p.permission_key = 'approve_payment_terms')
     ORDER BY r.id`
  );
  return rows.map((r) => String(r.name));
}

/** D-269: "someone who can approve payment terms (Admin, Managing Director, …)". */
async function whoCanApprove() {
  const names = await approverRoleNames();
  return `someone who can approve payment terms${names.length ? ` (${names.join(', ')})` : ''}`;
}

/**
 * @returns {Promise<boolean>} whether this call approved them — false when
 * they were already approved, e.g. by a second click a moment earlier (QA
 * Round 6, R6-N17): approved and logged once.
 */
async function approve(orderId, userId) {
  const result = await db.execute(
    'UPDATE orders SET payment_terms_approved_by = :user_id, payment_terms_approved_at = NOW() WHERE id = :id AND payment_terms_approved_at IS NULL',
    { user_id: userId, id: orderId }
  );
  if (result.affectedRows !== 1) {
    return false;
  }
  const now = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  const stamp = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
  await auditLogRepository.log(userId, 'PAYMENT_TERMS_APPROVED', 'orders', orderId, 'payment_terms_approved_at', null, stamp);
  return true;
}

module.exports = { state, isPending, pendingMessage, approve, approverRoleNames, whoCanApprove };
