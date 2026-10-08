'use strict';

const crypto = require('crypto');
const db = require('../config/db');

// Port of App\Repositories\ClientIntakeRepository. Public quotation-stage
// intake submissions — see schema.sql Section O. A submission is never
// auto-converted into a client; every row is reviewed by staff first
// (clientIntakeReviewController).

async function create(data, ip) {
  const result = await db.execute(
    `INSERT INTO client_intake_submissions
        (company_legal_name, billing_address,
         billing_address_line1, billing_address_line2, billing_city, billing_postcode, billing_country,
         vat_eori_tax_no, contact_person, email, phone,
         country_of_destination, port_of_discharge_text, coo_type, incoterm_preference,
         container_type_text, buyer_own_reference, notes, submitted_ip,
         consignee_same_as_buyer, consignee_name, consignee_address_line1, consignee_address_line2,
         consignee_city, consignee_postcode, consignee_country, consignee_vat_eori_tax_no,
         consignee_contact_person, consignee_phone, consignee_email)
     VALUES
        (:company_legal_name, :billing_address,
         :billing_address_line1, :billing_address_line2, :billing_city, :billing_postcode, :billing_country,
         :vat_eori_tax_no, :contact_person, :email, :phone,
         :country_of_destination, :port_of_discharge_text, :coo_type, :incoterm_preference,
         :container_type_text, :buyer_own_reference, :notes, :ip,
         :consignee_same_as_buyer, :consignee_name, :consignee_address_line1, :consignee_address_line2,
         :consignee_city, :consignee_postcode, :consignee_country, :consignee_vat_eori_tax_no,
         :consignee_contact_person, :consignee_phone, :consignee_email)`,
    {
      company_legal_name: data.company_legal_name,
      billing_address: data.billing_address,
      billing_address_line1: data.billing_address_line1 || null,
      billing_address_line2: data.billing_address_line2 || null,
      billing_city: data.billing_city || null,
      billing_postcode: data.billing_postcode || null,
      billing_country: data.billing_country || null,
      vat_eori_tax_no: data.vat_eori_tax_no || null,
      contact_person: data.contact_person,
      email: data.email,
      phone: data.phone || null,
      country_of_destination: data.country_of_destination,
      port_of_discharge_text: data.port_of_discharge_text || null,
      coo_type: data.coo_type || null,
      incoterm_preference: data.incoterm_preference || null,
      container_type_text: data.container_type_text || null,
      buyer_own_reference: data.buyer_own_reference || null,
      notes: data.notes || null,
      ip,
      consignee_same_as_buyer: data.consignee_same_as_buyer ?? 1,
      consignee_name: data.consignee_name ?? null,
      consignee_address_line1: data.consignee_address_line1 ?? null,
      consignee_address_line2: data.consignee_address_line2 ?? null,
      consignee_city: data.consignee_city ?? null,
      consignee_postcode: data.consignee_postcode ?? null,
      consignee_country: data.consignee_country ?? null,
      consignee_vat_eori_tax_no: data.consignee_vat_eori_tax_no ?? null,
      consignee_contact_person: data.consignee_contact_person ?? null,
      consignee_phone: data.consignee_phone ?? null,
      consignee_email: data.consignee_email ?? null,
    }
  );
  return result.insertId;
}

async function find(id) {
  return db.queryOne('SELECT * FROM client_intake_submissions WHERE id = :id', { id });
}

async function setAccessToken(id, tokenHash, expiresAt) {
  await db.execute(
    'UPDATE client_intake_submissions SET access_token_hash = :hash, access_token_expires_at = :expires WHERE id = :id',
    { hash: tokenHash, expires: expiresAt, id }
  );
}

/**
 * Only while still status='pending' — once staff act on a submission
 * (converted/rejected), the client-side correction link stops working,
 * by design (see schema.sql Section AA).
 */
async function findValidByToken(rawToken) {
  const hash = crypto.createHash('sha256').update(rawToken).digest('hex');
  return db.queryOne(
    "SELECT * FROM client_intake_submissions WHERE access_token_hash = :hash AND access_token_expires_at > NOW() AND status = 'pending'",
    { hash }
  );
}

