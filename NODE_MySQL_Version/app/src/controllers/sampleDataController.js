'use strict';

const flash = require('../helpers/flash');
const logger = require('../helpers/logger');
const auditLogRepository = require('../repositories/auditLogRepository');
const sampleDataService = require('../services/sampleDataService');

/**
 * Phase E follow-up — Sample Data Playground (Section: user request
 * 2026-09-19, "can we have some sample records to play with... this
 * effects only the test data and not actual data"). Gated on the new
 * `manage_sample_data` permission (Admin/Managing Director by default,
 * same wildcard grant every other admin-only permission gets).
 */

async function index(req, res) {
  const isLoaded = await sampleDataService.isLoaded();
  res.renderView(
    'sample_data/index',
    {
      isLoaded,
      summary: isLoaded ? await sampleDataService.summary() : [],
    },
    'layout/base'
  );
}

async function load(req, res) {
  const user = req.user;
  let result;
  try {
    result = await sampleDataService.load(user.id);
  } catch (e) {
    logger.error('SAMPLE DATA LOAD FAILED', e);
    flash.set(req, 'error', e.message || 'Could not load sample data — check the server error log.');
    res.redirect('/sample-data');
    return;
  }

  await auditLogRepository.log(user.id, 'SAMPLE_DATA_LOADED', 'clients', null, null, null, null,
    `Loaded ${result.clients} sample client(s) / ${result.orders} sample order(s).`);

  flash.set(req, 'success', `Sample data loaded: ${result.clients} client(s), ${result.orders} order(s) — look for the "[SAMPLE]" prefix everywhere they appear. Clear them any time from this same screen.`);
  res.redirect('/sample-data');
}

async function clear(req, res) {
  const user = req.user;
  let result;
  try {
    result = await sampleDataService.clear();
  } catch (e) {
    logger.error('SAMPLE DATA CLEAR FAILED', e);
    flash.set(req, 'error', 'Could not clear sample data — check the server error log. Nothing was left half-deleted (it runs in one transaction).');
    res.redirect('/sample-data');
    return;
  }

  await auditLogRepository.log(user.id, 'SAMPLE_DATA_CLEARED', 'clients', null, null, null, null,
    `Removed ${result.clients} sample client(s), ${result.orders} sample order(s), ${result.files} generated file(s).`);

  flash.set(req, 'success', `Sample data cleared: ${result.clients} client(s), ${result.orders} order(s), ${result.files} file(s) removed. Load it again whenever you like.`);
  res.redirect('/sample-data');
}

module.exports = { index, load, clear };
