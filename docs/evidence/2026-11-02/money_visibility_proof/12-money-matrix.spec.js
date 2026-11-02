// @ts-check
'use strict';

/**
 * Owner points 6 / 15 (2 Nov 2026): "only permitted people see profit /
 * procurement / sale / CHA / logistics amounts — not a single leak; give
 * proof". One sentinel order carries a distinctive amount for each kind of
 * money (support/moneyMatrixSeed.js). Every seeded role logs in and opens
 * every staff screen (Standard and Kanban), report, CSV export, audit log and
 * every file of the order. A sentinel may appear — and a file may download —
 * only for a role holding that money's permission. The run writes a role ×
 * money matrix to MONEY_MATRIX_OUT (HTML) and fails on any violation.
 */
const { test, expect } = require('@playwright/test');
const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const { PASSWORD } = require('./support/auth');
const { runSql, dbName, bcryptHash } = require('./support/db');

const CLASSES = ['selling', 'buyer_payments', 'purchase', 'logistics', 'financials', 'incentives'];
const CLASS_LABEL = {
  selling: 'Selling prices', buyer_payments: 'Buyer payments', purchase: 'Purchase / supplier',
  logistics: 'Logistics / CHA', financials: 'Costs, expenses, profit', incentives: 'Incentives / commission', cafin: 'CAFIN',
};

function permissionsOf(roleId) {
  const out = runSql(
    `SELECT p.permission_key FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ${Number(roleId)}`,
    'role permissions'
  );
  return new Set(out.split('\n').filter(Boolean));
}

function allowed(perms, cls) {
  switch (cls) {
    case 'selling': return perms.has('view_selling_prices');
    case 'buyer_payments': return perms.has('view_buyer_payments');
    case 'purchase': return perms.has('view_purchase_prices');
    case 'logistics': return perms.has('view_logistics_costs');
    case 'financials': return perms.has('manage_order_financials') || perms.has('ca_module_view');
    // D-172: amounts and profit basis for owner/MD (manage_incentives); Accounts sees what to pay (pay_incentives).
    case 'incentives': return perms.has('manage_incentives') || perms.has('pay_incentives');
    case 'cafin': return perms.has('view_cafin');
    default: return true;
  }
}

function pages(seed) {
  const o = seed.orderId;
  const c = seed.clientId;
  const s = seed.supplierId;
  return [
    '/dashboard', '/orders', '/orders/archived', `/orders/${o}`, `/orders/${o}/audit-log`, `/orders/${o}/audit-log?format=csv`,
    `/orders/${o}/amendments`, `/orders/${o}/annexure`, `/orders/${o}/disputes`, `/orders/${o}/complaints`, `/orders/${o}/edit`,
    `/orders/${o}/zoho-check`, '/my-tasks', '/cockpit', '/notifications', '/reviews', '/email-approvals', '/disputes', '/complaints',
    '/clients', `/clients/${c}`, '/suppliers', `/suppliers/${s}/edit`, '/logistics-partners', '/products', '/procurement', '/procurement/suppliers',
    '/associates', '/reports/associates', '/incentives/payouts', '/my-incentives', '/reorder-requests', '/pi-intake-review',
    '/reports', `/reports/order/${o}`, `/reports/order/${o}?format=csv`, `/reports/client/${c}`, `/reports/client/${c}?format=csv`,
    '/reports/aggregate', '/reports/aggregate?format=csv', '/reports/queues', '/reports/payments', '/reports/payments?format=csv',
    '/reports/todays-payments', '/reports/disputes', '/reports/amendments', '/reports/trends', '/reports/staff', '/reports/ageing',
    '/reports/freight-cost', '/reports/products', '/reports/suppliers', '/reports/order-profitability', '/reports/order-profitability?format=csv',
    '/reports/conversion', '/reports/performance', '/audit-log', '/audit-log?entity_type=order_vendor_bills', '/audit-log?entity_type=order_payment_status',
    '/ca', '/ca/expenses', '/ca/export-benefits', '/ca/reconciliation', '/ca/reports', '/ca/tds-summary', '/ca/gst-refunds', '/ca/bank-statement',
    '/ca/cafin-export', '/ca/zoho-sync', '/help/supplier-logistics-payments', '/getting-started',
  ];
}

