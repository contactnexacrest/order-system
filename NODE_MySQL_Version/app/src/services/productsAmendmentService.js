'use strict';

const db = require('../config/db');
const amendmentRepository = require('../repositories/amendmentRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const documentRepository = require('../repositories/documentRepository');
const notificationRepository = require('../repositories/notificationRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const orderProductRepository = require('../repositories/orderProductRepository');
const orderRepository = require('../repositories/orderRepository');
const permissionRepository = require('../repositories/permissionRepository');
const reasonValidator = require('../helpers/reasonValidator');
const lineTable = require('./lineTable');
const lineUnits = require('./lineUnits');
const orderLinePricing = require('./orderLinePricing');
const priceLock = require('./priceLock');

/**
 * Owner decision D-342 (12 Nov 2026, owner "confirm 1" and "confirm 2"): the
 * "Products & prices" amendment — the only way to change an order's lines once its
 * Quotation is approved (priceLock).
 *
 *  - The draft: each line's pieces / quantity, size and price (a price only goes
 *    DOWN — a higher price is refused: the buyer accepted the Quotation's price),
 *    lines added (a copy of an order line's product with its own size, quantity and
 *    price) and lines removed. Totals old → new and what happens to the advance.
 *  - It then follows the amendment flow (amendmentService): the MD approves it (the
 *    maker-checker rule "amendment" — a lowered price is approved with it), the AMD
 *    document is generated, the buyer signs, the signed copy is uploaded.
 *  - activate(): the amended lines are applied to the order (changed, added,
 *    removed), each keeps its new fixed price, the payment figures follow (the
 *    advance received stays credited; the difference goes to the balance; more than
 *    the new total shows as excess). The order's stage and the fixed exchange rate do
 *    not change.
 *  - checklist(): what to re-issue afterwards (mockup screen 2).
 *
 * PHP twin: app/src/Services/ProductsAmendmentService.php (same wording).
 */

/** Up to this many lines can be added in one amendment. */
const NEW_LINE_SLOTS = 3;

const CHANGE_LABELS = {
  none: 'no change', quantity: 'Quantity changed', size: 'Size changed',
  price: 'Price lowered (MD)', add: 'Line added', remove: 'Line removed',
};

const DOC_CHANGE_LABELS = {
  none: '—', quantity: 'Quantity', size: 'Size', price: 'Price', add: 'New line', remove: 'Removed',
};

const r2 = (v) => lineTable.roundTo(v, 2);
const nf = (v, d) => Number(v).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });

function money(v) {
  return v === null || v === undefined ? '—' : nf(v, 2);
}

/** "Line 1: USD 1,950.00 is the price the buyer accepted in the Quotation — a price can only go down, with the MD's approval." */
function priceUpMessage(lineNo, currency, fixed) {
  return `Line ${lineNo}: ${currency} ${nf(fixed, 2)} is the price the buyer accepted in the Quotation — a price can only go down, with the MD's approval.`;
}

/** Why a products amendment cannot be raised on this order now, or null. */
async function cannotStart(order) {
  if (!priceLock.isFixed(order)) {
    return 'This order\'s Quotation is not approved yet — change its lines on the order page (Products).';
  }
  const open = await amendmentRepository.openProductsAmendment(Number(order.id));
  if (open) {
    return `Amendment ${open.amendment_reference} (Products & prices) is still open — finish it or reject it before raising another.`;
  }
  return null;
}

/** The order's active lines, as the amendment screen starts from them. */
async function currentLines(orderId) {
  return (await orderProductRepository.forOrder(orderId)).map((l) => ({
    ...l,
    fixed_price: l.qt_fixed_unit_price !== null && l.qt_fixed_unit_price !== undefined ? Number(l.qt_fixed_unit_price)
      : (l.unit_price !== null && l.unit_price !== undefined ? Number(l.unit_price) : null),
  }));
}

function f(v) {
  return v === null || v === undefined || v === '' ? null : Number(v);
}

function sameNum(a, b, eps = 0.0005) {
  return (a === null && b === null) || (a !== null && b !== null && Math.abs(a - b) < eps);
}

function samePieces(a, b) {
  return (a === null || a === undefined ? null : Number(a)) === (b === null || b === undefined ? null : Number(b));
}

