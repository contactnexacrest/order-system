'use strict';

/**
 * Owner decision 2026-10-31 (option A, confirmed): CA → GST refunds (LUT).
 * NexaCrest exports under LUT and sells nothing in India, so the GST paid on
 * purchases comes back as a refund claimed for a whole period (RFD-01). This
 * page records each claim, then each amount the bank credits for it (with
 * its RFD-05 / UTR reference — rule D-212), and any amount refused. Every
 * credit goes to Zoho Books as a journal at the next sync. Port of
 * GstRefundController.
 */
const flash = require('../helpers/flash');
const fieldValidator = require('../helpers/fieldValidator');
const { todayIst } = require('../helpers/dbDateTime');
const auditLogRepository = require('../repositories/auditLogRepository');
const gstRefundRepository = require('../repositories/gstRefundRepository');
const caFyLockGuard = require('../services/caFyLockGuard');
const paymentDescription = require('../services/paymentDescription');

const BACK = '/ca/gst-refunds';
const money = (n) => Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const r2 = (v) => Math.round(Number(v) * 100) / 100;
const isNum = (v) => /^-?\d*\.?\d+$/.test(String(v).trim());
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const longDate = (d) => { const [y, m, day] = String(d).slice(0, 10).split('-').map(Number); return `${day} ${MONTHS[m - 1]} ${y}`; };

function isDate(value) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  if (!m) return false;
  const d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
  return d.getUTCFullYear() === Number(m[1]) && d.getUTCMonth() + 1 === Number(m[2]) && d.getUTCDate() === Number(m[3]);
}

async function index(req, res) {
  const claims = await gstRefundRepository.claims();
  for (const claim of claims) {
    claim.period_label = paymentDescription.periodLabel(claim.period_from, claim.period_to);
    claim.gst_on_bills = await gstRefundRepository.gstOnBills(claim.period_from, claim.period_to);
    claim.outstanding = r2(Math.max(0, Number(claim.amount_claimed) - Number(claim.amount_credited) - Number(claim.amount_refused)));
    claim.outstanding_plain = claim.outstanding.toFixed(2);
    claim.credit_rows = (await gstRefundRepository.creditsFor(Number(claim.id))).map((c) => ({
      ...c, description: paymentDescription.gstRefund({ ...c, period_from: claim.period_from, period_to: claim.period_to, claim_arn: claim.claim_arn }),
    }));
  }
  const sum = (key) => r2(claims.reduce((t, c) => t + Number(c[key]), 0));
  res.renderView('ca/gst_refunds', {
    claims,
    totals: { claimed: sum('amount_claimed'), credited: sum('amount_credited'), refused: sum('amount_refused'), outstanding: sum('outstanding') },
    parts: gstRefundRepository.PARTS,
  });
}

async function recordClaim(req, res) {
  const body = req.body || {};
  const from = String(body.period_from || '').trim();
  const to = String(body.period_to || '').trim();
  const arn = String(body.claim_arn || '').trim().toUpperCase();
  const filedOn = String(body.filed_on || '').trim();
  const amount = String(body.amount_claimed || '').trim();
  let error = null;
  if (!isDate(from) || !isDate(to) || to < from) error = 'Enter the period the claim covers (from and to; "to" not before "from").';
  else if (!/^[A-Z0-9][A-Z0-9-]{5,29}$/.test(arn)) error = 'Enter the RFD-01 claim no. (ARN) as on the GST portal: 6 to 30 letters and digits.';
  else if (await gstRefundRepository.arnTaken(arn)) error = `Claim ${arn} is already recorded.`;
  else if (!isDate(filedOn) || filedOn > todayIst() || filedOn < from) error = 'Enter the date the claim was filed (not in the future, not before the period starts).';
  else error = fieldValidator.moneyAmount(isNum(amount) ? Number(amount) : NaN, 'The amount claimed');
  if (error) {
    flash.set(req, 'error', `${error} Nothing was saved.`);
    res.redirect(BACK);
    return;
  }
  if (!(await caFyLockGuard.allow(req, filedOn, 'gst_refund_claims', 0, 'record'))) {
    res.redirect(BACK);
    return;
  }
  const id = await gstRefundRepository.createClaim(from, to, arn, filedOn, Number(amount), req.user.id);
  const label = paymentDescription.periodLabel(from, to);
  await auditLogRepository.log(req.user.id, 'GST_REFUND_CLAIM_RECORDED', 'gst_refund_claims', id, 'amount_claimed', null, Number(amount).toFixed(2), `Claim ${arn}, ${label}`);
  flash.set(req, 'success', `Claim ${arn} for ${label} recorded: ₹${money(amount)} claimed.`);
  res.redirect(BACK);
}

