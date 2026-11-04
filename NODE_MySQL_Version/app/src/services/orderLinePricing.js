'use strict';

/**
 * Owner decision D-245, phase P2 (4 Nov 2026): prices on order lines.
 *
 * A goods line picked from Products is checked against the floor of whoever
 * sells it: the staff floor, or — when the order's client has an associate
 * (and the order is one the associate earns on) — that associate's floor at
 * their commission level. Below the floor the line needs the MD's approval,
 * which may go down to the minimum price and never below it; until then the
 * Proforma Invoice cannot be issued. When the PI is issued the figures are
 * fixed on the line (price_snapshot), so later changes to the settings or the
 * supplier price do not move that order's check. An approval holds only while
 * the line keeps the approved price.
 *
 * Lines typed by hand (not picked from Products), service and charge lines,
 * lines without a price yet (To Be Confirmed) and orders not in USD are not
 * checked — prices are built in USD per unit (FOB).
 *
 * PHP twin: src/Services/OrderLinePricing.php.
 */
const db = require('../config/db');
const pricingService = require('./pricingService');
const pricingEngine = require('./pricingEngine');
const pricingRepository = require('../repositories/pricingRepository');

const OK = 'ok';
const BELOW = 'below';
const BELOW_MINIMUM = 'below_minimum';
const NO_PRICE = 'no_price';
const APPROVED = 'approved';
const UNCHECKED = 'unchecked';
const BLOCKING = [BELOW, BELOW_MINIMUM, NO_PRICE];

const n2 = (v) => Number(v).toFixed(2);
const money = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const auditLog = (...a) => require('../repositories/auditLogRepository').log(...a);

/** The order is one the client's associate earns on: forced "yes", or within the first-order window and not forced "no". */
async function associateEarnsOn(order) {
  let mode = 'auto';
  if (order.id) {
    const row = await require('../repositories/incentiveRepository').forOrder(Number(order.id));
    mode = String((row && row.associate_mode) || 'auto');
  }
  if (mode === 'no') return false;
  if (mode === 'yes' || !order.id) return true;
  return require('./incentiveService').withinFirstTenDays(order);
}

/**
 * The associate's commission level: the level fixed on the order when its PI was
 * issued, else their fixed level, or the ladder step for their next counting order
 * (D-245 P3: counting orders replace the P2 stand-in). Returns [level, count].
 */
async function levelForAssociate(associate, excludeOrderId) {
  const associateCommission = require('./associateCommission');
  if (excludeOrderId) {
    const order = await require('../repositories/orderRepository').find(excludeOrderId);
    const fixed = order && Number(order.associate_id || 0) === Number(associate.id) ? associateCommission.fixedLevel(order) : null;
    if (fixed) {
      const level = fixed.id !== null ? await pricingRepository.findLevel(fixed.id) : null;
      // The level as fixed (its value then), even if the level was changed or deactivated since.
      return [{ ...(level || {}), id: fixed.id, name: fixed.name, kind: fixed.kind, value: fixed.value }, null];
    }
  }
  return associateCommission.levelForAssociate(associate, excludeOrderId);
}

/** Who sells this order: the client's associate at their level (when the order is one they earn on), else staff. */
async function context(order) {
  const ctx = { associate: null, level: null, key: 'staff', label: 'Staff', ladder_count: null,
    usd: String((order && order.currency_code) || '').toUpperCase() === 'USD' };
  const client = order && order.client_id ? await require('../repositories/clientRepository').find(Number(order.client_id)) : null;
  if (!client || !client.associate_id) return ctx;
  const associate = await require('../repositories/associateRepository').find(Number(client.associate_id));
  if (!associate || !(await associateEarnsOn(order))) return ctx;
  const [level, count] = await levelForAssociate(associate, order.id ? Number(order.id) : null);
  ctx.associate = associate;
  ctx.ladder_count = count;
  if (level) {
    ctx.level = level;
    ctx.key = `L${Number(level.id)}`;
    ctx.label = `${associate.name} (${pricingService.levelLabel(level)})`;
  }
  return ctx;
}

