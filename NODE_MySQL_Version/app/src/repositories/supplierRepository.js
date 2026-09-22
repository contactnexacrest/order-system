'use strict';

const db = require('../config/db');

async function all() {
  return db.query('SELECT * FROM suppliers WHERE is_active = 1 ORDER BY supplier_legal_name');
}

async function find(id) {
  return db.queryOne('SELECT * FROM suppliers WHERE id = :id', { id });
}

async function create(data) {
  const result = await db.execute(
    `INSERT INTO suppliers (supplier_legal_name, address, gstin, pan, contact_person, phone, supplier_type)
     VALUES (:name, :address, :gstin, :pan, :contact_person, :phone, :supplier_type)`,
    {
      name: data.supplier_legal_name,
      address: data.address ?? null,
      gstin: data.gstin ?? null,
      pan: data.pan ?? null,
      contact_person: data.contact_person ?? null,
      phone: data.phone ?? null,
      supplier_type: data.supplier_type ?? null,
    }
  );
  return result.insertId;
}

/** Phase E follow-up — flags a supplier as Sample Data Playground content (see sampleDataService). */
async function markSample(id) {
  await db.execute('UPDATE suppliers SET is_sample_data = 1 WHERE id = :id', { id });
}

/** Test Mode (docs/schema.sql Section V) — mirrors markSample()'s pattern. */
async function markTest(id) {
  await db.execute('UPDATE suppliers SET is_test_data = 1 WHERE id = :id', { id });
}

module.exports = { all, find, create, markSample, markTest };
