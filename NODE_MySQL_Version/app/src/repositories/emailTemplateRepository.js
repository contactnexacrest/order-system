'use strict';

const db = require('../config/db');

async function find(templateKey) {
  return db.queryOne('SELECT * FROM email_templates WHERE template_key = :key', { key: templateKey });
}

async function all() {
  return db.query('SELECT * FROM email_templates ORDER BY template_key');
}

module.exports = { find, all };
