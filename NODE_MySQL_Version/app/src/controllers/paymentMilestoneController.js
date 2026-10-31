'use strict';

const flash = require('../helpers/flash');
const fieldValidator = require('../helpers/fieldValidator');
const { todayIst } = require('../helpers/dbDateTime');
const auditLogRepository = require('../repositories/auditLogRepository');
const documentRepository = require('../repositories/documentRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const orderRepository = require('../repositories/orderRepository');
const paymentMilestoneRepository = require('../repositories/paymentMilestoneRepository');
const moneyVisibility = require('../services/moneyVisibility');
const paymentDescription = require('../services/paymentDescription');

// Port of App\Controllers\PaymentMilestoneController. Owner decision D-215
// (5a): an order's own payment schedule — advance, the steps in between,
// balance. Each step in between is its own payment: remittance recorded
// with its bank reference, cleared, then pushed to Zoho Books by the
// revenue sync (caSyncService). Same rules and messages as PHP.

const back = (res, orderId) => res.redirect(`/orders/${orderId}#payment-schedule`);
const pct = (v) => Number(v).toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
const r2 = (n) => Math.round(Number(n) * 100) / 100;
const isDate = (v) => /^\d{4}-\d{2}-\d{2}$/.test(v) && !Number.isNaN(Date.parse(`${v}T00:00:00Z`)) && new Date(`${v}T00:00:00Z`).toISOString().slice(0, 10) === v;

/** @returns {[Array<{pct:number,due_text:string}>, string|null]} the steps, or the error */
function parseSteps(pcts, dues) {
  const p = [].concat(pcts || []);
  const d = [].concat(dues || []);
  const steps = [];
  const count = Math.max(p.length, d.length);
  for (let i = 0; i < count; i++) {
    const pctText = String(p[i] ?? '').trim();
    const due = String(d[i] ?? '').trim().replace(/[. ]+$/, '');
    if (pctText === '' && due === '') continue;
    const n = steps.length + 1;
    const error = pctText === '' ? `Milestone ${n} %: enter its share of the FOB value.` : fieldValidator.decimal(pctText, `Milestone ${n} %`, 0.01, 99);
    if (error) return [[], error];
    if (r2(pctText) !== parseFloat(pctText)) return [[], `Milestone ${n} %: use at most 2 decimal places.`];
    const len = [...due].length;
    if (len < 5 || len > 200) {
      return [[], `Milestone ${n}: say when it is due, in 5 to 200 characters (e.g. "on shipment — within 3 Calendar Days of receiving the scanned Bill of Lading").`];
    }
    steps.push({ pct: parseFloat(pctText), due_text: due });
  }
  if (steps.length > paymentMilestoneRepository.MAX_STEPS) {
    return [[], `An order can have at most ${paymentMilestoneRepository.MAX_STEPS} milestones between the advance and the balance.`];
  }
  return [steps, null];
}

function summary(advancePct, steps) {
  const parts = [`${pct(advancePct)}% advance`];
  let total = 0;
  for (const s of steps) {
    parts.push(`${pct(s.pct)}% ${s.due_text}`);
    total += r2(s.pct);
  }
  parts.push(`${pct(r2(100 - advancePct - total))}% balance`);
  return parts.join(' · ');
}

async function saveSchedule(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  if (!moneyVisibility.can(req.permissions, moneyVisibility.SELLING)) {
    flash.set(req, 'error', 'The payment schedule carries selling prices your department does not see.');
    back(res, orderId);
    return;
  }
  const payment = await orderPaymentStatusRepository.find(orderId);
  if (payment && payment.advance_cleared_at) {
    flash.set(req, 'error', 'The payment schedule is fixed once the advance is cleared — change it with a payment terms amendment.');
    back(res, orderId);
    return;
  }
  if (await paymentMilestoneRepository.anyMoneyRecorded(orderId)) {
    flash.set(req, 'error', 'A milestone payment is already recorded — the schedule can no longer be changed.');
    back(res, orderId);
    return;
  }
  let [steps, error] = parseSteps(req.body.milestone_pct, req.body.milestone_due);
  const advancePct = parseFloat(order.advance_pct);
  const total = steps.reduce((sum, s) => sum + r2(s.pct), 0);
  if (error === null && r2(advancePct + total) >= 100) {
    error = `Advance ${pct(advancePct)}% + milestones ${pct(total)}% leave nothing for the balance — together they must stay below 100%.`;
  }
  if (error !== null) {
    flash.set(req, 'error', error);
    back(res, orderId);
    return;
  }
  const before = await paymentMilestoneRepository.forOrder(orderId);
  await paymentMilestoneRepository.replaceSchedule(orderId, steps, advancePct);
  const text = summary(advancePct, steps);
  await auditLogRepository.log(req.user.id, 'PAYMENT_SCHEDULE_SET', 'orders', orderId, 'payment_milestones',
    summary(advancePct, before.map((m) => ({ pct: parseFloat(m.pct), due_text: String(m.due_text) }))), text);
  flash.set(req, 'success', `${steps.length === 0 ? 'Payment schedule back to advance and balance: ' : 'Payment schedule saved: '}${text}. Regenerate the Proforma Invoice so the buyer sees it.`);
  back(res, orderId);
}

async function milestoneFor(orderId, req) {
  const m = await paymentMilestoneRepository.find(parseInt(req.params.milestoneId, 10) || 0);
  return m && Number(m.order_id) === orderId ? m : null;
}

async function record(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const m = await milestoneFor(orderId, req);
  if (!m) {
    res.status(404).send('Milestone not found.');
    return;
  }
  if (!(await documentRepository.findLatestForOrderAndTypeCode(orderId, 'PI'))) {
    flash.set(req, 'error', 'Generate the Proforma Invoice first — the buyer pays each milestone against it.');
    back(res, orderId);
    return;
  }
  if (m.received_amount !== null) {
    flash.set(req, 'warning', `Milestone ${m.seq} remittance is already recorded — nothing changed.`);
    back(res, orderId);
    return;
  }
  const amount = parseFloat(req.body.amount) || 0;
  const rate = parseFloat(req.body.exchange_rate) || 0;
  const bankRef = String(req.body.bank_reference ?? '').trim();
  const receivedOn = String(req.body.received_on ?? '').trim() || todayIst();
  const note = String(req.body.staff_note ?? '').trim();
  const error = fieldValidator.moneyAmount(amount, `The milestone ${m.seq} amount`)
    ?? fieldValidator.exchangeRate(rate)
    ?? fieldValidator.bankReference(bankRef)
    ?? (!isDate(receivedOn) || receivedOn > todayIst() ? 'Enter the date the money was received — today or earlier.' : null)
    ?? ([...note].length > 255 ? 'The note can be at most 255 characters.' : null);
  if (error !== null) {
    flash.set(req, 'error', error);
    back(res, orderId);
    return;
  }
  await paymentMilestoneRepository.recordReceived(Number(m.id), amount, receivedOn, rate, bankRef, note, req.user.id);
  const description = await paymentDescription.milestonePayment((await paymentMilestoneRepository.find(Number(m.id))) || m);
  await auditLogRepository.log(req.user.id, 'MILESTONE_PAYMENT_RECORDED', 'order_payment_milestones', Number(m.id), 'received_amount', null, description);
  flash.set(req, 'success', `Recorded: ${description}. Mark it cleared once your bank confirms receipt.`);
  back(res, orderId);
}

async function clear(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const m = await milestoneFor(orderId, req);
  if (!m) {
    res.status(404).send('Milestone not found.');
    return;
  }
  if (m.received_amount === null) {
    flash.set(req, 'error', `Record the milestone ${m.seq} remittance before marking it cleared.`);
    back(res, orderId);
    return;
  }
  if (m.cleared_on !== null) {
    flash.set(req, 'warning', `Milestone ${m.seq} is already cleared — nothing changed.`);
    back(res, orderId);
    return;
  }
  const clearedOn = String(req.body.cleared_on ?? '').trim() || todayIst();
  if (!isDate(clearedOn) || clearedOn > todayIst() || clearedOn < String(m.received_on)) {
    flash.set(req, 'error', 'Enter the date your bank cleared it — on or after the day it was received, and not in the future.');
    back(res, orderId);
    return;
  }
  await paymentMilestoneRepository.markCleared(Number(m.id), clearedOn, req.user.id);
  await auditLogRepository.log(req.user.id, 'MILESTONE_PAYMENT_CLEARED', 'order_payment_milestones', Number(m.id), 'cleared_on', null, clearedOn);
  flash.set(req, 'success', `Milestone ${m.seq} cleared. It goes to Zoho Books with the next revenue sync.`);
  back(res, orderId);
}

/** What the order page's payment schedule panel shows (port of the PHP view's own block). */
async function scheduleView(orderId, order) {
  const orderProductRepository = require('../repositories/orderProductRepository');
  const { balanceTriggerSentence } = require('../services/documentDataAssembler');
  const rows = await paymentMilestoneRepository.forOrder(orderId);
  const fob = await orderProductRepository.totalFobValue(orderId);
  const payment = (await orderPaymentStatusRepository.find(orderId)) || {};
  const advancePct = parseFloat(order.advance_pct);
  const totalPct = rows.reduce((sum, m) => sum + parseFloat(m.pct), 0);
  const advanceAmount = r2(fob * advancePct / 100);
  const midAmount = rows.reduce((sum, m) => sum + r2(fob * parseFloat(m.pct) / 100), 0);
  const legStatus = (leg) => (payment[`${leg}_cleared_at`] ? 'Cleared' : (payment[`${leg}_remittance_received_at`] ? 'Received — not yet cleared' : 'Due'));
  return {
    advancePct: pct(advancePct),
    advanceAmount,
    advanceText: order.advance_trigger_text || '',
    advanceStatus: legStatus('advance'),
    rows: rows.map((m) => ({ ...m, pctText: pct(m.pct), amount: r2(fob * parseFloat(m.pct) / 100), amountInput: r2(fob * parseFloat(m.pct) / 100).toFixed(2) })),
    balanceStep: rows.length + 2,
    balancePct: pct(100 - advancePct - totalPct),
    balanceAmount: r2(fob - advanceAmount - midAmount),
    balanceText: balanceTriggerSentence(order.balance_trigger_option || null, order.balance_days != null ? parseInt(order.balance_days, 10) : null, order.balance_trigger_wording || null),
    balanceStatus: legStatus('balance'),
    fixed: Boolean(payment.advance_cleared_at) || (await paymentMilestoneRepository.anyMoneyRecorded(orderId)),
    maxSteps: paymentMilestoneRepository.MAX_STEPS,
    editRows: Array.from({ length: paymentMilestoneRepository.MAX_STEPS }, (_, i) => rows[i] ? { seq: i + 2, pct: pct(rows[i].pct), due: rows[i].due_text } : { seq: i + 2, pct: '', due: '' }),
  };
}

module.exports = { parseSteps, summary, saveSchedule, record, clear, scheduleView };
