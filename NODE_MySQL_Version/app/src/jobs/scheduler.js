'use strict';

const cron = require('node-cron');
const checkAlerts = require('./checkAlerts');
const dispatchDeferredEmails = require('./dispatchDeferredEmails');
const autoConfirmOcAcknowledgments = require('./autoConfirmOcAcknowledgments');
const logger = require('../helpers/logger');

/**
 * Optional in-process alternative to external OS-level cron/systemd timers
 * for the two background jobs this app needs (see checkAlerts.js and
 * dispatchDeferredEmails.js — both are also independently runnable via
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

  // Daily at 02:00 server time — LUT/RCMC expiry escalation, FDN/dispute
  // overdue checks. Matches the deployment guide's suggested crontab entry
  // for the external-cron option, so behavior is the same either way.
  tasks.push(
    cron.schedule('0 2 * * *', () => {
      checkAlerts.run().catch((e) => logger.error('scheduler:checkAlerts', e));
    })
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

  console.log('[scheduler] in-process background jobs started (checkAlerts daily @ 02:00, dispatchDeferredEmails every 10 min, autoConfirmOcAcknowledgments every 15 min).');
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
