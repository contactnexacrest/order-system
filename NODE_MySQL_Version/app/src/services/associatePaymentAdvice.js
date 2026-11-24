'use strict';

/**
 * Owner decision D-410 point 8 (24 Nov 2026, mockup M14 confirmed twice): the Commission Payment Advice (CPA).
 *  - One advice per bank transfer to an associate: the payouts of one associate paid on the same day with the same
 *    bank reference (UTR / SWIFT). A take-back deducted from them is listed as a deduction.
 *  - Number NC/SC/CPA/A{4-digit associate no.}/{YY}/{NNN}, counted per associate per financial year (1 April; YY =
 *    the year it starts). Made once, when the payouts are marked paid; what it shows is frozen in context_json.
 *  - The MD may correct it: a new advice "Rev n" with a reason; the old one is kept and marked superseded.
 *  - Company details from Admin (company settings); no TAN. Same design as the Quotation / PI / CI (_layout).
 *  - Shows only the associate's commission — never costs, margins or supplier prices.
 * PHP twin: src/Services/AssociatePaymentAdvice.php.
 */
const path = require('path');
const nunjucks = require('nunjucks');
const db = require('../config/db');
const { short } = require('../helpers/dates');
const auditLogRepository = require('../repositories/auditLogRepository');

const TITLE = 'COMMISSION PAYMENT ADVICE';
const HOW_TEXT = "Each commission is your % of the value of the goods (FOB) of the order, fixed when the buyer paid the order in full, at the rate the bank used for the buyer's payment. It does not change later. NexaCrest pays all bank charges.";
const TDS_TEXT = (t) => `The tax deducted (${t}) is deposited against your PAN. The TDS certificate (Form 16A) for this quarter will be available in your associate portal under Commission.`;
const FOOT_TEXT = 'This is a computer-generated advice and needs no signature. It shows only your commission — never costs, margins or supplier prices.';

const money2 = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); // PHP number_format(v, 2)
const ymd = (v) => (v instanceof Date ? v.toISOString().slice(0, 10) : String(v || '').slice(0, 10));
const len = (v) => [...String(v ?? '')].length;
let njk = null;

function associateNo(associateId) {
  return `A${String(Number(associateId)).padStart(4, '0')}`;
}

/** The financial year's two-digit start year: 2026-04-01 … 2027-03-31 → "26". */
function fyYy(date) {
  const d = ymd(date);
  const y = Number(d.slice(0, 4));
  return String(Number(d.slice(5, 7)) >= 4 ? y : y - 1).slice(-2);
}

function number(associateId, fy, seq) {
  return `NC/SC/CPA/${associateNo(associateId)}/${fy}/${String(seq).padStart(3, '0')}`;
}

/** After payouts are marked paid: one advice for each bank transfer among them that has none yet. */
async function afterPaid(payoutIds, userId) {
  const ids = (payoutIds || []).map(Number).filter((n) => n > 0);
  if (!ids.length) return [];
  const rows = await db.query(
    `SELECT id, associate_id, paid_on, paid_reference FROM incentive_payouts
      WHERE id IN (${ids.join(',')}) AND payee_kind = 'associate' AND status = 'paid' AND payment_advice_id IS NULL
        AND clawback_of_payout_id IS NULL AND COALESCE(paid_inr, 0) > 0`
  );
  const groups = new Map();
  for (const r of rows) {
    const key = `${r.associate_id}|${ymd(r.paid_on)}|${r.paid_reference || ''}`;
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key).push(Number(r.id));
  }
  const made = [];
  for (const [key, g] of groups.entries()) {
    try {
      // Paid one by one into the same bank transfer (always so abroad): the transfer's advice is corrected to include it (Rev n).
      const [aId, day, ...rest] = key.split('|');
      const existing = await db.queryOne(
        "SELECT * FROM associate_payment_advices WHERE associate_id = :a AND paid_on = :d AND COALESCE(paid_reference, '') = :r AND superseded_at IS NULL ORDER BY id DESC LIMIT 1",
        { a: Number(aId), d: day, r: rest.join('|') }
      );
      if (existing) {
        const linked = (await db.query('SELECT id FROM incentive_payouts WHERE payment_advice_id = :id', { id: existing.id })).map((x) => Number(x.id));
        made.push(await create([...new Set([...linked, ...g])], userId, existing, 'Another order paid in the same bank transfer was added'));
        continue;
      }
      made.push(await create(g, userId, null, null));
    } catch (e) {
      console.error('[associate payment advice]', e.message); // the payment itself is recorded; the advice can be made again
    }
  }
  return made;
}

