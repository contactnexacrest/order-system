'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const pricingRepository = require('../../src/repositories/pricingRepository');
const productRepository = require('../../src/repositories/productRepository');
const productSupplierRepository = require('../../src/repositories/productSupplierRepository');
const orderLinePricing = require('../../src/services/orderLinePricing');
const lineDisplay = require('../../src/services/orderLineDisplay');
const { createTestClient, createTestOrder, pinWorkedExamplePricing, resetPricingSettings, configureProductForQuoting, baseSpecJson } = require('../support/fixtures');

/**
 * Owner-approved layout of the order page's Products table (mockup 2, 18 Nov 2026) — display only:
 * the size on one line with "25 pcs · 0.1274 CBM each" under it, the Qty without decimals for pieces,
 * option chips side by side ("Edge work: None" for a bare None), the price check with its badge first
 * and the reference folded under "Per CBM ▸", and D-374's record line shortened to "₹90.00 / USD · <date>".
 * PHP twin: tests/Integration/OrderProductsTableLayoutTest.php.
 */
describe('the order page products table layout (mockup 2)', () => {
  const PASSWORD = 'JestTableLayout!1';
  const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const today = () => {
    const d = new Date();
    return `${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear()}`;
  };
  const isoToday = () => {
    const d = new Date();
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
  };

  afterAll(async () => {
    await db.pool.end();
  });

  beforeEach(async () => {
    await pinWorkedExamplePricing({ fx_mode: 'manual', pricing_fx_rate: '90.0000' });
  });

  afterEach(async () => {
    await resetPricingSettings();
  });

  test('the quantity and the other figures are shown as approved', () => {
    expect(lineDisplay.quantityText('25.000', 'PCS')).toBe('25');
    expect(lineDisplay.quantityText('1200.000', 'Nos')).toBe('1,200');
    expect(lineDisplay.quantityText('2.000', 'SET')).toBe('2');
    expect(lineDisplay.quantityText('3.000', 'Lot')).toBe('3');
    expect(lineDisplay.quantityText('2.500', 'SET')).toBe('2.5');
    expect(lineDisplay.quantityText('12.500', 'CBM')).toBe('12.500');
    expect(lineDisplay.quantityText('12.345', 'SQM')).toBe('12.35');
    expect(lineDisplay.quantityText('40.000', 'SQFT')).toBe('40.00');
    expect(lineDisplay.quantityText('1.500', 'TON')).toBe('1.500');
    expect(lineDisplay.cbmEachText({ piece_length_cm: '140.00', piece_width_cm: '70.00', piece_thickness_cm: '13.00' })).toBe('0.1274 CBM each');
    expect(lineDisplay.cbmEachText({ piece_length_cm: '140.00', piece_width_cm: '70.00', piece_thickness_cm: null })).toBe('');
    expect(lineDisplay.pricedAtShort({ unit_price: '368.11', priced_fx_rate: '91.6100', priced_at: '2026-11-18 10:15:00' })).toBe('₹91.61 / USD · 18 Nov 2026');
    expect(lineDisplay.pricedAtShort({ unit_price: '10.00', priced_fx_rate: null, priced_at: null })).toBe('Rate not recorded');
    expect(lineDisplay.pricedAtShort({ unit_price: null, priced_fx_rate: null, priced_at: null })).toBe('');
    expect(lineDisplay.chipText({ name: 'None', gone: false, spec: 'Edge work' })).toBe('Edge work: None');
    expect(lineDisplay.chipText({ name: 'Premium', gone: false, spec: 'Grade / quality' })).toBe('Premium');
  });

  test('the order page shows the approved row', async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const email = `jest-tablelayout-${crypto.randomBytes(4).toString('hex')}@email.com`;
    const uid = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    )).insertId;
    const productId = await productRepository.create({ name: `Layout Black Granite ${crypto.randomBytes(3).toString('hex')}`, specifications: 'Granite block', hs_code: '68029300', reference_unit: 'CBM' }, uid);
    const s = await productSupplierRepository.create({ product_id: productId, supplier_name: 'TEST-Supplier', fob_source: 'direct', fob_value: 80000, is_primary: 1 }, uid);
    await pricingRepository.setSupplierDates(s, isoToday(), null);
    await configureProductForQuoting(productId, uid);

    const orderId = await createTestOrder(await createTestClient());
    const line = await orderProductRepository.add(orderId, 1, 'Absolute Black Granite', '140 × 70 × 13 cm', null, '25', false, 'PCS', '368.11', '68029300');
    await orderProductRepository.setCatalogProduct(line, productId);
    await orderLinePricing.saveSelection(line, JSON.parse(await baseSpecJson(productId)), null);
    await orderProductRepository.setPieceSize(line, { piece_length_cm: '140', piece_width_cm: '70', piece_thickness_cm: '13' });
    await orderProductRepository.setPieces(line, 25, null);
    await orderLinePricing.afterSave(line, null, uid);

    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });
    const html = (await agent.get(`/orders/${orderId}`)).text;

    let row = html.slice(html.indexOf(`id="line-${line}"`));
    row = row.slice(0, row.indexOf('</tr>'));
    expect(row).toContain('<td class="num line-qty">25</td>');
    expect(row).toContain('<span class="line-dims">140 × 70 × 13 cm</span><div class="muted small line-pcs">25 pcs · 0.1274 CBM each</div>');
    expect(row).toContain('<span class="chip">Edge work: None</span>');
    expect(row).toContain('<details class="price-ref-more"><summary>Per CBM ▸</summary><span class="price-ref muted">Product reference per CBM:');
    expect(row.indexOf('class="price-badge')).toBeLessThan(row.indexOf('class="price-range'));
    expect(row).toContain(`>₹90.00 / USD · ${today()}</div>`);
    expect(row).toContain(`title="Priced at ₹90.00 per USD on ${today()}"`);
    for (const hook of ['<td class="line-actions">', `data-open-panel="line-panel-${line}">Edit</button>`, `/products/${line}/duplicate"`, `/products/${line}/delete"`]) {
      expect(row).toContain(hook);
    }
  });
});
