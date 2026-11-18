'use strict';
// B06 Part 1 — Logistics Executive, Manager and Director (Group B, Staff). Owner request 18 Nov 2026.
// One CIF order (scripts/_a02.js) whose Supplier PO is signed. On camera the Logistics Executive saves the
// freight terms (9a), generates the freight Debit Note (9b), saves packing and crates (10b), generates the
// Packing List (10c), saves the shipping details (10d) and generates the BL Instruction (10e). Reviews by
// other people, the buyer's freight payment and the supplier's balance are done off camera between scenes.
// Part 2 (B06-2) covers the draft BL, the BL, partner bills and the original documents.
// Written 18 Nov 2026 from orders/show.php (step-9a … step-10e) and logistics-partners views.

const A = require('./_a02');

let demo;
let order = 0;
const at = (hash = '') => `/orders/${order}${hash}`;
const sec = (d, id) => d.page.locator(id);

// A document of this type generated on camera is approved off camera by the reviewer (real review workflow).
const approveLatest = (code, reviewer) => A.php(demo, `
  $d = (int) $pdo->query("SELECT d.id FROM documents d JOIN document_types t ON t.id = d.document_type_id WHERE d.order_id = ${order} AND t.code = '${code}' ORDER BY d.id DESC LIMIT 1")->fetchColumn();
  $r = $uid('${reviewer}');
  if (!$pdo->query('SELECT COUNT(*) FROM document_reviews WHERE document_id = ' . $d)->fetchColumn()) App\\Services\\ReviewWorkflowService::assignReviewers($d, [$r], $uid('logistics@email.com'));
  $rv = (int) $pdo->query('SELECT id FROM document_reviews WHERE document_id = ' . $d . " AND status = 'pending'")->fetchColumn();
  if ($rv) App\\Services\\ReviewWorkflowService::approve($rv, $r, null);
  echo $d;`);

const ROLES = {
  logmgr: { label: 'Logistics Manager', sub: 'Logistics · logistics.manager@email.com', initials: 'LM', color: '#92400E' },
};

const MOVES = {
  // Production under way with an estimated shipment date (Step 8a).
  production: (o) => `OrderRepository::setProductionStatus(${o}, 'In production at the supplier factory');
    OrderRepository::setEstShipmentDate(${o}, date('d M Y', strtotime('+20 days')), date('Y-m-d', strtotime('+20 days'))); echo 'ok';`,
  // The buyer paid the freight; Accounts recorded and cleared it (Stage 6 passed).
  freightPaid: (o) => `
    Pay::recordFreightReceived(${o}, 1635.00, date('Y-m-d'));
    Pay::setLegBankReference(${o}, 'freight', 'UTR SAMPLE0000456');
    Pay::markFreightCleared(${o}, date('Y-m-d'), $uid('finance.manager@email.com'));
    Gate::passAndUnlockNext(${o}, 6, $uid('finance.manager@email.com')); echo 'ok';`,
  // Inspection passed (Export Compliance) and the supplier's balance paid (Accounts).
  readyToPack: (o) => `
    $comp = $uid('compliance@email.com'); $order = OrderRepository::find(${o});
    $file = $call('attachSamplePlaceholderFile', null, ${o}, App\\Helpers\\OrderStorage::folder($order, 'inspection'), 'Pre-shipment inspection report - SAMPLE.pdf', 'Inspection agency', 'Inspection report', $comp);
    App\\Services\\ShipmentGateService::saveInspection($order, 'passed', date('Y-m-d'), 'TEST Inspection Agency', $file, null, $comp);
    $po = App\\Repositories\\OrderSupplierPoRepository::findLatestForOrder(${o});
    App\\Repositories\\OrderSupplierPoRepository::markBalancePaid((int) $po['id'], date('Y-m-d'), $uid('accounts@email.com'), 1288560.00); echo 'ok';`,
};
const move = (name) => A.php(demo, MOVES[name](order));

