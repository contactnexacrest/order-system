'use strict';

const db = require('../config/db');

async function record(userId, emailAttempted, ip, success) {
  await db.execute(
    'INSERT INTO login_attempts (user_id, email_attempted, ip_address, success) VALUES (:user_id, :email, :ip, :success)',
    { user_id: userId, email: emailAttempted, ip, success: success ? 1 : 0 }
  );
}

async function recentFailedCount(userId, withinMinutes = 15) {
  // INTERVAL ? MINUTE can't take a bound param directly in all MySQL modes reliably
  // via named placeholders combined with INTERVAL syntax, so this interpolates the
  // (already-int-cast) minutes value directly — same as the PHP original binding it
  // as PDO::PARAM_INT rather than a string.
  const minutes = parseInt(withinMinutes, 10);
  const row = await db.queryOne(
    `SELECT COUNT(*) AS c FROM login_attempts
     WHERE user_id = :user_id AND success = 0
       AND attempted_at >= (NOW() - INTERVAL ${minutes} MINUTE)`,
    { user_id: userId }
  );
  return row ? parseInt(row.c, 10) : 0;
}

module.exports = { record, recentFailedCount };
