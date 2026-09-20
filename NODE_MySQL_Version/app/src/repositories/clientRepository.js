'use strict';

const db = require('../config/db');

async function all() {
  return db.query('SELECT * FROM clients WHERE is_active = 1 ORDER BY company_legal_name');
}

async function find(id) {
  return db.queryOne('SELECT * FROM clients WHERE id = :id', { id });
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

async function markSample(id) {
  await db.execute('UPDATE clients SET is_sample_data = 1 WHERE id = :id', { id });
}

module.exports = { all, find, create, markSample };
