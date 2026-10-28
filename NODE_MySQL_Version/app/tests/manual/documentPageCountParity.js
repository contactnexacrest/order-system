'use strict';

/**
 * External QA report N-14/L-17, QA Round 5 R5-12 — "Node quotation PDF 5
 * pages vs PHP 6 pages... owner requires identical look." Fixed in QA
 * Round 5: PHP's _layout.html.twig sets a line-height that lands DOMPDF on
 * Chromium's line pitch, and both layouts keep a section heading with the
 * content that follows it (see PHP's sibling,
 * tests/Integration/DocumentPageCountParityTest.php, for the root cause).
 * Both stacks now give the same page counts; these pins are the same
 * numbers PHP's test asserts, so neither stack can drift without someone
 * noticing.
 *
 * NOT a Jest test: jest.config.js's moduleNameMapper intentionally stubs
 * puppeteer-core for the whole suite (see
 * tests/support/mocks/puppeteerCoreStub.js's own docblock — "tests never
 * need real headless-Chrome PDF rendering, that's covered by E2E
 * verification instead"), so this needs its own process. Run it directly
 * whenever _layout.njk/quotation.njk changes, or Chromium/Puppeteer is
 * upgraded:
 *
 *   node tests/manual/documentPageCountParity.js
 *
 * Exits non-zero (and prints which case) if either pinned count drifted.
 */

process.env.APP_ENV = 'local';
process.env.DB_DATABASE = 'nexacrest_node_jest';
process.env.RUN_JOBS_IN_PROCESS = 'false';
process.env.SESSION_SECRET_KEY = 'doc-page-count-parity-script-secret';
process.env.SMTP_HOST = '';

const { execFileSync } = require('child_process');
const path = require('path');

const APP_ROOT = path.join(__dirname, '..', '..');
process.chdir(APP_ROOT);

const db = require(path.join(APP_ROOT, 'src/config/db'));
const documentGenerationService = require(path.join(APP_ROOT, 'src/services/documentGenerationService'));
const fileStoreRepository = require(path.join(APP_ROOT, 'src/repositories/fileStoreRepository'));
const orderProductRepository = require(path.join(APP_ROOT, 'src/repositories/orderProductRepository'));
const { createTestClient, createTestOrder } = require(path.join(APP_ROOT, 'tests/support/fixtures'));
const { closeBrowser } = require(path.join(APP_ROOT, 'src/services/pdfRenderService'));
const globalSetup = require(path.join(APP_ROOT, 'tests/support/globalSetup'));

async function createTestUser(roleName = 'Admin') {
  const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
  const result = await db.execute(
    `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
     VALUES ('Doc Parity Script User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
    { email: `doc-parity-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
  );
  return result.insertId;
}

function pageCountOf(filePath) {
  const info = execFileSync('pdfinfo', [filePath]).toString();
  const m = info.match(/Pages:\s*(\d+)/);
  if (!m) throw new Error(`pdfinfo produced no page count for ${filePath}`);
  return parseInt(m[1], 10);
}

const CASES = [
  { label: '1-line Quotation', lineCount: 1, pinnedPages: 5 },
  { label: '60-line Quotation (forces continuation pages)', lineCount: 60, pinnedPages: 8 },
];

async function main() {
  await globalSetup();
  const userId = await createTestUser('Admin');
  let failed = false;

  for (const { label, lineCount, pinnedPages } of CASES) {
    const orderId = await createTestOrder(await createTestClient());
    for (let i = 1; i <= lineCount; i++) {
      await orderProductRepository.add(orderId, i, `Parity test slab #${i}`, '600x600x18mm', 'Polished', '100', false, 'sqm', '25.00');
    }
    const result = await documentGenerationService.generate(orderId, 'QT', userId);
    const file = await fileStoreRepository.find(result.pdf_file_id);
    const pages = pageCountOf(file.server_path);

    if (pages === pinnedPages) {
      console.log(`PASS  ${label}: ${pages} page(s) (pinned)`);
    } else {
      failed = true;
      console.error(`FAIL  ${label}: expected ${pinnedPages} page(s), got ${pages} — see this file's docblock before assuming this is a regression.`);
    }
  }

  await closeBrowser();
  await db.pool.end();
  process.exitCode = failed ? 1 : 0;
}

main().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
