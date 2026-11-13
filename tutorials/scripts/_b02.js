'use strict';
// Shared set-up for B02 (the Sales/Export Manager and the Sales Director). Not a video itself.
// One buyer, "TEST Coral Bay Stone Trading W.L.L.", has a new order made by the Export Executive
// on the "Established Buyer — Post-BL" terms (they need approval) with one line picked from
// Products at a price below the seller's lowest price. The managers act on camera; the Export
// Executive's and the MD's own steps are done off camera with the same service calls the app uses.
// E-mail sending is switched on inside the snapshot (no SMTP in dev: nothing leaves the machine),
// so the two-level send can be shown. Runs inside the snapshot the runner restores afterwards.

const { cleanStage } = require('./_overview');
const A = require('./_a02');

const BUYER_EMAIL = 'purchasing@coralbay-stone.example';
const BUYER = { company: 'TEST Coral Bay Stone Trading W.L.L.', address: 'Building 12, Sample Street, Doha, Qatar', contact: 'Purchasing Manager', port: 'Hamad Port' };

const ROLES = {
  exec: A.ROLES.exec,
  salesmgr: { label: 'Sales/Export Manager', sub: 'Sales & Export · sales.manager@email.com', initials: 'SM', color: '#1E40AF' },
  salesdir: { label: 'Sales Director', sub: 'Sales & Export · sales.director@email.com', initials: 'SD', color: '#0B3A8C' },
  md: { label: 'Managing Director', sub: 'NexaCrest staff · md@email.com', initials: 'MD', color: '#C9962B' },
};

async function setup(demo) {
  cleanStage(demo);
  for (const k of ['exec', 'salesmgr', 'salesdir']) demo.demoStaff(k);
  demo.sql("UPDATE users SET is_active = 0 WHERE email NOT LIKE '%@email.com'");
  demo.sql("UPDATE company_settings SET setting_value = '1' WHERE setting_key = 'mail_approval_queue_enabled'");
  demo.sql("UPDATE company_settings SET setting_value = '0' WHERE setting_key = 'email_testing_mode'"); // D-280: buyer e-mails on (testing mode off)
  // Each works as Sales & Export, at their own level (their department membership), as on the live site.
  for (const [email, level] of [['export.executive@email.com', 'executive'], ['sales.manager@email.com', 'manager'], ['sales.director@email.com', 'director']]) {
    demo.sql(`INSERT INTO user_department_memberships (user_id, designation_id, starts_on, is_default)
      SELECT u.id, dd.id, CURDATE(), 1 FROM users u JOIN department_designations dd JOIN departments d ON d.id = dd.department_id
      WHERE u.email = ${demo.q(email)} AND d.code = 'SALES' AND dd.level = '${level}'
      AND NOT EXISTS (SELECT 1 FROM user_department_memberships m WHERE m.user_id = u.id AND m.designation_id = dd.id)`);
  }
  // One priced product in the catalogue (old dev-test products switched off inside the snapshot).
  const md = Number(demo.sql("SELECT id FROM users WHERE email = 'md@email.com'"));
  demo.sql('UPDATE catalog_products SET is_active = 0');
  const product = Number(demo.php(`
    use App\\Repositories\\ProductRepository; use App\\Repositories\\PricingRepository; use App\\Repositories\\ProductSupplierRepository;
    $p = ProductRepository::create(['name' => 'Absolute Black Granite Slab', 'specifications' => 'Granite slabs, polished', 'hs_code' => '68022390', 'reference_unit' => 'SQM'], ${md});
    PricingRepository::saveProductOverrides($p, ['pricing_margin_pct' => null, 'pricing_other_cost_mode' => 'pct', 'pricing_other_cost_value' => '0',
      'pricing_incentive_mode' => null, 'pricing_incentive_value' => null, 'pricing_buffer_pct' => null]);
    $s = ProductSupplierRepository::create(['product_id' => $p, 'supplier_name' => 'TEST Demo Quarry', 'location' => 'Demo location', 'contact_person' => null,
      'contact_phone' => null, 'contact_email' => null, 'fob_source' => 'direct', 'fob_value' => 3280.0, 'factory_cost' => null, 'transportation_cost' => null,
      'packing_cost' => null, 'loading_cost' => null, 'cha_cost' => null, 'is_primary' => 1, 'notes' => null], ${md});
    PricingRepository::setSupplierDates($s, date('Y-m-d'), null);
    // D-360 / D-361: the price is an offer of an active directory supplier, as "+ Add product" makes it (no figure changes).
    $sup = App\\Repositories\\SupplierRepository::create(['supplier_legal_name' => 'TEST Demo Quarry', 'city' => 'Demo city', 'state' => 'Demo state', 'types' => ['Quarry Owner']], ${md});
    ProductSupplierRepository::linkSupplier($s, $sup, 'TEST Demo Quarry');
    // D-361: a product is offered in Pick from Products once configured — fixed at the supplier's price, as before.
    App\\Services\\SupplierOffers::setConfiguredBase($p, 3280.0, null, 'SQM', ${md});
    echo $p;`).split('\n').pop());

  // The Export Executive's new order: approval-needing terms, one line below the lowest price.
  const order = Number(A.php(demo, `
    $exec = $uid('export.executive@email.com');
    $c = $call('createSampleClient', ${JSON.stringify(BUYER.company)}, ${JSON.stringify(BUYER.address)}, $exec, ${JSON.stringify(BUYER_EMAIL)});
    $pdo->prepare('UPDATE clients SET contact_person = ?, is_sample_data = 0 WHERE id = ?')->execute([${JSON.stringify(BUYER.contact)}, $c]);
    $incoterm = null; foreach (App\\Repositories\\LookupRepository::incoterms() as $t) if (strtoupper($t['code']) === 'FOB') $incoterm = $t;
    $currency = null; foreach (App\\Repositories\\LookupRepository::currencies() as $x) if ($x['code'] === 'USD') $currency = $x;
    $port = App\\Repositories\\LookupRepository::ports('loading')[0] ?? null;
    $preset = null; foreach (App\\Repositories\\LookupRepository::paymentPresets() as $p) if (str_starts_with($p['preset_name'], 'Established Buyer')) $preset = $p;
    $o = $call('createSampleOrderCustomLines', $c, $incoterm, $currency, $port, $preset, $exec, [
      ['description' => 'Absolute Black Granite Slab', 'dimensions' => '300 x 180 x 2 cm', 'finish' => 'Natural Polish', 'quantity' => '150', 'unit' => 'sqm', 'unit_price' => '50.00', 'hs_code' => '68022390'],
    ]);
    $pdo->prepare('UPDATE orders SET port_of_discharge_text = ?, is_sample_data = 0, created_by = ? WHERE id = ?')->execute([${JSON.stringify(BUYER.port)}, $exec, $o]);
    $pdo->prepare('DELETE FROM order_assignees WHERE order_id = ?')->execute([$o]);
    $pdo->prepare('INSERT INTO order_assignees (order_id, user_id, assigned_by) VALUES (?, ?, ?)')->execute([$o, $exec, $exec]);
    $line = (int) $pdo->query('SELECT id FROM order_products WHERE order_id = ' . (int) $o . ' ORDER BY id LIMIT 1')->fetchColumn();
    $pdo->prepare('UPDATE order_products SET catalog_product_id = ?, price_set_by = ? WHERE id = ?')->execute([${product}, $exec, $line]);
    $chk = App\\Services\\OrderLinePricing::checkLine(OrderProductRepository::find($line), App\\Services\\OrderLinePricing::context(OrderRepository::find($o)));
    $f = $chk['figures'];
    $price = floor(((float) $f['floor'] + (float) $f['minimum']) / 2 * 100) / 100;
    $pdo->prepare('UPDATE order_products SET unit_price = ?, fob_value = ROUND(quantity * ?, 2) WHERE id = ?')->execute([$price, $price, $line]);
    echo $o;`).split('\n').pop());

  // A sample order handed from the MD to the Export Executive on camera (add first, then remove).
  const handover = Number(demo.sql("SELECT o.id FROM orders o WHERE o.is_sample_data = 1 AND o.status = 'active' ORDER BY o.id LIMIT 1"));
  A.php(demo, `$md = $uid('md@email.com');
    $pdo->prepare('DELETE FROM order_assignees WHERE order_id = ?')->execute([${handover}]);
    $pdo->prepare('INSERT INTO order_assignees (order_id, user_id, assigned_by) VALUES (?, ?, ?)')->execute([${handover}, $md, $md]);
    echo 'ok';`);

  const actors = {};
  for (const k of ['salesmgr', 'salesdir']) actors[k] = { ...ROLES[k], cookies: await demo.staffCookies(k) };
  actors.salesmgrLogin = { ...ROLES.salesmgr, cookies: [] };
  return { actors, order, handover, product };
}

