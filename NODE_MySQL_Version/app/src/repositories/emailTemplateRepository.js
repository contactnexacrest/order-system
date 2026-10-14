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

/** Port of PHP's strtr() for this file's own token maps — plain, non-regex substring replacement, so a token like '{code}' can never be misread as a regex. */
function substituteTokens(text, tokens) {
  let result = text;
  for (const [token, value] of Object.entries(tokens)) {
    result = result.split(token).join(value);
  }
  return result;
}

/**
 * 90-item backlog #37: for the system/transactional emails (2FA code,
 * password resets, portal invite, correction/intake links — see
 * seed.sql's own comment block for the full key list) that used to have
 * their subject/body hardcoded inline rather than pulled from this
 * table. Unlike buildPreview() in emailDispatchService (which throws on
 * an unknown/inactive template, since a buyer document-send is always a
 * deliberate staff action with a visible error path), a template row
 * going missing or getting deactivated here must never stop a
 * security-critical email like a password reset from going out — so
 * this falls back to the exact original hardcoded wording (also
 * token-substituted, for consistency) instead of throwing.
 *
 * @returns {Promise<{subject: string, body: string}>}
 */
async function renderOrDefault(templateKey, tokens, defaultSubject, defaultBody) {
  const template = await find(templateKey);
  if (template && Number(template.is_active) === 1) {
    let body = substituteTokens(template.body, tokens);
    if (template.footer) {
      body += '\n\n' + substituteTokens(template.footer, tokens);
    }
    return { subject: substituteTokens(template.subject, tokens), body };
  }
  return { subject: substituteTokens(defaultSubject, tokens), body: substituteTokens(defaultBody, tokens) };
}

module.exports = { find, findById, all, create, update, setActive, keyExists, renderOrDefault };
