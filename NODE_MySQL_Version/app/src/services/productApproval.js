'use strict';

/**
 * D-435 (owner 26 Nov 2026): a product added by a brand manager waits in Approvals › New products until
 * someone with "Approve new products" (MD, ED by default) approves it — when the setting
 * brand_products_need_approval is on (default on). Until then it cannot be quoted, ordered or seen by associates.
 * Nobody approves a product they added. Every step is in the Audit Log. PHP twin: App\Services\ProductApproval.
 */
const db = require('../config/db');
const auditLogRepository = require('../repositories/auditLogRepository');

const STATUSES = { approved: 'Approved', pending: 'Waiting for approval', rejected: 'Not approved' };

/** The SQL condition (alias p) for a product that may be used — quoted, ordered, offered to associates. */
const APPROVED_SQL = "p.approval_status = 'approved'";

const len = (v) => [...String(v)].length;

async function settingOn() {
  return ((await require('../repositories/companySettingsRepository').get('brand_products_need_approval')) ?? '1') === '1';
}

/** Whether a product this user adds must wait for approval: a brand manager, with the setting on. */
async function needsApproval(user) {
  return (await settingOn()) && require('./associateBrands').userBrandId(user) !== null;
}

function canApprove(permissions) {
  return !!(permissions && permissions.approve_new_products);
}

/** Called right after a product is created: marks it waiting when the creator is a brand manager. */
async function afterCreate(productId, userId, user) {
  if (!(await needsApproval(user))) return;
  await db.execute(`UPDATE catalog_products SET approval_status = 'pending', submitted_by = :u, approval_reason = NULL,
      approved_by = NULL, approved_at = NULL WHERE id = :id`, { u: userId, id: productId });
  await auditLogRepository.log(userId, 'PRODUCT_SUBMITTED_FOR_APPROVAL', 'catalog_products', productId, 'approval_status', 'approved', 'pending');
}

/** '' when the user may decide on the product now, else why not. */
function refusal(product, userId, permissions) {
  if ((product.approval_status || 'approved') !== 'pending') return 'This product is not waiting for approval.';
  if (!canApprove(permissions)) return 'You do not have the "Approve new products" permission.';
  if (Number(product.submitted_by || 0) === Number(userId) || Number(product.created_by || 0) === Number(userId)) return 'Someone else approves a product you added.';
  return '';
}

/** Approve (reason optional) or reject (reason required, 10–300 characters). Returns [ok, message]. */
async function decide(product, approve, reason, userId, permissions) {
  const why = refusal(product, userId, permissions);
  if (why !== '') return [false, why];
  reason = String(reason || '').trim();
  if (!approve && (len(reason) < 10 || len(reason) > 300)) return [false, 'Give the reason the product is not approved (10 to 300 characters) — the brand manager sees it.'];
  if (len(reason) > 300) return [false, 'Keep the note to 300 characters.'];
  const status = approve ? 'approved' : 'rejected';
  const r = await db.execute(`UPDATE catalog_products SET approval_status = :s, approval_reason = :r, approved_by = :u, approved_at = NOW()
      WHERE id = :id AND approval_status = 'pending'`, { s: status, r: reason !== '' ? reason : null, u: userId, id: Number(product.id) });
  if (Number(r.affectedRows) !== 1) return [false, 'Someone else decided on this product a moment ago.'];
  await auditLogRepository.log(userId, approve ? 'PRODUCT_APPROVED' : 'PRODUCT_REJECTED', 'catalog_products', Number(product.id), 'approval_status', 'pending', status, reason !== '' ? reason : null);
  return [true, approve ? `"${product.name}" is approved — it can be quoted and associates of its brand see it.`
    : `"${product.name}" is not approved. The brand manager sees your reason on the product.`];
}

/** A rejected product sent again after changes (by its brand manager). */
async function resubmit(product, userId) {
  if ((product.approval_status || '') !== 'rejected') return false;
  await db.execute("UPDATE catalog_products SET approval_status = 'pending', submitted_by = :u, approved_by = NULL, approved_at = NULL WHERE id = :id",
    { u: userId, id: Number(product.id) });
  await auditLogRepository.log(userId, 'PRODUCT_SUBMITTED_FOR_APPROVAL', 'catalog_products', Number(product.id), 'approval_status', 'rejected', 'pending');
  return true;
}

/** Products waiting, oldest first, with category, brand and who added them. */
async function pending() {
  const productCategoryRepository = require('../repositories/productCategoryRepository');
  const rows = await db.query(`SELECT p.id, p.name, p.category_id, p.submitted_by, p.created_by, p.created_at, p.updated_at, u.name AS submitted_by_name
      FROM catalog_products p LEFT JOIN users u ON u.id = p.submitted_by WHERE p.approval_status = 'pending' ORDER BY p.updated_at, p.id`);
  for (const r of rows) {
    const c = r.category_id !== null ? await productCategoryRepository.find(r.category_id) : null;
    r.category = productCategoryRepository.displayName(c);
    r.brand_id = c ? (c.brand_id ?? null) : null;
  }
  return rows;
}

module.exports = { STATUSES, APPROVED_SQL, settingOn, needsApproval, canApprove, afterCreate, refusal, decide, resubmit, pending };
