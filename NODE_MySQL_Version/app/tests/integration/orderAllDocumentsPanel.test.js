'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const { createTestClient, createTestOrder, createTestFile, createTestDocument } = require('../support/fixtures');

/**
 * Port of OrderAllDocumentsPanelTest.php. Owner request (1 Nov 2026): the
 * order page's "All Documents — quick links" panel lists every file
 * uploaded for the order by anyone (client portal, staff, associate), next
 * to the generated documents — only the files the person may download
 * (money visibility, CAFIN never, associate agreements only for
 * manage_incentives).
 */
describe('Order page — All Documents panel lists uploaded files', () => {
  const PASSWORD = 'JestAllDocsPanel!1';

  afterAll(async () => { await db.pool.end(); });

  async function signedInAs(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-alldocs-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const id = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    )).insertId;
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });
    return { id, agent };
  }

  async function upload(orderId, label, from, original = 'upload.pdf') {
    const fileId = await createTestFile(orderId, null);
    await db.execute(
      'UPDATE file_store SET document_type_label = :l, received_from = :f, original_filename = :o, uploaded_at = :d WHERE id = :id',
      { l: label, f: from, o: original, d: '2026-10-30 10:15:00', id: fileId }
    );
    return fileId;
  }

  async function panel(agent, orderId) {
    const html = (await agent.get(`/orders/${orderId}`)).text;
    const start = html.indexOf('<details class="sidebar-docs-toggle">');
    expect(start).toBeGreaterThan(-1);
    return html.slice(start, html.indexOf('</details>', start));
  }

  it('lists uploaded files with the generated documents', async () => {
    const md = await signedInAs('Managing Director');
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, md.id);
    const qt = await createTestDocument(orderId, 'QT', 'approved');
    await db.execute('UPDATE documents SET pdf_file_id = :f WHERE id = :id', { f: await createTestFile(orderId, null), id: qt });
    const po = await upload(orderId, 'Buyer PO copy (client-uploaded)', 'Client (self-uploaded)', 'PO-4711.pdf');
    const shot = await upload(orderId, 'Client payment self-report screenshot', 'Client (self-reported)', 'transfer.png');
    const plain = await upload(orderId, '', '', 'loading-photo.jpg');
    await db.execute('UPDATE file_store SET uploaded_by = :u WHERE id = :id', { u: md.id, id: plain });
    // A file that belongs to the CAFIN document is never listed as an upload.
    const cafinFile = await upload(orderId, 'CAFIN SECRET FILE', 'x');
    const cafin = await createTestDocument(orderId, 'CAFIN', 'approved');
    await db.execute('UPDATE documents SET pdf_file_id = :f WHERE id = :id', { f: cafinFile, id: cafin });
    // A removed file is not listed.
    const removed = await upload(orderId, 'Removed upload', 'Buyer');
    await db.execute('UPDATE file_store SET is_active = 0 WHERE id = :id', { id: removed });

    const html = await panel(md.agent, orderId);
    expect(html).toContain('Uploaded / received (3)');
    expect(html).toContain(`href="/file-store/${po}/download">Buyer PO copy (client-uploaded)</a>`);
    expect(html).toContain('from Client (self-uploaded) &middot; 2026-10-30');
    expect(html).toContain(`href="/file-store/${shot}/download">Client payment self-report screenshot</a>`);
    expect(html).toContain(`href="/file-store/${plain}/download">loading-photo.jpg</a>`);
    expect(html).toContain('from Jest Test User');
    expect(html).not.toContain('CAFIN SECRET FILE');
    expect(html).not.toContain('Removed upload');
    expect(html).toMatch(/QT JEST-DOC-/);
    const generated = Number(html.match(/Generated \((\d+)\)/)[1]);
    const all = Number(html.match(/All Documents \((\d+)\)/)[1]);
    expect(all).toBe(generated + 3);
  });

  it('hides money-gated uploads from who may not see that money', async () => {
    const logistics = await signedInAs('Logistics Executive');
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, logistics.id);
    await upload(orderId, 'Client payment self-report screenshot', 'Client (self-reported)');
    await upload(orderId, 'Fumigation certificate', 'Fumigation agency');
    const html = await panel(logistics.agent, orderId);
    expect(html).not.toContain('Client payment self-report screenshot');
    expect(html).toContain('Fumigation certificate');
    expect(html).toContain('Uploaded / received (1)');
  });

  it('shows the empty state', async () => {
    const md = await signedInAs('Managing Director');
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, md.id);
    const html = await panel(md.agent, orderId);
    expect(html).toContain('No files uploaded yet.');
    expect(html).toContain('Uploaded / received (0)');
  });
});
