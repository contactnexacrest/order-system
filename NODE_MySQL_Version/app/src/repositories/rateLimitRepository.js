'use strict';

const db = require('../config/db');

// Port of App\Repositories\RateLimitRepository. Backs helpers/rateLimiter.js.
// See rate_limit_hits' own comment in docs/schema.sql — one row per
// attempt, counted over a trailing window rather than a running counter,
// so there's nothing to reset.

async function recordHit(bucketKey, ip) {
  await db.execute('INSERT INTO rate_limit_hits (bucket_key, ip_address) VALUES (:bucket, :ip)', { bucket: bucketKey, ip });
}

async function recentHitCount(bucketKey, ip, withinMinutes) {
  // INTERVAL ? MINUTE can't take a bound param directly in all MySQL modes
  // reliably via named placeholders combined with INTERVAL syntax, so this
  // interpolates the (already-int-cast) minutes value directly — same
  // approach as loginAttemptRepository.recentFailedCount.
  const minutes = parseInt(withinMinutes, 10);
  const row = await db.queryOne(
    `SELECT COUNT(*) AS c FROM rate_limit_hits
     WHERE bucket_key = :bucket AND ip_address = :ip
       AND created_at >= (NOW() - INTERVAL ${minutes} MINUTE)`,
    { bucket: bucketKey, ip }
  );
  return row ? parseInt(row.c, 10) : 0;
}

module.exports = { recordHit, recentHitCount };
