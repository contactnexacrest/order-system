'use strict';

const activeDepartmentService = require('./activeDepartmentService');
const makerCheckerGuard = require('./makerCheckerGuard');

// Port of App\Services\PurchasingService. Owner decision D-214 (2026-10-31),
// phase 3: approval limits. An award above the approval limit of the
// designation the person is working as waits for someone with "Approve
// purchases" whose own limit covers it (no limit set = any amount); nobody
// approves their own award unless the maker-checker rule allows it.

const fmt = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** The award's value: unit price × quantity, with GST. */
function awardValue(quote, quantity) {
  return Math.round(Number(quote.unit_price_inr) * Number(quantity) * (1 + Number(quote.gst_percent) / 100) * 100) / 100;
}

/** A designation's limit: its own figure; none set = any amount for a director, nothing below that. */
function designationLimit(dd) {
  if (dd.approval_limit_inr !== null && dd.approval_limit_inr !== undefined) return Number(dd.approval_limit_inr);
  return dd.level === 'director' ? null : 0;
}

/**
 * The approval limit (null = no limit). QA Round 7, R7-N01: the limit
 * applies whatever the department state. The designation the person is
 * working as this session (or previewing) decides; with none chosen, the
 * designation(s) tied to their own role (the lowest limit). No limit set
 * means "any amount" only at director level; below that, and for a role
 * with no designation, it fails closed (0 = nothing self-approves), except
 * for Admin / Super Admin. Port of PurchasingService::limitFor().
 */
async function limitFor(req, userId) {
  const m = (await activeDepartmentService.current(req, userId)) || (await activeDepartmentService.preview(req));
  if (m) return designationLimit(m);
  const db = require('../config/db');
  const user = await db.queryOne('SELECT u.role_id, u.is_super_admin, r.name AS role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = :id', { id: userId });
  if (!user) return 0;
  const rows = await db.query('SELECT level, approval_limit_inr FROM department_designations WHERE role_id = :r AND is_active = 1', { r: user.role_id || 0 });
  if (rows.length === 0) return (Number(user.is_super_admin) === 1 || user.role_name === 'Admin') ? null : 0;
  const numeric = rows.map(designationLimit).filter((l) => l !== null);
  return numeric.length === 0 ? null : Math.min(...numeric);
}

async function needsApproval(req, userId, value) {
  const limit = await limitFor(req, userId);
  return limit !== null && value > limit;
}

/** Why this person may not approve this award, or null when they may. */
async function approvalRefusal(req, requirement, value) {
  if (!req.permissions.approve_purchases) {
    return 'Approving a purchase needs the "Approve purchases" permission.';
  }
  const limit = await limitFor(req, req.user.id);
  if (limit !== null && value > limit) {
    return `This award (₹${fmt(value)}) is above your approval limit of ₹${fmt(limit)} — a higher approver must approve it.`;
  }
  if (Number(requirement.awarded_by) === Number(req.user.id) && !(await makerCheckerGuard.selfApprovalAllowed(req.user.id))) {
    return 'You awarded this purchase, so someone else must approve it.';
  }
  return null;
}

module.exports = { awardValue, limitFor, needsApproval, approvalRefusal, fmt };
