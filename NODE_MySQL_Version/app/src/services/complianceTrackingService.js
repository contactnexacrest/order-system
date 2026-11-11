'use strict';

const db = require('../config/db');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const departmentRepository = require('../repositories/departmentRepository');
const notificationRepository = require('../repositories/notificationRepository');
const permissionRepository = require('../repositories/permissionRepository');

/**
 * Port of ComplianceTrackingService.php. Owner decision (2 Nov 2026, gap-list Q-6 —
 * "go with your recommendation"):
 *  (a) eBRC / FIRC never blocks Mark Fully Settled — a 13h warning, a monthly
 *      "Missing eBRC / FIRC" report and a monthly reminder instead;
 *  (b) ECGC — the buyer's ECGC-approved limit, a warning when open exposure is
 *      above it, and the monthly shipment declaration (by the 10th of the month after the BL);
 *  (c) export incentives — sanctioned amount, claim deadline (default per scheme
 *      from company settings, to be confirmed with the CA) and claim status, with
 *      reminders 30 and 7 days before the deadline.
 * Same rules and wording as the PHP stack.
 */

const LEGS = { advance: 'Advance', freight: 'Freight & insurance (Debit Note)', balance: 'Balance' };
const CLAIM_STATUSES = { filed: 'Filed', sanctioned: 'Sanctioned', received: 'Received', rejected: 'Rejected' };

function ymd(v) {
  if (v === null || v === undefined || v === '') return null;
  if (v instanceof Date) {
    const p = (n) => String(n).padStart(2, '0');
    return `${v.getFullYear()}-${p(v.getMonth() + 1)}-${p(v.getDate())}`;
  }
  return String(v).slice(0, 10);
}

function todayStr() {
  return ymd(new Date());
}

function daysBetween(from, to) {
  return Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86400000);
}

