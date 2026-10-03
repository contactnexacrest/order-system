'use strict';

const db = require('../../src/config/db');
const orderRepository = require('../../src/repositories/orderRepository');
const orderAnnexureTermsRepository = require('../../src/repositories/orderAnnexureTermsRepository');
const annexureTermsSanitizer = require('../../src/helpers/annexureTermsSanitizer');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const annexureController = require('../../src/controllers/annexureController');
const flash = require('../../src/helpers/flash');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of AnnexureModeTermsTest.php (docs/schema.sql Section AT) —
 * Annexure A content mode (Product Specification / Additional Terms /
 * Both). Covers: mode persistence and validation, the WYSIWYG "Additional
 * Terms" HTML sanitizer (the one barrier between a stored payload and it
 * executing in a generated PDF/DOCX), and documentDataAssembler's
 * mode-aware flags that drive both the standalone Annexure A document and
 * the appendix baked into every other buyer-facing document.
 */
describe('Annexure A content mode (Section AT)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-user-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function fakeRes() {
    const res = { redirectedTo: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  // ---------------------------------------------------------------
  // annexureController.updateMode
  // ---------------------------------------------------------------

  it('updateMode accepts each valid mode', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());

    for (const mode of ['SPEC', 'TERMS', 'BOTH']) {
      const req = { params: { id: String(orderId) }, body: { mode }, user: { id: userId }, session: {} };
      await annexureController.updateMode(req, fakeRes());

      const messages = flash.pull(req);
      expect(messages[0].type).toBe('success');
      const order = await orderRepository.find(orderId);
      expect(order.annexure_mode).toBe(mode);
    }
  });

  it('updateMode rejects an invalid value', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());

    const req = { params: { id: String(orderId) }, body: { mode: 'EVERYTHING' }, user: { id: userId }, session: {} };
    await annexureController.updateMode(req, fakeRes());

    const messages = flash.pull(req);
    expect(messages[0].type).toBe('error');
    const order = await orderRepository.find(orderId);
    expect(order.annexure_mode).toBe('SPEC');
  });

  // ---------------------------------------------------------------
  // annexureController.updateTerms + annexureTermsSanitizer
  // ---------------------------------------------------------------

  it('updateTerms sanitizes script tags and event handlers', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());

    const req = {
      params: { id: String(orderId) },
      body: { content_html: '<p onclick="alert(1)">Hello <script>alert(1)</script><b>world</b></p>' },
      user: { id: userId },
      session: {},
    };
    await annexureController.updateTerms(req, fakeRes());

    const messages = flash.pull(req);
    expect(messages[0].type).toBe('success');

    const saved = await orderAnnexureTermsRepository.find(orderId);
    expect(saved).not.toBeNull();
    expect(saved.content_html).not.toMatch(/<script/);
    expect(saved.content_html).not.toMatch(/onclick/);
    expect(saved.content_html).toContain('<p>Hello <b>world</b></p>');
  });

  it('sanitizer strips remote/relative images, javascript: and data: links', () => {
    const dirty = '<img src="http://evil.example/x.png" onerror="alert(2)">'
      + '<img src="x">'
      + '<img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=" alt="ok">'
      + '<a href="javascript:alert(3)">bad link</a>'
      + '<a href="data:text/html,hi">data link</a>'
      + '<a href="https://example.com">good link</a>'
      + '<video src="x.mp4"></video>'
      + '<iframe src="https://evil.example"></iframe>';

    const clean = annexureTermsSanitizer.sanitize(dirty);

    expect(clean).not.toContain('evil.example');
    expect(clean).not.toMatch(/src="x"/);
    expect(clean).not.toContain('javascript:');
    expect(clean).not.toContain('data:text/html');
    expect(clean).not.toMatch(/<video/);
    expect(clean).not.toMatch(/<iframe/);
    expect(clean).toContain('data:image/png;base64,');
    expect(clean).toContain('href="https://example.com"');
  });

  it('sanitizer preserves allowed formatting tags and a table', () => {
    const html = '<p><strong>Bold</strong> and <em>italic</em></p><ul><li>One</li></ul><table><tr><td>A</td></tr></table>';
    const clean = annexureTermsSanitizer.sanitize(html);

    expect(clean).toContain('<strong>Bold</strong>');
    expect(clean).toContain('<em>italic</em>');
    expect(clean).toContain('<ul>');
    expect(clean).toContain('<table');
  });

  // ---------------------------------------------------------------
  // documentDataAssembler mode-aware flags
  // ---------------------------------------------------------------

  it('assemble() flags for SPEC mode with no products yet', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderRepository.setIncludeAnnexureA(orderId, true);
    await orderRepository.setAnnexureMode(orderId, 'SPEC');

    const data = await documentDataAssembler.assemble(orderId);

    expect(data.annexure_mode).toBe('SPEC');
    expect(data.annexure_show_spec).toBe(true);
    expect(data.annexure_show_terms).toBe(false);
    expect(data.annexure_terms_html).toBe('');
    expect(data.annexure_has_content).toBe(false);
  });

  it('assemble() flags for TERMS mode with saved terms', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await orderRepository.setIncludeAnnexureA(orderId, true);
    await orderRepository.setAnnexureMode(orderId, 'TERMS');
    await orderAnnexureTermsRepository.upsert(orderId, '<p>Inspect within 48 hours.</p>', userId);

    const data = await documentDataAssembler.assemble(orderId);

    expect(data.annexure_mode).toBe('TERMS');
    expect(data.annexure_show_spec).toBe(false);
    expect(data.annexure_show_terms).toBe(true);
    expect(data.annexure_terms_html).toContain('Inspect within 48 hours');
    expect(data.annexure_has_content).toBe(true);
  });

  it('assemble() flags for BOTH mode with only terms filled', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await orderRepository.setIncludeAnnexureA(orderId, true);
    await orderRepository.setAnnexureMode(orderId, 'BOTH');
    await orderAnnexureTermsRepository.upsert(orderId, '<p>Some terms.</p>', userId);

    const data = await documentDataAssembler.assemble(orderId);

    expect(data.annexure_show_spec).toBe(true);
    expect(data.annexure_show_terms).toBe(true);
    expect(data.annexure_products).toHaveLength(0);
    expect(data.annexure_has_content).toBe(true);
  });

  it('assemble() re-sanitizes terms HTML even if the DB row were poisoned', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await orderRepository.setAnnexureMode(orderId, 'TERMS');
    await orderAnnexureTermsRepository.upsert(orderId, '<p>Hi</p><script>alert(1)</script>', userId);

    const data = await documentDataAssembler.assemble(orderId);

    expect(data.annexure_terms_html).not.toMatch(/<script/);
  });
});