/** Reads one line's posted figures: size, pieces, quantity, price. @returns {[object, string|null]} */
function readFigures(input, unit, perAnnexure, label, oldQuantity) {
  let sizeV = { piece_length_cm: null, piece_width_cm: null, piece_thickness_cm: null };
  if (!perAnnexure) {
    const [s, sizeError] = lineUnits.readSize({
      piece_length_cm: input.length_cm ?? '', piece_width_cm: input.width_cm ?? '', piece_thickness_cm: input.thickness_cm ?? '',
    });
    if (sizeError !== null) return [{}, `${label}: ${sizeError}`];
    sizeV = s;
  }
  const [pieces, piecesError] = lineTable.readPieces(input.pieces ?? '');
  if (piecesError !== null) return [{}, `${label}: ${piecesError}`];
  const l = lineUnits.size(sizeV.piece_length_cm);
  const w = lineUnits.size(sizeV.piece_width_cm);
  const t = lineUnits.size(sizeV.piece_thickness_cm);
  let quantity = null;
  if (!perAnnexure && lineTable.needsPieces(unit, l, w, t)) {
    if (pieces === null) return [{}, `${label}: enter the No. of pieces — with the size given, the quantity is worked out from the pieces.`];
    quantity = Number(lineTable.quantityFromPieces(unit, l, w, t, pieces));
  } else if (lineTable.docUnit(unit) === 'PCS' && pieces !== null) {
    quantity = Number(pieces);
  } else {
    const raw = String(input.quantity ?? '').trim();
    if (raw === '') {
      if (oldQuantity !== null) return [{}, `${label}: enter the quantity (e.g. 40).`];
    } else if (!/^\d{1,11}(\.\d{1,3})?$/.test(raw) || Number(raw) <= 0) {
      return [{}, `${label}: the quantity must be a number above 0 with at most 3 decimals (e.g. 40 or 4.680).`];
    } else {
      quantity = Number(raw);
    }
  }
  const rawPrice = String(input.unit_price ?? '').trim();
  if (rawPrice === '') return [{}, `${label}: enter the unit price (e.g. 1950.00).`];
  if (!/^\d{1,12}(\.\d{1,2})?$/.test(rawPrice) || Number(rawPrice) <= 0) {
    return [{}, `${label}: the unit price must be a number above 0 with at most 2 decimals (e.g. 1950.00).`];
  }
  return [{
    new_length_cm: l, new_width_cm: w, new_thickness_cm: t,
    new_pieces: pieces, new_quantity: quantity, new_unit_price: Number(rawPrice),
  }, null];
}

/** Below the price NexaCrest can accept (never approvable): the refusal, else null. */
async function belowMinimum(line, fig, ctx, currency, label) {
  const candidate = {
    ...line,
    unit_price: Number(fig.new_unit_price).toFixed(2),
    piece_length_cm: fig.new_length_cm, piece_width_cm: fig.new_width_cm, piece_thickness_cm: fig.new_thickness_cm,
    quantity: fig.new_quantity, price_approved_at: null, price_approved_unit_price: null,
  };
  const check = await orderLinePricing.checkLine(candidate, ctx);
  if (check.status === orderLinePricing.BELOW_MINIMUM) {
    return `${label}: ${currency} ${nf(fig.new_unit_price, 2)} is below the price NexaCrest can accept — not even the MD can approve it.`;
  }
  return null;
}

function lineTotal(qty, price) {
  return qty === null || price === null ? null : r2(qty * price);
}

