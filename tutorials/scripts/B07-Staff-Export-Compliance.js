'use strict';
// B07 — Export Compliance Executive, Manager and Director (Group B, Staff). Owner request 18 Nov 2026.
// One CIF order (scripts/_a02.js). On camera the Export Compliance Executive works the order-time checklist
// (ECGC cover, buyer due diligence), records the pre-shipment inspection and the certificates (shipment
// checklist), generates the Certificate of Origin prep sheet, and files the RoDTEP claim (Step 13g);
// then the compliance task types and the benefit pages. Other steps are done off camera between chapters.
// Written 18 Nov 2026 from orders/show.php (compliance-checklist[-shipment], step-8a-inspection,
// step-11a-coo-prep, step-13g-benefits) and OrderComplianceTaskRepository / OrderExportBenefitSchemeRepository.

const A = require('./_a02');

let demo;
let order = 0;
const at = (hash = '') => `/orders/${order}${hash}`;
const sec = (d, id) => d.page.locator(id);
const today = () => new Date().toISOString().slice(0, 10);

// Set one checklist row's status (by task name) in a checklist section.
async function setTask(d, sectionId, taskName, statusLabel) {
  const row = sec(d, sectionId).locator('tr').filter({ hasText: taskName }).first();
  await d.point(row, 700);
  await d.select(row.locator('select[data-compliance-status]'), { label: statusLabel });
}

const MOVES = {
  shipping: (o) => `OrderRepository::setProductionStatus(${o}, 'Production complete');
    OrderRepository::setEstShipmentDate(${o}, date('d M Y', strtotime('+5 days')), date('Y-m-d', strtotime('+5 days'))); echo 'ok';`,
  // Finance answered Step 12b: RODTEP eligible, the rest not; the BL is issued and the shipping bill recorded.
  shipped: (o) => `
    $fm = $uid('finance.manager@email.com');
    foreach (['RODTEP' => 'eligible', 'Duty Drawback' => 'not_eligible', 'RoSCTL' => 'not_eligible', 'Other' => 'not_eligible'] as $n => $st)
      App\\Repositories\\OrderExportBenefitSchemeRepository::setStatus(${o}, $n, $st, $st === 'eligible' ? null : 'Not eligible for this order.', $fm);
    Ship::recordBl(${o}, 'TESTBL2611007', date('Y-m-d'));
    Gate::passAndUnlockNext(${o}, 7, $uid('logistics@email.com'));
    $docApproved(${o}, 'CI', $uid('export.executive@email.com'), $uid('sales.manager@email.com'));
    Ship::recordScannedBlSent(${o});
    App\\Services\\BalanceDueService::startFromScannedBl(${o}, date('Y-m-d'));
    $p = Pay::find(${o});
    Pay::recordBalanceReceived(${o}, (float) $p['balance_amount'], date('Y-m-d'));
    Pay::setLegBankReference(${o}, 'balance', 'UTR SAMPLE0000789');
    Pay::markBalanceCleared(${o}, date('Y-m-d'), $fm);
    Gate::passAndUnlockNext(${o}, 8, $fm); echo 'ok';`,
};
MOVES.inspected = (o) => `
    $order = OrderRepository::find(${o}); $log = $uid('logistics@email.com');
    $file = $call('attachSamplePlaceholderFile', null, ${o}, App\\Helpers\\OrderStorage::folder($order, 'inspection'), 'Pre-shipment inspection report - SAMPLE.pdf', 'Inspection agency', 'Inspection report', $log);
    App\\Services\\ShipmentGateService::saveInspection($order, 'passed', date('Y-m-d'), 'TEST Inspection Agency', $file, 'Colour, sizes and polish as the approved sample; 520 sqm counted.', $log); echo 'ok';`;
// The COO prep sheet approved, originals couriered, the order closed (documents sent): claims can be filed.
MOVES.closed = (o) => `
    $docs = $pdo->query("SELECT d.id FROM documents d JOIN document_types t ON t.id = d.document_type_id WHERE d.order_id = ${o} AND t.code = 'COOPREP' ORDER BY d.id DESC LIMIT 1")->fetchColumn();
    if ($docs) { App\\Services\\ReviewWorkflowService::assignReviewers((int) $docs, [$uid('md@email.com')], $uid('compliance@email.com'));
      $rv = (int) $pdo->query('SELECT id FROM document_reviews WHERE document_id = ' . (int) $docs . " AND status = 'pending'")->fetchColumn();
      if ($rv) App\\Services\\ReviewWorkflowService::approve($rv, $uid('md@email.com'), null); }
    Ship::recordBlOriginalsReceived(${o}, 3); Ship::recordBlEndorsed(${o}, $uid('logistics@email.com'));
    Ship::recordCourierSent(${o}, 'TCS2611778899');
    Gate::passAndUnlockNext(${o}, 9, $uid('logistics@email.com'));
    OrderRepository::markShippedDocsSent(${o}); echo 'ok';`;
