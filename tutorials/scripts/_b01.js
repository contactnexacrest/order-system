'use strict';
// Shared set-up for B01 (the Export Executive). Not a video itself (starts with "_").
// One new buyer, "TEST Gulf Stone Interiors LLC", has sent the public Quotation Details Form
// (filled in off camera); the Export Executive accepts it, creates the order and takes it from
// quotation to Order Confirmation on camera. The reviewers' approvals and the Finance Manager's
// clearing are done off camera, during the cards, with the same service calls the app uses
// (scripts/_a02.js). The sample orders give the Orders list and My tasks some company.
// Runs inside the snapshot the runner restores afterwards.

const { cleanStage } = require('./_overview');
const A = require('./_a02');

const BUYER_EMAIL = 'purchasing@gulfstone-interiors.example';
const BUYER = {
  company: 'TEST Gulf Stone Interiors LLC',
  line1: 'Warehouse 7, Sample Industrial Area',
  city: 'Dubai',
  postcode: '00000',
  country: 'United Arab Emirates',
  contact: 'Purchasing Manager',
  phone: '+971-0000000000',
  vat: 'TRN000000000000',
  port: 'Jebel Ali',
};

/** Fills the public Quotation Details Form off camera (as the buyer would). */
async function submitIntake(demo) {
  const cfg = require('../lib/config');
  const { chromium } = require('playwright');
  const b = await chromium.launch({ executablePath: cfg.CHROMIUM });
  try {
    const p = await (await b.newContext({ baseURL: cfg.BASE_URL })).newPage();
    await p.goto('/quotation-details');
    await p.fill('input[name="company_legal_name"]', BUYER.company);
    await p.fill('input[name="billing_address_line1"]', BUYER.line1);
    await p.fill('input[name="billing_city"]', BUYER.city);
    await p.fill('input[name="billing_postcode"]', BUYER.postcode);
    await p.selectOption('#billing_country_select', BUYER.country);
    await p.fill('input[name="contact_person"]', BUYER.contact);
    await p.fill('input[name="email"]', BUYER_EMAIL);
    await p.fill('input[name="phone"]', BUYER.phone);
    await p.fill('input[name="vat_eori_tax_no"]', BUYER.vat);
    await p.selectOption('#country_of_destination_select', BUYER.country);
    await p.fill('input[name="port_of_discharge_text"]', BUYER.port);
    await p.selectOption('#incoterm_select', 'FOB');
    await p.fill('textarea[name="notes"]', 'Black Galaxy granite slabs, about 200 sqm, polished. Shipment within 6 weeks.');
    await Promise.all([p.waitForNavigation(), p.getByRole('button', { name: 'Submit Request' }).click()]);
  } finally { await b.close(); }
}

/** Clean stage, the staff, the buyer's intake waiting for review; the exec on some sample orders. */
async function setup(demo) {
  cleanStage(demo);
  for (const k of ['exec', 'salesmgr', 'accounts', 'fm', 'procurement']) demo.demoStaff(k);
  demo.sql("UPDATE users SET is_active = 0 WHERE email NOT LIKE '%@email.com'");
  demo.sql('SET FOREIGN_KEY_CHECKS = 0; DELETE FROM pi_intake_submissions; DELETE FROM client_intake_submissions; SET FOREIGN_KEY_CHECKS = 1');
  // The Export Executive works on the sample orders too (orders are seen by assignment).
  A.php(demo, `$exec = $uid('export.executive@email.com');
    foreach ($pdo->query('SELECT id FROM orders WHERE is_sample_data = 1')->fetchAll(PDO::FETCH_COLUMN) as $o)
      $pdo->prepare('INSERT IGNORE INTO order_assignees (order_id, user_id, assigned_by) VALUES (?, ?, ?)')->execute([$o, $exec, $exec]);
    echo 'ok';`);
  // The Export Executive works as Sales & Export (their department membership), as on the live site.
  demo.sql(`INSERT INTO user_department_memberships (user_id, designation_id, starts_on, is_default)
    SELECT u.id, dd.id, CURDATE(), 1 FROM users u JOIN department_designations dd JOIN departments d ON d.id = dd.department_id
    WHERE u.email = 'export.executive@email.com' AND d.code = 'SALES' AND dd.level = 'executive'`);
  // One priced product in the catalogue (old dev-test products switched off inside the snapshot).
  const md = Number(demo.sql("SELECT id FROM users WHERE email = 'md@email.com'"));
  demo.sql('UPDATE catalog_products SET is_active = 0');
  demo.php(`
    use App\\Repositories\\ProductRepository; use App\\Repositories\\PricingRepository; use App\\Repositories\\SpecRepository; use App\\Repositories\\ProductSupplierRepository;
    $p = ProductRepository::create(['name' => 'Black Galaxy Granite Slab', 'specifications' => 'Granite slabs, polished', 'hs_code' => '68022390', 'reference_unit' => 'SQM'], ${md});
    PricingRepository::saveProductOverrides($p, ['pricing_margin_pct' => null, 'pricing_other_cost_mode' => 'pct', 'pricing_other_cost_value' => '0',
      'pricing_incentive_mode' => null, 'pricing_incentive_value' => null, 'pricing_buffer_pct' => null]);
    $s = ProductSupplierRepository::create(['product_id' => $p, 'supplier_name' => 'TEST Demo Quarry', 'location' => 'Demo location', 'contact_person' => null,
      'contact_phone' => null, 'contact_email' => null, 'fob_source' => 'direct', 'fob_value' => 3280.0, 'factory_cost' => null, 'transportation_cost' => null,
      'packing_cost' => null, 'loading_cost' => null, 'cha_cost' => null, 'is_primary' => 1, 'notes' => null], ${md});
    PricingRepository::setSupplierDates($s, date('Y-m-d'), null);
    echo $p;`);
  await submitIntake(demo);
  const actors = { exec: { ...A.ROLES.exec, cookies: [] } };
  for (const k of ['salesmgr', 'fm']) actors[k] = { ...A.ROLES[k], cookies: await demo.staffCookies(k) };
  actors.execIn = { ...A.ROLES.exec, cookies: await demo.staffCookies('exec') };
  return { actors };
}