/** Reads and checks the posted draft (form fields line[<id>][…] and new[<k>][…]). */
async function draft(order, post) {
  const orderId = Number(order.id);
  const currency = String(order.currency_code || '');
  const ctx = await orderLinePricing.context(order);
  const current = await currentLines(orderId);
  const byId = {};
  const errors = [];
  const lines = [];
  let maxNo = 0;
  for (const c of current) {
    byId[Number(c.id)] = c;
    maxNo = Math.max(maxNo, Number(c.line_no));
  }
  const postedLines = (post && post.line) || {};
  for (const c of current) {
    const id = Number(c.id);
    const no = Number(c.line_no);
    const input = postedLines[id] && typeof postedLines[id] === 'object' ? postedLines[id] : {};
    const old = {
      old_length_cm: f(c.piece_length_cm), old_width_cm: f(c.piece_width_cm), old_thickness_cm: f(c.piece_thickness_cm),
      old_pieces: c.pieces_count !== null && c.pieces_count !== undefined ? Number(c.pieces_count) : null,
      old_quantity: Number(c.quantity_is_tbc) ? null : f(c.quantity), old_unit_price: f(c.unit_price),
    };
    old.old_line_total = f(c.fob_value) ?? lineTotal(old.old_quantity, old.old_unit_price);
    const row = {
      line_no: no, order_product_id: id, source_line_id: null, description: String(c.description), unit: c.unit,
      line_type: String(c.line_type || 'goods'), as_per_annexure: !!Number(c.as_per_annexure || 0),
      fixed_price: c.fixed_price, price_lowered: false, changes: [], ...old,
    };
    if (input.remove) {
      lines.push({ ...row, change_kind: 'remove', new_length_cm: null, new_width_cm: null, new_thickness_cm: null,
        new_pieces: null, new_quantity: null, new_unit_price: null, new_line_total: null, changes: ['remove'] });
      continue;
    }
    let fig;
    if (Object.keys(input).length === 0) {
      // Not on the form: kept as it is.
      fig = { new_length_cm: old.old_length_cm, new_width_cm: old.old_width_cm, new_thickness_cm: old.old_thickness_cm,
        new_pieces: old.old_pieces, new_quantity: old.old_quantity, new_unit_price: old.old_unit_price };
    } else {
      const [read, err] = readFigures(input, c.unit, !!Number(c.as_per_annexure || 0), `Line ${no}`, old.old_quantity);
      if (err !== null) {
        errors.push(err);
        lines.push({ ...row, change_kind: 'none', new_length_cm: old.old_length_cm, new_width_cm: old.old_width_cm, new_thickness_cm: old.old_thickness_cm,
          new_pieces: old.old_pieces, new_quantity: old.old_quantity, new_unit_price: old.old_unit_price, new_line_total: old.old_line_total, input });
        continue;
      }
      fig = read;
    }
    const changes = [];
    if (!sameNum(fig.new_quantity, old.old_quantity) || !samePieces(fig.new_pieces, old.old_pieces)) changes.push('quantity');
    if (!sameNum(fig.new_length_cm, old.old_length_cm) || !sameNum(fig.new_width_cm, old.old_width_cm) || !sameNum(fig.new_thickness_cm, old.old_thickness_cm)) {
      changes.push('size');
    }
    const fixed = c.fixed_price;
    if (fixed !== null && fig.new_unit_price > fixed + 0.004) {
      errors.push(priceUpMessage(no, currency, fixed));
    } else if (!sameNum(fig.new_unit_price, old.old_unit_price, 0.004)) {
      changes.push('price');
      row.price_lowered = true;
    }
    if (changes.includes('price') || changes.includes('size')) {
      const belowError = await belowMinimum(c, fig, ctx, currency, `Line ${no}`);
      if (belowError !== null) errors.push(belowError);
    }
    lines.push({ ...row, ...fig, change_kind: changes.length === 0 ? 'none' : 'change', changes,
      new_line_total: lineTotal(fig.new_quantity, fig.new_unit_price), input });
  }
  // Lines added: a copy of an order line's product with its own size, pieces, quantity and price.
  let newNo = maxNo;
  const newPosted = (post && post.new) || {};
  for (const k of Object.keys(newPosted)) {
    const input = newPosted[k];
    if (!input || typeof input !== 'object' || String(input.source_line_id ?? '').trim() === '') continue;
    const src = byId[Number(input.source_line_id)] || null;
    newNo += 1;
    if (!src) {
      errors.push(`New line ${newNo}: choose the order line whose product it copies.`);
      continue;
    }
    const [fig, err] = readFigures(input, src.unit, !!Number(src.as_per_annexure || 0), `New line ${newNo}`, 1);
    const row = {
      line_no: newNo, order_product_id: null, source_line_id: Number(src.id), description: String(src.description), unit: src.unit,
      line_type: String(src.line_type || 'goods'), as_per_annexure: !!Number(src.as_per_annexure || 0),
      fixed_price: null, price_lowered: false, change_kind: 'add', changes: ['add'], input, slot: Number(k),
      old_length_cm: null, old_width_cm: null, old_thickness_cm: null, old_pieces: null, old_quantity: null,
      old_unit_price: null, old_line_total: null,
    };
    if (err !== null) {
      errors.push(err);
      lines.push({ ...row, new_length_cm: null, new_width_cm: null, new_thickness_cm: null, new_pieces: null,
        new_quantity: null, new_unit_price: null, new_line_total: null });
      continue;
    }
    if (fig.new_quantity === null) errors.push(`New line ${newNo}: enter the quantity (e.g. 40).`);
    const belowError = await belowMinimum(src, fig, ctx, currency, `New line ${newNo}`);
    if (belowError !== null) errors.push(belowError);
    lines.push({ ...row, ...fig, new_line_total: lineTotal(fig.new_quantity, fig.new_unit_price) });
  }
  const changed = lines.some((l) => l.change_kind !== 'none');
  const kept = lines.filter((l) => l.change_kind !== 'remove');
  if (errors.length === 0 && !changed) errors.push('Nothing was changed — change a line, add one or remove one first.');
  if (kept.length === 0) errors.push('At least one line must stay on the order.');
  return { lines, totals: await totals(order, lines), errors, changed };
}

