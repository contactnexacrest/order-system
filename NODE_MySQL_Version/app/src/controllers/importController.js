'use strict';

const flash = require('../helpers/flash');
const { todayIst } = require('../helpers/dbDateTime');
const auditLogRepository = require('../repositories/auditLogRepository');
const repo = require('../repositories/importRepository');
const divisionService = require('../services/divisionService');
const G = require('../services/gstCalculator');
const I = require('../services/importService');
const importDocumentService = require('../services/importDocumentService');
const uiTemplateService = require('../services/uiTemplateService');

/**
 * Owner decision D-257 Phase 3 (5 Nov 2026): the Import & Distribution (IMP)
 * pages — the imports list (Standard list and Kanban board, the same
 * filters, links and actions), one import with its steps, the reports
 * (landed cost, stock on hand, duty and IGST credit) and Admin › Import duty
 * rates. /import/… belongs to the module "Import, stock and landed cost",
 * which is on for IMP only, so nobody else reaches it; each import is also
 * checked against the person's division. Purchase money: view_purchase_prices.
 * Port of PHP ImportController.
 */

const num = (v) => {
  const s = String(v);
  return s.includes('.') ? s.replace(/0+$/, '').replace(/\.$/, '') : s;
};
const fcOf = (v) => I.fc(G.scaled(String(v), 2));

/** The import behind /import/:id/…, or null after answering 404. */
async function shipment(req, res) {
  const s = await repo.find(parseInt(req.params.id, 10) || 0);
  if (!s || !divisionService.allows(Number(s.division_id))) {
    res.status(404).send('Import not found.');
    return null;
  }
  return s;
}