/** Off camera: the MD approves the line price (the only one who may). */
function mdApprovesLine(demo, orderId) {
  return A.php(demo, `$md = $uid('md@email.com');
    $line = (int) $pdo->query('SELECT id FROM order_products WHERE order_id = ${Number(orderId)} ORDER BY id LIMIT 1')->fetchColumn();
    $e = App\\Services\\OrderLinePricing::approve(${Number(orderId)}, $line, $md, 'Repeat buyer, first order on these terms: agreed to match their target price.');
    echo $e ?? 'ok';`);
}

/** Off camera: the Export Executive generates the Quotation (no reviewer yet). */
function execGeneratesQt(demo, orderId) {
  return A.php(demo, `$d = Doc::generate(${Number(orderId)}, 'QT', $uid('export.executive@email.com'));
    echo (int) ($d['id'] ?? $d['document_id'] ?? 0);`);
}

/** Off camera: the Export Executive asks to send the approved Quotation to the buyer (Level 1). */
function execRequestsSend(demo, orderId) {
  return A.php(demo, `$d = (int) $pdo->query("SELECT d.id FROM documents d JOIN document_types t ON t.id = d.document_type_id WHERE d.order_id = ${Number(orderId)} AND t.code = 'QT' ORDER BY d.id DESC LIMIT 1")->fetchColumn();
    $r = App\\Services\\EmailDispatchService::requestSend(${Number(orderId)}, $d, 'send_qt', null, $uid('export.executive@email.com'));
    echo json_encode($r);`);
}

module.exports = { BUYER, BUYER_EMAIL, ROLES, setup, mdApprovesLine, execGeneratesQt, execRequestsSend };