/**
 * Old and new order totals (goods and charges — service lines are invoiced on their own,
 * as orderProductRepository.totalFobValue()) and what happens to the advance.
 */
async function totals(order, lines) {
  let oldT = 0;
  let newT = 0;
  for (const l of lines) {
    if ((l.line_type || 'goods') === 'service') continue;
    oldT += Number(l.old_line_total || 0);
    if (l.change_kind !== 'remove') newT += Number(l.new_line_total || 0);
  }
  oldT = r2(oldT);
  newT = r2(newT);
  const pct = Number(order.advance_pct || 0);
  const payment = await orderPaymentStatusRepository.find(Number(order.id));
  const received = payment && payment.advance_amount !== null && payment.advance_amount !== undefined ? Number(payment.advance_amount) : null;
  return {
    currency: String(order.currency_code || ''),
    old_total: oldT, new_total: newT, difference: r2(newT - oldT),
    advance_pct: pct,
    old_advance: r2(oldT * pct / 100), new_advance: r2(newT * pct / 100),
    advance_received: received,
    old_balance: orderPaymentStatusRepository.balanceDue(oldT, pct, payment),
    new_balance: orderPaymentStatusRepository.balanceDue(newT, pct, payment),
    excess: received !== null ? r2(Math.max(0, received - newT)) : 0,
    fx_rate: priceLock.fixedRate(order),
    prices_fixed_on: priceLock.fixedDate(order),
  };
}

/** "+ USD 4,313.40" / "− USD 120.00" */
function signedDifference(t) {
  const d = Number(t.difference);
  return `${d < 0 ? '− ' : '+ '}${t.currency} ${nf(Math.abs(d), 2)}`;
}

/** What happens to the advance (the screen's totals box). */
function advanceNote(t) {
  const c = t.currency;
  if (t.advance_received === null) return `no advance received yet — the advance due becomes ${c} ${nf(t.new_advance, 2)}.`;
  const r = Number(t.advance_received);
  if (Number(t.excess) > 0) return `advance already received ${c} ${nf(r, 2)} is ${c} ${nf(t.excess, 2)} more than the new total — it shows as excess.`;
  const d = r2(Number(t.new_advance) - r);
  if (d > 0.004) return `advance already received ${c} ${nf(r, 2)}; the extra ${c} ${nf(d, 2)} is added to the balance.`;
  if (d < -0.004) return `advance already received ${c} ${nf(r, 2)} stays credited; the ${c} ${nf(-d, 2)} received above the new advance is taken off the balance.`;
  return `advance already received ${c} ${nf(r, 2)} stays credited.`;
}

function pctText(v) {
  return Number(v).toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
}

/** Section 2 of the AMD document — "EFFECT ON PAYMENT". */
function paymentEffect(t) {
  const c = t.currency;
  const tail = ' All other terms of the Quotation / Proforma Invoice remain unchanged.';
  if (t.advance_received === null) {
    return `Advance payable as per the agreed payment terms: ${c} ${nf(t.new_advance, 2)} (${pctText(t.advance_pct)}% of ${c} ${nf(t.new_total, 2)}).`
      + ` Balance payable as per the agreed payment terms: ${c} ${nf(t.new_balance, 2)}.${tail}`;
  }
  const r = `${c} ${nf(t.advance_received, 2)}`;
  if (Number(t.excess) > 0) {
    return `Advance received ${r} stays credited. It is ${c} ${nf(t.excess, 2)} more than the amended order total of ${c} ${nf(t.new_total, 2)}; nothing more is payable and the excess is held to the Importer's credit.${tail}`;
  }
  return `Advance received ${r} stays credited. Balance payable as per the agreed payment terms: ${c} ${nf(t.new_balance, 2)}.${tail}`;
}

