'use strict';

const backupStatusService = require('../services/backupStatusService');

// Port of App\Controllers\BackupController — D-260: Admin › Backups, read-only (manage_company_settings).
async function index(req, res) {
  const jobs = backupStatusService.jobs();
  const rows = Object.values(jobs).map((row) => ({
    ...row,
    finished_label: row.status ? formatWhen(row.status.finished_at) : null,
    ago_label: row.age_hours !== null ? backupStatusService.ago(row.age_hours) : null,
    size_label: row.status ? backupStatusService.humanSize(Number(row.status.size_bytes || 0)) : null,
  }));
  res.renderView('backups/index', { rows, settings: backupStatusService.settings(), problems: backupStatusService.problems() }, 'layout/base');
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
function formatWhen(iso) {
  const t = Date.parse(String(iso || '').replace(/([+-]\d{2})(\d{2})$/, '$1:$2'));
  if (Number.isNaN(t)) return '';
  // Shown in IST, like PHP's date() on the IST server.
  const d = new Date(t + 330 * 60000);
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getUTCDate()} ${MONTHS[d.getUTCMonth()]} ${d.getUTCFullYear()}, ${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}`;
}

module.exports = { index, formatWhen };
