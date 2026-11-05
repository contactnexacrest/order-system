'use strict';

const db = require('../config/db');
const csv = require('../helpers/csv');
const dates = require('../helpers/dates');
const flash = require('../helpers/flash');
const associateBankRepository = require('../repositories/associateBankRepository');
const associateService = require('../services/associateService');
const auditLogRepository = require('../repositories/auditLogRepository');
const incentiveRepository = require('../repositories/incentiveRepository');
const orderRepository = require('../repositories/orderRepository');
const userRepository = require('../repositories/userRepository');
const incentiveService = require('../services/incentiveService');
const associateTds = require('../services/associateTds');
const associateDocumentRepository = require('../repositories/associateDocumentRepository');
const associateRepository = require('../repositories/associateRepository');
const fileUploadService = require('../services/fileUploadService');
const fieldValidator = require('../helpers/fieldValidator');

/**
 * Port of IncentiveController.php. Team incentive program and associates
 * (owner decision 2026-10-30, mockups approved). Settings, associates and
 * each order's incentive: manage_incentives (you and the MD). Payouts:
 * pay_incentives (Accounts) — person, order and amount only. My
 * Incentives: view_own_incentives — a person's own amounts, once the buyer
 * has fully paid.
 */

const isNum = (v) => v !== undefined && v !== null && String(v).trim() !== '' && !Number.isNaN(Number(v)) && Number.isFinite(Number(v));
const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

function todayIso() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function asArray(v) {
  if (v === undefined || v === null) return [];
  if (Array.isArray(v)) return v;
  if (typeof v === 'object') return Object.values(v);
  return [v];
}

/** PHP's rtrim(rtrim(number_format($v, 2), '0'), '.') */
function trimPct(v) {
  return Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).replace(/0+$/, '').replace(/\.$/, '');
}

const canManagePricing = (req) => !!(req.user && req.permissions && req.permissions.manage_pricing);

function back(req, res, orderId, type, message) {
  flash.set(req, type, message);
  res.redirect(`/orders/${orderId}#financials-12d`);
}

async function settings(req, res) {
  const roles = await incentiveRepository.allRoles();
  const roleTotal = Object.values(roles).reduce((sum, r) => sum + (r.is_active ? r.share_pct : 0), 0);
  res.renderView('incentives/settings', {
    settings: await incentiveService.settings(),
    roles: Object.values(roles).map((r) => ({ ...r, shareText: trimPct(r.share_pct) })),
    activeRoles: Object.entries(await incentiveRepository.roles()).map(([key, label]) => ({ key, label })),
    roleTotalText: trimPct(roleTotal),
    runLabel: require('../services/associateCommission').runLabel(await require('../services/associateCommission').settings()),
    users: await userRepository.listActive(),
    userRoles: await incentiveRepository.userRoles(),
    canManagePricing: canManagePricing(req),
    readyCount: (await incentiveService.readyToLock()).length,
  }, 'layout/base');
}

/**
 * The roles table from the form: label, share %, active; a new role from
 * new_role_label / new_role_share. Active shares must add up to 100%.
 */
async function parseRoles(body) {
  const errors = [];
  const rows = [];
  const all = await incentiveRepository.allRoles();
  const posted = body.roles || {};
  let order = 0;
  for (const [key, r] of Object.entries(all)) {
    const input = posted[key] || {};
    const label = String(input.label ?? r.label).trim();
    const share = input.share_pct ?? String(r.share_pct);
    if (label === '' || [...label].length > 80) {
      errors.push('Every role needs a name (up to 80 characters).');
      break;
    }
    if (!isNum(share) || Number(share) < 0 || Number(share) > 100) {
      errors.push('Enter every role\'s usual share as a % from 0 to 100.');
      break;
    }
    rows.push({ role_key: key, label, share_pct: Math.round(Number(share) * 100) / 100, is_active: body.roles ? !!input.is_active : r.is_active, sort_order: ++order });
  }
  const newLabel = String(body.new_role_label || '').trim();
  if (newLabel !== '') {
    const newShare = body.new_role_share;
    const key = newLabel.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
    if (key === '' || [...newLabel].length > 80) {
      errors.push('Give the new role a name (letters or numbers, up to 80 characters).');
    } else if (all[key] || key === 'associate') {
      errors.push(`A role named "${newLabel}" already exists.`);
    } else if (!isNum(newShare) || Number(newShare) < 0 || Number(newShare) > 100) {
      errors.push('Enter the new role\'s usual share as a % from 0 to 100.');
    } else {
      rows.push({ role_key: key.slice(0, 40), label: newLabel, share_pct: Math.round(Number(newShare) * 100) / 100, is_active: true, sort_order: ++order });
    }
  }
  const total = rows.reduce((sum, r) => sum + (r.is_active ? r.share_pct : 0), 0);
  if (errors.length === 0 && Math.abs(total - 100) > 0.001) {
    errors.push(`The usual shares of the active roles must add up to 100% (they add up to ${trimPct(total)}%).`);
  }
  return [rows, errors];
}

