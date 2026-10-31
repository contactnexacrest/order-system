'use strict';

const env = require('../config/env'); // loads .env as a side effect — must run first
const caSyncService = require('../services/caSyncService');
const db = require('../config/db');
const logger = require('../helpers/logger');

/**
 * CA / Accounting module — the scheduled half of the Zoho Books sync (the
 * other is the "Sync Now" button on /ca/zoho-sync). Runs the same
 * caSyncService.runFullSync() the button calls, so the two never drift
 * apart: it sends what is new to Zoho Books, imports the CA's expenses,
 * and — once a day here, every time on Sync Now — checks the records
 * already in Books and puts back any changed or deleted there (owner
 * decision 2026-10-29). Safe to run as often as wanted — nothing already
 * sent is sent twice, and runFullSync() itself no-ops cleanly (logging a
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
      console.log(`[zoho_sync] revenue ${result.revenue.invoices} invoices/${result.revenue.synced} synced/${result.revenue.applied} applied/${result.revenue.failed} failed/${result.revenue.skipped} skipped; purchases ${result.purchases.bills} bills/${result.purchases.payments} payments/${result.purchases.applied} applied/${result.purchases.expenses} expenses/${result.purchases.failed} failed; benefits ${result.benefits.journals} journals/${result.benefits.removed} removed/${result.benefits.failed} failed; GST refunds ${result.gst_refunds.journals} journals/${result.gst_refunds.failed} failed; expenses ${result.expenses.imported} imported/${result.expenses.updated} updated/${result.expenses.removed} removed/${result.expenses.failed} failed/${result.expenses.skipped} skipped.`);
      return db.pool.end();
    })
    .catch((e) => {
      logger.error('zoho_sync', e);
      process.exitCode = 1;
    });
}

module.exports = { run };