/** Live figures for a product in this order's context, or { missing }. */
async function liveFigures(product, ctx) {
  const p = await pricingService.forProduct(product, []);
  if (!p.build) return { missing: String(p.missing) };
  const b = p.build;
  let floor = b.staff_floor;
  let quote = b.staff_quote;
  if (ctx.level) {
    const a = pricingEngine.associate(b.raw, String(ctx.level.kind), Number(ctx.level.value));
    floor = a.floor;
    quote = a.quote;
  }
  return {
    cost: b.cost, minimum: b.minimum, reserve: b.reserve,
    staff_floor: b.staff_floor, staff_quote: b.staff_quote,
    floor, quote,
    sold_by: ctx.key, sold_by_label: ctx.label,
    associate_id: ctx.associate ? Number(ctx.associate.id) : null,
    level_id: ctx.level ? Number(ctx.level.id) : null,
    level_name: ctx.level ? String(ctx.level.name) : null,
    level_kind: ctx.level ? String(ctx.level.kind) : null,
    level_value: ctx.level ? Number(ctx.level.value) : null,
    margin_pct: p.effective.margin_pct, buffer_pct: p.effective.buffer_pct,
    other_mode: p.effective.other_mode, other_value: p.effective.other_value,
    incentive_mode: p.effective.incentive_mode, incentive_value: p.effective.incentive_value,
    program_on: p.program_on, fx_rate: p.settings.pricing_fx_rate,
    supplier_inr: p.supplier_inr ?? null,
    supplier_id: p.supplier && p.supplier.id ? Number(p.supplier.id) : null,
  };
}

/** Whether a line is in scope of the check at all, with the reason when not. */
function scope(line, ctx) {
  if ((line.line_type || 'goods') !== 'goods') return 'Service and charge lines are not price-checked.';
  if (!line.catalog_product_id) return 'Typed by hand — pick it from Products for a price check.';
  if (!ctx.usd) return 'Prices are built in USD — no price check on orders in another currency.';
  if (line.unit_price === null || line.unit_price === undefined || line.unit_price === '') return 'No price yet.';
  return null;
}

function approvalHolds(line) {
  return Boolean(line.price_approved_at) && line.price_approved_unit_price !== null && line.price_approved_unit_price !== undefined
    && line.unit_price !== null && line.unit_price !== undefined && Math.abs(Number(line.unit_price) - Number(line.price_approved_unit_price)) < 0.005;
}

/** The check of one line: { status, figures, fixed, note, approval, requested }. */
async function checkLine(line, ctx, product = null) {
  const out = { status: UNCHECKED, figures: null, fixed: false, note: null, approval: null, requested: Boolean(line.price_approval_requested_at) };
  const reason = scope(line, ctx);
  if (reason !== null) {
    out.note = reason;
    return out;
  }
  let figures;
  let snapshot = null;
  if (line.price_snapshot) {
    try { snapshot = JSON.parse(line.price_snapshot); } catch (e) { snapshot = null; }
  }
  if (snapshot && typeof snapshot === 'object') {
    figures = snapshot;
    out.fixed = true;
  } else {
    product = product || await require('../repositories/productRepository').find(Number(line.catalog_product_id));
    figures = product ? await liveFigures(product, ctx) : { missing: 'The product is no longer in Products.' };
  }
  if (approvalHolds(line)) {
    out.approval = {
      by: Number(line.price_approved_by), at: line.price_approved_at, reason: String(line.price_approval_reason),
      commission_zero: Number(line.price_approval_commission_zero) === 1,
      incentive_cut: line.price_approval_incentive_cut !== null && line.price_approval_incentive_cut !== undefined ? Number(line.price_approval_incentive_cut) : null,
    };
  }
  if (figures.missing !== undefined) {
    out.note = `No price set — needs MD approval. ${figures.missing}`;
    out.status = out.approval ? APPROVED : NO_PRICE;
    return out;
  }
  out.figures = figures;
  const price = Number(line.unit_price);
  if (out.approval) out.status = APPROVED;
  else if (price < Number(figures.minimum) - 0.0001) out.status = BELOW_MINIMUM;
  else if (price < Number(figures.floor) - 0.0001) out.status = BELOW;
  else out.status = OK;
  return out;
}

