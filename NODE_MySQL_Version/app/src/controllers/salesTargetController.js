'use strict';

const flash = require('../helpers/flash');
const reasonValidator = require('../helpers/reasonValidator');
const { todayIst } = require('../helpers/dbDateTime');
const salesTargetRepository = require('../repositories/salesTargetRepository');
const salesTargetService = require('../services/salesTargetService');

/**
 * Owner decision D-378 (15 Nov 2026): sales targets — the Targets page, its settings and the
 * MD / ED's edits. D-379: GET /sales-targets opens at the Department level of the "Sales targets"
 * report (read-only team view of the viewer's departments, views/sales_targets/team.njk); the full
 * page and every other route need the All level, which itself needs manage_sales_targets; money figures
 * (₹, margins, average margin per container) also need view_price_buildup
 * (salesTargetService.canSeeMoney()). Salary is never posted: the calculator on the Targets page
 * runs in the browser only, its inputs have no name and sit outside every form.
 *
 * Routes:
 *   GET  /sales-targets                    index()
 *   GET  /sales-targets/settings           settings()
 *   POST /sales-targets/settings           saveSettings()
 *   POST /sales-targets/person/:id         savePerson()
 *   POST /sales-targets/associate/:id      saveAssociate()
 *   POST /sales-targets/credit/:orderId    moveCredit()
 *
 * Port of App\Controllers\SalesTargetController.
 */

async function index(req, res) {
  salesTargetService.resetCache();
  await salesTargetService.sync();
  const settings = await salesTargetService.settings();
  const today = todayIst();
  const periods = salesTargetService.recentPeriods(String(settings.period), today, 4);
  let chosen = periods[0];
  for (const p of periods) {
    if (String(req.query.period ?? '') === p.key) chosen = p;
  }
  // Owner decision D-379: below All (Department level), the read-only team view of the viewer's departments —
  // containers only: no Edit, no money, no calculator, no associates, no settings link.
  const reportAccess = require('../services/reportAccess');
  if ((await reportAccess.level('sales_targets')) !== reportAccess.ALL) {
    const team = await salesTargetService.teamData(chosen, today);
    const members = new Set(await reportAccess.departmentMemberIds(Number(req.user.id)));
    res.renderView('sales_targets/team', {
      periods,
      period: chosen,
      rows: team.rows.filter((r) => members.has(Number(r.user.id))),
    }, 'layout/base');
    return;
  }
  const canMoney = salesTargetService.canSeeMoney(req.permissions);
  res.renderView('sales_targets/index', {
    periods,
    data: await salesTargetService.teamData(chosen, today),
    canMoney,
    // Printed for the calculator only to holders of view_price_buildup; otherwise typed.
    avgMargin: canMoney ? await salesTargetService.avgMarginPerContainer(today) : null,
  }, 'layout/base');
}

async function settings(req, res) {
  const canMoney = salesTargetService.canSeeMoney(req.permissions);
  const defaults = {};
  for (const [id, row] of await salesTargetRepository.targetsByScope('designation')) defaults[id] = row;
  res.renderView('sales_targets/settings', {
    settings: await salesTargetService.settings(),
    periodTypes: salesTargetService.PERIOD_TYPES,
    company: canMoney ? await salesTargetRepository.latestCompanyRow() : null,
    designations: await salesTargetRepository.designations(),
    defaults,
    canMoney,
  }, 'layout/base');
}

async function saveSettings(req, res) {
  const body = req.body || {};
  const reason = String(body.reason ?? '').trim();
  const error = reasonValidator.check(reason);
  if (error) {
    flash.set(req, 'error', error);
    res.redirect('/sales-targets/settings');
    return;
  }
  const canMoney = salesTargetService.canSeeMoney(req.permissions);
  const [values, errors] = salesTargetService.validateSettings(body, canMoney);
  if (errors.length) {
    flash.set(req, 'error', errors.join(' '));
    res.redirect('/sales-targets/settings');
    return;
  }
  const changes = await salesTargetService.saveSettings(values, canMoney, req.user.id, reason);
  flash.set(req, 'success', changes.length === 0 ? 'Nothing changed.' : `Sales target settings saved (${changes.join(', ')}).`);
  res.redirect('/sales-targets/settings');
}

/** Back to the period the form was sent from (?period=YYYY-MM-DD), when it is one. */
function periodQuery(req) {
  const p = String((req.body || {}).period ?? '');
  return /^\d{4}-\d{2}-01$/.test(p) ? `?period=${p}` : '';
}

async function savePerson(req, res) {
  const personId = parseInt(req.params.id, 10) || 0;
  if (!(await require('../repositories/userRepository').findById(personId))) {
    flash.set(req, 'error', 'Person not found.');
    res.redirect('/sales-targets');
    return;
  }
  const error = await salesTargetService.savePerson(personId, req.body || {}, req.user.id);
  flash.set(req, error === null ? 'success' : 'error', error ?? 'Target saved and marked reviewed today.');
  res.redirect(`/sales-targets${periodQuery(req)}`);
}

async function saveAssociate(req, res) {
  const associateId = parseInt(req.params.id, 10) || 0;
  if (!(await require('../repositories/associateRepository').find(associateId))) {
    flash.set(req, 'error', 'Associate not found.');
    res.redirect('/sales-targets');
    return;
  }
  const error = await salesTargetService.saveAssociate(associateId, req.body || {}, req.user.id);
  flash.set(req, error === null ? 'success' : 'error', error ?? 'Associate target saved.');
  res.redirect(`/sales-targets${periodQuery(req)}`);
}

async function moveCredit(req, res) {
  const orderId = parseInt(req.params.orderId, 10) || 0;
  if (!(await require('../repositories/orderRepository').find(orderId))) {
    flash.set(req, 'error', 'Order not found.');
    res.redirect('/sales-targets');
    return;
  }
  const body = req.body || {};
  const error = await salesTargetService.moveCredit(orderId, String(body.user_id ?? ''), String(body.reason ?? '').trim(), req.user.id);
  flash.set(req, error === null ? 'success' : 'error', error ?? 'Target credit moved.');
  res.redirect(`/sales-targets${periodQuery(req)}`);
}

module.exports = { index, settings, saveSettings, savePerson, saveAssociate, moveCredit };
