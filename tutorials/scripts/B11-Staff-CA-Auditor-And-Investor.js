'use strict';
// B11 — CA / Chartered Accountant, Viewer / Auditor and Investor (Group B, Staff). Owner request 18 Nov 2026.
// Three read-only roles on the app's own Sample Data (one order closed with its money recorded, scripts/_a02.js).
// The CA: the CA / Accounting module (revenue, export benefits, expenses and TDS, bank reconciliation, the INR
// Settlement Register, the CAFIN export) and every order read-only. The Internal Auditor: orders, clients,
// reports and the audit log, nothing changeable. The Investor: dashboard, cockpit and the count-only reports.
// Written 18 Nov 2026 from the roles' seed permissions (Handbook 4.2, 4.9) and views/ca, reports, audit_log.

const A = require('./_a02');

let demo;
let order = 0;
const ROLES = {
  ca: { label: 'Chartered Accountant (external)', sub: 'Audit & Compliance · ca@email.com', initials: 'CA', color: '#334155' },
  auditor: { label: 'Internal Auditor', sub: 'Audit & Compliance · auditor@email.com', initials: 'IA', color: '#475569' },
  investor: { label: 'Investor (view only)', sub: 'investor@email.com', initials: 'IN', color: '#64748B' },
};

module.exports = {
  code: 'B11',
  file: 'B11-Staff-CA-Auditor-And-Investor.mp4',
  group: 'Group B · Staff',
  title: 'CA, Auditor and Investor',
  subtitle: 'Three read-only views: the accounts, the audit trail, and the company at a glance.',
  minutes: '3',
  restoreDb: true,

  async setup(dm) {
    demo = dm;
    for (const k of ['ca', 'auditor', 'investor']) demo.demoStaff(k);
    const ctx = await A.setup(demo);
    order = ctx.orderId;
    for (const [email, title] of [['ca@email.com', 'External CA'], ['auditor@email.com', 'Internal Auditor']]) {
      demo.sql(`INSERT INTO user_department_memberships (user_id, designation_id, starts_on, is_default)
        SELECT u.id, dd.id, CURDATE(), 1 FROM users u JOIN department_designations dd JOIN departments d ON d.id = dd.department_id
        WHERE u.email = '${email}' AND d.code = 'AUDIT' AND dd.title = '${title}'`);
    }
    // One order taken through to the balance, so the registers have real lines.
    A.move(demo, 'part1Done', order);
    A.move(demo, 'supplierPo', order);
    A.move(demo, 'freight', order);
    for (const k of ['ca', 'auditor', 'investor']) ctx.actors[k] = { ...ROLES[k], cookies: await demo.staffCookies(k) };
    return ctx;
  },

  steps: ({ orderId = 0 } = {}) => { order = orderId || order; return [
    { card: 'title', say: 'This video is for three people who look, but do not change anything: our outside chartered accountant, the internal auditor, and investors. Each sees exactly what their work needs, and the app refuses every page that would change something.' },
    { card: 'list', kicker: 'Three read-only roles', title: 'Who sees what', items: [
      'Chartered Accountant: the CA / Accounting module, every order read-only, the CAFIN.',
      'Internal Auditor: orders, clients, products, reports and the full audit log.',
      'Investor: the dashboard, the cockpit and the reports that show counts, not money.',
      'Nobody here can save, approve or delete anything.',
    ], say: 'Three read-only roles. The chartered accountant sees the accounting module, every order read-only, and the internal cost sheet called CAFIN. The internal auditor sees orders, clients, products, reports and the full audit log. The investor sees the dashboard, the cockpit, and the reports that show counts rather than money. None of you can save, approve or delete anything.' },

    // ---------- 1. CA ----------
    { card: 'chapter', title: 'The Chartered Accountant', subtitle: 'Finance & accounts › CA / Accounting.', say: 'Chapter one. Our chartered accountant.' },
    { as: 'ca', goto: '/ca', caption: 'CA / Accounting', say: 'We are the outside chartered accountant. The accounting area is kept separate from the order pipeline. Revenue by financial year, export benefits, G S T refunds under the letter of undertaking, and the CAFIN export for the whole year.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.getByRole('heading', { name: /Revenue & Benefits/ }), 1800); } },
    { caption: 'Expenses, TDS and the bank', say: 'Every expense with its tax deducted at source, and the summary of what is payable; the bank statement and its reconciliation. You read these; the Finance team keeps them.', do: async (d) => { await d.point(d.page.getByRole('heading', { name: /Expenses & TDS/ }), 1300); await d.point(d.page.getByRole('heading', { name: /Bank & Reconciliation/ }), 1300); } },
    { caption: 'INR Settlement Register', say: 'The INR settlement register: every buyer payment cleared, in foreign currency and in the rupees actually credited, with the exchange gain or loss, and the bank\'s proof of realisation.', do: async (d) => { const x = d.page.getByRole('heading', { name: /INR Settlement Register/ }); await d.scrollTo(x); await d.point(x, 1000); await d.point(d.page.locator('tr').filter({ hasText: 'Harbourline' }).first(), 1800); } },
    { goto: `/orders/${order}`, caption: 'Every order, read-only', say: 'You can open any order, read every figure and download its documents, including the CAFIN. But there are no buttons to change anything: the steps are shown, not offered.', do: async (d) => { await d.point('h1', 1000); await d.point('.process-stepper', 1500); } },

    // ---------- 2. Auditor ----------
    { card: 'chapter', title: 'The Internal Auditor', subtitle: 'Orders · Reports · Audit log.', say: 'Chapter two. The internal auditor.' },
    { as: 'auditor', goto: '/reports', caption: 'Reports', say: 'The internal auditor sees the reports, with their money: sales, payments and receivables, disputes and amendments, freight and suppliers.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.getByRole('heading', { name: /Payments & receivables/ }), 1500); } },
    { goto: '/audit-log', caption: 'Reports › Audit log', say: 'And the audit log: every action, by every person, with the time, the old and the new value, and the reason. Nobody, not even the Admin, can edit or delete a line. Filter it by type of record, by record, by person, or by type of action.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.getByText(/Immutable/).first(), 1500); await d.point(d.page.locator('select, input').first(), 1200); } },

    // ---------- 3. Investor ----------
    { card: 'chapter', title: 'The Investor', subtitle: 'Counts and status, not money.', say: 'Chapter three. Investors.' },
    { as: 'investor', goto: '/cockpit', caption: 'Management cockpit', say: 'An investor sees the management cockpit: open work per department, what is overdue and escalated, and orders by status. Counts and status only.', do: async (d) => { await d.point(d.page.getByRole('heading', { name: /Management cockpit/ }), 1500); await d.point(d.page.locator('table').first(), 1500); } },
    { goto: '/reports', caption: 'Reports without amounts', say: 'In Reports, the investor is offered only the reports without amounts: the conversion rate, the management cockpit and the operations queues. If the company wants investors to see revenue or profit, the Managing Director adds those rights to the Investor role; that is a business decision.', do: async (d) => { await d.point('h1', 2000); } },

    { card: 'list', kicker: 'Recap', title: 'CA, Auditor and Investor', items: [
      'CA: the accounting module, the INR register, every order read-only, the CAFIN.',
      'Internal Auditor: orders, clients, reports with money, and the audit log.',
      'Investor: dashboard, cockpit and count-only reports.',
      'All three: look, never change; every page that changes something is refused.',
    ], next: 'Group D · Buyers · Group C · Associates', say: 'Let us recap. The chartered accountant reads the accounting module, the INR register, every order and the CAFIN. The internal auditor reads orders, clients, reports with money, and the audit log. The investor sees the dashboard, the cockpit and the reports without amounts. All three can look, and never change. That completes the staff videos. Buyers and associates have their own series, Group D and Group C.' },
  ]; },
};
