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

module.exports = { forOrder, add, totalFobValue };
