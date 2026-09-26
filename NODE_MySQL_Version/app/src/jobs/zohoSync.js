'use strict';

const env = require('../config/env'); // loads .env as a side effect — must run first
const caSyncService = require('../services/caSyncService');
const db = require('../config/db');
const logger = require('../helpers/logger');

/**
 * CA / Accounting module (Phase 3) — the scheduled half of the Zoho Books
 * sync (the other is the "Sync Now" button on /ca/zoho-sync). Pushes every
 * settlement leg that has an INR actual recorded but hasn't been synced
 * yet, same logic as the manual button, via the same caSyncService so the
 * two never drift apart. Safe to run as often as wanted — a leg already
 * marked synced is never re-pushed, and caSyncService.syncPendingRevenue()
 * itself no-ops cleanly (logging one "skipped" row) when Zoho Books isn't
 * configured, so this never breaks the job itself or blocks anything else
 * in the app (CA module brief, point 5).
 *
 * Runnable directly (`node src/jobs/zohoSync.js`) for an external OS-level
 * cron/systemd timer, or scheduled in-process via scheduler.js — either
 * way, see the deployment guide's job-scheduling section.
 */
async function run() {
  return caSyncService.syncPendingRevenue('scheduled', null);
}

if (require.main === module) {
  run()
    .then((result) => {
      console.log(`[zoho_sync] ${result.synced} synced, ${result.failed} failed, ${result.skipped} skipped.`);
      return db.pool.end();
    })
    .catch((e) => {
      logger.error('zoho_sync', e);
      process.exitCode = 1;
    });
}

module.exports = { run };
