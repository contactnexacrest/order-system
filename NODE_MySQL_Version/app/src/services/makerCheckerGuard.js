'use strict';

const selfApprovalRules = require('./selfApprovalRules');

// Port of App\Services\MakerCheckerGuard. The single choke point for every
// self-approval check: the person who created or requested an item must not
// also approve it, unless one of their own roles is ticked for that rule in
// Admin → Self-approval rules (default Managing Director and Executive
// Director). QA Round 8 (R8-N09): the seven rules are payment_terms,
// document_review, email_send, cancellation, purchase_award, amendment and
// short_payment. There is no blanket Super Admin or manage_permissions
// exemption any more — a Super Admin or System Administrator qualifies only
// if their own role is ticked, like everyone else.

async function selfApprovalAllowed(rule, userId) {
  return selfApprovalRules.allowed(rule, userId);
}

// Owner decision 1 Nov 2026 (A-1, then D-221): payment terms are approved
// by a second person unless the creator's role is ticked.
async function paymentTermsSelfApprovalAllowed(userId) {
  return selfApprovalAllowed('payment_terms', userId);
}

module.exports = { selfApprovalAllowed, paymentTermsSelfApprovalAllowed };
