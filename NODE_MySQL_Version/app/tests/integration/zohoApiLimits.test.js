'use strict';

const path = require('path');
const nunjucks = require('nunjucks');
const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const caSyncService = require('../../src/services/caSyncService');
const zohoBooksService = require('../../src/services/zohoBooksService');
const zohoApiBudget = require('../../src/services/zohoApiBudget');
const zohoPendingService = require('../../src/services/zohoPendingService');

/**
 * Port of ZohoApiLimitsTest.php. Owner decision 2 Nov 2026 (D-239, "Zoho
 * limits yes"): this app's share of the Zoho API calls of the organisation —
 * a call meter, a daily and a per-minute allowance, an immediate stop on
 * Zoho's "too many requests", and one access token shared by every process.
 * Every request goes to a fake Zoho — never the real one.
 */
describe('Zoho API limits for this app (D-239)', () => {
  let requests;
  let overrides;
  let tokenNo;

  function fakeZoho(method, url, payload) {
    requests.push({ method, url, payload });
    for (const o of overrides) {
      const answer = o(method, url, payload);
      if (answer) return answer;
    }
    const p = new URL(url).pathname;
    const json = (body, status = 200) => ({ status, body: JSON.stringify(body) });
    let m;
    if (p === '/oauth/v2/token') return json({ access_token: `fake-access-${++tokenNo}` });
    if (p === '/books/v3/organizations') return json({ code: 0, organizations: [{ organization_id: '600', name: 'TEST org', currency_code: 'INR' }] });
    if ((m = p.match(/^\/books\/v3\/organizations\/(\d+)$/))) return json({ code: 0, organization: { organization_id: m[1], is_registered_for_gst: true, tax_settings: { tax_reg_no: '29AAKCN8733G1ZZ' } } });
    if (p === '/books/v3/items' && method === 'GET') return json({ code: 0, items: [] });
    if (p === '/books/v3/items' && method === 'POST') return json({ code: 0, item: { item_id: `IT-${requests.length}`, name: payload.name, status: 'active' } }, 201);
    return json({ code: 0, message: 'not faked' });
  }

  const calls = (method, part) => requests.filter((r) => r.method === method && r.url.includes(part));
  const lastLog = async () => {
    const row = await db.queryOne("SELECT message FROM zoho_sync_log WHERE sync_type = 'sync_run' ORDER BY id DESC LIMIT 1");
    return row ? row.message : null;
  };
  const product = async (name) => (await db.execute("INSERT INTO catalog_products (name, specifications, hs_code, is_active) VALUES (:n, 'Granite', '68022310', 1)", { n: name })).insertId;
  // A token + the organisation read (what follows in testConnection() is not faked here).
  const connect = async () => {
    try {
      await zohoBooksService.testConnection();
    } catch (e) {
      if (e instanceof zohoApiBudget.ZohoSyncStop || !/bankaccounts|not faked|organi[sz]ation/i.test(String(e.message))) throw e;
    }
  };

  beforeEach(async () => {
    requests = [];
    overrides = [];
    tokenNo = 0;
    process.env.ZOHO_INTEGRATION_ENABLED = 'true';
    process.env.ZOHO_ENVIRONMENT = 'test';
    for (const [k, v] of Object.entries({
      zoho_books_enabled: '1', zoho_books_client_id: 'fake-client', zoho_books_client_secret: 'fake-secret',
      zoho_books_refresh_token: 'fake-refresh', zoho_books_organization_id: '600', zoho_books_deposit_account_id: 'SBI-1',
      zoho_sync_clients: '0', zoho_sync_products: '1', zoho_api_daily_limit: '800', zoho_api_minute_limit: '30',
    })) await companySettingsRepository.set(k, v);
    zohoBooksService.setTransport(fakeZoho);
    zohoApiBudget.useSettingLimits(); // this file checks the real limits (other tests' fake Zoho is unlimited)
    await db.execute("UPDATE clients SET company_legal_name = CONCAT('OLD-', company_legal_name) WHERE company_legal_name LIKE 'TEST-%'");
    await db.execute("UPDATE catalog_products SET name = CONCAT('OLD-', name) WHERE name LIKE 'TEST-%'");
    await db.execute('UPDATE catalog_products SET is_active = 0 WHERE zoho_item_id IS NULL');
    await db.execute('DELETE FROM zoho_pending_inactivations');
    // setTransport() clears the call meter and the token cache in the background — wait for it.
    await db.execute('DELETE FROM zoho_api_usage');
    await db.execute('DELETE FROM zoho_token_cache');
  });

  afterEach(async () => {
    zohoApiBudget.setClock(null);
    zohoApiBudget.setSleeper(null);
    zohoBooksService.setTransport(null);
    delete process.env.ZOHO_INTEGRATION_ENABLED;
    delete process.env.ZOHO_ENVIRONMENT;
    for (const [k, v] of Object.entries({ zoho_books_enabled: '0', zoho_sync_clients: '0', zoho_sync_products: '0', zoho_api_daily_limit: '800', zoho_api_minute_limit: '30' })) await companySettingsRepository.set(k, v);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('counts every call, the login token included', async () => {
    await product('TEST-Meter Slab');
    const before = (await zohoApiBudget.usage()).today;
    await caSyncService.runFullSync('manual', null);
    expect(calls('POST', '/oauth/v2/token').length).toBeGreaterThan(0);
    expect(calls('POST', '/books/v3/items').length).toBeGreaterThan(0);
    const usage = await zohoApiBudget.usage();
    expect(usage.today).toBe(before + requests.length);
    expect(usage.daily_limit).toBe(800);
    expect(usage.minute_limit).toBe(30);
  });

  it('the daily allowance stops the run before any call', async () => {
    await companySettingsRepository.set('zoho_api_daily_limit', '5');
    await db.execute("INSERT INTO zoho_api_usage (environment, bucket_type, bucket, calls) VALUES ('test', 'day', :d, 5)", { d: zohoApiBudget.dayKey() });
    await product('TEST-Over Budget');
    const r = await caSyncService.runFullSync('manual', null);
    expect(requests).toEqual([]);
    expect(r.stopped).toBe('Paused: daily Zoho allowance for this app (5 calls) used — resumes tomorrow.');
    expect(await lastLog()).toBe(r.stopped);
    expect((await zohoApiBudget.usage()).exhausted).toBe(true);
    expect(await zohoPendingService.pausedText()).toBe('paused — daily Zoho allowance used, resumes tomorrow');
  });

  it('the minute limit waits in the background and refuses quickly for a person', async () => {
    zohoApiBudget.setTestLimits(1000, 2);
    let now = Math.floor(Date.parse('2026-11-02T10:00:30Z') / 1000); // 15:30:30 IST — 31 s to the next minute
    zohoApiBudget.setClock(() => now);
    const slept = [];
    zohoApiBudget.setSleeper(async (s) => { slept.push(s); now += s; });

    await zohoApiBudget.acquire();
    await zohoApiBudget.acquire();
    await expect(zohoApiBudget.acquire()).rejects.toThrow('Zoho speed limit for this app reached — try again in a minute.');
    expect(slept).toEqual([]);

    await zohoApiBudget.inBackground(() => zohoApiBudget.acquire());
    expect(slept).toEqual([31]);
    expect((await zohoApiBudget.usage()).minute).toBe(1);
    expect((await zohoApiBudget.usage()).today).toBe(3);
  });

  it("Zoho's too many requests stops the run at once", async () => {
    for (const n of ['TEST-Rate A', 'TEST-Rate B', 'TEST-Rate C']) await product(n);
    overrides.push((m, u) => (m === 'POST' && u.includes('/books/v3/items') ? { status: 429, body: '{"code":44,"message":"You have exceeded the maximum number of API requests."}' } : null));
    const r = await caSyncService.runFullSync('manual', null);
    expect(calls('POST', '/books/v3/items').length).toBe(1);
    expect(r.stopped).toBe('Stopped: Zoho rate limit — remaining records will be sent at the next sync.');
    expect(await lastLog()).toBe(r.stopped);
    expect(r.revenue.synced + r.revenue.invoices).toBe(0);
  });

  it('code 44 in a body and the OAuth too-many-requests also stop', async () => {
    await product('TEST-Code 44');
    overrides.push((m, u) => (m === 'POST' && u.includes('/books/v3/items') ? { status: 400, body: '{"code":44,"message":"Maximum number of API calls exceeded"}' } : null));
    expect((await caSyncService.runFullSync('manual', null)).stopped).toBe(zohoApiBudget.RATE_LIMIT_MESSAGE);

    zohoBooksService.setTransport(fakeZoho);
    zohoApiBudget.useSettingLimits();
    await db.execute('DELETE FROM zoho_token_cache');
    overrides = [(m, u) => (u.includes('/oauth/v2/token') ? { status: 400, body: '{"error_description":"You have made too many requests continuously. Please try again after some time.","error":"Access Denied","status":"failure"}' } : null)];
    expect((await caSyncService.runFullSync('manual', null)).stopped).toBe(zohoApiBudget.RATE_LIMIT_MESSAGE);
  });

  it('one token is shared by every process and fetched again once after a 401', async () => {
    await connect();
    zohoBooksService.forgetTokensInMemoryForTests(); // a second process: only the shared cache
    await connect();
    expect(calls('POST', '/oauth/v2/token').length).toBe(1);
    const stored = (await db.queryOne('SELECT token_enc FROM zoho_token_cache')).token_enc;
    expect(stored).not.toBe('');
    expect(stored).not.toContain('fake-access-1');

    const auths = [];
    zohoBooksService.setTransport((method, url, payload, headers) => {
      requests.push({ method, url, payload });
      if (url.includes('/oauth/v2/token')) return { status: 200, body: JSON.stringify({ access_token: `fake-access-${++tokenNo}` }) };
      auths.push(headers && headers.Authorization);
      if (auths.length === 1) return { status: 401, body: '{"code":57,"message":"You are not authorized to perform this operation"}' };
      return { status: 200, body: '{"code":0,"organizations":[{"organization_id":"600","name":"TEST org","currency_code":"INR"}]}' };
    });
    zohoApiBudget.useSettingLimits();
    await db.execute('DELETE FROM zoho_token_cache');
    requests = [];
    await connect();
    expect(calls('POST', '/oauth/v2/token').length).toBe(2);
    expect(auths[0]).not.toBe(auths[1]);
  });

  it('the Sync page shows today’s usage and the history', async () => {
    await zohoApiBudget.acquire();
    await zohoApiBudget.acquire();
    const apiUsage = await zohoApiBudget.usage();
    const env = new nunjucks.Environment(new nunjucks.FileSystemLoader(path.join(__dirname, '../../views')));
    const html = env.render('ca/zoho_sync.njk', {
      log: [], isEnabled: true, pendingCount: 0, heldCount: 0, purchasesPendingCount: 0, benefitsPendingCount: 0, csrfToken: 't', canOpenOrder: () => true, legLabels: {}, apiUsage,
    });
    expect(html).toContain('Zoho calls today: 2 of 800 · this minute: 2 of 30');
    expect(html).toContain('Zoho API calls — last 7 days (this app)');
    expect(html).toContain(`<tr><td>${zohoApiBudget.dayKey()}</td><td>2</td><td>800</td></tr>`);
  });
});
