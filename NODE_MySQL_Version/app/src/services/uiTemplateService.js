'use strict';

const companySettingsRepository = require('../repositories/companySettingsRepository');
const uiTemplateRepository = require('../repositories/uiTemplateRepository');

/**
 * docs/schema.sql Section BQ — resolves which UI template a user actually
 * sees, in this order: the user's own preference, if that template is
 * still active -> the company-wide default, if IT is still active ->
 * hard-coded 'standard', which can never itself be deactivated (see
 * uiTemplateController.toggleActive()), so this always returns a real,
 * usable template key — never null, never a deactivated one.
 */

const FALLBACK_TEMPLATE_KEY = 'standard';

async function resolve(userPreferenceId) {
  if (userPreferenceId !== null && userPreferenceId !== undefined) {
    const preferred = await uiTemplateRepository.findById(userPreferenceId);
    if (preferred && Number(preferred.is_active) === 1) {
      return preferred.template_key;
    }
  }

  const defaultKey = await companySettingsRepository.get('default_ui_template');
  if (defaultKey !== null) {
    const defaultTemplate = await uiTemplateRepository.findByKey(defaultKey);
    if (defaultTemplate && Number(defaultTemplate.is_active) === 1) {
      return defaultTemplate.template_key;
    }
  }

  return FALLBACK_TEMPLATE_KEY;
}

module.exports = { resolve, FALLBACK_TEMPLATE_KEY };