const KANBAN_PAGES = ['/orders', '/clients', '/suppliers', '/logistics-partners', '/products', '/associates', '/procurement', '/reorder-requests', '/disputes', '/complaints'];

/** A plain HTTP GET with the session cookie (file downloads end the response in a way Playwright's request API aborts on). */
function rawGet(url, cookie) {
  const http = require('http');
  const base = new URL(process.env.BASE_URL || 'http://127.0.0.1:8080');
  return new Promise((resolve) => {
    const req = http.get({ host: base.hostname, port: base.port, path: url, headers: { cookie }, timeout: 60000 }, (res) => {
      const chunks = [];
      res.on('data', (d) => chunks.push(d));
      res.on('end', () => resolve({ status: res.statusCode, type: res.headers['content-type'], body: Buffer.concat(chunks) }));
      res.on('error', () => resolve({ status: res.statusCode, type: res.headers['content-type'], body: Buffer.concat(chunks) }));
    });
    req.on('timeout', () => { req.destroy(); resolve({ status: 0, type: '', body: Buffer.alloc(0) }); });
    req.on('error', () => resolve({ status: 0, type: '', body: Buffer.alloc(0) }));
  });
}

/** The dev server can be restarted by other work in progress — wait (up to 3 minutes) until it answers. */
async function waitForServer() {
  for (let i = 0; i < 90; i++) {
    if ((await rawGet('/login', '')).status === 200) return;
    await new Promise((r) => setTimeout(r, 2000));
  }
  throw new Error('server not answering on /login');
}

test.describe.configure({ mode: 'serial' });

