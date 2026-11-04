'use strict';

// Owner decision D-245 P2: every test file starts with the seeded pricing rule
// "Goods lines must be picked from Products" on, so a file that switches it off
// (allowHandTypedGoodsLines in fixtures.js) never leaks into the next file.
// PHP twin: DbTestCase::setUp().
beforeAll(async () => {
  try {
    await require('../../src/config/db').execute("UPDATE pricing_settings SET setting_value = '1' WHERE setting_key = 'require_product_pick'");
  } catch (e) {
    // A unit test without the database: nothing to reset.
  }
});
