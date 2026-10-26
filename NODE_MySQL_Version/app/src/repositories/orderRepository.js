'use strict';

const crypto = require('crypto');
const db = require('../config/db');
const orderAssigneeRepository = require('./orderAssigneeRepository');
const orderComplianceTaskRepository = require('./orderComplianceTaskRepository');
const orderExportBenefitSchemeRepository = require('./orderExportBenefitSchemeRepository');
const referenceNumberService = require('../services/referenceNumberService');
const encryptionService = require('../services/encryptionService');
const { formatForDb } = require('../helpers/dbDateTime');

/**
 * Also carries incoterm_code, current_stage_number, currency_code, and
 * total_fob_value — the card-grid list view (see orders/index.njk) needs
 * all of these for its incoterm tag, 9-segment stage bar, and displayed
 * amount, none of which a plain o.* + one join used to expose.
 */
// 90-item backlog "standalone order": incoterm_code/currency_code/
// company_legal_name all come straight off o.* now — no join to
// incoterms/currencies/clients needed, since they're snapshotted on the
// order itself.
async function all() {
  return db.query(
    `SELECT o.*, sm.stage_name AS current_stage_name,
            sm.stage_number AS current_stage_number,
            (SELECT COALESCE(SUM(op.fob_value), 0) FROM order_products op WHERE op.order_id = o.id) AS total_fob_value,
            EXISTS (
                SELECT 1 FROM order_payment_status ops
                WHERE ops.order_id = o.id AND ops.balance_due_date IS NOT NULL
                  AND ops.balance_cleared_at IS NULL AND ops.balance_due_date < CURDATE()
            ) AS is_overdue
     FROM orders o
     LEFT JOIN stages_master sm ON sm.id = o.current_stage_id
     WHERE o.is_archived = 0
     ORDER BY o.created_at DESC, o.id DESC`
  );
}

/**
 * Scoped order list for the Orders list / Kanban board. all() above stays
 * unscoped (and unchanged — existing tests call it directly expecting the
 * full list) for any other caller that genuinely wants every order
 * regardless of assignment; this is the one the controller's
 * index()/indexKanban() actually use. seesAll is the caller's own
 * view_all_orders permission check — this repository never consults
 * permissionService itself.
 */
async function allVisibleTo(userId, seesAll) {
  const scopeClause = seesAll ? '' : 'AND EXISTS (SELECT 1 FROM order_assignees oa WHERE oa.order_id = o.id AND oa.user_id = :user_id)';
  return db.query(
    `SELECT o.*, sm.stage_name AS current_stage_name,
            sm.stage_number AS current_stage_number,
            (SELECT GROUP_CONCAT(u2.name ORDER BY u2.name SEPARATOR ', ')
             FROM order_assignees oa2 JOIN users u2 ON u2.id = oa2.user_id
             WHERE oa2.order_id = o.id) AS assignee_names,
            (SELECT COALESCE(SUM(op.fob_value), 0) FROM order_products op WHERE op.order_id = o.id) AS total_fob_value,
            EXISTS (
                SELECT 1 FROM order_payment_status ops
                WHERE ops.order_id = o.id AND ops.balance_due_date IS NOT NULL
                  AND ops.balance_cleared_at IS NULL AND ops.balance_due_date < CURDATE()
            ) AS is_overdue
     FROM orders o
     LEFT JOIN stages_master sm ON sm.id = o.current_stage_id
     WHERE o.is_archived = 0 ${scopeClause}
     ORDER BY o.created_at DESC, o.id DESC`,
    seesAll ? {} : { user_id: userId }
  );
}

