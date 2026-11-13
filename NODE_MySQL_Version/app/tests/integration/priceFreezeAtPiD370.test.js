'use strict';

/**
 * Owner decision D-370 (13 Nov 2026, owner "go"; QA HNT-L05 — a gap in D-342): prices,
 * products and the exchange rate are fixed at the first approved Quotation OR, if none
 * was approved, at the first approved Proforma Invoice — whichever comes first. A
 * PI-fixed order is locked exactly like a Quotation-fixed one (order page, amendments,
 * document guard), and the screen names the document that fixed it. Migration
 * 2026-11-13g fixes existing orders the same way.
 * PHP twin: tests/Integration/PriceFreezeAtPiD370Test.php (same wording).
 */

jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const env = require('../../src/config/env');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const pricingRepository = require('../../src/repositories/pricingRepository');
const productRepository = require('../../src/repositories/productRepository');
const productSupplierRepository = require('../../src/repositories/productSupplierRepository');
const documentGenerationService = require('../../src/services/documentGenerationService');
const fxRateService = require('../../src/services/fxRateService');
const orderLinePricing = require('../../src/services/orderLinePricing');
const priceLock = require('../../src/services/priceLock');
const pricingService = require('../../src/services/pricingService');
const productsAmendmentService = require('../../src/services/productsAmendmentService');
const { createTestClient, createTestOrder, createTestDocument } = require('../support/fixtures');

const PASSWORD = 'JestPriceFreeze!1';
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function todayText() {
  const d = new Date();
  return `${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear()}`;
}
function todayYmd() {
  const d = new Date();
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}
const money2 = (n) => n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