/**
 * Owner decision D-245 P4: the program switch, the reminder days, the roles
 * (an editable list whose active shares add up to 100%; changing them is the
 * MD's — manage_pricing) and each person's roles.
 */
async function saveSettings(req, res) {
  const userId = req.user.id;
  const body = req.body || {};
  let errors = [];
  const reminder = String(body.lock_reminder_days ?? '3');
  if (!/^\d+$/.test(reminder) || Number(reminder) > 28) {
    errors.push('Enter the reminder before a payout run as 0 to 28 days (0 = no reminder).');
  }
  let roleRows = null;
  if (body.roles || String(body.new_role_label || '').trim() !== '') {
    if (!canManagePricing(req)) {
      errors.push('Only the MD can change the incentive roles and their shares.');
    } else {
      const [rows, roleErrors] = await parseRoles(body);
      roleRows = rows;
      errors = errors.concat(roleErrors);
    }
  }
  if (errors.length) {
    flash.set(req, 'error', errors.join(' '));
    res.redirect('/incentives/settings');
    return;
  }
  const old = await incentiveRepository.settings();
  const values = { program_enabled: body.program_enabled ? '1' : '0', lock_reminder_days: String(Number(reminder)) };
  for (const [key, value] of Object.entries(values)) {
    if ((old[key] ?? null) !== value) {
      await incentiveRepository.setSetting(key, value, userId);
      await auditLogRepository.log(userId, 'INCENTIVE_SETTING_CHANGED', 'incentive_settings', null, key, old[key] ?? null, value);
    }
  }
  if (roleRows !== null) {
    const describe = (all) => Object.values(all).map((r) => `${r.label} ${r.share_pct}%${r.is_active ? '' : ' (inactive)'}`);
    const before = describe(await incentiveRepository.allRoles());
    await incentiveRepository.saveRoles(roleRows, userId);
    const after = describe(await incentiveRepository.allRoles());
    if (JSON.stringify(before) !== JSON.stringify(after)) {
      await auditLogRepository.log(userId, 'INCENTIVE_ROLES_EDITED', 'incentive_roles', null, 'roles', JSON.stringify(before), JSON.stringify(after));
    }
  }
  if (body.user_roles_present) {
    const active = await incentiveRepository.roles();
    const rolesByUser = {};
    // Keys are "u<user id>": a bare number would make the form parser read the field as a (compacted) list.
    for (const [key, list] of Object.entries(body.user_roles || {})) {
      const uid = Number(String(key).replace(/^u/, ''));
      if (Number.isInteger(uid) && uid > 0) rolesByUser[uid] = asArray(list).filter((r) => active[r]);
    }
    const current = await incentiveRepository.userRoles();
    const norm = (m) => JSON.stringify(Object.keys(m).filter((k) => (m[k] || []).length).sort().map((k) => [Number(k), [...m[k]].sort()]));
    if (norm(rolesByUser) !== norm(current)) {
      await incentiveRepository.replaceUserRoles(rolesByUser);
      await auditLogRepository.log(userId, 'INCENTIVE_ROLES_CHANGED', 'user_incentive_roles', null, null, null, JSON.stringify(rolesByUser));
    }
  }
  flash.set(req, 'success', 'Team incentive settings saved.');
  res.redirect('/incentives/settings');
}

