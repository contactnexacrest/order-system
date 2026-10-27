'use strict';

const caExportBenefitRepository = require('../repositories/caExportBenefitRepository');
const orderExportBenefitSchemeRepository = require('../repositories/orderExportBenefitSchemeRepository');

/**
 * Port of App\Services\BenefitSettlementService. Owner decision
 * (2026-10-27, Option A): government export benefits are recorded at Step
 * 13g, just before Mark Fully Settled (13h) — the money usually lands after
 * the buyer has paid in full. 13g is done once every scheme has an
 * eligibility decision and every eligible scheme is either received or
 * skipped with a reason. Each scheme row carries a `settlement` of
 * not_eligible|undecided|skipped|received|waiting|unclaimed.
 */
async function state(orderId) {
  const claimsByScheme = {};
  for (const claim of await caExportBenefitRepository.forOrder(orderId)) {
    (claimsByScheme[claim.scheme_name] = claimsByScheme[claim.scheme_name] || []).push(claim);
  }
  const out = { undecided: [], waiting: [], unclaimed: [], skipped: [] };
  const schemes = [];
  for (const row of await orderExportBenefitSchemeRepository.forOrder(orderId)) {
    const claims = claimsByScheme[row.scheme_name] || [];
    let settlement;
    if (row.status === 'not_eligible') settlement = 'not_eligible';
    else if (row.status === 'pending') settlement = 'undecided';
    else if (row.claim_skipped_at) settlement = 'skipped';
    else if (claims.length === 0) settlement = 'unclaimed';
    else settlement = claims.some((c) => c.received_amount === null) ? 'waiting' : 'received';
    schemes.push({ ...row, settlement });
    if (out[settlement]) out[settlement].push(row.scheme_name);
  }
  return {
    done: out.undecided.length === 0 && out.waiting.length === 0 && out.unclaimed.length === 0,
    schemes,
    ...out,
  };
}

module.exports = { state };
