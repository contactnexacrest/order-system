'use strict';
// Shared set-up for A02 (one order, end to end). Not a video itself (starts with "_").
// One CIF order for a sample buyer introduced by a demo associate, moved forward stage by stage
// between scenes with the same service calls the app's own Sample Data uses
// (SampleDataService::advanceSampleOrderToStage9), plus document reviews by real reviewers.
// The on-camera clicks (approvals, clearing payments, the buyer's acknowledgement, BL, closing)
// are done in the app itself. Runs inside the snapshot the runner restores afterwards.

const { ACTORS, cleanStage } = require('./_overview');

const ASSOC = 'associate.full@email.com';
const BUYER_EMAIL = 'purchasing@harbourline-stone.example';
const ROLES = {
  exec: { label: 'Export Executive', sub: 'Sales & Export · export.executive@email.com', initials: 'EX', color: '#2563EB' },
  salesmgr: { label: 'Sales Manager', sub: 'Sales & Export · sales.manager@email.com', initials: 'SM', color: '#1E40AF' },
  procurement: { label: 'Procurement Executive', sub: 'Procurement · procurement@email.com', initials: 'PR', color: '#15803D' },
  logistics: { label: 'Logistics Executive', sub: 'Logistics · logistics@email.com', initials: 'LG', color: '#B45309' },
  accounts: { label: 'Accounts Executive', sub: 'Accounts & Finance · accounts@email.com', initials: 'AC', color: '#7C3AED' },
  fm: { label: 'Finance Manager', sub: 'Accounts & Finance · finance.manager@email.com', initials: 'FM', color: '#5B21B6' },
  compliance: { label: 'Export Compliance Executive', sub: 'Export Compliance · compliance@email.com', initials: 'CO', color: '#0F766E' },
};

// PHP prelude: private SampleDataService helpers through reflection, and user ids by e-mail.
const PRELUDE = `
  use App\\Services\\DocumentGenerationService as Doc; use App\\Services\\StageGateService as Gate;
  use App\\Repositories\\OrderRepository; use App\\Repositories\\OrderPaymentStatusRepository as Pay;
  use App\\Repositories\\OrderProductRepository; use App\\Repositories\\OrderShippingRepository as Ship;
  $sd = new ReflectionClass(App\\Services\\SampleDataService::class);
  $call = function ($name, ...$args) use ($sd) { $m = $sd->getMethod($name); $m->setAccessible(true); return $m->invokeArgs(null, $args); };
  $pdo = App\\Config\\Database::connection();
  $uid = function ($email) use ($pdo) { $s = $pdo->prepare('SELECT id FROM users WHERE email = ?'); $s->execute([$email]); return (int) $s->fetchColumn(); };
  // Generate a document as one person and have another approve it (the real review workflow).
  $docApproved = function ($orderId, $code, $by, $reviewer) {
    $d = Doc::generate($orderId, $code, $by);
    $docId = (int) ($d['id'] ?? $d['document_id'] ?? 0);
    App\\Services\\ReviewWorkflowService::assignReviewers($docId, [$reviewer], $by);
    $r = App\\Config\\Database::connection()->query('SELECT id FROM document_reviews WHERE document_id = ' . $docId . " AND status = 'pending'")->fetchColumn();
    App\\Services\\ReviewWorkflowService::approve((int) $r, $reviewer, null);
    return $docId;
  };
  // Generate and put it in one reviewer's queue (approved on camera).
  $docForReview = function ($orderId, $code, $by, $reviewer) {
    $d = Doc::generate($orderId, $code, $by);
    $docId = (int) ($d['id'] ?? $d['document_id'] ?? 0);
    App\\Services\\ReviewWorkflowService::assignReviewers($docId, [$reviewer], $by);
    return $docId;
  };
`;

function php(demo, code) { return demo.php(PRELUDE + code); }