/** Every line of the order with its check, and the lines keeping the PI back. */
async function forOrder(orderId) {
  const order = await require('../repositories/orderRepository').find(orderId);
  if (!order) return { ctx: {}, lines: {}, blocking: [] };
  const ctx = await context(order);
  const out = { ctx, lines: {}, blocking: [] };
  for (const line of await require('../repositories/orderProductRepository').forOrder(orderId)) {
    const check = await checkLine(line, ctx);
    check.line = line;
    out.lines[Number(line.id)] = check;
    if (BLOCKING.includes(check.status)) out.blocking.push(check);
  }
  return out;
}

/**
 * Owner decision D-245 P2: why the Quotation (QT) or the Proforma Invoice (PI) cannot be
 * generated yet — a line below its lowest price, below the minimum price, or with no price
 * set, not approved by the MD — or null when it can.
 */
async function documentBlockReason(orderId, typeCode) {
  const first = (await forOrder(orderId)).blocking[0];
  if (!first) return null;
  const doc = typeCode === 'QT' ? 'the Quotation' : 'the Proforma Invoice';
  const no = Number(first.line.line_no);
  if (first.status === NO_PRICE) return `Line ${no} has no price set for its product — the MD must approve it before ${doc} (order page → Products).`;
  if (first.status === BELOW_MINIMUM) return `Line ${no} is below the price NexaCrest can accept — raise it before ${doc} (order page → Products).`;
  return `Line ${no} is below the lowest price for this order — raise it, or ask the MD to approve it, before ${doc} (order page → Products).`;
}

/** Why the PI cannot be issued yet, or null when it can. */
async function piBlockReason(orderId) {
  return documentBlockReason(orderId, 'PI');
}

const PICK_MESSAGE = 'Pick this goods line from Products — on a USD order goods lines cannot be typed by hand (service and charge lines can). The MD can change this in Admin → Pricing.';

/** Owner decision D-245 P2: whether goods lines on this currency must be picked from Products (Admin › Pricing). */
async function productPickRequired(currencyCode) {
  return String(currencyCode || '').toUpperCase() === 'USD' && (await pricingService.settings()).require_product_pick;
}

/** The refusal for a goods line typed by hand when picking from Products is required, else null. */
async function pickRefusal(currencyCode, catalogProductId, lineType) {
  if (lineType !== 'goods' || catalogProductId || !(await productPickRequired(currencyCode))) return null;
  return PICK_MESSAGE;
}

/** Refuses a price below the product's minimum price before it is saved. Null when the price may be saved. */
async function saveRefusal(order, catalogProductId, lineType, unitPrice, seesBuildup) {
  if (!catalogProductId || lineType !== 'goods' || unitPrice === null || unitPrice === undefined || unitPrice === '') return null;
  const ctx = await context(order);
  if (!ctx.usd) return null;
  const product = await require('../repositories/productRepository').find(Number(catalogProductId));
  if (!product) return null;
  const f = await liveFigures(product, ctx);
  if (f.missing !== undefined || Number(unitPrice) >= Number(f.minimum) - 0.0001) return null;
  return `This price is below what NexaCrest can accept for ${product.name} — raise it.`
    + (seesBuildup ? ` Minimum price: USD ${money(f.minimum)}; to go lower, change the product's margin (Products → pricing).` : '');
}

/**
 * Sends a line to the MD for approval (in-app notification to everyone who
 * manages pricing). Null on success, else the reason it was not sent.
 */
