'use strict';

const db = require('../config/db');
const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const remittanceDifference = require('../services/remittanceDifference');

// Port of App\Controllers\RemittanceReasonController — owner follow-up on
// point 18 (2 Nov 2026): Admin → Settings → Remittance difference reasons,
// the list behind "Why is it different?" on the buyer remittance forms. Each
// reason is treated as bank charges, buyer paid less or buyer paid more;
// once used on a receipt its treatment is fixed (switch it off and add a new
// one instead). Also lists the reasons typed in by hand ("Other"), most used
// first. Gated by manage_company_settings.

const BACK = '/settings/remittance-reasons';

function cleanLabel(raw) {
  return String(raw == null ? '' : raw).replace(/\s+/g, ' ').trim();
}

async function find(id) {
  return db.queryOne('SELECT * FROM remittance_difference_reasons WHERE id = :id', { id });
}

async function uses(id) {
  const row = await db.queryOne('SELECT COUNT(*) AS n FROM order_payment_receipts WHERE difference_reason_id = :id', { id });
  return Number(row ? row.n : 0);
}

async function check(label, treatment, exceptId = null) {
  if (label.length < 3 || label.length > 150) {
    return 'Give the reason a name of 3 to 150 characters.';
  }
  if (!remittanceDifference.TREATMENTS[treatment]) {
    return 'Choose how the reason is treated: bank charges, buyer paid less, or buyer paid more.';
  }
  const dup = await db.queryOne('SELECT id FROM remittance_difference_reasons WHERE LOWER(label) = LOWER(:l) AND id <> :id LIMIT 1', { l: label, id: exceptId || 0 });
  if (dup) {
    return `A reason called "${label}" is already in the list.`;
  }
  return null;
}

function fail(req, res, message) {
  flash.set(req, 'error', message);
  res.redirect(BACK);
}

async function index(req, res) {
  const usage = {};
  for (const row of await db.query('SELECT difference_reason_id AS id, COUNT(*) AS n FROM order_payment_receipts WHERE difference_reason_id IS NOT NULL GROUP BY difference_reason_id')) {
    usage[Number(row.id)] = Number(row.n);
  }
  const custom = (await remittanceDifference.customReasonsUsed()).map((c) => ({
    ...c,
    uses: Number(c.uses),
    last_used_day: c.last_used instanceof Date ? c.last_used.toISOString().slice(0, 10) : String(c.last_used || '').slice(0, 10),
  }));
  res.renderView('settings/remittance_reasons', {
    reasons: await remittanceDifference.reasons(false),
    usage,
    custom,
    treatments: remittanceDifference.TREATMENTS,
  });
}

async function create(req, res) {
  const label = cleanLabel(req.body.label);
  const treatment = String(req.body.treatment || '');
  const error = await check(label, treatment);
  if (error) {
    fail(req, res, error);
    return;
  }
  let sort = parseInt(req.body.sort_order, 10) || 0;
  if (sort <= 0) {
    const row = await db.queryOne('SELECT COALESCE(MAX(sort_order), 0) + 10 AS s FROM remittance_difference_reasons');
    sort = Number(row.s);
  }
  const result = await db.execute('INSERT INTO remittance_difference_reasons (label, treatment, sort_order, is_active, created_by) VALUES (:l, :t, :s, 1, :u)',
    { l: label, t: treatment, s: sort, u: req.user.id });
  await auditLogRepository.log(req.user.id, 'REMITTANCE_REASON_ADDED', 'remittance_difference_reasons', result.insertId, 'label', null, `${label} (${remittanceDifference.TREATMENTS[treatment]})`);
  flash.set(req, 'success', `Reason added: "${label}".`);
  res.redirect(BACK);
}

async function update(req, res) {
  const id = Number(req.params.id);
  const row = await find(id);
  if (!row) {
    fail(req, res, 'That reason was not found.');
    return;
  }
  const label = cleanLabel(req.body.label);
  const treatment = String(req.body.treatment || row.treatment);
  const error = await check(label, treatment, id);
  if (error) {
    fail(req, res, error);
    return;
  }
  const used = await uses(id);
  if (treatment !== row.treatment && used > 0) {
    fail(req, res, `"${row.label}" is already on ${used} receipt(s), so how it is treated cannot change. Switch it off and add a new reason instead.`);
    return;
  }
  const sortRaw = req.body.sort_order;
  const sort = sortRaw !== undefined && sortRaw !== '' && !Number.isNaN(parseInt(sortRaw, 10)) ? parseInt(sortRaw, 10) : Number(row.sort_order);
  await db.execute('UPDATE remittance_difference_reasons SET label = :l, treatment = :t, sort_order = :s WHERE id = :id', { l: label, t: treatment, s: sort, id });
  for (const [field, oldV, newV] of [['label', row.label, label], ['treatment', row.treatment, treatment], ['sort_order', String(row.sort_order), String(sort)]]) {
    if (String(oldV) !== String(newV)) {
      await auditLogRepository.log(req.user.id, 'REMITTANCE_REASON_UPDATED', 'remittance_difference_reasons', id, field, String(oldV), String(newV));
    }
  }
  flash.set(req, 'success', `Reason saved: "${label}".`);
  res.redirect(BACK);
}

async function toggle(req, res) {
  const id = Number(req.params.id);
  const row = await find(id);
  if (!row) {
    fail(req, res, 'That reason was not found.');
    return;
  }
  const active = Number(row.is_active) === 1 ? 0 : 1;
  await db.execute('UPDATE remittance_difference_reasons SET is_active = :a WHERE id = :id', { a: active, id });
  await auditLogRepository.log(req.user.id, active ? 'REMITTANCE_REASON_REACTIVATED' : 'REMITTANCE_REASON_DEACTIVATED', 'remittance_difference_reasons', id, 'is_active', String(row.is_active), String(active), row.label);
  flash.set(req, 'success', `"${row.label}" ${active ? 'is back in the list.' : 'is switched off — it no longer shows on the forms; receipts that used it keep it.'}`);
  res.redirect(BACK);
}

module.exports = { index, create, update, toggle };