/** Archived orders only — visible via the separate view_archived_orders permission, never deleted, never dropped from any other listing/report. */
async function allArchived() {
  return db.query(
    `SELECT o.*, sm.stage_name AS current_stage_name,
            u.name AS archived_by_name
     FROM orders o
     LEFT JOIN stages_master sm ON sm.id = o.current_stage_id
     LEFT JOIN users u ON u.id = o.archived_by
     WHERE o.is_archived = 1
     ORDER BY o.archived_at DESC, o.id DESC`
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

/**
 * 90-item backlog "standalone order": incoterm_code, currency_code,
 * port_of_loading_name/_city, port_of_discharge_name/_city, preset_name,
 * advance_pct, advance_trigger_text, balance_pct, balance_trigger_option,
 * balance_days, balance_trigger_wording, and show_bl_instructions all
 * come straight off o.* now — they're snapshotted onto the order at
 * creation time (insertRow() below) and never re-read from incoterms/
 * currencies/ports/payment_presets again, so editing or retiring one of
 * those lookup rows later can never silently change what an existing
 * order displays or prints. advance_pct/balance_pct/balance_trigger_option/
 * balance_days are updated in place by an activated Amendment
 * (applyAmendmentOverride() below) rather than read via a separate
 * override column COALESCEd against the preset.
 *
 * 90-item backlog "standalone order" follow-on: company_legal_name,
 * billing/consignee/notify-party details all come straight off o.* now
 * too, for the same reason — see resolveClientSnapshot()'s own docblock.
 * client_unique_number/agreement_footer_text/agreement_expiry_date/
 * agreement_force_expired are deliberately still live-joined — see that
 * docblock for why those four stay live.
 */
async function find(id) {
  return db.queryOne(
    `SELECT o.*, c.client_unique_number, c.agreement_footer_text,
            c.agreement_expiry_date, c.agreement_force_expired,
            sm.stage_name AS current_stage_name, sm.stage_slug AS current_stage_slug,
            sm.stage_number AS current_stage_number,
            (SELECT GROUP_CONCAT(u2.name ORDER BY u2.name SEPARATOR ', ')
             FROM order_assignees oa2 JOIN users u2 ON u2.id = oa2.user_id
             WHERE oa2.order_id = o.id) AS assignee_names,
            (o.active_amendment_id IS NOT NULL) AS has_active_amendment
     FROM orders o
     JOIN clients c ON c.id = o.client_id
     LEFT JOIN stages_master sm ON sm.id = o.current_stage_id
     WHERE o.id = :id`,
    { id }
  );
}

/** CA Phase 8 (export benefits) — lets staff link a claim by typing the order reference rather than picking from a long dropdown of every order. */
async function findIdByReference(orderReference) {
  const row = await db.queryOne('SELECT id FROM orders WHERE order_reference = :ref', { ref: orderReference });
  return row ? row.id : null;
}

/**
 * Point 2 follow-up (2026-09-30) — the one switch controlling whether
 * this order's linked government export benefits / expenses can ever be
 * assembled into a printable (internal-only) document at all. Defaults
 * to 0 for every order; only flipped via caController.toggleInternalDoc(),
 * gated on the ca_internal_doc_manage permission.
 */
async function setCaInternalDocEnabled(orderId, enabled) {
  await db.execute('UPDATE orders SET ca_internal_doc_enabled = :enabled WHERE id = :id', {
    enabled: enabled ? 1 : 0,
    id: orderId,
  });
}

/**
 * QA-5 CONC-03: reading "next sequence number for this client" and
 * inserting the order used to be two separate, unlocked statements
 * (nextSequenceForClient() then create()) — two concurrent order-creation
 * requests for the same client (a double-submit, or two staff members
 * working the same client at once) could both read the same
 * MAX(sequence_no), both build the identical order_reference from it, and
 * the second INSERT would then 500 on order_reference's UNIQUE constraint.
 * referenceNumberService.nextOrderSequenceForClient() reserves the number
 * atomically (a single UPSERT on reference_sequences' unique scope_key
 * index — see its docblock for why that's used instead of a
 * `SELECT ... FOR UPDATE`, which deadlocks under real concurrent load),
 * so by the time buildFields(sequenceNo) runs, no other caller can ever
 * have been handed the same number.
 *
 * QA-5 ORD-05: `executor` lets the order INSERT itself join a caller's
 * transaction (see ordersController.store()) so it rolls back together
 * with the order_stages/order_payment_status/order_products rows on any
 * failure. The sequence reservation above stays on the plain pool
 * (already self-contained/atomic per its own docblock) — a failed order
 * creation can leave a small gap in sequence numbers, same as an
 * auto-increment gap on any other rolled-back insert, which is harmless.
 *
 * @param {number} clientId
 * @param {(sequenceNo: number) => object} buildFields
 * @param {number} createdBy
 * @param {object} [executor] db-shaped executor (query/queryOne/execute); defaults to the plain pool
 * @returns {Promise<{orderId: number, sequenceNo: number, orderReference: string}>}
 */
async function createWithNextSequence(clientId, buildFields, createdBy, executor = db) {
  const sequenceNo = await referenceNumberService.nextOrderSequenceForClient(clientId);
  const fields = { ...buildFields(sequenceNo), client_id: clientId, sequence_no: sequenceNo };
  const orderId = await insertOrderRow(executor, fields, createdBy);
  return { orderId, sequenceNo, orderReference: fields.order_reference };
}

/**
 * 90-item backlog "standalone order": resolves incoterm_id/currency_id/
 * port_of_loading_id/port_of_discharge_id/payment_preset_id to their
 * CURRENT values exactly once, here, and snapshots them onto the new
 * order row. This is the only place a new order's terms are ever read
 * from the lookup tables — after this INSERT, the order never looks at
 * incoterms/currencies/ports/payment_presets again for its own
 * display/document content. Since orderDuplicationService.js passes
 * the source order's *_id columns (not its old snapshot) into this same
 * path, duplicating an order naturally re-resolves today's current
 * values rather than freezing forward whatever the source order's own
 * snapshot happened to say — a duplicate is a new commercial decision
 * made today.
 */
async function resolveSnapshot(executor, data) {
  const preset = await executor.queryOne(
    `SELECT preset_name, advance_pct, advance_trigger_text, payment_method, balance_pct, balance_trigger_option,
            balance_days, balance_trigger_wording, show_bl_instructions
     FROM payment_presets WHERE id = :id`,
    { id: data.payment_preset_id }
  );

  return {
    ...(await resolveLogisticsSnapshot(executor, data.incoterm_id, data.currency_id, data.port_of_loading_id, data.port_of_discharge_id)),
    preset_name: (preset && preset.preset_name) ?? null,
    advance_pct: (preset && preset.advance_pct) ?? null,
    advance_trigger_text: (preset && preset.advance_trigger_text) ?? null,
    payment_method: (preset && preset.payment_method) ?? 'CASH_ADVANCE',
    balance_pct: (preset && preset.balance_pct) ?? null,
    balance_trigger_option: (preset && preset.balance_trigger_option) ?? null,
    balance_days: (preset && preset.balance_days) ?? null,
    balance_trigger_wording: (preset && preset.balance_trigger_wording) ?? null,
    show_bl_instructions: preset ? preset.show_bl_instructions : 1,
    ...(await resolveClientSnapshot(executor, data.client_id)),
  };
}

/**
 * 90-item backlog "standalone order" follow-on: factored out of
 * resolveSnapshot() so ordersController.js's own in-progress Incoterm/
 * Currency/Port edit ("Edit Details") can re-resolve just these four
 * fields without touching the payment-preset or client snapshot —
 * editing the logistics side must never silently revert an activated
 * Amendment's override or a PI-intake-specific correction on this order
 * back to its preset/client defaults.
 */
async function resolveLogisticsSnapshot(executor, incotermId, currencyId, portOfLoadingId, portOfDischargeId) {
  const incoterm = await executor.queryOne('SELECT code FROM incoterms WHERE id = :id', { id: incotermId });
  const currency = await executor.queryOne('SELECT code FROM currencies WHERE id = :id', { id: currencyId });

  let portOfLoadingName = null;
  let portOfLoadingCity = null;
  if (portOfLoadingId) {
    const port = await executor.queryOne('SELECT name, city FROM ports WHERE id = :id', { id: portOfLoadingId });
    portOfLoadingName = port ? port.name : null;
    portOfLoadingCity = port ? port.city : null;
  }

  let portOfDischargeName = null;
  let portOfDischargeCity = null;
  if (portOfDischargeId) {
    const port = await executor.queryOne('SELECT name, city FROM ports WHERE id = :id', { id: portOfDischargeId });
    portOfDischargeName = port ? port.name : null;
    portOfDischargeCity = port ? port.city : null;
  }

  return {
    incoterm_code: (incoterm && incoterm.code) || null,
    currency_code: (currency && currency.code) || null,
    port_of_loading_name: portOfLoadingName,
    port_of_loading_city: portOfLoadingCity,
    port_of_discharge_name: portOfDischargeName,
    port_of_discharge_city: portOfDischargeCity,
  };
}

/**
 * 90-item backlog "standalone order" follow-on: resolves the buyer/
 * billing/consignee/notify-party details from `clients` ONCE, here. Also
 * used by applyClientSnapshot() below, since a PI-intake acceptance is
 * really "re-run this same resolution, for this order only, against the
 * client row that was just corrected."
 */
async function resolveClientSnapshot(executor, clientId) {
  const client = (await executor.queryOne(
    `SELECT company_legal_name, billing_address, billing_address_line1, billing_address_line2,
            billing_city, billing_postcode, billing_country, vat_eori_tax_no, contact_person,
            email, phone, country_of_destination,
            consignee_same_as_buyer, consignee_name, consignee_address, consignee_address_line1,
            consignee_address_line2, consignee_city, consignee_postcode, consignee_country,
            consignee_vat_eori_tax_no, consignee_contact_person, consignee_phone, consignee_email,
            notify_party_same_as_consignee, notify_party, notify_party_address_line1,
            notify_party_address_line2, notify_party_city, notify_party_postcode, notify_party_country,
            notify_party_contact_person, notify_party_phone, notify_party_email
     FROM clients WHERE id = :id`,
    { id: clientId }
  )) || {};

  return {
    company_legal_name: client.company_legal_name ?? null,
    billing_address: client.billing_address ?? null,
    billing_address_line1: client.billing_address_line1 ?? null,
    billing_address_line2: client.billing_address_line2 ?? null,
    billing_city: client.billing_city ?? null,
    billing_postcode: client.billing_postcode ?? null,
    billing_country: client.billing_country ?? null,
    vat_eori_tax_no: client.vat_eori_tax_no ?? null,
    contact_person: client.contact_person ?? null,
    client_email: client.email ?? null,
    client_phone: client.phone ?? null,
    country_of_destination: client.country_of_destination ?? null,
    consignee_same_as_buyer: client.consignee_same_as_buyer ?? 1,
    consignee_name: client.consignee_name ?? null,
    consignee_address: client.consignee_address ?? null,
    consignee_address_line1: client.consignee_address_line1 ?? null,
    consignee_address_line2: client.consignee_address_line2 ?? null,
    consignee_city: client.consignee_city ?? null,
    consignee_postcode: client.consignee_postcode ?? null,
    consignee_country: client.consignee_country ?? null,
    consignee_vat_eori_tax_no: client.consignee_vat_eori_tax_no ?? null,
    consignee_contact_person: client.consignee_contact_person ?? null,
    consignee_phone: client.consignee_phone ?? null,
    consignee_email: client.consignee_email ?? null,
    notify_party_same_as_consignee: client.notify_party_same_as_consignee ?? 1,
    notify_party: client.notify_party ?? null,
    notify_party_address_line1: client.notify_party_address_line1 ?? null,
    notify_party_address_line2: client.notify_party_address_line2 ?? null,
    notify_party_city: client.notify_party_city ?? null,
    notify_party_postcode: client.notify_party_postcode ?? null,
    notify_party_country: client.notify_party_country ?? null,
    notify_party_contact_person: client.notify_party_contact_person ?? null,
    notify_party_phone: client.notify_party_phone ?? null,
    notify_party_email: client.notify_party_email ?? null,
  };
}

/**
 * 90-item backlog "standalone order" follow-on: called by
 * piIntakeReviewController.accept() right after it corrects the
 * `clients` master row — applies that SAME correction onto this one
 * order's own snapshot columns, since the buyer's confirmation is this
 * order's own current truth. Every other existing order for this client
 * is untouched.
 */
async function applyClientSnapshot(orderId, clientId, executor = db) {
  const snapshot = await resolveClientSnapshot(executor, clientId);
  await executor.execute(
    `UPDATE orders SET
        company_legal_name = :company_legal_name, billing_address = :billing_address,
        billing_address_line1 = :billing_address_line1, billing_address_line2 = :billing_address_line2,
        billing_city = :billing_city, billing_postcode = :billing_postcode, billing_country = :billing_country,
        vat_eori_tax_no = :vat_eori_tax_no, contact_person = :contact_person,
        client_email = :client_email, client_phone = :client_phone,
        country_of_destination = :country_of_destination,
        consignee_same_as_buyer = :consignee_same_as_buyer, consignee_name = :consignee_name,
        consignee_address = :consignee_address, consignee_address_line1 = :consignee_address_line1,
        consignee_address_line2 = :consignee_address_line2, consignee_city = :consignee_city,
        consignee_postcode = :consignee_postcode, consignee_country = :consignee_country,
        consignee_vat_eori_tax_no = :consignee_vat_eori_tax_no,
        consignee_contact_person = :consignee_contact_person, consignee_phone = :consignee_phone,
        consignee_email = :consignee_email,
        notify_party_same_as_consignee = :notify_party_same_as_consignee, notify_party = :notify_party,
        notify_party_address_line1 = :notify_party_address_line1,
        notify_party_address_line2 = :notify_party_address_line2, notify_party_city = :notify_party_city,
        notify_party_postcode = :notify_party_postcode, notify_party_country = :notify_party_country,
        notify_party_contact_person = :notify_party_contact_person, notify_party_phone = :notify_party_phone,
        notify_party_email = :notify_party_email
     WHERE id = :id`,
    { ...snapshot, id: orderId }
  );
}

async function insertOrderRow(executor, data, createdBy) {
  const snapshot = await resolveSnapshot(executor, data);
  const result = await executor.execute(
    `INSERT INTO orders
        (order_reference, client_id, sequence_no, buyer_inquiry_ref, payment_preset_id, incoterm_id,
         port_of_loading_id, port_of_discharge_id, port_of_discharge_text, currency_id, coo_type,
         include_annexure_a, special_requirements, container_type, estimated_total_cbm,
         estimated_gross_weight_kg, estimated_net_weight_kg, estimated_package_count,
         estimated_package_type, est_lead_time_text, lead_time_min_days, lead_time_max_days,
         sailing_lead_time_min_days, sailing_lead_time_max_days,
         indicative_freight_amount,
         indicative_insurance_amount, buyers_po_ref, quotation_date, quotation_valid_until,
         status, created_by, duplicated_from_order_id,
         incoterm_code, currency_code, port_of_loading_name, port_of_loading_city,
         port_of_discharge_name, port_of_discharge_city, preset_name, advance_pct,
         advance_trigger_text, payment_method, balance_pct, balance_trigger_option, balance_days,
         balance_trigger_wording, show_bl_instructions,
         company_legal_name, billing_address, billing_address_line1, billing_address_line2,
         billing_city, billing_postcode, billing_country, vat_eori_tax_no, contact_person,
         client_email, client_phone, country_of_destination,
         consignee_same_as_buyer, consignee_name, consignee_address, consignee_address_line1,
         consignee_address_line2, consignee_city, consignee_postcode, consignee_country,
         consignee_vat_eori_tax_no, consignee_contact_person, consignee_phone, consignee_email,
         notify_party_same_as_consignee, notify_party, notify_party_address_line1,
         notify_party_address_line2, notify_party_city, notify_party_postcode, notify_party_country,
         notify_party_contact_person, notify_party_phone, notify_party_email)
     VALUES
        (:order_reference, :client_id, :sequence_no, :buyer_inquiry_ref, :payment_preset_id, :incoterm_id,
         :port_of_loading_id, :port_of_discharge_id, :port_of_discharge_text, :currency_id, :coo_type,
         :include_annexure_a, :special_requirements, :container_type, :estimated_total_cbm,
         :estimated_gross_weight_kg, :estimated_net_weight_kg, :estimated_package_count,
         :estimated_package_type, :est_lead_time_text, :lead_time_min_days, :lead_time_max_days,
         :sailing_lead_time_min_days, :sailing_lead_time_max_days,
         :indicative_freight_amount,
         :indicative_insurance_amount, :buyers_po_ref, :quotation_date, :quotation_valid_until,
         'active', :created_by, :duplicated_from_order_id,
         :incoterm_code, :currency_code, :port_of_loading_name, :port_of_loading_city,
         :port_of_discharge_name, :port_of_discharge_city, :preset_name, :advance_pct,
         :advance_trigger_text, :payment_method, :balance_pct, :balance_trigger_option, :balance_days,
         :balance_trigger_wording, :show_bl_instructions,
         :company_legal_name, :billing_address, :billing_address_line1, :billing_address_line2,
         :billing_city, :billing_postcode, :billing_country, :vat_eori_tax_no, :contact_person,
         :client_email, :client_phone, :country_of_destination,
         :consignee_same_as_buyer, :consignee_name, :consignee_address, :consignee_address_line1,
         :consignee_address_line2, :consignee_city, :consignee_postcode, :consignee_country,
         :consignee_vat_eori_tax_no, :consignee_contact_person, :consignee_phone, :consignee_email,
         :notify_party_same_as_consignee, :notify_party, :notify_party_address_line1,
         :notify_party_address_line2, :notify_party_city, :notify_party_postcode, :notify_party_country,
         :notify_party_contact_person, :notify_party_phone, :notify_party_email)`,
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
      coo_type: data.coo_type ?? 'To Be Confirmed',
      include_annexure_a: data.include_annexure_a ? 1 : 0,
      special_requirements: data.special_requirements ?? null,
      container_type: data.container_type ?? null,
      estimated_total_cbm: data.estimated_total_cbm || null,
      estimated_gross_weight_kg: data.estimated_gross_weight_kg || null,
      estimated_net_weight_kg: data.estimated_net_weight_kg || null,
      estimated_package_count: data.estimated_package_count ?? 'TBD at packing',
      estimated_package_type: data.estimated_package_type ?? 'Wooden Crates',
      est_lead_time_text: data.est_lead_time_text ?? null,
      lead_time_min_days: data.lead_time_min_days ?? null,
      lead_time_max_days: data.lead_time_max_days ?? null,
      sailing_lead_time_min_days: data.sailing_lead_time_min_days ?? null,
      sailing_lead_time_max_days: data.sailing_lead_time_max_days ?? null,
      indicative_freight_amount: data.indicative_freight_amount || null,
      indicative_insurance_amount: data.indicative_insurance_amount || null,
      buyers_po_ref: data.buyers_po_ref ?? 'NIL',
      quotation_date: data.quotation_date,
      quotation_valid_until: data.quotation_valid_until,
      created_by: createdBy,
      duplicated_from_order_id: data.duplicated_from_order_id ?? null,
      incoterm_code: snapshot.incoterm_code,
      currency_code: snapshot.currency_code,
      port_of_loading_name: snapshot.port_of_loading_name,
      port_of_loading_city: snapshot.port_of_loading_city,
      port_of_discharge_name: snapshot.port_of_discharge_name,
      port_of_discharge_city: snapshot.port_of_discharge_city,
      preset_name: snapshot.preset_name,
      advance_pct: snapshot.advance_pct,
      advance_trigger_text: snapshot.advance_trigger_text,
      payment_method: snapshot.payment_method,
      balance_pct: snapshot.balance_pct,
      balance_trigger_option: snapshot.balance_trigger_option,
      balance_days: snapshot.balance_days,
      balance_trigger_wording: snapshot.balance_trigger_wording,
      show_bl_instructions: snapshot.show_bl_instructions,
      company_legal_name: snapshot.company_legal_name,
      billing_address: snapshot.billing_address,
      billing_address_line1: snapshot.billing_address_line1,
      billing_address_line2: snapshot.billing_address_line2,
      billing_city: snapshot.billing_city,
      billing_postcode: snapshot.billing_postcode,
      billing_country: snapshot.billing_country,
      vat_eori_tax_no: snapshot.vat_eori_tax_no,
      contact_person: snapshot.contact_person,
      client_email: snapshot.client_email,
      client_phone: snapshot.client_phone,
      country_of_destination: snapshot.country_of_destination,
      consignee_same_as_buyer: snapshot.consignee_same_as_buyer,
      consignee_name: snapshot.consignee_name,
      consignee_address: snapshot.consignee_address,
      consignee_address_line1: snapshot.consignee_address_line1,
      consignee_address_line2: snapshot.consignee_address_line2,
      consignee_city: snapshot.consignee_city,
      consignee_postcode: snapshot.consignee_postcode,
      consignee_country: snapshot.consignee_country,
      consignee_vat_eori_tax_no: snapshot.consignee_vat_eori_tax_no,
      consignee_contact_person: snapshot.consignee_contact_person,
      consignee_phone: snapshot.consignee_phone,
      consignee_email: snapshot.consignee_email,
      notify_party_same_as_consignee: snapshot.notify_party_same_as_consignee,
      notify_party: snapshot.notify_party,
      notify_party_address_line1: snapshot.notify_party_address_line1,
      notify_party_address_line2: snapshot.notify_party_address_line2,
      notify_party_city: snapshot.notify_party_city,
      notify_party_postcode: snapshot.notify_party_postcode,
      notify_party_country: snapshot.notify_party_country,
      notify_party_contact_person: snapshot.notify_party_contact_person,
      notify_party_phone: snapshot.notify_party_phone,
      notify_party_email: snapshot.notify_party_email,
    }
  );
  const orderId = result.insertId;

  // Auto-assigned to whoever creates the order — only a holder of
  // assign_order_owner (Admin/MD/ED) can add/remove assignees afterward,
  // via orderAssignmentService. Visibility-only; never consulted by the
  // stage-gate/permission system. Joins the caller's transaction (same
  // executor as the order INSERT above, QA-5 ORD-05) so this row rolls
  // back together with the order on any failure.
  await orderAssigneeRepository.add(orderId, createdBy, createdBy, executor);

  // Every active compliance task type gets a real 'not_started' row up
  // front — a report run directly against order_compliance_tasks must
  // never see "no row" as a stand-in for "not applicable."
  await orderComplianceTaskRepository.initializeForOrder(orderId, executor);

  // Same materialization fix for export-benefit scheme eligibility —
  // see orderExportBenefitSchemeRepository.js's own docblock.
  await orderExportBenefitSchemeRepository.initializeForOrder(orderId, executor);

  return orderId;
}

/** Repeat orders created from this one (staff "Duplicate Order" or an approved client reorder), newest first. */
async function findOrdersDuplicatedFrom(sourceOrderId) {
  return db.query(
    'SELECT id, order_reference, created_at FROM orders WHERE duplicated_from_order_id = :id ORDER BY created_at DESC',
    { id: sourceOrderId }
  );
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
/**
 * 90-item backlog "standalone order" follow-on: a real regression found
 * after the original snapshot fix — this is called from ordersController.js's
 * in-progress Incoterm/Currency/Port edit ("Edit Details"), but only ever
 * wrote the *_id FK columns. Once find()/every document stopped
 * live-joining incoterms/currencies/ports and started reading
 * incoterm_code/currency_code/port_of_loading_name/_city/port_of_discharge_name/_city
 * instead, an edit here silently stopped being reflected anywhere — the
 * FK changed, the snapshot didn't. Now re-resolves those four snapshot
 * fields too, via the same resolveLogisticsSnapshot() insertOrderRow()
 * uses — deliberately NOT the full resolveSnapshot(), so this never
 * re-reads payment_presets/clients and overwrites an activated
 * Amendment's override or a PI-intake-specific correction on this order
 * with their current defaults.
 */
async function updateDetails(orderId, data) {
  const snapshot = await resolveLogisticsSnapshot(db, data.incoterm_id, data.currency_id, data.port_of_loading_id, data.port_of_discharge_id);

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
        lead_time_min_days = :lead_time_min_days,
        lead_time_max_days = :lead_time_max_days,
        sailing_lead_time_min_days = :sailing_lead_time_min_days,
        sailing_lead_time_max_days = :sailing_lead_time_max_days,
        estimated_total_cbm = :estimated_total_cbm,
        estimated_gross_weight_kg = :estimated_gross_weight_kg,
        estimated_net_weight_kg = :estimated_net_weight_kg,
        estimated_package_count = :estimated_package_count,
        estimated_package_type = :estimated_package_type,
        indicative_freight_amount = :indicative_freight_amount,
        indicative_insurance_amount = :indicative_insurance_amount,
        incoterm_code = :incoterm_code,
        currency_code = :currency_code,
        port_of_loading_name = :port_of_loading_name,
        port_of_loading_city = :port_of_loading_city,
        port_of_discharge_name = :port_of_discharge_name,
        port_of_discharge_city = :port_of_discharge_city
     WHERE id = :id`,
    {
      incoterm_id: data.incoterm_id,
      currency_id: data.currency_id,
      port_of_loading_id: data.port_of_loading_id ?? null,
      port_of_discharge_id: data.port_of_discharge_id ?? null,
      port_of_discharge_text: data.port_of_discharge_text ?? null,
      coo_type: data.coo_type ?? 'To Be Confirmed',
      container_type: data.container_type ?? null,
      buyers_po_ref: data.buyers_po_ref || 'NIL',
      special_requirements: data.special_requirements ?? null,
      est_lead_time_text: data.est_lead_time_text ?? null,
      lead_time_min_days: data.lead_time_min_days ?? null,
      lead_time_max_days: data.lead_time_max_days ?? null,
      sailing_lead_time_min_days: data.sailing_lead_time_min_days ?? null,
      sailing_lead_time_max_days: data.sailing_lead_time_max_days ?? null,
      estimated_total_cbm: data.estimated_total_cbm || null,
      estimated_gross_weight_kg: data.estimated_gross_weight_kg || null,
      estimated_net_weight_kg: data.estimated_net_weight_kg || null,
      estimated_package_count: data.estimated_package_count || null,
      estimated_package_type: data.estimated_package_type || null,
      indicative_freight_amount: data.indicative_freight_amount || null,
      indicative_insurance_amount: data.indicative_insurance_amount || null,
      incoterm_code: snapshot.incoterm_code,
      currency_code: snapshot.currency_code,
      port_of_loading_name: snapshot.port_of_loading_name,
      port_of_loading_city: snapshot.port_of_loading_city,
      port_of_discharge_name: snapshot.port_of_discharge_name,
      port_of_discharge_city: snapshot.port_of_discharge_city,
      id: orderId,
    }
  );
}

async function markSample(id) {
  await db.execute('UPDATE orders SET is_sample_data = 1 WHERE id = :id', { id });
}

/** Test Mode (docs/schema.sql Section V) — mirrors markSample()'s pattern. */
// QA-5 ORD-05: optional executor lets this join the caller's transaction at
// order creation (see ordersController.store()).
async function markTest(id, executor = db) {
  await executor.execute('UPDATE orders SET is_test_data = 1 WHERE id = :id', { id });
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

/**
 * docs/schema.sql Section BT — est_shipment_date_text stays free text
 * for documents (e.g. "Week of 15 October 2026"); the optional
 * structured date is what the 7-day pre-shipment-inspection reminder
 * actually computes against.
 */
async function setEstShipmentDate(orderId, text, date = null) {
  await db.execute(
    'UPDATE orders SET est_shipment_date_text = :text, est_shipment_date = :date WHERE id = :id',
    { text, date, id: orderId }
  );
}

/** docs/schema.sql Section AF — staff-controlled, per order, default off. */
async function setDisputeButtonVisible(orderId, visible) {
  await db.execute(
    'UPDATE orders SET dispute_button_visible_to_client = :visible WHERE id = :id',
    { visible: visible ? 1 : 0, id: orderId }
  );
}

/**
 * docs/schema.sql Section BU — Stage 9 closure gate, phase 1: full
 * document set couriered to buyer. The client now sees this order as
 * done, but it stays UNLOCKED internally (status='shipped_docs_sent',
 * is_locked unchanged) — export process steps 11b-11d (apply for
 * government export benefits, record them once received) still need to
 * happen before the real final lock (markComplete() below).
 * External QA report L-25's Buyer Action Link cleanup happens here too,
 * same as before — the buyer has nothing further to action once
 * documents are sent, whichever closure phase comes next.
 */
async function markShippedDocsSent(orderId) {
  await db.execute(
    `UPDATE orders
        SET status = 'shipped_docs_sent',
            buyer_action_token_hash = NULL, buyer_action_token_plain = NULL, buyer_action_token_expires_at = NULL
      WHERE id = :id`,
    { id: orderId }
  );
}

/**
 * docs/schema.sql Section BU — Stage 9 closure gate, phase 2 (export
 * process step 11e): the real final lock, now reached from
 * 'shipped_docs_sent' once there's nothing left to do (government
 * export benefits received, or never applicable) rather than directly
 * from couriering the documents — see markShippedDocsSent() above.
 * Business Rule #17. External QA report L-25: clear any live Buyer
 * Action Link on close too — see markLost()'s matching comment.
 */
async function markComplete(orderId) {
  await db.execute(
    `UPDATE orders
        SET status = 'complete', is_locked = 1,
            buyer_action_token_hash = NULL, buyer_action_token_plain = NULL, buyer_action_token_expires_at = NULL
      WHERE id = :id`,
    { id: orderId }
  );
}

/**
 * Added 2026-09-19 — see orders.lost_reason/lost_at/lost_by in schema.sql.
 * Mirrors markComplete()'s locking behavior.
 * External QA report L-25: the Buyer Action Link (OC acknowledgment, Buyer
 * PO upload, advance self-report) kept working after the order was marked
 * lost, since findByValidBuyerActionToken() never checked status. Clear the
 * token here too, belt-and-braces with that lookup's own status check.
 */
async function markLost(orderId, reason, userId) {
  await db.execute(
    `UPDATE orders
        SET status = 'lost', is_locked = 1, lost_reason = :reason, lost_at = NOW(), lost_by = :user_id,
            buyer_action_token_hash = NULL, buyer_action_token_plain = NULL, buyer_action_token_expires_at = NULL
      WHERE id = :id`,
    { reason, user_id: userId, id: orderId }
  );
}

/**
 * External QA report M-09 — the formal cancellation workflow's own
 * approval step (ordersController.approveCancellation()) calls this
 * once an order_cancellations request is approved. Same locking and
 * buyer-action-token revocation as markLost(); full reason/approval/
 * refund detail lives on the order_cancellations row itself, not
 * duplicated onto this table.
 */
async function cancel(orderId) {
  await db.execute(
    `UPDATE orders
        SET status = 'cancelled', is_locked = 1,
            buyer_action_token_hash = NULL, buyer_action_token_plain = NULL, buyer_action_token_expires_at = NULL
      WHERE id = :id`,
    { id: orderId }
  );
}

/**
 * 90-item backlog "standalone order": writes directly to the order's own
 * base columns (advance_pct, balance_pct, balance_trigger_option,
 * balance_days) rather than a separate *_override column — those are the
 * order's own snapshotted values now, not a preset-derived default with
 * a floor on top, so an activated amendment just updates them in place.
 *
 * balanceTriggerWording is caller-resolved (see amendmentService.js's
 * attachSignedCopyAndActivate()): the order's OWN snapshotted wording is
 * custom free text written for whatever trigger option the order
 * started with, so it stays valid only while the option is unchanged.
 * When this amendment actually flips the option, the caller passes null
 * here, falling back to documentDataAssembler.js's
 * balanceTriggerSentence()'s generic built-in sentence for that option
 * rather than printing custom wording that was written for the OTHER
 * option.
 */
async function applyAmendmentOverride(orderId, advancePct, balancePct, balanceTriggerOption, balanceDays, balanceTriggerWording, amendmentId) {
  await db.execute(
    `UPDATE orders
     SET advance_pct = :advance_pct,
         balance_pct = :balance_pct,
         balance_trigger_option = :balance_trigger_option,
         balance_days = :balance_days,
         balance_trigger_wording = :balance_trigger_wording,
         active_amendment_id = :amendment_id
     WHERE id = :id`,
    {
      advance_pct: advancePct, balance_pct: balancePct, balance_trigger_option: balanceTriggerOption,
      balance_days: balanceDays, balance_trigger_wording: balanceTriggerWording, amendment_id: amendmentId, id: orderId,
    }
  );
}

/**
 * 90-item backlog Phase 6 Item B: activeOnly=true restricts to
 * in-progress orders (active/disputed), hiding finished ones
 * (complete/lost) — used for the client portal dashboard when this
 * client's order history is not visible to them.
 */
async function forClient(clientId, activeOnly = false) {
  const statusFilter = activeOnly ? " AND o.status IN ('active','disputed')" : '';
  return db.query(
    `SELECT o.*, sm.stage_name AS current_stage_name
     FROM orders o
     LEFT JOIN stages_master sm ON sm.id = o.current_stage_id
     WHERE o.client_id = :client_id${statusFilter}
     ORDER BY o.created_at DESC, o.id DESC`,
    { client_id: clientId }
  );
}

/** Toggled from the Annexure A management screen for an order created before this flag existed on the New Order form. */
async function setIncludeAnnexureA(orderId, include) {
  await db.execute('UPDATE orders SET include_annexure_a = :flag WHERE id = :id', { flag: include ? 1 : 0, id: orderId });
}

/** SPEC (structured product table) / TERMS (free-form rich text) / BOTH — see docs/schema.sql Section AT. */
async function setAnnexureMode(orderId, mode) {
  await db.execute('UPDATE orders SET annexure_mode = :mode WHERE id = :id', { mode, id: orderId });
}

/**
 * 90-item backlog #75 (docs/schema.sql Section BG): generates (or
 * regenerates, overwriting any prior link) the public, no-login token for
 * this order's Buyer Action page (OC acknowledgment + Buyer PO upload) —
 * same hash/plain/expiry convention as piIntakeRepository.createLink(),
 * stored directly on the order row since this is a 1:1-with-the-order
 * fact, not a per-submission one.
 */
async function generateBuyerActionToken(orderId, ttlDays = 90) {
  const rawToken = crypto.randomBytes(32).toString('hex');
  await db.execute(
    'UPDATE orders SET buyer_action_token_hash = :hash, buyer_action_token_plain = :plain, buyer_action_token_expires_at = :expires WHERE id = :id',
    {
      hash: crypto.createHash('sha256').update(rawToken).digest('hex'),
      // QA-5 L-24: stored encrypted (encryptionService), never raw —
      // staff re-view/re-copy the live link from the order page at any
      // time before it expires, so this can't just be shown once and
      // discarded; see encryptionService.js's docblock.
      plain: encryptionService.encrypt(rawToken),
      // External QA report L-21/L-02: toISOString() is always UTC — see
      // dbDateTime.formatForDb()'s docblock. (This is the exact bug the
      // report reproduced: this expiry stored 06:20 instead of 11:50.)
      expires: formatForDb(new Date(Date.now() + ttlDays * 86400000)),
      id: orderId,
    }
  );
  return rawToken;
}

/**
 * Public /buyer-action/{token} route's sole authorization check — no
 * session involved. Deliberately minimal (just confirms the token
 * resolves to a real, unexpired order); buyerActionController re-loads
 * the full row via find() for everything the page/upload actually needs.
 *
 * External QA report L-25: this never checked the order's status, so a
 * link kept working (OC acknowledgment, Buyer PO upload, advance
 * self-report all stayed open) after the order was marked lost or closed.
 * 'active'/'disputed' is this codebase's established "still in progress"
 * status set (see forClient()'s activeOnly filter above) vs.
 * 'complete'/'lost' for a finished order. markLost()/markComplete() also
 * now clear the token outright; this status check is the authoritative
 * guard in case an older row still has a token left over from before that
 * existed.
 */
async function findByValidBuyerActionToken(rawToken) {
  const hash = crypto.createHash('sha256').update(rawToken).digest('hex');
  return db.queryOne(
    `SELECT * FROM orders
      WHERE buyer_action_token_hash = :hash AND buyer_action_token_expires_at > NOW()
        AND status IN ('active', 'disputed')`,
    { hash }
  );
}

/** Owner decision (2026-10-26): staff record that the client won't use the PI-stage form, unlocking Step 5c. */
async function markPiFormSkipped(orderId, userId, reason) {
  await db.execute(
    'UPDATE orders SET pi_form_skipped_at = NOW(), pi_form_skipped_by = :user_id, pi_form_skip_reason = :reason WHERE id = :id',
    { user_id: userId, reason, id: orderId }
  );
}

/** Owner request (2026-10-26, t4): Step 12 marked complete without financial figures — nothing is written as 0. */
async function markFinancialsSkipped(orderId, userId, reason) {
  await db.execute(
    'UPDATE orders SET financials_skipped_at = NOW(), financials_skipped_by = :user_id, financials_skip_reason = :reason WHERE id = :id',
    { user_id: userId, reason, id: orderId }
  );
}

async function clearFinancialsSkipped(orderId) {
  await db.execute(
    'UPDATE orders SET financials_skipped_at = NULL, financials_skipped_by = NULL, financials_skip_reason = NULL WHERE id = :id',
    { id: orderId }
  );
}

module.exports = {
  all, allVisibleTo, allArchived, archive, unarchive, find, findIdByReference, createWithNextSequence, updateDetails, markSample, markTest, setCurrentStage, setPiDates,
  setProductionStatus, setBuyersPoRef, setEstShipmentDate, markShippedDocsSent, markComplete, markLost, cancel, applyAmendmentOverride, forClient,
  setIncludeAnnexureA, setAnnexureMode, setDisputeButtonVisible, setCaInternalDocEnabled, findOrdersDuplicatedFrom,
  generateBuyerActionToken, findByValidBuyerActionToken, applyClientSnapshot, markPiFormSkipped,
  markFinancialsSkipped, clearFinancialsSkipped,
};