async function requestApproval(orderId, lineId, userId, automatic = false) {
  const orderProductRepository = require('../repositories/orderProductRepository');
  const line = await orderProductRepository.find(lineId);
  const order = await require('../repositories/orderRepository').find(orderId);
  if (!line || !order || Number(line.order_id) !== Number(orderId)) return 'Order line not found.';
  const check = await checkLine(line, await context(order));
  if (![BELOW, NO_PRICE].includes(check.status)) {
    return check.status === BELOW_MINIMUM
      ? 'This price is below what NexaCrest can accept — raise it; the MD cannot approve it either.'
      : `Line ${Number(line.line_no)} does not need an approval.`;
  }
  if (line.price_approval_requested_at && automatic) return null;
  await db.execute('UPDATE order_products SET price_approval_requested_at = NOW(), price_approval_requested_by = :u WHERE id = :id', { u: userId, id: lineId });
  const ref = order.order_reference || `#${orderId}`;
  const message = `Order ${ref}, line ${line.line_no} (${line.description}) needs your price approval: `
    + (check.status === NO_PRICE ? 'its product has no price set.' : 'it is below the lowest price for this order.');
  const notificationRepository = require('../repositories/notificationRepository');
  for (const mdId of await require('../repositories/permissionRepository').usersWithPermission('manage_pricing')) {
    await notificationRepository.createInAppOnly(Number(mdId), 'line_price_approval', orderId, message.slice(0, 500));
  }
  await auditLog(userId, 'LINE_PRICE_APPROVAL_REQUESTED', 'order_products', lineId, null, null, message);
  return null;
}

/**
 * After a line is saved: a changed price voids its approval; a changed product
 * clears its fixed figures; a line now below its floor (or without a price for
 * its product) is sent to the MD for approval.
 */
async function afterSave(lineId, before, userId) {
  const orderProductRepository = require('../repositories/orderProductRepository');
  let line = await orderProductRepository.find(lineId);
  if (!line) return;
  const orderId = Number(line.order_id);
  if (before && Number(before.catalog_product_id || 0) !== Number(line.catalog_product_id || 0) && line.price_snapshot) {
    await db.execute('UPDATE order_products SET price_snapshot = NULL WHERE id = :id', { id: lineId });
    line.price_snapshot = null;
  }
  if (line.price_approved_at && !approvalHolds(line)) {
    await db.execute(
      `UPDATE order_products SET price_approved_by = NULL, price_approved_at = NULL, price_approval_reason = NULL, price_approved_unit_price = NULL,
              price_approval_commission_zero = 0, price_approval_incentive_cut = NULL WHERE id = :id`, { id: lineId }
    );
    await auditLog(userId, 'LINE_PRICE_APPROVAL_VOIDED', 'order_products', lineId, 'unit_price',
      n2(line.price_approved_unit_price), String(line.unit_price),
      `Order line ${line.line_no}: the price changed after the MD approved it — the approval no longer holds.`);
    line = (await orderProductRepository.find(lineId)) || line;
  }
  const order = await require('../repositories/orderRepository').find(orderId);
  const check = await checkLine(line, await context(order || {}));
  if (BLOCKING.includes(check.status)) {
    await requestApproval(orderId, lineId, userId, true);
  } else if (line.price_approval_requested_at) {
    await db.execute('UPDATE order_products SET price_approval_requested_at = NULL, price_approval_requested_by = NULL WHERE id = :id', { id: lineId });
  }
}

