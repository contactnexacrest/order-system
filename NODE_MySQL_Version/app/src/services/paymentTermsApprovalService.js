'use strict';

const db = require('../config/db');
const auditLogRepository = require('../repositories/auditLogRepository');

/**
 * QA Round 5, R5-03 (owner decision 2026-10-28, Option A): an order on a
 * payment preset marked "Requires MD approval" needs its payment terms
 * approved — by someone holding "Approve payment terms" (default Admin,
 * MD, ED) — before its Quotation can be generated or sent. The preset's
 * flag is read live, so unticking it in Admin → Payment Presets lifts the
 * requirement for orders not yet quoted. Port of PaymentTermsApprovalService.php.
 */
async function state(orderId) {
  const row = await db.queryOne(
    `SELECT pp.requires_md_approval, pp.preset_name, o.payment_terms_approved_at, u.name AS approved_by_name
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
  return `The payment terms on this order ("${preset}") need MD/ED approval before the Quotation can be generated or sent — approve them in Step 1, or ask someone who can.`;
}

async function approve(orderId, userId) {
  await db.execute(
    'UPDATE orders SET payment_terms_approved_by = :user_id, payment_terms_approved_at = NOW() WHERE id = :id',
    { user_id: userId, id: orderId }
  );
  const now = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  const stamp = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
  await auditLogRepository.log(userId, 'PAYMENT_TERMS_APPROVED', 'orders', orderId, 'payment_terms_approved_at', null, stamp);
}

module.exports = { state, isPending, pendingMessage, approve };
