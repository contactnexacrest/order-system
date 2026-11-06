'use strict';

// Q3 QA matrix (5 Nov 2026): every incoterm × every seeded payment preset. One
// two-line USD order per combination; every order document the standard generator
// handles is generated (PDF + Word). A document may be refused with a business
// message, but it must never crash with a programming error (TypeError,
// ReferenceError, …), and the core sales documents must carry the order's real
// total and the preset's payment split. PHP twin: QaIncotermPresetMatrixTest.php.
// Set QA_REPORT=/path.json to write the per-combination outcome table.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

// Runs on a database of its own, freshly built from schema.sql + seed.sql and dropped
// afterwards, so the orders, documents, e-mail rows and settings this matrix creates never
// reach the shared test database other tests read (same pattern as stoneDocumentsUnchanged).
const QA_DB = 'nexacrest_node_jest_qa_incoterm';
const realEnv = require('dotenv').parse(fs.readFileSync(path.join(__dirname, '..', '..', '.env')));
const mysqlArgs = ['-h', realEnv.DB_HOST || '127.0.0.1', '-P', realEnv.DB_PORT || '3306', '-u', realEnv.DB_USERNAME, `-p${realEnv.DB_PASSWORD || ''}`];
const runSql = (database, input) => execFileSync('mysql', database ? [...mysqlArgs, database] : mysqlArgs, { stdio: ['pipe', 'pipe', 'pipe'], input });
const docsDir = path.join(__dirname, '..', '..', '..', 'docs');
runSql('', `DROP DATABASE IF EXISTS \`${QA_DB}\`; CREATE DATABASE \`${QA_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;`);
runSql(QA_DB, fs.readFileSync(path.join(docsDir, 'schema.sql')));
runSql(QA_DB, fs.readFileSync(path.join(docsDir, 'seed.sql')));
process.env.DB_DATABASE = QA_DB;
const db = require('../../src/config/db');
const documentGenerationService = require('../../src/services/documentGenerationService');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const { renderPdfFromHtml } = require('../../src/services/pdfRenderService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

const CODES = ['QT', 'ANNEXA', 'BUYERPO', 'PI', 'OC', 'SUPPO', 'FDN', 'PL', 'BLI', 'CI', 'COOPREP'];
const CORE = ['QT', 'PI', 'OC', 'CI'];
const PROGRAMMING = [TypeError, ReferenceError, SyntaxError, RangeError];

function htmlText(html) {
  return html.replace(/<style[\s\S]*?<\/style>/g, ' ').replace(/<[^>]+>/g, ' ')
    .replace(/&nbsp;|&#160;/g, ' ').replace(/&amp;/g, '&').replace(/\s+/g, ' ');
}

describe('Q3 matrix: every incoterm × payment preset generates every order document', () => {
  afterAll(async () => { await db.pool.end(); runSql('', `DROP DATABASE IF EXISTS \`${QA_DB}\`;`); });

  it('no crash, and the core documents print the total and the payment split', async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const u = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest QA Matrix', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-qa-matrix-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    const userId = u.insertId;
    const presets = await db.query('SELECT preset_name, advance_pct, balance_pct, payment_method FROM payment_presets ORDER BY id');
    expect(presets.length).toBeGreaterThan(0);
    const report = {};
    const crashes = [];
    const wrong = [];

    for (const incoterm of ['FOB', 'CFR', 'CIF']) {
      for (const preset of presets) {
        const name = preset.preset_name;
        const orderId = await createTestOrder(await createTestClient(), incoterm, name, 'USD');
        await orderProductRepository.add(orderId, 1, 'Black Galaxy Granite Slabs', '300 x 60 x 2 cm', 'Polished', '120', false, 'SQM', '45.50');
        await orderProductRepository.add(orderId, 2, 'Kashmir White Granite Tiles', '60 x 60 x 2 cm', 'Flamed', '80', false, 'SQM', '32.25');
        await db.execute(
          `UPDATE orders SET buyers_po_ref = 'PO-QA-1', country_of_destination = 'Georgia', contact_person = 'QA Buyer',
                  estimated_net_weight_kg = 18500, estimated_package_count = 12 WHERE id = :id`,
          { id: orderId }
        );
        for (const code of CODES) {
          const key = `${incoterm} | ${name} | ${code}`;
          try {
            const r = await documentGenerationService.generate(orderId, code, userId, null, true, true);
            report[key] = `ok${r.docx_file_id ? '' : ' (pdf only)'}`;
            if (CORE.includes(code)) {
              const calls = renderPdfFromHtml.mock.calls;
              const text = htmlText(calls[calls.length - 1][0]);
              if (!text.includes('8,040.00')) wrong.push(`${key}: total 8,040.00 not printed`);
              if (code !== 'CI') {
                const adv = Math.round(Number(preset.advance_pct));
                const bal = Math.round(Number(preset.balance_pct));
                if (preset.payment_method === 'LC') {
                  if (!/letter of credit|L\/C|\bLC\b/i.test(text)) wrong.push(`${key}: LC preset but no letter-of-credit wording`);
                } else if (!new RegExp(`\\b${adv}\\s?%`).test(text) || !new RegExp(`\\b${bal}\\s?%`).test(text)) {
                  wrong.push(`${key}: ${adv}% / ${bal}% split not printed`);
                }
              }
            }
          } catch (e) {
            if (PROGRAMMING.some((T) => e instanceof T)) {
              report[key] = `CRASH ${e.name}: ${e.message}`;
              crashes.push(`${key}: ${report[key]} ${(e.stack || '').split('\n')[1] || ''}`);
            } else {
              report[key] = `refused: ${e.message}`;
              if (CORE.includes(code)) wrong.push(`${key}: core document refused: ${e.message}`);
            }
          }
        }
      }
    }

    if (process.env.QA_REPORT) {
      fs.writeFileSync(process.env.QA_REPORT, JSON.stringify({ stack: 'node', outcomes: report, wrong, crashes }, null, 2));
    }
    expect(Object.keys(report).length).toBeGreaterThanOrEqual(3 * presets.length * CODES.length);
    expect(crashes).toEqual([]);
    expect(wrong).toEqual([]);
  }, 600000);
});