const move = (name) => A.php(demo, MOVES[name](order));

module.exports = {
  code: 'B07',
  file: 'B07-Staff-Export-Compliance.mp4',
  group: 'Group B · Staff',
  title: 'Export Compliance',
  subtitle: 'The compliance checklist, inspection and certificates, the COO prep sheet and export-benefit claims.',
  minutes: '4',
  restoreDb: true,

  async setup(dm) {
    demo = dm;
    const ctx = await A.setup(demo);
    order = ctx.orderId;
    demo.sql(`INSERT INTO user_department_memberships (user_id, designation_id, starts_on, is_default)
      SELECT u.id, dd.id, CURDATE(), 1 FROM users u JOIN department_designations dd JOIN departments d ON d.id = dd.department_id
      WHERE u.email = 'compliance@email.com' AND d.code = 'EXPORT_COMPLIANCE' AND dd.level = 'executive'`);
    A.move(demo, 'part1Done', order);
    ctx.actors.compliance.cookies = await demo.staffCookies('compliance');
    return ctx;
  },

  steps: ({ orderId = 0 } = {}) => { order = orderId || order; return [
    { card: 'title', say: 'This video is for Export Compliance: the executive, the manager and the director. You make sure every export follows the rules: the buyer is checked and insured, the goods are inspected and certified, the certificate of origin is right, and the government export benefits are claimed on time.' },
    { card: 'list', kicker: 'Your roles', title: 'Export Compliance · executive, manager, director', items: [
      'Executive: the compliance checklist, inspection, certificates, COO prep sheet, benefit claims.',
      'Manager: the same on every order, approves documents, keeps the checklist\'s task types.',
      'Director: also the cockpit.',
      'The checklist runs beside the order steps; the BL waits for a passed inspection.',
    ], say: 'Your roles. The executive keeps the compliance checklist of each order, records the inspection and the certificates, prepares the certificate of origin, and files the export benefit claims. The manager does this on every order, approves documents, and keeps the list of checklist tasks. The director also sees the cockpit. The checklist runs beside the normal order steps, and the bill of lading waits until the inspection has passed.' },

    // ---------- 1. Before production ----------
    { card: 'chapter', title: 'When the order is confirmed', subtitle: 'The order-time checklist.', say: 'Chapter one. Before we buy anything.' },
    { as: 'compliance', goto: at('#compliance-checklist'), caption: 'Compliance Checklist · order', say: 'We are the Export Compliance Executive. The buyer has confirmed this order. On the order page, the compliance checklist has two parts. The first is due now: E C G C cover, the government export credit insurance against the buyer not paying, and due diligence on the buyer.', do: async (d) => { await A.openTo(d, '#compliance-checklist'); await d.point('#compliance-checklist > summary', 1500); await d.point(sec(d, '#compliance-checklist').locator('table').first(), 1500); } },
    { caption: 'Mark them done', say: 'The E C G C cover is in place, and the due diligence report is clean. Mark both Approved. If a task does not apply to an order, choose Not Applicable: the reason fills in by itself. A plain skip needs a reason typed. Save Checklist.', do: async (d) => {
      await setTask(d, '#compliance-checklist', 'ECGC Cover', 'Approved');
      await setTask(d, '#compliance-checklist', 'Due Diligence', 'Approved');
      await d.clickNav(sec(d, '#compliance-checklist').getByRole('button', { name: 'Save Checklist' }));
      await d.point('.alert', 1500);
    } },

    // ---------- 2. Before shipment ----------
    { card: 'chapter', title: 'Before shipment', subtitle: 'Inspection and certificates.', say: 'Chapter two. Before the goods leave.', do: async () => { A.move(demo, 'supplierPo', order); move('shipping'); A.move(demo, 'freight', order); move('inspected'); } },
    { goto: at('#step-8a-inspection'), caption: 'Pre-shipment inspection', say: 'Production is nearly finished. An inspection agency checked the goods at the factory: colour, size, thickness, finish, and the count. Logistics recorded the result with the agency\'s report, here in Step 8 a: passed. The bill of lading waits until the inspection has passed, so check it, and chase it when it is missing.', do: async (d) => { await A.openTo(d, '#step-8a-inspection'); await d.point('#step-8a-inspection', 2500); } },
    { goto: at('#compliance-checklist-shipment'), caption: 'Compliance Checklist · shipment', say: 'The second part of the checklist is due before shipment: the pre-shipment inspection, the fumigation certificate for the wooden crates, and the phytosanitary certificate. When you have the certificates, mark them Approved and save.', do: async (d) => {
      await A.openTo(d, '#compliance-checklist-shipment');
      await setTask(d, '#compliance-checklist-shipment', 'Pre-Shipment Inspection', 'Approved');
      await setTask(d, '#compliance-checklist-shipment', 'Fumigation Certificate', 'Approved');
      await setTask(d, '#compliance-checklist-shipment', 'Phytosanitary Certificate', 'Approved');
      await d.clickNav(sec(d, '#compliance-checklist-shipment').getByRole('button', { name: 'Save Checklist' }));
      await d.point('.alert', 1500);
    } },

    // ---------- 3. Certificate of Origin ----------
    { card: 'chapter', title: 'Certificate of Origin', subtitle: 'Step 11a · the COO prep sheet.', say: 'Chapter three. Where the goods come from.', do: async () => { A.move(demo, 'packing', order); move('shipped'); } },
    { goto: at('#step-11a-coo-prep'), caption: 'Step 11a · Generate COO Prep Sheet', say: 'The goods are shipped. The buyer\'s customs need a certificate of origin, issued by the export promotion council. Our COO prep sheet collects everything they ask for from the order: exporter, consignee, transport, the goods with their H S codes, and the invoice. Generate it; the Managing Director approves it, and you apply for the certificate with it.', do: async (d) => { await A.openTo(d, '#step-11a-coo-prep'); await d.clickNav(sec(d, '#step-11a-coo-prep').getByRole('button', { name: /Generate COO Prep Sheet/ })); await d.point('.alert', 1500); } },

    // ---------- 4. Export benefits ----------
    { card: 'chapter', title: 'Export benefits', subtitle: 'Step 13g · file the claim.', say: 'Chapter four. Money back from the government.', do: async () => { move('closed'); } },
    { goto: at('#step-13g-benefits'), caption: 'Step 13g · Government Benefits', say: 'The balance is paid, and the original documents are with the buyer, so the order is closed. Finance answered which schemes it is eligible for: R o D T E P, a refund of taxes on exported goods. Step 13 g shows it, waiting to be claimed. file the claim on the government portal, and record it here: the scheme, the reference number, the amount claimed in rupees, and the date. File Claim.', do: async (d) => {
      await A.openTo(d, '#step-13g-benefits');
      const f = sec(d, '#step-13g-benefits').locator('form[action$="/export-benefits"]').first();
      await d.scrollTo(f);
      const sch = f.locator('select[name="scheme_name"]');
      if (await sch.count()) await d.select(sch, { label: (await sch.locator('option').allInnerTexts()).find((t) => /RODTEP/i.test(t)) });
      await d.type(f.locator('input[name="reference_number"]'), 'SB 7012345 / RODTEP scroll 2611');
      await d.type(f.locator('input[name="claimed_amount"]'), '14250');
      await f.locator('input[name="claimed_at"]').fill(today());
      await d.clickNav(f.getByRole('button', { name: 'File Claim' }));
    } },
    { caption: 'Waiting for the money', say: 'The claim now shows as waiting for money. When the government credits it, record the amount received here; Finance sends it to Zoho Books. Claims not filed in time are lost, so the app keeps reminding you.', do: async (d) => { await A.openTo(d, '#step-13g-benefits'); await d.point('#step-13g-benefits > summary', 2000); } },

    { card: 'list', kicker: 'For managers', title: 'Export Compliance Manager and Director', items: [
      'Approve compliance documents such as the COO prep sheet.',
      'Keep the checklist\'s task types: Settings › Lists › Compliance task types.',
      'See every order, set the Export Compliance owner on each one.',
      'Director: the management cockpit too.',
    ], say: 'And for managers. The Export Compliance Manager approves compliance documents, keeps the list of checklist tasks under Settings, Lists, Compliance task types, sees every order and sets the Export Compliance owner on each one. The director also sees the management cockpit.' },

    { card: 'list', kicker: 'Recap', title: 'Export Compliance', items: [
      'Order-time checklist: ECGC cover and buyer due diligence.',
      'Before shipment: inspection result with its report; fumigation and phytosanitary.',
      'The COO prep sheet for the certificate of origin.',
      'Step 13g: file each eligible benefit claim, then record the money received.',
    ], next: 'B08 · Order desk', say: 'Let us recap. When an order is confirmed, complete the order-time checklist: E C G C cover and the buyer\'s due diligence. Before shipment, record the inspection result with its report, and the fumigation and phytosanitary certificates. Generate the certificate of origin prep sheet. And in Step 13 g, file each eligible export benefit claim, then record the money when it arrives. Next: the order desk.' },
  ]; },
};
