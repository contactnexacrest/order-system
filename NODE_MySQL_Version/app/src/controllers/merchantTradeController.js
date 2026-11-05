'use strict';

const flash = require('../helpers/flash');
const { todayIst } = require('../helpers/dbDateTime');
const auditLogRepository = require('../repositories/auditLogRepository');
const repo = require('../repositories/merchantTradeRepository');
const divisionService = require('../services/divisionService');
const G = require('../services/gstCalculator');
const I = require('../services/importService');
const M = require('../services/merchantTradeService');
const merchantDocs = require('../services/merchantDocumentService');
const uiTemplateService = require('../services/uiTemplateService');

/**
 * Owner decision D-257 Phase 4 (5 Nov 2026): the Merchant Trade (MTT)
 * pages — the deals list (Standard list and Kanban board, the same filters,
 * links and actions), one deal with its steps, money on both legs and RBI
 * checks, and the reports (open deals with their RBI status, margin per
 * deal). /merchant/… belongs to the module "Merchant trade", which is on for
 * MTT only; each deal is also checked against the person's division.
 * Port of PHP MerchantTradeController.
 */

const num = (v) => {
  const s = String(v);
  return s.includes('.') ? s.replace(/0+$/, '').replace(/\.$/, '') : s;
};
const fcOf = (v) => I.fc(G.scaled(String(v), 2));
const RBI_LABEL = { breach: 'RBI: action needed', warn: 'RBI: due soon', ok: 'RBI: on track' };
const LEVEL_STYLE = { ok: 'color:#2D7D56', info: 'color:#5B6573', warn: 'color:#8A5A00;font-weight:700', breach: 'color:#B42318;font-weight:700' };

/** The deal behind /merchant/:id/…, or null after answering 404. */
async function deal(req, res) {
  const d = await repo.find(parseInt(req.params.id, 10) || 0);
  if (!d || !divisionService.allows(Number(d.division_id))) {
    res.status(404).send('Deal not found.');
    return null;
  }
  return d;
}

