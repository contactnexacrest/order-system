'use strict';

const fs = require('fs');
const env = require('../config/env');

// Port of App\Services\BackupStatusService (owner decisions D-260, 6 Nov 2026).
// The app only READS what the backup scripts (scripts/backup_db.sh, backup_storage.sh,
// backup_test_restore.sh) write to BACKUP_DIR/status/*.json; it never runs, changes or
// deletes a backup. Used by Admin › Backups and the daily digest.

const STALE_HOURS = 26;
const RESTORE_TEST_STALE_DAYS = 35;
const JOBS = {
  db: 'Database copy (nightly)',
  storage: 'Documents copy (nightly)',
  restore_test: 'Test-restore (monthly)',
};

let nowOverride = null;
/** Tests only: "now" as a Unix time (seconds), null restores the clock. */
function setNow(now) { nowOverride = now; }

function backupDir() { return String(env.get('BACKUP_DIR', '') || '').replace(/\/+$/, ''); }

function settings() {
  return {
    backup_dir: backupDir(),
    keep_years: String(env.get('BACKUP_KEEP_YEARS', '10')),
    thin_after_days: String(env.get('BACKUP_THIN_AFTER_DAYS', '120')),
    offsite_target: String(env.get('BACKUP_OFFSITE_TARGET', '') || ''),
    encryption_key_file: String(env.get('BACKUP_ENCRYPTION_KEY_FILE', '') || ''),
  };
}

function ago(hours) { return hours < 48 ? `${hours} hours ago` : `${Math.floor(hours / 24)} days ago`; }

function humanSize(bytes) {
  const units = ['bytes', 'KB', 'MB', 'GB', 'TB'];
  let v = Number(bytes) || 0;
  let i = 0;
  while (v >= 1024 && i < units.length - 1) { v /= 1024; i += 1; }
  return i === 0 ? `${bytes} ${units[0]}` : `${v.toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} ${units[i]}`;
}

/** One row per job: state ok | stale | failed | missing | not_set, with a plain message. */
function jobs() {
  const dir = backupDir();
  const now = nowOverride ?? Math.floor(Date.now() / 1000);
  const out = {};
  for (const [job, label] of Object.entries(JOBS)) {
    const row = { job, label, state: 'not_set', message: 'BACKUP_DIR is not set in app/.env — no backups are being made.', status: null, age_hours: null };
    if (dir !== '') {
      const file = `${dir}/status/${job}.json`;
      let status = null;
      try { status = JSON.parse(fs.readFileSync(file, 'utf8')); } catch (e) { status = null; }
      if (!status || typeof status !== 'object') {
        row.state = 'missing';
        row.message = 'No run recorded yet — check the cron line for this job.';
      } else {
        const t = Date.parse(String(status.finished_at || '').replace(/([+-]\d{2})(\d{2})$/, '$1:$2'));
        const finished = Number.isNaN(t) ? 0 : Math.floor(t / 1000);
        const ageHours = finished > 0 ? Math.floor((now - finished) / 3600) : null;
        const limit = job === 'restore_test' ? RESTORE_TEST_STALE_DAYS * 24 : STALE_HOURS;
        row.status = status;
        row.age_hours = ageHours;
        if ((status.result || '') !== 'ok') {
          row.state = 'failed';
          row.message = `Last run FAILED: ${status.message || ''}`;
        } else if (ageHours === null || ageHours > limit) {
          row.state = 'stale';
          row.message = `Last good run was ${ageHours === null ? 'at an unknown time' : ago(ageHours)} — the job has stopped running.`;
        } else {
          row.state = 'ok';
          row.message = String(status.message || '');
        }
      }
    }
    out[job] = row;
  }
  return out;
}

/** Warnings for the daily digest. "Not configured" warns only on a production server. */
function problems() {
  if (backupDir() === '') {
    return String(env.get('APP_ENV', 'production')).toLowerCase() === 'production'
      ? ['Backups are not set up: BACKUP_DIR is empty in app/.env, so no backup copies are being made.']
      : [];
  }
  const list = [];
  for (const row of Object.values(jobs())) {
    if (row.state !== 'ok') {
      list.push(`${row.label}: ${row.message}`);
    } else if (row.job !== 'restore_test' && (row.status && row.status.encrypted) !== 'yes') {
      list.push(`${row.label}: made, but NOT encrypted (set BACKUP_ENCRYPTION_KEY_FILE).`);
    }
    if (String((row.status && row.status.offsite) || '').startsWith('failed')) {
      list.push(`${row.label}: off-site copy ${row.status.offsite}.`);
    }
  }
  return list;
}

module.exports = { STALE_HOURS, RESTORE_TEST_STALE_DAYS, JOBS, setNow, backupDir, settings, jobs, problems, ago, humanSize };
