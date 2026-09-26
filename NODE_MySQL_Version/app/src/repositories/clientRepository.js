'use strict';

const db = require('../config/db');

/** Also carries order_count — the card-grid list view (clients/index.njk) shows it per client. */
async function all() {
  return db.query(
    `SELECT c.*, (SELECT COUNT(*) FROM orders o WHERE o.client_id = c.id AND o.is_archived = 0) AS order_count
     FROM clients c WHERE c.is_active = 1 ORDER BY c.company_legal_name`
  );
}

async function find(id) {
  return db.queryOne('SELECT * FROM clients WHERE id = :id', { id });
}

/** CA / Accounting module (Phase 3) — caches the Zoho Books contact_id created for this client on first sync. */
async function setZohoContactId(id, zohoContactId) {
  await db.execute('UPDATE clients SET zoho_contact_id = :zoho_contact_id WHERE id = :id', { zoho_contact_id: zohoContactId, id });
}

async function allInactive() {
  return db.query('SELECT * FROM clients WHERE is_active = 0 ORDER BY company_legal_name');
}

async function create(data, createdBy, clientUniqueNumber) {
  const result = await db.execute(
    `INSERT INTO clients
        (client_unique_number, company_legal_name, billing_address, consignee_name, consignee_address,
         vat_eori_tax_no, contact_person, email, phone, country_of_destination, coo_type, notify_party,
         created_by)
     VALUES
        (:client_unique_number, :company_legal_name, :billing_address, :consignee_name, :consignee_address,
         :vat_eori_tax_no, :contact_person, :email, :phone, :country_of_destination, :coo_type, :notify_party,
         :created_by)`,
    {
      client_unique_number: clientUniqueNumber,
      company_legal_name: data.company_legal_name,
      billing_address: data.billing_address,
      consignee_name: data.consignee_name || 'SAME',
      consignee_address: data.consignee_address || 'SAME',
      vat_eori_tax_no: data.vat_eori_tax_no ?? null,
      contact_person: data.contact_person ?? null,
      email: data.email ?? null,
      phone: data.phone ?? null,
      country_of_destination: data.country_of_destination ?? null,
      coo_type: data.coo_type ?? 'TBC',
      notify_party: data.notify_party ?? null,
      created_by: createdBy,
    }
  );
  return result.insertId;
}

async function update(id, data) {
  await db.execute(
    `UPDATE clients SET
        company_legal_name = :company_legal_name,
        billing_address = :billing_address,
        consignee_name = :consignee_name,
        consignee_address = :consignee_address,
        vat_eori_tax_no = :vat_eori_tax_no,
        contact_person = :contact_person,
        email = :email,
        phone = :phone,
        country_of_destination = :country_of_destination,
        coo_type = :coo_type,
        notify_party = :notify_party
     WHERE id = :id`,
    {
      company_legal_name: data.company_legal_name,
      billing_address: data.billing_address,
      consignee_name: data.consignee_name || null,
      consignee_address: data.consignee_address || null,
      vat_eori_tax_no: data.vat_eori_tax_no ?? null,
      contact_person: data.contact_person ?? null,
      email: data.email ?? null,
      phone: data.phone ?? null,
      country_of_destination: data.country_of_destination ?? null,
      coo_type: data.coo_type ?? null,
      notify_party: data.notify_party ?? null,
      id,
    }
  );
}

async function setActive(id, active) {
  await db.execute('UPDATE clients SET is_active = :active WHERE id = :id', { active: active ? 1 : 0, id });
}

async function markSample(id) {
  await db.execute('UPDATE clients SET is_sample_data = 1 WHERE id = :id', { id });
}

/** Test Mode (docs/schema.sql Section V) — mirrors markSample()'s pattern. */
async function markTest(id) {
  await db.execute('UPDATE clients SET is_test_data = 1 WHERE id = :id', { id });
}

/**
 * docs/schema.sql Section AC — fires from either of the two trigger
 * points (PI-details client consent, or the fallback advance-remittance
 * trigger), whichever happens first. Only ever fires once — idempotent
 * so both trigger points can safely call it without checking who got
 * there first.
 */
async function lockData(id, reason) {
  await db.execute(
    `UPDATE clients SET is_data_locked = 1, data_locked_at = NOW(), data_locked_reason = :reason
     WHERE id = :id AND is_data_locked = 0`,
    { id, reason }
  );
}

module.exports = { all, find, allInactive, create, update, setActive, markSample, markTest, lockData, setZohoContactId };
