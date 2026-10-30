'use strict';

const fs = require('fs');
const recordCheck = require('../helpers/recordCheck');
const csv = require('../helpers/csv');
const dates = require('../helpers/dates');
const flash = require('../helpers/flash');
const associateRepository = require('../repositories/associateRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const fileStoreRepository = require('../repositories/fileStoreRepository');
const incentiveRepository = require('../repositories/incentiveRepository');
const lookupRepository = require('../repositories/lookupRepository');
const orderRepository = require('../repositories/orderRepository');
const userRepository = require('../repositories/userRepository');
const incentiveService = require('../services/incentiveService');
const fileUploadService = require('../services/fileUploadService');

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

async function settings(req, res) {
  const s = await incentiveService.settings();
  const tableRows = s.table.map((t) => ({ from: String(Math.round(t.from)), reward: String(Math.round(t.reward)) }));
  while (tableRows.length < 5) {
    tableRows.push({ from: '', reward: '' });
  }
  const splitText = {};
  for (const role of Object.keys(incentiveRepository.ROLES)) {
    splitText[role] = s.split[role].toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
  }
  res.renderView('incentives/settings', {
    settings: s,
    tableRows,
    splitText,
    minProfitText: String(Math.round(s.min_profit)),
    bonusText: String(Math.round(s.bonus)),
    users: await userRepository.listActive(),
    userRoles: await incentiveRepository.userRoles(),
  }, 'layout/base');
}

async function saveSettings(req, res) {
  const userId = req.user.id;
  const before = await incentiveService.settings();
  const body = req.body || {};

  const minProfit = isNum(body.min_profit_inr) ? Number(body.min_profit_inr) : false;
  const bonus = isNum(body.new_client_bonus_inr) ? Number(body.new_client_bonus_inr) : false;
  const cutoff = String(body.cutoff_day ?? '');
  const errors = [];
  if (minProfit === false || minProfit < 0) {
    errors.push('Enter the minimum order profit as an amount of 0 or more.');
  }
  if (bonus === false || bonus < 0) {
    errors.push('Enter the new client bonus as an amount of 0 or more (0 = off).');
  }
  if (cutoff !== 'last' && (!/^\d+$/.test(cutoff) || Number(cutoff) < 1 || Number(cutoff) > 28)) {
    errors.push('Choose an incentive cut-off day from 1 to 28, or the last day of the month.');
  }
  const table = [];
  const froms = body.table_from || {};
  const rewards = body.table_reward || {};
  for (const i of Object.keys(froms)) {
    const from = froms[i];
    const reward = rewards[i] ?? '';
    if (String(from).trim() === '' && String(reward).trim() === '') {
      continue;
    }
    if (!isNum(from) || !isNum(reward) || Number(from) <= 0 || Number(reward) <= 0) {
      errors.push('Each reward table row needs a profit and a reward above 0 (leave both empty to drop the row).');
      break;
    }
    table.push({ from: Number(from), reward: Number(reward) });
  }
  // QA Round 6, R6-N12: two rows with one profit threshold — which reward applies would be undefined.
  const thresholds = table.map((t) => t.from);
  if (new Set(thresholds).size !== thresholds.length) {
    errors.push('Two reward table rows have the same order profit — each row needs its own.');
  }
  const split = {};
  const postedSplit = body.split || {};
  for (const role of Object.keys(incentiveRepository.ROLES)) {
    const v = postedSplit[role];
    if (!isNum(v) || Number(v) < 0) {
      errors.push('Enter every role\'s share as a % of 0 or more.');
      break;
    }
    split[role] = Number(v);
  }
  if (Object.keys(split).length === Object.keys(incentiveRepository.ROLES).length) {
    const sum = Object.values(split).reduce((a, b) => a + b, 0);
    if (Math.abs(sum - 100) > 0.001) {
      errors.push(`The role shares must add up to 100% (they add up to ${trimPct(sum)}%).`);
    }
  }
  if (errors.length) {
    flash.set(req, 'error', errors.join(' '));
    res.redirect('/incentives/settings');
    return;
  }

  // Orders already fully paid keep the settings they were paid under.
  await incentiveService.lockDue(userId);

  const enabled = !!body.program_enabled;
  table.sort((a, b) => a.from - b.from);
  const values = {
    program_enabled: enabled ? '1' : '0',
    min_profit_inr: String(minProfit),
    reward_table: JSON.stringify(table),
    new_client_bonus_inr: String(bonus),
    role_split: JSON.stringify(split),
    cutoff_day: cutoff,
  };
  if (enabled && before.start_date === '') {
    values.program_start_date = todayIso();
  }
  const old = await incentiveRepository.settings();
  for (const [key, value] of Object.entries(values)) {
    if ((old[key] ?? null) !== value) {
      await incentiveRepository.setSetting(key, value, userId);
      await auditLogRepository.log(userId, 'INCENTIVE_SETTING_CHANGED', 'incentive_settings', null, key, old[key] ?? null, value);
    }
  }

  const rolesByUser = {};
  // Keys are "u<user id>": a bare number would make the form parser read the field as a (compacted) list.
  for (const [key, roles] of Object.entries(body.user_roles || {})) {
    const uid = Number(String(key).replace(/^u/, ''));
    if (Number.isInteger(uid) && uid > 0) rolesByUser[uid] = asArray(roles).filter((r) => incentiveRepository.ROLES[r]);
  }
  const current = await incentiveRepository.userRoles();
  const norm = (m) => JSON.stringify(Object.keys(m).filter((k) => (m[k] || []).length).sort().map((k) => [Number(k), [...m[k]].sort()]));
  if (norm(rolesByUser) !== norm(current)) {
    await incentiveRepository.replaceUserRoles(rolesByUser);
    await auditLogRepository.log(userId, 'INCENTIVE_ROLES_CHANGED', 'user_incentive_roles', null, null, null, JSON.stringify(rolesByUser));
  }
  flash.set(req, 'success', 'Team incentive settings saved.');
  res.redirect('/incentives/settings');
}

async function associates(req, res) {
  // QA Round 6, R6-N07: editing an associate that does not exist is "not found".
  if (req.params.id !== undefined && !(await associateRepository.find(parseInt(req.params.id, 10)))) {
    res.status(404).send('Associate not found.');
    return;
  }
  const list = await associateRepository.all();
  const clientsByAssociate = {};
  for (const a of list) {
    clientsByAssociate[Number(a.id)] = await associateRepository.clients(Number(a.id));
  }
  for (const a of list) {
    a.rateText = [a.rate_1_5, a.rate_6_10, a.rate_11_20, a.rate_21_40, a.rate_41_plus].map((r) => `${trimPct(r)}%`).join(' / ');
  }
  res.renderView('incentives/associates', {
    rateFields: [
      { name: 'rate_1_5', label: 'Orders 1–5', default: '0.70' },
      { name: 'rate_6_10', label: 'Orders 6–10', default: '1.00' },
      { name: 'rate_11_20', label: 'Orders 11–20', default: '1.50' },
      { name: 'rate_21_40', label: 'Orders 21–40', default: '1.75' },
      { name: 'rate_41_plus', label: 'Order 41 onward', default: '2.00' },
    ],
    associates: list,
    clientsByAssociate,
    unlinkedClients: await associateRepository.unlinkedClients(),
    currencies: await lookupRepository.currencies(),
    editing: req.params.id ? await associateRepository.find(parseInt(req.params.id, 10)) : null,
  }, 'layout/base');
}

async function saveAssociate(req, res) {
  const id = req.params.id ? parseInt(req.params.id, 10) : null;
  if (id !== null && await recordCheck.missing(req, res, 'associates', id, '/associates', 'associate')) return;
  const body = req.body || {};
  const data = {};
  for (const f of associateRepository.FIELDS) {
    const v = String(body[f] ?? '').trim();
    data[f] = v === '' ? null : v;
  }
  const errors = [];
  if (data.name === null || [...data.name].length > 150) {
    errors.push('Enter the associate\'s name (up to 150 characters).');
  }
  if (!['india', 'abroad'].includes(data.based_in)) {
    errors.push('Choose whether the associate is in India or abroad.');
  }
  if (!/^[A-Z]{3}$/.test(String(data.currency_code ?? ''))) {
    errors.push('Choose the currency the associate is paid in.');
  }
  if (data.email !== null && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email)) {
    errors.push('Enter a valid email address, or leave it empty.');
  }
  if (data.agreement_signed_on !== null && !DATE_RE.test(data.agreement_signed_on)) {
    errors.push('Enter the agreement date as a date.');
  }
  for (const r of ['rate_1_5', 'rate_6_10', 'rate_11_20', 'rate_21_40', 'rate_41_plus']) {
    if (!isNum(data[r]) || Number(data[r]) < 0 || Number(data[r]) > 20) {
      errors.push('Enter every commission rate as a % from 0 to 20.');
      break;
    }
  }
  data.status = data.status === 'inactive' ? 'inactive' : 'active';
  // Owner decision 2026-10-30: the signed agreement is kept with its date —
  // a signing date needs the signed copy on file, and the other way round.
  const existing = id !== null ? await associateRepository.find(id) : null;
  const hasUpload = Boolean(req.file && req.file.originalname);
  if (data.agreement_signed_on !== null && !hasUpload && !(existing && existing.agreement_file_id)) {
    errors.push('Attach the signed agreement (PDF) with its signing date.');
  }
  if ((hasUpload || (existing && existing.agreement_file_id)) && data.agreement_signed_on === null) {
    errors.push('Enter the date the agreement was signed with its signed copy.');
  }
  if (errors.length) {
    flash.set(req, 'error', errors.join(' '));
    res.redirect(id ? `/associates/${id}/edit` : '/associates');
    return;
  }
  let fileId = null;
  if (hasUpload) {
    try {
      fileId = await fileUploadService.handleUpload(req, 'agreement_file', 'associate_agreement', 'associates/agreements', null, null, req.user.id, null, data.name, 'Signed associate agreement');
    } catch (e) {
      flash.setErrorFromException(req, e);
      res.redirect(id ? `/associates/${id}/edit` : '/associates');
      return;
    }
  }
  let savedId = id;
  if (id === null) {
    savedId = await associateRepository.create(data, req.user.id);
    await auditLogRepository.log(req.user.id, 'ASSOCIATE_ADDED', 'associates', savedId, null, null, data.name);
    flash.set(req, 'success', `Associate "${data.name}" added.`);
  } else {
    await associateRepository.update(id, data);
    await auditLogRepository.log(req.user.id, 'ASSOCIATE_UPDATED', 'associates', id, null, null, JSON.stringify(data));
    flash.set(req, 'success', `Associate "${data.name}" saved.`);
  }
  if (fileId !== null) {
    await associateRepository.setAgreementFile(savedId, fileId);
    await auditLogRepository.log(req.user.id, 'ASSOCIATE_AGREEMENT_UPLOADED', 'associates', savedId, 'agreement_file_id', existing && existing.agreement_file_id ? String(existing.agreement_file_id) : null, String(fileId));
  }
  res.redirect('/associates');
}