async function updateFromClient(id, data) {
  await db.execute(
    `UPDATE client_intake_submissions SET
        company_legal_name = :company_legal_name, billing_address = :billing_address,
        billing_address_line1 = :billing_address_line1, billing_address_line2 = :billing_address_line2,
        billing_city = :billing_city, billing_postcode = :billing_postcode, billing_country = :billing_country,
        vat_eori_tax_no = :vat_eori_tax_no, contact_person = :contact_person, email = :email,
        phone = :phone, country_of_destination = :country_of_destination,
        port_of_discharge_text = :port_of_discharge_text, coo_type = :coo_type,
        incoterm_preference = :incoterm_preference, container_type_text = :container_type_text,
        buyer_own_reference = :buyer_own_reference, notes = :notes,
        consignee_same_as_buyer = :consignee_same_as_buyer, consignee_name = :consignee_name,
        consignee_address_line1 = :consignee_address_line1, consignee_address_line2 = :consignee_address_line2,
        consignee_city = :consignee_city, consignee_postcode = :consignee_postcode,
        consignee_country = :consignee_country, consignee_vat_eori_tax_no = :consignee_vat_eori_tax_no,
        consignee_contact_person = :consignee_contact_person, consignee_phone = :consignee_phone,
        consignee_email = :consignee_email
     WHERE id = :id`,
    {
      company_legal_name: data.company_legal_name,
      billing_address: data.billing_address,
      billing_address_line1: data.billing_address_line1 || null,
      billing_address_line2: data.billing_address_line2 || null,
      billing_city: data.billing_city || null,
      billing_postcode: data.billing_postcode || null,
      billing_country: data.billing_country || null,
      vat_eori_tax_no: data.vat_eori_tax_no || null,
      contact_person: data.contact_person,
      email: data.email,
      phone: data.phone || null,
      country_of_destination: data.country_of_destination,
      port_of_discharge_text: data.port_of_discharge_text || null,
      coo_type: data.coo_type || null,
      incoterm_preference: data.incoterm_preference || null,
      container_type_text: data.container_type_text || null,
      buyer_own_reference: data.buyer_own_reference || null,
      notes: data.notes || null,
      id,
      consignee_same_as_buyer: data.consignee_same_as_buyer ?? 1,
      consignee_name: data.consignee_name ?? null,
      consignee_address_line1: data.consignee_address_line1 ?? null,
      consignee_address_line2: data.consignee_address_line2 ?? null,
      consignee_city: data.consignee_city ?? null,
      consignee_postcode: data.consignee_postcode ?? null,
      consignee_country: data.consignee_country ?? null,
      consignee_vat_eori_tax_no: data.consignee_vat_eori_tax_no ?? null,
      consignee_contact_person: data.consignee_contact_person ?? null,
      consignee_phone: data.consignee_phone ?? null,
      consignee_email: data.consignee_email ?? null,
    }
  );
}

async function pending() {
  return db.query("SELECT * FROM client_intake_submissions WHERE status = 'pending' ORDER BY submitted_at ASC");
}

async function recentResolved(limit = 30) {
  return db.query(
    `SELECT cis.*, u.name AS reviewed_by_name, c.client_unique_number
     FROM client_intake_submissions cis
     LEFT JOIN users u ON u.id = cis.reviewed_by
     LEFT JOIN clients c ON c.id = cis.converted_client_id
     WHERE cis.status != 'pending'
     ORDER BY cis.reviewed_at DESC LIMIT ${parseInt(limit, 10)}`
  );
}

async function markConverted(id, clientId, reviewedBy) {
  await db.execute(
    `UPDATE client_intake_submissions
     SET status = 'converted', converted_client_id = :client_id, reviewed_by = :reviewed_by, reviewed_at = NOW()
     WHERE id = :id`,
    { client_id: clientId, reviewed_by: reviewedBy, id }
  );
}

async function markRejected(id, reviewedBy, reason) {
  await db.execute(
    `UPDATE client_intake_submissions
     SET status = 'rejected', rejection_reason = :reason, reviewed_by = :reviewed_by, reviewed_at = NOW()
     WHERE id = :id`,
    { reason, reviewed_by: reviewedBy, id }
  );
}

module.exports = { create, find, setAccessToken, findValidByToken, updateFromClient, pending, recentResolved, markConverted, markRejected };
