'use strict';

const { spawn } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const env = require('../config/env');

/**
 * Port of App\Services\MysqlDumpService (PHP). Point 6 of the "fresh
 * install must never lose data" request: a Super Admin (or a
 * permission-gated staff member) needs to pull the live database out as
 * two separate, portable files before a reinstall — structure and data
 * kept apart on purpose.
 *
 * Why two files instead of one combined dump: after a reinstall, the new
 * code's own docs/schema.sql already creates the up-to-date structure
 * (including any columns/tables added since this export was taken). The
 * structure file exported here is a point-in-time reference an operator
 * diffs against that new schema.sql before importing data, to confirm
 * nothing the old data depends on was renamed, dropped, or shrunk — see
 * docs/SOP/18-data-export-migration.md for the full restore procedure.
 * The data file is what actually gets imported, straight into the
 * freshly-installed (structurally current) database.
 *
 * Shells out to the real `mysqldump` binary via spawn() (never a shell —
 * arguments are passed as an array, so nothing needs escaping and no
 * argument can be interpreted as a second command). The DB password goes
 * in via the MYSQL_PWD environment variable rather than a command-line
 * flag, so it never shows up in a process listing. stdout is piped
 * straight to a temp file rather than buffered in memory, since a full
 * database dump can be far larger than Node's default buffer limits.
 */

function dumpSchema() {
  return run(['--no-data', '--skip-triggers', '--skip-comments', '--skip-add-locks']);
}

function dumpData() {
  return run(['--no-create-info', '--skip-triggers', '--single-transaction', '--complete-insert', '--hex-blob', '--skip-comments']);
}

/** @returns {Promise<string>} absolute path to a temp file the caller must unlink() after streaming it */
function run(extraArgs) {
  return new Promise((resolve, reject) => {
    const host = env.get('DB_HOST', '127.0.0.1');
    const port = env.getInt('DB_PORT', 3306);
    const db = env.get('DB_DATABASE');
    const user = env.get('DB_USERNAME');
    const pass = env.get('DB_PASSWORD', '');

    const args = ['-h', host, '-P', String(port), '-u', user, ...extraArgs, db];
    const tmpPath = path.join(os.tmpdir(), `nexacrest_dump_${crypto.randomBytes(8).toString('hex')}.sql`);
    const out = fs.createWriteStream(tmpPath);

    const child = spawn('mysqldump', args, {
      env: pass !== '' ? { ...process.env, MYSQL_PWD: pass } : process.env,
    });

    let stderr = '';
    child.stderr.on('data', (chunk) => {
      stderr += chunk.toString();
    });
    child.stdout.pipe(out);

    child.on('error', (err) => {
      out.close();
      fs.unlink(tmpPath, () => {});
      reject(err);
    });

    child.on('close', (code) => {
      out.close(() => {
        if (code !== 0) {
          fs.unlink(tmpPath, () => {});
          reject(new Error(`mysqldump failed (exit ${code}): ${stderr}`));
          return;
        }
        resolve(tmpPath);
      });
    });
  });
}

module.exports = { dumpSchema, dumpData };
