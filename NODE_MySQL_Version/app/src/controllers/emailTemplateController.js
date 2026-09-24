'use strict';

const flash = require('../helpers/flash');
const emailTemplateRepository = require('../repositories/emailTemplateRepository');

/** docs/schema.sql Section AI — email template CRUD, gated by manage_email_templates. Never delete. */

const AVAILABLE_TOKENS = [
  '{buyer_contact_person}', '{buyer_company_name}', '{document_reference}', '{generated_date}',
  '{order_reference}', '{buyer_inquiry_ref}', '{quotation_ref}', '{quotation_valid_until}',
  '{pi_ref}', '{pi_valid_until}', '{oc_ref}', '{company_name}', '{company_email}', '{company_phone}',
  '{sender_name}', '{sender_title}', '{sender_signature}',
];

async function index(req, res) {
  res.renderView('email_templates/index', { templates: await emailTemplateRepository.all() }, 'layout/base');
}

async function create(req, res) {
  res.renderView('email_templates/form', { template: null, availableTokens: AVAILABLE_TOKENS }, 'layout/base');
}

async function store(req, res) {
  const templateKey = String(req.body.template_key || '').trim().toLowerCase();
  const subject = String(req.body.subject || '').trim();
  const body = String(req.body.body || '');
  const footer = String(req.body.footer || '').trim();

  if (templateKey === '' || !/^[a-z0-9_]+$/.test(templateKey)) {
    flash.set(req, 'error', 'Template key must be lowercase letters, numbers, and underscores only.');
    res.redirect('/email-templates/create');
    return;
  }
  if (subject === '' || body.trim() === '') {
    flash.set(req, 'error', 'Subject and body are required.');
    res.redirect('/email-templates/create');
    return;
  }
  if (await emailTemplateRepository.keyExists(templateKey)) {
    flash.set(req, 'error', `Template key "${templateKey}" already exists — edit it instead of creating a duplicate.`);
    res.redirect('/email-templates/create');
    return;
  }

  await emailTemplateRepository.create(templateKey, subject, body, footer !== '' ? footer : null, req.user.id);
  flash.set(req, 'success', 'Template created.');
  res.redirect('/email-templates');
}

async function edit(req, res) {
  const template = await emailTemplateRepository.findById(parseInt(req.params.id, 10));
  if (!template) {
    res.status(404).send('Template not found.');
    return;
  }
  res.renderView('email_templates/form', { template, availableTokens: AVAILABLE_TOKENS }, 'layout/base');
}

async function update(req, res) {
  const id = parseInt(req.params.id, 10);
  const template = await emailTemplateRepository.findById(id);
  if (!template) {
    res.status(404).send('Template not found.');
    return;
  }

  const subject = String(req.body.subject || '').trim();
  const body = String(req.body.body || '');
  const footer = String(req.body.footer || '').trim();
  if (subject === '' || body.trim() === '') {
    flash.set(req, 'error', 'Subject and body are required.');
    res.redirect(`/email-templates/${id}/edit`);
    return;
  }

  await emailTemplateRepository.update(id, subject, body, footer !== '' ? footer : null, req.user.id);
  flash.set(req, 'success', 'Template updated. Every email already sent from it keeps exactly what it said at send time — this only affects new sends.');
  res.redirect('/email-templates');
}

async function toggleActive(req, res) {
  const id = parseInt(req.params.id, 10);
  const template = await emailTemplateRepository.findById(id);
  if (!template) {
    res.status(404).send('Template not found.');
    return;
  }
  await emailTemplateRepository.setActive(id, !template.is_active, req.user.id);
  flash.set(req, 'success', template.is_active ? 'Template deactivated — no longer offered for new sends.' : 'Template reactivated.');
  res.redirect('/email-templates');
}

module.exports = { index, create, store, edit, update, toggleActive };