/** Clean stage + demo staff + associate + the buyer and its CIF order (Stage 1, terms awaiting MD). */
async function setup(demo) {
  cleanStage(demo);
  for (const k of ['exec', 'salesmgr', 'procurement', 'logistics', 'accounts', 'fm', 'compliance']) demo.demoStaff(k);
  // Reviewer drop-downs list every active login: keep only the role logins (test-run logins switched off, inside the snapshot).
  demo.sql("UPDATE users SET is_active = 0 WHERE email NOT LIKE '%@email.com'");
  const a = demo.associate({ email: ASSOC, name: 'Demo Associate', level: 'full', city: 'Jaipur' });
  const out = php(demo, `
    $exec = $uid('export.executive@email.com');
    $incoterm = null; foreach (App\\Repositories\\LookupRepository::incoterms() as $t) if (strtoupper($t['code']) === 'CIF') $incoterm = $t;
    $currency = null; foreach (App\\Repositories\\LookupRepository::currencies() as $c) if ($c['code'] === 'USD') $currency = $c;
    $port = App\\Repositories\\LookupRepository::ports('loading')[0] ?? null;
    $preset = null; foreach (App\\Repositories\\LookupRepository::paymentPresets() as $p) if ($p['preset_name'] === 'Established Buyer — Post-BL') $preset = $p;
    $client = $call('createSampleClient', '[SAMPLE] Harbourline Stone Imports', '18 Harbour Road, Sample District, Test Country', $exec, ${JSON.stringify(BUYER_EMAIL)});
    $pdo->prepare('UPDATE clients SET contact_person = ? WHERE id = ?')->execute(['Purchasing Manager', $client]);
    App\\Services\\AssociateService::linkClient($client, ${a.id}, 'link', null, date('Y-m-d'), $exec);
    $order = $call('createSampleOrderCustomLines', $client, $incoterm, $currency, $port, $preset, $exec, [
      ['description' => 'Black Galaxy Granite Slab', 'dimensions' => '300 x 180 x 2 cm', 'finish' => 'Natural Polish', 'quantity' => '120', 'unit' => 'sqm', 'unit_price' => '48.00', 'hs_code' => '68022390'],
      ['description' => 'Black Galaxy Granite Tile', 'dimensions' => '60 x 60 x 1.5 cm', 'finish' => 'Natural Polish', 'quantity' => '400', 'unit' => 'sqm', 'unit_price' => '32.00', 'hs_code' => '68022390'],
    ]);
    $pdo->prepare('UPDATE orders SET port_of_discharge_text = ? WHERE id = ?')->execute(['Rotterdam, Netherlands', $order]);
    // A real (not sample) order, so the associate's first-order commission applies; the buyer stays a [SAMPLE] client.
    $pdo->prepare('UPDATE orders SET is_sample_data = 0 WHERE id = ?')->execute([$order]);
    App\\Services\\AssociateService::onOrderCreated($order);
    foreach (['export.executive@email.com','sales.manager@email.com','procurement@email.com','logistics@email.com','accounts@email.com','finance.manager@email.com','compliance@email.com'] as $e) {
      $pdo->prepare('INSERT IGNORE INTO order_assignees (order_id, user_id, assigned_by) VALUES (?, ?, ?)')->execute([$order, $uid($e), $exec]);
    }
    echo $client . '|' . $order;`);
  const [clientId, orderId] = out.split('\n').pop().split('|').map(Number);
  const actors = {
    md: { ...ACTORS.md, cookies: await demo.staffCookies('md') },
    associate: { ...ACTORS.associate(a.email), cookies: await demo.associateCookies(a) },
    client: { ...ACTORS.client(BUYER_EMAIL), cookies: [] },
  };
  for (const k of Object.keys(ROLES)) actors[k] = { ...ROLES[k], cookies: await demo.staffCookies(k) };
  return { clientId, orderId, associate: a, actors };
}

