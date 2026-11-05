'use strict';

const flash = require('../helpers/flash');
const { todayIst } = require('../helpers/dbDateTime');
const auditLogRepository = require('../repositories/auditLogRepository');
const clientRepository = require('../repositories/clientRepository');
const repo = require('../repositories/domesticOrderRepository');
const orderRepository = require('../repositories/orderRepository');
const divisionService = require('../services/divisionService');
const D = require('../services/domesticOrderService');
const domesticDocumentService = require('../services/domesticDocumentService');
const G = require('../services/gstCalculator');
const moneyVisibility = require('../services/moneyVisibility');
const orderAccessService = require('../services/orderAccessService');

/**
 * Owner decision D-257 Phase 2 (5 Nov 2026): the Domestic Trade (DOM) order
 * page and its steps. A DOM order opens at /orders/:id like any order (the
 * order page hands over to show() here); its actions post to /domestic/orders/:id/….
 * Every action checks the same order access as the order page (assignment,
 * division) and that the order is a DOM order. Port of PHP DomesticOrderController.
 */

async function create(req, res) {
  const active = await D.isActive();
  res.renderView('domestic/create', {
    active,
    clients: active ? await clientRepository.all() : [],
    states: await repo.states(),
    defaultTerms: await D.setting('dom_payment_terms_days'),
    old: flash.pullOld(req) || {},
  });
}

async function store(req, res) {
  const [orderId, error] = await D.create(req.body, req.user.id);
  if (error !== null) {
    flash.set(req, 'error', error);
    flash.setOld(req, req.body);
    res.redirect('/domestic/orders/create');
    return;
  }
  flash.set(req, 'success', 'Domestic order created. Add its lines, then generate the quotation.');
  res.redirect(`/orders/${orderId}`);
}

/** The page data of a DOM order (also used by the tests). */
async function pageData(order, permissions) {
  const orderId = Number(order.id);
  const dom = await repo.find(orderId);
  const totals = await D.totals(orderId);
  const stage = dom.stage;
  const states = await repo.states();
  const stateName = (code) => {
    const s = states.find((x) => x.code === code);
    return s ? `${s.name} (${s.code})` : '—';
  };
  const lines = totals.lines.map((l) => ({
    ...l,
    rate_fmt: G.inr(l.rate_inr),
    taxable_fmt: G.inr(l.taxable),
    tax_fmt: G.inr(G.money(G.scaled(l.cgst, 2) + G.scaled(l.sgst, 2) + G.scaled(l.igst, 2))),
    total_fmt: G.inr(l.line_total),
  }));
  const zoho = require('../services/zohoBooksService');
  const keys = D.STAGE_KEYS;
  const next = stage !== 'closed' ? keys[keys.indexOf(stage) + 1] : null;
  const documents = (await repo.documents(orderId)).map((d) => ({ ...d, label: D.DOCUMENTS[d.doc_code] || d.doc_code }));
  return {
    order,
    dom,
    stage,
    stageLabel: D.STAGES[stage],
    next,
    nextLabel: next ? D.STAGES[next] : '',
    stages: keys.map((k) => ({ key: k, label: D.STAGES[k], cls: k === stage ? 'current' : (D.atOrAfter(stage, k) ? 'done' : '') })),
    lines,
    totals,
    totalsFmt: {
      taxable: G.inr(totals.taxable_total), cgst: G.inr(totals.cgst_total), sgst: G.inr(totals.sgst_total),
      igst: G.inr(totals.igst_total), roundOff: G.inr(totals.round_off), total: G.inr(totals.invoice_total),
    },
    invoiceTotalFmt: dom.invoice_total !== null ? G.inr(dom.invoice_total) : null,
    payments: (await repo.payments(orderId)).map((p) => ({ ...p, amount_fmt: G.inr(p.amount_inr) })),
    paidFmt: G.inr(await D.paidTotal(orderId)),
    documents,
    blockers: await D.blockers(orderId),
    needsEway: await D.needsEwayBill(dom, totals),
    ewayThresholdFmt: G.inr(await D.setting('dom_eway_threshold_inr')),
    states,
    buyerStateName: stateName(dom.buyer_state_code),
    posName: stateName(dom.place_of_supply),
    sellerStateName: stateName(dom.seller_state_code),
    linesOpen: D.LINES_OPEN.includes(stage),
    afterOc: D.atOrAfter(stage, 'order_confirmation'),
    afterDispatch: D.atOrAfter(stage, 'dispatch'),
    afterInvoice: D.atOrAfter(stage, 'tax_invoice'),
    docButtons: Object.entries(D.DOCUMENTS).filter(([code]) => D.documentAllowed(dom, code)).map(([code, label]) => ({ code, label: label.charAt(0).toLowerCase() + label.slice(1) })),
    seesPrices: moneyVisibility.can(permissions, moneyVisibility.SELLING),
    seesPayments: moneyVisibility.can(permissions, moneyVisibility.BUYER_PAYMENTS),
    canEdit: !!permissions.manage_orders && Number(order.is_locked || 0) !== 1,
    canPay: !!permissions.manage_payments,
    canDocs: !!permissions.generate_documents,
    canZoho: !!permissions.ca_module_manage && (await zoho.isEnabled()),
    today: todayIst(),
  };
}

