'use strict';

const db = require('../config/db');

/**
 * Also carries incoterm_code, current_stage_number, currency_code, and
 * total_fob_value — the card-grid list view (see orders/index.njk) needs
 * all of these for its incoterm tag, 9-segment stage bar, and displayed
 * amount, none of which a plain o.* + one join used to expose.
 */
async function all() {
  return db.query(
    `SELECT o.*, c.company_legal_name, sm.stage_name AS current_stage_name,
            sm.stage_number AS current_stage_number, it.code AS incoterm_code, cur.code AS currency_code,
            (SELECT COALESCE(SUM(op.fob_value), 0) FROM order_products op WHERE op.order_id = o.id) AS total_fob_value,
            EXISTS (
                SELECT 1 FROM order_payment_status ops
                WHERE ops.order_id = o.id AND ops.balance_due_date IS NOT NULL
                  AND ops.balance_cleared_at IS NULL AND ops.balance_due_date < CURDATE()
            ) AS is_overdue
     FROM orders o
     JOIN clients c ON c.id = o.client_id
     LEFT JOIN stages_master sm ON sm.id = o.current_stage_id
     LEFT JOIN incoterms it ON it.id = o.incoterm_id
     LEFT JOIN currencies cur ON cur.id = o.currency_id
     WHERE o.is_archived = 0
     ORDER BY o.created_at DESC`
  );
}

/** Archived orders only — visible via the separate view_archived_orders permission, never deleted, never dropped from any other listing/report. */
async function allArchived() {
  return db.query(
    `SELECT o.*, c.company_legal_name, sm.stage_name AS current_stage_name,
            u.name AS archived_by_name
     FROM orders o
     JOIN clients c ON c.id = o.client_id
     LEFT JOIN stages_master sm ON sm.id = o.current_stage_id
     LEFT JOIN users u ON u.id = o.archived_by
     WHERE o.is_archived = 1
     ORDER BY o.archived_at DESC`
  );
}

async function archive(orderId, archivedBy) {
  await db.execute(
    'UPDATE orders SET is_archived = 1, archived_at = NOW(), archived_by = :archived_by WHERE id = :id',
    { archived_by: archivedBy, id: orderId }
  );
}

async function unarchive(orderId) {
  await db.execute(
    'UPDATE orders SET is_archived = 0, archived_at = NULL, archived_by = NULL WHERE id = :id',
    { id: orderId }
  );
}

async function find(id) {
  return db.queryOne(
    `SELECT o.*, c.company_legal_name, c.billing_address, c.consignee_name, c.consignee_address,
            c.vat_eori_tax_no, c.contact_person, c.email AS client_email, c.phone AS client_phone,
            c.country_of_destination, c.notify_party, c.client_unique_number,
            sm.stage_name AS current_stage_name, sm.stage_slug AS current_stage_slug,
            sm.stage_number AS current_stage_number,
            i.code AS incoterm_code, cur.code AS currency_code,
            pl.name AS port_of_loading_name, pd.name AS port_of_discharge_name,
            pp.preset_name, pp.advance_trigger_text, pp.requires_md_approval,
            COALESCE(o.advance_pct_override, pp.advance_pct) AS advance_pct,
            COALESCE(o.balance_pct_override, pp.balance_pct) AS balance_pct,
            COALESCE(o.balance_trigger_option_override, pp.balance_trigger_option) AS balance_trigger_option,
            COALESCE(o.balance_days_override, pp.balance_days) AS balance_days,
            (o.active_amendment_id IS NOT NULL) AS has_active_amendment
     FROM orders o
     JOIN clients c ON c.id = o.client_id
     LEFT JOIN stages_master sm ON sm.id = o.current_stage_id
     JOIN incoterms i ON i.id = o.incoterm_id
     JOIN currencies cur ON cur.id = o.currency_id
     LEFT JOIN ports pl ON pl.id = o.port_of_loading_id
     LEFT JOIN ports pd ON pd.id = o.port_of_discharge_id
     JOIN payment_presets pp ON pp.id = o.payment_preset_id
     WHERE o.id = :id`,
    { id }
  );
}

async function nextSequenceForClient(clientId) {
  const row = await db.queryOne(
    'SELECT COALESCE(MAX(sequence_no), 0) + 1 AS next_seq FROM orders WHERE client_id = :client_id',
    { client_id: clientId }
  );
  return parseInt(row.next_seq, 10);
}

