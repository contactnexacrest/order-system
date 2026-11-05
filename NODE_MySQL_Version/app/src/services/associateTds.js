'use strict';

/**
 * Owner decision 2 Nov 2026 (D-235): TDS on associate commission, worked out
 * by the app — port of App\Services\AssociateTds (same rules, same wording).
 *
 * India (section 194H): no TDS while the associate's commission in the
 * financial year (April–March) stays within the threshold (₹20,000). The
 * payment that takes the year past it carries TDS on itself plus a catch-up
 * on the commission paid earlier that year without TDS; every later payment
 * carries TDS on its full amount. 2%; 20% (206AA) without a verified PAN.
 * Rounded to the nearest rupee (section 288B).
 * Abroad: no TDS by default once TRC, Form 10F and the no-PE declaration are
 * accepted; each payout needs Form 15CA (and 15CB, setting) before it is paid.
 */

const db = require('../config/db');
const associateRepository = require('../repositories/associateRepository');
const incentiveRepository = require('../repositories/incentiveRepository');

const FOREIGN_DOCS = { tax_residency: 'Tax Residency Certificate (TRC)', form_10f: 'Form 10F', no_pe: 'No-PE declaration' };

const { round2 } = require('../helpers/money'); // PHP round(x, 2) (fresh-eyes review M3)
const money = (n) => Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const iso = (d) => (d instanceof Date ? `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}` : String(d).slice(0, 10));

async function settings() {
  const s = await incentiveRepository.settings();
  return {
    section: String(s.associate_tds_section ?? '194H').trim() || '194H',
    rate: Number(s.associate_tds_pct ?? 2),
    threshold: Number(s.associate_tds_threshold_inr ?? 20000),
    no_pan_rate: Number(s.associate_tds_no_pan_pct ?? 20),
    foreign: String(s.associate_tds_foreign ?? '0') === '1',
    cb_required: String(s.associate_15cb_required ?? '1') === '1',
    zoho_tax_id: String(s.zoho_tds_tax_id ?? '').trim(),
  };
}

function fyBounds(date) {
  const y = parseInt(String(date).slice(0, 4), 10);
  const m = parseInt(String(date).slice(5, 7), 10);
  const start = m >= 4 ? y : y - 1;
  return [`${start}-04-01`, `${start + 1}-03-31`];
}

function fyLabel(date) {
  const start = parseInt(fyBounds(date)[0].slice(0, 4), 10);
  return `FY ${start}-${String(start + 1).slice(2)}`;
}

function quarter(date) {
  const m = parseInt(String(date).slice(5, 7), 10);
  return m >= 4 ? Math.floor((m - 4) / 3) + 1 : 4;
}

function isAbroad(associate) {
  return (associate.based_in || 'india') !== 'india';
}

function pct(rate) {
  return `${Number(rate).toFixed(2).replace(/\.?0+$/, '')}%`;
}

async function panVerified(associate) {
  const pan = String(associate.pan || '').trim().toUpperCase();
  if (!/^[A-Z]{5}[0-9]{4}[A-Z]$/.test(pan)) return false;
  const row = await db.queryOne("SELECT 1 AS ok FROM associate_documents WHERE associate_id = :a AND kind = 'pan' AND status = 'accepted' LIMIT 1", { a: associate.id });
  return !!row;
}

async function missingForeignDocs(associateId) {
  const rows = await db.query("SELECT DISTINCT kind FROM associate_documents WHERE associate_id = :a AND status = 'accepted'", { a: associateId });
  const have = new Set(rows.map((r) => r.kind));
  return Object.entries(FOREIGN_DOCS).filter(([k]) => !have.has(k)).map(([, label]) => label);
}

async function missingPayoutDocs(payoutId) {
  const rows = await db.query("SELECT DISTINCT kind FROM associate_documents WHERE payout_id = :p AND status <> 'rejected'", { p: payoutId });
  const have = new Set(rows.map((r) => r.kind));
  const missing = [];
  if (!have.has('form_15ca')) missing.push('Form 15CA');
  if ((await settings()).cb_required && !have.has('form_15cb')) missing.push('Form 15CB');
  return missing;
}

