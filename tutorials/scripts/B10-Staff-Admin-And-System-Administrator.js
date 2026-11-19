'use strict';
// B10 — Admin and System Administrator (Group B, Staff). Owner request 18 Nov 2026.
// A tour as the Admin on the app's own Sample Data: create a staff login, give it a department, roles and
// permissions, task due days, SOP access, holidays, e-mail templates, lists, company settings (protected
// fields), backups, data export, test mode; then what the System Administrator can and cannot do.
// Written 18 Nov 2026 from views/users, departments, permission_admin, sop_access, holidays,
// email_templates, settings, backups, data_export, test_mode.

const { cleanStage } = require('./_overview');

const ROLES = {
  admin: { label: 'Admin', sub: 'NexaCrest staff · admin@email.com', initials: 'AD', color: '#374151' },
  sysadmin: { label: 'System Administrator', sub: 'Administration · sysadmin@email.com', initials: 'SA', color: '#4B5563' },
};

module.exports = {
  code: 'B10',
  file: 'B10-Staff-Admin-And-System-Administrator.mp4',
  group: 'Group B · Staff',
  title: 'Admin and System Administrator',
  subtitle: 'Users and rights, help access, templates and lists, settings and backups.',
  minutes: '5',
  restoreDb: true,

  async setup(demo) {
    cleanStage(demo);
    demo.demoStaff('admin');
    demo.demoStaff('sysadmin');
    demo.sql(`INSERT INTO user_department_memberships (user_id, designation_id, starts_on, is_default)
      SELECT u.id, dd.id, CURDATE(), 1 FROM users u JOIN department_designations dd JOIN departments d ON d.id = dd.department_id
      WHERE u.email = 'sysadmin@email.com' AND d.code = 'ADMIN'`);
    return { actors: {
      admin: { ...ROLES.admin, cookies: await demo.staffCookies('admin') },
      sysadmin: { ...ROLES.sysadmin, cookies: await demo.staffCookies('sysadmin') },
    } };
  },

  steps: () => [
    { card: 'title', say: 'This video is for the Admin and the System Administrator. You keep the application itself in order: the people who log in and what they may do, the help they see, the templates and lists behind every form, the company settings, and the safety net: backups.' },
    { card: 'list', kicker: 'Your roles', title: 'Admin · System Administrator', items: [
      'Admin: full system access, users, roles, settings, data export and the audit log.',
      'System Administrator: users, settings, templates and lists; no business money, no data export.',
      'Neither approves business decisions such as prices; that is the MD and ED.',
      'Every change you make is in the audit log, with your reason.',
    ], say: 'Your roles. The Admin has full system access: users, roles, settings, data export and the audit log. The System Administrator looks after users, settings, templates and lists, but sees no business money and cannot export the data. Neither of you approves business decisions such as prices; that is the Managing Director and the Executive Director. And every change you make is in the audit log, with your reason.' },

    // ---------- 1. People ----------
    { card: 'chapter', title: 'A new colleague', subtitle: 'Users · Departments.', say: 'Chapter one. Someone new joins.' },
    { as: 'admin', goto: '/users#create-user', caption: 'Users & roles › + Add user', say: 'We are the Admin. A new Export Executive joins. Under Users and roles, Add user: the name, their work e-mail, a phone number, and the role. Create User. The app shows a one-time temporary password, once: give it to them yourself, it is never shown again, and they must change it the first time they log in. Every login then also asks for a six-digit code from their e-mail.', do: async (d) => {
      const f = d.page.locator('form').filter({ has: d.page.locator('#create-user, [name="role_id"]') }).filter({ has: d.page.locator('input[name="email"]') }).first();
      await d.scrollTo(f.locator('input[name="name"]'));
      await d.type(f.locator('input[name="name"]'), 'Export Executive (new)');
      await d.type(f.locator('input[name="email"]'), 'export.executive2@email.com');
      const ph = f.locator('input[name="phone"]'); if (await ph.count()) await d.type(ph, '+91-0000000000');
      const sel = f.locator('select[name="role_id"]');
      await d.select(sel, { label: (await sel.locator('option').allInnerTexts()).find((t) => /^Export Executive/.test(t.trim())) });
      await d.clickNav(f.getByRole('button', { name: 'Create User' }));
      // The one-time password is masked on camera (a tutorial never shows a password).
      await d.page.evaluate(() => document.querySelectorAll('.alert').forEach((a) => { a.innerHTML = a.innerHTML.replace(/(temporary password:\s*)(\S+)/i, '$1••••••••'); }));
      await d.point('.alert', 2000);
    } },
    { goto: '/admin/departments', caption: 'Users & roles › Departments', say: 'Then give them a department: in Departments, add them to Sales and Export as an executive. Their rights now follow from that designation. Preview the app as this shows exactly what they will see.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.locator('tr, .card').filter({ hasText: 'Sales & Export' }).first(), 1800); } },
    { goto: '/admin/permissions', caption: 'Users & roles › Roles and permissions', say: 'Roles and permissions shows each role and its rights, in a matrix. You can give one person an extra right beyond their role, with a reason. Rights that touch money, such as approving prices, stay with the directors.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.getByRole('heading', { name: 'Role Matrix' }), 1500); } },
    { goto: '/admin/task-types', caption: 'Users & roles › Task due days', say: 'Task due days: how many days each department has for each kind of task, before it turns overdue and is escalated.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.locator('table').first(), 1500); } },
    { goto: '/admin/sop-access', caption: 'Users & roles › SOP access', say: 'SOP access: choose a role, and tick the chapters of the operations guide it sees. The Managing Director and the Admin always see every chapter, so nobody can hide the help from the people who manage it.', do: async (d) => { await d.point('h1', 1800); } },

    // ---------- 2. Templates and lists ----------
    { card: 'chapter', title: 'Templates and lists', subtitle: 'Settings › Documents · Lists.', say: 'Chapter two. What the forms offer.' },
    { goto: '/email-templates', caption: 'Settings › Documents › E-mail templates', say: 'E-mail templates hold the wording of every e-mail the app sends. Templates are edited, never deleted, and every e-mail already sent keeps its own copy of exactly what it said.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.locator('table').first(), 1500); } },
    { goto: '/admin/dropdown-options', caption: 'Settings › Lists › Drop-down lists', say: 'Lists hold the choices in the drop-downs: units, finishes, container types, scheme names, and more, plus ports, port routes, payment presets, G S T rates and import duties. Add an item when the business needs it; old items are switched off, not deleted, so old orders stay correct.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.locator('table, form').first(), 1500); } },
    { goto: '/holidays', caption: 'Settings › Holidays', say: 'The holiday calendar: the dated holidays skipped when the app counts working days, for example for the buyer\'s dispute response time, or a payout that would fall on a holiday. Add the year\'s state and festival holidays each January.', do: async (d) => { await d.point('h1', 1800); } },

    // ---------- 3. Settings and safety ----------
    { card: 'chapter', title: 'Settings and safety', subtitle: 'Company · Backups · Data export.', say: 'Chapter three. Keeping the system safe.' },
    { goto: '/settings', caption: 'Settings › Company', say: 'Company settings hold every value our documents print. Protected fields, such as the bank account, show a lock: press Unlock on the row before you can change it, and give a reason. The mail copy list is for the Super Admin only.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.getByRole('button', { name: 'Expand all' }), 1200); } },
    { goto: '/admin/backups', caption: 'Settings › Backups', say: 'Backups shows what the nightly backup jobs report: the database and every order document, kept for ten years, encrypted and copied off-site. On this practice copy, backups are not set up, so the page says so. How to restore is in the SOP.', do: async (d) => { await d.point('h1', 1800); } },
    { goto: '/admin/data-export', caption: 'Settings › Data export', say: 'Data export, for the Admin only: the structure of the database, and a full data export, for moving to a new server. The file holds everything, so keep it safe.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.getByRole('heading', { name: /Full data export/ }), 1500); } },
    { card: 'list', kicker: 'Good to know', title: 'Test mode is for the Super Admin', items: [
      'Test mode runs the whole order process for practice, with documents and reports.',
      'Every business e-mail is redirected to a test address while it is on.',
      'Only a Super Admin opens it: by default the Managing Director and the Executive Director.',
      'Switch it off again before real work.',
    ], say: 'One more safety net, which is not yours to switch: test mode. It lets the team run the whole order process for practice, with documents and reports, while every business e-mail goes to a test address. Only a Super Admin can open it, by default the Managing Director and the Executive Director. If you try, the app refuses the page.' },

    // ---------- 4. System Administrator ----------
    { card: 'chapter', title: 'The System Administrator', subtitle: 'Same tools, no money.', say: 'Chapter four. The System Administrator.' },
    { as: 'sysadmin', goto: '/users', caption: 'System Administrator', say: 'Here is the System Administrator. Users, settings, templates and lists work exactly as you just saw.', do: async (d) => { await d.point('h1', 1500); } },
    { goto: '/admin/data-export', caption: 'Not open to this role', say: 'But some pages are closed: the data export, and every page with business money. The app says so plainly: this page is not open to your role.', do: async (d) => { await d.point('h1', 2000); } },

    { card: 'list', kicker: 'Recap', title: 'Admin and System Administrator', items: [
      'New colleague: Add user (one-time password), then the department.',
      'Roles and permissions, task due days and SOP access.',
      'E-mail templates, lists and holidays behind every form.',
      'Protected settings, backups and data export (Admin only); test mode is the MD and ED.',
    ], next: 'B11 · CA, Auditor and Investor', say: 'Let us recap. For a new colleague, add the user, hand over the one-time password, and add them to their department. Keep the roles and permissions, the task due days and the SOP access right. Look after the e-mail templates, the lists and the holidays behind every form. And protect the settings and watch the backups; the data export is for the Admin only, and test mode for the Managing Director and the Executive Director. Next: the chartered accountant, the auditor and the investor.' },
  ],
};
