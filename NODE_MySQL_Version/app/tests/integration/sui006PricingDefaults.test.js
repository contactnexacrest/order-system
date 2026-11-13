'use strict';

const fs = require('fs');
const path = require('path');
const bcrypt = require('bcrypt');
const crypto = require('crypto');
const db = require('../../src/config/db');
const pricingRepository = require('../../src/repositories/pricingRepository');
const productRepository = require('../../src/repositories/productRepository');
const productSupplierRepository = require('../../src/repositories/productSupplierRepository');
const pricingService = require('../../src/services/pricingService');
const orderLinePricing = require('../../src/services/orderLinePricing');
const { todayIst } = require('../../src/helpers/dbDateTime');
const { pinWorkedExamplePricing, resetPricingSettings, configureProductForQuoting } = require('../support/fixtures');

/**
 * QA 13 Nov 2026, SUI-006: the code fallbacks for the pricing settings (used only when a
 * pricing_settings row is missing) said margin 20 % / other costs 2.5 % while D-347 made the
 * installed values 15 % / 5.5 %. The fallbacks now equal seed.sql; a stored row still wins,
 * so no existing product's price changes. PHP twin: tests/Integration/Sui006PricingDefaultsTest.php.
 */
describe('SUI-006 pricing fallbacks equal the installed values', () => {
  afterEach(async () => {
    await resetPricingSettings();
    await db.execute("UPDATE incentive_settings SET setting_value = '0' WHERE setting_key = 'program_enabled'");
  });

  afterAll(async () => {
    await db.pool.end();
  });

  function seedValues() {
    const seed = fs.readFileSync(path.join(__dirname, '../../../docs/seed.sql'), 'utf8');
    const out = {};
    for (const block of seed.matchAll(/INSERT IGNORE INTO pricing_settings \(setting_key, setting_value\) VALUES([\s\S]*?);/g)) {
      for (const [, k, v] of block[1].matchAll(/\('([a-z_]+)',\s*'([^']*)'\)/g)) out[k] = v;
    }
    return out;
  }

  it('SUI-006: every fallback equals the installed value', () => {
    const seed = seedValues();
    expect(seed.margin_pct).toBe('15.00');
    expect(seed.other_cost_pct).toBe('5.50');
    for (const [k, v] of Object.entries(pricingService.DEFAULTS)) {
      expect([k, seed[k]]).toEqual([k, v]);
    }
  });

  it('SUI-006: a stored setting still wins, so existing prices do not change', async () => {
    // A live site that runs on 20 % / 2.5 % keeps exactly the prices it had.
    await pinWorkedExamplePricing();
    await db.execute("UPDATE incentive_settings SET setting_value = '1' WHERE setting_key = 'program_enabled'");
    const s = await pricingService.settings();
    expect([s.margin_pct, s.other_cost_pct]).toEqual([20, 2.5]);

    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const admin = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email: `jest-sui006-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`, hash: await bcrypt.hash('JestSui006!1', 4), role_id: role.id }
    )).insertId;
    const id = await productRepository.create({ name: `SUI006 Black Galaxy ${crypto.randomBytes(3).toString('hex')}`, specifications: 'Granite block', hs_code: '25161100', reference_unit: 'CBM' }, admin);
    const offer = await productSupplierRepository.create({ product_id: id, supplier_name: 'SUI006 Quarry', fob_source: 'direct', fob_value: 80000, is_primary: 1 }, admin);
    await pricingRepository.setSupplierDates(offer, todayIst(), null);
    await configureProductForQuoting(id, admin);
    expect((await orderLinePricing.pickerPrices([{ id }]))[id].staff).toEqual([1326.13, 1287.5]);
  });

  it('SUI-006: a missing row falls back to the installed value', async () => {
    await db.execute("DELETE FROM pricing_settings WHERE setting_key IN ('margin_pct', 'other_cost_pct')");
    try {
      const s = await pricingService.settings();
      expect([s.margin_pct, s.other_cost_pct]).toEqual([15, 5.5]);
    } finally {
      await db.execute("INSERT INTO pricing_settings (setting_key, setting_value) VALUES ('margin_pct', '15.00'), ('other_cost_pct', '5.50')");
    }
  });
});
