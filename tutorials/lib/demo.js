'use strict';
// Demo data for tutorials, on the PHP dev database only. Role e-mails and
// placeholder names only (no PII). Everything a video creates is removed again
// by cleanup(); videos that create clients/orders use snapshot()/restore().
const fs = require('fs');
const path = require('path');
const { spawnSync, execFileSync } = require('child_process');
const { chromium } = require('playwright');
const cfg = require('./config');

const q = (v) => (v == null ? 'NULL' : `'${String(v).replace(/\\/g, '\\\\').replace(/'/g, "''")}'`);

function sql(statement, what = 'sql') {
  const r = spawnSync('mysql', ['-N', '-u', cfg.DB.user, `-p${cfg.DB.password}`, cfg.DB.database, '-e', statement]);
  if (r.status !== 0) throw new Error(`${what} failed: ${r.stderr.toString()}`);
  return r.stdout.toString().trim();
}

// Runs PHP with the app bootstrap loaded (same DB the PHP dev server uses).
function php(code) {
  const boot = path.join(cfg.PHP_APP, 'bootstrap.php');
  const r = spawnSync('php', ['-r', `require ${JSON.stringify(boot)}; ${code}`], { cwd: cfg.PHP_APP });
  if (r.status !== 0) throw new Error(`php failed: ${r.stderr.toString()}${r.stdout.toString()}`);
  return r.stdout.toString().trim();
}

