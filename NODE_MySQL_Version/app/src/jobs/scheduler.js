'use strict';

const cron = require('node-cron');
const checkAlerts = require('./checkAlerts');
const dispatchDeferredEmails = require('./dispatchDeferredEmails');
const autoConfirmOcAcknowledgments = require('./autoConfirmOcAcknowledgments');
const zohoSync = require('./zohoSync');
const dailyDigest = require('./dailyDigest');
const documentCopyCleanup = require('./documentCopyCleanup');
const logger = require('../helpers/logger');

/**
 * Optional in-process alternative to external OS-level cron/systemd timers
 * for the five background jobs this app needs (dailyDigest.js, checkAlerts.js,
 * dispatchDeferredEmails.js, autoConfirmOcAcknowledgments.js and
 * zohoSync.js — each also independently runnable via
 * `node src/jobs/<name>.js`, which is what an external cron entry calls).
 *
 * Two ways to run this on a VPS (pick one — never both, or alerts/emails
 * double-fire):
 *
 *   1. External cron (recommended — simplest, survives app restarts/crashes
 *      independently, and is what the deployment guide sets up by default):
 *      add two crontab/systemd-timer entries that `cd` into the app
 *      directory and run `node src/jobs/checkAlerts.js` (once daily) and
 *      `node src/jobs/dispatchDeferredEmails.js` (every 5-15 minutes).
 *      This module is then never loaded at all.
 *
 *   2. In-process (this module) — set RUN_JOBS_IN_PROCESS=true in .env and
 *      require+call start() once from server.js at boot (see the commented
 *      block near the bottom of server.js). Simpler to deploy (one process,
 *      no crontab to configure) but ties job execution to the web process's
 *      uptime — if you restart/redeploy the web app frequently, prefer
 *      option 1 instead so a deferred email doesn't miss its window during
 *      a brief restart.
 *
 * Either way the underlying job logic is identical — this file only adds
 * scheduling, never duplicates the jobs themselves.
 */

let started = false;
const tasks = [];

function start() {
  if (started) {
    return tasks;
  }
  started = true;

  // Daily at 06:00 IST — LUT/RCMC expiry escalation, FDN/dispute
  // overdue checks. QA batch 4, PAR-2: the same IST time as the PHP stack's
  // crontab entry (CRON_TZ=Asia/Kolkata, 0 6 * * *), so alerts reach staff
  // on the same morning on either stack.
  tasks.push(
    cron.schedule('0 6 * * *', () => {
      checkAlerts.run().catch((e) => logger.error('scheduler:checkAlerts', e));
    }, { timezone: 'Asia/Kolkata' })
  );

  // Every 10 minutes — deferred/approved email dispatch.
  tasks.push(
    cron.schedule('*/10 * * * *', () => {
      dispatchDeferredEmails.run().catch((e) => logger.error('scheduler:dispatchDeferredEmails', e));
    })
  );

  // Every 15 minutes — auto-confirm any Order Confirmation the buyer
  // hasn't acknowledged within 48 hours (docs/schema.sql Section AE).
  tasks.push(
    cron.schedule('*/15 * * * *', () => {
      autoConfirmOcAcknowledgments.run().catch((e) => logger.error('scheduler:autoConfirmOcAcknowledgments', e));
    })
  );

  // Daily at 08:15 IST — owner decision 1 Nov 2026 (C-7b): the per-person digest of what waits on each person.
  tasks.push(
    cron.schedule('15 8 * * *', () => {
      dailyDigest.run().catch((e) => logger.error('scheduler:dailyDigest', e));
    }, { timezone: 'Asia/Kolkata' })
  );

  // Hourly — CA / Accounting module (Phase 3): pushes any settlement leg
  // with a recorded INR actual but not yet synced to Zoho Books. No-ops
  // cleanly (one logged "skipped" row) when Zoho Books isn't configured.
  tasks.push(
    cron.schedule('0 * * * *', () => {
      zohoSync.run().catch((e) => logger.error('scheduler:zohoSync', e));
    }, { timezone: 'Asia/Kolkata', noOverlap: true }) // QA batch 4, ZOH-1: a long run never overlaps the next hour's
  );

  // Daily at 02:15 IST — owner points 5/8/9 (2 Nov 2026): Document copies clean-up.
  // Removes nothing while every rule in Admin → Settings → Document copies clean-up is off (the default).
  tasks.push(
    cron.schedule('15 2 * * *', () => {
      documentCopyCleanup.run().catch((e) => logger.error('scheduler:documentCopyCleanup', e));
    }, { timezone: 'Asia/Kolkata' })
  );

  console.log('[scheduler] in-process background jobs started (checkAlerts daily @ 06:00 IST, dispatchDeferredEmails every 10 min, autoConfirmOcAcknowledgments every 15 min, zohoSync hourly, dailyDigest daily @ 08:15 incl. associate reminders, documentCopyCleanup daily @ 02:15).');
  return tasks;
}

function stop() {
  for (const task of tasks) {
    task.stop();
  }
  tasks.length = 0;
  started = false;
}

module.exports = { start, stop };
