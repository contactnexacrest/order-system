'use strict';

/**
 * Q3 QA harness (5 Nov 2026): HTTP-level role and single-permission matrices.
 *
 * For one stack (BASE_URL), creates QA users directly in that stack's dev DB:
 *   - one per staff role   (qa-role-<roleId>@qa-matrix.test)
 *   - one per permission   (qa-perm-<key>@qa-matrix.test, on a role "QA-ONLY <key>" holding only that permission)
 * logs each in over HTTP and GETs the route list from docs/QA inventory
 * (e2e/support/qa-inventory.json, or the path in QA_INVENTORY), with real ids
 * resolved from the DB. Every response is classified:
 *   BUG  – 5xx, PHP/Node error text in the page, or the gate says "deny" but the page answered 200
 *   NOTE – the gate says "allow" but the page refused (object-level / module / division rules; reviewed by hand)
 *   OK   – everything else
 * Also checks the one division-isolation sample available in dev data: an
 * order of a planned division is never shown to Export staff.
 * Everything it creates is removed again by cleanup().
 */

const fs = require('fs');
const { spawnSync } = require('child_process');
const { request } = require('@playwright/test');

const DB_USER = process.env.E2E_DB_USER || 'nexacrest_app';
const DB_PASSWORD = process.env.E2E_DB_PASSWORD || 'DevPass123!';
const PASSWORD = 'QaMatrix#2026';
const MAIL_DOMAIN = 'qa-matrix.test';

function baseUrl() { return process.env.BASE_URL || 'http://127.0.0.1:8080'; }
function isNode() { return baseUrl().includes(':3000'); }
function dbName() { return process.env.E2E_DB_NAME || (isNode() ? 'nexacrest_node_dev' : 'nexacrest_php_dev'); }

function sql(query) {
  const r = spawnSync('mysql', ['-N', '-B', '-u', DB_USER, `-p${DB_PASSWORD}`, dbName(), '-e', query]);
  if (r.status !== 0) throw new Error(`SQL failed: ${r.stderr.toString()}\n${query}`);
  return r.stdout.toString().trim();
}
function rows(query) { const out = sql(query); return out ? out.split('\n').map((l) => l.split('\t')) : []; }
const q = (s) => `'${String(s).replace(/\\/g, '\\\\').replace(/'/g, "''")}'`;

function bcryptHash(password) {
  if (isNode()) {
    const mod = require.resolve('bcrypt', { paths: ['/home/user/order-system/NODE_MySQL_Version/app/node_modules'] });
    const r = spawnSync('node', ['-e', `console.log(require(${JSON.stringify(mod)}).hashSync(${JSON.stringify(password)}, 10))`]);
    return r.stdout.toString().trim();
  }
  const r = spawnSync('php', ['-r', `echo password_hash(${JSON.stringify(password)}, PASSWORD_BCRYPT, ['cost'=>10]);`]);
  return r.stdout.toString().trim();
}

