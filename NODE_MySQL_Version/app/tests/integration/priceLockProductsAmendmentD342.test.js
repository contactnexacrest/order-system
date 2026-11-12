'use strict';

/**
 * Owner decision D-342 (12 Nov 2026, owner "confirm 1" and "confirm 2"; mockup approved):
 *  - the first approved Quotation fixes the exchange rate and every line's price and
 *    price check figures; the PI keeps the same rate;
 *  - after that no line is added, changed, copied or removed on the order page — not by
 *    the Super Admin either; the products table is read-only with the note and a link;
 *  - the "Products & prices" amendment: a price only goes down, quantities / sizes change,
 *    lines are added and removed; totals and the advance worked out; MD approval, the AMD
 *    document (PDF), the signed copy, activation (lines applied, stage kept, money follows);
 *  - the re-issue checklist; the document guard; the payment-terms AMD unchanged; the
 *    migration's back-fill of existing orders.
 * PHP twin: tests/Integration/PriceLockProductsAmendmentD342Test.php (same wording).
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
const amendmentRepository = require('../../src/repositories/amendmentRepository');
const departmentTaskRepository = require('../../src/repositories/departmentTaskRepository');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const pricingRepository = require('../../src/repositories/pricingRepository');
const productRepository = require('../../src/repositories/productRepository');
const productSupplierRepository = require('../../src/repositories/productSupplierRepository');
const amendmentService = require('../../src/services/amendmentService');
const documentGenerationService = require('../../src/services/documentGenerationService');
const fxRateService = require('../../src/services/fxRateService');
const orderLinePricing = require('../../src/services/orderLinePricing');
const priceLock = require('../../src/services/priceLock');
const pricingService = require('../../src/services/pricingService');
const productsAmendmentService = require('../../src/services/productsAmendmentService');
const { renderPdfFromHtml } = require('../../src/services/pdfRenderService');
const { createTestClient, createTestOrder, createTestFile, createTestDocument } = require('../support/fixtures');

const PASSWORD = 'JestPriceLock!1';
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
function text(html) {
  return String(html).replace(/<style[\s\S]*?<\/style>/g, ' ').replace(/<[^>]+>/g, ' ')
    .replace(/&amp;/g, '&').replace(/&nbsp;/g, ' ').replace(/&#39;/g, "'").replace(/&quot;/g, '"').replace(/\s+/g, ' ');
}

describe('Prices fixed by the approved Quotation; the Products & prices amendment (D-342)', () => {
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
    const email = `jest-d342-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
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

  /** A product priced per CBM (₹80,000 supplier price): the mockup's USD 1,950.00 and 1,900.00 are above its floor. */
  async function product() {
    const admin = await user('Admin');
    const id = await productRepository.create({ name: `D342 CHM Black P6 ${crypto.randomBytes(3).toString('hex')}`, specifications: 'Granite slab', hs_code: '68022310', reference_unit: 'CBM' }, admin.id);
    const s = await productSupplierRepository.create({ product_id: id, supplier_name: 'Test Supplier', fob_source: 'direct', fob_value: 80000, is_primary: 1 }, admin.id);
    await pricingRepository.setSupplierDates(s, todayYmd(), null);
    return id;
  }

  /** The mockup's order: three lines of CHM Black P6 in CBM at USD 1,950.00 (total USD 17,161.95), 40% advance. */
  async function mockupOrder() {
    const productId = await product();
    const orderId = await createTestOrder(await createTestClient());
    await db.execute('UPDATE orders SET advance_pct = 40, balance_pct = 60 WHERE id = :id', { id: orderId });
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

  async function approveQt(orderId) {
    const qt = await documentGenerationService.generate(orderId, 'QT', (await user('Admin')).id, null, false, true);
    await documentGenerationService.finalizeApproval(qt.document_id);
    return Number(qt.document_id);
  }

  async function received(orderId) {
    await db.execute('INSERT INTO order_payment_status (order_id, advance_amount) VALUES (:o, 6864.78) ON DUPLICATE KEY UPDATE advance_amount = 6864.78', { o: orderId });
  }

  /** The mockup's amendment: line 1 to 30 pieces, line 3 down to USD 1,900.00, a new 100 × 50 × 13 line of 20 pieces. */
  function mockupPost(ids, extra = {}) {
    const line = (l, w, t, pcs, price) => ({ length_cm: l, width_cm: w, thickness_cm: t, pieces: pcs, unit_price: price });
    const out = {
      line: {
        [ids[0]]: line('180', '80', '13', '30', '1950.00'),
        [ids[1]]: line('140', '70', '13', '25', '1950.00'),
        [ids[2]]: line('120', '60', '13', '10', '1900.00'),
      },
      new: { 0: { source_line_id: String(ids[0]), length_cm: '100', width_cm: '50', thickness_cm: '13', pieces: '20', unit_price: '1950.00' } },
    };
    for (const [id, v] of Object.entries(extra.line || {})) out.line[id] = { ...out.line[id], ...v };
    return out;
  }

  it('the first approved Quotation fixes the rate and every line\'s price and figures', async () => {
    const [orderId, ids] = await mockupOrder();
    expect(priceLock.isFixed(await orderRepository.find(orderId))).toBe(false);
    const rateNow = await fxRateService.rate(await pricingRepository.settings());
    const qtId = await approveQt(orderId);
    const order = await orderRepository.find(orderId);
    expect(order.prices_fixed_at).not.toBeNull();
    expect(Number(order.prices_fixed_by_document_id)).toBe(qtId);
    expect(Number(order.pricing_fx_rate_at_qt)).toBe(rateNow);
    const line = await orderProductRepository.find(ids[0]);
    expect(String(line.qt_fixed_unit_price)).toBe('1950.00');
    expect(Number(JSON.parse(line.price_snapshot).fx_rate)).toBe(rateNow);
    const floorBefore = (await orderLinePricing.forOrder(orderId)).lines[ids[0]].figures.floor;

    // A new rate after the approval changes nothing on this order — not its figures, not the PI's rate.
    await db.execute("UPDATE pricing_settings SET setting_value = 'manual' WHERE setting_key = 'fx_mode'");
    await db.execute("UPDATE pricing_settings SET setting_value = '99.5000' WHERE setting_key = 'pricing_fx_rate'");
    expect(await fxRateService.rate(await pricingRepository.settings())).toBe(99.5);
    expect((await orderLinePricing.forOrder(orderId)).lines[ids[0]].figures.floor).toBe(floorBefore);
    expect(await priceLock.fixAtQtApproval(orderId, qtId)).toBe(false);
    const pi = await documentGenerationService.generate(orderId, 'PI', (await user('Admin')).id, null, false, true);
    await documentGenerationService.finalizeApproval(pi.document_id);
    expect(Number((await orderRepository.find(orderId)).pricing_fx_rate_at_pi)).toBe(rateNow);
    const lineText = await fxRateService.orderLine(await orderRepository.find(orderId), (await orderLinePricing.forOrder(orderId)).lines);
    expect(lineText.startsWith(`Prices worked out at ₹${rateNow.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} per USD — fixed by the approved Quotation of ${todayText()}`)).toBe(true);
  });

  it('every line route is refused after the approval, even for the Super Admin', async () => {
    const [orderId, ids] = await mockupOrder();
    await approveQt(orderId);
    const agent = await login(await user('Admin', true));
    const expected = `Prices and products are fixed by the approved Quotation of ${todayText()} — change them through an Amendment (Order page › Amendments › Products & prices).`;
    const body = { description: 'CHM Black P6', quantity: '9', unit: 'CBM', unit_price: '2500.00', reason: 'Super Admin override attempt for the test' };
    for (const url of [`/orders/${orderId}/products`, `/orders/${orderId}/products/${ids[0]}`, `/orders/${orderId}/products/${ids[0]}/duplicate`, `/orders/${orderId}/products/${ids[1]}/delete`]) {
      expect(await post(agent, `/orders/${orderId}`, url, body)).toBe(expected);
    }
    expect((await orderProductRepository.forOrder(orderId)).length).toBe(3);
    expect(String((await orderProductRepository.find(ids[0])).unit_price)).toBe('1950.00');
    expect(String((await orderProductRepository.find(ids[0])).quantity)).toBe('4.680');
  });

  it('the order page shows the lines read-only with the note and link', async () => {
    const [orderId] = await mockupOrder();
    const agent = await login(await user('Admin'));
    expect((await agent.get(`/orders/${orderId}`)).text).toContain('data-open-panel="line-panel-new"');
    await approveQt(orderId);
    const html = (await agent.get(`/orders/${orderId}`)).text;
    expect(html).toContain('id="price-lock-note"');
    expect(html).toContain(`Prices and products are fixed by the approved Quotation of ${todayText()} — change them through an Amendment (Order page › Amendments › Products &amp; prices).`);
    expect(html).toContain(`/orders/${orderId}/amendments#products-amendment`);
    expect(html).not.toContain('data-open-panel="line-panel-new"');
    expect(html).not.toContain(`/orders/${orderId}/products/`);
  });

  it('a price can only go down; the mockup totals and the advance are worked out', async () => {
    const [orderId, ids] = await mockupOrder();
    await approveQt(orderId);
    await received(orderId);
    const order = await orderRepository.find(orderId);

    const up = await productsAmendmentService.draft(order, mockupPost(ids, { line: { [ids[0]]: { unit_price: '2000.00' } } }));
    expect(up.errors).toContain('Line 1: USD 1,950.00 is the price the buyer accepted in the Quotation — a price can only go down, with the MD\'s approval.');

    const d = await productsAmendmentService.draft(order, mockupPost(ids));
    expect(d.errors).toEqual([]);
    const byNo = {};
    for (const l of d.lines) byNo[l.line_no] = l;
    expect(byNo[1].changes).toEqual(['quantity']);
    expect(byNo[1].new_quantity).toBe(5.616);
    expect(byNo[1].new_line_total).toBe(10951.2);
    expect(byNo[2].change_kind).toBe('none');
    expect(byNo[3].changes).toEqual(['price']);
    expect(byNo[3].price_lowered).toBe(true);
    expect(byNo[3].new_line_total).toBe(1778.4);
    expect([byNo[4].change_kind, byNo[4].new_quantity, byNo[4].new_line_total]).toEqual(['add', 1.3, 2535]);
    const t = d.totals;
    expect([t.old_total, t.new_total, t.old_advance, t.new_advance, t.old_balance, t.new_balance]).toEqual([17161.95, 21475.35, 6864.78, 8590.14, 10297.17, 14610.57]);
    expect(productsAmendmentService.signedDifference(t)).toBe('+ USD 4,313.40');
    expect(productsAmendmentService.advanceNote(t)).toBe('advance already received USD 6,864.78; the extra USD 1,725.36 is added to the balance.');

    const small = await productsAmendmentService.draft(order, { line: { [ids[0]]: { remove: '1' }, [ids[1]]: { remove: '1' } } });
    expect(small.errors).toEqual([]);
    expect(small.totals.new_total).toBe(1825.2);
    expect(productsAmendmentService.advanceNote(small.totals)).toBe('advance already received USD 6,864.78 is USD 5,039.58 more than the new total — it shows as excess.');
    expect((await productsAmendmentService.draft(order, {})).errors).toContain('Nothing was changed — change a line, add one or remove one first.');
  });

  it('the whole flow applies the lines, keeps the stage and shows the checklist', async () => {
    const [orderId, ids] = await mockupOrder();
    await approveQt(orderId);
    const admin = await user('Admin');
    const pi = await documentGenerationService.generate(orderId, 'PI', admin.id, null, false, true);
    await documentGenerationService.finalizeApproval(pi.document_id);
    await db.execute("UPDATE orders SET buyers_po_ref = 'BUYER-PO-77', buyer_po_received_on = CURDATE() WHERE id = :o", { o: orderId });
    await received(orderId);
    const before = await orderRepository.find(orderId);

    // Raised through the screen; a reason is required.
    const maker = await user('Export Executive');
    const makerAgent = await login(admin);
    const newPage = await makerAgent.get(`/orders/${orderId}/amendments/products/new`);
    const csrf = newPage.text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    const page = await makerAgent.post(`/orders/${orderId}/amendments/products`).type('form').send({ _csrf: csrf, ...mockupPost(ids), reason: 'short', step: 'send' });
    expect(page.text).toContain('Reason for the amendment: Reason must be at least 10 characters');
    const [amendmentId, errors] = await productsAmendmentService.create(orderId, mockupPost(ids), 'Buyer asked for 5 more slabs of 180 × 80 on 14 Nov (e-mail); discount on line 3 agreed by MD', maker.id);
    expect(errors).toEqual([]);
    expect((await amendmentRepository.find(amendmentId)).amendment_type).toBe('products');
    expect(await productsAmendmentService.cannotStart(await orderRepository.find(orderId))).not.toBeNull();

    const md = await user('Managing Director');
    await amendmentService.approveByMd(amendmentId, md.id);
    await amendmentService.generateDocument(amendmentId, md.id);
    const calls = renderPdfFromHtml.mock.calls;
    const doc = text(calls[calls.length - 1][0]);
    for (const s of ['AMENDMENT — PRODUCTS & PRICES', '1. PRODUCTS & PRICES — AS AMENDED', 'Size (cm)', 'Price (USD) per CBM', 'Total (USD)',
      '180×80×13', '10,951.20', '9,126.00', '1,900.00', '1,950.00', '100×50×13', 'New line', 'Quantity', 'Price',
      'Order total (was USD 17,161.95)', '21,475.35', '2. EFFECT ON PAYMENT',
      'Advance received USD 6,864.78 stays credited. Balance payable as per the agreed payment terms: USD 14,610.57. All other terms of the Quotation / Proforma Invoice remain unchanged.',
      '3. ACCEPTANCE', 'Authorised Signatory Name', 'Company Stamp / Seal']) {
      expect(doc).toContain(s);
    }
    expect(doc).not.toContain('AMENDED PAYMENT TERMS');

    await createTestDocument(orderId, 'SUPPO', 'approved');
    // Everything issued so far was issued before the signed copy came back.
    await db.execute('UPDATE documents SET generated_at = generated_at - INTERVAL 1 HOUR WHERE order_id = :o', { o: orderId });
    await amendmentService.attachSignedCopyAndActivate(amendmentId, await createTestFile(orderId, null), maker.id);
    expect(Number((await amendmentRepository.find(amendmentId)).supplier_po_revision_needed)).toBe(1);
    const tasks = (await departmentTaskRepository.open(['PROC'])).filter((t) => t.task_key === 'revise_supplier_po' && Number(t.order_id) === orderId);
    expect(tasks.length).toBe(1);
    const after = await orderRepository.find(orderId);
    expect(after.current_stage_id).toBe(before.current_stage_id);
    expect(String(after.pricing_fx_rate_at_qt)).toBe(String(before.pricing_fx_rate_at_qt));
    const lines = {};
    for (const l of await orderProductRepository.forOrder(orderId)) lines[l.line_no] = l;
    expect([String(lines[1].quantity), Number(lines[1].pieces_count), String(lines[1].fob_value)]).toEqual(['5.616', 30, '10951.20']);
    expect([String(lines[3].unit_price), String(lines[3].qt_fixed_unit_price)]).toEqual(['1900.00', '1900.00']);
    expect([String(lines[4].quantity), String(lines[4].qt_fixed_unit_price), String(lines[4].dimensions)]).toEqual(['1.300', '1950.00', '100 × 50 × 13 cm']);
    expect(lines[4].price_snapshot).toBeTruthy();
    expect(Number(await orderProductRepository.totalFobValue(orderId))).toBe(21475.35);
    expect(orderPaymentStatusRepository.balanceDue(21475.35, 40, await orderPaymentStatusRepository.find(orderId))).toBe(14610.57);
    expect(await priceLock.documentGuard(orderId, 'PI')).toBeNull();

    const list = await productsAmendmentService.checklist(after);
    expect(list).not.toBeNull();
    const ref = (await amendmentRepository.find(amendmentId)).amendment_reference;
    expect(list.title).toBe(`Amendment ${ref} activated — re-issue checklist`);
    const rows = {};
    for (const r of list.items) rows[r.document] = r;
    expect(rows['Quotation Rev.00'].todo).toBe('nothing — the signed amendment and the Quotation together are the agreement');
    expect([rows['Proforma Invoice Rev.00'].status, rows['Proforma Invoice Rev.00'].todo]).toEqual([`Outdated by ${ref}`, 'Generate revised Proforma Invoice (Rev.01)']);
    expect(rows['Buyer PO (buyer\'s)'].todo).toBe('Ask the buyer for a revised PO, or tick "The signed amendment amends the Buyer PO"');
    expect(rows['Commercial Invoice / Packing List'].todo).toBe('will use the amended figures');
    expect([rows['Supplier PO'].status, rows['Supplier PO'].todo]).toEqual(['Quantities changed', 'Procurement: revise the Supplier PO (task sent to Procurement)']);
    expect(list.money).toContain('Money worked out again: total USD 21,475.35 · advance received USD 6,864.78 · balance now USD 14,610.57 (was USD 10,297.17).');

    // Items disappear once done: the revised PI, the revised Supplier PO, the Buyer PO tick.
    await documentGenerationService.generate(orderId, 'PI', admin.id, null, false, true);
    await db.execute('UPDATE documents SET revision_number = 1 WHERE id = :id', { id: await createTestDocument(orderId, 'SUPPO', 'draft') });
    expect(await productsAmendmentService.tickBuyerPo(orderId, amendmentId, admin.id)).toBeNull();
    expect(await productsAmendmentService.checklist(await orderRepository.find(orderId))).toBeNull();
  });

  it('the document guard refuses a price above the fixed one', async () => {
    const [orderId, ids] = await mockupOrder();
    await approveQt(orderId);
    await db.execute("UPDATE order_products SET unit_price = '2000.00' WHERE id = :id", { id: ids[1] });
    await expect(documentGenerationService.generate(orderId, 'PI', (await user('Admin')).id, null, false, true))
      .rejects.toThrow('Line 2: USD 2,000.00 is higher than USD 1,950.00, the price the buyer accepted in the approved Quotation — the Proforma Invoice cannot be generated. Prices change only through an Amendment (Order page › Amendments › Products & prices).');
    await db.execute("UPDATE order_products SET unit_price = '1950.00', qt_fixed_unit_price = NULL WHERE id = :id", { id: ids[1] });
    expect(await priceLock.documentGuard(orderId, 'CI')).toBe('Line 2 is not in the approved Quotation — the Commercial Invoice cannot be generated. Lines are added only through an Amendment (Order page › Amendments › Products & prices).');
    expect(await priceLock.documentGuard(orderId, 'QT')).toBeNull();
  });

  it('the payment-terms amendment prints exactly as before', async () => {
    const [orderId] = await mockupOrder();
    const maker = await user('Export Executive');
    const id = await amendmentService.createRequest(orderId, 'Buyer asked for 30 days after BL', 'importer', 30, null, 'Balance within 30 days of BL', 'B_AGAINST_BL', 30, null, null, maker.id);
    expect((await amendmentRepository.find(id)).amendment_type).toBe('payment_terms');
    await amendmentService.approveByMd(id, (await user('Managing Director')).id);
    await amendmentService.generateDocument(id, maker.id);
    const calls = renderPdfFromHtml.mock.calls;
    const doc = text(calls[calls.length - 1][0]);
    for (const s of ['PAYMENT TERMS AMENDMENT AGREEMENT', '1. PARTIES TO THIS AMENDMENT', '2. ORIGINAL DOCUMENT REFERENCE', '4. AMENDED PAYMENT TERMS — REPLACING SECTION 3 ABOVE', '8. PAYMENT INSTRUCTIONS UNDER AMENDED TERMS']) {
      expect(doc).toContain(s);
    }
    expect(doc).not.toContain('PRODUCTS & PRICES');
    expect(fs.readFileSync(path.join(__dirname, '../../templates/AMD/payment_terms_amendment.njk'), 'utf8')).not.toContain('products_amendment');
  });

  it('the migration back-fills orders with an approved Quotation', async () => {
    const [fixedOrder, ids] = await mockupOrder();
    const qt = await createTestDocument(fixedOrder, 'QT', 'approved');
    await db.execute('UPDATE orders SET pricing_fx_rate_at_pi = 84.2500 WHERE id = :o', { o: fixedOrder });
    const [openOrder] = await mockupOrder();
    await createTestDocument(openOrder, 'QT', 'draft');
    const sql = fs.readFileSync(path.join(__dirname, '../../../../docs/migrations/2026-11-12h-price-lock-and-products-amendment.sql'), 'utf8');
    const args = ['-h', env.get('DB_HOST', '127.0.0.1'), '-P', String(env.get('DB_PORT', '3306')), '-u', env.get('DB_USERNAME'), `-p${env.get('DB_PASSWORD', '')}`, env.get('DB_DATABASE')];
    for (let run = 0; run < 2; run++) execFileSync('mysql', args, { input: sql });
    const o = await orderRepository.find(fixedOrder);
    expect([Number(o.prices_fixed_by_document_id), String(o.pricing_fx_rate_at_qt)]).toEqual([qt, '84.2500']);
    expect(o.prices_fixed_at).not.toBeNull();
    expect(String((await orderProductRepository.find(ids[0])).qt_fixed_unit_price)).toBe('1950.00');
    expect((await orderRepository.find(openOrder)).prices_fixed_at).toBeNull();
  });
});
