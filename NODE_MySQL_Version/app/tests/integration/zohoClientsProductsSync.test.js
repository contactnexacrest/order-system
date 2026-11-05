'use strict';

const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const productRepository = require('../../src/repositories/productRepository');
const caSyncService = require('../../src/services/caSyncService');
const zohoBooksService = require('../../src/services/zohoBooksService');
const masterData = require('../../src/services/zohoMasterDataSync');
const zohoPendingService = require('../../src/services/zohoPendingService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of ZohoClientsProductsSyncTest.php. Owner decision 2 Nov 2026
 * (D-238, "Zoho clients/products yes"): clients and the Products menu kept in
 * Zoho Books, each behind its own switch. Every request goes to a fake Zoho —
 * never the real one.
 */
describe('Zoho Books: clients and products (D-238)', () => {
  let requests;
  let contacts;
  let items;

  function fakeZoho(method, url, payload) {
    requests.push({ method, url, payload });
    const u = new URL(url);
    const p = u.pathname;
    const q = (k) => u.searchParams.get(k);
    const json = (body, status = 200) => ({ status, body: JSON.stringify(body) });
    let m;
    if (p === '/oauth/v2/token') return json({ access_token: 'fake-access' });
    if ((m = p.match(/^\/books\/v3\/organizations\/(\d+)$/))) return json({ code: 0, organization: { organization_id: m[1], is_registered_for_gst: true, tax_settings: { tax_reg_no: '29AAKCN8733G1ZZ' } } });
    if (p === '/books/v3/settings/currencies') return json({ code: 0, currencies: [{ currency_id: 'CUR-USD', currency_code: 'USD' }, { currency_id: 'CUR-EUR', currency_code: 'EUR' }] });
    if (p === '/books/v3/contacts' && method === 'GET') return json({ code: 0, contacts: Object.values(contacts).filter((c) => c.contact_name === q('contact_name')) });
    if (p === '/books/v3/contacts' && method === 'POST') {
      const id = `CT-${Object.keys(contacts).length + 1}`;
      contacts[id] = { contact_id: id, contact_name: payload.contact_name, contact_type: payload.contact_type, status: 'active' };
      return json({ code: 0, contact: contacts[id] }, 201);
    }
    if ((m = p.match(/^\/books\/v3\/contacts\/([^/]+)(?:\/(active|inactive))?$/))) {
      if (m[2]) contacts[m[1]].status = m[2];
      return json({ code: 0, contact: contacts[m[1]] || {} });
    }
    if (p === '/books/v3/items' && method === 'GET') return json({ code: 0, items: Object.values(items).filter((i) => i.name === q('name')) });
    if (p === '/books/v3/items' && method === 'POST') {
      const id = `IT-${Object.keys(items).length + 1}`;
      items[id] = { item_id: id, name: payload.name, status: 'active' };
      return json({ code: 0, item: items[id] }, 201);
    }
    if ((m = p.match(/^\/books\/v3\/items\/([^/]+)(?:\/(active|inactive))?$/))) {
      if (m[2]) items[m[1]].status = m[2];
      return json({ code: 0, item: items[m[1]] || {} });
    }
    return json({ code: 0, message: `not faked: ${method} ${p}` });
  }

  const calls = (method, part) => requests.filter((r) => r.method === method && new URL(r.url).pathname.includes(part));

  async function testClient(name, currency = 'USD') {
    const id = await createTestClient();
    await db.execute(
      "UPDATE clients SET company_legal_name = :n, billing_address_line1 = '1 Harbour Road', billing_city = 'Rotterdam', billing_postcode = '3011', billing_country = 'Netherlands', vat_eori_tax_no = 'NL123456789B01', contact_person = 'Anna Buyer', phone = '+31 10 555 0101' WHERE id = :id",
      { n: name, id }
    );
    if (currency !== null) await createTestOrder(id, 'FOB', 'Standard — New Buyer', currency);
    return id;
  }

  async function testProduct(name, hs = '68022310') {
    return (await db.execute(
      "INSERT INTO catalog_products (name, specifications, hs_code, default_factory_cost, default_cha_cost, is_active) VALUES (:n, 'Granite slab, polished, 20 mm', :hs, 45000, 9999, 1)",
      { n: name, hs }
    )).insertId;
  }

  beforeEach(async () => {
    requests = [];
    contacts = {};
    items = {};
    process.env.ZOHO_INTEGRATION_ENABLED = 'true';
    process.env.ZOHO_ENVIRONMENT = 'test';
    for (const [k, v] of Object.entries({
      zoho_books_enabled: '1', zoho_books_client_id: 'fake-client', zoho_books_client_secret: 'fake-secret',
      zoho_books_refresh_token: 'fake-refresh', zoho_books_organization_id: '600', zoho_books_deposit_account_id: 'SBI-1',
      zoho_sync_clients: '0', zoho_sync_products: '0',
    })) await companySettingsRepository.set(k, v);
    zohoBooksService.setTransport(fakeZoho);
    // The test database is shared by the whole run: earlier tests' TEST- records must not reach this fake Zoho.
    await db.execute("UPDATE clients SET company_legal_name = CONCAT('OLD-', company_legal_name) WHERE company_legal_name LIKE 'TEST-%'");
    await db.execute("UPDATE catalog_products SET name = CONCAT('OLD-', name) WHERE name LIKE 'TEST-%'");
    // QA batch 4: the full sync now also sends Import & Distribution and Merchant Trade records (D-257 Zoho);
    // other tests' TEST- imports and deals must not reach this fake Zoho either.
    await db.execute("UPDATE import_shipments SET supplier_name = CONCAT('OLD-', supplier_name) WHERE supplier_name LIKE 'TEST-%'");
    await db.execute("UPDATE merchant_deals SET supplier_name = CONCAT('OLD-', supplier_name) WHERE supplier_name LIKE 'TEST-%'");
    await db.execute("UPDATE merchant_deals SET buyer_name = CONCAT('OLD-', buyer_name) WHERE buyer_name LIKE 'TEST-%'");
    await db.execute('DELETE FROM zoho_pending_inactivations');
  });

  afterEach(async () => {
    zohoBooksService.setTransport(null);
    delete process.env.ZOHO_INTEGRATION_ENABLED;
    delete process.env.ZOHO_ENVIRONMENT;
    for (const k of ['zoho_books_enabled', 'zoho_sync_clients', 'zoho_sync_products']) await companySettingsRepository.set(k, '0');
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('both switches off send nothing', async () => {
    await testClient('TEST-Off Client');
    await testProduct('TEST-Off Product');
    const r = await caSyncService.runFullSync('manual', null);
    expect(r.master_data).toEqual({ clients: 0, products: 0, inactivated: 0, failed: 0, skipped: 0 });
    // The shared test database may still hold other tests' orders whose invoices the normal sync
    // sends (creating their customer on the way) — that is the existing invoice path, not this switch.
    // With the switches off, nothing of THIS test's client or product may reach Zoho.
    expect(calls('POST', '/contacts').filter((c) => JSON.stringify(c.payload).includes('TEST-Off Client'))).toEqual([]);
    expect(calls('POST', '/items')).toEqual([]);
    const by = (await zohoPendingService.counts()).by_type;
    expect(by.client).toBeUndefined();
    expect(by.product).toBeUndefined();
  });

  it('clients: create, link by name, no-op when unchanged, update on change, inactivate', async () => {
    await companySettingsRepository.set('zoho_sync_clients', '1');
    const a = await testClient('TEST-Alpha Stone BV');
    await testClient('TEST-No Order Yet', null);
    contacts['CT-EXIST'] = { contact_id: 'CT-EXIST', contact_name: 'TEST-Beta Marble GmbH', contact_type: 'customer', status: 'active' };
    const b = await testClient('TEST-Beta Marble GmbH', 'EUR');

    expect((await zohoPendingService.counts()).by_type.client).toBe(2);
    expect((await masterData.run('manual', null)).clients).toBe(2);
    const created = calls('POST', '/contacts').filter((c) => !c.url.includes('/active'));
    expect(created).toHaveLength(1);
    const p = created[0].payload;
    expect(p.contact_name).toBe('TEST-Alpha Stone BV');
    expect(p.contact_type).toBe('customer');
    expect(p.currency_id).toBe('CUR-USD');
    expect(p.billing_address).toEqual({ address: '1 Harbour Road', city: 'Rotterdam', zip: '3011', country: 'Netherlands' });
    expect(p.contact_persons[0].first_name).toBe('Anna Buyer');
    expect(p.notes).toContain('NL123456789B01');
    expect(p.gst_treatment).toBe('overseas');
    expect((await db.queryOne('SELECT zoho_contact_id FROM clients WHERE id = :id', { id: b })).zoho_contact_id).toBe('CT-EXIST');
    expect((await zohoPendingService.counts()).by_type.client).toBeUndefined();

    requests = [];
    expect((await masterData.run('manual', null)).clients).toBe(0);
    expect(calls('PUT', '/contacts')).toEqual([]);

    await db.execute("UPDATE clients SET billing_city = 'Amsterdam' WHERE id = :id", { id: a });
    expect((await masterData.run('manual', null)).clients).toBe(1);
    const put = calls('PUT', '/contacts');
    expect(put).toHaveLength(1);
    expect(put[0].payload.billing_address.city).toBe('Amsterdam');
    expect(put[0].payload.currency_id).toBeUndefined();

    await db.execute('UPDATE clients SET is_active = 0 WHERE id = :id', { id: a });
    expect((await masterData.run('manual', null)).inactivated).toBe(1);
    const contactId = (await db.queryOne('SELECT zoho_contact_id FROM clients WHERE id = :id', { id: a })).zoho_contact_id;
    expect(contacts[contactId].status).toBe('inactive');
    expect(calls('DELETE', '/contacts')).toEqual([]);
    const logged = await db.queryOne("SELECT COUNT(*) AS n FROM zoho_sync_log WHERE sync_type = 'client' AND entity_id = :id AND status = 'success'", { id: a });
    expect(Number(logged.n)).toBeGreaterThan(0);
  });

  it('products: rate 0 and no cost ever sent; inactive and deleted products marked inactive', async () => {
    await companySettingsRepository.set('zoho_sync_products', '1');
    const id = await testProduct('TEST-Absolute Black Slab');
    await testProduct('Real Product Not In Test Org');
    expect((await zohoPendingService.counts()).by_type.product).toBe(1);

    expect((await masterData.run('manual', null)).products).toBe(1);
    const post = calls('POST', '/items');
    expect(post).toHaveLength(1);
    const p = post[0].payload;
    expect(p.name).toBe('TEST-Absolute Black Slab');
    expect(p.rate).toBe(0);
    expect(p.hsn_or_sac).toBe('68022310');
    expect(p.product_type).toBe('goods');
    expect(p.item_type).toBe('sales');
    const encoded = JSON.stringify(p).toLowerCase();
    for (const never of ['45000', '9999', 'cost', 'factory', 'purchase']) expect(encoded).not.toContain(never);

    await db.execute('UPDATE catalog_products SET is_active = 0 WHERE id = :id', { id });
    expect((await masterData.run('manual', null)).inactivated).toBe(1);
    const itemId = (await db.queryOne('SELECT zoho_item_id FROM catalog_products WHERE id = :id', { id })).zoho_item_id;
    expect(items[itemId].status).toBe('inactive');

    await db.execute('UPDATE catalog_products SET is_active = 1, zoho_synced_hash = NULL WHERE id = :id', { id });
    await masterData.run('manual', null);
    await productRepository.remove(id);
    expect((await zohoPendingService.counts()).by_type.product).toBe(1);
    expect((await masterData.run('manual', null)).inactivated).toBe(1);
    expect(items[itemId].status).toBe('inactive');
    expect(Number((await db.queryOne('SELECT COUNT(*) AS n FROM zoho_pending_inactivations')).n)).toBe(0);
  });

  it('re-push all queues everything again; "Sync this order" leaves clients and products to the full sync', async () => {
    await companySettingsRepository.set('zoho_sync_clients', '1');
    await companySettingsRepository.set('zoho_sync_products', '1');
    await testClient('TEST-Gamma Granite Ltd');
    await testProduct('TEST-Kashmir White');
    await masterData.run('manual', null);
    let by = (await zohoPendingService.counts()).by_type;
    expect(by.client).toBeUndefined();
    expect(by.product).toBeUndefined();
    await masterData.forgetHashes();
    by = (await zohoPendingService.counts()).by_type;
    expect(by.client).toBeGreaterThanOrEqual(1);
    expect(by.product).toBeGreaterThanOrEqual(1);
    const r = await caSyncService.runForOrder(await createTestOrder(await createTestClient()), null);
    expect(r.master_data).toBeUndefined();
  });

  it('the switches are ordinary Zoho Books settings, saved with the page\'s mandatory reason', async () => {
    const rows = await db.query("SELECT setting_key, value_type, category FROM company_settings WHERE setting_key IN ('zoho_sync_clients', 'zoho_sync_products') ORDER BY setting_key");
    expect(rows).toHaveLength(2);
    for (const row of rows) {
      expect(row.value_type).toBe('boolean');
      expect(row.category).toBe('zoho_books');
    }
  });
});
