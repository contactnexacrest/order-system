'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient } = require('../support/fixtures');

/**
 * Port of RequiredDropDownChooseFirstUi013Test.php. QA UI-013 — owner decision
 * 13 Nov 2026 (item 6, Option A): on every create / add form a required
 * drop-down starts at an empty "Choose…", so the user picks consciously; the
 * browser asks for it beside the field and the server refuses an empty choice
 * (no silent default). The views as a whole are checked by
 * formLabelConventions.test.js (UI-013); this test checks the server side and
 * the New Order page.
 */
describe('UI-013: required drop-downs on create forms start at "Choose…"', () => {
  let agent;
  const csrfOf = (html) => html.match(/name="_csrf"\s+value="([^"]+)"/)[1];

  beforeAll(async () => {
    agent = request.agent(app);
    const csrf = csrfOf((await agent.get('/login')).text);
    await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function post(page, path, fields) {
    const html = (await agent.get(page)).text;
    const res = await agent.post(path).type('form').send({ _csrf: csrfOf(html), ...fields });
    const back = await agent.get(res.headers.location);
    const m = back.text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/);
    return { message: m ? m[2].replace(/<[^>]+>/g, '').trim() : '', page: back.text, html };
  }

  it('UI-013: a new compliance task without "When is it done?" is refused', async () => {
    const name = `UI013 Task ${Date.now()}`;
    expect((await post('/compliance-task-types/create', '/compliance-task-types', { name, phase: '' })).message).toBe('Choose when the task is done.');
    expect((await db.query('SELECT id FROM compliance_task_types WHERE name = :n', { n: name })).length).toBe(0); // never saved with a default phase
    expect((await post('/compliance-task-types/create', '/compliance-task-types', { name, phase: 'shipment' })).message).toContain('added to the compliance checklist');
  });

  it('UI-013: the New Order currency starts at "Choose…" with nothing preselected', async () => {
    const page = (await agent.get('/orders/create')).text;
    const m = page.match(/<select name="currency_id" id="order-currency-select"[^>]*>([\s\S]*?)<\/select>/);
    expect(m).not.toBeNull();
    expect(m[1].trim().startsWith('<option value="">Choose…</option>')).toBe(true);
    expect(m[1]).not.toContain('selected'); // no default currency
  });

  it('UI-013: a new order without its currency is refused and the typed values are kept', async () => {
    const clientId = await createTestClient();
    const incoterm = (await db.queryOne('SELECT id FROM incoterms ORDER BY id LIMIT 1')).id;
    const preset = (await db.queryOne('SELECT id FROM payment_presets ORDER BY id LIMIT 1')).id;
    const createPage = (await agent.get('/orders/create')).text;
    const division = (createPage.match(/<select name="division_id"[^>]*>[\s\S]*?<option value="(\d+)"/) || [])[1];
    const r = await post('/orders/create', '/orders', {
      client_id: String(clientId), incoterm_id: String(incoterm), payment_preset_id: String(preset), currency_id: '', ...(division ? { division_id: division } : {}),
    });
    expect(r.message).toBe('Choose the order currency.');
    expect(r.page).toMatch(new RegExp(`<option value="${incoterm}"[^>]*selected`)); // the typed values come back
  });
});