function hash(password) {
  return execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(password)}, PASSWORD_BCRYPT, ['cost'=>10]);`]).toString().trim();
}

const made = { associates: new Set(), staff: new Set() };

/**
 * A ready-to-use demo associate (active, portal on, no forced password change).
 * opts: email, name, level (starter|quoting|full|custom), basedIn, country, city, currency, address, pan
 */
function associate(opts) {
  const o = { level: 'starter', basedIn: 'india', country: 'India', city: 'Jaipur', currency: 'INR', status: 'active', ...opts };
  removeAssociates([o.email]);
  sql(`INSERT INTO associates (name, based_in, country, city, currency_code, email, phone, status, portal_enabled, force_password_change,
        password_hash, access_level, commission_mode, address, pan, notes)
       VALUES (${q(o.name)}, ${q(o.basedIn)}, ${q(o.country)}, ${q(o.city)}, ${q(o.currency)}, ${q(o.email)}, '+91-0000000000', ${q(o.status)}, 1, 0,
        ${q(hash(cfg.PASSWORD))}, ${q(o.level)}, 'ladder', ${q(o.address || null)}, ${q(o.pan || null)}, 'Tutorial demo record')`, 'create associate');
  const id = Number(sql(`SELECT id FROM associates WHERE email = ${q(o.email)}`));
  const code = php(`echo \\App\\Services\\AssociateCodeService::issue(${id}, ${JSON.stringify(o.codeName || o.name)});`);
  made.associates.add(o.email);
  return { ...o, id, code, password: cfg.PASSWORD };
}

// Remove demo associates and every row that points at them.
function removeAssociates(emails) {
  if (!emails.length) return;
  const ids = sql(`SELECT id FROM associates WHERE email IN (${emails.map(q).join(',')})`).split('\n').filter(Boolean);
  if (!ids.length) return;
  const list = ids.join(',');
  const refs = sql(`SELECT k.table_name, k.column_name FROM information_schema.key_column_usage k
     WHERE k.table_schema = DATABASE() AND k.referenced_table_name = 'associates'`).split('\n').filter(Boolean).map((l) => l.split('\t'));
  sql('SET FOREIGN_KEY_CHECKS = 1');
  for (const [table, col] of refs) {
    if (table === 'clients') sql(`UPDATE clients SET ${col} = NULL WHERE ${col} IN (${list})`, `unlink ${table}`);
  }
  // Children first; retry a few rounds so tables pointing at each other clear in any order.
  for (let round = 0; round < 4; round++) {
    for (const [table, col] of refs) {
      if (table === 'clients') continue;
      spawnSync('mysql', ['-u', cfg.DB.user, `-p${cfg.DB.password}`, cfg.DB.database, '-e', `DELETE FROM ${table} WHERE ${col} IN (${list})`]);
    }
  }
  sql(`DELETE FROM associates WHERE id IN (${list})`, 'delete associates');
}

function cleanup() { removeAssociates([...made.associates]); made.associates.clear(); removeStaff([...made.staff]); made.staff.clear(); }

// Whole-database snapshot for videos that create clients, orders or payouts.
function snapshot() {
  const file = path.join(cfg.WORK, 'db-before-recording.sql');
  fs.mkdirSync(cfg.WORK, { recursive: true });
  const r = spawnSync('sh', ['-c', `mysqldump --single-transaction --routines -u ${cfg.DB.user} -p'${cfg.DB.password}' ${cfg.DB.database} > '${file}'`]);
  if (r.status !== 0) throw new Error(`snapshot failed: ${r.stderr}`);
  return file;
}
function restore(file = path.join(cfg.WORK, 'db-before-recording.sql')) {
  const r = spawnSync('sh', ['-c', `mysql -u ${cfg.DB.user} -p'${cfg.DB.password}' ${cfg.DB.database} < '${file}'`]);
  if (r.status !== 0) throw new Error(`restore failed: ${r.stderr}`);
}

// ---------- off-camera logins: returns cookies to hand to the recording ----------
async function withHiddenPage(fn) {
  const browser = await chromium.launch({ executablePath: fs.existsSync(cfg.CHROMIUM) ? cfg.CHROMIUM : undefined });
  const context = await browser.newContext({ baseURL: cfg.BASE_URL });
  const page = await context.newPage();
  try { await fn(page); return await context.cookies(); } finally { await browser.close(); }
}

async function associateCookies(a) {
  return withHiddenPage(async (page) => {
    await page.goto('/associate/login');
    await page.getByLabel('E-mail').fill(a.email);
    await page.getByLabel('Password').fill(a.password);
    await page.getByRole('button', { name: 'Continue' }).click();
    const code = (await page.locator('.alert-info').first().innerText()).match(/(\d{6})/)[1];
    await page.getByLabel('Code').fill(code);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL(/\/associate$/);
  });
}

// Staff accounts used in tutorials (the dev seed accounts, role e-mails only).
const STAFF = {
  md: { email: 'md@email.com', password: process.env.TUT_STAFF_PASSWORD || 'PlaywrightTest#2026' },
  ed: { email: 'ed@email.com', password: process.env.TUT_STAFF_PASSWORD || 'PlaywrightTest#2026' },
};
// Demo staff for a role that has no seed account with a role e-mail (e.g. Finance Manager).
// Created on demand with the tutorial staff password; removed by removeStaff() / cleanup().
const DEMO_STAFF = { fm: { email: 'finance.manager@email.com', role: 'Finance Manager', name: 'Finance Manager' } };
function demoStaff(key) {
  const d = DEMO_STAFF[key];
  const h = hash(STAFF.md.password);
  const role = `(SELECT id FROM roles WHERE name = ${q(d.role)})`;
  if (sql(`SELECT id FROM users WHERE email = ${q(d.email)}`)) {
    sql(`UPDATE users SET password_hash = ${q(h)}, role_id = ${role}, is_active = 1, is_archived = 0, force_password_change = 0, two_fa_enabled = 0, failed_login_count = 0, locked_until = NULL WHERE email = ${q(d.email)}`);
  } else {
    sql(`INSERT INTO users (name, email, password_hash, role_id, is_active, force_password_change, two_fa_enabled, is_super_admin)
         VALUES (${q(d.name)}, ${q(d.email)}, ${q(h)}, ${role}, 1, 0, 0, 0)`);
  }
  STAFF[key] = { email: d.email, password: STAFF.md.password };
  made.staff.add(d.email);
  return d;
}
// Deletes a demo staff login; if records still point at it, it is switched off instead.
function removeStaff(emails) {
  for (const e of emails) {
    const r = spawnSync('mysql', ['-u', cfg.DB.user, `-p${cfg.DB.password}`, cfg.DB.database, '-e', `DELETE FROM users WHERE email = ${q(e)}`]);
    if (r.status !== 0) sql(`UPDATE users SET is_active = 0, is_archived = 1 WHERE email = ${q(e)}`);
  }
}
async function staffCookies(key = 'md') {
  const s = STAFF[key];
  return withHiddenPage(async (page) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill(s.email);
    await page.getByLabel('Password').fill(s.password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL((u) => !/\/login/.test(u.pathname));
  });
}

// A small one-page PDF that says SAMPLE, for upload demos (no real document is ever used).
function sampleFile(name = 'sample.pdf', label = 'SAMPLE DOCUMENT - TUTORIAL DEMO') {
  const dir = path.join(cfg.WORK, 'samples'); fs.mkdirSync(dir, { recursive: true });
  const file = path.join(dir, name);
  const text = label.replace(/[()\\]/g, '');
  const stream = `BT /F1 28 Tf 60 700 Td (${text}) Tj ET`;
  const objs = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
    `<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
  let pdf = '%PDF-1.4\n'; const offs = [];
  objs.forEach((o, i) => { offs.push(pdf.length); pdf += `${i + 1} 0 obj\n${o}\nendobj\n`; });
  const x = pdf.length;
  pdf += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n${offs.map((o) => `${String(o).padStart(10, '0')} 00000 n \n`).join('')}trailer\n<< /Size ${objs.length + 1} /Root 1 0 R >>\nstartxref\n${x}\n%%EOF\n`;
  fs.writeFileSync(file, pdf);
  return file;
}

/**
 * A demo buyer linked to an associate, optionally with orders, goods lines and commission payouts.
 * spec: { associateId, company, country, email, source, orders: [{ reference, status, currency, lines, payouts }] }
 * Use in videos with restoreDb: true (the whole DB is put back afterwards).
 */
function buyerWithOrders(spec) {
  const r = spawnSync('node', [path.join(__dirname, 'demo-orders.js'), JSON.stringify(spec)], {
    env: { ...process.env, DB_DATABASE: cfg.DB.database, DB_USERNAME: cfg.DB.user, DB_PASSWORD: cfg.DB.password },
  });
  if (r.status !== 0) throw new Error(`buyerWithOrders failed: ${r.stderr.toString()}`);
  return JSON.parse(r.stdout.toString().trim().split('\n').pop());
}

/**
 * A demo granite product with supplier prices, so "Get price" answers at once (same set-up as the
 * D255PriceRequestsTest fixture). Opens the system category (slab, tile) to the associate.
 * Use in videos with restoreDb: true.
 */
function pricedProduct(associateId, name = 'Black Galaxy Granite (demo)') {
  const md = Number(sql("SELECT id FROM users WHERE email = 'md@email.com'"));
  return Number(php(`
    use App\\Repositories\\ProductRepository; use App\\Repositories\\PricingRepository; use App\\Repositories\\SpecRepository;
    use App\\Repositories\\ProductSupplierRepository; use App\\Services\\PriceRequestService;
    $pdo = App\\Config\\Database::connection();
    $pdo->exec("UPDATE incentive_settings SET setting_value = '1' WHERE setting_key = 'program_enabled'");
    $p = ProductRepository::create(['name' => ${JSON.stringify(name)}, 'specifications' => 'Granite, slabs and tiles', 'hs_code' => '68022390', 'reference_unit' => 'SQM'], ${md});
    PricingRepository::saveProductOverrides($p, ['pricing_margin_pct' => null, 'pricing_other_cost_mode' => 'pct', 'pricing_other_cost_value' => '0',
      'pricing_incentive_mode' => null, 'pricing_incentive_value' => null, 'pricing_buffer_pct' => null]);
    SpecRepository::setProductFields($p, null, 'slab,tile', null);
    foreach ([['unit', 3280.0], ['cbm', 53300.0]] as [$basis, $inr]) {
      $s = ProductSupplierRepository::create(['product_id' => $p, 'supplier_name' => 'TEST Demo Quarry ' . $basis, 'location' => 'Demo location', 'contact_person' => null,
        'contact_phone' => null, 'contact_email' => null, 'fob_source' => 'direct', 'fob_value' => $inr, 'factory_cost' => null, 'transportation_cost' => null,
        'packing_cost' => null, 'loading_cost' => null, 'cha_cost' => null, 'is_primary' => $basis === 'unit' ? 1 : 0, 'notes' => null], ${md});
      PricingRepository::setSupplierDates($s, date('Y-m-d'), null);
      if ($basis === 'cbm') { PricingRepository::setSupplierBasis($s, 'cbm'); PricingRepository::setCbmPrimary($p, $s); }
    }
    PriceRequestService::saveAccess(${Number(associateId)}, [(int) SpecRepository::systemCategoryId() => ['slab', 'tile']]);
    echo $p;`));
}

module.exports = { demoStaff, removeStaff, pricedProduct, buyerWithOrders, sampleFile, sql, php, q, associate, removeAssociates, cleanup, snapshot, restore, associateCookies, staffCookies, STAFF };
