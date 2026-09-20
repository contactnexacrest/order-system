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

module.exports = { forOrder, add, totalFobValue, orderedQuantitySummary };