// A reviewer's approval, done off camera: the Sales Manager is assigned to the latest document of
// this type and approves it (the Export Executive cannot approve documents).
function approve(demo, orderId, code) {
  return A.php(demo, `$sm = $uid('sales.manager@email.com');
    $d = (int) $pdo->query("SELECT d.id FROM documents d JOIN document_types t ON t.id = d.document_type_id WHERE d.order_id = ${Number(orderId)} AND t.code = '${code}' ORDER BY d.id DESC LIMIT 1")->fetchColumn();
    if (!$pdo->query('SELECT COUNT(*) FROM document_reviews WHERE document_id = ' . $d)->fetchColumn()) App\\Services\\ReviewWorkflowService::assignReviewers($d, [$sm], $sm);
    $r = (int) $pdo->query('SELECT id FROM document_reviews WHERE document_id = ' . $d . " AND status = 'pending'")->fetchColumn();
    if ($r) App\\Services\\ReviewWorkflowService::approve($r, $sm, null);
    echo $d;`);
}

/**
 * Part 2's starting point: the buyer is a client and the order is where Part 1 left it
 * (quotation approved, Buyer PO approved, signed copy and PO number recorded: Stage 3 open).
 */
function orderAfterPart1(demo) {
  const out = A.php(demo, `
    $exec = $uid('export.executive@email.com');
    $c = $call('createSampleClient', ${JSON.stringify(BUYER.company)}, ${JSON.stringify(`${BUYER.line1}, ${BUYER.city}, ${BUYER.country}`)}, $exec, ${JSON.stringify(BUYER_EMAIL)});
    $pdo->prepare('UPDATE clients SET contact_person = ?, is_sample_data = 0 WHERE id = ?')->execute([${JSON.stringify(BUYER.contact)}, $c]);
    $incoterm = null; foreach (App\\Repositories\\LookupRepository::incoterms() as $t) if (strtoupper($t['code']) === 'FOB') $incoterm = $t;
    $currency = null; foreach (App\\Repositories\\LookupRepository::currencies() as $x) if ($x['code'] === 'USD') $currency = $x;
    $port = App\\Repositories\\LookupRepository::ports('loading')[0] ?? null;
    $preset = null; foreach (App\\Repositories\\LookupRepository::paymentPresets() as $p) if ($p['preset_name'] === 'Standard — New Buyer') $preset = $p;
    $o = $call('createSampleOrderCustomLines', $c, $incoterm, $currency, $port, $preset, $exec, [
      ['description' => 'Black Galaxy Granite Slab', 'dimensions' => '300 x 180 x 2 cm', 'finish' => 'Polished', 'quantity' => '200', 'unit' => 'sqm', 'unit_price' => '53.05', 'hs_code' => '68022390'],
    ]);
    $pdo->prepare('UPDATE orders SET port_of_discharge_text = ?, is_sample_data = 0 WHERE id = ?')->execute([${JSON.stringify(BUYER.port)}, $o]);
    $pdo->prepare('INSERT IGNORE INTO order_assignees (order_id, user_id, assigned_by) VALUES (?, ?, ?)')->execute([$o, $exec, $exec]);
    $docApproved($o, 'QT', $exec, $uid('sales.manager@email.com'));
    $docApproved($o, 'BUYERPO', $exec, $uid('sales.manager@email.com'));
    App\\Repositories\\OrderRepository::setBuyersPoRef($o, 'GSI-PO-2611-14');
    $pdo->prepare('UPDATE orders SET buyer_po_received_on = CURDATE() WHERE id = ?')->execute([$o]);
    App\\Services\\StageGateService::passAndUnlockNext($o, 2, $exec);
    echo $o;`);
  return Number(out.split('\n').pop());
}

const orderOf = (demo) => Number(demo.sql(`SELECT o.id FROM orders o JOIN clients c ON c.id = o.client_id WHERE c.email = ${demo.q(BUYER_EMAIL)} ORDER BY o.id DESC LIMIT 1`));

module.exports = { BUYER, BUYER_EMAIL, setup, submitIntake, orderOf, approve, orderAfterPart1 };
