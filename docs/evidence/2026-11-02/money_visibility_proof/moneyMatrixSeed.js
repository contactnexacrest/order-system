'use strict';

/**
 * Owner points 6 / 15 (2 Nov 2026) — proof data for 12-money-matrix.spec.js.
 * Run as a child process with DB_DATABASE set to the stack's dev database
 * (both stacks share one schema, so the Node app's own db/fixtures write it).
 *
 * Creates one staff login per seeded role (all assigned to the order) and one
 * order whose money of each kind carries a distinctive "sentinel" amount that
 * appears nowhere else, plus generated-document files (current and orphaned
 * draft copies) and uploaded files of each money class. Prints JSON.
 */
const path = require('path');
const fs = require('fs');

const app = '/home/user/order-system/NODE_MySQL_Version/app';
const db = require(path.join(app, 'src/config/db'));
const { createTestClient, createTestOrder } = require(path.join(app, 'tests/support/fixtures'));

const SENTINELS = {
  selling: '917.43',
  buyer_payments: '823.61',
  purchase: '731.29',
  logistics: '649.17',
  financials: '557.83',
  incentives: '461.39',
};

async function main() {
  const passwordHash = process.argv[2];
  const storageDir = process.argv[3];
  fs.mkdirSync(storageDir, { recursive: true });

  // One login per role.
  const roles = await db.query('SELECT id, name FROM roles ORDER BY id');
  const users = [];
  for (const role of roles) {
    const email = `money-matrix-role-${role.id}@nexacrest.test`;
    let user = await db.queryOne('SELECT id FROM users WHERE email = :email', { email });
    if (!user) {
      const r = await db.execute(
        `INSERT INTO users (name, email, password_hash, role_id, is_active, force_password_change, two_fa_enabled, is_super_admin)
         VALUES (:name, :email, :hash, :role, 1, 0, 0, 0)`,
        { name: `MM ${role.name}`, email, hash: passwordHash, role: role.id }
      );
      user = { id: r.insertId };
    } else {
      await db.execute(
        `UPDATE users SET password_hash = :hash, role_id = :role, is_active = 1, force_password_change = 0, two_fa_enabled = 0,
                is_super_admin = 0, failed_login_count = 0, locked_until = NULL, is_archived = 0 WHERE id = :id`,
        { hash: passwordHash, role: role.id, id: user.id }
      );
    }
    users.push({ id: user.id, roleId: role.id, role: role.name, email });
  }

  // The sentinel order.
  const clientId = await createTestClient();
  await db.execute("UPDATE clients SET company_legal_name = 'MM Sentinel Buyer' WHERE id = :id", { id: clientId });
  const orderId = await createTestOrder(clientId, 'CIF', 'Standard — New Buyer', 'USD', null, null, users.map((u) => u.id));
  await db.execute("UPDATE orders SET order_reference = CONCAT('MM-SENTINEL-', id) WHERE id = :id", { id: orderId });

  // D-240 (2 Nov 2026): a Products-menu product whose reference SELLING price is the selling sentinel,
  // picked on the sentinel order's line — so the proof covers the product pages and the order-line note.
  let catalogProduct = await db.queryOne("SELECT id FROM catalog_products WHERE name = 'MM Sentinel Product'");
  if (!catalogProduct) {
    const r = await db.execute(
      "INSERT INTO catalog_products (name, specifications, hs_code, is_active) VALUES ('MM Sentinel Product', 'Sentinel slab', '68022310', 1)"
    );
    catalogProduct = { id: r.insertId };
  }
  await db.execute(
    "UPDATE catalog_products SET reference_price = :p, reference_currency = 'USD', reference_unit = 'PCS' WHERE id = :id",
    { p: SENTINELS.selling, id: catalogProduct.id }
  );
  await db.execute(
    `INSERT INTO order_products (order_id, line_no, line_type, description, quantity, unit, unit_price, fob_value, is_active, catalog_product_id)
     VALUES (:o, 1, 'goods', 'MM sentinel granite', 1, 'PCS', :p, :p, 1, :cp)`,
    { o: orderId, p: SENTINELS.selling, cp: catalogProduct.id }
  );
  await db.execute(
    `INSERT INTO order_payment_status (order_id, advance_amount, advance_remittance_received_at)
     VALUES (:o, :a, NOW())
     ON DUPLICATE KEY UPDATE advance_amount = :a, advance_remittance_received_at = NOW()`,
    { o: orderId, a: SENTINELS.buyer_payments }
  );

  let supplier = await db.queryOne("SELECT id FROM suppliers WHERE supplier_legal_name = 'MM Sentinel Quarry'");
  if (!supplier) {
    const r = await db.execute("INSERT INTO suppliers (supplier_legal_name) VALUES ('MM Sentinel Quarry')");
    supplier = { id: r.insertId };
  }
  const po = await db.execute(
    `INSERT INTO order_supplier_po (order_id, supplier_id, supplier_legal_name, supplier_po_reference, quantity, unit,
                                    unit_price_inr, basic_value_inr, gst_rate_pct, gst_amount_inr, total_payable_inr, advance_pct, advance_amount_inr, balance_amount_inr, status)
     VALUES (:o, :s, 'MM Sentinel Quarry', CONCAT('MM-SPO-', :o), 1, 'PCS', :v, :v, 0, 0, :v, 0, 0, :v, 'draft')`,
    { o: orderId, s: supplier.id, v: SENTINELS.purchase }
  );

  // Files: an uploaded one per money class, and generated documents.
  const files = [];
  async function file(origin, label, cls, name) {
    const p = path.join(storageDir, `mm-${orderId}-${files.length}-${Math.random().toString(16).slice(2, 8)}.pdf`);
    fs.writeFileSync(p, '%PDF-1.4 money matrix sentinel file');
    const r = await db.execute(
      `INSERT INTO file_store (client_id, order_id, file_origin, document_type_label, server_path, uuid_filename, original_filename, file_size_bytes, mime_type, is_active)
       VALUES (:c, :o, :origin, :label, :p, :u, :n, 36, 'application/pdf', 1)`,
      { c: clientId, o: orderId, origin, label, p, u: path.basename(p), n: name }
    );
    files.push({ id: r.insertId, cls, name });
    return r.insertId;
  }

  const supplierBillFile = await file('RECEIVED', 'Supplier bill / invoice', 'purchase', 'supplier-bill.pdf');
  await db.execute(
    `INSERT INTO order_vendor_bills (order_id, vendor_kind, supplier_po_id, bill_for, bill_no, bill_date, taxable_inr, gst_inr, total_inr, bill_file_id, doc_kind)
     VALUES (:o, 'supplier', :po, 'goods', 'MM-SUP-BILL', CURDATE(), :v, 0, :v, :f, 'invoice')`,
    { o: orderId, po: po.insertId, v: SENTINELS.purchase, f: supplierBillFile }
  );
  const logisticsBillFile = await file('RECEIVED', 'Logistics partner bill', 'logistics', 'logistics-bill.pdf');
  await db.execute(
    `INSERT INTO order_vendor_bills (order_id, vendor_kind, bill_for, bill_no, bill_date, taxable_inr, gst_inr, total_inr, bill_file_id, doc_kind)
     VALUES (:o, 'logistics', 'cha', 'MM-LOG-BILL', CURDATE(), :v, 0, :v, :f, 'invoice')`,
    { o: orderId, v: SENTINELS.logistics, f: logisticsBillFile }
  );
  const receiptFile = await file('RECEIVED', 'Expense receipt', 'financials', 'expense-receipt.pdf');
  await db.execute(
    `INSERT INTO order_expenses (order_id, expense_date, category, details, amount_inr, paid_by, receipt_file_id)
     VALUES (:o, CURDATE(), 'other', 'MM sentinel expense', :v, 'company_account', :f)`,
    { o: orderId, v: SENTINELS.financials, f: receiptFile }
  );
  await db.execute(
    `INSERT INTO order_cost_entries (order_id, category, amount_inr, recorded_by, description)
     VALUES (:o, 'other', :v, :u, 'MM sentinel cost')`,
    { o: orderId, v: SENTINELS.financials, u: users[1].id }
  ).catch(async () => {
    await db.execute(
      "INSERT INTO order_cost_entries (order_id, category, amount_inr, recorded_by) VALUES (:o, 'other', :v, :u)",
      { o: orderId, v: SENTINELS.financials, u: users[1].id }
    );
  });
  await db.execute(
    `INSERT INTO order_incentives (order_id, reward_override_inr, team_total_inr, associate_amount_inr)
     VALUES (:o, :v, :v, :v)
     ON DUPLICATE KEY UPDATE reward_override_inr = :v, team_total_inr = :v, associate_amount_inr = :v`,
    { o: orderId, v: SENTINELS.incentives }
  );
  // A staff incentive payout for this order (Incentives → Payouts, My incentives, the order's 12d).
  await db.execute(
    `INSERT INTO incentive_payouts (order_id, payee_kind, user_id, role, amount_inr, incentive_month, status)
     VALUES (:o, 'staff', :u, 'sales', :v, DATE_FORMAT(CURDATE(), '%Y-%m'), 'due')`,
    { o: orderId, u: users[1].id, v: SENTINELS.incentives }
  );
  const screenshotFile = await file('RECEIVED', 'Client payment self-report screenshot', 'buyer_payments', 'payment-screenshot.pdf');
  const buyerPoFile = await file('RECEIVED', 'Buyer PO copy', 'selling', 'buyer-po.pdf');
  await db.execute('INSERT INTO order_buyer_po_documents (order_id, file_id) VALUES (:o, :f)', { o: orderId, f: buyerPoFile });
  await file('RECEIVED', 'Packing photo', null, 'packing-photo.pdf');

  // Audit rows carrying each kind of money (the audit log, the order's audit page,
  // the per-order report's Full Audit Trail and their CSVs must hide them).
  const auditRows = [
    ['FIELD_EDIT', 'unit_price', SENTINELS.selling],
    ['ADVANCE_PAYMENT_RECORDED', 'advance_amount', SENTINELS.buyer_payments],
    ['SUPPLIER_BILL_ADDED', 'taxable_inr', SENTINELS.purchase],
    ['LOGISTICS_BILL_ADDED', 'taxable_inr', SENTINELS.logistics],
    ['ORDER_EXPENSE_ADDED', 'amount_inr', SENTINELS.financials],
    ['ORDER_INCENTIVE_SET', 'reward_override_inr', SENTINELS.incentives],
  ];
  for (const [action, field, value] of auditRows) {
    await db.execute(
      `INSERT INTO audit_log (user_id, action_type, entity_type, entity_id, field_name, old_value, new_value)
       VALUES (:u, :a, 'orders', :o, :f, NULL, :v)`,
      { u: users[1].id, a: action, o: orderId, f: field, v: value }
    );
  }

  // Generated documents: for each type, a current PDF, plus an orphaned draft copy
  // (no longer the document's pdf_file_id — the leak this round closes).
  const docClass = { QT: 'selling', PI: 'selling', CI: 'selling', SUPPO: 'purchase', BLI: 'logistics', PL: null, CAFIN: 'cafin' };
  for (const [code, cls] of Object.entries(docClass)) {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code });
    if (!type) continue;
    const draft = await file('GENERATED_AUTO', null, cls, `${code} MM-${orderId} Rev.0.pdf`);
    const current = await file('GENERATED_AUTO', null, cls, `${code} MM-${orderId}.pdf`);
    const r = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, revision_number, status, pdf_file_id)
       VALUES (:o, :t, CONCAT('MM-', :code, '-', :o), 0, 'approved', :f)`,
      { o: orderId, t: type.id, code, f: current }
    );
    // What the app now records for every generated file (DocumentRepository.linkFiles).
    await db.execute('UPDATE file_store SET linked_document_id = :d WHERE id IN (:a, :b)', { d: r.insertId, a: draft, b: current });
  }

  process.stdout.write(JSON.stringify({ orderId, clientId, supplierId: supplier.id, catalogProductId: catalogProduct.id, users, files, sentinels: SENTINELS }));
  await db.pool.end();
}

main().catch(async (e) => {
  process.stderr.write(String(e && e.stack ? e.stack : e));
  try { await db.pool.end(); } catch (_) { /* ignore */ }
  process.exit(1);
});