/** Makes one advice for these payouts (same associate, day and reference). With supersedes: a correction (Rev n). */
async function create(payoutIds, userId, supersedes, reason) {
  const ids = payoutIds.map(Number).join(',');
  const payouts = await db.query(
    `SELECT p.*, o.order_reference, o.client_id, o.currency_code AS order_currency, o.associate_level_kind, o.associate_level_value, o.associate_rate_pct
       FROM incentive_payouts p JOIN orders o ON o.id = p.order_id WHERE p.id IN (${ids}) ORDER BY o.order_reference, p.id`
  );
  if (!payouts.length) throw new Error('No payouts for the advice.');
  const first = payouts[0];
  const associateId = Number(first.associate_id);
  const paidOn = ymd(first.paid_on);
  const fy = supersedes ? String(supersedes.fy_yy) : fyYy(paidOn);
  const id = await db.transaction(async (t) => {
    let seq;
    let revision;
    if (supersedes) {
      seq = Number(supersedes.seq);
      revision = Number((await t.queryOne('SELECT COALESCE(MAX(revision), 0) + 1 AS n FROM associate_payment_advices WHERE associate_id = :a AND fy_yy = :fy AND seq = :s FOR UPDATE',
        { a: associateId, fy, s: seq })).n);
    } else {
      seq = Number((await t.queryOne('SELECT COALESCE(MAX(seq), 0) + 1 AS n FROM associate_payment_advices WHERE associate_id = :a AND fy_yy = :fy FOR UPDATE', { a: associateId, fy })).n);
      revision = 0;
    }
    const no = number(associateId, fy, seq);
    const ctx = await context(payouts, no, revision, reason);
    const res = await t.execute(
      `INSERT INTO associate_payment_advices (associate_id, advice_no, fy_yy, seq, revision, supersedes_id, revision_reason, paid_on, paid_reference, currency_code, total_paid, context_json, created_by)
       VALUES (:a, :n, :fy, :s, :r, :sup, :why, :d, :ref, :cur, :tot, :ctx, :u)`,
      { a: associateId, n: no, fy, s: seq, r: revision, sup: supersedes ? supersedes.id : null, why: reason, d: paidOn, ref: first.paid_reference ?? null,
        cur: ctx.currency, tot: ctx.total_paid_raw, ctx: JSON.stringify(ctx), u: userId ?? null }
    );
    await t.execute(`UPDATE incentive_payouts SET payment_advice_id = :id WHERE id IN (${ids})`, { id: res.insertId });
    if (supersedes) await t.execute('UPDATE associate_payment_advices SET superseded_at = NOW() WHERE id = :id', { id: supersedes.id });
    return { id: res.insertId, no, revision };
  });
  await auditLogRepository.log(userId ?? null, supersedes ? 'ASSOCIATE_PAYMENT_ADVICE_REVISED' : 'ASSOCIATE_PAYMENT_ADVICE_MADE', 'associate_payment_advices', id.id,
    'advice_no', supersedes ? supersedes.advice_no : null, `${id.no}${id.revision > 0 ? ` Rev ${id.revision}` : ''}${reason !== null && reason !== undefined ? ` — reason: ${reason}` : ''}`);
  return id.id;
}

/** MD correction: a new advice "Rev n" from the payouts as they are now; the old one is kept and marked superseded. */
async function revise(adviceId, reason, userId) {
  const a = await find(adviceId);
  if (!a) return 'That payment advice no longer exists.';
  if (a.superseded_at) return 'This advice was already corrected — correct the latest one.';
  const r = String(reason ?? '');
  if (len(r.trim()) < 10 || len(r) > 300) return 'Give the reason for the correction (10 to 300 characters) — it is kept on the advice and in the Audit Log.';
  const ids = (await db.query('SELECT id FROM incentive_payouts WHERE payment_advice_id = :id', { id: adviceId })).map((x) => Number(x.id));
  if (!ids.length) return 'No payments are linked to this advice.';
  await create(ids, userId, a, r.trim());
  return null;
}