/** Called by ordersController.show() for a DOM order (access already checked there). */
async function show(req, res, order) {
  const dom = await repo.find(Number(order.id));
  if (!dom) {
    res.status(404).send('Order not found.');
    return;
  }
  res.renderView('domestic/show', await divisionService.withDivision(Number(order.division_id), () => pageData(order, req.permissions)));
}

/** The DOM order behind a /domestic/orders/:id/… action, or null after answering 404/403. */
async function loadOrder(req, res) {
  const order = await orderRepository.find(parseInt(req.params.id, 10));
  if (!order || !(await D.isDomesticOrder(order))) {
    res.status(404).send('Order not found.');
    return null;
  }
  const denied = await orderAccessService.denialReason(order, req.user.id, req.permissions);
  if (denied !== null) {
    res.status(403).send(denied);
    return null;
  }
  if (Number(order.is_locked || 0) === 1) {
    flash.set(req, 'error', 'This order is closed and locked.');
    res.redirect(`/orders/${order.id}`);
    return null;
  }
  return order;
}

function action(fn, ok, anchor = '') {
  return async (req, res) => {
    const order = await loadOrder(req, res);
    if (!order) return;
    const error = await divisionService.withDivision(Number(order.division_id), () => fn(req, Number(order.id)));
    const okText = typeof ok === 'function' ? await ok(Number(order.id), req) : ok;
    flash.set(req, error === null ? 'success' : 'error', error === null ? okText : error);
    res.redirect(`/orders/${order.id}${anchor ? `#${anchor}` : ''}`);
  };
}

const saveParty = action((req, id) => D.saveParty(id, req.body, req.user.id), 'Buyer and place of supply saved.', 'dom-party');
const addLine = action((req, id) => D.addLine(id, req.body, req.user.id), 'Line added.', 'dom-lines');
const removeLine = action((req, id) => D.removeLine(id, parseInt(req.params.lineId, 10), req.user.id), 'Line removed.', 'dom-lines');
const savePurchase = action((req, id) => D.savePurchase(id, req.body, req.user.id), 'Supplier PO saved.', 'dom-purchase');
const saveDispatch = action((req, id) => D.saveDispatch(id, req.body, req.user.id), 'Dispatch saved.', 'dom-dispatch');
const invoiceSent = action((req, id) => D.markInvoiceSent(id, String(req.body.invoice_sent_on || '').trim(), req.user.id), 'Tax invoice marked as sent.', 'dom-invoice');
const recordPayment = action((req, id) => D.recordPayment(id, req.body, req.user.id), 'Payment recorded.', 'dom-payments');
const advance = action((req, id) => D.advance(id, String(req.body.from_stage || ''), req.user.id), async (id) => `Moved on to: ${D.STAGES[((await repo.find(id)) || {}).stage] || ''}.`);
const generate = action(async (req, id) => {
  const code = String(req.body.doc_code || '').trim().toUpperCase();
  req.domDocCode = code;
  const [, error] = await domesticDocumentService.generate(id, code, req.user.id);
  return error;
}, (id, req) => `${D.DOCUMENTS[req.domDocCode] || 'Document'} generated.`, 'dom-documents');
const pushZoho = action(async (req, id) => {
  try {
    await require('../services/zohoBooksService').pushDomesticInvoice(id, req.user.id);
    return null;
  } catch (e) {
    return `Zoho Books: ${e.message}`;
  }
}, 'Tax invoice sent to Zoho Books.', 'dom-invoice');

/** A client's GST registration (Domestic Trade). Shown on the client page only once Domestic Trade is active. */
async function saveClientGst(req, res) {
  const clientId = parseInt(req.params.id, 10);
  const client = await clientRepository.find(clientId);
  if (!client || !(await divisionService.allowsClient(clientId))) {
    res.status(404).send('Client not found.');
    return;
  }
  const gstin = String(req.body.gstin || '').trim().toUpperCase();
  let state = String(req.body.gst_state_code || '').trim();
  const pos = String(req.body.place_of_supply_state || '').trim();
  let error = null;
  if (!(await D.isActive())) error = 'Domestic Trade is not active.';
  else if (gstin !== '' && !G.isValidGstin(gstin)) error = 'The GSTIN is not valid (15 characters: state code, PAN, entity number, Z and a check character).';
  else if (state !== '' && !(await repo.isState(state))) error = 'Choose the state from the list.';
  else if (pos !== '' && !(await repo.isState(pos))) error = 'Choose the place of supply from the list.';
  else if (gstin !== '' && state !== '' && gstin.slice(0, 2) !== state) error = `The GSTIN starts with state code ${gstin.slice(0, 2)}, which does not match the state chosen.`;
  if (error === null) {
    if (gstin !== '' && state === '') state = gstin.slice(0, 2);
    await repo.setClientGst(clientId, gstin || null, state || null, pos || null);
    await auditLogRepository.log(req.user.id, 'CLIENT_GST_SAVED', 'clients', clientId, 'gstin', client.gstin || null, gstin || null);
  }
  flash.set(req, error === null ? 'success' : 'error', error === null ? 'GST details saved.' : error);
  res.redirect(`/clients/${clientId}#client-gst`);
}

module.exports = { create, store, show, pageData, saveParty, addLine, removeLine, savePurchase, saveDispatch, invoiceSent, recordPayment, advance, generate, pushZoho, saveClientGst };