/** Picks one real id for each {param} route, from rows staff of the Export division may open. */
function resolveIds() {
  const one = (query) => { try { return sql(query).split('\n')[0] || null; } catch { return null; } };
  const order = one("SELECT o.id FROM orders o WHERE o.division_id = 1 AND EXISTS (SELECT 1 FROM order_products p WHERE p.order_id = o.id) ORDER BY o.id DESC LIMIT 1");
  const ids = {
    order,
    orderProduct: order && one(`SELECT id FROM order_products WHERE order_id = ${order} LIMIT 1`),
    orderDocument: order && one(`SELECT id FROM documents WHERE order_id = ${order} ORDER BY id DESC LIMIT 1`),
    anyDocument: one('SELECT id FROM documents ORDER BY id DESC LIMIT 1'),
    orderFile: order && one(`SELECT id FROM order_comment_attachments WHERE order_id = ${order} LIMIT 1`),
    client: order && one(`SELECT client_id FROM orders WHERE id = ${order}`),
  };
  const table = {
    '/logistics-partners/': 'logistics_partners', '/suppliers/': 'suppliers', '/ports/': 'ports', '/port-routes/': 'port_routes',
    '/payment-presets/': 'payment_presets', '/compliance-task-types/': 'compliance_task_types', '/email-templates/': 'email_templates',
    '/reference-docs/custom/': 'reference_library_documents', '/signatories/user-assets/': 'user_signature_assets',
    '/procurement/': 'purchase_requirements', '/admin/divisions/': 'divisions', '/import/': 'import_shipments', '/merchant/': 'merchant_deals',
    '/domestic/orders/': 'domestic_orders', '/admin/roles/': 'roles', '/admin/permission-definitions/': 'permissions', '/reorder-requests/': 'order_reorder_requests',
    '/associates/': 'associates', '/agreements/': 'agreements', '/file-store/': 'file_store', '/users/': 'users', '/products/': 'catalog_products',
    '/reports/saved/': 'report_definitions', '/admin/departments/': 'departments', '/admin/designations/': 'designations',
  };
  const cache = {};
  const firstOf = (t) => { if (!(t in cache)) cache[t] = one(`SELECT MIN(id) FROM ${t}`); return cache[t] === 'NULL' ? null : cache[t]; };
  return function fill(path) {
    let p = path;
    if (/^\/(orders|client\/orders|associate\/orders)\/\{id\}/.test(p)) p = p.replace('{id}', ids.order || '0');
    if (/^\/clients\/\{id\}|^\/reports\/client\/\{clientId\}/.test(p)) p = p.replace(/\{id\}|\{clientId\}/, ids.client || '0');
    p = p.replace('{orderId}', ids.order || '0').replace('{productId}', ids.orderProduct || '0')
      .replace('{documentId}', ids.orderDocument || ids.anyDocument || '0').replace('{fileId}', ids.orderFile || '0');
    if (p.includes('{token}')) p = p.replace('{token}', 'qa-matrix-invalid-token');
    if (p.includes('{code}')) p = p.replace('{code}', path.startsWith('/reference-docs') ? 'STAGEGATE' : 'qa-invalid');
    if (p.includes('{chapter}')) p = p.replace('{chapter}', '01');
    if (p.includes('{file}')) p = p.replace('{file}', 'qa-missing.png');
    if (p.includes('{reportId}')) p = p.replace('{reportId}', firstOf('report_definitions') || '0');
    if (p.includes('{imageId}')) p = p.replace('{imageId}', firstOf('catalog_product_images') || '0');
    if (p.includes('{bankId}')) p = p.replace('{bankId}', firstOf('associate_bank_accounts') || '0');
    if (p.includes('{docId}')) p = p.replace('{docId}', firstOf('associate_documents') || '0');
    if (p.includes('{id}')) {
      const prefix = Object.keys(table).find((k) => p.startsWith(k));
      if (prefix === '/file-store/') p = p.replace('{id}', one(`SELECT MIN(id) FROM file_store WHERE order_id = ${ids.order || 0}`) || firstOf('file_store') || '0');
      else p = p.replace('{id}', (prefix && firstOf(table[prefix])) || '0');
    }
    return p;
  };
}

/** Gate verdict for a permission set: true = allow, false = deny, null = not decided by a permission. */
function gateAllows(route, perms) {
  // Inventory gates: a string must be held; an array is "any one of these".
  const terms = [...(route.requires_all || []), ...(route.requires_any || [])];
  if (!terms.length) return null;
  const has = (t) => (Array.isArray(t) ? t.some((k) => has(k)) : perms.has(t));
  const all = (route.requires_all || []).every(has);
  const anyList = route.requires_any || [];
  const anyOk = !anyList.length || (anyList.every((t) => Array.isArray(t)) ? anyList.every(has) : anyList.some(has));
  return all && anyOk;
}