/** Everything the advice prints (frozen). */
async function context(payouts, no, revision, reason) {
  const first = payouts[0];
  const a = (await require('../repositories/associateRepository').find(Number(first.associate_id))) || {};
  const india = require('./associatePayoutService').isIndia(a);
  const cur = india ? 'INR' : String(first.currency_code || 'USD');
  // Rupees in Indian grouping (₹1,98,914.40), as on the confirmed M14 sample; other currencies as usual.
  const inr = (v) => `₹${require('../helpers/moneyFormat').indian(Number(v))}`;
  const m = (v) => (cur === 'INR' ? inr(v) : `${cur} ${money2(v)}`);
  const orderRepository = require('../repositories/orderRepository');
  const orderProductRepository = require('../repositories/orderProductRepository');
  const portal = require('../repositories/associatePortalRepository');
  const lines = [];
  let gross = 0;
  let tds = 0;
  let paid = 0;
  for (const [i, p] of payouts.entries()) {
    const order = (await orderRepository.find(Number(p.order_id))) || {};
    const client = await db.queryOne('SELECT company_legal_name FROM clients WHERE id = :id', { id: p.client_id });
    const lineTds = Number(p.tds_inr || 0);
    const linePaid = india ? Number(p.paid_inr || 0) : Number(p.amount_fc || 0);
    const lineGross = india ? linePaid + lineTds : linePaid;
    gross += lineGross;
    tds += lineTds;
    paid += linePaid;
    const ratePct = p.associate_rate_pct !== null && p.associate_rate_pct !== undefined ? `${Number(p.associate_rate_pct).toFixed(2).replace(/\.?0+$/, '')}%` : '—';
    lines.push({
      no: i + 1,
      order: String(p.order_reference),
      client: String((client && client.company_legal_name) || ''),
      fob: `${p.order_currency} ${money2((await require('./associateCommission').goodsLines(Number(p.order_id))).reduce((t, l) => t + Number(l.fob_value || 0), 0))}`,
      pct: portal.orderRateText(order) || ratePct,
      gross: m(lineGross),
      tds: lineTds > 0 ? inr(lineTds) : '—',
      paid: m(linePaid),
    });
  }
  // Take-backs deducted from these payments (marked paid with "Deducted from payout no. …").
  const refs = payouts.map((p) => `Deducted from payout no. ${Number(p.id)}`);
  const params = { a: Number(first.associate_id) };
  refs.forEach((r, i) => { params[`r${i}`] = r; });
  const deductions = (await db.query(
    `SELECT amount_inr, note FROM incentive_payouts WHERE associate_id = :a AND clawback_of_payout_id IS NOT NULL AND paid_reference IN (${refs.map((_, i) => `:r${i}`).join(',')}) ORDER BY id`, params
  )).map((d) => ({ text: String(d.note || 'Commission taken back'), amount: `− ${inr(Math.abs(Number(d.amount_inr)))}` }));
  const bank = await require('../repositories/associateBankRepository').usable(Number(first.associate_id), ymd(first.paid_on));
  const sectionRate = tds > 0 ? `section ${first.tds_section || '194H'}${first.tds_rate !== null && first.tds_rate !== undefined ? `, ${require('./associateTds').pct(Number(first.tds_rate))}` : ''}` : '';
  const company = await require('./documentDataAssembler').companyBlock();
  const paidOn = short(ymd(first.paid_on));
  const ref = String(first.paid_reference ?? '');
  const bankText = bank ? `${bank.bank_name} · account ending ${bank.account_last4}${bank.ifsc ? ` · IFSC ${bank.ifsc}` : (bank.swift ? ` · SWIFT ${bank.swift}` : '')}`.trim() : '—';
  return {
    doc_title: TITLE,
    number: no,
    revision,
    revision_label: revision > 0 ? `Rev ${revision}` : '',
    revision_reason: reason ?? null,
    paid_on: paidOn,
    paid_reference: ref,
    paid_via: india ? 'Bank transfer (NEFT / RTGS / IMPS)' : 'Bank transfer (SWIFT)',
    associate_no: associateNo(first.associate_id),
    associate: { name: String(a.name || ''), pan: String(a.pan || ''), country: india ? 'India' : String(a.country || '') },
    bank: bankText,
    company: { legal_name: String(company.legal_name || ''), registered_office: String(company.registered_office || ''), gstin: String(company.gstin || ''),
      iec_pan: String(company.iec_pan || ''), phone: String(company.phone || ''), email: String(company.email || '') },
    currency: cur,
    india,
    lines,
    deductions,
    total_gross: m(gross),
    total_tds: tds > 0 ? inr(tds) : '',
    tds_text: sectionRate,
    total_paid: m(paid),
    total_paid_raw: require('../helpers/money').phpRound(paid, 2),
    headline: `PAID: ${m(paid)} was transferred to your bank account on ${paidOn}${ref !== '' ? ` (bank ref ${ref})` : ''}.`,
  };
}

async function find(id) {
  return db.queryOne('SELECT * FROM associate_payment_advices WHERE id = :id', { id: Number(id) || 0 });
}

/** Newest first; the associate sees only their own. */
async function forAssociate(associateId) {
  return db.query('SELECT * FROM associate_payment_advices WHERE associate_id = :a ORDER BY paid_on DESC, id DESC', { a: associateId });
}

/** "Bank transfers made this run" (Accounts): one line per advice whose payouts belong to the incentive month. */
async function forMonth(month) {
  return db.query(
    `SELECT DISTINCT c.*, a.name AS associate_name FROM associate_payment_advices c JOIN associates a ON a.id = c.associate_id
       JOIN incentive_payouts p ON p.payment_advice_id = c.id WHERE p.incentive_month = :m ORDER BY c.paid_on, a.name, c.id`, { m: month }
  );
}

