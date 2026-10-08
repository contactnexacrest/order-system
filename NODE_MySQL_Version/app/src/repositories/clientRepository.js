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
  const consigneeSameAsBuyer = data.consignee_same_as_buyer ?? 1;
  const result = await db.execute(
    `INSERT INTO clients
        (client_unique_number, company_legal_name, billing_address,
         billing_address_line1, billing_address_line2, billing_city, billing_postcode,
         consignee_name, consignee_address, consignee_same_as_buyer,
         consignee_address_line1, consignee_address_line2, consignee_city, consignee_postcode,
         consignee_country, consignee_vat_eori_tax_no, consignee_contact_person, consignee_phone, consignee_email,
         vat_eori_tax_no, contact_person, email, phone, country_of_destination, coo_type,
         notify_party, notify_party_same_as_consignee,
         notify_party_address_line1, notify_party_address_line2, notify_party_city, notify_party_postcode,
         notify_party_country, notify_party_contact_person, notify_party_phone, notify_party_email,
         created_by)
     VALUES
        (:client_unique_number, :company_legal_name, :billing_address,
         :billing_address_line1, :billing_address_line2, :billing_city, :billing_postcode,
         :consignee_name, :consignee_address, :consignee_same_as_buyer,
         :consignee_address_line1, :consignee_address_line2, :consignee_city, :consignee_postcode,
         :consignee_country, :consignee_vat_eori_tax_no, :consignee_contact_person, :consignee_phone, :consignee_email,
         :vat_eori_tax_no, :contact_person, :email, :phone, :country_of_destination, :coo_type,
         :notify_party, :notify_party_same_as_consignee,
         :notify_party_address_line1, :notify_party_address_line2, :notify_party_city, :notify_party_postcode,
         :notify_party_country, :notify_party_contact_person, :notify_party_phone, :notify_party_email,
         :created_by)`,
    {
      client_unique_number: clientUniqueNumber,
      company_legal_name: data.company_legal_name,
      billing_address: data.billing_address,
      billing_address_line1: data.billing_address_line1 ?? null,
      billing_address_line2: data.billing_address_line2 ?? null,
      billing_city: data.billing_city ?? null,
      billing_postcode: data.billing_postcode ?? null,
      consignee_name: data.consignee_name || (parseInt(consigneeSameAsBuyer, 10) === 1 ? null : 'SAME'),
      consignee_address: data.consignee_address ?? null,
      consignee_same_as_buyer: parseInt(consigneeSameAsBuyer, 10),
      consignee_address_line1: data.consignee_address_line1 ?? null,
      consignee_address_line2: data.consignee_address_line2 ?? null,
      consignee_city: data.consignee_city ?? null,
      consignee_postcode: data.consignee_postcode ?? null,
      consignee_country: data.consignee_country ?? null,
      consignee_vat_eori_tax_no: data.consignee_vat_eori_tax_no ?? null,
      consignee_contact_person: data.consignee_contact_person ?? null,
      consignee_phone: data.consignee_phone ?? null,
      consignee_email: data.consignee_email ?? null,
      vat_eori_tax_no: data.vat_eori_tax_no ?? null,
      contact_person: data.contact_person ?? null,
      email: data.email ?? null,
      phone: data.phone ?? null,
      country_of_destination: data.country_of_destination ?? null,
      coo_type: data.coo_type ?? 'To Be Confirmed',
      notify_party: data.notify_party ?? null,
      notify_party_same_as_consignee: parseInt(data.notify_party_same_as_consignee ?? 1, 10),
      notify_party_address_line1: data.notify_party_address_line1 ?? null,
      notify_party_address_line2: data.notify_party_address_line2 ?? null,
      notify_party_city: data.notify_party_city ?? null,
      notify_party_postcode: data.notify_party_postcode ?? null,
      notify_party_country: data.notify_party_country ?? null,
      notify_party_contact_person: data.notify_party_contact_person ?? null,
      notify_party_phone: data.notify_party_phone ?? null,
      notify_party_email: data.notify_party_email ?? null,
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
        billing_address_line1 = :billing_address_line1,
        billing_address_line2 = :billing_address_line2,
        billing_city = :billing_city,
        billing_postcode = :billing_postcode,
        consignee_name = :consignee_name,
        consignee_address = :consignee_address,
        consignee_same_as_buyer = :consignee_same_as_buyer,
        consignee_address_line1 = :consignee_address_line1,
        consignee_address_line2 = :consignee_address_line2,
        consignee_city = :consignee_city,
        consignee_postcode = :consignee_postcode,
        consignee_country = :consignee_country,
        consignee_vat_eori_tax_no = :consignee_vat_eori_tax_no,
        consignee_contact_person = :consignee_contact_person,
        consignee_phone = :consignee_phone,
        consignee_email = :consignee_email,
        vat_eori_tax_no = :vat_eori_tax_no,
        contact_person = :contact_person,
        email = :email,
        phone = :phone,
        country_of_destination = :country_of_destination,
        coo_type = :coo_type,
        notify_party = :notify_party,
        notify_party_same_as_consignee = :notify_party_same_as_consignee,
        notify_party_address_line1 = :notify_party_address_line1,
        notify_party_address_line2 = :notify_party_address_line2,
        notify_party_city = :notify_party_city,
        notify_party_postcode = :notify_party_postcode,
        notify_party_country = :notify_party_country,
        notify_party_contact_person = :notify_party_contact_person,
        notify_party_phone = :notify_party_phone,
        notify_party_email = :notify_party_email
     WHERE id = :id`,
    {
      company_legal_name: data.company_legal_name,
      billing_address: data.billing_address,
      billing_address_line1: data.billing_address_line1 ?? null,
      billing_address_line2: data.billing_address_line2 ?? null,
      billing_city: data.billing_city ?? null,
      billing_postcode: data.billing_postcode ?? null,
      consignee_name: data.consignee_name || null,
      consignee_address: data.consignee_address ?? null,
      consignee_same_as_buyer: parseInt(data.consignee_same_as_buyer ?? 1, 10),
      consignee_address_line1: data.consignee_address_line1 ?? null,
      consignee_address_line2: data.consignee_address_line2 ?? null,
      consignee_city: data.consignee_city ?? null,
      consignee_postcode: data.consignee_postcode ?? null,
      consignee_country: data.consignee_country ?? null,
      consignee_vat_eori_tax_no: data.consignee_vat_eori_tax_no ?? null,
      consignee_contact_person: data.consignee_contact_person ?? null,
      consignee_phone: data.consignee_phone ?? null,
      consignee_email: data.consignee_email ?? null,
      vat_eori_tax_no: data.vat_eori_tax_no ?? null,
      contact_person: data.contact_person ?? null,
      email: data.email ?? null,
      phone: data.phone ?? null,
      country_of_destination: data.country_of_destination ?? null,
      coo_type: data.coo_type ?? null,
      notify_party: data.notify_party ?? null,
      notify_party_same_as_consignee: parseInt(data.notify_party_same_as_consignee ?? 1, 10),
      notify_party_address_line1: data.notify_party_address_line1 ?? null,
      notify_party_address_line2: data.notify_party_address_line2 ?? null,
      notify_party_city: data.notify_party_city ?? null,
      notify_party_postcode: data.notify_party_postcode ?? null,
      notify_party_country: data.notify_party_country ?? null,
      notify_party_contact_person: data.notify_party_contact_person ?? null,
      notify_party_phone: data.notify_party_phone ?? null,
      notify_party_email: data.notify_party_email ?? null,
      id,
    }
  );
}

// Batch 3 #12 — deliberately its own function/endpoint, never routed through
// update() above: a client-level agreement T&C footer is a staff-authored
// annotation of an externally-negotiated term, not a client-submitted
// identity detail, so it must stay editable even after is_data_locked.
async function updateAgreementFooterText(id, text) {
  await db.execute('UPDATE clients SET agreement_footer_text = :agreement_footer_text WHERE id = :id', {
    agreement_footer_text: text ?? null,
    id,
  });
}

async function setActive(id, active) {
  await db.execute('UPDATE clients SET is_active = :active WHERE id = :id', { active: active ? 1 : 0, id });
}

/** docs/schema.sql Section AV — per-client gate on staff impersonation, independent of the global company_settings switch. */
async function setAllowStaffImpersonation(id, allow) {
  await db.execute('UPDATE clients SET allow_staff_impersonation = :allow WHERE id = :id', { allow: allow ? 1 : 0, id });
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

module.exports = {
  all, find, allInactive, create, update, setActive, setAllowStaffImpersonation, markSample, markTest, lockData, setZohoContactId,
  updateAgreementFooterText,
};