/** Money the bank credited for a claim (and, with it, any amount refused). */
async function recordCredit(req, res) {
  const claim = await gstRefundRepository.findClaim(parseInt(req.params.id, 10));
  if (!claim) {
    res.status(404).send('Claim not found.');
    return;
  }
  const body = req.body || {};
  const amount = String(body.amount || '').trim();
  const creditedOn = String(body.credited_on || '').trim();
  const reference = String(body.bank_reference || '').trim();
  const part = String(body.part || '');
  const refused = String(body.amount_refused || '').trim();
  const refusedAmount = refused === '' ? 0 : Number(refused);
  const open = r2(Number(claim.amount_claimed) - Number(claim.amount_credited) - Number(claim.amount_refused));
  let error = null;
  if (!isNum(amount)) error = 'Enter the amount the bank credited.';
  else error = fieldValidator.moneyAmount(Number(amount), 'The amount credited');
  if (!error && (!isDate(creditedOn) || creditedOn > todayIst() || creditedOn < String(claim.filed_on).slice(0, 10))) error = 'Enter the date the bank credited it (not in the future, not before the claim was filed).';
  if (!error) error = fieldValidator.bankReference(reference, 'The bank reference (RFD-05 / UTR)');
  if (!error && !Object.prototype.hasOwnProperty.call(gstRefundRepository.PARTS, part)) error = 'Choose whether this is the provisional part or the final / balance.';
  if (!error && refused !== '' && (!isNum(refused) || refusedAmount < 0)) error = 'Enter the amount refused as a number (or leave it empty).';
  if (!error && r2(Number(amount) + refusedAmount) > open + 0.005) error = `Credited plus refused (₹${money(Number(amount) + refusedAmount)}) is more than what is still open on this claim (₹${money(Math.max(0, open))}).`;
  if (error) {
    flash.set(req, 'error', `${error} Nothing was saved.`);
    res.redirect(BACK);
    return;
  }
  if (!(await caFyLockGuard.allow(req, creditedOn, 'gst_refund_credits', Number(claim.id), 'record'))) {
    res.redirect(BACK);
    return;
  }
  const id = await gstRefundRepository.addCredit(Number(claim.id), Number(amount), creditedOn, reference, part, req.user.id);
  if (refusedAmount > 0) {
    await gstRefundRepository.addRefused(Number(claim.id), refusedAmount, `₹${money(refusedAmount)} refused (with the credit of ${longDate(creditedOn)})`);
  }
  const description = paymentDescription.gstRefund((await gstRefundRepository.findCredit(id)) || {});
  await auditLogRepository.log(req.user.id, 'GST_REFUND_CREDITED', 'gst_refund_credits', id, 'amount', null, Number(amount).toFixed(2), `${description}${refusedAmount > 0 ? ` · ₹${money(refusedAmount)} refused` : ''}`);
  flash.set(req, 'success', `Recorded: ${description}. It goes to Zoho Books at the next sync.`);
  res.redirect(BACK);
}

/** A credit typed wrongly: removed only while it is not in Zoho Books yet. */
async function deleteCredit(req, res) {
  const credit = await gstRefundRepository.findCredit(parseInt(req.params.creditId, 10));
  if (!credit) {
    res.status(404).send('Credit not found.');
    return;
  }
  if (credit.zoho_journal_id) {
    flash.set(req, 'error', `This refund credit is already in Zoho Books (journal ${credit.zoho_journal_id}), so it cannot be changed here. Ask the CA to correct it there.`);
    res.redirect(BACK);
    return;
  }
  if (!(await caFyLockGuard.allow(req, String(credit.credited_on).slice(0, 10), 'gst_refund_credits', Number(credit.id), 'delete'))) {
    res.redirect(BACK);
    return;
  }
  await gstRefundRepository.deleteCredit(Number(credit.id));
  await auditLogRepository.log(req.user.id, 'GST_REFUND_CREDIT_REMOVED', 'gst_refund_credits', Number(credit.id), 'amount', Number(credit.amount).toFixed(2), null, paymentDescription.gstRefund(credit));
  flash.set(req, 'success', 'Refund credit removed. Record it again with the right figures.');
  res.redirect(BACK);
}

module.exports = { index, recordClaim, recordCredit, deleteCredit };
