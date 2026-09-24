'use strict';

const env = require('../config/env'); // loads .env as a side effect — must run first
const orderOcAcknowledgmentRepository = require('../repositories/orderOcAcknowledgmentRepository');
const stageGateService = require('../services/stageGateService');
const auditLogRepository = require('../repositories/auditLogRepository');
const db = require('../config/db');
const logger = require('../helpers/logger');

/**
 * Port of app/cron/auto_confirm_oc_acknowledgments.php (docs/schema.sql
 * Section AE) — the buyer has 48 hours from the Order Confirmation being
 * emailed to either acknowledge it in their portal or reply to the email
 * (which staff record). If neither happens in that window, it
 * auto-confirms so the order isn't stuck waiting on a buyer who never
 * responds — Stage 5 (Supplier PO) unlocks exactly as if the buyer had
 * clicked "I acknowledge" themselves.
 *
 * Same deployment story as dispatchDeferredEmails.js: runnable directly
 * for an external cron/systemd timer, or scheduled in-process from
 * scheduler.js.
 */

async function run() {
  const due = await orderOcAcknowledgmentRepository.dueForAutoConfirm();
  let confirmedCount = 0;

  for (const row of due) {
    const orderId = row.order_id;
    await orderOcAcknowledgmentRepository.markAcknowledged(orderId, 'auto_48h', null, null);
    await stageGateService.passAndUnlockNext(orderId, 4, null);
    await auditLogRepository.log(null, 'OC_AUTO_CONFIRMED', 'orders', orderId, null, null, null, 'Buyer did not respond within 48 hours of the Order Confirmation being emailed — auto-confirmed.');
    confirmedCount++;
  }

  return { checked: due.length, confirmed: confirmedCount };
}

if (require.main === module) {
  run()
    .then(({ checked, confirmed }) => {
      console.log(`[auto_confirm_oc_acknowledgments] checked ${checked} due row(s): ${confirmed} auto-confirmed.`);
      return db.pool.end();
    })
    .catch((e) => {
      logger.error('auto_confirm_oc_acknowledgments', e);
      process.exitCode = 1;
    });
}

module.exports = { run };