/** Deletes an incentive role that was never used; a used role can only be deactivated. */
async function deleteRole(req, res) {
  const key = String(req.params.key || '');
  const all = await incentiveRepository.allRoles();
  if (!canManagePricing(req)) {
    flash.set(req, 'error', 'Only the MD can change the incentive roles.');
  } else if (!all[key]) {
    flash.set(req, 'error', 'That role does not exist.');
  } else if (await incentiveRepository.roleInUse(key)) {
    flash.set(req, 'error', `The role "${all[key].label}" is in use (people, orders or payouts) — deactivate it instead.`);
  } else if (all[key].is_active && all[key].share_pct > 0) {
    flash.set(req, 'error', `Set the role "${all[key].label}" to 0% or deactivate it first, so the active shares still add up to 100%.`);
  } else {
    await incentiveRepository.deleteRole(key);
    await auditLogRepository.log(req.user.id, 'INCENTIVE_ROLE_DELETED', 'incentive_roles', all[key].id, 'label', all[key].label, null);
    flash.set(req, 'success', `Role "${all[key].label}" deleted.`);
  }
  res.redirect('/incentives/settings');
}

/**
 * Owner decision D-245 P4: the order's own team incentive (MD only, with a
 * reason) — the global rule, a % of the order's profit or a fixed amount —
 * and, D-245 P3, the associate's commission mode.
 */
async function saveOrder(req, res) {
  const orderId = parseInt(req.params.id, 10);
  if (!(await orderRepository.find(orderId))) {
    res.status(404).send('Order not found.');
    return;
  }
  if (!canManagePricing(req)) return back(req, res, orderId, 'error', 'Only the MD can change an order\'s incentive.');
  const row = await incentiveRepository.forOrder(orderId);
  if (row && row.locked_at) return back(req, res, orderId, 'error', 'This order\'s incentive is locked. Unlock it (with a reason) to change it.');
  const body = req.body || {};
  const fields = {};
  const mode = String(body.incentive_mode ?? ((row && row.incentive_mode) || 'global'));
  const rawValue = String(body.incentive_value ?? '').trim();
  const reason = String(body.incentive_reason || '').trim();
  if (!['global', 'pct', 'fixed'].includes(mode)) return back(req, res, orderId, 'error', 'Choose how the team incentive is set for this order.');
  let value = null;
  if (mode !== 'global') {
    if (!isNum(rawValue) || Number(rawValue) < 0 || (mode === 'pct' && Number(rawValue) > 100) || Number(rawValue) > 100000000) {
      return back(req, res, orderId, 'error', mode === 'pct' ? 'Enter the % of the order\'s profit (0 to 100).' : 'Enter the incentive for this order as an amount of 0 or more.');
    }
    value = mode === 'pct' ? Math.round(Number(rawValue) * 10000) / 10000 : Math.round(Number(rawValue) * 100) / 100;
  }
  const old = incentiveService.orderRule(row);
  const amountChanged = mode !== old.mode || (value !== null && Math.abs(value - Number(old.value || 0)) > 0.00001);
  if (amountChanged) {
    if ([...reason].length < 10 || [...reason].length > 500) {
      return back(req, res, orderId, 'error', 'Say why the team incentive is set this way for this order (10 to 500 characters).');
    }
    if (mode !== 'global') {
      const calc = await incentiveService.calculate(orderId);
      const amount = mode === 'fixed' ? value : incentiveService.round2(calc.basis * value / 100);
      if (amount > calc.basis + 0.005) {
        return back(req, res, orderId, 'error', `The team incentive (₹${incentiveService.fmt2(amount)}) cannot be more than the order's profit after the associate's commission (₹${incentiveService.fmt2(calc.basis)}).`);
      }
    }
    Object.assign(fields, { incentive_mode: mode, incentive_value: value, incentive_reason: mode === 'global' ? null : reason });
  }
  // Owner decision D-245 P3: forcing an associate's commission on ("Yes", e.g. on a repeat order)
  // is the MD's decision alone, with a written reason; it is audited.
  if (body.associate_mode !== undefined) {
    const aMode = String(body.associate_mode);
    if (!['auto', 'yes', 'no'].includes(aMode)) return back(req, res, orderId, 'error', 'Choose whether the associate earns commission on this order.');
    const wasYes = ((row && row.associate_mode) || 'auto') === 'yes';
    const modeReason = String(body.associate_mode_reason || '').trim();
    if (aMode === 'yes' && (!wasYes || modeReason !== String((row && row.associate_mode_reason) || ''))) {
      if (!(await associateService.canChangeLink(req))) return back(req, res, orderId, 'error', 'Only the MD can force the associate\'s commission on for an order.');
      if ([...modeReason].length < 10 || [...modeReason].length > 300) {
        return back(req, res, orderId, 'error', 'Say why the associate\'s commission is forced on for this order (10 to 300 characters).');
      }
      fields.associate_mode_reason = modeReason;
      fields.associate_mode_set_by = req.user.id;
      await auditLogRepository.log(req.user.id, 'ASSOCIATE_COMMISSION_FORCED', 'orders', orderId, 'associate_mode', String((row && row.associate_mode) || 'auto'), 'yes', modeReason);
    } else if (aMode !== 'yes' && wasYes) {
      fields.associate_mode_reason = null;
      fields.associate_mode_set_by = null;
    }
    if (aMode !== ((row && row.associate_mode) || 'auto')) {
      fields.associate_mode = aMode;
      await incentiveRepository.addHistory(orderId, 'associate_mode', String((row && row.associate_mode) || 'auto'), aMode, aMode === 'yes' ? modeReason : null, req.user.id);
    }
  }
  if (Object.keys(fields).length === 0) return back(req, res, orderId, 'warning', 'Nothing changed.');
  await incentiveRepository.saveOrder(orderId, fields, req.user.id);
  if (amountChanged) {
    const describe = (r) => (r.mode === 'fixed' ? `fixed ₹${incentiveService.fmt2(r.value)}`
      : r.mode === 'pct' ? `${String(Number(r.value).toFixed(4)).replace(/0+$/, '').replace(/\.$/, '')}% of profit` : 'global rule');
    await incentiveRepository.addHistory(orderId, 'amount', describe(old), describe({ mode, value }), reason, req.user.id);
  }
  const before = row ? Object.fromEntries(Object.keys(fields).map((k) => [k, row[k] ?? null])) : null;
  await auditLogRepository.log(req.user.id, 'ORDER_INCENTIVE_SET', 'orders', orderId, 'incentive', before ? JSON.stringify(before) : null, JSON.stringify(fields));
  return back(req, res, orderId, 'success', 'Team incentive for this order saved.');
}