/** The signed agreement — internal (manage_incentives only), every download audit-logged. */
async function downloadAgreement(req, res) {
  const associate = await associateRepository.find(parseInt(req.params.id, 10));
  const file = associate && associate.agreement_file_id ? await fileStoreRepository.find(Number(associate.agreement_file_id)) : null;
  if (!file || !fs.existsSync(file.server_path)) {
    res.status(404).send('No signed agreement is on file for this associate.');
    return;
  }
  await auditLogRepository.log(req.user.id, 'ASSOCIATE_AGREEMENT_DOWNLOADED', 'associates', Number(associate.id), null, null, file.original_filename);
  // eslint-disable-next-line no-control-regex
  const name = String(file.original_filename).replace(/[\x00-\x1F\x7F"/\\]/g, '-');
  res.setHeader('Content-Type', 'application/pdf');
  res.setHeader('Content-Disposition', `attachment; filename="${name}"`);
  res.setHeader('Content-Length', String(fs.statSync(file.server_path).size));
  res.setHeader('Cache-Control', 'private, no-store');
  res.send(fs.readFileSync(file.server_path));
}

async function linkClient(req, res) {
  const associateId = parseInt(req.params.id, 10);
  const clientId = parseInt((req.body || {}).client_id || 0, 10);
  const registeredOn = String((req.body || {}).registered_on || '');
  if (!(await associateRepository.find(associateId)) || !(clientId > 0) || !DATE_RE.test(registeredOn)) {
    flash.set(req, 'error', 'Choose the client and the date the associate registered the lead.');
    res.redirect('/associates');
    return;
  }
  await associateRepository.linkClient(clientId, associateId, registeredOn);
  await auditLogRepository.log(req.user.id, 'ASSOCIATE_CLIENT_LINKED', 'clients', clientId, 'associate_id', null, String(associateId));
  flash.set(req, 'success', 'Client linked to the associate.');
  res.redirect('/associates');
}

async function unlinkClient(req, res) {
  const clientId = parseInt(req.params.clientId, 10);
  if (await recordCheck.missing(req, res, 'clients', clientId, '/associates', 'client link', { associate_id: parseInt(req.params.id, 10) })) return;
  await associateRepository.linkClient(clientId, null, null);
  await auditLogRepository.log(req.user.id, 'ASSOCIATE_CLIENT_UNLINKED', 'clients', clientId, 'associate_id', String(req.params.id), null);
  flash.set(req, 'success', 'Client unlinked from the associate.');
  res.redirect('/associates');
}

async function saveOrder(req, res) {
  const orderId = parseInt(req.params.id, 10);
  if (!(await orderRepository.find(orderId))) {
    res.status(404).send('Order not found.');
    return;
  }
  const row = await incentiveRepository.forOrder(orderId);
  if (row && row.locked_at) {
    flash.set(req, 'error', 'This order\'s incentive is already fixed (the buyer has fully paid). Use "Work it out again" first.');
    res.redirect(`/orders/${orderId}#financials-12d`);
    return;
  }
  const body = req.body || {};
  const override = isNum(body.reward_override_inr) ? Number(body.reward_override_inr) : false;
  const mode = String(body.associate_mode ?? 'auto');
  if (override === false || override < 0 || !['auto', 'yes', 'no'].includes(mode)) {
    flash.set(req, 'error', 'Enter the reward for this order as an amount of 0 or more (0 = use the settings).');
    res.redirect(`/orders/${orderId}#financials-12d`);
    return;
  }
  const fields = { reward_override_inr: override, is_excluded: body.is_excluded ? 1 : 0, associate_mode: mode };
  await incentiveRepository.saveOrder(orderId, fields, req.user.id);
  const oldFields = row ? { reward_override_inr: row.reward_override_inr, is_excluded: row.is_excluded, associate_mode: row.associate_mode } : null;
  await auditLogRepository.log(req.user.id, 'ORDER_INCENTIVE_SET', 'orders', orderId, 'incentive', oldFields ? JSON.stringify(oldFields) : null, JSON.stringify(fields));
  flash.set(req, 'success', 'Team incentive for this order saved.');
  res.redirect(`/orders/${orderId}#financials-12d`);
}

async function saveOrderPeople(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const row = await incentiveRepository.forOrder(orderId);
  if (row && row.locked_at) {
    flash.set(req, 'error', 'This order\'s incentive is already fixed. Use "Work it out again" first.');
    res.redirect(`/orders/${orderId}#financials-12d`);
    return;
  }
  const body = req.body || {};
  if (body.reset) {
    await incentiveRepository.replaceOrderPeople(orderId, {});
    await incentiveRepository.saveOrder(orderId, { people_customised: 0 }, req.user.id);
  } else {
    const active = (await userRepository.listActive()).map((u) => Number(u.id));
    const people = {};
    const posted = body.people || {};
    for (const role of Object.keys(incentiveRepository.ROLES)) {
      people[role] = asArray(posted[role]).map((v) => parseInt(v, 10)).filter((v) => active.includes(v));
    }
    await incentiveRepository.replaceOrderPeople(orderId, people);
    await incentiveRepository.saveOrder(orderId, { people_customised: 1 }, req.user.id);
    await auditLogRepository.log(req.user.id, 'ORDER_INCENTIVE_PEOPLE_SET', 'orders', orderId, 'incentive_people', null, JSON.stringify(people));
  }
  flash.set(req, 'success', 'People on this order saved.');
  res.redirect(`/orders/${orderId}#financials-12d`);
}

async function recalculate(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const error = await incentiveService.unlock(orderId, req.user.id);
  if (error !== null) {
    flash.set(req, 'error', error);
  } else {
    await incentiveService.lockIfDue(orderId, req.user.id);
    await auditLogRepository.log(req.user.id, 'ORDER_INCENTIVE_RECALCULATED', 'orders', orderId);
    flash.set(req, 'success', 'Incentive worked out again.');
  }
  res.redirect(`/orders/${orderId}#financials-12d`);
}

async function mine(req, res) {
  await incentiveService.lockDue(req.user.id);
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
    cutoff: (await incentiveService.settings()).cutoff,
  }, 'layout/base');
}

