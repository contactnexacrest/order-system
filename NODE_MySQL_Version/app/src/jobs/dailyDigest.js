'use strict';

const db = require('../config/db');
const logger = require('../helpers/logger');
const dailyDigestService = require('../services/dailyDigestService');

/**
 * Owner decision 1 Nov 2026 (C-7b): the daily per-person digest. Run once
 * every morning, e.g. 08:15 IST (`node src/jobs/dailyDigest.js` from an
 * external cron, or the in-process scheduler). Safe to run twice: each
 * person gets at most one digest a day.
 */
async function run() {
  const result = await dailyDigestService.run();
  // Owner decision 1 Nov 2026 (AS-5, guarantee 7): associates' protection and agreement reminders (30 and 7 days), expired links.
  result.associateReminders = await require('../services/associateService').dailyReminders();
  // Owner decisions 11 Nov 2026 (D-293 / D-294): new bank details not confirmed in 7 days are cancelled (the old account stays);
  // supplier bank changes whose waiting days are over are put in use.
  result.bankExpired = await require('../services/associateBankConfirmation').expireOverdue();
  result.vendorBank = await require('../services/vendorBankChangeService').daily();
  // Owner decision D-245 P4: N days before each payout run, remind the MD of orders ready to lock but not locked.
  // D-264: associate commission fixed at full payment (catch-up), held / released for buyer claims, worked out again after a settlement, small abroad amounts carried.
  result.associatePayouts = await require('../services/associatePayoutService').daily();
  result.incentiveLockReminders = await require('../services/incentiveService').lockReminders();
  return result;
}

/** What the job prints for the cron e-mail / log — the same lines as PHP cron/daily_digest.php (QA MTX-007). */
function consoleLines(r) {
  return [
    // QA Round 8, R8-N02: say plainly when nothing could leave.
    dailyDigestService.summary(r),
    `Associate reminders: ${r.associateReminders} sent.`,
    `Bank changes: ${r.bankExpired} associate and ${r.vendorBank.expired} supplier change(s) cancelled (not confirmed); ${r.vendorBank.applied} supplier change(s) put in use.`,
    // QA MTX-007: the associate commission and incentive lock reminder results, as the PHP job prints them.
    `Associate commission: ${JSON.stringify(r.associatePayouts)}`,
    `Incentive lock reminders: ${r.incentiveLockReminders} sent.`,
  ];
}

if (require.main === module) {
  run()
    .then((r) => {
      for (const line of consoleLines(r)) console.log(line);
      return db.pool.end();
    })
    .catch((e) => {
      logger.error('daily_digest', e);
      process.exitCode = 1;
    });
}

module.exports = { run, consoleLines };
