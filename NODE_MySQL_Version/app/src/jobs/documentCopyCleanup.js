'use strict';

// Port of app/cron/document_copy_cleanup.php — owner points 5 / 8 / 9 (2 Nov 2026):
// daily Document copies clean-up. Removes what Admin → Settings → Document copies
// clean-up lists in its preview — nothing at all while every rule there is off
// (the default). See services/documentCopyCleanupService.js for what is never removed.
//
// Runs in-process from scheduler.js (daily 02:15 IST) or directly:
//   node src/jobs/documentCopyCleanup.js

require('../config/env'); // loads .env as a side effect when run directly — must run first
const db = require('../config/db');
const logger = require('../helpers/logger');
const cleanup = require('../services/documentCopyCleanupService');

async function run() {
  const result = await cleanup.run(null, 'scheduled');
  if (result.skipped_off) {
    return 'Document copies clean-up: every rule is off — nothing removed.';
  }
  return `Document copies clean-up: ${result.files} copy(ies) removed, ${cleanup.formatBytes(result.bytes)} freed`
    + `${result.missing > 0 ? ` (${result.missing} file(s) were already missing from storage)` : ''}.`;
}

if (require.main === module) {
  run()
    .then((line) => {
      console.log(line);
      return db.pool.end();
    })
    .catch((e) => {
      logger.error('document_copy_cleanup', e);
      process.exitCode = 1;
    });
}

module.exports = { run };