function done(req, res, id, error, ok, anchor = '') {
  flash.set(req, error === null ? 'success' : 'error', error === null ? ok : error);
  res.redirect(`/import/${id}${anchor ? `#${anchor}` : ''}`);
}

// ------------------------------------------------------------ list / board

async function indexData(req) {
  let stage = String((req.query && req.query.stage) || '');
  if (!I.STAGES[stage]) stage = '';
  const rows = (await repo.list(await I.reportDivisionIds(), stage !== '' ? stage : null)).map((r) => ({
    ...r, stage_label: I.STAGES[r.stage] || r.stage, value_fmt: fcOf(r.po_value_fc), line_count: Number(r.line_count),
  }));
  const board = await uiTemplateService.showsBoard(req);
  const columns = board
    ? I.STAGE_KEYS.filter((k) => stage === '' || k === stage).map((k) => ({ key: k, label: I.STAGES[k], rows: rows.filter((r) => r.stage === k) }))
    : [];
  return {
    rows,
    board,
    columns,
    stage,
    stages: I.STAGE_KEYS.map((k) => ({ key: k, label: I.STAGES[k] })),
    kanbanUser: (await uiTemplateService.resolve(req.user.ui_template_preference_id || null)) === 'kanban',
    active: await I.isActive(),
    canEdit: !!req.permissions.manage_purchasing,
  };
}

async function index(req, res) {
  res.renderView('import/index', await indexData(req));
}

async function create(req, res) {
  res.renderView('import/create', {
    active: await I.isActive(), old: flash.pullOld(req) || {}, currencies: I.CURRENCIES, incoterms: I.INCOTERMS, today: todayIst(),
  });
}

async function store(req, res) {
  const [id, error] = await I.create(req.body, req.user.id);
  if (error !== null) {
    flash.set(req, 'error', error);
    flash.setOld(req, req.body);
    res.redirect('/import/create');
    return;
  }
  flash.set(req, 'success', 'Import created. Add its lines, then generate the import PO.');
  res.redirect(`/import/${id}`);
}

// ------------------------------------------------------------ one import

async function showData(s, permissions) {
  const id = Number(s.id);
  const stage = s.stage;
  const figures = await I.figures(s);
  const margin = await I.setting('imp_margin_pct');
  const pos = I.STAGE_KEYS.indexOf(stage);
  const next = stage !== 'closed' ? I.STAGE_KEYS[pos + 1] : null;
  const cleared = s.customs_fx_rate !== null && s.customs_fx_rate !== undefined;
  const zoho = require('../services/zohoBooksService');
  const poCents = await I.poValueCents(id);
  const paidCents = await I.paidCents(id);
  return {
    s,
    id,
    stage,
    stageLabel: I.STAGES[stage],
    next,
    nextLabel: next ? I.STAGES[next] : '',
    stages: I.STAGE_KEYS.map((k, i) => ({ key: k, label: I.STAGES[k], cls: k === stage ? 'current' : (i < pos ? 'done' : '') })),
    blockers: await I.blockers(id),
    cleared,
    frozen: s.landed_total !== null && s.landed_total !== undefined,
    lines: figures.lines.map((l) => ({
      ...l,
      qty_fmt: num(l.quantity),
      price_fmt: fcOf(l.unit_price_fc),
      value_fc_fmt: fcOf(l.value_fc),
      bcd_pct_fmt: num(l.bcd_pct), sws_pct_fmt: num(l.sws_pct), igst_pct_fmt: num(l.igst_pct),
      assessable_fmt: G.inr(l.assessable_inr),
      duty_fmt: G.inr(G.money(G.scaled(l.bcd_inr, 2) + G.scaled(l.sws_inr, 2))),
      igst_fmt: G.inr(l.igst_inr),
      landed_fmt: G.inr(l.landed_inr),
      suggested_fmt: G.inr(I.suggestedPrice(l.landed_unit_inr, margin)),
    })),
    totals: {
      value_fc: fcOf(figures.value_total_fc), value_inr: G.inr(figures.value_total_inr), assessable: G.inr(figures.assessable_total),
      bcd: G.inr(figures.bcd_total), sws: G.inr(figures.sws_total), igst: G.inr(figures.igst_total), charges: G.inr(figures.charges_total), landed: G.inr(figures.landed_total),
    },
    lcAmountFmt: s.lc_amount_fc !== null && s.lc_amount_fc !== undefined ? fcOf(s.lc_amount_fc) : '',
    lcMarginFmt: s.lc_margin_inr !== null && s.lc_margin_inr !== undefined ? G.inr(String(s.lc_margin_inr)) : '',
    lcBankDefault: s.lc_bank || (await I.setting('imp_lc_bank')),
    chaDefault: s.cha_name || (await I.setting('imp_default_cha')),
    igstFmt: s.igst_total !== null && s.igst_total !== undefined ? G.inr(String(s.igst_total)) : '',
    dutyTotalFmt: s.bcd_total !== null && s.bcd_total !== undefined ? G.inr((Number(s.bcd_total) + Number(s.sws_total) + Number(s.igst_total)).toFixed(2)) : '',
    payments: (await repo.payments(id)).map((p) => ({ ...p, kind_label: I.PAYMENT_KINDS[p.kind] || p.kind, amount_fmt: fcOf(p.amount_fc), inr_fmt: G.inr(String(p.amount_inr)) })),
    poFmt: I.fc(poCents),
    paidFmt: I.fc(paidCents),
    products: await repo.products(),
    currencies: I.CURRENCIES,
    incoterms: I.INCOTERMS,
    paymentKinds: Object.entries(I.PAYMENT_KINDS).map(([key, label]) => ({ key, label })),
    margin,
    today: todayIst(),
    canEdit: !!permissions.manage_purchasing,
    canPay: !!permissions.manage_payments,
    canZoho: !!permissions.ca_module_manage && (await zoho.isEnabled()),
  };
}

async function show(req, res) {
  const s = await shipment(req, res);
  if (!s) return;
  res.renderView('import/show', await showData(s, req.permissions));
}

function act(fn, ok, anchor) {
  return async (req, res) => {
    const s = await shipment(req, res);
    if (!s) return;
    done(req, res, Number(s.id), await fn(req, Number(s.id)), ok, anchor);
  };
}

const saveHeader = act((req, id) => I.saveHeader(id, req.body, req.user.id), 'Import PO saved.', 'imp-po');
const addLine = act((req, id) => I.addLine(id, req.body, req.user.id), 'Line added.', 'imp-lines');
const removeLine = act((req, id) => I.removeLine(id, parseInt(req.params.lineId, 10), req.user.id), 'Line removed.', 'imp-lines');
const saveLc = act((req, id) => I.saveLc(id, req.body, req.user.id), 'LC saved.', 'imp-lc');
const saveShipment = act((req, id) => I.saveShipment(id, req.body, req.user.id), 'Bill of lading saved.', 'imp-shipment');
const saveClearance = act((req, id) => I.saveClearance(id, req.body, req.user.id), 'Customs clearance saved.', 'imp-clearance');
const igstCredit = act((req, id) => I.saveIgstCredit(id, String(req.body.igst_credit_on || '').trim(), req.user.id), 'IGST input credit recorded.', 'imp-clearance');
const dutyPayment = act((req, id) => I.saveDutyPayment(id, req.body, req.user.id), 'Customs duty payment recorded.', 'imp-clearance');
const recordPayment = act((req, id) => I.recordPayment(id, req.body, req.user.id), 'Payment recorded.', 'imp-payments');

async function advance(req, res) {
  const s = await shipment(req, res);
  if (!s) return;
  const error = await I.advance(Number(s.id), String(req.body.from_stage || ''), req.user.id);
  const now = await repo.find(Number(s.id));
  done(req, res, Number(s.id), error, `Moved on to: ${I.STAGES[(now || {}).stage] || ''}.`);
}

/** The import PO as a PDF (download). Generating it ticks "import PO generated". */
async function poDocument(req, res) {
  const s = await shipment(req, res);
  if (!s) return;
  if ((await repo.lines(Number(s.id))).length === 0) {
    done(req, res, Number(s.id), 'Add at least one line first.', '', 'imp-lines');
    return;
  }
  const pdf = await importDocumentService.pdf(Number(s.id));
  await I.markPoGenerated(Number(s.id), req.user.id);
  await auditLogRepository.log(req.user.id, 'IMPORT_PO_GENERATED', 'import_shipments', Number(s.id), 'reference', null, String(s.reference));
  res.setHeader('Content-Type', 'application/pdf');
  res.setHeader('Content-Disposition', `attachment; filename="IPO ${String(s.reference).replace(/\//g, '-')}.pdf"`);
  res.send(pdf);
}

async function pushZoho(req, res) {
  const s = await shipment(req, res);
  if (!s) return;
  const [error, ok] = await require('../services/divisionZohoSync').sendNow('import', Number(s.id), req.user.id);
  done(req, res, Number(s.id), error, ok, 'imp-clearance');
}

/** D-257 Zoho: the import's "App vs Zoho" check (reads only). */
async function zohoCheck(req, res) {
  const s = await shipment(req, res);
  if (!s) return;
  await require('./caController').divisionZohoCheck(req, res, 'import', Number(s.id), `/import/${s.id}`, `Import ${s.reference}`, String(s.supplier_name));
}

// ------------------------------------------------------------ reports

async function reportsData() {
  const ids = await I.reportDivisionIds();
  const landed = [];
  const duty = [];
  for (const row of await repo.list(ids)) {
    if (['ordered', 'shipped'].includes(row.stage)) continue;
    const s = await repo.find(Number(row.id));
    for (const l of await repo.lines(Number(s.id))) {
      landed.push({
        ...l, reference: s.reference, boe_no: s.boe_no, shipment_id: Number(s.id), qty_fmt: num(l.quantity),
        value_fmt: G.inr(String(l.value_inr)), assessable_fmt: G.inr(String(l.assessable_inr)), bcd_fmt: G.inr(String(l.bcd_inr)),
        sws_fmt: G.inr(String(l.sws_inr)), landed_fmt: G.inr(String(l.landed_inr)),
      });
    }
    duty.push({
      ...s, assessable_fmt: G.inr(String(s.assessable_total)), bcd_fmt: G.inr(String(s.bcd_total)), sws_fmt: G.inr(String(s.sws_total)), igst_fmt: G.inr(String(s.igst_total)),
    });
  }
  return {
    landed,
    duty,
    stock: (await I.stockReport()).map((r) => ({ ...r, qty_fmt: num(r.qty_on_hand), value_fmt: G.inr(String(r.value_inr)), suggested_fmt: G.inr(r.suggested_price_inr) })),
    movements: (await repo.movements(ids, 50)).map((m) => ({ ...m, qty_fmt: num(m.quantity), value_fmt: G.inr(String(m.value_inr)) })),
    margin: await I.setting('imp_margin_pct'),
  };
}

async function reports(req, res) {
  res.renderView('import/reports', await reportsData());
}

// ------------------------------------------------------------ Admin › Import duty rates

async function dutyRates(req, res) {
  res.renderView('import/duty_rates', {
    rates: await repo.dutyRates(),
    defaults: { bcd: await I.setting('imp_default_bcd_pct'), sws: await I.setting('imp_default_sws_pct'), igst: await I.setting('imp_default_igst_pct') },
  });
}

async function storeDutyRate(req, res) {
  const hsn = String(req.body.hsn_prefix || '').replace(/\D/g, '');
  const pct = (k) => String(req.body[k] === undefined || req.body[k] === null ? '' : req.body[k]).trim();
  const bad = (v) => !/^\d{1,3}(\.\d{1,2})?$/.test(v) || G.scaled(v, 2) > 15000n;
  const note = [...String(req.body.note || '').trim()].slice(0, 255).join('');
  let error = null;
  if (hsn.length < 2 || hsn.length > 8) error = 'An HSN has 2 to 8 digits.';
  else if (bad(pct('bcd_pct')) || bad(pct('sws_pct')) || bad(pct('igst_pct'))) error = 'Each rate is a percentage, 0 to 150, with up to 2 decimals.';
  if (error !== null) {
    flash.set(req, 'error', error);
    res.redirect('/admin/import-duty-rates');
    return;
  }
  const m = (v) => G.money(G.scaled(v, 2));
  const id = await repo.addDutyRate(hsn, m(pct('bcd_pct')), m(pct('sws_pct')), m(pct('igst_pct')), note !== '' ? note : null, req.user.id);
  await auditLogRepository.log(req.user.id, 'IMPORT_DUTY_RATE_ADDED', 'import_duty_rates', id, 'hsn_prefix', null, hsn);
  flash.set(req, 'success', 'Duty rate added.');
  res.redirect('/admin/import-duty-rates');
}

async function toggleDutyRate(req, res) {
  const active = String(req.body.active || '') === '1';
  const id = parseInt(req.params.id, 10);
  await repo.setDutyRateActive(id, active, req.user.id);
  await auditLogRepository.log(req.user.id, `IMPORT_DUTY_RATE_${active ? 'ON' : 'OFF'}`, 'import_duty_rates', id, 'is_active', null, active ? '1' : '0');
  flash.set(req, 'success', active ? 'Duty rate in use again.' : 'Duty rate no longer used.');
  res.redirect('/admin/import-duty-rates');
}

module.exports = {
  index, indexData, create, store, show, showData, saveHeader, addLine, removeLine, saveLc, saveShipment, saveClearance, igstCredit,
  recordPayment, dutyPayment, advance, poDocument, pushZoho, zohoCheck, reports, reportsData, dutyRates, storeDutyRate, toggleDutyRate,
};
