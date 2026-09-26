'use strict';

const env = require('../config/env'); // loads .env as a side effect — must run first
const caSyncService = require('../services/caSyncService');
const db = require('../config/db');
const logger = require('../helpers/logger');

/**
 * CA / Accounting module — the scheduled half of the Zoho Books sync (the
 * other is the "Sync Now" button on /ca/zoho-sync). Runs both directions
 * — Phase 3's revenue push and Phase 4's expense import — via the same
 * caSyncService.runFullSync() the button calls, so the two never drift
 * apart. Safe to run as often as wanted — an already-synced revenue leg
 * is never re-pushed and an already-imported expense is never
 * re-inserted, and runFullSync() itself no-ops cleanly (logging a
 * "skipped" row for each direction) when Zoho Books isn't configured, so
 * this never breaks the job itself or blocks anything else in the app
 * (CA module brief, point 5).
 *
 * Runnable directly (`node src/jobs/zohoSync.js`) for an external OS-level
 * cron/systemd timer, or scheduled in-process via scheduler.js — either
 * way, see the deployment guide's job-scheduling section.
 */
async function run() {
  return caSyncService.runFullSync('scheduled', null);
}

if (require.main === module) {
  run()
    .then((result) => {
      console.log(`[zoho_sync] revenue ${result.revenue.synced} synced/${result.revenue.failed} failed/${result.revenue.skipped} skipped; expenses ${result.expenses.imported} imported/${result.expenses.failed} failed/${result.expenses.skipped} skipped.`);
      return db.pool.end();
    })
    .catch((e) => {
      logger.error('zoho_sync', e);
      process.exitCode = 1;
    });
}

module.exports = { run };
