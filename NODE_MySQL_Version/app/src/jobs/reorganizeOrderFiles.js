'use strict';

const db = require('../config/db');
const orderFileReorganizer = require('../services/orderFileReorganizer');

/**
 * Port of cron/reorganize_order_files.php. One-off (re-runnable) tidy-up:
 * moves every order's existing files into the step-named folders the
 * order page uses (helpers/orderStorage — 01-quotation/, 04-buyer-po/,
 * 10-packing-and-bl/, ...) and updates file_store.server_path to match.
 * New files already go to the right folder; this brings older ones in
 * line. See services/orderFileReorganizer for the rules.
 *
 * Usage:
 *   node src/jobs/reorganizeOrderFiles.js           # dry run — lists every planned move, changes nothing
 *   node src/jobs/reorganizeOrderFiles.js --apply   # performs the moves
 *
 * Take a backup of the storage folder and the database first. Safe to run
 * again: files already in place are skipped.
 */
async function run(applyChanges) {
  const planned = await orderFileReorganizer.plan();
  const moves = planned.filter((p) => p.to !== null);
  const left = planned.filter((p) => p.to === null && p.note !== 'already in place');

  for (const p of moves) {
    console.log(`move  #${p.file_id}  ${p.from}\n      -> ${p.to}   (${p.note})`);
  }
  for (const p of left) {
    console.log(`leave #${p.file_id}  ${p.from}   (${p.note})`);
  }
  console.log(`\n${planned.length} order file(s): ${moves.length} to move, ${planned.length - moves.length - left.length} already in place, ${left.length} left as they are.`);

  if (!applyChanges) {
    console.log('Dry run — nothing changed. Run again with --apply to move the files.');
    return 0;
  }
  const result = await orderFileReorganizer.apply(planned);
  for (const error of result.errors) {
    console.log(`! ${error}`);
  }
  console.log(`Done: ${result.moved} moved, ${result.missing} missing on disk, ${result.failed} failed.`);
  return result.failed > 0 ? 1 : 0;
}

if (require.main === module) {
  run(process.argv.includes('--apply'))
    .then((code) => {
      process.exitCode = code;
      return db.pool.end();
    })
    .catch((e) => {
      console.error(e);
      process.exitCode = 1;
      return db.pool.end();
    });
}

module.exports = { run };
