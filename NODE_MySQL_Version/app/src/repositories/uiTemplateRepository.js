'use strict';

const db = require('../config/db');

/**
 * docs/schema.sql Section BQ — the small fixed catalog of UI templates
 * (today: 'standard', 'kanban') a user or the company default can point
 * at. 'standard' can never be deactivated (see uiTemplateService) — that
 * is enforced by the controller/service layer, not here.
 */

async function all() {
  return db.query('SELECT * FROM ui_templates ORDER BY id');
}

async function active() {
  return db.query('SELECT * FROM ui_templates WHERE is_active = 1 ORDER BY id');
}

async function findById(id) {
  return db.queryOne('SELECT * FROM ui_templates WHERE id = :id', { id });
}

async function findByKey(templateKey) {
  return db.queryOne('SELECT * FROM ui_templates WHERE template_key = :key', { key: templateKey });
}

async function setActive(id, isActive) {
  await db.execute('UPDATE ui_templates SET is_active = :is_active WHERE id = :id', { is_active: isActive ? 1 : 0, id });
}

module.exports = { all, active, findById, findByKey, setActive };
