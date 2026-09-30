'use strict';

const db = require('../config/db');

/**
 * CHA / Transportation partner directory (docs/schema.sql Section AQ) —
 * standalone master data, not yet linked to a specific order or cost
 * entry. service_type covers a partner providing CHA only, transportation
 * only, or both, since the same company sometimes does either or both.
 */
const SERVICE_TYPES = {
  cha: 'CHA',
  transportation: 'Transportation',
  both: 'CHA + Transportation',
};

function bindData(data) {
  return {
    partner_name: data.partner_name,
    service_type: data.service_type,
    address: data.address ?? null,
    city: data.city ?? null,
    state: data.state ?? null,
    phone: data.phone ?? null,
    whatsapp_number: data.whatsapp_number ?? null,
    email: data.email ?? null,
    contact_person_name: data.contact_person_name ?? null,
    contact_person_phone: data.contact_person_phone ?? null,
    contact_person_whatsapp: data.contact_person_whatsapp ?? null,
    gstin: data.gstin ?? null,
    pan: data.pan ?? null,
    notes: data.notes ?? null,
  };
}

/** @param {string|null} serviceType filters to that type alone or 'both' */
async function all(includeInactive = false, serviceType = null) {
  const where = [];
  const params = {};
  if (!includeInactive) {
    where.push('is_active = 1');
  }
  if (serviceType) {
    where.push("service_type IN (:service_type, 'both')");
    params.service_type = serviceType;
  }
  let sql = 'SELECT * FROM logistics_partners';
  if (where.length > 0) {
    sql += ` WHERE ${where.join(' AND ')}`;
  }
  sql += ' ORDER BY partner_name';
  return db.query(sql, params);
}

async function find(id) {
  return db.queryOne('SELECT * FROM logistics_partners WHERE id = :id', { id });
}

async function create(data, createdBy) {
  const result = await db.execute(
    `INSERT INTO logistics_partners
        (partner_name, service_type, address, city, state, phone, whatsapp_number, email,
         contact_person_name, contact_person_phone, contact_person_whatsapp, gstin, pan, notes, created_by)
     VALUES
        (:partner_name, :service_type, :address, :city, :state, :phone, :whatsapp_number, :email,
         :contact_person_name, :contact_person_phone, :contact_person_whatsapp, :gstin, :pan, :notes, :created_by)`,
    { ...bindData(data), created_by: createdBy }
  );
  return result.insertId;
}

async function update(id, data) {
  await db.execute(
    `UPDATE logistics_partners SET
        partner_name = :partner_name, service_type = :service_type, address = :address,
        city = :city, state = :state, phone = :phone, whatsapp_number = :whatsapp_number, email = :email,
        contact_person_name = :contact_person_name, contact_person_phone = :contact_person_phone,
        contact_person_whatsapp = :contact_person_whatsapp, gstin = :gstin, pan = :pan, notes = :notes
     WHERE id = :id`,
    { ...bindData(data), id }
  );
}

async function toggleActive(id) {
  await db.execute('UPDATE logistics_partners SET is_active = 1 - is_active WHERE id = :id', { id });
}

async function remove(id) {
  await db.execute('DELETE FROM logistics_partners WHERE id = :id', { id });
}

module.exports = { SERVICE_TYPES, all, find, create, update, toggleActive, remove };