/** Raises the amendment (status pending, MD approval next). @returns {[number|null, string[], object]} */
async function create(orderId, post, reason, userId) {
  const order = await orderRepository.find(orderId);
  if (!order) return [null, ['Order not found.'], {}];
  const why = await cannotStart(order);
  if (why !== null) return [null, [why], {}];
  const d = await draft(order, post);
  const errors = [...d.errors];
  const reasonText = String(reason ?? '').trim();
  const reasonError = reasonValidator.check(reasonText);
  if (reasonError) errors.push(`Reason for the amendment: ${reasonError}`);
  else if (reasonText.length > 500) errors.push('Reason for the amendment: keep it under 500 characters.');
  if (errors.length) return [null, errors, d];
  const reference = await require('./referenceNumberService').generateAmendmentRef();
  if (reference === null || reference === undefined) return [null, ['AMD document type has no ref_format configured — cannot mint an amendment reference.'], d];
  const id = await amendmentRepository.create(reference, orderId, reasonText, 'importer', await require('./amendmentService').originalSnapshot(order),
    null, null, null, null, null, null, null, userId);
  await amendmentRepository.setProducts(id, d.totals);
  for (const l of d.lines) await amendmentRepository.addProductLine(id, l);
  await auditLogRepository.log(userId, 'AMENDMENT_REQUESTED', 'amendments', id, 'products', null, reference, reasonText);
  for (const mdId of await permissionRepository.usersWithPermission('approve_documents')) {
    await notificationRepository.create(mdId, null, 'amendment_pending_md_approval', orderId, `Amendment ${reference} (Products & prices) needs MD approval.`);
  }
  return [id, [], d];
}

/** A Supplier PO already issued (one not made yet will use the amended figures). */
async function hasSupplierPo(orderId) {
  return (await documentRepository.findLatestForOrderAndTypeCode(orderId, 'SUPPO')) !== null;
}

/**
 * The buyer's signed copy is uploaded: the amended lines become the order's lines.
 * Called by amendmentService.attachSignedCopyAndActivate() after its status checks.
 */
async function activate(amendment, signedCopyFileId, userId) {
  const amendmentId = Number(amendment.id);
  const orderId = Number(amendment.order_id);
  const lines = await amendmentRepository.productLines(amendmentId);
  const current = {};
  for (const c of await orderProductRepository.forOrder(orderId)) current[Number(c.id)] = c;
  // The order's lines may not have moved since the draft (they are locked; this is the safety net).
  for (const l of lines) {
    if (l.order_product_id === null) continue;
    const c = current[Number(l.order_product_id)];
    const cQty = !c || Number(c.quantity_is_tbc) ? null : f(c.quantity);
    if (!c || !sameNum(f(c.unit_price), f(l.old_unit_price), 0.004) || !sameNum(cQty, f(l.old_quantity))) {
      throw new Error(`Line ${Number(l.line_no)} of the order changed since this amendment was drafted — reject it and draft a new one.`);
    }
  }
  await amendmentRepository.activate(amendmentId, signedCopyFileId);
  let quantitiesChanged = false;
  const mdId = amendment.md_approved_by !== null && amendment.md_approved_by !== undefined ? Number(amendment.md_approved_by) : userId;
  const reference = String(amendment.amendment_reference);
  for (const l of lines) {
    const kind = String(l.change_kind);
    if (kind === 'none') continue;
    if (kind === 'remove') {
      await orderProductRepository.softDelete(Number(l.order_product_id));
      quantitiesChanged = true;
      await auditLogRepository.log(userId, 'ORDER_LINE_AMENDED', 'order_products', Number(l.order_product_id), 'is_active', '1', '0',
        `Line ${l.line_no} removed by Amendment ${reference}.`);
      continue;
    }
    let lineId;
    let before;
    if (kind === 'add') {
      lineId = await orderProductRepository.duplicate(Number(l.source_line_id), orderId, Number(l.line_no));
      before = null;
      quantitiesChanged = true;
    } else {
      lineId = Number(l.order_product_id);
      before = current[lineId];
      if (!sameNum(f(l.old_quantity), f(l.new_quantity)) || !samePieces(l.old_pieces, l.new_pieces)) quantitiesChanged = true;
    }
    await applyLine(lineId, l, mdId, userId, reference, before);
  }
  // A line added by the amendment gets its price check figures at the fixed rate.
  await orderLinePricing.snapshotLines(orderId);
  // The money follows: a balance already set (CI issued, not cleared yet) is worked out again.
  const order = await orderRepository.find(orderId);
  const payment = await orderPaymentStatusRepository.find(orderId);
  if (order && payment && payment.balance_amount !== null && payment.balance_cleared_at === null) {
    const newBalance = orderPaymentStatusRepository.balanceDue(await orderProductRepository.totalFobValue(orderId), Number(order.advance_pct), payment);
    await orderPaymentStatusRepository.setBalanceAmount(orderId, newBalance, payment.balance_due_date);
    await auditLogRepository.log(userId, 'BALANCE_AMOUNT_CHANGED', 'orders', orderId, 'balance_amount', String(payment.balance_amount),
      newBalance.toFixed(2), `Balance worked out again after Amendment ${reference} (Products & prices).`);
  }
  if (quantitiesChanged && (await hasSupplierPo(orderId))) await amendmentRepository.setSupplierPoRevisionNeeded(amendmentId, true);
  await auditLogRepository.log(userId, 'AMENDMENT_ACTIVATED', 'amendments', amendmentId, 'products', null, reference,
    'Products & prices applied to the order; the stage and the fixed exchange rate do not change.');
}

