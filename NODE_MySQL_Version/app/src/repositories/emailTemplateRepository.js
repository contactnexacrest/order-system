'use strict';

const db = require('../config/db');

/**
 * docs/schema.sql Section AI — a template can be added or edited
 * (governed by manage_email_templates) but never deleted. email_log
 * already freezes subject/body at send time (body_snapshot), so
 * deactivating a template here never changes what a past send actually
 * said — is_active only controls whether it's still offered for a NEW
 * send.
 */

async function find(templateKey) {
  return db.queryOne('SELECT * FROM email_templates WHERE template_key = :key', { key: templateKey });
}

async function findById(id) {
  return db.queryOne('SELECT * FROM email_templates WHERE id = :id', { id });
}

async function all() {
  return db.query('SELECT * FROM email_templates ORDER BY template_key');
}

async function create(templateKey, subject, body, footer, createdBy) {
  const result = await db.execute(
    `INSERT INTO email_templates (template_key, subject, body, footer, is_active, created_by, updated_by)
     VALUES (:template_key, :subject, :body, :footer, 1, :created_by, :updated_by)`,
    { template_key: templateKey, subject, body, footer, created_by: createdBy, updated_by: createdBy }
  );
  return result.insertId;
}

async function update(id, subject, body, footer, updatedBy) {
  await db.execute(
    'UPDATE email_templates SET subject = :subject, body = :body, footer = :footer, updated_by = :updated_by WHERE id = :id',
    { subject, body, footer, updated_by: updatedBy, id }
  );
}

async function setActive(id, isActive, updatedBy) {
  await db.execute(
    'UPDATE email_templates SET is_active = :is_active, updated_by = :updated_by WHERE id = :id',
    { is_active: isActive ? 1 : 0, updated_by: updatedBy, id }
  );
}

async function keyExists(templateKey) {
  const row = await db.queryOne('SELECT 1 AS x FROM email_templates WHERE template_key = :key', { key: templateKey });
  return !!row;
}

module.exports = { find, findById, all, create, update, setActive, keyExists };
