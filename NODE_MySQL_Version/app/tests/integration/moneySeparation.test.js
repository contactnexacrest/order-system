'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const fs = require('fs');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const permissionRepository = require('../../src/repositories/permissionRepository');
const roleRepository = require('../../src/repositories/roleRepository');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const moneyVisibility = require('../../src/services/moneyVisibility');
const { createTestClient, createTestOrder, createTestFile, createTestDocument } = require('../support/fixtures');

/**
 * Port of MoneySeparationTest.php. Owner decision D-214 (2026-10-31), phase
 * 2: who sees which money. Sales sees the selling side, Procurement the
 * buying side, Logistics only logistics costs; Finance, management, the
 * auditors and the CA see everything — on the order page, and for every
 * document and stored file.
 */
describe('Money separation (D-214 phase 2)', () => {
  const PASSWORD = 'JestMoneySeparation!1';
  const KEYS = ['view_selling_prices', 'view_buyer_payments', 'view_purchase_prices', 'view_logistics_costs', 'manage_supplier_po', 'settle_orders'];
  // role => [selling, buyer payments, purchase, logistics, Supplier PO, settle]
  const MATRIX = {
    'Export Executive': [1, 1, 0, 0, 0, 0],
    'Sales/Export Manager': [1, 1, 0, 0, 0, 0],
    'Procurement Executive': [0, 0, 1, 0, 1, 0],
    'Procurement Manager': [0, 0, 1, 0, 1, 0],
    'Logistics Executive': [0, 0, 0, 1, 0, 0],
    'Logistics Manager': [0, 0, 0, 1, 0, 0],
    'Accounts Executive': [1, 1, 1, 1, 0, 1],
    'Finance Manager': [1, 1, 1, 1, 0, 1],
    'Managing Director': [1, 1, 1, 1, 1, 1],
    'Viewer / Auditor': [1, 1, 1, 1, 0, 0],
    'CA / Chartered Accountant': [1, 1, 1, 1, 0, 0],
    'System Administrator': [0, 0, 0, 0, 0, 0],
  };
  const written = [];

  afterAll(async () => {
    for (const p of written) { try { fs.unlinkSync(p); } catch (e) { /* already gone */ } }
    await db.pool.end();
  });

  async function signedInAs(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-money-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
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

  async function documentWithFile(orderId, clientId, code) {
    const fileId = await createTestFile(orderId, clientId);
    const file = await db.queryOne('SELECT server_path FROM file_store WHERE id = :id', { id: fileId });
    fs.writeFileSync(file.server_path, '%PDF-1.4 test');
    written.push(file.server_path);
    await db.execute('UPDATE file_store SET file_size_bytes = :n WHERE id = :id', { n: fs.statSync(file.server_path).size, id: fileId });
    const documentId = await createTestDocument(orderId, code, 'approved');
    await db.execute('UPDATE documents SET pdf_file_id = :f WHERE id = :d', { f: fileId, d: documentId });
    return { fileId, documentId };
  }

  it('gives each role exactly its own money', async () => {
    for (const [roleName, expected] of Object.entries(MATRIX)) {
      const role = await roleRepository.findByName(roleName);
      expect(role).toBeTruthy();
      const effective = await permissionRepository.effectivePermissions(0, role.id);
      expect([roleName, KEYS.map((k) => (effective[k] ? 1 : 0))]).toEqual([roleName, expected]);
    }
  });

  it('gives every document its money class', () => {
    for (const code of ['QT', 'PI', 'OC', 'CI', 'AMD', 'FDN', 'BUYERPO', 'COOPREP']) {
      expect([code, moneyVisibility.documentClass(code)]).toEqual([code, moneyVisibility.SELLING]);
    }
    expect(moneyVisibility.documentClass('SUPPO')).toBe(moneyVisibility.PURCHASE);
    expect(moneyVisibility.documentClass('BLI')).toBe(moneyVisibility.LOGISTICS);
    expect(moneyVisibility.documentClass('PL')).toBeNull();
  });

  it('lets downloads follow the money and the order', async () => {
    const cases = [
      ['Export Executive', 'CI', 200], ['Export Executive', 'SUPPO', 403], ['Export Executive', 'BLI', 403],
      ['Logistics Executive', 'CI', 403], ['Logistics Executive', 'SUPPO', 403], ['Logistics Executive', 'BLI', 200], ['Logistics Executive', 'PL', 200],
      ['Accounts Executive', 'SUPPO', 200], ['Accounts Executive', 'CI', 200],
    ];
    for (const [roleName, code, expected] of cases) {
      const { id, agent } = await signedInAs(roleName);
      const clientId = await createTestClient();
      const orderId = await createTestOrder(clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, id);
      const { fileId, documentId } = await documentWithFile(orderId, clientId, code);
      expect([roleName, code, (await agent.get(`/documents/${documentId}/download`)).status]).toEqual([roleName, code, expected]);
      expect([roleName, code, 'file', (await agent.get(`/file-store/${fileId}/download`)).status]).toEqual([roleName, code, 'file', expected]);
    }

    // Not on the order: refused even for a document type the person may see.
    const other = await signedInAs('Export Executive');
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId, 'FOB');
    const { documentId } = await documentWithFile(orderId, clientId, 'CI');
    expect((await other.agent.get(`/documents/${documentId}/download`)).status).toBe(403);
  });

  it('shows each department only its money on the order page', async () => {
    for (const [roleName, sees] of [['Logistics Executive', false], ['Viewer / Auditor', true]]) {
      const { id, agent } = await signedInAs(roleName);
      const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, id);
      await orderPaymentStatusRepository.initializeForOrder(orderId);
      await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 6100.0, '2026-06-01');
      const html = (await agent.get(`/orders/${orderId}`)).text;
      expect([roleName, 'selling', html.includes('<th>Unit Price</th>')]).toEqual([roleName, 'selling', sees]);
      expect([roleName, 'fob', html.includes('>FOB Value<')]).toEqual([roleName, 'fob', sees]);
      expect([roleName, 'payment', /6,100\.00/.test(html)]).toEqual([roleName, 'payment', sees]);
    }
  });
});
