'use strict';

// Port of app/cron/fetch_fx_rate.php — owner decision D-301 (point 4, 11 Nov 2026): the daily
// USD → INR exchange rate. Fetches today's market rate from Frankfurter (European Central Bank
// rates), or from open.er-api.com when that fails, and keeps it in the history (fx_rates). A fetch
// that fails keeps the rate in use (Pricing settings shows a warning); a change of more than 2 %
// waits for the MD's OK. Prices on issued Quotations and Proforma Invoices never change.
//
// The server must be able to reach api.frankfurter.app and open.er-api.com (HTTPS).
//
// Runs in-process from scheduler.js (daily 07:05 IST) or directly:
//   node src/jobs/fetchFxRate.js

require('../config/env'); // loads .env as a side effect when run directly — must run first
const db = require('../config/db');
const logger = require('../helpers/logger');
const fxRateService = require('../services/fxRateService');

async function run() {
  return fxRateService.fetchDaily();
}

if (require.main === module) {
  run()
    .then((r) => {
      console.log(r.message);
      if (r.status === 'failed') process.exitCode = 1;
      return db.pool.end();
    })
    .catch((e) => {
      logger.error('fetch_fx_rate', e);
      process.exitCode = 1;
    });
}

module.exports = { run };