const ERROR_MARKERS = [
  /Fatal error/i, /Uncaught [A-Z]\w+/, /Stack trace:/, /<b>(Warning|Notice|Deprecated)<\/b>:/, /on line <b>\d+<\/b>/,
  /\bTypeError\b/, /\bReferenceError\b/, /Template render error/, /Cannot read propert(y|ies) of (undefined|null)/,
  /\bat [A-Za-z_.]+ \(\/home\/user\//, /SQLSTATE\[/, /ER_[A-Z_]+:/,
];

async function loginContext(email) {
  const ctx = await request.newContext({ baseURL: baseUrl(), ignoreHTTPSErrors: true });
  const page = await ctx.get('/login');
  const token = ((await page.text()).match(/name="_csrf" value="([^"]+)"/) || [])[1];
  const res = await ctx.post('/login', { form: { _csrf: token, email, password: PASSWORD }, maxRedirects: 0 });
  const loc = res.headers().location || '';
  if (res.status() >= 400 || /\/login/.test(loc)) {
    await ctx.dispose();
    throw new Error(`login failed for ${email}: ${res.status()} ${loc}`);
  }
  return ctx;
}

function setup(inventory) {
  const hash = bcryptHash(PASSWORD);
  cleanup();
  const roles = rows("SELECT id, name FROM roles WHERE name NOT LIKE 'QA-ONLY %' AND name NOT LIKE 'TEST-%' ORDER BY id");
  const users = [];
  for (const [roleId, name] of roles) {
    const email = `qa-role-${roleId}@${MAIL_DOMAIN}`;
    const company = /^(Admin|Managing Director|Executive Director|CA \/ Chartered Accountant|Investor|Finance Director)/.test(name);
    sql(`INSERT INTO users (name, email, password_hash, role_id, is_active, force_password_change, two_fa_enabled, division_id)
         VALUES (${q(`QA Role ${name}`)}, ${q(email)}, ${q(hash)}, ${roleId}, 1, 0, 0, ${company ? 'NULL' : 1})`);
    users.push({ kind: 'role', label: name, email, roleId: Number(roleId) });
  }
  for (const p of inventory.permissions) {
    const rname = `QA-ONLY ${p.key}`;
    sql(`INSERT INTO roles (name, description) VALUES (${q(rname)}, 'Q3 QA single-permission role (removed after the run)')`);
    const roleId = sql(`SELECT id FROM roles WHERE name = ${q(rname)}`);
    sql(`INSERT INTO role_permissions (role_id, permission_id, is_enabled) SELECT ${roleId}, id, 1 FROM permissions WHERE permission_key = ${q(p.key)}`);
    const email = `qa-perm-${p.key.replace(/_/g, '-')}@${MAIL_DOMAIN}`;
    sql(`INSERT INTO users (name, email, password_hash, role_id, is_active, force_password_change, two_fa_enabled, division_id)
         VALUES (${q(`QA Perm ${p.key}`)}, ${q(email)}, ${q(hash)}, ${roleId}, 1, 0, 0, 1)`);
    users.push({ kind: 'permission', label: p.key, email, roleId: Number(roleId) });
  }
  // Assign every QA user to the sample order, so order pages are judged by permission, not by assignment.
  const order = sql("SELECT o.id FROM orders o WHERE o.division_id = 1 AND EXISTS (SELECT 1 FROM order_products p WHERE p.order_id = o.id) ORDER BY o.id DESC LIMIT 1");
  if (order) sql(`INSERT IGNORE INTO order_assignees (order_id, user_id) SELECT ${order}, id FROM users WHERE email LIKE '%@${MAIL_DOMAIN}'`);
  for (const u of users) {
    u.perms = new Set(rows(`SELECT p.permission_key FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ${u.roleId} AND rp.is_enabled = 1`).map((r) => r[0]));
  }
  return users;
}

function cleanup() {
  const ids = `(SELECT id FROM (SELECT id FROM users WHERE email LIKE '%@${MAIL_DOMAIN}') x)`;
  for (const t of ['order_assignees', 'user_department_memberships', 'user_permissions', 'notifications', 'login_attempts', 'two_fa_backup_codes', 'user_incentive_roles']) {
    try { sql(`DELETE FROM ${t} WHERE user_id IN ${ids}`); } catch { /* table without user_id */ }
  }
  try { sql(`UPDATE audit_log SET user_id = NULL WHERE user_id IN ${ids}`); } catch { /* nullable or not */ }
  try { sql(`DELETE FROM users WHERE email LIKE '%@${MAIL_DOMAIN}'`); } catch (e) { console.warn(`cleanup users: ${e.message.split('\n')[0]}`); }
  try {
    sql("DELETE rp FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.name LIKE 'QA-ONLY %'");
    sql("DELETE FROM roles WHERE name LIKE 'QA-ONLY %'");
  } catch (e) { console.warn(`cleanup roles: ${e.message.split('\n')[0]}`); }
}

function classify(route, user, status, body, location) {
  const gate = gateAllows(route, user.perms);
  const issues = [];
  if (status >= 500) issues.push(`HTTP ${status}`);
  const hit = ERROR_MARKERS.find((re) => re.test(body));
  if (hit) issues.push(`error text ${hit}`);
  const refused = status === 403 || status === 404 || (status >= 300 && status < 400 && /\/(login|dashboard)\b|^\/$/.test(location.replace(/^https?:\/\/[^/]+/, '')));
  if (gate === false && status === 200) issues.push('gate denies, page answered 200');
  if (issues.length) return { level: 'BUG', why: issues.join('; ') };
  if (gate === true && refused) return { level: 'NOTE', why: `gate allows, page refused (${status}${location ? ` → ${location.replace(/^https?:\/\/[^/]+/, '')}` : ''})` };
  return { level: 'OK' };
}

async function run(users, inventory, { routeFilter } = {}) {
  const fill = resolveIds();
  const staffGets = inventory.routes.filter((r) => r.method === 'GET' && (r.guards || []).includes('session')
    && !/logout|\/stop|\/end/.test(r.path));
  const results = [];
  for (const user of users) {
    let ctx;
    try { ctx = await loginContext(user.email); } catch (e) { results.push({ user: user.label, kind: user.kind, route: 'LOGIN', level: 'BUG', why: e.message }); continue; }
    const routes = routeFilter ? staffGets.filter((r) => routeFilter(r, user)) : staffGets;
    for (const route of routes) {
      const url = fill(route.path);
      let status; let body = ''; let location = '';
      try {
        const res = await ctx.get(url, { maxRedirects: 0, timeout: 60000 });
        status = res.status();
        location = res.headers().location || '';
        const type = res.headers()['content-type'] || '';
        if (/text|json|html/.test(type)) body = await res.text();
      } catch (e) {
        results.push({ user: user.label, kind: user.kind, route: `GET ${route.path}`, url, level: 'BUG', why: `request failed: ${e.message.split('\n')[0]}` });
        continue;
      }
      const c = classify(route, user, status, body, location);
      if (c.level !== 'OK') results.push({ user: user.label, kind: user.kind, route: `GET ${route.path}`, url, status, ...c });
      else results.push({ user: user.label, kind: user.kind, route: `GET ${route.path}`, level: 'OK' });
    }
    await ctx.dispose();
  }
  return results;
}

/** Export staff never see an order of another division (one sample from dev data, if any exists). */
async function divisionSample(users) {
  const other = rows('SELECT id, order_reference FROM orders WHERE division_id <> 1 LIMIT 1')[0];
  if (!other) return [{ level: 'NOTE', why: 'no non-Export order in this dev DB; division isolation left to DivisionIsolation tests' }];
  const out = [];
  for (const user of users.filter((u) => u.kind === 'role' && !u.perms.has('see_all_divisions'))) {
    const ctx = await loginContext(user.email);
    const list = await ctx.get('/orders?status=all', { maxRedirects: 0 });
    const page = await ctx.get(`/orders/${other[0]}`, { maxRedirects: 0 });
    if (list.status() === 200 && (await list.text()).includes(other[1])) out.push({ user: user.label, level: 'BUG', why: `order list shows ${other[1]} (another division)` });
    if (page.status() === 200) out.push({ user: user.label, level: 'BUG', why: `/orders/${other[0]} (another division) opened with 200` });
    await ctx.dispose();
  }
  return out;
}

function loadInventory() {
  const p = process.env.QA_INVENTORY || require('path').join(__dirname, 'qa-inventory.json');
  return JSON.parse(fs.readFileSync(p, 'utf8'));
}

module.exports = { setup, cleanup, run, divisionSample, loadInventory, gateAllows, isNode, baseUrl, PASSWORD, MAIL_DOMAIN, sql, rows };
