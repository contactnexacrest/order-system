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
  // Owner decision D-245 P3: forcing an associate's commission on ("Yes", e.g. on a repeat order)
  // is the MD's decision alone, with a written reason; it is audited.
  const wasYes = ((row && row.associate_mode) || 'auto') === 'yes';
  const modeReason = String(body.associate_mode_reason || '').trim();
  if (mode === 'yes' && (!wasYes || modeReason !== String((row && row.associate_mode_reason) || ''))) {
    if (!(await require('../services/associateService').canChangeLink(req))) {
      flash.set(req, 'error', 'Only the MD can force the associate\'s commission on for an order.');
      res.redirect(`/orders/${orderId}#financials-12d`);
      return;
    }
    if ([...modeReason].length < 10 || [...modeReason].length > 300) {
      flash.set(req, 'error', 'Say why the associate\'s commission is forced on for this order (10 to 300 characters).');
      res.redirect(`/orders/${orderId}#financials-12d`);
      return;
    }
    fields.associate_mode_reason = modeReason;
    fields.associate_mode_set_by = req.user.id;
    await auditLogRepository.log(req.user.id, 'ASSOCIATE_COMMISSION_FORCED', 'orders', orderId, 'associate_mode', String((row && row.associate_mode) || 'auto'), 'yes', modeReason);
  } else if (mode !== 'yes' && wasYes) {
    fields.associate_mode_reason = null;
    fields.associate_mode_set_by = null;
  }
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
      ['Person', 'Kind', 'Order', 'Role', 'Amount (INR)', 'Amount (currency)', 'Currency', 'Status', 'Paid on', 'Paid (INR)', 'TDS (INR)', 'TDS section', 'TDS rate %', 'TDS reason'],
      rows.map((r) => [r.user_name ?? r.associate_name, r.payee_kind, r.buyer_inquiry_ref || r.order_reference,
        r.payee_kind === 'associate' ? 'Associate (agreement)' : (incentiveRepository.ROLES[r.role] ?? r.role),
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
    cutoff: s.cutoff,
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
      const gst = await associateCommission.gstFor(payout);
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
  saveOrder,
  saveOrderPeople,
  recalculate,
  mine,
  payouts,
  markPaid,
};
