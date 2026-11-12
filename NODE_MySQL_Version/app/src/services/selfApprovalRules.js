'use strict';

const db = require('../config/db');
const roleRepository = require('../repositories/roleRepository');
const userRepository = require('../repositories/userRepository');
const departmentRepository = require('../repositories/departmentRepository');

/**
 * Port of SelfApprovalRules.php. Owner decision 1 Nov 2026 (D-221): who
 * may approve their own work is set in Admin → Self-approval rules (Super
 * Admins only), one list of roles per rule. Defaults: Managing Director
 * and Executive Director on every rule (QA Round 8: seven rules), except
 * buyer_details_change (D-337): Managing Director only. A person qualifies when any of
 * their own roles (primary role or a department membership) is ticked.
 */
const RULES = {
  payment_terms: 'Approve the payment terms of an order they created',
  document_review: 'Review (approve) a document they generated',
  email_send: 'Approve an e-mail send they requested',
  cancellation: 'Approve or reject the cancellation of an order they requested',
  purchase_award: 'Approve a purchase award they made',
  amendment: 'MD-approve an amendment they requested',
  short_payment: 'Clear short a buyer payment whose receipts they recorded',
  // Owner decision 2 Nov 2026 (D-240): a price below the reference by the warning % or more.
  price_discount: 'Approve an order line price below the floor where they set that price themselves (D-245)',
  // Owner decision 11 Nov 2026 (D-293): verify bank details (associates and suppliers) they entered themselves, with a reason.
  bank_details: 'Verify bank details (associates and suppliers) they entered themselves — with a reason',
  // Owner decision 12 Nov 2026 (D-337): default Managing Director only.
  buyer_details_change: 'Approve copying the client\'s current details onto an order with an issued document, where they asked for it themselves',
};

async function roleIds(rule) {
  const rows = await db.query('SELECT role_id FROM self_approval_rules WHERE rule_key = :k ORDER BY role_id', { k: rule });
  return rows.map((r) => Number(r.role_id));
}

async function roleNames(rule) {
  const names = [];
  for (const id of await roleIds(rule)) {
    const role = await roleRepository.find(id);
    if (role && role.name) names.push(role.name);
  }
  return names;
}

async function allowed(rule, userId) {
  if (!RULES[rule]) return false;
  const user = await userRepository.findById(userId);
  if (!user) return false;
  const own = new Set((await departmentRepository.activeRoleIdsForUser(userId)).map(Number));
  if (user.role_id !== null && user.role_id !== undefined) own.add(Number(user.role_id));
  const ticked = await roleIds(rule);
  return ticked.some((id) => own.has(id));
}

async function set(rule, ids) {
  await db.execute('DELETE FROM self_approval_rules WHERE rule_key = :k', { k: rule });
  for (const id of [...new Set(ids.map(Number))]) {
    await db.execute('INSERT INTO self_approval_rules (rule_key, role_id) VALUES (:k, :r)', { k: rule, r: id });
  }
}

module.exports = { RULES, roleIds, roleNames, allowed, set };