/** Writes one amended line onto the order and keeps its new fixed price. */
async function applyLine(lineId, l, mdId, userId, reference, before) {
  const line = await orderProductRepository.find(lineId);
  if (!line) return;
  const sizeV = { piece_length_cm: l.new_length_cm, piece_width_cm: l.new_width_cm, piece_thickness_cm: l.new_thickness_cm };
  const sizeChanged = before === null
    || !sameNum(f(l.old_length_cm), f(l.new_length_cm))
    || !sameNum(f(l.old_width_cm), f(l.new_width_cm))
    || !sameNum(f(l.old_thickness_cm), f(l.new_thickness_cm));
  let dimensions = line.dimensions;
  if (sizeChanged && !Number(line.as_per_annexure || 0)) {
    const text = lineUnits.dimensionsText(f(l.new_length_cm), f(l.new_width_cm), f(l.new_thickness_cm));
    dimensions = text !== '' ? text : line.dimensions;
  }
  const qty = l.new_quantity !== null ? Number(l.new_quantity).toFixed(3) : null;
  await orderProductRepository.update(
    lineId, String(line.description), dimensions, line.finish, qty,
    qty === null ? !!Number(line.quantity_is_tbc) : false, line.unit,
    Number(l.new_unit_price).toFixed(2), String(line.hs_code)
  );
  await orderProductRepository.setPieceSize(lineId, sizeV);
  await orderProductRepository.setPieces(lineId, l.new_pieces !== null ? Number(l.new_pieces) : null, line.size_note ?? null);
  await db.execute('UPDATE order_products SET qt_fixed_unit_price = :p WHERE id = :id', { p: Number(l.new_unit_price).toFixed(2), id: lineId });
  const num = (v) => (v === null || v === undefined ? null : Number(v));
  await auditLogRepository.log(userId, 'ORDER_LINE_AMENDED', 'order_products', lineId, 'line',
    before ? JSON.stringify({ quantity: num(l.old_quantity), pieces: num(l.old_pieces), unit_price: num(l.old_unit_price), size: [num(l.old_length_cm), num(l.old_width_cm), num(l.old_thickness_cm)] }) : null,
    JSON.stringify({ quantity: num(l.new_quantity), pieces: num(l.new_pieces), unit_price: num(l.new_unit_price), size: [num(l.new_length_cm), num(l.new_width_cm), num(l.new_thickness_cm)] }),
    `${before ? `Line ${l.line_no} changed` : `Line ${l.line_no} added`} by Amendment ${reference}.`);
  // The MD approved the amendment — a lowered (or new) price below the floor is approved with it.
  const fresh = await orderProductRepository.find(lineId);
  const order = await orderRepository.find(Number(fresh.order_id));
  const check = await orderLinePricing.checkLine({ ...fresh, price_approved_at: null }, await orderLinePricing.context(order || {}));
  if (orderLinePricing.APPROVABLE.includes(check.status)) {
    await orderLinePricing.approveByAmendment(lineId, mdId, `Agreed with the buyer in Amendment ${reference} (Products & prices), approved by the MD.`);
  } else {
    await orderLinePricing.afterSave(lineId, before || null, userId);
  }
}

