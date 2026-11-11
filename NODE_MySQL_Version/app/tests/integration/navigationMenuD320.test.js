'use strict';

const fs = require('fs');
const path = require('path');
const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const navigation = require('../../src/services/navigation');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of NavigationMenuD320Test.php. Owner decision D-320 (11 Nov 2026,
 * point 26): the left menu goes from about 60 entries to 13; every page of a
 * section shows that section's menu bar; the order page gets a sticky bar of
 * action buttons. One config, src/config/navigation.json (the same file in
 * the PHP stack), drives both menus.
 */
describe('D-320: the short left menu, the section menu bars and the order action bar', () => {
  // Every page the old left menu linked to, plus the pages it only reached through links inside other pages.
  const OLD_MENU_PAGES = [
    '/approvals', '/getting-started', '/reference-docs', '/sop', '/gallery', '/reviews', '/my-tasks', '/email-approvals',
    '/ca', '/ca/zoho-sync', '/products', '/clients', '/associates', '/agreements', '/orders', '/orders/archived',
    '/client-intake', '/pi-intake-review', '/reorder-requests', '/disputes', '/complaints', '/procurement', '/import', '/merchant',
    '/reports', '/reports/freight-cost', '/reports/suppliers', '/incentives/payouts', '/my-incentives', '/cockpit', '/audit-log',
    '/admin/setup-checklist', '/settings', '/admin/email-settings', '/holidays', '/hs-codes', '/logistics-partners', '/suppliers',
    '/ports', '/payment-presets', '/compliance-task-types', '/admin/dropdown-options', '/email-templates', '/ui-templates',
    '/watermarks', '/company-assets', '/signatories', '/admin/divisions', '/admin/gst-rates', '/admin/import-duty-rates',
    '/admin/departments', '/admin/task-types', '/admin/permissions', '/users', '/admin/field-protection', '/incentives/settings',
    '/pricing/settings', '/admin/overrides', '/sample-data', '/admin/data-export', '/admin/backups', '/super-admin',
    '/admin/self-approval', '/test-mode',
    // Reached before only from links inside pages (the TDS register had no link at all).
    '/associates/tds', '/associates/registrations', '/associates/price-requests', '/associates/ladder', '/associates/access-levels',
    '/associates/new', '/reports/associates', '/products/categories', '/products/price-rules', '/pricing/commission-levels',
    '/pricing/specifications', '/products/fix-hs-codes', '/products/link-suppliers', '/procurement/suppliers', '/admin/sop-access',
    '/settings/document-cleanup', '/port-routes', '/settings/remittance-reasons', '/settings/associate-tds', '/clients/create',
    '/products/create',
  ];
  // Decided bar (D-320 register): Procurement & suppliers has no "+ Add" entry; + Add supplier stays a button on the
  // Suppliers page, which shows the section's bar.
  const PAGE_BUTTON_ONLY = ['/suppliers/create'];
  const ALL_FLAGS = {
    superAdmin: true, sopOpen: true, approver: true, mod_supplier_purchase: true, mod_import_stock: true,
    mod_merchant_trade: true, mod_associates: true, mod_team_incentive: true, div_gst: true, div_import_duty: true,
  };
  const all = () => true;
  const only = (...keys) => (p) => keys.includes(p);
  const pathOf = (href) => href.split('?')[0].split('#')[0];
  const leaves = () => {
    const out = [];
    for (const section of navigation.config().sections) {
      for (const item of section.items) for (const leaf of (item.children || [item])) out.push({ ...leaf, section: section.key });
    }
    return out;
  };

  afterAll(async () => {
    await db.pool.end();
  });

  async function loginAs(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-nav-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const password = 'JestNavD320!';
    await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Nav User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(password, 4), role_id: role.id }
    );
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password });
    return agent;
  }

  it('the config is the same file in both stacks', () => {
    const php = path.join(__dirname, '../../../../PHP_MySQL_Version/app/src/Config/navigation.json');
    if (!fs.existsSync(php)) return; // the PHP stack is not next to this one
    expect(fs.readFileSync(path.join(__dirname, '../../src/config/navigation.json'), 'utf8')).toBe(fs.readFileSync(php, 'utf8'));
  });

  it('the left menu has 13 entries in the decided order', () => {
    const sections = navigation.config().sections;
    expect(sections.map((s) => s.key)).toEqual(['dashboard', 'approvals', 'tasks', 'orders', 'clients', 'associates', 'products', 'procurement', 'finance', 'reports', 'help', 'users', 'settings']);
    expect(sections.map((s) => s.label)).toEqual(['Dashboard', 'Approvals', 'My tasks', 'Orders', 'Clients', 'Associates', 'Products', 'Procurement & suppliers', 'Finance & accounts', 'Reports', 'Help & SOP', 'Users & roles', 'Settings']);
    const built = navigation.build(all, ALL_FLAGS, {}, '/', {});
    expect(built.sidebar).toHaveLength(13);
    expect(built.sidebar.map((s) => s.heading).filter(Boolean)).toEqual(['Work', 'Overview', 'Help', 'Admin']);
  });

  it('every old menu page is still reachable from a menu and shows its section bar', () => {
    // A section without a menu bar of its own (Dashboard, My tasks) is reached from its left-menu entry.
    const linked = new Set([...leaves().map((l) => pathOf(l.href)), ...navigation.config().sections.filter((s) => s.href).map((s) => pathOf(s.href))]);
    for (const page of OLD_MENU_PAGES) {
      expect([page, linked.has(page)]).toEqual([page, true]);
      expect([page, navigation.sectionKeyFor(page) !== null]).toEqual([page, true]);
    }
    for (const page of PAGE_BUTTON_ONLY) {
      expect([page, linked.has(page)]).toEqual([page, false]);
      expect([page, navigation.sectionKeyFor(page)]).toEqual([page, 'procurement']);
    }
    expect(navigation.build(all, ALL_FLAGS, {}, '/', {}).currentKey).toBe('dashboard');
  });

  it('every menu link needs exactly what its route needs (the same permissions, or Super Admin)', () => {
    const source = fs.readFileSync(path.join(__dirname, '..', '..', 'src', 'server.js'), 'utf8').split('\n');
    for (const leaf of leaves()) {
      const p = pathOf(leaf.href);
      const line = source.find((l) => l.startsWith(`app.get('${p}',`));
      expect([p, !!line]).toEqual([p, true]);
      const one = line.match(/requirePermission\('([^']+)'\)/);
      const any = line.match(/requirePermissionAny\(\[([^\]]+)\]\)/);
      const route = one ? [one[1]] : (any ? [...any[1].matchAll(/'([^']+)'/g)].map((k) => k[1]) : null);
      if (leaf.href.startsWith('/approvals?tab=')) {
        expect([leaf.href, route]).toEqual([leaf.href, null]); // the Approvals page is open to everyone; a tab shows only to those who approve that kind
        continue;
      }
      const menu = leaf.when && leaf.when.any ? [...leaf.when.any].sort() : null;
      expect([leaf.href, menu]).toEqual([leaf.href, route ? route.sort() : null]);
      if (((leaf.when || {}).flags || []).includes('superAdmin')) expect([p, line.includes('requireSuperAdmin')]).toEqual([p, true]);
    }
  });

  it('an entry shows only when the person can open a page in it, and links to the first one', () => {
    const nothing = navigation.build(only(), {}, {}, '/', {});
    expect(nothing.sidebar.map((s) => s.key)).toEqual(['dashboard', 'approvals', 'help']);
    expect(nothing.sidebar.map((s) => s.href)).toEqual(['/', '/reviews', '/reference-docs']);

    const ports = navigation.build(only('manage_ports'), {}, {}, '/', {});
    expect(ports.sidebar.map((s) => s.key)).toEqual(['dashboard', 'approvals', 'help', 'settings']);
    expect(ports.sidebar[3].href).toBe('/ports');

    const viewer = navigation.build(only('view_orders', 'view_clients', 'view_reports', 'view_audit_log'), {}, {}, '/', {});
    expect(viewer.sidebar.map((s) => s.key)).toEqual(['dashboard', 'approvals', 'tasks', 'orders', 'clients', 'reports', 'help']);

    // A module switched off (D-257) hides its pages; a Super Admin page needs the Super Admin.
    expect(navigation.build(only('view_purchase_prices'), { mod_import_stock: false, mod_merchant_trade: false }, {}, '/', {}).sidebar.map((s) => s.key)).not.toContain('procurement');
    const buyer = navigation.build(only('view_purchase_prices'), { mod_merchant_trade: true }, {}, '/merchant', {});
    expect(buyer.sidebar.find((s) => s.key === 'procurement').href).toBe('/merchant');
    const admin = navigation.build(all, { ...ALL_FLAGS, superAdmin: false }, {}, '/users', {});
    const labels = admin.bar.items.map((i) => i.label);
    expect(labels).not.toContain('Self-approval rules');
    expect(labels).toContain('Roles and permissions');
  });

  it('the bar marks the page shown and carries the counts', () => {
    const active = (built) => {
      const out = [];
      for (const item of built.bar.items) {
        if (!item.active) continue;
        out.push(item.label);
        for (const child of item.children || []) if (child.active) out.push(child.label);
      }
      return out;
    };
    expect(active(navigation.build(all, ALL_FLAGS, {}, '/orders/12', {}))).toEqual(['Active']);
    expect(active(navigation.build(all, ALL_FLAGS, {}, '/orders/archived', {}))).toEqual(['Archived']);
    expect(active(navigation.build(all, ALL_FLAGS, {}, '/reports/associates', {}))).toEqual(['Report']); // the longest prefix wins
    expect(active(navigation.build(all, ALL_FLAGS, {}, '/pricing/settings', {}))).toEqual(['Pricing', 'Pricing settings (margin, buffer, exchange rate)']);
    expect(active(navigation.build(all, ALL_FLAGS, {}, '/approvals', { tab: 'prices' }))).toEqual(['Prices']);
    expect(active(navigation.build(all, ALL_FLAGS, {}, '/approvals', {}))).toEqual(['All']);
    expect(navigation.sectionKeyFor('/cafin')).toBeNull(); // "/ca" never covers "/cafin"

    const built = navigation.build(all, ALL_FLAGS, { associateRegistrations: 2, approvals: 5, myTasks: 3, settingsExpiry: 1 }, '/associates', {});
    expect(built.bar.items.find((i) => i.label === 'Registrations').count).toBe(2);
    const side = Object.fromEntries(built.sidebar.map((s) => [s.key, s.count]));
    expect([side.approvals, side.tasks, side.settings, side.orders]).toEqual([5, 3, 1, 0]);
  });

  it('the pages draw the menus from the config', async () => {
    const admin = await loginAs('Admin');
    const dashboard = (await admin.get('/')).text;
    expect(dashboard.split(' data-nav="').length - 1).toBe(13);
    expect(dashboard).not.toContain('class="section-nav"');
    expect(dashboard).not.toContain('class="nav-group"');

    const associates = (await admin.get('/associates?view=list')).text;
    expect(associates).toContain('<nav class="section-nav" aria-label="Associates menu" data-section="associates">');
    expect(associates).toContain('href="/associates/tds">TDS register');
    expect(associates).toContain('href="/associates" class="active" aria-current="page">All associates');
    expect(associates).toContain('href="/associates" class="active" data-nav="associates"');

    const viewer = await loginAs('Viewer / Auditor');
    const clients = (await viewer.get('/clients')).text;
    expect(clients).toContain('data-section="clients"');
    expect(clients).not.toContain('href="/clients/create"');
  });

  it('the order page has a sticky action bar instead of the grey link', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const admin = await loginAs('Admin');
    const page = (await admin.get(`/orders/${orderId}`)).text;
    const start = page.indexOf('data-testid="order-action-bar"');
    expect(start).toBeGreaterThan(-1);
    const bar = page.slice(start, page.indexOf('<div class="card page-wide">', start));
    expect(bar).toContain(`href="/orders/${orderId}/edit">Edit order</a>`);
    expect(bar).toContain('href="#document-review-approval">Documents</a>');
    expect(bar).toContain(`href="/reports/order/${orderId}">Full report</a>`);
    expect(bar).toContain(`action="/orders/${orderId}/duplicate"`);
    expect(bar).toContain('Request cancellation</button>');
    expect(bar).toContain(`href="/orders/${orderId}/audit-log">Audit trail</a>`);
    expect(page).not.toContain('Edit Order Details');
    expect(page.split(`action="/orders/${orderId}/duplicate"`).length - 1).toBe(1);
    expect(page).toContain('id="request-cancellation-btn"');

    const viewer = await loginAs('Viewer / Auditor');
    const seen = (await viewer.get(`/orders/${orderId}`)).text;
    expect(seen).toContain('data-testid="order-action-bar"');
    expect(seen).not.toContain('/edit">Edit order</a>');
    expect(seen).not.toContain('/duplicate"');
    expect(seen).not.toContain('Request cancellation</button>');
  });

  it('the main buttons stick to the top (order, client and associate pages)', () => {
    const css = fs.readFileSync(path.join(__dirname, '../../public/css/app.css'), 'utf8');
    expect(css).toMatch(/\.order-action-bar,\.sticky-actions\{position:sticky;top:0;/);
    const views = path.join(__dirname, '../../views');
    expect(fs.readFileSync(path.join(views, 'clients/show.njk'), 'utf8')).toContain('class="btn-row sticky-actions"');
    expect(fs.readFileSync(path.join(views, 'associates/show.njk'), 'utf8')).toContain('class="page-header-row sticky-actions"');
  });
});