// ---------- off-camera moves (run during cards) ----------
const MOVES = {
  // QT generated by the Export Executive, waiting in the MD's review queue.
  qtForReview: (o) => `$docForReview(${o}, 'QT', $uid('export.executive@email.com'), $uid('md@email.com'));`,
  // Stage 2: Buyer PO document approved, signed copy back, PO number and date recorded.
  buyerPo: (o) => `
    $docApproved(${o}, 'BUYERPO', $uid('export.executive@email.com'), $uid('sales.manager@email.com'));
    OrderRepository::setBuyersPoRef(${o}, 'HSI-PO-2611-07');
    $pdo->prepare('UPDATE orders SET buyer_po_received_on = CURDATE() WHERE id = ?')->execute([${o}]);
    Gate::passAndUnlockNext(${o}, 2, $uid('export.executive@email.com'));`,
  // PI generated by the Export Executive, in the Sales Manager's queue.
  piForReview: (o) => `OrderRepository::markPiFormSkipped(${o}, $uid('export.executive@email.com'), 'Buyer details already confirmed on the quotation');
    $docForReview(${o}, 'PI', $uid('export.executive@email.com'), $uid('sales.manager@email.com'));
    App\\Services\\AssociateCommission::fixLevelAtPi(${o});`,
  // Accounts records the advance from the bank statement.
  advanceRecorded: (o) => `
    $fob = OrderProductRepository::totalFobValue(${o}); $ord = OrderRepository::find(${o});
    $adv = round($fob * ((float) $ord['advance_pct']) / 100, 2);
    Pay::recordAdvanceReceived(${o}, $adv, date('Y-m-d'));
    Pay::setLegBankReference(${o}, 'advance', 'UTR SAMPLE0000123');
    Pay::setAdvanceInrActual(${o}, round($adv * 84.10, 2), $uid('accounts@email.com'));
    Pay::setLegExchangeRate(${o}, 'advance', 84.10, $uid('accounts@email.com'));`,
  // OC generated and approved, sent, link generated — waiting for the buyer.
  ocSent: (o) => `
    $doc = $docApproved(${o}, 'OC', $uid('export.executive@email.com'), $uid('sales.manager@email.com'));
    $pdo->prepare('UPDATE orders SET oc_link_generated_at = NOW() WHERE id = ?')->execute([${o}]);
    App\\Repositories\\OrderOcAcknowledgmentRepository::recordSent(${o}, $doc, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+48 hours')));`,
  // Stage 5 (Procurement): supplier PO saved, generated, approved, signed copy back; ECGC and due diligence done.
  supplierPo: (o) => `
    $proc = $uid('procurement@email.com'); $comp = $uid('compliance@email.com');
    $sup = $call('createSampleSupplier');
    $ref = App\\Services\\ReferenceNumberService::generateDocumentRef((int) Doc::documentTypeIdFor('SUPPO'));
    App\\Repositories\\OrderSupplierPoRepository::create(${o}, $sup, $ref, [
      'supplier_type' => 'Quarry Owner', 'material_stone_type' => 'Natural Granite, Black Galaxy', 'grade' => 'Grade A',
      'surface_finish' => 'Natural Polish', 'dimensions' => 'Slabs 300 x 180 x 2 cm; tiles 60 x 60 x 1.5 cm', 'dimensional_tolerance' => '+/- 1mm',
      'quantity' => '520', 'unit' => 'sqm', 'colour_reference' => 'Sample swatch on file', 'unit_price_inr' => '2100.00',
      'basic_value_inr' => '1092000.00', 'gst_rate_pct' => '18.00', 'gst_amount_inr' => '196560.00', 'total_payable_inr' => '1288560.00',
      'advance_pct' => '0.00', 'advance_amount_inr' => '0.00', 'balance_amount_inr' => '1288560.00',
      'delivery_location' => 'Supplier factory, Chennai', 'required_delivery_date' => date('Y-m-d', strtotime('+21 days')),
      'packing_requirement' => 'Export wooden crates, fumigated']);
    $docApproved(${o}, 'SUPPO', $proc, $uid('md@email.com'));
    $po = App\\Repositories\\OrderSupplierPoRepository::findLatestForOrder(${o});
    App\\Repositories\\OrderSupplierPoRepository::markSigned((int) $po['id']);
    Gate::passAndUnlockNext(${o}, 5, $proc);
    Gate::maybeAutoSkipFreightStage(${o}, $proc);
    foreach (App\\Repositories\\OrderComplianceTaskRepository::forOrder(${o}) as $t) {
      if (in_array($t['task_key'], ['ecgc_cover', 'due_diligence'], true)) App\\Repositories\\OrderComplianceTaskRepository::setStatus(${o}, (int) $t['task_type_id'], 'approved', null, $comp);
    }`,
  // Stage 6 (CIF): freight terms, Debit Note approved, freight received and cleared.
  freight: (o) => `
    $log = $uid('logistics@email.com');
    App\\Repositories\\OrderFreightRepository::upsert(${o}, ['confirmed_freight_rate' => '1450.00', 'insurance_amount' => '185.00',
      'freight_forwarder_name' => 'TEST Forwarder Logistics', 'freight_forwarder_contact' => 'ops@forwarder.example']);
    $docApproved(${o}, 'FDN', $log, $uid('sales.manager@email.com'));
    Pay::recordFreightReceived(${o}, 1635.00, date('Y-m-d'));
    Pay::setLegBankReference(${o}, 'freight', 'UTR SAMPLE0000456');
    Pay::markFreightCleared(${o}, date('Y-m-d'), $uid('finance.manager@email.com'));
    Gate::passAndUnlockNext(${o}, 6, $uid('finance.manager@email.com'));`,
  // Stage 7 up to the BL: inspection passed, certificates done, packing list and BL instruction approved.
  packing: (o) => `
    $log = $uid('logistics@email.com'); $comp = $uid('compliance@email.com'); $order = OrderRepository::find(${o});
    $file = $call('attachSamplePlaceholderFile', null, ${o}, App\\Helpers\\OrderStorage::folder($order, 'inspection'), 'Pre-shipment inspection report - SAMPLE.pdf', 'Inspection agency', 'Inspection report', $comp);
    App\\Services\\ShipmentGateService::saveInspection($order, 'passed', date('Y-m-d'), 'TEST Inspection Agency', $file, null, $comp);
    foreach (App\\Repositories\\OrderComplianceTaskRepository::forOrder(${o}) as $t) {
      if (in_array($t['task_key'], ['fumigation_certificate', 'phytosanitary_certificate'], true)) App\\Repositories\\OrderComplianceTaskRepository::setStatus(${o}, (int) $t['task_type_id'], 'approved', null, $comp);
    }
    $po = App\\Repositories\\OrderSupplierPoRepository::findLatestForOrder(${o});
    App\\Repositories\\OrderSupplierPoRepository::markBalancePaid((int) $po['id'], date('Y-m-d'), $uid('accounts@email.com'), 1288560.00);
    $sum = OrderProductRepository::orderedQuantitySummary(${o});
    App\\Repositories\\OrderPackingRepository::upsert(${o}, ['actual_quantity_packed' => (string) $sum['total'], 'crate_count' => '2',
      'total_net_weight_kg' => '18400.00', 'total_gross_weight_kg' => '19200.00', 'total_cbm' => '22.500', 'packing_date' => date('Y-m-d'), 'shortfall_pct' => '0.00']);
    $crates = [];
    foreach (OrderProductRepository::forOrder(${o}) as $i => $p) $crates[] = ['crate_no' => 'C-00' . ($i + 1), 'marks_numbers' => 'NEXACREST/HSI/' . ($i + 1),
      'product_description' => $p['description'], 'dimensions_lwh_cm' => '310 x 120 x 90', 'pcs' => $p['quantity'], 'net_weight_kg' => '9200.00',
      'gross_weight_kg' => '9600.00', 'cbm' => '11.250', 'hs_code' => $p['hs_code'] ?? '68022390'];
    App\\Repositories\\OrderCrateRepository::replaceForOrder(${o}, $crates);
    $docApproved(${o}, 'PL', $log, $uid('sales.manager@email.com'));
    Ship::upsert(${o}, ['shipping_line' => 'TEST Shipping Line', 'vessel_name' => 'MV Sample Voyager', 'voyage_number' => 'SV-2611-07',
      'etd' => date('Y-m-d', strtotime('+2 days')), 'eta' => date('Y-m-d', strtotime('+26 days')), 'container_type' => '1x20GP',
      'container_no' => 'SAMU7654321', 'seal_no' => 'SEAL000777']);
    $docApproved(${o}, 'BLI', $log, $uid('md@email.com'));`,
  // Stage 8 up to clearing: CI approved, scanned BL sent (starts the balance clock), balance received.
  ciBalance: (o) => `
    $docApproved(${o}, 'CI', $uid('export.executive@email.com'), $uid('sales.manager@email.com'));
    Ship::recordScannedBlSent(${o});
    App\\Services\\BalanceDueService::startFromScannedBl(${o}, date('Y-m-d'));
    $p = Pay::find(${o});
    Pay::recordBalanceReceived(${o}, (float) $p['balance_amount'], date('Y-m-d'));
    Pay::setLegBankReference(${o}, 'balance', 'UTR SAMPLE0000789');
    // D-264: Accounts records each receipt with the rupees the bank credited (from the bank advice),
    // so the associate's commission can be fixed at the realised rate when the balance is cleared.
    $rcpt = $pdo->prepare('INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, bank_reference, inr_credited, bank_rate, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$pdo->query('SELECT COUNT(*) FROM order_payment_receipts WHERE order_id = ' . (int) ${o} . " AND leg = 'advance'")->fetchColumn()) {
      $rcpt->execute([${o}, 'advance', (float) $p['advance_amount'], date('Y-m-d'), 84.10, 'UTR SAMPLE0000123', round((float) $p['advance_amount'] * 84.10, 2), 84.10, $uid('accounts@email.com')]);
    }
    $rcpt->execute([${o}, 'balance', (float) $p['balance_amount'], date('Y-m-d'), 84.40, 'UTR SAMPLE0000789', round((float) $p['balance_amount'] * 84.40, 2), 84.40, $uid('accounts@email.com')]);`,
  // Stage 9 up to closing: COO prep sheet approved, original BLs received from the CHA and endorsed.
  closeReady: (o) => `
    $docApproved(${o}, 'COOPREP', $uid('compliance@email.com'), $uid('md@email.com'));
    Ship::recordBlOriginalsReceived(${o}, 3);
    Ship::recordBlEndorsed(${o}, $uid('logistics@email.com'));`,
};

