'use strict';

const db = require('../config/db');

async function forOrder(orderId) {
  return db.query('SELECT * FROM order_crates WHERE order_id = :order_id ORDER BY crate_no', { order_id: orderId });
}

/**
 * Crates are entered as a batch from the factory packing sheet, so a
 * re-submission replaces the whole set for this order rather than trying to
 * diff/match rows.
 */
async function replaceForOrder(orderId, crates) {
  return db.transaction(async (conn) => {
    await conn.execute('DELETE FROM order_crates WHERE order_id = :order_id', { order_id: orderId });
    for (const c of crates) {
      await conn.execute(
        `INSERT INTO order_crates
            (order_id, crate_no, marks_numbers, product_description, dimensions_lwh_cm, pcs, net_weight_kg, gross_weight_kg, cbm, hs_code)
         VALUES
            (:order_id, :crate_no, :marks_numbers, :product_description, :dimensions, :pcs, :net, :gross, :cbm, :hs_code)`,
        {
          order_id: orderId,
          crate_no: c.crate_no,
          marks_numbers: c.marks_numbers ?? null,
          product_description: c.product_description ?? null,
          dimensions: c.dimensions_lwh_cm ?? null,
          pcs: c.pcs || null,
          net: c.net_weight_kg || null,
          gross: c.gross_weight_kg || null,
          cbm: c.cbm || null,
          hs_code: c.hs_code || null,
        }
      );
    }
  });
}

module.exports = { forOrder, replaceForOrder };