/**
 * Owner decision D-245 P4: who gets it on this order — a tick per role, the
 * people on each role and, optionally, the shares on this order (they must
 * add up to 100% across the ticked roles).
 */
async function saveOrderPeople(req, res) {
  const orderId = parseInt(req.params.id, 10);
  if (!canManagePricing(req)) return back(req, res, orderId, 'error', 'Only the MD can change who gets an order\'s incentive.');
  const row = await incentiveRepository.forOrder(orderId);
  if (row && row.locked_at) return back(req, res, orderId, 'error', 'This order\'s incentive is locked. Unlock it (with a reason) to change it.');
  const body = req.body || {};
  const describe = (sp) => Object.values(sp.roles).map((r) => `${r.label}: ${r.gets_it ? `${trimPct(r.pct)}% ${r.people.map((p) => p.name).join(', ')}` : 'no'}`).join('; ');
  const before = describe(await incentiveService.split(orderId, 100));
  if (body.reset) {
    await incentiveRepository.replaceOrderPeople(orderId, {});
    await incentiveRepository.replaceOrderRoles(orderId, {});
    await incentiveRepository.saveOrder(orderId, { people_customised: 0, shares_customised: 0 }, req.user.id);
  } else {
    const active = (await userRepository.listActive()).map((u) => Number(u.id));
    const custom = !!body.shares_customised;
    const roleMap = {};
    const people = {};
    let total = 0;
    const postedPeople = body.people || {};
    const gets = body.gets_it || {};
    const shares = body.share_pct || {};
    for (const role of Object.keys(await incentiveRepository.roles())) {
      people[role] = asArray(postedPeople[role]).map((v) => parseInt(v, 10)).filter((v) => active.includes(v));
      const ticked = !!gets[role];
      let share = null;
      if (custom && ticked) {
        const raw = shares[role];
        if (!isNum(raw) || Number(raw) < 0 || Number(raw) > 100) return back(req, res, orderId, 'error', 'Enter the share of every ticked role as a % from 0 to 100.');
        share = Math.round(Number(raw) * 100) / 100;
        total += share;
      }
      roleMap[role] = { gets_it: ticked, share_pct: share };
    }
    if (custom && Math.abs(total - 100) > 0.001) {
      return back(req, res, orderId, 'error', `The shares on this order must add up to 100% (they add up to ${trimPct(total)}%).`);
    }
    await incentiveRepository.replaceOrderPeople(orderId, people);
    await incentiveRepository.replaceOrderRoles(orderId, roleMap);
    await incentiveRepository.saveOrder(orderId, { people_customised: 1, shares_customised: custom ? 1 : 0 }, req.user.id);
  }
  const after = describe(await incentiveService.split(orderId, 100));
  await incentiveRepository.addHistory(orderId, 'people', before, after, String(body.people_note || '').trim() || null, req.user.id);
  await auditLogRepository.log(req.user.id, 'ORDER_INCENTIVE_PEOPLE_SET', 'orders', orderId, 'incentive_people', null, after);
  return back(req, res, orderId, 'success', 'Who gets the incentive on this order saved.');
}

