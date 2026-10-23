'use strict';

const flash = require('../helpers/flash');
const uiTemplateRepository = require('../repositories/uiTemplateRepository');
const uiTemplateService = require('../services/uiTemplateService');

/**
 * docs/schema.sql Section BQ — admin on/off switch per UI template,
 * gated by manage_company_settings (same tier as the rest of Settings).
 * The company-wide default itself is set from the Settings page (see
 * settingsController), not here — this screen only controls which
 * templates exist to choose from at all.
 */

async function index(req, res) {
  res.renderView('ui_templates/index', { templates: await uiTemplateRepository.all() }, 'layout/base');
}

async function toggleActive(req, res) {
  const id = parseInt(req.params.id, 10);
  const template = await uiTemplateRepository.findById(id);
  if (!template) {
    res.status(404).send('Template not found.');
    return;
  }
  if (template.template_key === uiTemplateService.FALLBACK_TEMPLATE_KEY && Number(template.is_active) === 1) {
    flash.set(req, 'error', '"Standard" can never be deactivated — it is the fallback every user and the company default ultimately fall back to.');
    res.redirect('/ui-templates');
    return;
  }
  await uiTemplateRepository.setActive(id, !template.is_active);
  flash.set(req, 'success', template.is_active ? 'Template deactivated — anyone using it (by preference or as the company default) now falls back automatically.' : 'Template reactivated.');
  res.redirect('/ui-templates');
}

module.exports = { index, toggleActive };