async function compute(payout, paidOn) {
  const s = await settings();
  const gross = round2(payout.amount_inr);
  const associate = (await associateRepository.find(Number(payout.associate_id))) || { id: Number(payout.associate_id), based_in: 'india' };
  const result = { gross, rate: 0, tds: 0, net: gross, section: null, reason: '', catch_up: 0, abroad: isAbroad(associate), missing_foreign: [], missing_payout: [], fy_total: 0 };
  if (result.abroad) {
    result.missing_foreign = await missingForeignDocs(Number(associate.id));
    result.missing_payout = payout.id ? await missingPayoutDocs(Number(payout.id)) : [];
    if (!s.foreign) {
      result.reason = result.missing_foreign.length === 0
        ? 'abroad — no TDS (TRC/10F/no-PE on file)'
        : `abroad — no TDS once on file: ${result.missing_foreign.join(', ')}`;
      return result;
    }
  }
  const [from, to] = fyBounds(paidOn);
  const prior = await db.queryOne(
    `SELECT COALESCE(SUM(amount_inr), 0) AS gross, COALESCE(SUM(CASE WHEN COALESCE(tds_inr, 0) = 0 THEN amount_inr ELSE 0 END), 0) AS untaxed
       FROM incentive_payouts
      WHERE associate_id = :a AND payee_kind = 'associate' AND status = 'paid' AND amount_inr > 0 AND clawback_of_payout_id IS NULL
        AND paid_on BETWEEN :f AND :t AND id <> :id`,
    { a: associate.id, f: from, t: to, id: Number(payout.id || 0) }
  );
  const priorGross = round2(prior.gross);
  result.fy_total = round2(priorGross + gross);
  const panOk = await panVerified(associate);
  const rate = panOk ? s.rate : s.no_pan_rate;
  if (result.fy_total <= s.threshold + 0.005) {
    result.reason = `below threshold — ${fyLabel(paidOn)} total ₹${money(result.fy_total)} of ₹${money(s.threshold)}`;
    return result;
  }
  // Catch-up only on the payment that takes the year past the threshold; once past it, each payment carries TDS on itself.
  const catchUp = priorGross <= s.threshold + 0.005 ? round2(prior.untaxed) : 0;
  const tds = Math.min(gross, Math.round((gross + catchUp) * rate / 100));
  result.rate = rate;
  result.tds = tds;
  result.net = round2(gross - tds);
  result.section = panOk ? s.section : '206AA';
  result.catch_up = catchUp;
  result.reason = (panOk ? `${s.section} ${pct(rate)}` : `no PAN ${pct(rate)} (206AA)`)
    + (catchUp > 0 ? ` — includes catch-up on ₹${money(catchUp)} paid earlier this ${fyLabel(paidOn)} without TDS` : '');
  return result;
}

/** Owner rule D-212: the description of an associate commission payment, from the facts; fresh-eyes review M5: the GST paid on top is named too (the Zoho payment amount includes it). */
function paymentDescription(associateName, orderRefs, gross, tds, rate, section, net, reference, defaultSection = '194H', gst = 0) {
  const tdsPart = tds > 0
    ? `TDS u/s ${section || defaultSection} ₹${money(tds)}${rate !== null && rate !== undefined && Number(rate) > 0 ? ` (${pct(rate)})` : ''}`
    : 'no TDS';
  return `Associate commission for ${associateName} — order${orderRefs.length > 1 ? 's ' : ' '}${orderRefs.join(', ')}`
    + ` — gross ₹${money(gross)}, ${tdsPart}, net paid ₹${money(net)}`
    + (Number(gst) > 0 ? ` + GST ₹${money(gst)} on the associate's tax invoice (input credit), total paid ₹${money(round2(Number(net) + Number(gst)))}` : '')
    + `${reference !== '' ? ` · UTR ${reference}` : ''}`;
}

