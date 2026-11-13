'use strict';

// Owner decision item 10a (13 Nov 2026, "go for the two-level version"): one line's
// quantity has two limits, the same for an export order line (create order, add line,
// edit line) and a Domestic Trade line.
//
//   1. Hard block above 1,000,000 in any unit — a typing slip, never an order
//      (orderLineValidator.MAX_QUANTITY / domesticOrderService; browser data-max-value).
//   2. Above a realistic level for the unit (about 20 containers) the line saves only with
//      "I confirm this quantity" ticked. The six levels are settings (Settings › Orders),
//      edited by Admin / MD: SQM 30,000 · SQFT 300,000 · CBM 500 · TON 600 · PCS 600,000 ·
//      RMT 60,000. Other units have only the hard block. The unit's kind comes from
//      lineUnits.canonical() (SQM = M2, MT = TON, NOS = PCS …).
//
// A confirmed large quantity is written to the audit log (LARGE_QUANTITY_CONFIRMED).
// Port of App\Services\QuantityLimits.

const lineUnits = require('./lineUnits');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const auditLogRepository = require('../repositories/auditLogRepository');

/** The hard block (any unit). */
const HARD_MAX = 1000000;

/** unit kind => [setting key, default level] */
const LEVELS = {
  M2: ['quantity_warning_level_sqm', 30000],
  SQFT: ['quantity_warning_level_sqft', 300000],
  CBM: ['quantity_warning_level_cbm', 500],
  TON: ['quantity_warning_level_ton', 600],
  PCS: ['quantity_warning_level_pcs', 600000],
  RMT: ['quantity_warning_level_rmt', 60000],
};

/** The checkbox of the line forms (create order: product_confirm_large_quantity[] per row). */
const TICK = 'confirm_large_quantity';

const MESSAGE = 'This is unusually large for one line (more than about 20 containers of {unit}). Check the quantity, or tick \'I confirm this quantity\' to save it.';

const isNum = (v) => /^\s*-?(\d+\.?\d*|\.\d+)([eE][-+]?\d+)?\s*$/.test(String(v ?? ''));

/** The level for this unit (from Settings, else the default), or null when the unit has only the hard block. */
async function levelFor(unit) {
  const kind = lineUnits.canonical(unit);
  if (kind === null || !LEVELS[kind]) return null;
  const [key, def] = LEVELS[kind];
  const raw = String((await companySettingsRepository.get(key)) ?? '').trim();
  return isNum(raw) && Number(raw) > 0 ? Number(raw) : def;
}

/** True when this quantity is above its unit's level (a TBC or blank quantity never is). */
async function isLarge(rawQuantity, unit, isTbc = false) {
  const q = String(rawQuantity ?? '').trim();
  if (isTbc || q === '' || !isNum(q)) return false;
  const level = await levelFor(unit);
  return level !== null && Number(q) > level;
}

function message(unit) {
  return MESSAGE.split('{unit}').join(String(unit ?? '').trim().toUpperCase());
}

/** The refusal when the quantity is above its level and not confirmed, else null. */
async function check(rawQuantity, unit, isTbc, confirmed) {
  return (await isLarge(rawQuantity, unit, isTbc)) && !confirmed ? message(unit) : null;
}

/** The browser's levels: every way of writing a unit with a level => its level, e.g. {"SQM":30000,"M2":30000,…}. */
async function browserLevels() {
  const out = {};
  for (const kind of Object.keys(LEVELS)) {
    const level = await levelFor(lineUnits.names(kind)[0] || kind);
    for (const name of lineUnits.names(kind)) out[name] = level;
  }
  return out;
}

function esc(s) {
  return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#039;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/** The quantity field's attributes for app.js (data-confirm-above / -unit / -message), HTML-escaped. */
async function fieldAttributes(unitField, unlessField = null) {
  return ` data-confirm-above="${esc(JSON.stringify(await browserLevels()))}"`
    + ` data-confirm-unit="${esc(unitField)}"`
    + (unlessField !== null ? ` data-confirm-unless="${esc(unlessField)}"` : '')
    + ` data-confirm-message="${esc(MESSAGE)}"`;
}

function levelText(n) {
  return Number(n).toLocaleString('en-US', { maximumFractionDigits: 3 });
}

/** Audit: who confirmed which line's quantity in which unit (and the level it passed). */
async function audit(userId, entityType, entityId, line, quantity, unit) {
  const q = String(quantity).trim();
  const u = String(unit ?? '').trim();
  await auditLogRepository.log(
    userId,
    'LARGE_QUANTITY_CONFIRMED',
    entityType,
    entityId,
    'quantity',
    null,
    `${q} ${u}`,
    `Large quantity confirmed ("I confirm this quantity"): ${q} ${u} on line "${line}" — above the ${levelText(await levelFor(u))} ${u} level (about 20 containers).`,
  );
}

module.exports = { HARD_MAX, LEVELS, TICK, MESSAGE, levelFor, isLarge, check, message, browserLevels, fieldAttributes, audit };