/** The AMD document's "Products & prices" section. */
async function documentBlock(amendment) {
  const lines = await amendmentRepository.productLines(Number(amendment.id));
  let t = {};
  try { t = JSON.parse(amendment.products_totals_json || '') || {}; } catch (e) { t = {}; }
  const currency = String(t.currency || '');
  const units = [];
  for (const l of lines) {
    const u = lineTable.docUnit(l.unit) ?? String(l.unit || '').trim().toUpperCase();
    if (!units.includes(u)) units.push(u);
  }
  const oneUnit = units.length === 1 ? units[0] : null;
  const size = (a, b, c) => {
    const p = [a, b, c].filter((v) => v !== null && v !== undefined && Number(v) > 0).map((v) => lineUnits.num(Number(v)));
    return p.length === 0 ? '' : p.join('×');
  };
  const qtyText = (q, unit) => {
    if (q === null || q === undefined) return 'To Be Confirmed';
    const dec = lineTable.quantityDecimals(unit) ?? 3;
    return nf(q, dec);
  };
  const rows = [];
  for (const l of lines) {
    const kind = String(l.change_kind);
    const removed = kind === 'remove';
    const added = kind === 'add';
    const changes = [];
    if (added) changes.push('add');
    else if (removed) changes.push('remove');
    else {
      if (!sameNum(f(l.old_quantity), f(l.new_quantity)) || !samePieces(l.old_pieces, l.new_pieces)) changes.push('quantity');
      if (size(l.old_length_cm, l.old_width_cm, l.old_thickness_cm) !== size(l.new_length_cm, l.new_width_cm, l.new_thickness_cm)) changes.push('size');
      if (Number(l.price_lowered) === 1) changes.push('price');
    }
    const unitLabel = lineTable.docUnit(l.unit) ?? String(l.unit || '');
    const cell = (newV, oldV) => (removed ? { new: '', old: oldV } : { new: newV, old: (!added && oldV !== newV) ? oldV : '' });
    const suffix = oneUnit === null ? ` ${unitLabel}` : '';
    rows.push({
      no: Number(l.line_no),
      product: String(l.description),
      size: cell(size(l.new_length_cm, l.new_width_cm, l.new_thickness_cm) || '—', size(l.old_length_cm, l.old_width_cm, l.old_thickness_cm) || '—'),
      pieces: cell(l.new_pieces !== null ? nf(l.new_pieces, 0) : '—', l.old_pieces !== null ? nf(l.old_pieces, 0) : '—'),
      quantity: cell(qtyText(l.new_quantity, l.unit) + suffix, qtyText(l.old_quantity, l.unit) + suffix),
      price: cell(money(f(l.new_unit_price)), money(f(l.old_unit_price))),
      total: cell(money(f(l.new_line_total)), money(f(l.old_line_total))),
      change: changes.length === 0 ? DOC_CHANGE_LABELS.none : changes.map((k) => DOC_CHANGE_LABELS[k]).join(', '),
      removed,
    });
  }
  const oldTotal = t.old_total !== undefined ? money(Number(t.old_total)) : '—';
  return {
    currency,
    unit: oneUnit,
    qty_header: oneUnit ?? 'Qty',
    price_header: `Price (${currency})${oneUnit !== null ? ` per ${oneUnit}` : ''}`,
    total_header: `Total (${currency})`,
    rows,
    old_total: oldTotal,
    new_total: t.new_total !== undefined ? money(Number(t.new_total)) : '—',
    total_label: `Order total (was ${currency} ${oldTotal})`,
    payment_effect: Object.keys(t).length ? paymentEffect(t) : '',
  };
}

/** 'YYYY-MM-DD …' → "12 Nov" */
function dayShort(value) {
  const full = priceLock.dayText(value);
  return full === '' ? '' : full.split(' ').slice(0, 2).join(' ');
}

/**
 * Mockup screen 2: after an activated products amendment, what to re-issue — one row per
 * document. Null when nothing is left to do (every row done, or no such amendment).
 */