async function register(fyStart, q = null) {
  const to = `${parseInt(fyStart.slice(0, 4), 10) + 1}-03-31`;
  const rows = await db.query(
    `SELECT p.id, p.paid_on, p.amount_inr, p.paid_inr, p.tds_inr, p.tds_rate, p.tds_section, p.tds_reason, p.tds_challan_no, p.tds_challan_date, p.paid_reference,
            a.id AS associate_id, a.name, a.pan, a.based_in, a.country, o.order_reference, o.buyer_inquiry_ref,
            (SELECT COUNT(*) FROM associate_documents d WHERE d.associate_id = a.id AND d.kind = 'form_16a' AND d.status <> 'rejected'
                AND d.created_at >= :f2) AS form16a_count
       FROM incentive_payouts p JOIN associates a ON a.id = p.associate_id JOIN orders o ON o.id = p.order_id
      WHERE p.payee_kind = 'associate' AND p.status = 'paid' AND p.amount_inr > 0 AND p.clawback_of_payout_id IS NULL
        AND p.paid_on BETWEEN :f AND :t
      ORDER BY p.paid_on, p.id`,
    { f: fyStart, t: to, f2: fyStart }
  );
  for (const r of rows) {
    r.paid_on = iso(r.paid_on);
    if (r.tds_challan_date) r.tds_challan_date = iso(r.tds_challan_date);
    r.quarter = quarter(r.paid_on);
  }
  return q === null ? rows : rows.filter((r) => r.quarter === q);
}

function lastDayOfMonth(date) {
  const y = parseInt(date.slice(0, 4), 10);
  const m = parseInt(date.slice(5, 7), 10);
  return new Date(Date.UTC(y, m, 0)).getUTCDate();
}

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

async function reminders(today) {
  const out = [];
  const day = parseInt(today.slice(8, 10), 10);
  const month = parseInt(today.slice(5, 7), 10);
  const year = parseInt(today.slice(0, 4), 10);
  if (day <= 7) {
    const pm = month === 1 ? 12 : month - 1;
    const py = month === 1 ? year - 1 : year;
    const prevStart = `${py}-${String(pm).padStart(2, '0')}-01`;
    const prevEnd = `${py}-${String(pm).padStart(2, '0')}-${String(lastDayOfMonth(prevStart)).padStart(2, '0')}`;
    const r = await db.queryOne(
      `SELECT COALESCE(SUM(tds_inr), 0) AS tds, COUNT(*) AS n FROM incentive_payouts
        WHERE payee_kind = 'associate' AND status = 'paid' AND tds_inr > 0 AND (tds_challan_no IS NULL OR tds_challan_no = '')
          AND paid_on BETWEEN :f AND :t`, { f: prevStart, t: prevEnd }
    );
    if (Number(r.tds) > 0) {
      out.push({ type: 'associate_tds_deposit', message: `Deposit the TDS of ₹${money(r.tds)} deducted on associate commission in ${MONTHS[pm - 1]} ${py} (${Number(r.n)} payment(s)) by 7 ${MONTHS[month - 1]} ${year} with challan 281, then enter the challan no. in Associates → TDS register.` });
    }
  }
  const dueMonths = { 7: 1, 10: 2, 1: 3, 5: 4 };
  if (dueMonths[month] && day >= 20) {
    const q = dueMonths[month];
    const fyStartYear = q === 4 ? year - 1 : (year - (q === 3 ? 1 : 0));
    const rows = await register(`${fyStartYear}-04-01`, q);
    const tds = rows.reduce((sum, r) => sum + Number(r.tds_inr || 0), 0);
    if (tds > 0) {
      out.push({ type: 'associate_tds_26q', message: `File Form 26Q for Q${q} of FY ${fyStartYear}-${String(fyStartYear + 1).slice(2)} (TDS on associate commission ₹${money(tds)}) by ${lastDayOfMonth(today)} ${MONTHS[month - 1]} ${year}, then upload each associate's Form 16A.` });
    }
  }
  return out;
}

module.exports = {
  FOREIGN_DOCS, settings, fyBounds, fyLabel, quarter, isAbroad, pct, panVerified, missingForeignDocs, missingPayoutDocs, compute, paymentDescription, register, reminders,
};
