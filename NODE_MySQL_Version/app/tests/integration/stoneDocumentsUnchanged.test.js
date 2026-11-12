'use strict';

// Owner decision D-257 Phase 1 (5 Nov 2026): documents take their product columns,
// quality documents, packing and T&C clauses from the category, but a Natural Stone
// order must print exactly what it printed before. The fixtures in
// tests/fixtures/stone_documents were captured from the code BEFORE Phase 1 (the
// PDF's HTML text and the Word text, per-run parts masked); this test proves every
// stone document still matches them. CAPTURE_STONE_DOCS=1 rewrites the fixtures
// (only ever from pre-change code); they were captured on a fresh schema + seed
// database, which is what the test renders against. PHP twin: StoneDocumentsUnchangedTest.php.
// Owner decision D-343 (12 Nov 2026): the Word fixtures of the QT, PI, OC, CI and PL were captured again once,
// deliberately — the Word copy now numbers its sections as the PDF (Buyer / Consignee / Notify Party sections,
// Legal Terms & Definitions); the PDF fixtures did not change.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

// Renders against a database of its own, freshly built from schema.sql + seed.sql,
// so settings, watermarks or clauses another test changed in the shared test
// database cannot alter the text being compared. Set before src/config/db loads
// (Jest gives each test file its own module registry, so only this file uses it).
const STONE_DB = 'nexacrest_node_jest_stone';
const realEnv = require('dotenv').parse(fs.readFileSync(path.join(__dirname, '..', '..', '.env')));
const mysqlArgs = ['-h', realEnv.DB_HOST || '127.0.0.1', '-P', realEnv.DB_PORT || '3306', '-u', realEnv.DB_USERNAME, `-p${realEnv.DB_PASSWORD || ''}`];
const runSql = (database, input) => execFileSync('mysql', database ? [...mysqlArgs, database] : mysqlArgs, { stdio: ['pipe', 'pipe', 'pipe'], input });
const docsDir = path.join(__dirname, '..', '..', '..', 'docs');
runSql('', `DROP DATABASE IF EXISTS \`${STONE_DB}\`; CREATE DATABASE \`${STONE_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;`);
runSql(STONE_DB, fs.readFileSync(path.join(docsDir, 'schema.sql')));
runSql(STONE_DB, fs.readFileSync(path.join(docsDir, 'seed.sql')));
process.env.DB_DATABASE = STONE_DB;

const db = require('../../src/config/db');
const documentGenerationService = require('../../src/services/documentGenerationService');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const { renderPdfFromHtml } = require('../../src/services/pdfRenderService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

const CODES = ['QT', 'PI', 'OC', 'CI', 'PL', 'SUPPO', 'BLI', 'COOPREP'];
const DIR = path.join(__dirname, '..', 'fixtures', 'stone_documents');

function decode(s) {
  return s.replace(/&#39;|&#x27;|&apos;/g, "'").replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>')
    .replace(/&nbsp;|&#160;/g, ' ').replace(/&amp;/g, '&');
}

function mask(text) {
  let t = text.replace(/\s+/g, ' ');
  const patterns = [
    [/\b[A-Z]{2,}\/[A-Z0-9]+(?:\/[A-Z0-9-]+)+\b/g, '<REF>'],
    [/\b\d{1,2} (?:January|February|March|April|May|June|July|August|September|October|November|December) \d{4}\b/g, '<DATE>'],
    [/\b\d{1,2}[ -](?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[ -]\d{2,4}\b/g, '<DATE>'],
    [/\b\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}(?::\d{2})?)?\b/g, '<DATE>'],
    [/\b\d{2}[/.-]\d{2}[/.-]\d{4}\b/g, '<DATE>'],
    [/[\w.+-]+@[\w.-]+/g, '<EMAIL>'],
    [/Test Client \S+/g, 'Test Client <ID>'],
    [/JEST- ?[0-9a-f]{6,8}/gi, 'JEST-<ID>'],
    [/Rev\.\d+/g, 'Rev.<N>'],
    [/Page \d+ of \d+/g, 'Page <N>'],
  ];
  for (const [re, to] of patterns) t = t.replace(re, to);
  return t.trim();
}

describe('D-257 Phase 1: Natural Stone documents print exactly as before', () => {
  afterAll(async () => {
    await db.pool.end();
    runSql('', `DROP DATABASE IF EXISTS \`${STONE_DB}\`;`);
  });

  let userId;
  beforeAll(async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const r = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Stone Docs', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-stone-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    userId = r.insertId;
  });

  async function stoneOrder(incoterm) {
    const orderId = await createTestOrder(await createTestClient(), incoterm, 'Standard — New Buyer', 'USD');
    await orderProductRepository.add(orderId, 1, 'Black Galaxy Granite Slabs', '300 x 60 x 2 cm', 'Polished', '120', false, 'SQM', '45.50');
    await orderProductRepository.add(orderId, 2, 'Kashmir White Granite Tiles', '60 x 60 x 2 cm', 'Flamed', '80', false, 'SQM', '32.25');
    await db.execute(
      `UPDATE orders SET buyers_po_ref = 'PO-STONE-1', billing_address_line1 = '7 Harbour Road', billing_city = 'Poti',
              billing_postcode = '4400', country_of_destination = 'Georgia', contact_person = 'Head Buyer',
              estimated_net_weight_kg = 18500, estimated_package_count = 12 WHERE id = :id`,
      { id: orderId }
    );
    return orderId;
  }

  async function render(incoterm) {
    const orderId = await stoneOrder(incoterm);
    const out = {};
    for (const code of CODES) {
      const r = await documentGenerationService.generate(orderId, code, userId, null, true, true);
      const calls = renderPdfFromHtml.mock.calls;
      out[`${incoterm}_${code}_pdf`] = mask(decode(calls[calls.length - 1][0].replace(/<style[\s\S]*?<\/style>/g, ' ').replace(/<[^>]+>/g, ' ')));
      if (r.docx_file_id) {
        const file = await db.queryOne('SELECT server_path FROM file_store WHERE id = :id', { id: r.docx_file_id });
        const xml = execFileSync('unzip', ['-p', file.server_path, 'word/document.xml']).toString('utf8');
        out[`${incoterm}_${code}_docx`] = mask(decode(xml.replace(/<[^>]+>/g, ' ')));
      }
    }
    return out;
  }

  it('every stone document (PDF and Word, FOB and CIF) matches the pre-Phase-1 capture', async () => {
    const all = { ...(await render('FOB')), ...(await render('CIF')) };
    const capture = process.env.CAPTURE_STONE_DOCS === '1';
    for (const [name, text] of Object.entries(all)) {
      const file = path.join(DIR, `${name}.txt`);
      if (capture) { fs.writeFileSync(file, `${text}\n`); continue; }
      expect([name, fs.existsSync(file)]).toEqual([name, true]);
      expect([name, text]).toEqual([name, fs.readFileSync(file, 'utf8').trim()]);
    }
    expect(Object.keys(all).length).toBeGreaterThanOrEqual(16);
  }, 180000);
});