describe('Prices fixed at the first approved Quotation or, failing that, the first approved PI (D-370, HNT-L05)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  afterEach(async () => {
    for (const [k, v] of Object.entries(pricingService.DEFAULTS)) {
      await db.execute('UPDATE pricing_settings SET setting_value = :v WHERE setting_key = :k', { k, v });
    }
  });

  async function user(roleName, superAdmin = false) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-d370-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const id = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled, is_super_admin)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0, :sa)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id, sa: superAdmin ? 1 : 0 }
    )).insertId;
    return { id, email };
  }

  async function login(u) {
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: u.email, password: PASSWORD });
    return agent;
  }

  /** Posts a form and returns the flash message shown after the redirect. */
  async function post(agent, from, url, body) {
    const csrf = (await agent.get(from)).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    const res = await agent.post(url).type('form').send({ _csrf: csrf, ...body });
    const m = (await agent.get(res.headers.location)).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/);
    return m ? m[2].trim().replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&') : '';
  }

  /** The D-342 mockup order: three CBM lines at USD 1,950.00. */
  async function order() {
    const admin = await user('Admin');
    const productId = await productRepository.create({ name: `D370 CHM Black P6 ${crypto.randomBytes(3).toString('hex')}`, specifications: 'Granite slab', hs_code: '68022310', reference_unit: 'CBM' }, admin.id);
    const s = await productSupplierRepository.create({ product_id: productId, supplier_name: 'Test Supplier', fob_source: 'direct', fob_value: 80000, is_primary: 1 }, admin.id);
    await pricingRepository.setSupplierDates(s, todayYmd(), null);
    const orderId = await createTestOrder(await createTestClient());
    const ids = [];
    const rows = [[180, 80, 13, 25, '4.680'], [140, 70, 13, 25, '3.185'], [120, 60, 13, 10, '0.936']];
    for (let i = 0; i < rows.length; i++) {
      const [l, w, t, pcs, qty] = rows[i];
      const id = await orderProductRepository.add(orderId, i + 1, 'CHM Black P6', `${l} × ${w} × ${t} cm`, null, qty, false, 'CBM', '1950.00', '68022310');
      await orderProductRepository.setCatalogProduct(id, productId);
      await orderProductRepository.setPieceSize(id, { piece_length_cm: String(l), piece_width_cm: String(w), piece_thickness_cm: String(t) });
      await orderProductRepository.setPieces(id, pcs, null);
      ids.push(id);
    }
    return [orderId, ids];
  }

  async function approve(orderId, type) {
    const doc = await documentGenerationService.generate(orderId, type, (await user('Admin')).id, null, false, true);
    await documentGenerationService.finalizeApproval(doc.document_id);
    return Number(doc.document_id);
  }

  async function auditCount(orderId) {
    return Number((await db.queryOne(
      "SELECT COUNT(*) AS n FROM audit_log WHERE action_type = 'PRICES_FIXED_AT_PI' AND entity_type = 'orders' AND entity_id = :o", { o: orderId })).n);
  }

  it('HNT-L05: a PI approved without an approved Quotation fixes the rate and the lines', async () => {
    const [orderId, ids] = await order();
    await createTestDocument(orderId, 'QT', 'draft');
    const rateNow = await fxRateService.rate(await pricingRepository.settings());
    const piId = await approve(orderId, 'PI');

    const o = await orderRepository.find(orderId);
    expect(priceLock.isFixed(o)).toBe(true);
    expect(Number(o.prices_fixed_by_document_id)).toBe(piId);
    expect(o.prices_fixed_by_type).toBe('PI');
    expect(Number(o.pricing_fx_rate_at_qt)).toBe(rateNow);
    expect(String(o.pricing_fx_rate_at_qt)).toBe(String(o.pricing_fx_rate_at_pi));
    for (const id of ids) {
      const line = await orderProductRepository.find(id);
      expect(String(line.qt_fixed_unit_price)).toBe('1950.00');
      expect(Number(JSON.parse(line.price_snapshot).fx_rate)).toBe(rateNow);
    }
    expect(await auditCount(orderId)).toBe(1);
    expect(priceLock.message(o)).toBe(`Prices and products are fixed by the approved Proforma Invoice of ${todayText()} — change them through an Amendment (Order page › Amendments › Products & prices).`);
    const lineText = await fxRateService.orderLine(o, (await orderLinePricing.forOrder(orderId)).lines);
    expect(lineText.startsWith(`Prices worked out at ₹${money2(rateNow)} per USD — fixed by the approved Proforma Invoice of ${todayText()}`)).toBe(true);

    // A new rate, a later Quotation approval, a second PI approval: nothing changes.
    await db.execute("UPDATE pricing_settings SET setting_value = 'manual' WHERE setting_key = 'fx_mode'");
    await db.execute("UPDATE pricing_settings SET setting_value = '99.5000' WHERE setting_key = 'pricing_fx_rate'");
    await approve(orderId, 'QT');
    expect(await priceLock.fixAtPiApproval(orderId, piId)).toBe(false);
    const after = await orderRepository.find(orderId);
    expect([Number(after.prices_fixed_by_document_id), Number(after.pricing_fx_rate_at_qt)]).toEqual([piId, rateNow]);
  });

  it('a Quotation approved first still fixes the prices; the PI changes nothing', async () => {
    const [orderId] = await order();
    const qtId = await approve(orderId, 'QT');
    const fixedAt = (await orderRepository.find(orderId)).prices_fixed_at;
    await approve(orderId, 'PI');
    const o = await orderRepository.find(orderId);
    expect([Number(o.prices_fixed_by_document_id), o.prices_fixed_by_type, o.prices_fixed_at]).toEqual([qtId, 'QT', fixedAt]);
    expect(priceLock.message(o).startsWith('Prices and products are fixed by the approved Quotation of ')).toBe(true);
    expect(await auditCount(orderId)).toBe(0);
  });

  it('after a PI freeze the lines are refused and only the amendment changes them', async () => {
    const [orderId, ids] = await order();
    await approve(orderId, 'PI');
    const expected = `Prices and products are fixed by the approved Proforma Invoice of ${todayText()} — change them through an Amendment (Order page › Amendments › Products & prices).`;
    const agent = await login(await user('Admin', true));
    const body = { description: 'CHM Black P6', quantity: '9', unit: 'CBM', unit_price: '2500.00', reason: 'Super Admin override attempt for the test' };
    for (const url of [`/orders/${orderId}/products`, `/orders/${orderId}/products/${ids[0]}`, `/orders/${orderId}/products/${ids[0]}/duplicate`, `/orders/${orderId}/products/${ids[1]}/delete`]) {
      expect(await post(agent, `/orders/${orderId}`, url, body)).toBe(expected);
    }
    expect(String((await orderProductRepository.find(ids[0])).unit_price)).toBe('1950.00');
    expect((await orderProductRepository.forOrder(orderId)).length).toBe(3);

    const html = (await agent.get(`/orders/${orderId}`)).text;
    expect(html).toContain('id="price-lock-note"');
    expect(html).toContain(expected.replace('&', '&amp;'));
    expect(html).not.toContain('data-open-panel="line-panel-new"');

    const amdPage = (await agent.get(`/orders/${orderId}/amendments/products/new`)).text;
    expect(amdPage).toContain(`Prices fixed by the approved Proforma Invoice of ${todayText()}`);
    expect(amdPage).toContain('is the price the buyer accepted in the Proforma Invoice — a price can only go down');
    expect(amdPage).toContain('the buyer accepted the Proforma Invoice at USD');

    const o = await orderRepository.find(orderId);
    expect(await productsAmendmentService.cannotStart(o)).toBeNull();
    const up = await productsAmendmentService.draft(o, { line: { [ids[0]]: { length_cm: '180', width_cm: '80', thickness_cm: '13', pieces: '25', unit_price: '2000.00' } } });
    expect(up.errors).toContain('Line 1: USD 1,950.00 is the price the buyer accepted in the Proforma Invoice — a price can only go down, with the MD\'s approval.');

    await db.execute("UPDATE order_products SET unit_price = '2000.00' WHERE id = :id", { id: ids[1] });
    expect(await priceLock.documentGuard(orderId, 'CI')).toBe('Line 2: USD 2,000.00 is higher than USD 1,950.00, the price the buyer accepted in the approved Proforma Invoice — the Commercial Invoice cannot be generated. Prices change only through an Amendment (Order page › Amendments › Products & prices).');
  });

  it('nothing approved yet: the lines stay editable', async () => {
    const [orderId] = await order();
    await createTestDocument(orderId, 'PI', 'draft');
    const o = await orderRepository.find(orderId);
    expect(priceLock.isFixed(o)).toBe(false);
    expect(await productsAmendmentService.cannotStart(o)).toBe('Neither a Quotation nor a Proforma Invoice of this order is approved yet — change its lines on the order page (Products).');
  });

  it('the migration fixes orders with a sent PI and a draft Quotation', async () => {
    const [withRate, ids] = await order();
    await createTestDocument(withRate, 'QT', 'draft');
    const pi = await createTestDocument(withRate, 'PI', 'sent');
    await db.execute('UPDATE orders SET pricing_fx_rate_at_pi = 84.2500 WHERE id = :o', { o: withRate });
    const [noRate] = await order();
    const piNoRate = await createTestDocument(noRate, 'PI', 'approved');
    const [draftOnly] = await order();
    await createTestDocument(draftOnly, 'PI', 'draft');
    const [qtFixed] = await order();
    const qtId = await approve(qtFixed, 'QT');
    const before = await orderRepository.find(qtFixed);
    await db.execute("UPDATE pricing_settings SET setting_value = 'manual' WHERE setting_key = 'fx_mode'");
    await db.execute("UPDATE pricing_settings SET setting_value = '88.1200' WHERE setting_key = 'pricing_fx_rate'");

    const sql = fs.readFileSync(path.join(__dirname, '../../../../docs/migrations/2026-11-13g-freeze-prices-at-pi.sql'), 'utf8');
    // phpMyAdmin (shared hosting) switches database after an information_schema query and then refuses
    // PREPAREd statements (#1044, 13 Nov 2026): the file must be plain statements only.
    const code = sql.replace(/^--.*$/gm, ''); // comments may mention the words
    expect(code).not.toContain('PREPARE');
    expect(code.toLowerCase()).not.toContain('information_schema');
    const args = ['-h', env.get('DB_HOST', '127.0.0.1'), '-P', String(env.get('DB_PORT', '3306')), '-u', env.get('DB_USERNAME'), `-p${env.get('DB_PASSWORD', '')}`, env.get('DB_DATABASE')];
    for (const text of [sql, sql]) {
      const out = execFileSync('mysql', args, { input: text }).toString();
      expect(out).toContain('; 0 still to fix (must be 0).');
    }

    const o = await orderRepository.find(withRate);
    expect([Number(o.prices_fixed_by_document_id), o.prices_fixed_by_type, String(o.pricing_fx_rate_at_qt)]).toEqual([pi, 'PI', '84.2500']);
    expect(o.prices_fixed_at).not.toBeNull();
    expect(String((await orderProductRepository.find(ids[0])).qt_fixed_unit_price)).toBe('1950.00');
    const n = await orderRepository.find(noRate);
    expect([Number(n.prices_fixed_by_document_id), String(n.pricing_fx_rate_at_qt)]).toEqual([piNoRate, '88.1200']);
    expect((await orderRepository.find(draftOnly)).prices_fixed_at).toBeNull();
    const q = await orderRepository.find(qtFixed);
    expect([Number(q.prices_fixed_by_document_id), String(q.prices_fixed_at), String(q.pricing_fx_rate_at_qt)])
      .toEqual([qtId, String(before.prices_fixed_at), String(before.pricing_fx_rate_at_qt)]);
  });
});
