'use strict';

const db = require('../config/db');
const logger = require('../helpers/logger');
const prospectService = require('../services/prospectService');

/**
 * Owner decision D-394 (20 Nov 2026): quick-quote prospects, once a day
 * (06:40 IST — `node src/jobs/prospectExpiry.js` from an external cron, or
 * the in-process scheduler). Port of PHP cron/prospect_expiry.php.
 *  1. Reminder: N days (Settings › Quick-quote prospects, default 7) before a
 *     prospect's quotation expires, its order's owner gets ONE notification
 *     (Notifications + My tasks) and e-mail.
 *  2. Automatic closing (when On): on the date, each still-active order moves
 *     to "Did not go ahead" (Mark Lost's own service and refusal rules; never
 *     past the quotation stage) and the client is deactivated.
 * Nothing is ever deleted. Safe to run twice a day.
 */
async function run() {
  const reminded = await prospectService.sendReminders();
  const closed = await prospectService.expireDue();
  return { reminded, ...closed, autoClose: await prospectService.autoClose() };
}

/** The same lines as PHP cron/prospect_expiry.php prints. */
function consoleLines(r) {
  return [
    `Prospect reminders: ${r.reminded} sent.`,
    `Prospects closed automatically: ${r.clients} client(s), ${r.orders} order(s) moved to "Did not go ahead".${r.autoClose ? '' : ' (Automatic closing is off — only reminders.)'}`,
    ...r.skipped.map((line) => `Left as it is: ${line}`),
  ];
}

if (require.main === module) {
  run()
    .then((r) => {
      for (const line of consoleLines(r)) console.log(line);
      return db.pool.end();
    })
    .catch((e) => {
      logger.error('prospect_expiry', e);
      process.exitCode = 1;
    });
}

module.exports = { run, consoleLines };