module.exports = {
  code: 'B06-1',
  file: 'B06-1-Staff-Logistics-Part-1.mp4',
  group: 'Group B · Staff',
  title: 'Logistics · Part 1',
  subtitle: 'Freight terms and the freight bill, packing and crates, the packing list, shipping details, BL instruction.',
  minutes: '4',
  restoreDb: true,

  async setup(dm) {
    demo = dm;
    demo.demoStaff('logmgr');
    const ctx = await A.setup(demo);
    order = ctx.orderId;
    for (const [email, level] of [['logistics@email.com', 'executive'], ['logistics.manager@email.com', 'manager']]) {
      demo.sql(`INSERT INTO user_department_memberships (user_id, designation_id, starts_on, is_default)
        SELECT u.id, dd.id, CURDATE(), 1 FROM users u JOIN department_designations dd JOIN departments d ON d.id = dd.department_id
        WHERE u.email = '${email}' AND d.code = 'LOG' AND dd.level = '${level}'`);
    }
    ctx.actors.logmgr = { ...ROLES.logmgr, cookies: await demo.staffCookies('logmgr') };
    A.move(demo, 'part1Done', order);
    A.move(demo, 'supplierPo', order);
    move('production');
    ctx.actors.logistics.cookies = await demo.staffCookies('logistics');
    return ctx;
  },

  steps: ({ orderId = 0 } = {}) => { order = orderId || order; return [
    { card: 'title', say: 'This video is for Logistics: the executive, the manager and the director. You get the goods from the supplier\'s factory onto a ship, with every paper the buyer, the bank and customs need. Part one covers the freight, the packing and the instructions for the bill of lading. Part two covers the bill of lading itself and the original documents.' },
    { card: 'list', kicker: 'Your roles', title: 'Logistics · executive, manager, director', items: [
      'Executive: freight terms, the freight Debit Note, packing, shipping details, BL, originals.',
      'Manager: the same on every order, plus the logistics partners directory.',
      'Director: also the cockpit and agreements with logistics partners.',
      'You see freight and logistics costs; not our selling prices or margins.',
    ], say: 'Your roles. The executive saves the freight terms, raises the freight bill, records the packing, the shipping details and the bill of lading, and sends the originals. The manager does this on every order, and keeps the directory of logistics partners. The director also sees the cockpit and the agreements with logistics partners. You see freight and logistics costs, but not our selling prices or margins.' },

    // ---------- 1. Partners ----------
    { card: 'chapter', title: 'Your partners', subtitle: 'Forwarders, CHA and transport.', say: 'Chapter one. The people you work with.' },
    { as: 'logmgr', goto: '/logistics-partners', caption: 'Procurement & suppliers › Logistics partners', say: 'Here is the Logistics Manager. Logistics partners lists the companies we work with: the customs house agent, called C H A, the transport company, and the freight forwarder who books the ship. One company can be tagged for more than one service, so it is entered only once. Their bank details are checked before anyone pays them. Managers keep this list; executives choose from it.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.locator('table').first(), 1800); } },
    { as: 'logistics', goto: at(), caption: 'Our order', say: 'Now we are the Logistics Executive. Here is our sample order: CIF, so we arrange and pay the freight and the insurance, and the buyer pays us back. The supplier\'s purchase order is signed and production has started, so stage six, the freight, is next.', do: async (d) => { await d.point('h1', 1000); await d.point('.process-step[data-stage="6"]', 1500); } },

    // ---------- 2. Freight ----------
    { card: 'chapter', title: 'Freight', subtitle: 'Step 9a terms · Step 9b Debit Note.', say: 'Chapter two. Booking the freight.' },
    { goto: at('#step-9a-freight-terms'), caption: 'Step 9a · Save Freight Terms', say: 'You get a freight quote from the forwarder. Step 9 a: the confirmed freight rate for the container, the insurance amount, which a CIF order needs, and the forwarder, chosen from the logistics partners. Save Freight Terms.', do: async (d) => {
      await A.openTo(d, '#step-9a-freight-terms');
      const s = sec(d, '#step-9a-freight-terms');
      await d.type(s.locator('input[name="confirmed_freight_rate"]'), '1450.00');
      await d.type(s.locator('input[name="insurance_amount"]'), '185.00');
      const sel = s.locator('select[name="freight_forwarder_partner_id"]');
      const label = (await sel.locator('option').allInnerTexts()).find((t) => /Forwarder/i.test(t));
      await d.select(sel, { label });
      await d.clickNav(s.getByRole('button', { name: 'Save Freight Terms' }));
    } },
    { goto: at('#step-9b-freight-debit-note'), caption: 'Step 9b · the freight Debit Note', say: 'Step 9 b is the freight Debit Note, our bill to the buyer for the freight and insurance. It carries amounts in the buyer\'s currency that Logistics does not see, so the Export Executive generates it, not you. Your terms from 9 a go straight into it.', do: async (d) => { await A.openTo(d, '#step-9b-freight-debit-note'); await d.point('#step-9b-freight-debit-note > summary', 2200); } },
    { caption: 'Approved, then paid', say: 'The Sales Manager approved it, and it went to the buyer. When the buyer pays the freight, Accounts records and clears it, and stage seven opens: packing.', do: async (d) => { A.php(demo, `$docApproved(${order}, 'FDN', $uid('export.executive@email.com'), $uid('sales.manager@email.com')); echo 1;`); move('freightPaid'); move('readyToPack'); await d.goto(at()); await d.point('.process-step[data-stage="6"]', 1200); await d.point('.process-step[data-stage="7"]', 1500); } },

    // ---------- 3. Packing ----------
    { card: 'chapter', title: 'Packing and crates', subtitle: 'Step 10b · Step 10c Packing List.', say: 'Chapter three. Packing the stone.' },
    { goto: at('#step-10b-packing'), caption: 'Step 10b · Save Packing & Crates', say: 'The goods passed inspection, and the supplier has been paid. At the factory, the stone is packed in wooden crates. Step 10 b: the quantity actually packed, the number of crates, the net and gross weight, and the volume in cubic metres. If less was packed than ordered, beyond the tolerance, the app asks for the buyer\'s written approval.', do: async (d) => {
      await A.openTo(d, '#step-10b-packing');
      const s = sec(d, '#step-10b-packing');
      await d.point(s.locator('p.muted.small').first(), 1500);
      await d.type(s.locator('input[name="actual_quantity_packed"]'), '520');
      await d.type(s.locator('input[name="crate_count"]'), '2');
      await d.type(s.locator('input[name="total_net_weight_kg"]'), '18400');
      await d.type(s.locator('input[name="total_gross_weight_kg"]'), '19200');
      await d.type(s.locator('input[name="total_cbm"]'), '22.5');
    } },
    { caption: 'One line per crate', say: 'Then one line per crate: its number, the marks painted on it, what is inside, the size, the pieces, and the weights. These lines print on the packing list exactly as typed. Save Packing and Crates.', do: async (d) => {
      const s = sec(d, '#step-10b-packing');
      const rows = [['C-001', 'NEXACREST/HSI/1', 'Black Galaxy Granite Slab', '310 x 120 x 90', '120', '9200', '9600', '11.25'],
        ['C-002', 'NEXACREST/HSI/2', 'Black Galaxy Granite Tile', '310 x 120 x 90', '400', '9200', '9600', '11.25']];
      const names = ['crate_no[]', 'crate_marks_numbers[]', 'crate_product_description[]', 'crate_dimensions[]', 'crate_pcs[]', 'crate_net_weight_kg[]', 'crate_gross_weight_kg[]', 'crate_cbm[]'];
      for (let r = 0; r < rows.length; r++) {
        if (r > 0 && (await s.locator('input[name="crate_no[]"]').count()) <= r) { const add = s.getByRole('button', { name: /Add crate|\+ Add/ }).first(); if (await add.count()) await d.click(add); }
        for (let c = 0; c < names.length; c++) { const f = s.locator(`input[name="${names[c]}"]`).nth(r); if (await f.count()) await d.type(f, rows[r][c], { delay: r === 0 ? 40 : 15 }); }
      }
      await d.clickNav(s.getByRole('button', { name: /Save Packing/ }));
    } },
    { goto: at('#step-10c-packing-list'), caption: 'Step 10c · Generate Packing List', say: 'Step 10 c: Generate Packing List, built from those crates. It is reviewed and approved like every document.', do: async (d) => { await A.openTo(d, '#step-10c-packing-list'); await d.clickNav(sec(d, '#step-10c-packing-list').getByRole('button', { name: 'Generate Packing List' })); await d.point('.alert', 1500); approveLatest('PL', 'sales.manager@email.com'); } },

    // ---------- 4. Shipping ----------
    { card: 'chapter', title: 'Shipping details', subtitle: 'Step 10d · Step 10e BL Instruction.', say: 'Chapter four. The ship and the container.' },
    { goto: at('#step-10d-shipping-details'), caption: 'Step 10d · Save Shipping Details', say: 'The forwarder confirms the booking. Step 10 d: the shipping line, the vessel and the voyage number, the estimated departure and arrival dates, the container type and count, and, once the container is loaded, its number and the seal number. Save Shipping Details.', do: async (d) => {
      await A.openTo(d, '#step-10d-shipping-details');
      const s = sec(d, '#step-10d-shipping-details');
      await d.type(s.locator('input[name="shipping_line"]'), 'TEST Shipping Line');
      await d.type(s.locator('input[name="vessel_name"]'), 'MV Sample Voyager');
      await d.type(s.locator('input[name="voyage_number"]'), 'SV-2611-07');
      await s.locator('input[name="etd"]').fill(new Date(Date.now() + 2 * 864e5).toISOString().slice(0, 10));
      await s.locator('input[name="eta"]').fill(new Date(Date.now() + 26 * 864e5).toISOString().slice(0, 10));
      const ct = s.locator('select[name="ship_container_type"]');
      if (await ct.count()) { const o = (await ct.locator('option').allInnerTexts()).find((t) => /20/.test(t)); if (o) await d.select(ct, { label: o }); }
      const cc = s.locator('input[name="container_count"]'); if (await cc.count()) await d.type(cc, '1');
      await d.type(s.locator('input[name="container_no"]'), 'SAMU7654321');
      await d.type(s.locator('input[name="seal_no"]'), 'SEAL000777');
      await d.clickNav(s.getByRole('button', { name: 'Save Shipping Details' }));
    } },
    { goto: at('#step-10e-bl-instruction'), caption: 'Step 10e · Generate BL Instruction Sheet', say: 'Step 10 e: the bill of lading instruction sheet. It tells the shipping line exactly what to print on the bill of lading: shipper, consignee, notify party, ports, the goods and the marks. Generate it. The Managing Director approves it, and you send it to the forwarder. In part two: the draft bill of lading, the bill itself, and the original documents.', do: async (d) => { await A.openTo(d, '#step-10e-bl-instruction'); await d.clickNav(sec(d, '#step-10e-bl-instruction').getByRole('button', { name: /Generate BL Instruction/ })); await d.point('.alert', 1500); approveLatest('BLI', 'md@email.com'); } },

    { card: 'list', kicker: 'Recap', title: 'Logistics · Part 1', items: [
      'Keep the logistics partners directory complete; banks are checked before payment.',
      'Step 9: freight terms and the freight Debit Note to the buyer (CIF / CFR).',
      'Step 10b–c: packing totals and one line per crate; the Packing List.',
      'Step 10d–e: ship, voyage, container and seal; the BL Instruction Sheet.',
    ], next: 'B06 Part 2 · The bill of lading and the originals', say: 'Let us recap part one. Keep the directory of logistics partners complete. On CIF and CFR orders, save the freight terms and send the buyer the freight debit note. Record the packing, with one line per crate, and generate the packing list. Save the ship, the voyage, the container and the seal, and generate the bill of lading instruction sheet. In part two: the draft bill of lading, the bill itself, partner bills, and the original documents.' },
  ]; },
};