function done(req, res, id, error, ok, anchor = '') {
  flash.set(req, error === null ? 'success' : 'error', error === null ? ok : error);
  res.redirect(`/merchant/${id}${anchor ? `#${anchor}` : ''}`);
}

/** The figures a view prints, formatted. */
function fmtFigures(f) {
  return {
    ...f,
    sale_fmt: I.fc(f.sale_cents),
    purchase_fmt: I.fc(f.purchase_cents),
    received_fmt: I.fc(f.received_cents),
    paid_fmt: I.fc(f.paid_cents),
    received_inr_fmt: G.inr(f.received_inr),
    paid_inr_fmt: G.inr(f.paid_inr),
    margin_inr_fmt: G.inr(f.margin_inr),
    margin_usd_fmt: I.fc(f.margin_usd_cents),
    contract_margin_fmt: f.contract_margin_cents !== null ? I.fc(f.contract_margin_cents) : null,
  };
}

// ------------------------------------------------------------ list / board

async function indexData(req) {
  let stage = String((req.query && req.query.stage) || '');
  if (!M.STAGES[stage]) stage = '';
  const rows = [];
  for (const d of await repo.list(await M.reportDivisionIds(), stage !== '' ? stage : null)) {
    const worst = M.worstLevel(await M.checksFor(d));
    rows.push({ ...d, stage_label: M.STAGES[d.stage] || d.stage, f: fmtFigures(await M.figures(d)), worst, rbi_label: RBI_LABEL[worst] || '' });
  }
  const board = await uiTemplateService.showsBoard(req);
  return {
    rows,
    board,
    columns: board ? M.STAGE_KEYS.filter((k) => stage === '' || k === stage).map((k) => ({ key: k, label: M.STAGES[k], rows: rows.filter((r) => r.stage === k) })) : [],
    stage,
    stages: M.STAGE_KEYS.map((k) => ({ key: k, label: M.STAGES[k] })),
    kanbanUser: (await uiTemplateService.resolve(req.user.ui_template_preference_id || null)) === 'kanban',
    active: await M.isActive(),
    canEdit: !!req.permissions.manage_purchasing,
  };
}

async function index(req, res) {
  res.renderView('merchant/index', await indexData(req));
}

function formLists() {
  return { currencies: I.CURRENCIES, incoterms: M.INCOTERMS, today: todayIst() };
}

async function create(req, res) {
  res.renderView('merchant/create', { active: await M.isActive(), v: flash.pullOld(req) || {}, ...formLists() });
}

async function store(req, res) {
  const [id, error] = await M.create(req.body, req.user.id);
  if (error !== null) {
    flash.set(req, 'error', error);
    flash.setOld(req, req.body);
    res.redirect('/merchant/create');
    return;
  }
  flash.set(req, 'success', 'Deal created. Add the goods, then generate the purchase order.');
  res.redirect(`/merchant/${id}`);
}

// ------------------------------------------------------------ one deal

async function showData(d, permissions) {
  const id = Number(d.id);
  const stage = d.stage;
  const pos = M.STAGE_KEYS.indexOf(stage);
  const next = stage !== 'closed' ? M.STAGE_KEYS[pos + 1] : null;
  const docs = await M.bankDocuments();
  const received = await repo.documents(id);
  return {
    d,
    v: d,
    id,
    stage,
    stageLabel: M.STAGES[stage],
    next,
    nextLabel: next ? M.STAGES[next] : '',
    stages: M.STAGE_KEYS.map((k, i) => ({ key: k, label: M.STAGES[k], cls: k === stage ? 'current' : (i < pos ? 'done' : '') })),
    blockers: await M.blockers(id),
    checks: (await M.checksFor(d)).map((c) => ({ ...c, style: LEVEL_STYLE[c.level] || '' })),
    lines: (await repo.lines(id)).map((l) => ({
      ...l, qty_fmt: num(l.quantity), buy_fmt: fcOf(l.buy_price_fc), sell_fmt: fcOf(l.sell_price_fc),
      buy_amount_fmt: I.fc(I.lineValueCents(String(l.quantity), String(l.buy_price_fc))), sell_amount_fmt: I.fc(I.lineValueCents(String(l.quantity), String(l.sell_price_fc))),
    })),
    f: fmtFigures(await M.figures(d)),
    payments: (await repo.payments(id)).map((p) => ({
      ...p, leg_label: M.LEGS[p.leg], cur: p.leg === 'buy' ? d.purchase_currency : d.sale_currency, amount_fmt: fcOf(p.amount_fc), inr_fmt: G.inr(String(p.amount_inr)),
    })),
    backingLabel: M.BACKING[d.advance_backing],
    backings: Object.entries(M.BACKING).map(([key, label]) => ({ key, label })),
    legs: Object.entries(M.LEGS).map(([key, label]) => ({ key, label })),
    docs: Object.entries(docs).map(([key, label]) => ({ key, label, received: received[key] || '' })),
    ...formLists(),
    canEdit: !!permissions.manage_purchasing,
    canPay: !!permissions.manage_payments,
    canZoho: !!permissions.ca_module_manage && (await require('../services/zohoBooksService').isEnabled()),
  };
}

async function show(req, res) {
  const d = await deal(req, res);
  if (!d) return;
  res.renderView('merchant/show', await showData(d, req.permissions));
}

function act(fn, ok, anchor) {
  return async (req, res) => {
    const d = await deal(req, res);
    if (!d) return;
    done(req, res, Number(d.id), await fn(req, Number(d.id)), ok, anchor);
  };
}

const saveHeader = act((req, id) => M.saveHeader(id, req.body, req.user.id), 'Deal saved.', 'mtt-deal');
const addLine = act((req, id) => M.addLine(id, req.body, req.user.id), 'Line added.', 'mtt-lines');
const removeLine = act((req, id) => M.removeLine(id, parseInt(req.params.lineId, 10), req.user.id), 'Line removed.', 'mtt-lines');
const saveBacking = act((req, id) => M.saveBacking(id, req.body, req.user.id), 'Advance backing saved.', 'mtt-payments');
const saveShipment = act((req, id) => M.saveShipment(id, req.body, req.user.id), 'Bill of lading saved.', 'mtt-shipment');
const saveDocument = act((req, id) => M.saveDocument(id, String(req.body.doc_key || ''), String(req.body.received_on || '').trim(), req.user.id), 'Bank checklist saved.', 'mtt-bank');
const recordPayment = act((req, id) => M.recordPayment(id, req.body, req.user.id), 'Payment recorded.', 'mtt-payments');

async function advance(req, res) {
  const d = await deal(req, res);
  if (!d) return;
  const error = await M.advance(Number(d.id), String(req.body.from_stage || ''), req.user.id);
  const now = await repo.find(Number(d.id));
  done(req, res, Number(d.id), error, `Moved on to: ${M.STAGES[(now || {}).stage] || ''}.`);
}

/** The purchase order or the sale invoice as a PDF (download). */
async function document(req, res) {
  const d = await deal(req, res);
  if (!d) return;
  const id = Number(d.id);
  const kind = String(req.body.kind || '');
  if (kind === 'po') {
    if ((await repo.lines(id)).length === 0) {
      done(req, res, id, 'Add the goods first.', '', 'mtt-lines');
      return;
    }
    await M.markPoGenerated(id, req.user.id);
  } else if (kind === 'invoice') {
    const error = await M.fixInvoice(id, req.user.id);
    if (error !== null) {
      done(req, res, id, error, '', 'mtt-shipment');
      return;
    }
  } else {
    done(req, res, id, 'Choose the document.', '');
    return;
  }
  const pdf = await merchantDocs.pdf(id, kind);
  const number = (await merchantDocs.context(id, kind)).number;
  await auditLogRepository.log(req.user.id, 'MERCHANT_DOCUMENT_GENERATED', 'merchant_deals', id, kind, null, number);
  res.setHeader('Content-Type', 'application/pdf');
  res.setHeader('Content-Disposition', `attachment; filename="${kind === 'po' ? 'PO ' : 'INV '}${number.replace(/\//g, '-')}.pdf"`);
  res.send(pdf);
}

async function pushZoho(req, res) {
  const d = await deal(req, res);
  if (!d) return;
  const [error, ok] = await require('../services/divisionZohoSync').sendNow('deal', Number(d.id), req.user.id);
  done(req, res, Number(d.id), error, ok, 'mtt-payments');
}

/** D-257 Zoho: the deal's "App vs Zoho" check (reads only). */
async function zohoCheck(req, res) {
  const d = await deal(req, res);
  if (!d) return;
  await require('./caController').divisionZohoCheck(req, res, 'deal', Number(d.id), `/merchant/${d.id}`, `Deal ${d.reference}`, `${d.supplier_name} → ${d.buyer_name}`);
}

// ------------------------------------------------------------ reports

async function reportsData() {
  const rows = (await M.reportRows()).map((r) => {
    const byKey = Object.fromEntries(r.checks.map((c) => [c.key, { ...c, style: LEVEL_STYLE[c.level] || '' }]));
    return { ...r, stage_label: M.STAGES[r.stage], f: fmtFigures(r.figures), statusCells: ['total', 'outlay', 'advance', 'documents'].map((k) => byKey[k]) };
  });
  const settings = await M.rbiSettings();
  return { rows, open: rows.filter((r) => r.stage !== 'closed'), settings, limitFmt: I.fc(G.scaled(settings.mtt_advance_limit_usd, 2)) };
}

async function reports(req, res) {
  res.renderView('merchant/reports', await reportsData());
}

module.exports = {
  index, indexData, create, store, show, showData, saveHeader, addLine, removeLine, saveBacking, saveShipment, saveDocument, recordPayment,
  advance, document, pushZoho, zohoCheck, reports, reportsData,
};
