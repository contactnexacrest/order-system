'use strict';

/**
 * Owner decision (2026-10-26): Step 12 as guided sub-steps (Option A) —
 * 12a costs, 12b scheme eligibility, 12c benefits claimed & received, 12d
 * review & complete. Port of the status block at the top of PHP's Step 12
 * markup. Sub-steps never lock each other (benefit money can arrive months
 * later); the next unfinished one is simply opened.
 */
function financialsStep(order, eligibility, claims, costEntries, payment) {
  const eligible = (eligibility || []).filter((r) => r.status === 'eligible');
  const pending = (eligibility || []).filter((r) => r.status === 'pending').length;
  const claimedNames = (claims || []).map((c) => c.scheme_name);
  const waiting = (claims || []).filter((c) => c.received_amount === null).length;
  const unclaimed = eligible.filter((r) => !claimedNames.includes(r.scheme_name)).length;
  const skipped = !!order.financials_skipped_at;
  const done = {
    '12a': skipped || !!order.financials_costs_confirmed_at,
    '12b': skipped || pending === 0,
    '12c': skipped || (pending === 0 && unclaimed === 0 && waiting === 0),
    '12d': skipped || !!order.financials_completed_at,
  };
  const current = Object.keys(done).find((sub) => !done[sub]) || null;
  const sum = (rows, key) => (rows || []).reduce((total, r) => total + Number(r[key] || 0), 0);
  return {
    done,
    doneCount: Object.values(done).filter(Boolean).length,
    icon: (sub) => (done[sub] ? 'done' : (sub === current ? 'current' : 'locked')),
    open: (sub) => (sub === current ? ' open' : ''),
    eligible,
    pending,
    claimedNames,
    waiting,
    unclaimed,
    skipped,
    costTotal: sum(costEntries, 'amount_inr'),
    benefitsApplicable: ['shipped_docs_sent', 'complete'].includes(order.status) && !!(payment && payment.balance_remittance_received_at),
    receivedBenefits: sum(claims, 'received_amount'),
    claimedBenefits: sum(claims, 'claimed_amount'),
  };
}

module.exports = { financialsStep };