async function create(data, createdBy) {
  const result = await db.execute(
    `INSERT INTO orders
        (order_reference, client_id, sequence_no, buyer_inquiry_ref, payment_preset_id, incoterm_id,
         port_of_loading_id, port_of_discharge_id, port_of_discharge_text, currency_id, coo_type,
         include_annexure_a, special_requirements, container_type, estimated_total_cbm,
         estimated_gross_weight_kg, estimated_net_weight_kg, estimated_package_count,
         estimated_package_type, est_lead_time_text, indicative_freight_low, indicative_freight_high,
         indicative_insurance_amount, buyers_po_ref, quotation_date, quotation_valid_until,
         status, created_by)
     VALUES
        (:order_reference, :client_id, :sequence_no, :buyer_inquiry_ref, :payment_preset_id, :incoterm_id,
         :port_of_loading_id, :port_of_discharge_id, :port_of_discharge_text, :currency_id, :coo_type,
         :include_annexure_a, :special_requirements, :container_type, :estimated_total_cbm,
         :estimated_gross_weight_kg, :estimated_net_weight_kg, :estimated_package_count,
         :estimated_package_type, :est_lead_time_text, :indicative_freight_low, :indicative_freight_high,
         :indicative_insurance_amount, :buyers_po_ref, :quotation_date, :quotation_valid_until,
         'active', :created_by)`,
    {
      order_reference: data.order_reference,
      client_id: data.client_id,
      sequence_no: data.sequence_no,
      buyer_inquiry_ref: data.buyer_inquiry_ref,
      payment_preset_id: data.payment_preset_id,
      incoterm_id: data.incoterm_id,
      port_of_loading_id: data.port_of_loading_id ?? null,
      port_of_discharge_id: data.port_of_discharge_id ?? null,
      port_of_discharge_text: data.port_of_discharge_text ?? null,
      currency_id: data.currency_id,
      coo_type: data.coo_type ?? 'TBC',
      include_annexure_a: data.include_annexure_a ? 1 : 0,
      special_requirements: data.special_requirements ?? null,
      container_type: data.container_type ?? null,
      estimated_total_cbm: data.estimated_total_cbm || null,
      estimated_gross_weight_kg: data.estimated_gross_weight_kg || null,
      estimated_net_weight_kg: data.estimated_net_weight_kg || null,
      estimated_package_count: data.estimated_package_count ?? 'TBD at packing',
      estimated_package_type: data.estimated_package_type ?? 'Wooden Crates',
      est_lead_time_text: data.est_lead_time_text ?? null,
      indicative_freight_low: data.indicative_freight_low || null,
      indicative_freight_high: data.indicative_freight_high || null,
      indicative_insurance_amount: data.indicative_insurance_amount || null,
      buyers_po_ref: data.buyers_po_ref ?? 'NIL',
      quotation_date: data.quotation_date,
      quotation_valid_until: data.quotation_valid_until,
      created_by: createdBy,
    }
  );
  return result.insertId;
}

/**
 * Order-Edit feature (added 2026-09-26) — the core fields set once at
 * creation (create()) had no edit path afterward at all. Deliberately
 * excludes payment_preset_id (payment-terms changes stay routed through
 * the Amendments module, never here) and every system-generated
 * identifier (order_reference, sequence_no, client_id, buyer_inquiry_ref).
 * Caller (ordersController.updateDetails) is responsible for the
 * orderEditGuard check before calling this.
 */
async function updateDetails(orderId, data) {
  await db.execute(
    `UPDATE orders SET
        incoterm_id = :incoterm_id,
        currency_id = :currency_id,
        port_of_loading_id = :port_of_loading_id,
        port_of_discharge_id = :port_of_discharge_id,
        port_of_discharge_text = :port_of_discharge_text,
        coo_type = :coo_type,
        container_type = :container_type,
        buyers_po_ref = :buyers_po_ref,
        special_requirements = :special_requirements,
        est_lead_time_text = :est_lead_time_text,
        estimated_total_cbm = :estimated_total_cbm,
        estimated_gross_weight_kg = :estimated_gross_weight_kg,
        estimated_net_weight_kg = :estimated_net_weight_kg,
        estimated_package_count = :estimated_package_count,
        estimated_package_type = :estimated_package_type,
        indicative_freight_low = :indicative_freight_low,
        indicative_freight_high = :indicative_freight_high,
        indicative_insurance_amount = :indicative_insurance_amount
     WHERE id = :id`,
    {
      incoterm_id: data.incoterm_id,
      currency_id: data.currency_id,
      port_of_loading_id: data.port_of_loading_id ?? null,
      port_of_discharge_id: data.port_of_discharge_id ?? null,
      port_of_discharge_text: data.port_of_discharge_text ?? null,
      coo_type: data.coo_type ?? 'TBC',
      container_type: data.container_type ?? null,
      buyers_po_ref: data.buyers_po_ref || 'NIL',
      special_requirements: data.special_requirements ?? null,
      est_lead_time_text: data.est_lead_time_text ?? null,
      estimated_total_cbm: data.estimated_total_cbm || null,
      estimated_gross_weight_kg: data.estimated_gross_weight_kg || null,
      estimated_net_weight_kg: data.estimated_net_weight_kg || null,
      estimated_package_count: data.estimated_package_count || null,
      estimated_package_type: data.estimated_package_type || null,
      indicative_freight_low: data.indicative_freight_low || null,
      indicative_freight_high: data.indicative_freight_high || null,
      indicative_insurance_amount: data.indicative_insurance_amount || null,
      id: orderId,
    }
  );
}

