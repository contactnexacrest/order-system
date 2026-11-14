'use strict';

const db = require('../config/db');

async function forOrder(orderId) {
  // D-245 P5: read once per report run (batchCache scope only).
  return require('../helpers/batchCache').memoRead(`products:${orderId}`, () => db.query('SELECT * FROM order_products WHERE order_id = :order_id AND is_active = 1 ORDER BY line_no', { order_id: orderId }));
}

// QA-5 ORD-05: optional executor lets this join the caller's transaction
// (order creation — see ordersController.store()), defaulting to the plain
// pool for every other existing call site.
async function add(orderId, lineNo, description, dimensions, finish, quantity, quantityIsTbc, unit, unitPrice, hsCode = '680293', executor = db) {
  unitPrice = require('../helpers/orderLineValidator').priceInput(unitPrice); // HNT-011 (owner, 13 Nov): a typed 0 is 0.00, only blank is "no price yet"
  const fobValue = (quantity !== null && unitPrice !== null && !quantityIsTbc)
    ? require('../helpers/money').round2(parseFloat(quantity) * parseFloat(unitPrice)).toFixed(2) // QA batch 4, MON-3: PHP round(), not the binary float
    : null;

  const result = await executor.execute(
    `INSERT INTO order_products
        (order_id, line_no, description, finish, dimensions, quantity, quantity_is_tbc, unit, unit_price, fob_value, hs_code)
     VALUES
        (:order_id, :line_no, :description, :finish, :dimensions, :quantity, :quantity_is_tbc, :unit, :unit_price, :fob_value, :hs_code)`,
    {
      order_id: orderId, line_no: lineNo, description, finish, dimensions,
      quantity: quantity || null, quantity_is_tbc: quantityIsTbc ? 1 : 0, unit,
      unit_price: unitPrice, fob_value: fobValue, hs_code: hsCode || '680293',
    }
  );
  return result.insertId;
}

async function find(id) {
  return db.queryOne('SELECT * FROM order_products WHERE id = :id', { id });
}

async function nextLineNo(orderId) {
  // Owner, 14 Nov 2026: removed (inactive) lines never count — a new line follows the visible ones.
  const row = await db.queryOne('SELECT COALESCE(MAX(line_no), 0) + 1 AS next_line FROM order_products WHERE order_id = :order_id AND is_active = 1', { order_id: orderId });
  return parseInt(row.next_line, 10);
}

/**
 * Order-Edit feature (added 2026-09-26) — order_products previously had
 * add() (called once, at order creation) and nothing else: no
 * update/delete/duplicate path existed for a line already saved.
 */
async function update(id, description, dimensions, finish, quantity, quantityIsTbc, unit, unitPrice, hsCode) {
  unitPrice = require('../helpers/orderLineValidator').priceInput(unitPrice); // HNT-011 (owner, 13 Nov): a typed 0 is 0.00, only blank is "no price yet"
  const fobValue = (quantity !== null && unitPrice !== null && !quantityIsTbc)
    ? require('../helpers/money').round2(parseFloat(quantity) * parseFloat(unitPrice)).toFixed(2) // QA batch 4, MON-3: PHP round(), not the binary float
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
      unit_price: unitPrice, fob_value: fobValue, hs_code: hsCode || '680293',
      id,
    }
  );
}

/** Soft delete — is_active=0, same convention as everywhere else in this system; nothing is ever hard-deleted. */
async function softDelete(id) {
  await db.execute('UPDATE order_products SET is_active = 0 WHERE id = :id', { id });
}

/**
 * Owner, 14 Nov 2026: the order's visible lines are always numbered 1, 2, 3 … with no gap, however
 * often lines are removed and added (the documents already print by position). Called after a line
 * is removed on the order page. Not after a "Products & prices" amendment: its lines keep the
 * numbers the signed amendment gives them (D-342). Twin of OrderProductRepository::renumber().
 */
async function renumber(orderId) {
  const rows = await db.query('SELECT id FROM order_products WHERE order_id = :o AND is_active = 1 ORDER BY line_no, id', { o: orderId });
  for (let i = 0; i < rows.length; i += 1) {
    await db.execute('UPDATE order_products SET line_no = :n WHERE id = :id', { n: i + 1, id: rows[i].id });
  }
}

/**
 * Clones a line's fields into a new row — either a new line on the same
 * order (the common case: "same product, just the name or dimension
 * changed") or, with targetOrderId, onto a different order entirely
 * (reused by whole-order duplication).
 */
/*
 * Owner decision D-271/D-272 (points 13 and 24, 11 Nov 2026): an exact copy — every option, the
 * piece size, material, product, price-request link and Zoho item — placed right below the
 * original on the same order and marked "copy of line N". The MD's price approval, the
 * figures fixed at PI and an open approval request are NOT copied: the caller runs
 * orderLinePricing.afterSave() so the copy is checked at once.
 */
