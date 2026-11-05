'use strict';

const db = require('../config/db');
const flash = require('../helpers/flash');
const associateRepository = require('../repositories/associateRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const productCategoryRepository = require('../repositories/productCategoryRepository');
const productRepository = require('../repositories/productRepository');
const moneyVisibility = require('../services/moneyVisibility');
const orderLinePricing = require('../services/orderLinePricing');
const priceRequestService = require('../services/priceRequestService');
const pricingService = require('../services/pricingService');
const productSpecs = require('../services/productSpecs');

/**
 * Port of App\Controllers\PriceRequestController — owner decision D-255 (5 Nov 2026), staff side
 * of associate price requests:
 *  - Associates → Price requests: the queue and the 90-day report — view_price_buildup or
 *    manage_pricing; pricing a manual request — manage_pricing (MD).
 *  - An associate's price access (categories and forms, daily limit, validity) — manage_pricing.
 *  - The order form's live quote for a product and options — selling-price visibility.
 */

const can = (req, key) => !!(req.user && req.permissions && req.permissions[key]);
const fmtDt = (v) => (v ? `${require('../helpers/dates').short(String(v).slice(0, 10))} ${String(v).slice(11, 16)}` : '—');

async function index(req, res) {
  const show = String(req.query.show || '');
  const filter = ['pending', 'auto', 'manual'].includes(show) ? show : 'all';
  const rows = (await priceRequestService.queue(filter)).map((r) => ({
    ...r, display_status: priceRequestService.displayStatus(r), requested_text: fmtDt(r.requested_at), due_text: fmtDt(r.due_at),
  }));
  res.renderView('associates/price_requests', {
    rows, report: await priceRequestService.report(), filter, canPrice: can(req, 'manage_pricing'), warnAt: priceRequestService.WARN_REQUESTS,
  });
}

async function price(req, res) {
  const body = req.body || {};
  const error = await priceRequestService.priceManual(parseInt(req.params.id, 10) || 0, Number(req.user.id), String(body.quote ?? '').trim(),
    String(body.lowest ?? '').trim(), String(body.unit ?? ''), String(body.note ?? ''));
  flash.set(req, error === null ? 'success' : 'error', error ?? 'Priced — the associate has been told.');
  res.redirect('/associates/price-requests');
}

async function access(req, res) {
  const a = await associateRepository.find(parseInt(req.params.id, 10) || 0);
  if (!a) {
    res.status(404).send('Associate not found.');
    return;
  }
  const settings = await pricingService.settings();
  res.renderView('associates/price_access', {
    associate: a, access: await priceRequestService.access(Number(a.id)), categories: await productCategoryRepository.all(true),
    forms: productSpecs.FORMS, globalLimit: settings.price_request_daily_limit, globalDays: settings.price_request_valid_days,
  });
}

async function saveAccess(req, res) {
  const a = await associateRepository.find(parseInt(req.params.id, 10) || 0);
  if (!a) {
    flash.set(req, 'error', 'That associate no longer exists.');
    res.redirect('/associates');
    return;
  }
  const id = Number(a.id);
  const body = req.body || {};
  const errors = [];
  const acc = {};
  const cats = body.category && typeof body.category === 'object' ? body.category : {};
  const formsIn = body.forms && typeof body.forms === 'object' ? body.forms : {};
  for (const [cidRaw, on] of Object.entries(cats)) {
    const cid = parseInt(cidRaw, 10) || 0;
    if (on && (await productCategoryRepository.find(cid))) {
      const list = formsIn[cidRaw] === undefined ? [] : (Array.isArray(formsIn[cidRaw]) ? formsIn[cidRaw] : (typeof formsIn[cidRaw] === 'object' ? Object.values(formsIn[cidRaw]) : [formsIn[cidRaw]]));
      acc[cid] = list.filter((f) => f !== undefined).map(String).filter((f) => Object.prototype.hasOwnProperty.call(productSpecs.FORMS, f));
    }
  }
  let limit = null;
  let days = null;
  for (const [key, [min, max, label]] of [['price_request_daily_limit', [0, 1000, 'Price requests per day']], ['price_valid_days', [1, 365, 'A price stays valid for']]]) {
    const v = String(body[key] ?? '').trim();
    if (v === '') continue;
    const n = productSpecs.validInt(v);
    if (n === false || n < min || n > max) errors.push(`${label}: a whole number from ${min} to ${max}, or leave it empty for the global value.`);
    else if (key === 'price_request_daily_limit') limit = n;
    else days = n;
  }
  if (errors.length) {
    flash.set(req, 'error', errors.join(' '));
    res.redirect(`/associates/${id}/price-access`);
    return;
  }
  const before = `${JSON.stringify(await priceRequestService.access(id))} limit ${a.price_request_daily_limit ?? ''} days ${a.price_valid_days ?? ''}`;
  await priceRequestService.saveAccess(id, acc);
  await db.execute('UPDATE associates SET price_request_daily_limit = :l, price_valid_days = :d WHERE id = :id', { l: limit, d: days, id });
  await auditLogRepository.log(Number(req.user.id), 'ASSOCIATE_PRICE_ACCESS_CHANGED', 'associates', id, 'price access', before,
    `${JSON.stringify(acc)} limit ${limit ?? 'global'} days ${days ?? 'global'}`);
  flash.set(req, 'success', `Price access saved for ${a.name}.`);
  res.redirect(`/associates/${id}`);
}

/** The order form's live quote for a product with chosen options (JSON). Only the seller's quote and lowest — never cost. */
async function lineQuote(req, res) {
  if (!moneyVisibility.can(req.permissions, moneyVisibility.SELLING)) {
    res.status(403).json({ ok: false, reason: 'You may not see selling prices.' });
    return;
  }
  const product = await productRepository.find(parseInt(req.query.product_id, 10) || 0);
  if (!product) {
    res.status(404).json({ ok: false, reason: 'Product not found.' });
    return;
  }
  let order;
  if (req.query.order_id) {
    order = await require('../repositories/orderRepository').find(parseInt(req.query.order_id, 10) || 0);
    if (!order || (await require('../services/orderAccessService').denialReason(order, Number(req.user.id), req.permissions)) !== null) {
      res.status(404).json({ ok: false, reason: 'Order not found.' });
      return;
    }
  } else {
    order = { client_id: req.query.client_id ? parseInt(req.query.client_id, 10) || null : null, currency_code: 'USD' };
    // QA ACC-17: a client-specific price only for a client of the user's division(s).
    if (order.client_id !== null && !(await require('../services/divisionService').allowsClient(order.client_id))) {
      res.status(404).json({ ok: false, reason: 'Client not found.' });
      return;
    }
  }
  const ctx = await orderLinePricing.context(order);
  res.json(await orderLinePricing.lineQuote(product, ctx, orderLinePricing.selectionInput(String(req.query.spec || '')) || {}));
}

module.exports = { index, price, access, saveAccess, lineQuote };