test('Money matrix: every role sees only the money its permissions allow', async ({ browser }) => {
  test.setTimeout(90 * 60 * 1000);
  const storage = path.join('/tmp', `money-matrix-${Date.now()}`);
  const seedRun = spawnSync('node', [path.join(__dirname, 'support', 'moneyMatrixSeed.js'), bcryptHash(PASSWORD), storage], {
    env: { ...process.env, DB_DATABASE: dbName() },
    encoding: 'utf8',
    maxBuffer: 10 * 1024 * 1024,
  });
  if (seedRun.status !== 0) throw new Error(`seed failed: ${seedRun.stderr}`);
  const seed = JSON.parse(seedRun.stdout);
  const sentinelEntries = Object.entries(seed.sentinels);
  const violations = [];
  const rows = [];

  for (const user of seed.users) {
    const perms = permissionsOf(user.roleId);
    const seen = Object.fromEntries([...CLASSES, 'cafin'].map((cl) => [cl, []]));
    await waitForServer();
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto('/login');
    await page.getByLabel('Email').fill(user.email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForLoadState('domcontentloaded');
    if (/\/choose-department/.test(page.url())) {
      await page.locator('form button[type=submit], form input[type=submit]').first().click();
      await page.waitForLoadState('domcontentloaded');
    }

    if (process.env.MONEY_MATRIX_DEBUG) console.log(`role ${user.role} → ${page.url()}`);
    const visit = async (url, template) => {
      if (process.env.MONEY_MATRIX_DEBUG) console.log(`  ${url}`);
      const res = await context.request.get(url, { maxRedirects: 0, failOnStatusCode: false, timeout: 60000 });
      const status = res.status();
      if (status !== 200) return;
      const body = await res.text();
      for (const [cls, amount] of sentinelEntries) {
        if (body.includes(amount)) {
          seen[cls].push(`${url}${template ? ` (${template})` : ''}`);
          if (!allowed(perms, cls)) violations.push(`${user.role}: ${CLASS_LABEL[cls]} amount ${amount} visible on ${url}${template ? ` (${template})` : ''}`);
        }
      }
    };

    runSql(`UPDATE users SET ui_template_preference_id = 1 WHERE id = ${user.id}`, 'standard template');
    for (const url of pages(seed)) await visit(url, 'Standard');
    runSql(`UPDATE users SET ui_template_preference_id = 2 WHERE id = ${user.id}`, 'kanban template');
    for (const url of KANBAN_PAGES) await visit(url, 'Kanban');
    for (const url of [`/orders/${seed.orderId}`, '/dashboard']) await visit(url, 'Kanban');
    runSql(`UPDATE users SET ui_template_preference_id = NULL WHERE id = ${user.id}`, 'template reset');

    // Every file of the order: a file of a money class downloads only for a role allowed to see it.
    const cookie = (await context.cookies()).map((k) => `${k.name}=${k.value}`).join('; ');
    for (const f of seed.files) {
      const ok = (await rawGet(`/file-store/${f.id}/download`, cookie)).status === 200;
      if (ok && f.cls) seen[f.cls].push(`file ${f.name}`);
      if (ok && f.cls && !allowed(perms, f.cls)) violations.push(`${user.role}: downloaded ${f.name} (${CLASS_LABEL[f.cls]})`);
    }
    // The dossier ZIP: list its file names.
    const zip = await rawGet(`/orders/${seed.orderId}/dossier`, cookie);
    if (zip.status === 200 && /zip/.test(zip.type || '')) {
      const zipPath = path.join(storage, `dossier-${user.id}.zip`);
      fs.writeFileSync(zipPath, zip.body);
      const list = spawnSync('unzip', ['-Z1', zipPath], { encoding: 'utf8' }).stdout || '';
      for (const f of seed.files) {
        const base = path.basename(f.name, '.pdf');
        if (f.cls && list.includes(base)) {
          seen[f.cls].push(`dossier: ${f.name}`);
          if (!allowed(perms, f.cls)) violations.push(`${user.role}: dossier ZIP contains ${f.name} (${CLASS_LABEL[f.cls]})`);
        }
      }
    }
    rows.push({ role: user.role, perms, seen });
    await context.close();
  }

  // Report.
  const out = process.env.MONEY_MATRIX_OUT;
  if (out) {
    const cell = (r, cls) => {
      const may = allowed(r.perms, cls);
      const n = r.seen[cls].length;
      const bad = n > 0 && !may;
      const text = n > 0 ? `seen (${n})` : 'hidden';
      return `<td class="${bad ? 'bad' : n > 0 ? 'seen' : 'hidden'}" title="${r.seen[cls].join('\n').replace(/"/g, '&quot;')}">${bad ? 'LEAK — ' : ''}${text}<br><small>${may ? 'permitted' : 'not permitted'}</small></td>`;
    };
    const cols = [...CLASSES, 'cafin'];
    const html = `<!doctype html><meta charset="utf-8"><title>Money visibility proof</title>
<style>body{font:14px system-ui;margin:24px}td,th{border:1px solid #ccc;padding:6px 8px;vertical-align:top}table{border-collapse:collapse}
.seen{background:#e7f6ec}.hidden{background:#f4f4f4;color:#555}.bad{background:#fde2e1;color:#900;font-weight:bold}small{color:#666;font-weight:normal}</style>
<h1>Money visibility proof — ${process.env.BASE_URL || ''} (${dbName()})</h1>
<p>Run ${new Date().toISOString()}. Each role logged in and opened ${pages(seed).length} screens in the Standard template and ${KANBAN_PAGES.length + 2} in Kanban, including reports, CSV exports and the audit log,
then tried to download each of the order's ${seed.files.length} files and the dossier ZIP. Sentinel amounts: ${sentinelEntries.map(([k, v]) => `${CLASS_LABEL[k]} = ${v}`).join(', ')}.
"seen (n)" = the amount appeared on n screens or files (hover for the list); "hidden" = it never appeared. A red cell is a leak.</p>
<p><strong>Violations: ${violations.length}</strong></p>${violations.length ? `<ul>${violations.map((v) => `<li>${v}</li>`).join('')}</ul>` : ''}
<table><tr><th>Role</th>${cols.map((c) => `<th>${CLASS_LABEL[c]}</th>`).join('')}</tr>
${rows.map((r) => `<tr><th>${r.role}</th>${cols.map((c) => cell(r, c)).join('')}</tr>`).join('\n')}</table>`;
    fs.mkdirSync(path.dirname(out), { recursive: true });
    fs.writeFileSync(out, html);
  }
  expect(violations, violations.join('\n')).toEqual([]);
});