/** What approving this line would mean, for the MD's approval screen. */
async function approvalView(orderId, lineId) {
  const order = await require('../repositories/orderRepository').find(orderId);
  const line = await require('../repositories/orderProductRepository').find(lineId);
  if (!order || !line || Number(line.order_id) !== Number(orderId)) return null;
  const ctx = await context(order);
  const check = await checkLine(line, ctx);
  const view = { order, line, ctx, check, effect: null };
  const f = check.figures;
  if (f) {
    const price = Number(line.unit_price);
    const qty = line.quantity !== null && line.quantity !== undefined ? Number(line.quantity) : null;
    const reservePerUnit = Number(f.reserve);
    const cutPerUnit = Math.min(reservePerUnit, Math.max(0, Number(f.staff_floor) - price));
    const r2 = (v) => Math.round(v * 100) / 100;
    view.effect = {
      margin_pct: price > 0 ? Math.round((price - Number(f.cost)) / price * 1000) / 10 : null,
      short_per_unit: r2(Math.max(0, Number(f.floor) - price)),
      reserve_per_unit: reservePerUnit,
      cut_per_unit: r2(cutPerUnit),
      qty,
      reserve_line: qty !== null ? r2(reservePerUnit * qty) : null,
      after_line: qty !== null ? r2((reservePerUnit - cutPerUnit) * qty) : null,
      cut_line: qty !== null ? r2(cutPerUnit * qty) : null,
      commission_zero: ctx.associate !== null,
    };
  }
  return view;
}

/** The MD approves a line's price. Null on success, else the refusal text. */
async function approve(orderId, lineId, userId, reason) {
  reason = String(reason ?? '').trim();
  if (reason.length < 10) return 'Give a reason of at least 10 characters for approving this price.';
  if (reason.length > 500) return 'Keep the reason under 500 characters.';
  const view = await approvalView(orderId, lineId);
  if (!view) return 'Order line not found.';
  const { line } = view;
  const status = view.check.status;
  const no = Number(line.line_no);
  if (status === BELOW_MINIMUM) {
    const f = view.check.figures;
    await auditLog(userId, 'LINE_PRICE_APPROVAL_REFUSED', 'order_products', lineId, 'unit_price', null, String(line.unit_price),
      `Order line ${no}: USD ${money(line.unit_price)} is below the minimum price USD ${money(f.minimum)} — not approvable.`);
    return `Line ${no}: USD ${money(line.unit_price)} is below the minimum price USD ${money(f.minimum)} — nobody can approve it, not even the MD. To sell lower, change the product's margin (Products → pricing; logged).`;
  }
  if (![BELOW, NO_PRICE].includes(status)) return `Line ${no} does not need an approval.`;
  if (Number(line.price_set_by || 0) === Number(userId) && !(await require('./makerCheckerGuard').selfApprovalAllowed('price_discount', userId))) {
    return 'You set this price yourself — Admin → Self-approval rules do not let your role approve its own price.';
  }
  const effect = view.effect || {};
  await db.execute(
    `UPDATE order_products SET price_approved_by = :u, price_approved_at = NOW(), price_approval_reason = :r, price_approved_unit_price = :p,
            price_approval_commission_zero = :cz, price_approval_incentive_cut = :ic, price_approval_requested_at = NULL, price_approval_requested_by = NULL
     WHERE id = :id`,
    { u: userId, r: reason, p: n2(line.unit_price), cz: effect.commission_zero ? 1 : 0, ic: effect.cut_line !== undefined && effect.cut_line !== null ? n2(effect.cut_line) : null, id: lineId }
  );
  const what = status === NO_PRICE ? 'no price set for its product' : `USD ${money(line.unit_price)} below the floor USD ${money(view.check.figures.floor)}`;
  await auditLog(userId, 'LINE_PRICE_APPROVED', 'order_products', lineId, 'unit_price', null, String(line.unit_price), `Order line ${no}: ${what} — reason: ${reason}`);
  if (Number(line.price_set_by || 0) === Number(userId)) {
    await auditLog(userId, 'LINE_PRICE_SELF_APPROVED', 'order_products', lineId, null, null, 'Self-approval allowed by Admin → Self-approval rules (price_discount).');
  }
  return null;
}

