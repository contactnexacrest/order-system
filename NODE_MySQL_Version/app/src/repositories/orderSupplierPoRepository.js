'use strict';

const db = require('../config/db');

async function create(orderId, supplierId, supplierPoReference, data) {
  const result = await db.execute(
    `INSERT INTO order_supplier_po
        (order_id, supplier_id, supplier_po_reference, material_stone_type, grade, surface_finish,
         dimensions, dimensional_tolerance, quantity, unit, colour_reference, special_requirements,
         unit_price_inr, basic_value_inr, gst_rate_pct, gst_amount_inr, total_payable_inr,
         advance_pct, advance_amount_inr, balance_amount_inr,
         delivery_location, required_delivery_date, packing_requirement, status)
     VALUES
        (:order_id, :supplier_id, :ref, :material, :grade, :finish,
         :dimensions, :tolerance, :quantity, :unit, :colour, :special,
         :unit_price, :basic_value, :gst_rate, :gst_amount, :total_payable,
         :advance_pct, :advance_amount, :balance_amount,
         :delivery_location, :required_delivery_date, :packing_requirement, 'issued')`,
    {
      order_id: orderId, supplier_id: supplierId, ref: supplierPoReference,
      material: data.material_stone_type ?? null,
      grade: data.grade ?? 'Grade A',
      finish: data.surface_finish ?? null,
      dimensions: data.dimensions ?? null,
      tolerance: data.dimensional_tolerance ?? null,
      quantity: data.quantity || null,
      unit: data.unit ?? null,
      colour: data.colour_reference ?? null,
      special: data.special_requirements ?? null,
      unit_price: data.unit_price_inr || null,
      basic_value: data.basic_value_inr || null,
      gst_rate: data.gst_rate_pct || null,
      gst_amount: data.gst_amount_inr || null,
      total_payable: data.total_payable_inr || null,
      advance_pct: data.advance_pct || null,
      advance_amount: data.advance_amount_inr || null,
      balance_amount: data.balance_amount_inr || null,
      delivery_location: data.delivery_location ?? null,
      required_delivery_date: data.required_delivery_date || null,
      packing_requirement: data.packing_requirement ?? null,
    }
  );
  return result.insertId;
}

async function findLatestForOrder(orderId) {
  return db.queryOne(
    `SELECT sp.*, s.supplier_legal_name, s.address AS supplier_address, s.gstin AS supplier_gstin,
            s.pan AS supplier_pan, s.contact_person AS supplier_contact_person, s.phone AS supplier_phone,
            s.supplier_type
     FROM order_supplier_po sp
     JOIN suppliers s ON s.id = sp.supplier_id
     WHERE sp.order_id = :order_id
     ORDER BY sp.id DESC LIMIT 1`,
    { order_id: orderId }
  );
}

async function markSigned(id) {
  await db.execute("UPDATE order_supplier_po SET status = 'signed' WHERE id = :id", { id });
}

module.exports = { create, findLatestForOrder, markSigned };