// Part 1's on-camera actions, done off camera for Part 2: terms approved, QT approved, PI approved,
// advance cleared (portal login created), OC acknowledged by the buyer. Leaves the order at Stage 5.
MOVES.part1Done = (o) => `
  $md = $uid('md@email.com'); $exec = $uid('export.executive@email.com'); $sm = $uid('sales.manager@email.com'); $fm = $uid('finance.manager@email.com');
  App\\Services\\PaymentTermsApprovalService::approve(${o}, $md);
  $docApproved(${o}, 'QT', $exec, $md);
  ${MOVES.buyerPo(o)}
  OrderRepository::markPiFormSkipped(${o}, $exec, 'Buyer details already confirmed on the quotation');
  App\\Services\\AssociateCommission::fixLevelAtPi(${o});
  $docApproved(${o}, 'PI', $exec, $sm);
  ${MOVES.advanceRecorded(o)}
  $ord = OrderRepository::find(${o}); $pay = Pay::find(${o});
  Pay::markAdvanceCleared(${o}, date('Y-m-d'), $fm);
  Pay::setBalanceAmount(${o}, Pay::balanceDue(OrderProductRepository::totalFobValue(${o}), (float) $ord['advance_pct'], $pay,
    App\\Repositories\\PaymentMilestoneRepository::balanceDeduction(${o}, OrderProductRepository::totalFobValue(${o}), true)), null);
  Gate::passAndUnlockNext(${o}, 3, $fm);
  App\\Services\\ClientPortalService::provisionIfNeeded((int) $ord['client_id'], ${o});
  ${MOVES.ocSent(o)}
  App\\Repositories\\OrderOcAcknowledgmentRepository::markAcknowledged(${o}, 'client_portal', null, null);
  Gate::passAndUnlockNext(${o}, 4, null);`;