/** The MD refuses a line's price: the request is closed and whoever asked is told why. Null on success. */
async function refuse(orderId, lineId, userId, reason) {
  reason = String(reason ?? '').trim();
  if (reason.length < 10) return 'Give a reason of at least 10 characters for refusing this price.';
  const view = await approvalView(orderId, lineId);
  if (!view) return 'Order line not found.';
  const { line } = view;
  await db.execute('UPDATE order_products SET price_approval_requested_at = NULL, price_approval_requested_by = NULL WHERE id = :id', { id: lineId });
  const message = `Order ${view.order.order_reference || `#${orderId}`}, line ${Number(line.line_no)}: the MD refused USD ${money(line.unit_price)} — ${reason} Raise the price; the Proforma Invoice waits for it.`;
  if (line.price_approval_requested_by && Number(line.price_approval_requested_by) !== Number(userId)) {
    await require('../repositories/notificationRepository').createInAppOnly(Number(line.price_approval_requested_by), 'line_price_refused', orderId, message.slice(0, 500));
  }
  await auditLog(userId, 'LINE_PRICE_APPROVAL_REFUSED', 'order_products', lineId, 'unit_price', null, String(line.unit_price), message);
  return null;
}

/** Fixes the figures on every checked line that has none yet — called when the PI is issued. */
async function snapshotForPi(orderId) {
  // D-245 P3: the associate's commission level is fixed on the order with the PI, first,
  // so the line figures fixed below use that same level.
  await require('./associateCommission').fixLevelAtPi(orderId);
  const order = await require('../repositories/orderRepository').find(orderId);
  if (!order) return;
  const ctx = await context(order);
  const productRepository = require('../repositories/productRepository');
  for (const line of await require('../repositories/orderProductRepository').forOrder(orderId)) {
    if (line.price_snapshot || scope(line, ctx) !== null) continue;
    const product = await productRepository.find(Number(line.catalog_product_id));
    if (!product) continue;
    const f = await liveFigures(product, ctx);
    if (f.missing !== undefined) continue;
    f.fixed_at = new Date().toISOString().slice(0, 19).replace('T', ' ');
    await db.execute('UPDATE order_products SET price_snapshot = :s WHERE id = :id AND price_snapshot IS NULL', { s: JSON.stringify(f), id: Number(line.id) });
  }
}

/** The quote price and floor of every picker product, per seller ("staff", "L<level id>"). */
async function pickerPrices(pickerProducts) {
  const levels = await pricingRepository.levels(true);
  const productRepository = require('../repositories/productRepository');
  const out = {};
  for (const pp of pickerProducts) {
    const product = await productRepository.find(Number(pp.id));
    if (!product) continue;
    const p = await pricingService.forProduct(product, levels);
    if (!p.build) continue;
    const prices = { staff: [p.build.staff_quote, p.build.staff_floor] };
    for (const l of p.levels) prices[`L${l.id}`] = [l.quote, l.floor];
    out[Number(pp.id)] = prices;
  }
  return out;
}

/** For the New Order form: each client's seller key ("staff" or "L<level id>"). */
async function clientKeys(clients) {
  const byAssociate = {};
  const out = {};
  const associateRepository = require('../repositories/associateRepository');
  for (const c of clients) {
    let key = 'staff';
    if (c.associate_id) {
      const aid = Number(c.associate_id);
      if (!(aid in byAssociate)) {
        byAssociate[aid] = 'staff';
        const associate = await associateRepository.find(aid);
        if (associate) {
          const [level] = await levelForAssociate(associate, null);
          byAssociate[aid] = level ? `L${Number(level.id)}` : 'staff';
        }
      }
      key = byAssociate[aid];
    }
    out[Number(c.id)] = key;
  }
  return out;
}

module.exports = {
  OK, BELOW, BELOW_MINIMUM, NO_PRICE, APPROVED, UNCHECKED, BLOCKING,
  PICK_MESSAGE, productPickRequired, pickRefusal, documentBlockReason,
  context, levelForAssociate, liveFigures, scope, approvalHolds, checkLine, forOrder, piBlockReason,
  saveRefusal, afterSave, requestApproval, approvalView, approve, refuse, snapshotForPi, pickerPrices, clientKeys,
};
