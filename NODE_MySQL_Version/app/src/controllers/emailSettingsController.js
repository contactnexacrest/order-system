'use strict';

const db = require('../config/db');
const flash = require('../helpers/flash');
const reasonValidator = require('../helpers/reasonValidator');
const emailPolicy = require('../services/emailPolicy');
const staffRouting = require('../services/staffRouting');

/**
 * Port of App\Controllers\EmailSettingsController — owner decisions D-280 / D-281
 * (11 Nov 2026, point 3): Admin › E-mail settings (permission
 * manage_company_settings). One row per e-mail, one switch per audience; MUST
 * e-mails locked on; testing mode; and, for company-level compliance alerts, the
 * responsible person and the backup per item. Every save needs a reason.
 */
async function index(req, res) {
  const staff = await db.query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name');
  const matrix = await emailPolicy.matrix();
  const groups = Object.entries(emailPolicy.GROUPS).map(([key, title]) => ({
    key,
    title,
    kinds: Object.entries(emailPolicy.KINDS).filter(([, def]) => def.group === key).map(([kind, def]) => {
      const tiers = Object.values(def.audiences);
      const tierLabel = tiers.includes('must') ? 'must' : (tiers.includes('should') ? 'should' : 'optional');
      return {
        kind,
        label: def.label,
        accessCritical: !!def.access_critical,
        tierLabel,
        cells: Object.entries(emailPolicy.AUDIENCES).map(([aud, audLabel]) => ({ aud, audLabel, cell: matrix[kind][aud] || null })),
      };
    }),
  }));
  const owners = await staffRouting.companyOwners();
  const items = Object.entries(staffRouting.COMPANY_ITEMS).map(([item, [label]]) => ({ item, label, owner: owners[item] }));
  res.renderView('settings/email_settings', {
    groups,
    audiences: Object.values(emailPolicy.AUDIENCES),
    testingMode: await emailPolicy.testingMode(),
    items,
    staff,
  });
}

async function save(req, res) {
  const body = req.body || {};
  const reason = String(body.reason || '').trim();
  const error = reasonValidator.check(reason);
  if (error) {
    flash.set(req, 'error', error);
    res.redirect('/admin/email-settings');
    return;
  }
  const on = body.on && typeof body.on === 'object' ? body.on : {};
  let changes = await emailPolicy.save(on, String(body.testing_mode || '0') === '1', req.user.id, reason);
  const owners = body.owners && typeof body.owners === 'object' ? body.owners : {};
  changes = changes.concat((await staffRouting.saveCompanyOwners(owners, req.user.id, reason)).map((l) => `Who is told: ${l}`));
  flash.set(req, 'success', changes.length ? `Saved: ${changes.join('; ')}.` : 'Nothing changed.');
  res.redirect('/admin/email-settings');
}

module.exports = { index, save };
