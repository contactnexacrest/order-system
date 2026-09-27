'use strict';

const rateLimitRepository = require('../repositories/rateLimitRepository');

// Port of App\Helpers\RateLimiter.
//
// QA-5 INT-05: the public quotation-intake form (spec Section 16's actual
// entry point into the system — no auth, no CAPTCHA, no per-order token
// like PI-intake has) accepted unlimited submissions from a single IP; the
// QA report's own reproduction sent 30 requests in a burst and all 30 were
// accepted into the staff review queue. A scripted flood — from a bored
// outsider or a client/ex-employee wanting to bury the intake queue in
// noise — costs the attacker nothing and drowns real prospects in the same
// queue. This is a simple IP+bucket sliding-window limiter backed by
// rate_limit_hits (see its own schema comment); every call records a hit
// before counting, so a request that's itself rejected still counts toward
// the window instead of giving a flood a free retry.

/** @returns {Promise<boolean>} true if this request should be BLOCKED (limit already exceeded) */
async function tooManyRequests(bucketKey, ip, maxRequests, withinMinutes) {
  await rateLimitRepository.recordHit(bucketKey, ip);
  const count = await rateLimitRepository.recentHitCount(bucketKey, ip, withinMinutes);
  return count > maxRequests;
}

module.exports = { tooManyRequests };
