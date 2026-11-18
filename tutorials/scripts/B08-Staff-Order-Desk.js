'use strict';
// B08 — Order desk: Order Coordinator and Operations Manager (Group B, Staff). Owner request 18 Nov 2026.
// The sample orders plus one CIF order (scripts/_a02.js) confirmed by the buyer. On camera the Order Coordinator
// follows every order without seeing prices, sets the department owners on an order, works from the
// management cockpit and the operations queues; the Operations Manager also manages the order's assignees.
// Written 18 Nov 2026 from orders/show.php (department-owners, assignees), coordination/cockpit.php,
// reports/queues.php and my-tasks.

const A = require('./_a02');

let demo;
let order = 0;
const at = (hash = '') => `/orders/${order}${hash}`;
const ROLES = {
  coord: { label: 'Order Coordinator', sub: 'Order Desk · order.coordinator@email.com', initials: 'OC', color: '#0E7490' },
  opsmgr: { label: 'Operations Manager', sub: 'Order Desk · operations.manager@email.com', initials: 'OM', color: '#155E75' },
};
const MEMBERS = [
  ['export.executive@email.com', 'SALES', 'executive'], ['sales.manager@email.com', 'SALES', 'manager'],
  ['procurement@email.com', 'PROC', 'executive'], ['logistics@email.com', 'LOG', 'executive'],
  ['accounts@email.com', 'FIN', 'executive'], ['finance.manager@email.com', 'FIN', 'manager'],
  ['compliance@email.com', 'EXPORT_COMPLIANCE', 'executive'],
  ['order.coordinator@email.com', 'OPS', 'executive'], ['operations.manager@email.com', 'OPS', 'manager'],
];