async function duplicate(id, targetOrderId = null, atLineNo = null) {
  const source = await find(id);
  if (!source) {
    throw new Error(`Cannot duplicate order_products row ${id} — not found.`);
  }
  const sameOrder = targetOrderId === null || Number(targetOrderId) === Number(source.order_id);
  const orderId = targetOrderId ?? source.order_id;
  let lineNo = await nextLineNo(orderId);
  if (atLineNo !== null) {
    // D-342: a line added by an amendment takes the number the amendment gave it (no other line moves).
    lineNo = Number(atLineNo);
  } else if (sameOrder) {
    // Right below the original: the lines after it move down by one.
    lineNo = Number(source.line_no) + 1;
    await db.execute('UPDATE order_products SET line_no = line_no + 1 WHERE order_id = :o AND is_active = 1 AND line_no >= :n ORDER BY line_no DESC', { o: orderId, n: lineNo });
  }
  const newId = await add(
    orderId,
    lineNo,
    source.description,
    source.dimensions,
    source.finish,
    source.quantity !== null ? String(source.quantity) : null,
    !!source.quantity_is_tbc,
    source.unit,
    source.unit_price !== null ? String(source.unit_price) : null,
    source.hs_code
  );
  await setLineType(newId, source.line_type || 'goods', source.sac_code || null);
  // D-215 (5a): the copy keeps the line's product category and attribute values.
  await db.execute('UPDATE order_products SET category_id = :c, attribute_values = :v, catalog_product_id = :cp, price_set_by = :pb WHERE id = :id', {
    c: source.category_id || null, v: source.attribute_values || null, cp: source.catalog_product_id || null, pb: source.price_set_by || null, id: newId,
  });
  // D-272: and everything else that describes the line.
  await db.execute(
    `UPDATE order_products SET product_id = :p, material = :m, spec_selection = :s, price_request_id = :pr, zoho_item_id = :z,
            piece_length_cm = :l, piece_width_cm = :w, piece_thickness_cm = :t, copied_from_id = :cf, as_per_annexure = :apa,
            pieces_count = :pc, size_note = :sn WHERE id = :id`, {
      p: source.product_id ?? null, m: source.material ?? null, s: source.spec_selection ?? null, pr: source.price_request_id ?? null, z: source.zoho_item_id ?? null,
      l: source.piece_length_cm ?? null, w: source.piece_width_cm ?? null, t: source.piece_thickness_cm ?? null, cf: sameOrder ? id : null, apa: Number(source.as_per_annexure || 0),
      // D-339: the No. of pieces and the size note.
      pc: source.pieces_count ?? null, sn: source.size_note ?? null, id: newId,
    }
  );
  return newId;
}

/**
 * Owner decision D-270 (point 25): the piece size Length × Width × Thickness (cm) a line's price
 * is converted with. Used for the price check only — the Dimensions text prints.
 */
/** Owner decision D-336: the line's size is given in the annexure ("As per annexure"). */
async function setAsPerAnnexure(id, perAnnexure) {
  await db.execute('UPDATE order_products SET as_per_annexure = :a WHERE id = :id', { a: perAnnexure ? 1 : 0, id });
}

/** Owner decision D-339: a line's No. of pieces and its size note (printed under the line). */
async function setPieces(id, pieces, sizeNote) {
  await db.execute('UPDATE order_products SET pieces_count = :p, size_note = :n WHERE id = :id', { p: pieces ?? null, n: sizeNote ?? null, id });
}

async function setPieceSize(id, size) {
  await db.execute('UPDATE order_products SET piece_length_cm = :l, piece_width_cm = :w, piece_thickness_cm = :t WHERE id = :id', {
    l: (size || {}).piece_length_cm ?? null, w: (size || {}).piece_width_cm ?? null, t: (size || {}).piece_thickness_cm ?? null, id,
  });
}

/** D-272: the line number of the line a copy was made from (null when it is gone or not a copy). */
function copiedFromLineNo(line, lines) {
  if (!line.copied_from_id) return null;
  const src = lines.find((l) => Number(l.id) === Number(line.copied_from_id));
  return src ? Number(src.line_no) : null;
}

// Owner decision 2 Nov 2026 (D-240): the Products-menu product a line was picked from (null = typed by
// hand), and who set its unit price (for the self-approval rule on a discount below the reference price).
async function setCatalogProduct(id, catalogProductId, executor = db) {
  await executor.execute('UPDATE order_products SET catalog_product_id = :c WHERE id = :id', { c: catalogProductId || null, id });
}

async function setPriceSetBy(id, userId, executor = db) {
  await executor.execute('UPDATE order_products SET price_set_by = :u WHERE id = :id', { u: userId, id });
}

/** Owner decision D-215: a line is goods, a service (SAC code, its own Export Service Invoice) or a charge. */
const LINE_TYPES = { goods: 'Goods', service: 'Service', charge: 'Charge' };

async function setLineType(id, lineType, sacCode) {
  await db.execute('UPDATE order_products SET line_type = :t, sac_code = :s WHERE id = :id', { t: lineType, s: lineType === 'service' ? sacCode : null, id });
}

/** The service lines' total — invoiced on the Export Service Invoice, never part of the goods FOB value. */
async function totalServiceValue(orderId) {
  const row = await db.queryOne("SELECT COALESCE(SUM(fob_value), 0) AS total FROM order_products WHERE order_id = :order_id AND is_active = 1 AND line_type = 'service'", { order_id: orderId });
  return parseFloat(row.total);
}

/** The goods FOB value (goods and charges; D-215: service lines are invoiced separately). */
async function totalFobValue(orderId) {
  const row = await db.queryOne("SELECT COALESCE(SUM(fob_value), 0) AS total FROM order_products WHERE order_id = :order_id AND is_active = 1 AND line_type <> 'service'", { order_id: orderId });
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

module.exports = { LINE_TYPES, forOrder, add, find, nextLineNo, update, softDelete, renumber, duplicate, setPieceSize, setPieces, setAsPerAnnexure, copiedFromLineNo, setLineType, totalServiceValue, totalFobValue, orderedQuantitySummary, setCatalogProduct, setPriceSetBy };