/** Owner decision D-245 P4: the MD locks a ready order (nothing locks by itself). */
async function lock(req, res) {
  const orderId = parseInt(req.params.id, 10);
  if (!canManagePricing(req)) return back(req, res, orderId, 'error', 'Only the MD can lock an order\'s incentive.');
  const error = await incentiveService.lockIfReady(orderId, req.user.id);
  if (error !== null) return back(req, res, orderId, 'error', error);
  await auditLogRepository.log(req.user.id, 'ORDER_INCENTIVE_LOCKED', 'orders', orderId);
  return back(req, res, orderId, 'success', 'Incentive locked. The team is paid in the next payout run; the associate on their due date.');
}

/** The MD unlocks a locked order with a reason, until anything is paid. */
async function recalculate(req, res) {
  const orderId = parseInt(req.params.id, 10);
  if (!canManagePricing(req)) return back(req, res, orderId, 'error', 'Only the MD can unlock an order\'s incentive.');
  const reason = String((req.body || {}).unlock_reason || '');
  const error = await incentiveService.unlock(orderId, req.user.id, reason);
  if (error !== null) return back(req, res, orderId, 'error', error);
  await auditLogRepository.log(req.user.id, 'ORDER_INCENTIVE_UNLOCKED', 'orders', orderId, 'locked', 'yes', 'no', reason.trim());
  return back(req, res, orderId, 'success', 'Incentive unlocked. Change it, then lock it again.');
}

/** Owner decision D-245 P4: the orders ready to lock (buyer fully paid, no claim open). */
async function ready(req, res) {
  const rows = [];
  for (const orderId of await incentiveService.readyToLock()) {
    rows.push({ order: await orderRepository.find(orderId), calc: await incentiveService.calculate(orderId), paidOn: await incentiveService.fullyPaidOn(orderId) });
  }
  res.renderView('incentives/ready', {
    rows,
    canManagePricing: canManagePricing(req),
    nextRun: await require('../services/associateCommission').payoutRunOn(todayIso()),
  }, 'layout/base');
}

/** Locks every ready order at once. */
async function lockAll(req, res) {
  if (!canManagePricing(req)) {
    flash.set(req, 'error', 'Only the MD can lock incentives.');
    res.redirect('/incentives/ready');
    return;
  }
  const result = await incentiveService.lockAllReady(req.user.id);
  await auditLogRepository.log(req.user.id, 'ORDER_INCENTIVES_LOCKED_ALL', 'order_incentives', null, 'locked', null, String(result.locked));
  flash.set(req, result.locked > 0 ? 'success' : 'warning', (result.locked === 1 ? '1 order locked.' : `${result.locked} orders locked.`)
    + (result.skipped.length ? ` Not locked: ${result.skipped.join(' ')}` : ''));
  res.redirect('/incentives/ready');
}

async function mine(req, res) {
  await incentiveRepository.allRoles();
  const list = await incentiveRepository.payoutsForUser(req.user.id);
  const year = String(new Date().getFullYear());
  let paidThisYear = 0;
  const due = {};
  for (const p of list) {
    if (p.status === 'paid' && String(p.paid_on || '').slice(0, 4) === year) {
      paidThisYear += Number(p.paid_inr);
    }
    if (p.status === 'due') {
      due[p.incentive_month] = (due[p.incentive_month] || 0) + Number(p.amount_inr);
    }
  }
  res.renderView('incentives/mine', {
    year,
    paidThisYear,
    dueByMonth: Object.entries(due).map(([month, amount]) => ({ month, amount })),
    payouts: list,
    runLabel: require('../services/associateCommission').runLabel(await require('../services/associateCommission').settings()),
  }, 'layout/base');
}