module.exports = {
  code: 'B08',
  file: 'B08-Staff-Order-Desk.mp4',
  group: 'Group B · Staff',
  title: 'Order Desk',
  subtitle: 'Order Coordinator and Operations Manager: every order\'s status, owners, tasks and the cockpit.',
  minutes: '8',
  restoreDb: true,

  async setup(dm) {
    demo = dm;
    demo.demoStaff('coord');
    demo.demoStaff('opsmgr');
    const ctx = await A.setup(demo);
    order = ctx.orderId;
    for (const [email, dept, level] of MEMBERS) {
      demo.sql(`INSERT INTO user_department_memberships (user_id, designation_id, starts_on, is_default)
        SELECT u.id, dd.id, CURDATE(), 1 FROM users u JOIN department_designations dd JOIN departments d ON d.id = dd.department_id
        WHERE u.email = '${email}' AND d.code = '${dept}' AND dd.level = '${level}'
          AND NOT EXISTS (SELECT 1 FROM user_department_memberships m WHERE m.user_id = u.id)`);
    }
    // The buyer has confirmed: Procurement, Logistics and Export Compliance have work waiting.
    A.move(demo, 'part1Done', order);
    ctx.actors.coord = { ...ROLES.coord, cookies: await demo.staffCookies('coord') };
    ctx.actors.opsmgr = { ...ROLES.opsmgr, cookies: await demo.staffCookies('opsmgr') };
    return ctx;
  },

  steps: ({ orderId = 0 } = {}) => { order = orderId || order; return [
    { card: 'title', say: 'This video is for the order desk: the Order Coordinator and the Operations Manager. You are the people who keep every order moving. You see the status of every order, make sure each department has an owner for it, and chase whatever is late. You do not see prices or money; you do not need them.' },
    { card: 'list', kicker: 'Your roles', title: 'Order Desk · coordinator and manager', items: [
      'Coordinator: the status of every order, department owners, My tasks and the cockpit.',
      'Operations Manager: the same, plus who is assigned to each order.',
      'No prices, costs or payments: amounts are hidden from you.',
      'Your job: no order without an owner, no task forgotten.',
    ], say: 'Your roles. The Order Coordinator sees the status of every order, sets the owner for each department, and works from My tasks and the management cockpit. The Operations Manager does the same, and also decides who is assigned to each order. Neither of you sees prices, costs or payments. Your job, in one line: no order without an owner, and no task forgotten.' },

    // ---------- 1. Every order ----------
    { card: 'chapter', title: 'Every order at a glance', subtitle: 'Orders, stages, no amounts.', say: 'Chapter one. Seeing every order.' },
    { as: 'coord', goto: '/orders', caption: 'Orders', say: 'We are the Order Coordinator. Orders shows every order in the company, not only yours: the reference, the buyer, the stage, and who is assigned. Notice there is no order value: amounts are hidden from the order desk. Filter by active, overdue, complete, lost or cancelled, or switch to the Kanban board to see where work piles up.', do: async (d) => { await d.point('h1', 900); await d.point(d.page.locator('a.entity-card').first(), 1500); await d.point(d.page.locator('a, button').filter({ hasText: /^Overdue/ }).first(), 1000); } },
    { goto: at(), caption: 'The order page', say: 'Open our sample order. The stage tracker shows where it is: the buyer has confirmed, so stage five, the supplier\'s purchase order, is next. You see every step, but no prices, costs or payments.', do: async (d) => { await d.point('h1', 900); await d.point('.process-stepper', 1500); await d.point('.process-step[data-stage="5"]', 1200); } },

    // ---------- 2. Owners ----------
    { card: 'chapter', title: 'Department owners', subtitle: 'One named person per department.', say: 'Chapter two. Who owns this order?' },
    { goto: at('#department-owners'), caption: 'Owners', say: 'On the right, Owners: one line per department, Sales and Export, Procurement, Logistics, Accounts, Export Compliance. An owner is the person in that department who is responsible for this order. Their tasks for this order go to them, and the cockpit flags any order without one.', do: async (d) => { const p = d.page.locator('#department-owners'); await d.scrollTo(p); await d.point(p, 2200); } },
    { caption: 'Set the owners', say: 'Choose the owner for Procurement from the people in that department, and press Set. Then Logistics, and Export Compliance. Each change is kept in the audit log.', do: async (d) => {
      const p = d.page.locator('#department-owners');
      for (const dept of ['Procurement', 'Logistics', 'Export Compliance']) {
        const row = p.locator('.kv-grid > div').filter({ hasText: dept }).first();
        if (!(await row.count())) continue;
        const sel = row.locator('select[name="user_id"]');
        if (!(await sel.count())) continue;
        const opts = await sel.locator('option').allInnerTexts();
        if (opts.length < 2) continue;
        await d.select(sel, { label: opts[1] });
        await d.clickNav(row.getByRole('button', { name: 'Set' }));
        await d.scrollTo(d.page.locator('#department-owners'));
      }
      await d.point(d.page.locator('#department-owners'), 1500);
    } },

    // ---------- 3. Tasks and the cockpit ----------
    { card: 'chapter', title: 'Tasks and the cockpit', subtitle: 'What is waiting, what is late.', say: 'Chapter three. Chasing the work.' },
    { goto: '/my-tasks', caption: 'My tasks', say: 'My tasks lists what is waiting, with due dates. As the order desk you can switch between your own tasks and all tasks of your department. Overdue tasks turn red, and after a few days they are escalated to management.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.locator('.kanban-board, table').first(), 1800); } },
    { goto: '/cockpit', caption: 'Management cockpit', say: 'The management cockpit shows, for each department, the open tasks, the overdue ones, the escalated ones, and the orders that still have no owner. Below: everything escalated, with who should act, and any supplier who is late. Counts only, no money. Start your day here, and chase from the top.', do: async (d) => { await d.point(d.page.getByRole('heading', { name: /Management cockpit/ }), 1000); await d.point(d.page.locator('table').filter({ hasText: 'Open tasks' }).first(), 2000); } },
    { goto: '/reports/queues', caption: 'Reports › Operations queues', say: 'And Operations queues: how many orders wait in each queue right now, and the funnel for any period, from quotation to closure. Use it in your weekly meeting.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.locator('table').first(), 1800); } },

    // ---------- 4. Operations Manager ----------
    { card: 'chapter', title: 'Assigning people', subtitle: 'Operations Manager · order assignees.', say: 'Chapter four. For the Operations Manager.' },
    { as: 'opsmgr', goto: at(), caption: 'Assigned to', say: 'Here is the Operations Manager. Under the order\'s title: Assigned to. Assignees are the people who work on the order day to day; staff who only see their own orders see an order only when they are assigned to it. Add an assignee from the list, and press Add. The cross removes one, but an order always keeps at least one.', do: async (d) => {
      const f = d.page.locator(`form[action="/orders/${order}/assignees"]`);
      await d.scrollTo(f);
      await d.point(f, 1200);
      const sel = f.locator('select[name="user_id"]');
      const opts = await sel.locator('option').allInnerTexts();
      const pick = opts.find((t) => /Order Coordinator/.test(t)) || opts[1];
      await d.select(sel, { label: pick });
      await d.clickNav(f.getByRole('button', { name: 'Add' }));
      await d.point('.alert', 1500);
    } },

    { card: 'list', kicker: 'Recap', title: 'Order Desk', items: [
      'You see every order and its stage, never the money.',
      'Set an owner for every department on every order.',
      'My tasks and the cockpit show what is waiting, late or escalated.',
      'Operations Manager: also adds and removes the order\'s assignees.',
    ], next: 'B09 · Managing Director and Executive Director', say: 'Let us recap. You see every order and its stage, but never the money. Make sure every department has an owner on every order. Use My tasks, the cockpit and the operations queues to see what is waiting, late or escalated, and chase it. And the Operations Manager also decides who is assigned to each order. Next: the Managing Director and the Executive Director.' },
  ]; },
};
