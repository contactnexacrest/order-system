'use strict';

const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const paymentPresetRepository = require('../repositories/paymentPresetRepository');
const lookupRepository = require('../repositories/lookupRepository');

/**
 * Full CRUD for payment_presets — see paymentPresetRepository's
 * docblock. Gated on manage_payment_presets, same tier as
 * manage_logistics_partners (Admin/MD/ED and Super Admin only).
 */

const TRIGGER_OPTIONS = {
  A_BEFORE_SHIPMENT: 'Before Shipment',
  B_AGAINST_BL: 'Against Scanned BL Copy',
};

async function index(req, res) {
  const presets = await paymentPresetRepository.all(true);
  for (const p of presets) {
    p.in_use = await paymentPresetRepository.isInUse(p.id);
  }
  res.renderView('payment_presets/index', { presets }, 'layout/base');
}

async function createForm(req, res) {
  res.renderView('payment_presets/create', {
    currencies: await lookupRepository.currencies(),
    triggerOptions: TRIGGER_OPTIONS,
  }, 'layout/base');
}

/** @returns {{data: object}|{error: string}} */
function collectFormData(req) {
  const presetName = String(req.body.preset_name || '').trim();
  const advanceTriggerText = String(req.body.advance_trigger_text || '').trim();
  const balanceTriggerOption = String(req.body.balance_trigger_option || '').trim();
  const balanceTriggerWording = String(req.body.balance_trigger_wording || '').trim();
  const advancePct = parseFloat(req.body.advance_pct || 0);
  const balancePct = parseFloat(req.body.balance_pct || 0);
  const balanceDays = parseInt(req.body.balance_days || 0, 10);
  const currencyId = parseInt(req.body.currency_id || 0, 10);

  if (presetName === '') {
    return { error: 'Preset name is required.' };
  }
  if (advanceTriggerText === '') {
    return { error: 'Advance trigger text is required.' };
  }
  if (!Object.prototype.hasOwnProperty.call(TRIGGER_OPTIONS, balanceTriggerOption)) {
    return { error: 'Select a valid balance trigger option.' };
  }
  if (advancePct <= 0 || advancePct >= 100) {
    return { error: 'Advance % must be between 0 and 100.' };
  }
  if (Math.abs((advancePct + balancePct) - 100.0) > 0.01) {
    return { error: `Advance % and Balance % must add up to 100 (got ${advancePct}% + ${balancePct}% = ${advancePct + balancePct}%).` };
  }
  if (balanceDays <= 0) {
    return { error: 'Balance days must be a positive number.' };
  }
  if (currencyId <= 0) {
    return { error: 'Select a currency.' };
  }
  if (balanceTriggerWording !== '' && !balanceTriggerWording.includes('{days}')) {
    return { error: 'Balance trigger wording must include the {days} token, substituted with Balance Days at render time.' };
  }

  return {
    data: {
      preset_name: presetName,
      is_default: !!req.body.is_default,
      advance_pct: advancePct,
      advance_trigger_text: advanceTriggerText,
      balance_pct: balancePct,
      balance_trigger_option: balanceTriggerOption,
      balance_days: balanceDays,
      balance_trigger_wording: balanceTriggerWording || null,
      currency_id: currencyId,
      requires_md_approval: !!req.body.requires_md_approval,
    },
  };
}

async function create(req, res) {
  const result = collectFormData(req);
  if (result.error) {
    flash.set(req, 'error', result.error);
    res.redirect('/payment-presets/create');
    return;
  }

  const id = await paymentPresetRepository.create(result.data, req.user.id);
  await auditLogRepository.log(req.user.id, 'PAYMENT_PRESET_ADDED', 'payment_presets', id, null, null, result.data.preset_name);
  flash.set(req, 'success', `"${result.data.preset_name}" added — ${result.data.advance_pct}% advance / ${result.data.balance_pct}% balance.`);
  res.redirect('/payment-presets');
}

async function editForm(req, res) {
  const id = parseInt(req.params.id, 10);
  const preset = await paymentPresetRepository.find(id);
  if (!preset) {
    res.status(404).send('Payment preset not found.');
    return;
  }
  res.renderView('payment_presets/edit', {
    preset,
    currencies: await lookupRepository.currencies(),
    triggerOptions: TRIGGER_OPTIONS,
    inUse: await paymentPresetRepository.isInUse(id),
  }, 'layout/base');
}

async function update(req, res) {
  const id = parseInt(req.params.id, 10);
  const preset = await paymentPresetRepository.find(id);
  if (!preset) {
    res.status(404).send('Payment preset not found.');
    return;
  }
  if (preset.is_protected) {
    flash.set(req, 'error', 'This preset is protected — unlock it via Field Protection before editing.');
    res.redirect(`/payment-presets/${id}/edit`);
    return;
  }

  const result = collectFormData(req);
  if (result.error) {
    flash.set(req, 'error', result.error);
    res.redirect(`/payment-presets/${id}/edit`);
    return;
  }

  await paymentPresetRepository.update(id, result.data);
  await auditLogRepository.log(req.user.id, 'PAYMENT_PRESET_UPDATED', 'payment_presets', id, 'preset_name', preset.preset_name, result.data.preset_name);
  flash.set(req, 'success', `"${result.data.preset_name}" updated.`);
  res.redirect('/payment-presets');
}

async function toggleActive(req, res) {
  const id = parseInt(req.params.id, 10);
  const preset = await paymentPresetRepository.find(id);
  if (!preset) {
    flash.set(req, 'error', 'Payment preset not found.');
    res.redirect('/payment-presets');
    return;
  }

  try {
    await paymentPresetRepository.toggleActive(id);
  } catch (e) {
    flash.set(req, 'error', e.message);
    res.redirect('/payment-presets');
    return;
  }
  const nowActive = !preset.is_active;
  await auditLogRepository.log(
    req.user.id,
    nowActive ? 'PAYMENT_PRESET_REACTIVATED' : 'PAYMENT_PRESET_DEACTIVATED',
    'payment_presets', id, 'is_active', String(Number(!!preset.is_active)), String(Number(nowActive))
  );
  flash.set(req, 'success', `"${preset.preset_name}" ${nowActive ? 'reactivated' : 'deactivated'}.`);
  res.redirect('/payment-presets');
}

module.exports = { index, createForm, create, editForm, update, toggleActive };