function storedContext(advice) {
  return { ...JSON.parse(String(advice.context_json)), superseded: !!advice.superseded_at };
}

function environment() {
  if (!njk) {
    njk = new nunjucks.Environment(new nunjucks.FileSystemLoader(path.join(__dirname, '..', '..', 'templates'), { noCache: true }), { autoescape: true });
    njk.addFilter('markdown_lite', (s) => new nunjucks.runtime.SafeString(require('../helpers/markdownLite').toHtml(String(s ?? ''))));
  }
  return njk;
}

async function html(advice) {
  const c = storedContext(advice);
  return environment().render('CPA/payment_advice.njk', { ...c, assets: await require('./documentDataAssembler').assetsBlock(), order: {}, watermark: { enabled: false },
    meta: { document_reference: c.number, generated_date: c.paid_on } });
}

async function pdf(advice) {
  return require('./pdfRenderService').renderPdfFromHtml(await html(advice), `${advice.advice_no}${Number(advice.revision) > 0 ? ` Rev ${Number(advice.revision)}` : ''}`);
}

function fileName(advice, ext) {
  return `${String(advice.advice_no).replace(/\//g, '-')}${Number(advice.revision) > 0 ? `-Rev${Number(advice.revision)}` : ''}.${ext}`;
}

/** The Word copy (same content, docxComponents building blocks). */
async function docx(advice) {
  const C = require('./docx/docxComponents');
  const { Document, Packer } = require('docx');
  const c = storedContext(advice);
  const kv = (pairs) => pairs.map(([label, value]) => ({ label, value }));
  const children = [];
  const add = (x) => { children.push(...[].concat(x)); };
  add(C.header({ company: c.company, assets: await require('./documentDataAssembler').assetsBlock() }, TITLE, null));
  add(C.metaBar(kv([['ADVICE NO.', `${c.number}${c.revision_label ? ` ${c.revision_label}` : ''}`], ['PAID ON', c.paid_on], ['BANK REFERENCE', c.paid_reference || '—'], ['ASSOCIATE NO.', c.associate_no]])));
  add(C.plain(c.headline, { bold: true, color: '2D7D56' }));
  if (c.revision_label) add(C.plain(`${c.revision_label} — corrected: ${c.revision_reason}. It replaces the earlier advice with the same number.`, { italics: true }));
  add(C.sectionTitle('1. PAID BY'));
  add(C.kvTable(kv([['Company / Legal Entity', c.company.legal_name], ['Registered Office', c.company.registered_office], ['GSTIN', c.company.gstin], ['IEC / PAN', c.company.iec_pan],
    ['Email / Phone', [c.company.email, c.company.phone].filter(Boolean).join(' · ')]])));
  add(C.sectionTitle('2. PAID TO'));
  add(C.kvTable(kv([['Associate', `${c.associate.name} (${c.associate_no})`], ...(c.associate.pan ? [['PAN', c.associate.pan]] : []), ['Bank Account', c.bank],
    ['Paid Via', `${c.paid_via}${c.paid_reference ? ` · bank ref ${c.paid_reference}` : ''}`]])));
  add(C.sectionTitle('3. COMMISSION PAID'));
  add(C.productsTable(['#', 'Order', 'Client', 'Value of the goods (FOB)', 'Your %', 'Commission (before tax)', 'TDS', 'Paid to you'], [4, 14, 20, 15, 8, 14, 11, 14],
    c.lines.map((l) => ({ cells: [String(l.no), l.order, l.client, l.fob, l.pct, l.gross, l.tds, l.paid], lineColor: l.no % 2 === 1 ? '1D6FA8' : '2D7D56',
      align: [null, null, null, 'right', 'right', 'right', 'right', 'right'] }))));
  const totals = kv([['Commission before tax', c.total_gross], ...c.deductions.map((d) => [d.text, d.amount]),
    ...(c.total_tds ? [[`Less: tax deducted at source (TDS) — ${c.tds_text}`, c.total_tds]] : [])]);
  totals.push({ label: 'TOTAL PAID TO YOUR BANK', value: c.total_paid, highlight: true });
  add(C.totalsTable(totals));
  add(C.sectionTitle('4. HOW THE COMMISSION IS WORKED OUT'));
  add(C.plain(HOW_TEXT));
  if (c.total_tds) {
    add(C.sectionTitle('5. TAX (TDS)'));
    add(C.plain(TDS_TEXT(c.total_tds)));
  }
  add(C.plain(FOOT_TEXT, { italics: true, size: 16 }));
  const doc = new Document({ styles: C.documentDefaultStyles(), sections: [{ properties: { page: C.PAGE_A4 }, children }] });
  return Packer.toBuffer(doc);
}

module.exports = { TITLE, associateNo, fyYy, number, afterPaid, create, revise, context, find, forAssociate, forMonth, storedContext, html, pdf, fileName, docx };
