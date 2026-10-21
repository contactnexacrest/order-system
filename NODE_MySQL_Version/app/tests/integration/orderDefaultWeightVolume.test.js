'use strict';

const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const flash = require('../../src/helpers/flash');
const ordersController = require('../../src/controllers/ordersController');

/**
 * Port of OrderDefaultWeightVolumeTest.php. 90-item backlog "default
 * weight/volume values": the New Order form's Total CBM/Gross Weight/Net
 * Weight fields used to always start blank. They now pre-fill from
 * configurable company_settings — staff still edit per order as always,
 * and a validation-retry's own typed value (or the #41/#46 intake
 * prefill) always wins over the configured default.
 */
describe('Order default weight/volume values (90-item backlog "default weight/volume values")', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function fakeRes() {
    const res = {};
    res.renderView = (view, data) => { res.renderedView = view; res.renderedData = data; };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  it('create form prefills configured weight/volume defaults', async () => {
    const req = { query: {}, session: {} };
    const res = fakeRes();

    await ordersController.create(req, res);

    const totalCbm = await companySettingsRepository.get('default_estimated_total_cbm');
    const grossWeight = await companySettingsRepository.get('default_estimated_gross_weight_kg');
    const netWeight = await companySettingsRepository.get('default_estimated_net_weight_kg');

    expect(res.renderedData.old.estimated_total_cbm).toBe(totalCbm);
    expect(res.renderedData.old.estimated_gross_weight_kg).toBe(grossWeight);
    expect(res.renderedData.old.estimated_net_weight_kg).toBe(netWeight);
  });

  it('a validation-retry value wins over the configured default', async () => {
    const req = { query: {}, session: {} };
    flash.setOld(req, { estimated_total_cbm: '99.9' });
    const res = fakeRes();

    await ordersController.create(req, res);

    expect(res.renderedData.old.estimated_total_cbm).toBe('99.9');
  });
});
