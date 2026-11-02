'use strict';

const csv = require('../helpers/csv');
const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const incentiveRepository = require('../repositories/incentiveRepository');
const associateTds = require('../services/associateTds');

// Port of App\Controllers\AssociateTdsController — owner decision 2 Nov 2026
// (D-235): TDS on associate commission — Settings → Associate TDS and the
// quarterly TDS register with the challan Accounts deposited it with.

const todayIso = () => {
  const d = new Date(Date.now() + 5.5 * 3600 * 1000);
  return d.toISOString().slice(0, 10);
};
const can = (req, key) => !!(req.user && req.permissions && req.permissions[key]);

async function settings(req, res) {
  res.renderView('settings/associate_tds', { s: await associateTds.settings() }, 'layout/base');
}

async function saveSettings(req, res) {
  const b = req.body || {};
  const section = String(b.associate_tds_section || '').trim().toUpperCase();
  const num = (v) => (String(v ?? '').trim() !== '' && Number.isFinite(Number(v)) ? Number(v) : null);
  const rate = num(b.associate_tds_pct);
  const threshold = num(b.associate_tds_threshold_inr);
  const noPan = num(b.associate_tds_no_pan_pct);
  const taxId = String(b.zoho_tds_tax_id || '').trim();
  const reason = String(b.reason || '').trim();
  if (!/^[0-9]{3}[A-Z]{0,3}$/.test(section) || rate === null || rate < 0 || rate > 30 || threshold === null || threshold < 0 || threshold > 10000000
    || noPan === null || noPan < 0 || noPan > 40 || !/^[0-9A-Za-z_-]{0,40}$/.test(taxId)) {
    flash.set(req, 'error', 'Enter the section like 194H, the rate as 0–30 %, the threshold as ₹0–1,00,00,000, the rate without PAN as 0–40 %, and the Zoho tax id as letters and digits only (or leave it blank).');
    res.redirect('/settings/associate-tds');
    return;
  }
  if ([...reason].length < 10) {
    flash.set(req, 'error', 'Give the reason for the change (at least 10 characters), e.g. "CA confirmed 194H at 2% on 2 Nov".');
    res.redirect('/settings/associate-tds');
    return;
  }
  const values = {
    associate_tds_section: section,
    associate_tds_pct: rate.toFixed(2),
    associate_tds_threshold_inr: String(Math.round(threshold * 100) / 100),
    associate_tds_no_pan_pct: noPan.toFixed(2),
    associate_tds_foreign: b.associate_tds_foreign ? '1' : '0',
    associate_15cb_required: b.associate_15cb_required ? '1' : '0',
    zoho_tds_tax_id: taxId,
  };
  const before = await incentiveRepository.settings();
  let changed = 0;
  for (const [k, v] of Object.entries(values)) {
    if (String(before[k] ?? '') !== v) {
      await incentiveRepository.setSetting(k, v, req.user.id);
      await auditLogRepository.log(req.user.id, 'ASSOCIATE_TDS_SETTING_CHANGED', 'incentive_settings', null, k, before[k] ?? null, v, reason);
      changed++;
    }
  }
  flash.set(req, 'success', changed === 0 ? 'Nothing changed.' : 'Saved. Payments recorded from now on use the new values; payments already recorded keep the TDS they were paid with.');
  res.redirect('/settings/associate-tds');
}

async function register(req, res) {
  const q = req.query || {};
  let fyStart = String(q.fy || '');
  if (!/^\d{4}-04-01$/.test(fyStart)) {
    fyStart = associateTds.fyBounds(todayIso())[0];
  }
  const quarter = [1, 2, 3, 4].includes(parseInt(q.q, 10)) ? parseInt(q.q, 10) : null;
  const rows = await associateTds.register(fyStart, quarter);
  const fyLabel = associateTds.fyLabel(fyStart);
  if (q.format === 'csv') {
    csv.stream(res, `associate-tds-${fyLabel.replace(/ /g, '')}${quarter !== null ? `-Q${quarter}` : ''}.csv`,
      ['Quarter', 'Paid on', 'Associate', 'PAN', 'Based in', 'Order', 'Gross (INR)', 'TDS (INR)', 'Section', 'Rate %', 'Net paid (INR)', 'Reason', 'Bank reference', 'Challan no.', 'Challan date', 'Form 16A uploaded'],
      rows.map((r) => [`Q${r.quarter}`, r.paid_on, r.name, r.pan, r.based_in === 'india' ? 'India' : `Abroad ${r.country || ''}`,
        r.buyer_inquiry_ref || r.order_reference, (Number(r.paid_inr) + Number(r.tds_inr || 0)).toFixed(2), Number(r.tds_inr || 0).toFixed(2),
        r.tds_section, r.tds_rate, r.paid_inr, r.tds_reason, r.paid_reference, r.tds_challan_no, r.tds_challan_date, Number(r.form16a_count) > 0 ? 'yes' : 'no']));
    return;
  }
  let gross = 0;
  let tds = 0;
  for (const r of rows) {
    gross += Number(r.paid_inr) + Number(r.tds_inr || 0);
    tds += Number(r.tds_inr || 0);
    r.gross = Number(r.paid_inr) + Number(r.tds_inr || 0);
    r.rateLabel = Number(r.tds_rate || 0) > 0 ? associateTds.pct(Number(r.tds_rate)) : '';
  }
  const thisYear = parseInt(todayIso().slice(0, 4), 10);
  const years = [];
  for (let y = thisYear + 1; y >= 2025; y--) {
    years.push({ value: `${y - 1}-04-01`, label: `FY ${y - 1}-${String(y).slice(2)}` });
  }
  res.renderView('associates/tds', {
    rows, fyStart, fyLabel, quarter, years, gross, tds, today: todayIso(),
    canEditChallan: can(req, 'pay_incentives'),
  }, 'layout/base');
}

async function saveChallan(req, res) {
  const b = req.body || {};
  const id = parseInt(req.params.payoutId, 10);
  const p = await incentiveRepository.findPayout(id);
  const back = `/associates/tds${/^\d{4}-04-01$/.test(String(b.fy || '')) ? `?fy=${b.fy}` : ''}`;
  if (!p || p.payee_kind !== 'associate' || p.status !== 'paid' || Number(p.tds_inr || 0) <= 0) {
    res.status(404).send('No TDS payment found.');
    return;
  }
  const no = String(b.tds_challan_no || '').trim();
  const date = String(b.tds_challan_date || '');
  const paidOn = p.paid_on instanceof Date ? `${p.paid_on.getFullYear()}-${String(p.paid_on.getMonth() + 1).padStart(2, '0')}-${String(p.paid_on.getDate()).padStart(2, '0')}` : String(p.paid_on).slice(0, 10);
  if (!/^[0-9A-Za-z/ -]{5,40}$/.test(no) || !/^\d{4}-\d{2}-\d{2}$/.test(date) || date > todayIso() || date < paidOn) {
    flash.set(req, 'error', 'Enter the challan (CIN / BSR + serial) as 5–40 letters or digits, and its date — on or after the payment date, and not in the future.');
    res.redirect(back);
    return;
  }
  await incentiveRepository.setPayoutChallan(id, no, date);
  await auditLogRepository.log(req.user.id, 'ASSOCIATE_TDS_CHALLAN_RECORDED', 'incentive_payouts', id, 'tds_challan_no', p.tds_challan_no ?? null, `${no} (${date})`);
  flash.set(req, 'success', 'Challan saved.');
  res.redirect(back);
}

module.exports = { settings, saveSettings, register, saveChallan };