async function markSample(id) {
  await db.execute('UPDATE orders SET is_sample_data = 1 WHERE id = :id', { id });
}

/** Test Mode (docs/schema.sql Section V) — mirrors markSample()'s pattern. */
async function markTest(id) {
  await db.execute('UPDATE orders SET is_test_data = 1 WHERE id = :id', { id });
}

async function setCurrentStage(orderId, stageId) {
  await db.execute('UPDATE orders SET current_stage_id = :stage_id WHERE id = :id', { stage_id: stageId, id: orderId });
}

async function setPiDates(orderId, piDate, piValidUntil) {
  await db.execute('UPDATE orders SET pi_date = :pi_date, pi_valid_until = :pi_valid_until WHERE id = :id', { pi_date: piDate, pi_valid_until: piValidUntil, id: orderId });
}

async function setProductionStatus(orderId, text) {
  await db.execute('UPDATE orders SET production_status_text = :text WHERE id = :id', { text, id: orderId });
}

async function setBuyersPoRef(orderId, ref) {
  await db.execute('UPDATE orders SET buyers_po_ref = :ref WHERE id = :id', { ref, id: orderId });
}

async function setEstShipmentDate(orderId, text) {
  await db.execute('UPDATE orders SET est_shipment_date_text = :text WHERE id = :id', { text, id: orderId });
}

/** docs/schema.sql Section AF — staff-controlled, per order, default off. */
async function setDisputeButtonVisible(orderId, visible) {
  await db.execute(
    'UPDATE orders SET dispute_button_visible_to_client = :visible WHERE id = :id',
    { visible: visible ? 1 : 0, id: orderId }
  );
}

async function markComplete(orderId) {
  await db.execute("UPDATE orders SET status = 'complete', is_locked = 1 WHERE id = :id", { id: orderId });
}

/** Added 2026-09-19 — see orders.lost_reason/lost_at/lost_by in schema.sql. Mirrors markComplete()'s locking behavior. */
async function markLost(orderId, reason, userId) {
  await db.execute(
    `UPDATE orders
        SET status = 'lost', is_locked = 1, lost_reason = :reason, lost_at = NOW(), lost_by = :user_id
      WHERE id = :id`,
    { reason, user_id: userId, id: orderId }
  );
}

async function applyAmendmentOverride(orderId, advancePct, balancePct, balanceTriggerOption, balanceDays, amendmentId) {
  await db.execute(
    `UPDATE orders
     SET advance_pct_override = :advance_pct,
         balance_pct_override = :balance_pct,
         balance_trigger_option_override = :balance_trigger_option,
         balance_days_override = :balance_days,
         active_amendment_id = :amendment_id
     WHERE id = :id`,
    {
      advance_pct: advancePct, balance_pct: balancePct, balance_trigger_option: balanceTriggerOption,
      balance_days: balanceDays, amendment_id: amendmentId, id: orderId,
    }
  );
}

async function forClient(clientId) {
  return db.query(
    `SELECT o.*, sm.stage_name AS current_stage_name
     FROM orders o
     LEFT JOIN stages_master sm ON sm.id = o.current_stage_id
     WHERE o.client_id = :client_id
     ORDER BY o.created_at DESC`,
    { client_id: clientId }
  );
}

/** Toggled from the Annexure A management screen for an order created before this flag existed on the New Order form. */
async function setIncludeAnnexureA(orderId, include) {
  await db.execute('UPDATE orders SET include_annexure_a = :flag WHERE id = :id', { flag: include ? 1 : 0, id: orderId });
}

module.exports = {
  all, allArchived, archive, unarchive, find, nextSequenceForClient, create, updateDetails, markSample, markTest, setCurrentStage, setPiDates,
  setProductionStatus, setBuyersPoRef, setEstShipmentDate, markComplete, markLost, applyAmendmentOverride, forClient,
  setIncludeAnnexureA, setDisputeButtonVisible,
};
