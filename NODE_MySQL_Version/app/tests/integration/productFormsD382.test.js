'use strict';

jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn(async () => Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const pricingRepository = require('../../src/repositories/pricingRepository');
const productRepository = require('../../src/repositories/productRepository');
const productSupplierRepository = require('../../src/repositories/productSupplierRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const pricingService = require('../../src/services/pricingService');
const permissionService = require('../../src/services/permissionService');
const productForms = require('../../src/services/productForms');
const productSpecs = require('../../src/services/productSpecs');
const { createTestClient, createTestOrder, configureProductForQuoting, baseSpecJson, soldAs, allowHandTypedGoodsLines } = require('../support/fixtures');

/**
 * Port of ProductFormsD382Test.php — owner decisions D-382 … D-385 and D-389 (18 Nov 2026, mockups
 * approved, plan confirmed twice): forms of stone, the specification list that sets the HS code, the
 * finishes per form, a product's "Sold as" ticks, an order line's Sold as with its locked HS code
 * (MD / ED may change it with a reason), and the option order (Monument first in Size / format).
 *
 * Every row this file creates carries "Jest D-382" in its name or reason and is removed after each
 * test (forms, specifications, finish ticks, product ticks); products and orders are the usual
 * disposable test rows.
 */
describe('Forms of stone, Specifications (HS), Sold as and the option order (D-382 … D-385, D-389)', () => {
  const PASSWORD = 'JestProductForms!1';
  const REASON = 'Jest D-382 test change';
  let finishMap = {};

  beforeEach(async () => {
    finishMap = await productForms.finishFormMap();
  });

  afterEach(async () => {
    await db.execute("DELETE x FROM product_hs_specifications x JOIN hs_specifications s ON s.id = x.hs_spec_id WHERE s.name LIKE 'Jest D-382%'");
    await db.execute("UPDATE order_products op JOIN hs_specifications s ON s.id = op.hs_spec_id SET op.hs_spec_id = NULL WHERE s.name LIKE 'Jest D-382%'");
    await db.execute("DELETE FROM hs_specifications WHERE name LIKE 'Jest D-382%'");
    await db.execute("DELETE FROM product_forms WHERE name LIKE 'Jest D-382%'");
    // The seeded finish ticks back as they were.
    await db.execute('DELETE FROM surface_finish_forms');
    for (const [dd, forms] of Object.entries(finishMap)) await productForms.setFinishForms(Number(dd), forms);
    for (const [k, v] of Object.entries(pricingService.DEFAULTS)) {
      await db.execute('UPDATE pricing_settings SET setting_value = :v WHERE setting_key = :k', { k, v });
    }
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function user(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-d382-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const id = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    )).insertId;
    return { id, email, roleId: role.id };
  }

  async function login(u) {
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: u.email, password: PASSWORD });
    return agent;
  }

  const unescape = (t) => t.replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&');

  /** Posts a form and returns the flash message shown after the redirect. */
  async function post(agent, from, url, body) {
    const csrf = (await agent.get(from)).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    const res = await agent.post(url).type('form').send({ _csrf: csrf, ...body });
    const m = (await agent.get(res.headers.location)).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/);
    return m ? unescape(m[2].trim()) : '';
  }

  async function value(sql, params = {}) {
    const row = await db.queryOne(sql, params);
    return row ? Object.values(row)[0] : null;
  }

  const specId = async (name) => Number((await soldAs(name)).hs_spec_id);

  /** The base options with the Surface finish set to this finish (spec_json). */
  async function withFinish(productId, finish) {
    const sel = JSON.parse(await baseSpecJson(productId));
    for (const s of await productSpecs.offered(await productRepository.find(productId))) {
      if ((s.list_key ?? null) !== 'surface_finish') continue;
      for (const o of s.options) if (o.name === finish) sel[String(s.id)] = Number(o.id);
    }
    return JSON.stringify(sel);
  }

  function today() {
    const d = new Date();
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
  }

  /** A configured, quotable product (₹80,000 per CBM) with no Sold as ticked. */
  async function product(refUnit = 'CBM') {
    const admin = await user('Admin');
    const id = await productRepository.create({ name: `Jest D-382 granite ${crypto.randomBytes(3).toString('hex')}`, specifications: 'Granite', hs_code: '68022310', reference_unit: refUnit }, admin.id);
    const s = await productSupplierRepository.create({ product_id: id, supplier_name: 'D-382 Quarry', location: null, contact_person: null, contact_phone: null, contact_email: null,
      fob_source: 'direct', fob_value: 80000, factory_cost: null, transportation_cost: null, packing_cost: null, loading_cost: null, cha_cost: null, is_primary: 1, notes: null }, admin.id);
    await pricingRepository.setSupplierDates(s, today(), null);
    await configureProductForQuoting(id, admin.id);
    return id;
  }

  // ------------------------------------------------------------------ seeds and migration

  it('the seed holds the forms, specifications, finishes and the permission', async () => {
    expect((await productForms.forms(true)).map((f) => f.name))
      .toEqual(['Monument slab', 'Gangsaw slab', 'Cut-to-size slab', 'Raw block', 'Sawn block', 'Tile', 'Paving stone', 'Kerbs, setts, cobbles']);
    const raw = await productForms.findForm(Number(await value("SELECT id FROM product_forms WHERE code = 'raw_block'")));
    expect(productForms.formUnits(raw)).toEqual(['CBM', 'TON']);
    expect([raw.spec_group, raw.default_finish, raw.default_unit]).toEqual(['block', 'Roughly trimmed', 'CBM']);
    const codes = {};
    for (const s of await productForms.specifications(true)) codes[s.name] = [s.form_name, s.hs_code];
    expect(codes).toEqual({
      'Raw block': ['Raw block', '25161100'], 'Sawn block': ['Sawn block', '25161200'], 'Gangsaw slab': ['Gangsaw slab', '68022310'],
      'Random slab — irregular': ['Gangsaw slab', '68022390'], 'Cut-to-size slab': ['Cut-to-size slab', '68022310'], Tile: ['Tile', '68022310'],
      'Monument slab': ['Monument slab', '68029300'], 'Monument — complete set / tombstone': ['Monument slab', '68029300'],
      'Paving stone': ['Paving stone', '68010000'], 'Kerbs, setts, cobbles': ['Kerbs, setts, cobbles', '68010000'],
    });
    // Finishes: Roughly trimmed on Raw block only, Sawn on Sawn block only, the others on every form that is not a block.
    expect(((await productForms.finishesForForm(Number(raw.id))) || []).map((f) => f.name)).toEqual(['Roughly trimmed']);
    const sawn = Number(await value("SELECT id FROM product_forms WHERE code = 'sawn_block'"));
    expect(((await productForms.finishesForForm(sawn)) || []).map((f) => f.name)).toEqual(['Sawn']);
    const tile = ((await productForms.finishesForForm(Number(await value("SELECT id FROM product_forms WHERE code = 'tile'")))) || []).map((f) => f.name);
    expect(tile).toContain('Natural Polish');
    expect(tile).not.toContain('Roughly trimmed');
    expect(tile).not.toContain('Sawn');
    // The new finishes have their Surface finish option at no price change (D-357).
    expect(Number(await value(`SELECT COUNT(*) AS n FROM spec_options o JOIN spec_definitions s ON s.id = o.spec_id AND s.list_key = 'surface_finish' AND s.product_id IS NULL
      JOIN product_categories c ON c.id = s.category_id AND c.is_system = 1 WHERE o.name IN ('Roughly trimmed', 'Sawn') AND o.change_type = 'none' AND o.dropdown_option_id IS NOT NULL`))).toBe(2);
    expect(await value("SELECT description FROM hs_codes WHERE code = '68022310'")).toBe('Granite blocks or tiles');
    // MariaDB quotes the default ('68029300'), MySQL does not.
    expect(String(await value("SELECT COLUMN_DEFAULT AS d FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'order_products' AND column_name = 'hs_code'")).replace(/^'|'$/g, ''))
      .toBe('68029300');
    // The permission: MD and ED, not Admin or staff.
    for (const [role, can] of [['Managing Director', true], ['Executive Director', true], ['Admin', false], ['Export Executive', false]]) {
      const u = await user(role);
      const permissions = await permissionService.load(u.id, u.roleId);
      expect(productForms.canOverride({ user: { id: u.id }, permissions })).toBe(can);
    }
    // D-389: Monument (cut to size) first in Size / format, the others keep their order.
    const order = (await db.query(`SELECT o.name FROM spec_options o JOIN spec_definitions s ON s.id = o.spec_id AND s.name = 'Size / format' AND s.product_id IS NULL
      JOIN product_categories c ON c.id = s.category_id AND c.is_system = 1 WHERE o.product_id IS NULL ORDER BY o.sort_order, o.id`)).map((r) => r.name);
    expect(order[0]).toBe('Monument (cut to size)');
    expect(order.slice(1, 3)).toEqual(['Gangsaw slab', 'Cutter slab']);
  });

  // ------------------------------------------------------------------ Forms of stone

  it('forms are added, changed and switched off with a reason — never deleted once used', async () => {
    const agent = await login(await user('Managing Director'));
    const form = { name: 'Jest D-382 cut block', units: ['CBM', 'TON'], default_unit: 'TON', spec_group: 'block', default_finish: 'Sawn', sort_order: '30', is_active: '1' };

    expect(await post(agent, '/pricing/forms', '/pricing/forms', { ...form, reason: 'short' })).toContain('Reason must be at least 10 characters');
    expect(await value("SELECT id FROM product_forms WHERE name = 'Jest D-382 cut block'")).toBeNull();

    expect(await post(agent, '/pricing/forms', '/pricing/forms', { ...form, default_unit: 'PCS', reason: REASON })).toContain('Starts on: choose one of the ticked units.');

    expect(await post(agent, '/pricing/forms', '/pricing/forms', { ...form, reason: REASON })).toBe('Form "Jest D-382 cut block" added.');
    const id = Number(await value("SELECT id FROM product_forms WHERE name = 'Jest D-382 cut block'"));
    expect(await value('SELECT units FROM product_forms WHERE id = :id', { id })).toBe('CBM,TON');
    expect(await value("SELECT reason FROM audit_log WHERE action_type = 'PRODUCT_FORM_CHANGED' AND entity_id = :id ORDER BY id DESC LIMIT 1", { id })).toBe(REASON);

    // Used by a specification: never deleted, switched off instead.
    expect(await post(agent, '/products/hs-specifications', '/products/hs-specifications', {
      name: 'Jest D-382 cut block spec', form_id: String(id), usual_finish: 'Sawn', hs_code: '25161200', sort_order: '40', is_active: '1', reason: REASON,
    })).toContain('added');
    expect(await post(agent, '/pricing/forms', `/pricing/forms/${id}/delete`, { reason: REASON })).toContain('is used by a specification — switch it off instead');
    expect(await productForms.findForm(id)).not.toBeNull();

    const off = { ...form };
    delete off.is_active;
    await post(agent, '/pricing/forms', `/pricing/forms/${id}`, { ...off, reason: REASON });
    expect(String(await value('SELECT is_active FROM product_forms WHERE id = :id', { id }))).toBe('0');
    expect(String(await value("SELECT new_value FROM audit_log WHERE action_type = 'PRODUCT_FORM_CHANGED' AND entity_id = :id ORDER BY id DESC LIMIT 1", { id }))).toContain('switched off');
    expect((await productForms.forms(true)).map((f) => f.name)).not.toContain('Jest D-382 cut block');

    // An unused form is deleted (with a reason).
    await post(agent, '/pricing/forms', '/pricing/forms', { ...form, name: 'Jest D-382 spare form', reason: REASON });
    const spare = Number(await value("SELECT id FROM product_forms WHERE name = 'Jest D-382 spare form'"));
    expect(await post(agent, '/pricing/forms', `/pricing/forms/${spare}/delete`, { reason: REASON })).toBe('Form "Jest D-382 spare form" deleted.');
    expect(await productForms.findForm(spare)).toBeNull();

    // The page lists them, with the add form (placeholders, Choose…).
    const page = (await agent.get('/pricing/forms')).text;
    expect(page).toContain('Forms of stone');
    expect(page).toContain('Jest D-382 cut block');
    expect(page).toContain('placeholder="e.g. Raw block"');
  });

  // ------------------------------------------------------------------ Specifications (HS)

  it('a specification needs an active HS master code and is switched off once used', async () => {
    const agent = await login(await user('Managing Director'));
    const tileForm = String(await value("SELECT id FROM product_forms WHERE code = 'tile'"));
    const spec = { name: 'Jest D-382 honed tile', form_id: tileForm, usual_finish: 'Honed', sort_order: '50', is_active: '1', reason: REASON };
    const url = '/products/hs-specifications';

    expect(await post(agent, url, url, { ...spec, hs_code: '6802.23' })).toContain('HS code: 6 or 8 digits, no dots');
    expect(await post(agent, url, url, { ...spec, hs_code: '99999999' })).toContain('HS code 99999999 is not an active code of the HS Code Master');
    expect(await value("SELECT id FROM hs_specifications WHERE name = 'Jest D-382 honed tile'")).toBeNull();

    expect(await post(agent, url, url, { ...spec, hs_code: '68022310' })).toContain('Specification "Jest D-382 honed tile" added');
    const id = Number(await value("SELECT id FROM hs_specifications WHERE name = 'Jest D-382 honed tile'"));
    expect(await value("SELECT action_type FROM audit_log WHERE entity_type = 'hs_specifications' AND entity_id = :id ORDER BY id DESC LIMIT 1", { id })).toBe('HS_SPECIFICATION_CHANGED');

    // Ticked on a product: used — deleting is refused.
    const p = await product();
    await productForms.setProductSpecs(p, [id]);
    expect(await post(agent, url, `${url}/${id}/delete`, { reason: REASON })).toContain('switch it off instead');
    expect(await productForms.findSpecification(id)).not.toBeNull();

    const page = (await agent.get(url)).text;
    expect(page).toContain('Specifications (HS)');
    expect(page).toContain('Jest D-382 honed tile');
    expect(page).toContain('<option value="">Choose…</option>');
  });

  // ------------------------------------------------------------------ a product's Sold as

  it('Sold as ticks need a reason and decide which category specifications apply', async () => {
    const md = await user('Managing Director');
    let agent = await login(md);
    const pid = await product();
    await db.execute("UPDATE catalog_products SET forms = 'tile' WHERE id = :id", { id: pid });
    const p = await productRepository.find(pid);
    // Nothing ticked: the old forms value still applies.
    expect(await productSpecs.productForms(p)).toEqual(['tile']);
    const page = `/products/${pid}/specifications`;
    const to = `/products/${pid}/sold-as`;

    expect(await post(agent, page, to, { reason: REASON })).toContain('Tick at least one specification the product is sold as.');

    const ticks = { [await specId('Raw block')]: '1', [await specId('Gangsaw slab')]: '1' };
    expect(await post(agent, page, to, { sold_as: ticks, reason: 'short' })).toContain('Reason must be at least 10 characters');
    expect(await productForms.productSpecIds(pid)).toEqual([]);

    expect(await post(agent, page, to, { sold_as: ticks, reason: REASON })).toContain('Sold as saved');
    const ids = [await specId('Raw block'), await specId('Gangsaw slab')].sort((a, b) => a - b);
    expect(await productForms.productSpecIds(pid)).toEqual(ids);
    // The product's forms are now the groups of its ticked specifications' forms (block, slab), not "tile".
    expect(await productSpecs.productForms(p)).toEqual(['block', 'slab']);
    const names = (await productSpecs.tree(p)).map((s) => s.name);
    expect(names).toContain('Size / format'); // applies to slab and block
    expect(names).not.toContain('Calibration'); // tiles only
    expect(await value("SELECT action_type FROM audit_log WHERE entity_type = 'catalog_products' AND entity_id = :id ORDER BY id DESC LIMIT 1", { id: pid })).toBe('PRODUCT_SOLD_AS_CHANGED');
    expect((await productForms.soldAsByProduct([pid]))[pid].map((s) => s.name)).toEqual(['Raw block', 'Gangsaw slab']);

    // The Specifications page shows the Sold as ticks (no more fixed "Forms" ticks); the Products list shows "Sold as".
    const specPage = (await agent.get(page)).text;
    expect(specPage).toContain('id="sold-as"');
    expect(specPage).not.toContain('name="forms[]"');
    const listUrl = `/products?status=all&q=${encodeURIComponent(p.name)}`;
    expect((await agent.get(listUrl)).text).toContain('Sold as: Raw block, Gangsaw slab');
    // Owner hard rule (1 Nov 2026): the same in the Kanban template (the Products list has no board — one page for both).
    const kanban = await require('../../src/repositories/uiTemplateRepository').findByKey('kanban');
    await require('../../src/repositories/userRepository').updateUiTemplatePreference(md.id, Number(kanban.id));
    agent = await login(md);
    expect((await agent.get(listUrl)).text).toContain('Sold as: Raw block, Gangsaw slab');
    expect((await agent.get(page)).text).toContain('id="sold-as"');
    // The product edit form calls the product's HS code the fallback.
    expect((await agent.get(`/products/${pid}/edit`)).text).toContain('HS Code (fallback) *');
  });

  // ------------------------------------------------------------------ finishes per form

  it('finish ticks per form are saved with the list\'s reason', async () => {
    const agent = await login(await user('Admin'));
    const flamed = Number(await value("SELECT id FROM dropdown_options WHERE list_key = 'surface_finish' AND option_value = 'Flamed'"));
    const raw = Number(await value("SELECT id FROM product_forms WHERE code = 'raw_block'"));
    const rows = await db.query("SELECT id, option_value, sort_order, is_active FROM dropdown_options WHERE list_key = 'surface_finish'");
    const base = { finish_forms_present: '1', option_value: {}, sort_order: {}, is_active: {}, finish_forms: {} };
    for (const r of rows) {
      const rid = Number(r.id);
      base.option_value[rid] = r.option_value;
      base.sort_order[rid] = String(r.sort_order);
      if (Number(r.is_active) === 1) base.is_active[rid] = '1';
      for (const f of finishMap[rid] || []) (base.finish_forms[rid] = base.finish_forms[rid] || {})[f] = '1';
    }
    (base.finish_forms[flamed] = base.finish_forms[flamed] || {})[raw] = '1'; // Flamed now also on Raw block
    const page = '/admin/dropdown-options';
    const to = '/admin/dropdown-options/surface_finish/update';

    expect(await post(agent, page, to, base)).toContain('Offered for forms: A reason is required');
    expect((await productForms.finishFormMap())[flamed] || []).not.toContain(raw);

    await post(agent, page, to, { ...base, reason: REASON });
    expect((await productForms.finishFormMap())[flamed]).toContain(raw);
    expect(((await productForms.finishesForForm(raw)) || []).map((f) => f.name)).toEqual(['Flamed', 'Roughly trimmed']); // in the list order
    expect(await value("SELECT reason FROM audit_log WHERE action_type = 'FINISH_FORMS_CHANGED' AND entity_id = :id ORDER BY id DESC LIMIT 1", { id: flamed })).toBe(REASON);

    const html = (await agent.get(page)).text;
    expect(html).toContain('Offered for forms');
    expect(html).toContain(`name="finish_forms[${flamed}][${raw}]" value="1" checked`);
  });

  // ------------------------------------------------------------------ order lines

  it('a line picked on a USD order needs its Sold as, which locks the HS code and the units', async () => {
    const pid = await product();
    await productForms.setProductSpecs(pid, [await specId('Raw block'), await specId('Gangsaw slab')]);
    const sales = await user('Export Executive');
    const md = await user('Managing Director');
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [sales.id, md.id]);
    const salesAgent = await login(sales);
    const page = `/orders/${orderId}`;
    const add = `/orders/${orderId}/products`;
    const rawId = String(await specId('Raw block'));
    const base = { catalog_product_id: String(pid), description: 'D-382 raw block', quantity: '25', unit: 'TON', line_type: 'goods',
      hs_code: '68022310', spec_json: await withFinish(pid, 'Roughly trimmed') };

    // No Sold as: refused.
    expect(await post(salesAgent, page, add, base)).toBe("Line 1: choose what the product is sold as (Sold as) — it sets the line's HS code.");
    // A specification the product is not sold as: refused.
    expect(await post(salesAgent, page, add, { ...base, hs_spec_id: String(await specId('Tile')) })).toContain('is not sold as "Tile" — choose one of its Sold as.');
    // A unit the form is not sold in: refused.
    expect(await post(salesAgent, page, add, { ...base, hs_spec_id: rawId, unit: 'SQM' })).toBe('Line 1: Raw block is sold in CBM, TON — choose one of these units.');
    // A finish the form does not offer: refused.
    expect(await post(salesAgent, page, add, { ...base, hs_spec_id: rawId, spec_json: await withFinish(pid, 'Natural Polish') }))
      .toBe('Line 1: the finish "Natural Polish" is not offered for Raw block — choose another Surface finish.');

    // Raw block in tonnes: the HS code comes from the specification.
    expect(await post(salesAgent, page, add, { ...base, hs_spec_id: rawId, hs_code: '' })).toContain('Product line added');
    let line = (await orderProductRepository.forOrder(orderId))[0];
    expect([line.hs_code, Number(line.hs_spec_id), line.hs_override_reason, line.unit]).toEqual(['25161100', Number(rawId), null, 'TON']);
    expect(await productForms.formNameForLine(line)).toBe('Raw block');
    expect(await productForms.formNameForOrderLines(orderId)).toEqual({ [line.id]: 'Raw block' });
    const edit = `/orders/${orderId}/products/${line.id}`;

    // Staff may not change the HS code from the specification.
    expect(await post(salesAgent, page, edit, { ...base, hs_spec_id: rawId, hs_code: '25161200', hs_override_reason: 'Customs want the sawn block code' }))
      .toBe('Line 1: the HS code comes from its Sold as ("Raw block": 25161100) — only the MD or the ED can change it.');

    // The MD may, with a reason (reasonValidator) and an active master code; audited.
    const mdAgent = await login(md);
    expect(await post(mdAgent, page, edit, { ...base, hs_spec_id: rawId, hs_code: '25161200', hs_override_reason: 'short' }))
      .toBe('Line 1: give the reason for changing the HS code from 25161100 (at least 10 characters).');
    expect(await post(mdAgent, page, edit, { ...base, hs_spec_id: rawId, hs_code: '12345678', hs_override_reason: 'Customs want another code' }))
      .toBe('Line 1: HS code "12345678" is not an active code of the HS Code Master.');
    expect(await post(mdAgent, page, edit, { ...base, hs_spec_id: rawId, hs_code: '25161200', hs_override_reason: 'Customs want the sawn block code' })).toContain('Product line updated');
    line = await orderProductRepository.find(Number(line.id));
    expect([line.hs_code, line.hs_override_reason]).toEqual(['25161200', 'Customs want the sawn block code']);
    const audit = await db.queryOne("SELECT old_value, new_value, reason FROM audit_log WHERE action_type = 'HS_CODE_OVERRIDDEN' AND entity_id = :id ORDER BY id DESC LIMIT 1", { id: Number(line.id) });
    expect([audit.old_value, audit.new_value, audit.reason]).toEqual(['Line 1 (Raw block): 25161100', 'Line 1 (Raw block): 25161200', 'Customs want the sawn block code']);

    // Staff then edit the line (the MD's code unchanged): it stays, nothing refused.
    expect(await post(salesAgent, page, edit, { ...base, hs_spec_id: rawId, hs_code: '25161200', quantity: '26' })).toContain('Product line updated');
    const kept = await orderProductRepository.find(Number(line.id));
    expect([kept.hs_code, kept.hs_override_reason]).toEqual(['25161200', 'Customs want the sawn block code']);

    // A duplicated line copies the Sold as, the HS code and the reason.
    await post(salesAgent, page, `${edit}/duplicate`, {});
    const copy = (await orderProductRepository.forOrder(orderId))[1];
    expect([Number(copy.hs_spec_id), copy.hs_code, copy.hs_override_reason]).toEqual([Number(rawId), '25161200', 'Customs want the sawn block code']);

    // The order page shows the Sold as and where the HS code comes from, and offers Sold as in the panel.
    const html = unescape((await mdAgent.get(page)).text);
    expect(html).toContain('Sold as: <b>Raw block</b>');
    expect(html).toContain('changed by MD/ED — Customs want the sawn block code');
    expect(html).toContain('class="sold-as-select"');
    // After a change the specification's own code shows beside it (owner 18 Nov 2026).
    expect(html).toContain('changed by MD/ED (specification: 25161100)');
    // The MD gets "Change HS code" beside the HS line; staff without the permission do not.
    expect(html).toContain('<button type="button" class="btn-link" data-change-hs>Change HS code</button>');
    const staffHtml = unescape((await salesAgent.get(page)).text);
    expect(staffHtml).toContain('class="sold-as-select"');
    expect(staffHtml).not.toContain('data-change-hs>Change HS code</button>');
    expect(html).toContain('data-sold-as="');
    expect(html).toContain('data-unit-kind="TON"');
  });

  it('with a Sold as, what the line needs follows its unit — D-348 stays for lines without', async () => {
    const pid = await product();
    const admin = await user('Admin');
    const agent = await login(admin);
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [admin.id]);
    // Thickness and Size / format left on "Choose…" (only the other options chosen).
    const sel = JSON.parse(await baseSpecJson(pid));
    for (const s of await productSpecs.offered(await productRepository.find(pid))) {
      if (['Thickness', 'Size / format'].includes(s.name)) delete sel[String(s.id)];
    }
    const base = { catalog_product_id: String(pid), description: 'D-382 slab', quantity: '40', line_type: 'goods', spec_json: JSON.stringify(sel) };
    const page = `/orders/${orderId}`;
    const add = `/orders/${orderId}/products`;

    // SQM with a Sold as: the thickness (option or cm) is needed — not the Size / format.
    expect(await post(agent, page, add, { ...base, unit: 'SQM', ...await soldAs() })).toBe('Line 1: choose the Thickness or enter the Thickness (cm) — a price per SQM needs it.');
    expect(await post(agent, page, add, { ...base, unit: 'SQM', piece_thickness_cm: '3', ...await soldAs() })).toContain('Product line added');

    // PCS (Monument slab): length, width and thickness.
    expect(await post(agent, page, add, { ...base, unit: 'PCS', piece_length_cm: '180', ...await soldAs('Monument slab') }))
      .toBe('Line 2: enter the width and thickness (cm) — a price per piece needs them.');

    // CBM: nothing more.
    expect(await post(agent, page, add, { ...base, unit: 'CBM', ...await soldAs() })).toContain('Product line added');

    // Without a Sold as (picking not required here): D-348 exactly as before.
    await allowHandTypedGoodsLines();
    expect(await post(agent, page, add, { ...base, unit: 'CBM', hs_code: '68022310', hs_spec_id: '' }))
      .toBe('Line 3: choose the Thickness and the Size / format — the price depends on them.');
  });

  // ------------------------------------------------------------------ D-389 option order

  it('an option\'s order is editable and logged', async () => {
    const agent = await login(await user('Managing Director'));
    const opt = await db.queryOne(`SELECT o.* FROM spec_options o JOIN spec_definitions s ON s.id = o.spec_id AND s.name = 'Edge work' AND s.product_id IS NULL
      JOIN product_categories c ON c.id = s.category_id AND c.is_system = 1 WHERE o.name = 'Bullnose' AND o.product_id IS NULL`);
    const category = Number(await value('SELECT id FROM product_categories WHERE is_system = 1 ORDER BY id LIMIT 1'));
    const tab = `/products/categories/${category}?tab=options&edit=${opt.spec_id}`;
    try {
      await post(agent, tab, `/pricing/spec-options/${opt.id}`, {
        name: opt.name, change_type: opt.change_type, value: opt.value !== null ? String(opt.value) : '', thickness_mm: '', sort_order: '0',
      });
      expect(String(await value('SELECT sort_order FROM spec_options WHERE id = :id', { id: opt.id }))).toBe('0');
      expect(String(await value("SELECT new_value FROM audit_log WHERE action_type = 'SPEC_OPTION_CHANGED' AND entity_id = :id ORDER BY id DESC LIMIT 1", { id: opt.id }))).toMatch(/· order 0$/);
      // The category's Options tab shows the order as a box (it was a hidden field).
      expect((await agent.get(tab)).text).toContain('aria-label="Order of Bullnose"');
    } finally {
      await db.execute('UPDATE spec_options SET sort_order = :o WHERE id = :id', { o: opt.sort_order, id: opt.id });
    }
  });
});