function addDays(date, days) {
  const d = new Date(`${date}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}

function fmt(n) {
  return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

async function setting(key, fallback = '') {
  const v = await companySettingsRepository.get(key);
  return v === null || v === undefined ? fallback : String(v);
}

// ------------------------------------------------------------------ (a)

async function missingFirc(where, params) {
  const rows = await db.query(
    `SELECT o.id, o.order_reference, o.currency_code, c.company_legal_name,
            ps.advance_amount, ps.advance_remittance_received_at, ps.advance_firc_reference,
            ps.freight_amount, ps.freight_remittance_received_at, ps.freight_firc_reference,
            ps.balance_amount, ps.balance_remittance_received_at, ps.balance_firc_reference,
            lc.lc_received_at, lc.lc_received_amount, lc.lc_firc_reference,
            (SELECT GROUP_CONCAT(u.name ORDER BY u.name SEPARATOR ', ') FROM order_assignees oa JOIN users u ON u.id = oa.user_id WHERE oa.order_id = o.id) AS owners
       FROM orders o
       JOIN clients c ON c.id = o.client_id
       LEFT JOIN order_payment_status ps ON ps.order_id = o.id
       LEFT JOIN order_lc_details lc ON lc.order_id = o.id
     ${where}
      ORDER BY o.id`,
    params
  );
  const today = todayStr();
  const out = [];
  for (const r of rows) {
    const legs = [];
    for (const [leg, label] of Object.entries(LEGS)) {
      if (r[`${leg}_remittance_received_at`] && String(r[`${leg}_firc_reference`] || '').trim() === '') {
        legs.push([leg, label, Number(r[`${leg}_amount`] || 0), r[`${leg}_remittance_received_at`]]);
      }
    }
    if (r.lc_received_at && String(r.lc_firc_reference || '').trim() === '') {
      legs.push(['lc', 'Letter of Credit', Number(r.lc_received_amount || 0), r.lc_received_at]);
    }
    for (const [leg, label, amount, receivedAt] of legs) {
      const received = ymd(receivedAt);
      out.push({
        order_id: Number(r.id),
        order_reference: r.order_reference,
        client: r.company_legal_name,
        leg,
        label,
        amount,
        currency: r.currency_code || '',
        received_at: received,
        days: daysBetween(received, today),
        owners: r.owners || '',
      });
    }
  }
  return out;
}

function missingFircLegs(orderId) {
  return missingFirc('WHERE o.id = :id', { id: orderId });
}

function missingFircReport() {
  return missingFirc("WHERE o.status <> 'lost' AND COALESCE(o.is_test_data, 0) = 0", {});
}

// ------------------------------------------------------------------ (b)

async function ecgcPosition(clientId) {
  const client = (await db.queryOne(
    'SELECT ecgc_limit_amount, ecgc_limit_currency, ecgc_limit_approved_on, ecgc_limit_valid_to, ecgc_limit_ref, ecgc_limit_file_id FROM clients WHERE id = :id',
    { id: clientId }
  )) || {};
  const rows = await db.query(
    `SELECT o.id, o.currency_code,
            (SELECT COALESCE(SUM(p.fob_value), 0) FROM order_products p WHERE p.order_id = o.id AND p.is_active = 1 AND p.line_type <> 'service') AS fob,
            ps.advance_amount, ps.advance_remittance_received_at, ps.balance_amount, ps.balance_remittance_received_at
       FROM orders o
       LEFT JOIN order_payment_status ps ON ps.order_id = o.id
      WHERE o.client_id = :c AND o.status = 'active' AND COALESCE(o.is_test_data, 0) = 0`,
    { c: clientId }
  );
  const exposure = {};
  let open = 0;
  for (const row of rows) {
    const received = (row.advance_remittance_received_at ? Number(row.advance_amount || 0) : 0)
      + (row.balance_remittance_received_at ? Number(row.balance_amount || 0) : 0);
    const outstanding = Math.max(0, Math.round((Number(row.fob) - received) * 100) / 100);
    const ccy = row.currency_code || '';
    exposure[ccy] = Math.round(((exposure[ccy] || 0) + outstanding) * 100) / 100;
    open++;
  }
  const limit = client.ecgc_limit_amount !== null && client.ecgc_limit_amount !== undefined ? Number(client.ecgc_limit_amount) : null;
  const currency = client.ecgc_limit_currency || null;
  const validTo = ymd(client.ecgc_limit_valid_to);
  const expired = validTo !== null && validTo < todayStr();
  const policyNo = (await setting('ecgc_policy_no')).trim();

  let overBy = 0;
  let warning = null;
  if (limit !== null) {
    const inLimitCcy = exposure[currency || ''] || 0;
    overBy = Math.round((inLimitCcy - limit) * 100) / 100;
    if (expired) {
      warning = `The ECGC credit limit for this buyer expired on ${validTo} — ask ECGC to renew it before shipping.`;
    } else if (overBy > 0) {
      warning = `Open orders for this buyer (${currency} ${fmt(inLimitCcy)}) are above the ECGC-approved credit limit (${currency} ${fmt(limit)}) by ${currency} ${fmt(overBy)} — the amount above the limit is not covered.`;
    }
    for (const [ccy, amt] of Object.entries(exposure)) {
      if (ccy !== currency && amt > 0 && warning === null) {
        warning = `Some open orders are in ${ccy}, but the ECGC limit is in ${currency} — check the cover with ECGC.`;
      }
    }
  } else if (policyNo !== '' && open > 0) {
    warning = 'No ECGC credit limit is recorded for this buyer — shipments to this buyer are not covered until ECGC approves a limit.';
  }
  return {
    policy_no: policyNo,
    cover_pct: await setting('ecgc_policy_cover_pct'),
    limit,
    currency,
    valid_to: validTo,
    approved_on: ymd(client.ecgc_limit_approved_on),
    ref: client.ecgc_limit_ref || null,
    file_id: client.ecgc_limit_file_id ? Number(client.ecgc_limit_file_id) : null,
    expired,
    exposure,
    open_orders: open,
    over_limit: limit !== null && (expired || overBy > 0),
    over_by: Math.max(0, overBy),
    warning,
  };
}

async function declarationDue(blDate) {
  const day = Math.max(1, Math.min(28, parseInt(await setting('ecgc_declaration_day', '10'), 10) || 10));
  const d = new Date(`${ymd(blDate)}T00:00:00Z`);
  const y = d.getUTCMonth() === 11 ? d.getUTCFullYear() + 1 : d.getUTCFullYear();
  const m = (d.getUTCMonth() + 1) % 12 + 1;
  return `${y}-${String(m).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

async function declarationStatus(orderId, today = null) {
  const r = (await db.queryOne(
    'SELECT o.ecgc_declared_on, o.ecgc_declaration_ref, s.bl_date FROM orders o LEFT JOIN order_shipping s ON s.order_id = o.id WHERE o.id = :id',
    { id: orderId }
  )) || {};
  const t = today || todayStr();
  const declared = ymd(r.ecgc_declared_on);
  const bl = ymd(r.bl_date);
  const due = bl ? await declarationDue(bl) : null;
  if (declared) {
    return { state: 'declared', due, declared_on: declared, ref: r.ecgc_declaration_ref || null, bl_date: bl,
      text: `Declared to ECGC on ${declared}${r.ecgc_declaration_ref ? ` (${r.ecgc_declaration_ref})` : ''}.` };
  }
  if (!bl) {
    return { state: 'not_due', due: null, declared_on: null, ref: null, bl_date: null, text: 'To be declared to ECGC once the BL is issued.' };
  }
  const state = t > due ? 'overdue' : 'due';
  return { state, due, declared_on: null, ref: null, bl_date: bl,
    text: state === 'overdue'
      ? `Not declared to ECGC — it was due by ${due}. A shipment not declared on time is not covered.`
      : `Declare this shipment to ECGC by ${due} (BL dated ${bl}).` };
}

// ------------------------------------------------------------------ (c)

function schemeSettingKey(scheme) {
  const s = String(scheme || '').trim().toLowerCase();
  if (s === 'rodtep') return 'benefit_deadline_days_rodtep';
  if (s === 'rosctl') return 'benefit_deadline_days_rosctl';
  if (s.includes('drawback')) return 'benefit_deadline_days_duty_drawback';
  return 'benefit_deadline_days_other';
}

async function defaultDeadline(orderId, scheme) {
  const days = parseInt(await setting(schemeSettingKey(scheme), '0'), 10) || 0;
  if (days <= 0) return null;
  const s = (await db.queryOne('SELECT shipping_bill_date, bl_date FROM order_shipping WHERE order_id = :id', { id: orderId })) || {};
  const base = ymd(s.shipping_bill_date) || ymd(s.bl_date);
  return base ? addDays(base, days) : null;
}

async function claimView(claim, today = null) {
  let status = claim.received_amount !== null && claim.received_amount !== undefined ? 'received' : (claim.claim_status || 'filed');
  if (status === 'received' && (claim.received_amount === null || claim.received_amount === undefined)) status = 'filed';
  let deadline = ymd(claim.claim_deadline);
  let isDefault = false;
  if (!deadline && claim.order_id) {
    deadline = await defaultDeadline(Number(claim.order_id), claim.scheme_name);
    isDefault = deadline !== null;
  }
  const daysLeft = deadline ? daysBetween(today || todayStr(), deadline) : null;
  return {
    ...claim,
    claimed_at: ymd(claim.claimed_at),
    sanctioned_at: ymd(claim.sanctioned_at),
    claim_deadline: ymd(claim.claim_deadline),
    received_at: ymd(claim.received_at),
    effective_status: status,
    status_label: CLAIM_STATUSES[status] || status,
    effective_deadline: deadline,
    deadline_is_default: isDefault,
    days_left: daysLeft,
  };
}

async function saveClaimTracking(claim, input) {
  const status = String(input.claim_status || '');
  if (!['filed', 'sanctioned', 'rejected'].includes(status)) {
    return 'Choose the claim status (Filed, Sanctioned or Rejected) — "Received" is set when the money is recorded.';
  }
  const received = claim.received_amount !== null && claim.received_amount !== undefined;
  if (received && status !== 'sanctioned' && status !== 'filed') {
    return 'This claim has already been received — it cannot be marked rejected.';
  }
  const sanctioned = String(input.sanctioned_amount || '').trim();
  const sanctionedAt = String(input.sanctioned_at || '').trim();
  const deadline = String(input.claim_deadline || '').trim();
  const reason = String(input.rejected_reason || '').trim();
  if (sanctioned !== '' && (!/^-?\d+(\.\d+)?$/.test(sanctioned) || Number(sanctioned) < 0 || Number(sanctioned) > 999999999)) {
    return 'Enter the sanctioned amount as a number (₹), 0 or more.';
  }
  if (status === 'sanctioned' && sanctioned === '') return 'Enter the amount sanctioned.';
  for (const [what, d] of [['sanctioned date', sanctionedAt], ['claim deadline', deadline]]) {
    if (d !== '' && !/^\d{4}-\d{2}-\d{2}$/.test(d)) return `Enter the ${what} as a date.`;
  }
  if (status === 'rejected' && [...reason].length < 10) return 'Say why the claim was rejected (at least 10 characters).';
  await db.execute(
    'UPDATE ca_export_benefits SET claim_status = :st, sanctioned_amount = :sa, sanctioned_at = :sd, claim_deadline = :dl, rejected_reason = :rr WHERE id = :id',
    {
      st: received ? 'received' : status,
      sa: sanctioned === '' ? null : Math.round(Number(sanctioned) * 100) / 100,
      sd: sanctionedAt || null,
      dl: deadline || null,
      rr: status === 'rejected' ? reason.slice(0, 500) : null,
      id: Number(claim.id),
    }
  );
  return null;
}

// ------------------------------------------------------------- reminders

async function exportComplianceManagers() {
  const roles = await db.query("SELECT id FROM roles WHERE name IN ('Export Compliance Manager', 'Export Compliance Director')");
  const roleIds = roles.map((r) => Number(r.id));
  if (roleIds.length === 0) return [];
  const out = [];
  for (const u of await db.query('SELECT id, role_id FROM users WHERE is_active = 1')) {
    let ids = (await departmentRepository.activeRoleIdsForUser(Number(u.id))).map(Number);
    if (ids.length === 0 && u.role_id !== null) ids = [Number(u.role_id)];
    if (ids.some((id) => roleIds.includes(id))) out.push(Number(u.id));
  }
  return out;
}

async function once(kind, ref, threshold, today) {
  const r = await db.execute('INSERT IGNORE INTO compliance_alerts_sent (alert_kind, ref_key, threshold, sent_on) VALUES (:k, :r, :t, :d)',
    { k: kind, r: ref, t: threshold, d: today });
  return (r.affectedRows || 0) > 0;
}

/**
 * D-281: everyone in userIds gets the in-app notice; the e-mail goes to emailIds
 * (an order alert: the department on the order; a company item: its responsible
 * person and backup). null = everyone, as before.
 */
async function notify(userIds, type, orderId, message, emailIds = null) {
  if (emailIds !== null) {
    return (await notificationRepository.notifyRouted(userIds, emailIds, type, orderId, message)).length;
  }
  let n = 0;
  for (const u of [...new Set(userIds.map(Number))]) {
    await notificationRepository.create(u, null, type, orderId, message);
    n++;
  }
  return n;
}

async function runReminders(today = null) {
  const t = today || todayStr();
  const finance = (await permissionRepository.usersWithPermission('ca_module_manage')).map(Number);
  const managers = await exportComplianceManagers();
  let created = 0;

  // (a) monthly: missing eBRC / FIRC
  const missing = await missingFircReport();
  if (missing.length > 0 && await once('ebrc_monthly', t.slice(0, 7), 'monthly', t)) {
    const orders = new Set(missing.map((m) => m.order_id)).size;
    created += await notify([...finance, ...managers], 'ebrc_missing_monthly', null,
      `Missing eBRC / FIRC: ${missing.length} received payment(s) on ${orders} order(s) still have no FIRC / eBRC reference — see Reports → Missing eBRC / FIRC.`,
      await require('./staffRouting').companyItem('ebrc_monthly'));
  }

  // (b) ECGC shipment declarations and the policy's expiry
  const policyNo = (await setting('ecgc_policy_no')).trim();
  if (policyNo !== '') {
    const rows = await db.query(
      `SELECT o.id, o.order_reference FROM orders o JOIN order_shipping s ON s.order_id = o.id
        WHERE s.bl_date IS NOT NULL AND o.ecgc_declared_on IS NULL AND o.status <> 'lost' AND COALESCE(o.is_test_data, 0) = 0`
    );
    for (const r of rows) {
      const st = await declarationStatus(Number(r.id), t);
      const due = st.due;
      if (t < `${due.slice(0, 8)}01`) continue; // BL month not over yet
      const threshold = st.state === 'overdue' ? 'overdue' : 'due';
      if (await once('ecgc_declaration', String(r.id), threshold, t)) {
        created += await notify(managers.length > 0 ? managers : finance, `ecgc_declaration_${threshold}`, Number(r.id),
          threshold === 'overdue'
            ? `Order ${r.order_reference}: the shipment was not declared to ECGC by ${due} — declare it now (a late declaration may not be covered).`
            : `Order ${r.order_reference}: declare the shipment to ECGC by ${due}.`,
          (await require('./staffRouting').forOrder(Number(r.id), 'EXPORT_COMPLIANCE')).email);
      }
    }
    const policyTo = (await setting('ecgc_policy_valid_to')).trim();
    if (policyTo !== '' && policyTo >= t && policyTo <= addDays(t, 30) && await once('ecgc_policy', policyTo, '30', t)) {
      created += await notify([...(await permissionRepository.usersWithPermission('manage_company_settings')).map(Number), ...managers], 'ecgc_policy_expiring', null,
        `The ECGC policy ${policyNo} ends on ${policyTo} — renew it so new shipments stay covered.`, await require('./staffRouting').companyItem('ecgc_policy'));
    }
  }

  // (c) incentive claim deadlines — 30 and 7 days before
  const claims = await db.query(
    `SELECT b.*, o.order_reference FROM ca_export_benefits b JOIN orders o ON o.id = b.order_id
      WHERE b.received_amount IS NULL AND COALESCE(b.claim_status, 'filed') NOT IN ('rejected', 'received')`
  );
  for (const claim of claims) {
    const v = await claimView(claim, t);
    if (v.effective_deadline === null || v.days_left === null || v.days_left < 0) continue;
    for (const th of [7, 30]) {
      if (v.days_left <= th) {
        if (await once('benefit_deadline', `${claim.id}@${v.effective_deadline}`, String(th), t)) {
          created += await notify([...managers, ...finance], 'benefit_claim_deadline', Number(claim.order_id),
            `Order ${claim.order_reference}: the ${claim.scheme_name} claim must be completed by ${v.effective_deadline} (${v.days_left} day(s) left).`,
            (await require('./staffRouting').forOrder(Number(claim.order_id), 'EXPORT_COMPLIANCE')).email);
        }
        break;
      }
    }
  }
  return created;
}

module.exports = {
  LEGS, CLAIM_STATUSES, missingFircLegs, missingFircReport, ecgcPosition, declarationDue, declarationStatus,
  schemeSettingKey, defaultDeadline, claimView, saveClaimTracking, exportComplianceManagers, runReminders, todayStr,
};