async function payouts(req, res) {
  await incentiveRepository.allRoles();
  const months = await incentiveRepository.payoutMonths();
  let month = String((req.query || {}).month || '');
  if (!incentiveService.isMonth(month)) {
    month = months[0] || await incentiveService.incentiveMonth(todayIso());
  }
  const rows = await incentiveRepository.payoutsForMonth(month);
  if ((req.query || {}).format === 'csv') {
    csv.stream(res, `incentives-${month}.csv`,
      ['Person', 'Kind', 'Order', 'Role', 'Amount (INR)', 'Amount (currency)', 'Currency', 'Status', 'Paid on', 'Paid (INR)', 'TDS (INR)', 'TDS section', 'TDS rate %', 'TDS reason'],
      rows.map((r) => [r.user_name ?? r.associate_name, r.payee_kind, r.buyer_inquiry_ref || r.order_reference,
        r.payee_kind === 'associate' ? 'Associate (agreement)' : incentiveRepository.roleLabel(r.role),
        r.amount_inr, r.amount_fc, r.currency_code, r.status, r.paid_on, r.paid_inr, r.tds_inr, r.tds_section ?? null, r.tds_rate ?? null, r.tds_reason ?? null]));
    return;
  }
  let staffTotal = 0;
  let associateInr = 0;
  const fc = {};
  const dueIds = [];
  for (const r of rows) {
    if (r.payee_kind === 'staff') {
      staffTotal += Number(r.amount_inr);
    } else {
      associateInr += Number(r.amount_inr);
      if (r.currency_code !== null && r.currency_code !== 'INR') {
        fc[r.currency_code] = (fc[r.currency_code] || 0) + Number(r.amount_fc);
      }
    }
    if (r.status === 'due') {
      dueIds.push(Number(r.id));
    }
    r.amountPlain = Number(r.amount_inr).toFixed(2);
    r.earliestPaidOn = incentiveService.earliestPaidOn(String(r.incentive_month));
    // Owner decision 2 Nov 2026 (D-235): the TDS worked out for each associate payout still due, as of today.
    if (r.payee_kind === 'associate' && r.status === 'due' && Number(r.amount_inr) > 0) {
      r.tdsCalc = await associateTds.compute(r, todayIso());
    }
  }
  const tdsSettings = await associateTds.settings();
  res.renderView('incentives/payouts', {
    paidVia: incentiveRepository.PAID_VIA,
    staffTotal,
    associateInr,
    associateFc: Object.entries(fc).map(([currency, amount]) => ({ currency, amount })),
    dueIds,
    month,
    months: [...new Set([month, ...months])],
    rows,
    earliestPaidOn: incentiveService.earliestPaidOn(month),
    runLabel: require('../services/associateCommission').runLabel(await require('../services/associateCommission').settings()),
    tdsSettings,
    tdsRatePct: associateTds.pct(tdsSettings.rate),
  }, 'layout/base');
}

