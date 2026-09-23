'use strict';

const env = require('../config/env'); // loads .env as a side effect — must run first
const emailLogRepository = require('../repositories/emailLogRepository');
const emailDispatchService = require('../services/emailDispatchService');
const db = require('../config/db');
const logger = require('../helpers/logger');

/**
 * Port of app/cron/dispatch_deferred_emails.php. Spec Section 10 — "LEVEL 2
 * ... APPROVE -> email sends at scheduled time."
 *
 * There's no long-running worker process by default (a plain VPS running
 * this under PM2/systemd is still request-driven for the web process), so a
 * Level-2 approval can't fire the send itself the instant scheduled_at
 * arrives if that moment is in the future (and even an "immediate" send
 * still has to wait for the next tick of whatever's driving this job —
 * there's no queue consumer otherwise). This script is that consumer: run
 * it every 5-15 minutes (an external OS-level cron/systemd timer calling
 * `node src/jobs/dispatchDeferredEmails.js`, or the node-cron schedule in
 * src/jobs/scheduler.js — see the deployment guide for both options), and
 * it sends every email_log row that's 'approved' and whose scheduled_at has
 * passed (or is NULL, meaning "immediate").
 *
 * Deliberately a thin loop — all the actual logic (which file to attach,
 * marking documents 'sent', audit logging) lives in emailDispatchService,
 * so this script and a future "send now" button (if ever added) share one
 * code path instead of two.
 */

async function run() {
  const due = await emailLogRepository.dueForSend();
  let sentCount = 0;
  let failedCount = 0;

  for (const row of due) {
    const sent = await emailDispatchService.dispatch(row);
    if (sent) {
      sentCount++;
    } else {
      failedCount++;
    }
  }

  return { checked: due.length, sent: sentCount, failed: failedCount };
}

// Runnable directly (`node src/jobs/dispatchDeferredEmails.js`) for an
// external OS-level cron/systemd timer, exactly like the PHP script it
// replaces.
if (require.main === module) {
  run()
    .then(({ checked, sent, failed }) => {
      console.log(`[dispatch_deferred_emails] checked ${checked} due row(s): ${sent} sent, ${failed} failed.`);
      return db.pool.end();
    })
    .catch((e) => {
      logger.error('dispatch_deferred_emails', e);
      process.exitCode = 1;
    });
}

module.exports = { run };
