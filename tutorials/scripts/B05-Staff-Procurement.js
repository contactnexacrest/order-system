'use strict';
// B05 — Procurement Executive, Manager and Director (Group B, Staff). Owner request 18 Nov 2026.
// One CIF order (scripts/_a02.js) confirmed by the buyer; Sales raised a purchase requirement. On camera the
// Procurement Executive records two supplier quotes, compares them and awards the lowest; the award is above
// the executive's approval limit, so the Procurement Manager approves it within their limit; the Supplier PO
// (Step 7) is shown once placed; the goods are received with the quality check; then Suppliers, offers and
// Supplier performance. Written 18 Nov 2026 from views/procurement/show.php, queue.php, suppliers/*.

const A = require('./_a02');

let demo;
let order = 0;
let pr = 0;
const at = (hash = '') => `/orders/${order}${hash}`;
const ROLES = {
  procmgr: { label: 'Procurement Manager', sub: 'Procurement · procurement.manager@email.com', initials: 'PM', color: '#166534' },
};

module.exports = {
  code: 'B05',
  file: 'B05-Staff-Procurement.mp4',
  group: 'Group B · Staff',
  title: 'Procurement',
  subtitle: 'Executive, Manager and Director: quotes, award, approval limits, the Supplier PO and receipt.',
  minutes: '5',
  restoreDb: true,

  async setup(dm) {
    demo = dm;
    demo.demoStaff('procmgr');
    const ctx = await A.setup(demo);
    order = ctx.orderId;
    for (const [email, level] of [['procurement@email.com', 'executive'], ['procurement.manager@email.com', 'manager']]) {
      demo.sql(`INSERT INTO user_department_memberships (user_id, designation_id, starts_on, is_default)
        SELECT u.id, dd.id, CURDATE(), 1 FROM users u JOIN department_designations dd JOIN departments d ON d.id = dd.department_id
        WHERE u.email = '${email}' AND d.code = 'PROC' AND dd.level = '${level}'`);
    }
    // The buyer has confirmed (Stage 5 open); two directory suppliers; Sales raised the purchase requirement.
    A.move(demo, 'part1Done', order);
    pr = Number(A.php(demo, `
      $exec = $uid('export.executive@email.com'); $md = $uid('md@email.com');
      foreach ([['TEST Deccan Granite Quarry', 'Hosur', 'Tamil Nadu'], ['TEST Southern Stone Works', 'Chennai', 'Tamil Nadu']] as [$n, $c, $s]) {
        App\\Repositories\\SupplierRepository::create(['supplier_legal_name' => $n, 'city' => $c, 'state' => $s, 'types' => ['Quarry Owner', 'Factory Owner']], $md);
      }
      echo App\\Repositories\\PurchasingRepository::createUnlessDuplicate(${order}, null, 'Black Galaxy Granite slabs, 2 cm, Natural Polish, Grade A',
        'Slabs 300 x 180 x 2 cm; colour as the approved sample', 180, 'SQM', date('Y-m-d', strtotime('+21 days')), $exec, 'Buyer wants the shipment within four weeks.');`).split('\n').pop());
    // Sessions started after the memberships exist (otherwise the app asks which department to work in).
    ctx.actors.procurement.cookies = await demo.staffCookies('procurement');
    ctx.actors.procmgr = { ...ROLES.procmgr, cookies: await demo.staffCookies('procmgr') };
    return ctx;
  },

  steps: ({ orderId = 0 } = {}) => { order = orderId || order; return [
    { card: 'title', say: 'This video is for Procurement: the executive, the manager and the director. You buy the stone our buyers ordered, from the right supplier, at the right price, on time. In this video we take one purchase from the request by Sales to the goods arriving and passing the quality check.' },
    { card: 'list', kicker: 'Your roles', title: 'Procurement · executive, manager, director', items: [
      'Executive: collects quotes, compares, awards, places the Supplier PO, records receipt and QC.',
      'Manager: the same on every order, and approves purchases up to ₹5,00,000.',
      'Director: approves purchases of any amount; sees the cockpit and agreements.',
      'You see purchase prices and the order number, not the buyer or our selling prices.',
    ], say: 'Your roles. The executive collects supplier quotes, compares them, awards the purchase, places the supplier\'s purchase order, and records the receipt and the quality check. The manager does the same on every order, and approves purchases up to five lakh rupees. The director approves purchases of any amount, and also sees the cockpit and the agreements. You see purchase prices and the order number, but not the buyer, and not our selling prices.' },

    // ---------- 1. Where requests come from ----------
    { card: 'chapter', title: 'A purchase requirement', subtitle: 'Sales asks, Procurement buys.', say: 'Chapter one. Sales asks for stone.' },
    { as: 'procurement', goto: '/my-tasks', caption: 'My tasks', say: 'We are the Procurement Executive. The buyer confirmed the order, so Sales raised a purchase requirement, and a task appeared in our My tasks, with a due date.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.locator('.kanban-board, table, .task-card').first(), 1800); } },
    { goto: '/procurement', caption: 'Procurement & suppliers › Procurement', say: 'All requirements are listed under Procurement, earliest need first. Filter by status: waiting for quotes, quotes in, awarded and waiting for approval, with the supplier, received. Our new one waits for quotes.', do: async (d) => { await d.point('h1', 900); await d.point(d.page.locator('tr').filter({ hasText: `PR-${pr}` }).first(), 1800); } },
    { goto: `/procurement/${pr}`, caption: 'The requirement', say: 'Open it. What Sales needs: one hundred and eighty square metres of Black Galaxy granite slabs, two centimetres, natural polish, grade A, needed within three weeks, and a note from Sales. It links to the order by its number only.', do: async (d) => { await d.point('h1', 1200); await d.point('.kv-grid', 2200); } },

    // ---------- 2. Quotes ----------
    { card: 'chapter', title: 'Collecting quotes', subtitle: 'At least two suppliers, compared fairly.', say: 'Chapter two. Asking suppliers for prices.' },
    { caption: 'Record a supplier quote', say: 'You ask your suppliers by phone or e-mail. Each answer is recorded here: the supplier from our directory, the price per square metre in rupees before G S T, the G S T rate, how many days they need, how long the price is valid, and their reference.', do: async (d) => {
      const f = d.page.locator(`form[action="/procurement/${pr}/quotes"]`);
      await d.scrollTo(f);
      await d.select(f.locator('select[name="supplier_id"]'), { label: 'TEST Deccan Granite Quarry' });
      await d.type(f.locator('input[name="unit_price_inr"]'), '2100');
      await d.type(f.locator('input[name="gst_percent"]'), '18');
      await d.type(f.locator('input[name="lead_time_days"]'), '14');
      await f.locator('input[name="valid_until"]').fill(new Date(Date.now() + 15 * 864e5).toISOString().slice(0, 10));
      await d.type(f.locator('input[name="quote_reference"]'), 'DGQ/Q/2611/41');
      await d.clickNav(f.getByRole('button', { name: /Record quote/ }));
    } },
    { caption: 'A second quote', say: 'And a second supplier: one thousand nine hundred and eighty rupees, twelve days.', do: async (d) => {
      const f = d.page.locator(`form[action="/procurement/${pr}/quotes"]`);
      await d.scrollTo(f);
      await d.select(f.locator('select[name="supplier_id"]'), { label: 'TEST Southern Stone Works' });
      await d.type(f.locator('input[name="unit_price_inr"]'), '1980');
      await d.type(f.locator('input[name="gst_percent"]'), '18');
      await d.type(f.locator('input[name="lead_time_days"]'), '12');
      await f.locator('input[name="valid_until"]').fill(new Date(Date.now() + 15 * 864e5).toISOString().slice(0, 10));
      await d.type(f.locator('input[name="quote_reference"]'), 'SSW/QT/0907');
      await d.clickNav(f.getByRole('button', { name: /Record quote/ }));
    } },
    { caption: 'Cheapest first, with GST', say: 'The quotes are compared side by side, cheapest first, with G S T added, the total for the whole quantity, and the lead time. The lowest is marked.', do: async (d) => { const t = d.page.getByRole('heading', { name: /Supplier quotes/ }); await d.scrollTo(t); await d.point(d.page.locator('table.list').first(), 2500); } },

    // ---------- 3. Award and approval ----------
    { card: 'chapter', title: 'Award and approval', subtitle: 'Approval limits keep purchases safe.', say: 'Chapter three. Choosing the supplier.' },
    { caption: 'Award the lowest', say: 'The cheaper supplier is also faster, so: Award. The total is above four lakh rupees. A Procurement Executive\'s approval limit is zero, so the award waits for an approver. The app says so.', do: async (d) => {
      const row = d.page.locator('table.list tr').filter({ hasText: 'TEST Southern Stone Works' }).first();
      await d.point(row, 1000);
      await d.clickNav(row.getByRole('button', { name: 'Award' }));
      await d.point('.alert', 2000);
    } },
    { as: 'procmgr', goto: '/approvals', caption: 'The Procurement Manager approves', say: 'Here is the Procurement Manager. The purchase waits on their Approvals page, under Other. They may approve up to five lakh rupees, and this purchase is below that.', do: async (d) => { await d.point('h1', 900); await d.point(d.page.locator('table.approvals-table tbody tr').first(), 1800); } },
    { goto: `/procurement/${pr}`, caption: 'Approve this purchase', say: 'They open it, check the quotes and the award, and press Approve this purchase. Above their limit, the app would refuse and send it on to the Procurement Director or the Managing Director.', do: async (d) => {
      const b = d.page.getByRole('button', { name: 'Approve this purchase' });
      await d.scrollTo(b); await d.point(d.page.getByRole('heading', { name: 'Approval' }), 1000); await d.clickNav(b); await d.point('.alert', 1800);
    } },

    // ---------- 4. The Supplier PO ----------
    { card: 'chapter', title: 'The Supplier PO', subtitle: 'Step 7 on the order.', say: 'Chapter four. Our purchase order to the supplier.', do: async () => { A.move(demo, 'supplierPo', order); } },
    { as: 'procurement', goto: at('#step-group-stage-5'), caption: 'Step 7 · Supplier PO', say: 'Back to the executive. On the order page, Step 7 is the supplier\'s purchase order. 7 a, the terms: the supplier\'s offers are listed lowest first, and choosing one fills in the supplier and the price; a different price needs a reason. Then 7 b generates the P O, it is approved like every document, the signed copy comes back in 7 c, and 7 d confirms it. Stage five is now done.', do: async (d) => { await A.openTo(d, '#step-7a-supplier-po-terms'); await d.point('#step-group-stage-5', 2200); await d.point('.process-step[data-stage="5"]', 1500); } },

    // ---------- 5. Receipt and QC ----------
    { card: 'chapter', title: 'Receipt and quality check', subtitle: 'The goods arrive.', say: 'Chapter five. The stone arrives.' },
    { goto: `/procurement/${pr}`, caption: 'Record receipt', say: 'When the supplier delivers, open the requirement again. Record the quantity received, the date, and the quality check: passed, passed with remarks, or failed. Add what you saw. Record receipt.', do: async (d) => {
      const f = d.page.locator(`form[action="/procurement/${pr}/receive"]`);
      await d.scrollTo(f);
      await d.type(f.locator('input[name="received_quantity"]'), '180');
      await f.locator('input[name="received_on"]').fill(new Date().toISOString().slice(0, 10));
      const opts = await f.locator('select[name="qc_result"] option').allInnerTexts();
      await d.select(f.locator('select[name="qc_result"]'), { label: opts.find((t) => /^pass/i.test(t.trim())) || opts[1] });
      await d.type(f.locator('textarea[name="qc_note"], input[name="qc_note"]').first(), 'Colour matches the approved sample; thickness and polish checked on 10 slabs.', { delay: 25 });
      await d.clickNav(f.getByRole('button', { name: 'Record receipt' }));
    } },
    { caption: 'Received', say: 'The requirement shows the quantity received and the quality result. Accounts then adds the supplier\'s tax invoice and pays it, matched to this purchase.', do: async (d) => { await d.point('.alert', 1200); await d.point('.kv-grid', 2000); } },

    // ---------- 6. Suppliers ----------
    { card: 'chapter', title: 'Suppliers and their offers', subtitle: 'The directory and performance.', say: 'Chapter six. Your suppliers.' },
    { goto: '/suppliers', caption: 'Suppliers', say: 'Suppliers is the directory: each supplier with its types, location and contact, and the products it offers with the price it quoted. Use Add product to record a supplier\'s offer for a product; that price feeds the catalogue and the price checks, never what buyers see. A supplier\'s bank details are verified before anyone pays them.', do: async (d) => { await d.point('h1', 900); await d.point(d.page.locator('tr').filter({ hasText: 'TEST Southern Stone Works' }).first(), 1500); await d.point(d.page.getByRole('link', { name: '+ Add product' }).first(), 1500); } },
    { goto: '/procurement/suppliers', caption: 'Supplier performance', say: 'And Supplier performance: for each supplier, how often they signed the purchase order on time, and how often they delivered on time. Use it when choosing between two similar quotes.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.locator('table').first(), 1800); } },

    { card: 'list', kicker: 'Recap', title: 'Procurement', items: [
      'Requirements from Sales arrive in My tasks and on the Procurement page.',
      'Record every quote; the app compares them cheapest first, with GST.',
      'Award; above your limit the purchase waits for a manager or director.',
      'Supplier PO in Step 7; receipt and quality check on the requirement.',
    ], next: 'B06 · Logistics', say: 'Let us recap. Purchase requirements from Sales arrive in your My tasks and on the Procurement page. Record every supplier\'s quote, and the app compares them, cheapest first, with G S T. Award the best one: above your approval limit, it waits for a manager or a director. Place the supplier\'s purchase order in Step 7 of the order, and when the goods arrive, record the receipt and the quality check. Next: Logistics.' },
  ]; },
};