async function markPaid(req, res) {
  const body = req.body || {};
  const month = incentiveService.isMonth(body.month) ? String(body.month) : '';
  const single = req.params.payoutId !== undefined;
  const ids = single ? [parseInt(req.params.payoutId, 10)] : asArray(body.payout_ids).map((v) => parseInt(v, 10));
  const paidOn = String(body.paid_on || '');
  if (!DATE_RE.test(paidOn) || paidOn > todayIso()) {
    flash.set(req, 'error', 'Enter the date it was paid (today or earlier).');
    res.redirect(`/incentives/payouts?month=${encodeURIComponent(month)}`);
    return;
  }
  // Owner rule D-212: how it was paid, with its reference (payroll month, UTR or cheque no.).
  const paidVia = String(body.paid_via || '');
  const paidReference = String(body.paid_reference || '').trim();
  if (!Object.prototype.hasOwnProperty.call(incentiveRepository.PAID_VIA, paidVia)) {
    flash.set(req, 'error', 'Choose how it was paid (with the salary, bank transfer or cheque).');
    res.redirect(`/incentives/payouts?month=${encodeURIComponent(month)}`);
    return;
  }
  const refError = fieldValidator.bankReference(paidReference, 'The payment reference');
  if (refError) {
    flash.set(req, 'error', refError);
    res.redirect(`/incentives/payouts?month=${encodeURIComponent(month)}`);
    return;
  }
  let count = 0;
  let skipped = 0;
  let skippedDocs = 0;
  let skippedDue = 0;
  for (const id of ids) {
    const payout = await incentiveRepository.findPayout(id);
    if (!payout || payout.status !== 'due' || Number(payout.amount_inr) < 0) {
      // A claw-back line (negative) is settled with the associate's next payment, never paid on its own.
      continue;
    }
    // Owner decision 1 Nov 2026 (AS-7): an associate is paid only into a verified bank account past its waiting days.
    if (payout.payee_kind === 'associate' && !(await associateBankRepository.usable(Number(payout.associate_id), paidOn))) {
      if (single) {
        flash.set(req, 'error', `This associate has no verified bank account usable on ${dates.short(paidOn)} — Accounts must verify their bank details first (two people), and a new account is usable only after its waiting days.`);
        res.redirect(`/incentives/payouts?month=${encodeURIComponent(month)}`);
        return;
      }
      skipped++;
      continue;
    }
    // Owner decision D-245 P3: an associate's commission is paid only from its due date, and not while a buyer claim holds it.
    const block = await require('../services/associateCommission').payBlockReason(payout, paidOn);
    if (block !== null) {
      if (single) {
        flash.set(req, 'error', block);
        res.redirect(`/incentives/payouts?month=${encodeURIComponent(month)}`);
        return;
      }
      skippedDue++;
      continue;
    }
    // Owner decision 2 Nov 2026 (D-235): the app works out the TDS on an associate's commission.
    let tdsCalc = null;
    let overrideReason = null;
    if (payout.payee_kind === 'associate') {
      tdsCalc = await associateTds.compute(payout, paidOn);
      if (tdsCalc.abroad) {
        if (single) {
          await storeRemittanceForms(req, payout);
          tdsCalc = await associateTds.compute(payout, paidOn);
        }
        const missing = [...tdsCalc.missing_foreign, ...tdsCalc.missing_payout];
        if (missing.length > 0) {
          if (single) {
            flash.set(req, 'error', `Before paying an associate based abroad, these must be on file: ${missing.join(', ')}. The TRC, Form 10F and no-PE declaration are accepted on the associate's Documents tab; Form 15CA${(await associateTds.settings()).cb_required ? ' and 15CB are' : ' is'} attached to this payment.`);
            res.redirect(`/incentives/payouts?month=${encodeURIComponent(month)}`);
            return;
          }
          skippedDocs++;
          continue;
        }
      }
    }
    const paidInr = single && isNum(body.paid_inr) ? Number(body.paid_inr) : (tdsCalc ? tdsCalc.net : Number(payout.amount_inr));
    const tds = single && isNum(body.tds_inr) ? Number(body.tds_inr) : (tdsCalc ? tdsCalc.tds : null);
    if (tdsCalc) {
      if (Math.abs(Number(tds || 0) - tdsCalc.tds) > 0.5) {
        overrideReason = String(body.tds_override_reason || '').trim();
        if ([...overrideReason].length < 10) {
          flash.set(req, 'error', `The TDS worked out for this payment is ₹${money(tdsCalc.tds)} (${tdsCalc.reason}). To record a different TDS, give the reason (at least 10 characters), e.g. the CA's advice or a lower-deduction certificate.`);
          res.redirect(`/incentives/payouts?month=${encodeURIComponent(month)}`);
          return;
        }
      }
      if (paidInr + Number(tds || 0) > tdsCalc.gross + 0.005) {
        flash.set(req, 'error', `The amount paid plus the TDS cannot be more than the commission (₹${money(tdsCalc.gross)}).`);
        res.redirect(`/incentives/payouts?month=${encodeURIComponent(month)}`);
        return;
      }
    }
    if (paidInr <= 0 || (tds !== null && (tds < 0 || tds > paidInr))) {
      flash.set(req, 'error', 'Enter the amount paid above 0, and TDS between 0 and that amount.');
      res.redirect(`/incentives/payouts?month=${encodeURIComponent(month)}`);
      return;
    }
    // QA Round 6, R6-N10: never more than the payout, never before its month.
    if (paidInr > Number(payout.amount_inr) + 0.005) {
      flash.set(req, 'error', `The amount paid cannot be more than the payout (₹${Number(payout.amount_inr).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}).`);
      res.redirect(`/incentives/payouts?month=${encodeURIComponent(month)}`);
      return;
    }
    const earliest = incentiveService.earliestPaidOn(String(payout.incentive_month));
    if (paidOn < earliest) {
      flash.set(req, 'error', `The date paid cannot be before ${dates.short(earliest)} for a payout of ${payout.incentive_month}.`);
      res.redirect(`/incentives/payouts?month=${encodeURIComponent(month)}`);
      return;
    }
    // QA Round 6, R6-N17: a second click records and logs nothing more.
    if (!(await incentiveService.markPaid(id, paidOn, paidInr, tds, req.user.id, paidVia, paidReference))) {
      continue;
    }
    await auditLogRepository.log(req.user.id, 'INCENTIVE_PAID', 'incentive_payouts', id, 'status', 'due', 'paid');
    if (tdsCalc) {
      const recordedTds = Number(tds || 0);
      await incentiveRepository.setPayoutTds(id,
        recordedTds > 0 ? (overrideReason !== null && tdsCalc.gross > 0 ? Math.round(recordedTds / tdsCalc.gross * 10000) / 100 : tdsCalc.rate) : 0,
        recordedTds > 0 ? (tdsCalc.section || (await associateTds.settings()).section) : null,
        overrideReason !== null ? `changed by hand: ${overrideReason} (worked out: ₹${money(tdsCalc.tds)}, ${tdsCalc.reason})` : tdsCalc.reason,
        overrideReason);
      if (overrideReason !== null) {
        await auditLogRepository.log(req.user.id, 'ASSOCIATE_TDS_OVERRIDDEN', 'incentive_payouts', id, 'tds_inr', tdsCalc.tds.toFixed(2), recordedTds.toFixed(2), overrideReason);
      }
    }
    if (payout.payee_kind === 'associate') {
      // D-245 P3: GST on top for a GST-registered associate whose tax invoice for this payout is on file.
      const associateCommission = require('../services/associateCommission');
      // Fresh-eyes review L2: on the gross billed (paid + TDS), as recorded just now.
      const gst = await associateCommission.gstFor({ ...payout, paid_inr: paidInr, tds_inr: Number(tds || 0) });
      if (gst > 0) {
        await db.execute('UPDATE incentive_payouts SET gst_pct = :p, gst_inr = :g WHERE id = :id', { p: (await associateCommission.settings()).gst_pct, g: gst, id });
      }
      await associateService.onPayoutPaid(id, req.user.id);
    }
    count++;
  }
  // QA Round 6, R6-N11: nothing selected (or all already paid) is not a success.
  const dueNote = skippedDue ? ` ${skippedDue} associate payout(s) skipped: not due yet, or held while a buyer claim is open.` : '';
  const docsNote = skippedDocs ? ` ${skippedDocs} payout(s) to associates abroad skipped: TRC / Form 10F / no-PE / Form 15CA${(await associateTds.settings()).cb_required ? '/15CB' : ''} not yet on file — pay them one by one.` : '';
  if (count === 0) {
    flash.set(req, 'warning', `Nothing was recorded — no payout due for payment was selected.${skipped ? ` ${skipped} associate payout(s) skipped: no verified bank account yet.` : ''}${dueNote}${docsNote}`);
  } else {
    flash.set(req, 'success', `${count === 1 ? 'Payment recorded.' : `${count} payments recorded.`}${skipped ? ` ${skipped} associate payout(s) skipped: no verified bank account yet.` : ''}${docsNote}`);
  }
  res.redirect(`/incentives/payouts?month=${encodeURIComponent(month)}`);
}


