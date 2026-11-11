'use strict';

const agreementRepository = require('../repositories/agreementRepository');
const notificationRepository = require('../repositories/notificationRepository');
const permissionRepository = require('../repositories/permissionRepository');

/**
 * Port of AgreementAlertService.php. Owner decision 2 Nov 2026 (point 4): a reminder to everyone who
 * manages agreements 30 and 7 days before an agreement expires and on the expiry day — once per
 * threshold, never repeated by a re-run. Covers the register's own agreements and the client
 * commercial agreements (no reminder before). Run from jobs/checkAlerts.js.
 */

function message(row, daysLeft) {
  const what = `${row.agreement_type} with ${agreementRepository.PARTY_TYPES[row.party_type] || row.party_type} ${row.party_name}`;
  const when = daysLeft === 0 ? `expires TODAY (${row.expires_on})` : `expires in ${daysLeft} day(s) (${row.expires_on})`;
  return `Agreement ${when}: ${what}. Renew it or record that it ends — Agreements register.`;
}

async function run(today = null) {
  const t = today || agreementRepository.todayStr();
  const recipients = [...new Set((await permissionRepository.usersWithPermission('manage_agreements')).map(Number))];
  if (recipients.length === 0) return 0;
  let created = 0;
  for (const row of await agreementRepository.all(t)) {
    if (!row.expires_on || row.status === 'terminated' || row.status === 'renewed' || row.days_left === null) continue;
    const days = Number(row.days_left);
    let threshold = null;
    for (const th of agreementRepository.ALERT_DAYS) {
      if (days <= th && days >= 0) threshold = th; // the smallest threshold reached
    }
    if (threshold === null || await agreementRepository.alertAlreadySent(row.key, row.expires_on, threshold)) continue;
    const text = message(row, days);
    // D-281: e-mailed to the responsible person and backup for agreements (Admin › E-mail settings).
    created += (await notificationRepository.notifyRouted(recipients, await require('./staffRouting').companyItem('agreements'), 'agreement_expiry', null, text)).length;
    await agreementRepository.markAlertSent(row.key, row.expires_on, threshold);
  }
  return created;
}

module.exports = { run, message };
