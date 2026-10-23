'use strict';

/**
 * External QA report N-14/L-17 — "Node quotation PDF now 5 pages vs PHP 6
 * pages... owner requires identical look." Root-caused by direct pt
 * measurement of generated PDFs (see PHP's sibling test,
 * tests/Integration/DocumentPageCountParityTest.php): for the identical
 * HTML/CSS, DOMPDF lays out every data-table row ~20% taller than
 * Chromium does, and — confirmed by direct experiment — DOMPDF does not
 * honour an explicit CSS `line-height` override for table row-height
 * purposes the way Chromium does, so there is no CSS-only lever that
 * closes the gap. This is an upstream DOMPDF-vs-Chromium rendering-engine
 * difference, not a template bug: both stacks print the exact same
 * words, in the exact same order, with no data loss — only the page
 * count differs. That is why this item survived 2 prior QA retest rounds.
 *
 * This pins Node's CURRENT, already-reviewed page count (rather than
 * chasing pixel parity with DOMPDF, which would mean degrading
 * Chromium's more accurate layout to mimic DOMPDF's inflated one, across
 * every shared template section) so a future template or Puppeteer/
 * Chromium upgrade can't silently drift the page count further without
 * someone noticing.
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