const money = (n) => Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * Owner decision 2 Nov 2026 (D-235): Form 15CA / 15CB attached to the
 * payment of an associate based abroad, kept on the associate's documents
 * against this payout.
 */
async function storeRemittanceForms(req, payout) {
  const files = req.files || {};
  const associate = await associateRepository.find(Number(payout.associate_id));
  for (const [kind, field] of [['form_15ca', 'form_15ca_file'], ['form_15cb', 'form_15cb_file']]) {
    const f = Array.isArray(files[field]) ? files[field][0] : null;
    if (!f) continue;
    let fileId;
    const saved = req.file;
    req.file = f;
    try {
      fileId = await fileUploadService.handleUpload(req, field, 'associate_document', 'associates/documents', null, null, req.user.id, null, associate ? associate.name : '', associateDocumentRepository.KINDS[kind]);
    } catch (e) {
      flash.setErrorFromException(req, e);
      continue;
    } finally {
      req.file = saved;
    }
    await associateDocumentRepository.add(Number(payout.associate_id), kind, fileId, Number(payout.id), null, false, req.user.id);
    await auditLogRepository.log(req.user.id, 'ASSOCIATE_DOCUMENT_UPLOADED', 'associates', Number(payout.associate_id), 'kind', null, kind);
  }
}

module.exports = {
  settings,
  saveSettings,
  deleteRole,
  saveOrder,
  saveOrderPeople,
  lock,
  ready,
  lockAll,
  recalculate,
  mine,
  payouts,
  markPaid,
};