async function payouts(req, res) {
  await incentiveService.lockDue(req.user.id);
  const months = await incentiveRepository.payoutMonths();
  const s = await incentiveService.settings();
  let month = String((req.query || {}).month || '');
  if (!incentiveService.isMonth(month)) {
    month = months[0] || incentiveService.incentiveMonth(todayIso(), s.cutoff);
  }
  const rows = await incentiveRepository.payoutsForMonth(month);
  if ((req.query || {}).format === 'csv') {
    csv.stream(res, `incentives-${month}.csv`,
      ['Person', 'Kind', 'Order', 'Role', 'Amount (INR)', 'Amount (currency)', 'Currency', 'Status', 'Paid on', 'Paid (INR)', 'TDS (INR)'],
      rows.map((r) => [r.user_name ?? r.associate_name, r.payee_kind, r.buyer_inquiry_ref || r.order_reference,
        r.payee_kind === 'associate' ? 'Associate (agreement)' : (incentiveRepository.ROLES[r.role] ?? r.role),
        r.amount_inr, r.amount_fc, r.currency_code, r.status, r.paid_on, r.paid_inr, r.tds_inr]));
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
  }
  res.renderView('incentives/payouts', {
    staffTotal,
    associateInr,
    associateFc: Object.entries(fc).map(([currency, amount]) => ({ currency, amount })),
    dueIds,
    month,
    months: [...new Set([month, ...months])],
    rows,
    earliestPaidOn: incentiveService.earliestPaidOn(month),
    cutoff: s.cutoff,
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
  let count = 0;
  for (const id of ids) {
    const payout = await incentiveRepository.findPayout(id);
    if (!payout || payout.status !== 'due') {
      continue;
    }
    const paidInr = single && isNum(body.paid_inr) ? Number(body.paid_inr) : Number(payout.amount_inr);
    const tds = single && isNum(body.tds_inr) ? Number(body.tds_inr) : null;
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
    if (!(await incentiveService.markPaid(id, paidOn, paidInr, tds, req.user.id))) {
      continue;
    }
    await auditLogRepository.log(req.user.id, 'INCENTIVE_PAID', 'incentive_payouts', id, 'status', 'due', 'paid');
    count++;
  }
  // QA Round 6, R6-N11: nothing selected (or all already paid) is not a success.
  if (count === 0) {
    flash.set(req, 'warning', 'Nothing was recorded — no payout due for payment was selected.');
  } else {
    flash.set(req, 'success', count === 1 ? 'Payment recorded.' : `${count} payments recorded.`);
  }
  res.redirect(`/incentives/payouts?month=${encodeURIComponent(month)}`);
}

module.exports = {
  settings,
  saveSettings,
  associates,
  saveAssociate,
  downloadAgreement,
  linkClient,
  unlinkClient,
  saveOrder,
  saveOrderPeople,
  recalculate,
  mine,
  payouts,
  markPaid,
};