async function checklist(order) {
  const orderId = Number(order.id);
  const a = await amendmentRepository.latestActiveProducts(orderId);
  if (!a || !a.activated_at) return null;
  const at = String(a.activated_at);
  const ref = String(a.amendment_reference);
  const rev = (d, plus = 0) => `Rev.${String(Number(d.revision_number) + plus).padStart(2, '0')}`;
  const after = (d) => !!d && String(d.generated_at) >= at;
  const doc = (code) => documentRepository.findLatestForOrderAndTypeCode(orderId, code);
  const items = [];
  let pending = 0;

  const qt = await doc('QT');
  if (qt) {
    items.push({ document: `Quotation ${rev(qt)}`, status: `Approved ${dayShort(order.prices_fixed_at)}`, state: 'ok',
      todo: 'nothing — the signed amendment and the Quotation together are the agreement' });
  }
  for (const [code, name] of [['PI', 'Proforma Invoice'], ['OC', 'Order Confirmation']]) {
    const d = await doc(code);
    if (!d) {
      items.push({ document: name, status: 'Not made yet', state: 'ok', todo: 'will use the amended figures' });
    } else if (!after(d)) {
      pending += 1;
      items.push({ document: `${name} ${rev(d)}`, status: `Outdated by ${ref}`, state: 'todo',
        todo: `Generate revised ${name} (${rev(d, 1)})`, generate: code, codes: [code] });
    }
  }
  const poRef = String(order.buyers_po_ref || '').trim();
  const buyerPoDoc = await doc('BUYERPO');
  if ((poRef !== '' && poRef !== 'NIL') || buyerPoDoc) {
    const ticked = Number(order.buyer_po_amended_by_amendment_id || 0) === Number(a.id);
    if (!ticked && !after(buyerPoDoc)) {
      pending += 1;
      const received = order.buyer_po_received_on || null;
      items.push({ document: 'Buyer PO (buyer\'s)', status: received ? `Received ${dayShort(received)}` : 'Received', state: 'todo',
        todo: 'Ask the buyer for a revised PO, or tick "The signed amendment amends the Buyer PO"', tick: Number(a.id) });
    }
  }
  const sp = await doc('SUPPO');
  if (!sp) {
    items.push({ document: 'Supplier PO', status: 'Not made yet', state: 'ok', todo: 'will use the amended figures' });
  } else if (Number(a.supplier_po_revision_needed) === 1 && !after(sp)) {
    pending += 1;
    items.push({ document: 'Supplier PO', status: 'Quantities changed', state: 'todo',
      todo: 'Procurement: revise the Supplier PO (task sent to Procurement)', link: `/orders/${orderId}#step-supplier-po` });
  }
  const ci = await doc('CI');
  const pl = await doc('PL');
  if (!ci && !pl) {
    items.push({ document: 'Commercial Invoice / Packing List', status: 'Not made yet', state: 'ok', todo: 'will use the amended figures' });
  } else if ((ci && !after(ci)) || (pl && !after(pl))) {
    pending += 1;
    items.push({ document: 'Commercial Invoice / Packing List', status: `Outdated by ${ref}`, state: 'todo',
      todo: 'Generate the revised Commercial Invoice / Packing List — the difference needs a credit / debit note in Zoho Books',
      generate_ci: !!ci && !after(ci), generate_pl: !!pl && !after(pl),
      codes: [...(ci && !after(ci) ? ['CI'] : []), ...(pl && !after(pl) ? ['PL'] : [])] });
  }
  if (pending === 0) return null;
  let t = {};
  try { t = JSON.parse(a.products_totals_json || '') || {}; } catch (e) { t = {}; }
  const payment = await orderPaymentStatusRepository.find(orderId);
  const total = Number(await orderProductRepository.totalFobValue(orderId));
  const received = payment && payment.advance_amount !== null && payment.advance_amount !== undefined ? Number(payment.advance_amount) : null;
  const balance = orderPaymentStatusRepository.balanceDue(total, Number(order.advance_pct || 0), payment);
  const c = String(order.currency_code || '');
  const moneyText = `Money worked out again: total ${c} ${nf(total, 2)}`
    + (received !== null ? ` · advance received ${c} ${nf(received, 2)}` : ' · no advance received yet')
    + ` · balance now ${c} ${nf(balance, 2)}`
    + (t.old_balance !== undefined ? ` (was ${c} ${nf(t.old_balance, 2)})` : '') + '.'
    + (received !== null && received > total ? ` ${c} ${nf(received - total, 2)} received above the new total shows as excess.` : '')
    + ' Commission, incentive and profit follow the amended figures.';
  const stage = order.current_stage_number !== null && order.current_stage_number !== undefined
    ? `Stage ${Number(order.current_stage_number)} (${String(order.current_stage_name || '')})` : 'its stage';
  const rate = priceLock.fixedRate(order);
  return {
    amendment: a,
    title: `Amendment ${ref} activated — re-issue checklist`,
    intro: `Products & prices as per Amendment ${ref}, signed by the buyer ${priceLock.dayText(a.activated_at)}. Order stays at ${stage}.`,
    items,
    money: moneyText,
    rate: rate !== null ? `Exchange rate unchanged: ₹${nf(rate, 2)} (fixed at QT approval).` : null,
  };
}

/** The checklist's tick: "The signed amendment amends the Buyer PO" — kept on the order, logged. */
async function tickBuyerPo(orderId, amendmentId, userId) {
  const a = await amendmentRepository.find(amendmentId);
  if (!a || Number(a.order_id) !== Number(orderId) || (a.amendment_type || '') !== 'products' || a.status !== 'active') {
    return 'Only an activated Products & prices amendment of this order can amend its Buyer PO.';
  }
  await db.execute('UPDATE orders SET buyer_po_amended_by_amendment_id = :a, buyer_po_amended_at = NOW(), buyer_po_amended_by = :u WHERE id = :o',
    { a: amendmentId, u: userId, o: orderId });
  await auditLogRepository.log(userId, 'BUYER_PO_AMENDED_BY_AMENDMENT', 'orders', orderId, 'buyer_po_amended_by_amendment_id', null, String(amendmentId),
    `The signed Amendment ${a.amendment_reference} amends the Buyer PO.`);
  return null;
}

module.exports = {
  NEW_LINE_SLOTS, CHANGE_LABELS, DOC_CHANGE_LABELS, money, priceUpMessage, cannotStart, currentLines, draft, totals,
  signedDifference, advanceNote, paymentEffect, create, activate, documentBlock, checklist, tickBuyerPo,
};
