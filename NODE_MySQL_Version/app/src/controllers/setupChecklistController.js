'use strict';

const flash = require('../helpers/flash');
const setupChecklist = require('../services/setupChecklist');

/**
 * Owner decision D-312 (point 4 B, 11 Nov 2026): Admin › Setup checklist
 * (manage_company_settings). Port of App\Controllers\SetupChecklistController.
 */
const BACK = '/admin/setup-checklist';

async function index(req, res) {
  const items = await setupChecklist.items(req);
  const recommendedText = Object.values(setupChecklist.RECOMMENDED).map(([label, value]) => `${label} = ${value}`).join(' · ');
  res.renderView('settings/setup_checklist', {
    items,
    neededCount: items.filter((i) => i.status === 'needed').length,
    differences: await setupChecklist.differences(),
    recommendedText,
    year: parseInt(require('../helpers/dbDateTime').todayIst().slice(0, 4), 10),
  }, 'layout/base');
}

async function tick(req, res) {
  const body = req.body || {};
  const on = String(body.ticked || '') === '1';
  const error = await setupChecklist.setTick(String(body.item_key || ''), on, String(body.note || ''), Number(req.user.id), req.permissions);
  flash.set(req, error === null ? 'success' : 'error', error || (on ? 'Ticked — recorded with your name and the date.' : 'Tick removed.'));
  res.redirect(BACK);
}

async function applyRecommended(req, res) {
  const body = req.body || {};
  const raw = body['keys[]'] !== undefined ? body['keys[]'] : body.keys;
  const keys = (Array.isArray(raw) ? raw : (raw !== undefined ? [raw] : [])).map(String).filter((k) => setupChecklist.RECOMMENDED[k]);
  const error = await setupChecklist.applyRecommended(keys, String(body.reason || ''), Number(req.user.id));
  flash.set(req, error === null ? 'success' : 'error', error || 'Recommended values applied — each change is in the Audit Log with your reason.');
  res.redirect(`${BACK}#recommended`);
}

async function nationalHolidays(req, res) {
  const added = await setupChecklist.addNationalHolidays(parseInt(require('../helpers/dbDateTime').todayIst().slice(0, 4), 10), Number(req.user.id));
  flash.set(req, 'success', added > 0 ? `${added} national holiday(s) added to the Holiday Calendar.` : 'The national holidays were already in the Holiday Calendar.');
  res.redirect(BACK);
}

module.exports = { index, tick, applyRecommended, nationalHolidays };