function move(demo, name, orderId) { return php(demo, MOVES[name](orderId)); }

/** Opens every closed <details> around an element (a step card inside a closed section), then scrolls to it. */
async function openTo(d, selector) {
  const l = typeof selector === 'string' ? d.page.locator(selector).first() : selector.first();
  await l.evaluate((el) => { for (let x = el.closest('details'); x; x = x.parentElement && x.parentElement.closest('details')) x.open = true; });
  await d.scrollTo(l);
  return l;
}

/** Portal password for the buyer (set off camera once the app has created the login) and its cookies. */
async function clientCookies(demo) {
  const cfg = require('../lib/config');
  const h = demo.php(`echo password_hash(${JSON.stringify(cfg.PASSWORD)}, PASSWORD_DEFAULT);`);
  demo.sql(`UPDATE client_logins cl JOIN clients c ON c.id = cl.client_id SET cl.password_hash = ${demo.q(h)}, cl.is_active = 1, cl.force_password_change = 0, cl.failed_login_count = 0, cl.locked_until = NULL WHERE c.email = ${demo.q(BUYER_EMAIL)}`);
  return require('./_overview').clientCookies(demo, BUYER_EMAIL);
}

module.exports = { ASSOC, BUYER_EMAIL, ROLES, setup, move, php, clientCookies, openTo };
