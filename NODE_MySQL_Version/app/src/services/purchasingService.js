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

/** The approval limit of the designation this person is working as; null = none set (no limit). */
async function limitFor(req, userId) {
  const m = await activeDepartmentService.current(req, userId);
  return m && m.approval_limit_inr !== null ? Number(m.approval_limit_inr) : null;
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
