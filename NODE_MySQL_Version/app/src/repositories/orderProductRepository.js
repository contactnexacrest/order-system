'use strict';

const db = require('../config/db');

async function forOrder(orderId) {
  return db.query('SELECT * FROM order_products WHERE order_id = :order_id AND is_active = 1 ORDER BY line_no', { order_id: orderId });
}

async function add(orderId, lineNo, description, dimensions, finish, quantity, quantityIsTbc, unit, unitPrice, hsCode = '6802.93') {
  const fobValue = (quantity !== null && unitPrice !== null && !quantityIsTbc)
    ? String(parseFloat(quantity) * parseFloat(unitPrice))
    : null;

  const result = await db.execute(
    `INSERT INTO order_products
        (order_id, line_no, description, finish, dimensions, quantity, quantity_is_tbc, unit, unit_price, fob_value, hs_code)
     VALUES
        (:order_id, :line_no, :description, :finish, :dimensions, :quantity, :quantity_is_tbc, :unit, :unit_price, :fob_value, :hs_code)`,
    {
      order_id: orderId, line_no: lineNo, description, finish, dimensions,
      quantity: quantity || null, quantity_is_tbc: quantityIsTbc ? 1 : 0, unit,
      unit_price: unitPrice || null, fob_value: fobValue, hs_code: hsCode || '6802.93',
    }
  );
  return result.insertId;
}

async function find(id) {
  return db.queryOne('SELECT * FROM order_products WHERE id = :id', { id });
}

async function nextLineNo(orderId) {
  const row = await db.queryOne('SELECT COALESCE(MAX(line_no), 0) + 1 AS next_line FROM order_products WHERE order_id = :order_id', { order_id: orderId });
  return parseInt(row.next_line, 10);
}

/**
 * Order-Edit feature (added 2026-09-26) — order_products previously had
 * add() (called once, at order creation) and nothing else: no
 * update/delete/duplicate path existed for a line already saved.
 */
async function update(id, description, dimensions, finish, quantity, quantityIsTbc, unit, unitPrice, hsCode) {
  const fobValue = (quantity !== null && unitPrice !== null && !quantityIsTbc)
    ? String(parseFloat(quantity) * parseFloat(unitPrice))
    : null;

  await db.execute(
    `UPDATE order_products SET
        description = :description, finish = :finish, dimensions = :dimensions,
        quantity = :quantity, quantity_is_tbc = :quantity_is_tbc, unit = :unit,
        unit_price = :unit_price, fob_value = :fob_value, hs_code = :hs_code
     WHERE id = :id`,
    {
      description, finish, dimensions,
      quantity: quantity || null, quantity_is_tbc: quantityIsTbc ? 1 : 0, unit,
      unit_price: unitPrice || null, fob_value: fobValue, hs_code: hsCode || '6802.93',
      id,
    }
  );
}

/** Soft delete — is_active=0, same convention as everywhere else in this system; nothing is ever hard-deleted. */
async function softDelete(id) {
  await db.execute('UPDATE order_products SET is_active = 0 WHERE id = :id', { id });
}

/**
 * Clones a line's fields into a new row — either a new line on the same
 * order (the common case: "same product, just the name or dimension
 * changed") or, with targetOrderId, onto a different order entirely
 * (reused by whole-order duplication).
 */
async function duplicate(id, targetOrderId = null) {
  const source = await find(id);
  if (!source) {
    throw new Error(`Cannot duplicate order_products row ${id} — not found.`);
  }
  const orderId = targetOrderId ?? source.order_id;
  return add(
    orderId,
    await nextLineNo(orderId),
    source.description,
    source.dimensions,
    source.finish,
    source.quantity !== null ? String(source.quantity) : null,
    !!source.quantity_is_tbc,
    source.unit,
    source.unit_price !== null ? String(source.unit_price) : null,
    source.hs_code
  );
}

async function totalFobValue(orderId) {
  const row = await db.queryOne('SELECT COALESCE(SUM(fob_value), 0) AS total FROM order_products WHERE order_id = :order_id AND is_active = 1', { order_id: orderId });
  return parseFloat(row.total);
}

/**
 * Ordered-quantity total for the shortfall-tolerance check
 * (ordersController.savePacking). Only meaningful when every active line
 * shares one unit and none is quantity_is_tbc — mixing SQM and PCS (or
 * comparing against a quantity nobody has confirmed yet) has no sane
 * single percentage, so the caller falls back to manual entry in that
 * case rather than the system silently comparing apples to oranges.
 * @returns {Promise<{total: ?number, unit: ?string, comparable: boolean}>}
 */
async function orderedQuantitySummary(orderId) {
  const rows = await forOrder(orderId);
  if (!rows.length) {
    return { total: null, unit: null, comparable: false };
  }

  const units = new Set();
  let total = 0;
  for (const r of rows) {
    if (r.quantity_is_tbc || r.quantity === null) {
      return { total: null, unit: null, comparable: false };
    }
    units.add(r.unit || '');
    total += parseFloat(r.quantity);
  }

  if (units.size !== 1) {
    return { total: null, unit: null, comparable: false };
  }

  return { total, unit: [...units][0], comparable: true };
}

module.exports = { forOrder, add, find, nextLineNo, update, softDelete, duplicate, totalFobValue, orderedQuantitySummary };
