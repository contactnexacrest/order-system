'use strict';

/**
 * Owner decision D-245 P5: the incentive & commission register, "To pay", "Owed at month end"
 * and a person's statement. For whoever manages or pays incentives or sees the price build-up
 * (MD / ED / Accounts) — staff see only their own amounts on My incentives, associates on their
 * portal. Twin of PHP IncentiveReportController.
 */
const csv = require('../helpers/csv');
const svc = require('../services/incentiveReportService');

function today() {
  const d = new Date();
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}

function filters(q) {
  const month = String(q.month || '');
  const from = String(q.from || '');
  const to = String(q.to || '');
  const state = String(q.state || '');
  return {
    state: svc.STATES[state] ? state : '',
    month: /^\d{4}-\d{2}$/.test(month) ? month : '',
    user_id: Math.max(0, parseInt(q.user_id, 10) || 0),
    associate_id: Math.max(0, parseInt(q.associate_id, 10) || 0),
    from: /^\d{4}-\d{2}-\d{2}$/.test(from) ? from : '',
    to: /^\d{4}-\d{2}-\d{2}$/.test(to) ? to : '',
  };
}


function query(obj) {
  return Object.entries(obj).filter(([, v]) => v !== '' && v !== 0 && v !== null && v !== undefined)
    .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`).join('&');
}

async function register(req, res) {
  const f = filters(req.query || {});
  // D-259: the register also lists what the company kept from people who were not eligible.
  const rows = await svc.rows({ ...f, with_kept: true });
  if (req.query.format === 'csv') {
    csv.stream(res, 'incentive_commission_register.csv', svc.HEADERS, svc.csvRows(rows));
    return;
  }
  // D-245 P6: one page on screen; the totals (and the CSV) are over the whole filtered set.
  const pg = svc.page(rows, parseInt(req.query.page, 10) || 1);
  res.renderView('incentives/report_register', { rows: pg.rows, page: pg.page, pages: pg.pages, totals: svc.totals(rows), filters: f, csvQuery: query(f), ...(await svc.payeeOptions()) }, 'layout/base');
}

async function toPay(req, res) {
  const kind = ['staff', 'associate'].includes(req.query.payee) ? req.query.payee : '';
  const groups = await svc.toPay(kind);
  if (req.query.format === 'csv') {
    const rows = [];
    for (const g of Object.values(groups)) for (const r of svc.csvRows(g.rows)) rows.push({ Group: g.label, ...r });
    csv.stream(res, 'incentive_to_pay.csv', ['Group', ...svc.HEADERS], rows);
    return;
  }
  res.renderView('incentives/report_to_pay', { groups, payee: kind }, 'layout/base');
}

async function owed(req, res) {
  let date = String(req.query.date || '');
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
    const t = today();
    const [y, m] = t.split('-').map(Number);
    date = `${t.slice(0, 7)}-${String(new Date(y, m, 0).getDate()).padStart(2, '0')}`;
  }
  const rows = await svc.owedAt(date);
  if (req.query.format === 'csv') {
    csv.stream(res, `incentive_owed_${date}.csv`, svc.HEADERS, svc.csvRows(rows));
    return;
  }
  res.renderView('incentives/report_owed', { rows, totals: svc.totals(rows), date }, 'layout/base');
}

async function statement(req, res) {
  const userId = Math.max(0, parseInt(req.query.user_id, 10) || 0);
  const associateId = userId > 0 ? 0 : Math.max(0, parseInt(req.query.associate_id, 10) || 0);
  const rows = await svc.statement(userId || null, associateId || null);
  if (req.query.format === 'csv' && rows.length > 0) {
    csv.stream(res, 'incentive_statement.csv', svc.HEADERS, svc.csvRows(rows));
    return;
  }
  const p = await svc.payeeOptions();
  const find = (list, id) => (list.find((x) => x.id === id) || {}).name || '';
  const name = userId ? find(p.people, userId) : (associateId ? find(p.associates, associateId) : '');
  res.renderView('incentives/report_statement', { rows, totals: svc.totals(rows), userId, associateId, name, csvQuery: query({ user_id: userId, associate_id: associateId }), ...p }, 'layout/base');
}

module.exports = { register, toPay, owed, statement };
