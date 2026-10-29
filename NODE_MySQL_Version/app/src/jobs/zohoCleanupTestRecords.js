'use strict';

require('../config/env'); // loads .env as a side effect — must run first
const fs = require('fs');
const db = require('../config/db');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const zohoBooksService = require('../services/zohoBooksService');
const zohoGuard = require('../services/zohoGuard');
const orderZohoInvoiceRepository = require('../repositories/orderZohoInvoiceRepository');
const zohoPurchaseRepository = require('../repositories/zohoPurchaseRepository');
const orderLcDetailRepository = require('../repositories/orderLcDetailRepository');

/**
 * Port of cron/zoho_cleanup_test_records.php. Zoho integration Stage 1
 * (2026-10-29) — removes the records made in Zoho Books while testing,
 * before go-live. Testing runs in NexaCrest's real Books organisation
 * (owner decision), so every test record must go.
 *
 * What it removes:
 *   - every Books customer whose name starts with TEST-, with its payments
 *     and (Stage 2) its invoices; (Stage 3) every TEST- vendor with its
 *     payments and bills, and every expense whose description starts TEST-;
 *     every journal whose notes start TEST- (export benefits, 2026-10-29);
 *   - with --from-sync-log, also every payment this database's sync log
 *     pushed, every invoice it sent (order_zoho_invoices), every customer
 *     this database created (clients.zoho_contact_id), and every vendor,
 *     bill, vendor payment and expense it sent (Stage 3).
 *     Run that only on the TEST database, never on the live one.
 * Payments go first, then invoices, bills and expenses (Books refuses to
 * delete a contact that still has transactions); this database's Zoho references are then
 * cleared so a later sync starts clean.
 *
 * Usage:
 *   node src/jobs/zohoCleanupTestRecords.js                  # dry run: lists what would go, changes nothing
 *   node src/jobs/zohoCleanupTestRecords.js --apply          # deletes them
 *   ... --from-sync-log                                      # include this database's synced records
 *   ... --by "Name"                                          # who is running it (for the change log)
 *   ... --log ../docs/zoho/zoho-change-log.csv               # append one row per deletion to the change log
 * Without --log, the change-log rows are printed for pasting in.
 */
function csvLine(row) {
  return row.map((v) => (/[",\n]/.test(String(v)) ? `"${String(v).replace(/"/g, '""')}"` : String(v))).join(',');
}

async function run(args) {
  const apply = args.includes('--apply');
  const fromSyncLog = args.includes('--from-sync-log');
  if (fromSyncLog && zohoGuard.isProduction()) {
    console.error("Refused: --from-sync-log would take this database's real payments and customers. It runs only where ZOHO_ENVIRONMENT=test.");
    return 1;
  }
  const option = (name) => {
    const i = args.indexOf(name);
    return i !== -1 && args[i + 1] !== undefined ? args[i + 1] : null;
  };
  const by = option('--by') || process.env.USER || 'unknown';
  const logFile = option('--log');

  const plan = { customerpayment: new Map(), vendorpayment: new Map(), invoice: new Map(), bill: new Map(), expense: new Map(), journal: new Map(), contact: new Map() };
  for (const contact of await zohoBooksService.listTestContacts()) {
    for (const payment of contact.payments) {
      plan.customerpayment.set(payment.payment_id, `payment ${payment.label} of ${contact.name}`);
    }
    for (const invoice of contact.invoices) {
      plan.invoice.set(invoice.invoice_id, `invoice ${invoice.label} of ${contact.name}`);
    }
    for (const payment of contact.vendor_payments) {
      plan.vendorpayment.set(payment.payment_id, `vendor payment ${payment.label} to ${contact.name}`);
    }
    for (const bill of contact.bills) {
      plan.bill.set(bill.bill_id, `bill ${bill.label} from ${contact.name}`);
    }
    plan.contact.set(contact.contact_id, `${contact.bills.length > 0 || contact.vendor_payments.length > 0 ? 'vendor' : 'contact'} ${contact.name}`);
  }
  for (const expense of await zohoBooksService.listTestExpenses()) {
    plan.expense.set(expense.expense_id, `expense ${expense.label}`);
  }
  for (const journal of await zohoBooksService.listTestJournals()) {
    plan.journal.set(journal.journal_id, `journal ${journal.label}`);
  }
  if (fromSyncLog) {
    const rows = await db.query("SELECT entity_id, leg, zoho_reference FROM zoho_sync_log WHERE sync_type = 'revenue_payment' AND status = 'success' AND zoho_reference IS NOT NULL");
    for (const row of rows) {
      const id = String(row.zoho_reference);
      if (!plan.customerpayment.has(id)) plan.customerpayment.set(id, `payment for order #${row.entity_id} (${row.leg}) from this database's sync log`);
    }
    for (const row of await db.query('SELECT order_id, kind, zoho_invoice_id, zoho_invoice_number FROM order_zoho_invoices')) {
      const id = String(row.zoho_invoice_id);
      if (!plan.invoice.has(id)) plan.invoice.set(id, `invoice ${row.zoho_invoice_number} for order #${row.order_id} (${row.kind}) from this database`);
    }
    for (const row of await db.query('SELECT company_legal_name, zoho_contact_id FROM clients WHERE zoho_contact_id IS NOT NULL')) {
      const id = String(row.zoho_contact_id);
      if (!plan.contact.has(id)) plan.contact.set(id, `customer ${row.company_legal_name} (created by this database)`);
    }
    for (const row of await db.query('SELECT id, scheme_name, received_amount, zoho_journal_id FROM ca_export_benefits WHERE zoho_journal_id IS NOT NULL')) {
      const id = String(row.zoho_journal_id);
      if (!plan.journal.has(id)) plan.journal.set(id, `journal for export benefit #${row.id} ${row.scheme_name} INR ${row.received_amount} (created by this database)`);
    }
    for (const record of await zohoPurchaseRepository.pushedRecords()) {
      if (!plan[record.kind].has(record.id)) plan[record.kind].set(record.id, record.label);
    }
  }

  for (const [kind, items] of Object.entries(plan)) {
    for (const [id, label] of items) console.log(`${kind} ${id}  ${label}`);
  }
  console.log(`\n${plan.customerpayment.size} customer payment(s), ${plan.vendorpayment.size} vendor payment(s), ${plan.invoice.size} invoice(s), ${plan.bill.size} bill(s), ${plan.expense.size} expense(s), ${plan.journal.size} journal(s) and ${plan.contact.size} customer/vendor contact(s) to delete from Zoho Books.`);
  if (!apply) {
    console.log('Dry run — nothing changed. Run again with --apply to delete them.');
    return 0;
  }

  const orgId = (await companySettingsRepository.get('zoho_books_organization_id')) || '';
  const csvRows = [];
  let failed = 0;
  let n = 0;
  for (const [kind, items] of Object.entries(plan)) {
    for (const [id, label] of items) {
      try {
        const deleted = await zohoBooksService.deleteRecord(kind, id);
        console.log(`${deleted ? 'deleted' : 'already gone'} ${kind} ${id}`);
        const now = new Date(Date.now() + 5.5 * 3600e3).toISOString().slice(0, 16).replace('T', ' ');
        if (deleted) n += 1;
        if (deleted) csvRows.push([
          `Z-NEW-${n}`, now, by, by, 'Zoho API (cleanup script)', 'Zoho Books', `Books org ${orgId}`,
          {
            contact: 'DELETE /books/v3/contacts/{id} (Books: Sales -> Customers / Purchases -> Vendors)',
            invoice: 'DELETE /books/v3/invoices/{id} (Books: Sales -> Invoices)',
            vendorpayment: 'DELETE /books/v3/vendorpayments/{id} (Books: Purchases -> Payments Made)',
            bill: 'DELETE /books/v3/bills/{id} (Books: Purchases -> Bills)',
            expense: 'DELETE /books/v3/expenses/{id} (Books: Purchases -> Expenses)',
            customerpayment: 'DELETE /books/v3/customerpayments/{id} (Books: Sales -> Payments Received)',
            journal: 'DELETE /books/v3/journals/{id} (Books: Accountant -> Manual Journals)',
          }[kind],
          'Delete', `${label}; Zoho ID ${id}`, 'Exists', 'Deleted', 'Remove test data before go-live', 'Test', 'Yes', `${now} / ${by}`,
          'src/jobs/zohoCleanupTestRecords.js', '', '',
        ]);
        await zohoPurchaseRepository.forgetZohoRecord(kind, id);
        if (kind === 'contact') {
          await db.execute('UPDATE clients SET zoho_contact_id = NULL WHERE zoho_contact_id = :id', { id });
        } else if (kind === 'invoice') {
          await orderZohoInvoiceRepository.forgetZohoInvoice(id);
        } else if (kind === 'customerpayment') {
          for (const leg of ['advance', 'balance', 'freight']) {
            await db.execute(`UPDATE order_payment_status SET ${leg}_zoho_synced_at = NULL, ${leg}_zoho_reference = NULL, ${leg}_zoho_applied_at = NULL WHERE ${leg}_zoho_reference = :id`, { id });
          }
          await orderLcDetailRepository.forgetZohoPayment(id);
        } else if (kind === 'journal') {
          await db.execute('UPDATE ca_export_benefits SET zoho_journal_id = NULL, zoho_synced_at = NULL, zoho_pushed_hash = NULL WHERE zoho_journal_id = :id', { id });
        }
      } catch (e) {
        failed += 1;
        console.log(`! ${kind} ${id}: ${e.message}`);
      }
    }
  }

  const lines = csvRows.map(csvLine);
  if (logFile) {
    fs.appendFileSync(logFile, lines.map((l) => `${l}\r\n`).join(''));
    console.log(`${csvRows.length} row(s) added to ${logFile} — renumber Z-NEW-n to the next Log IDs.`);
  } else if (lines.length > 0) {
    console.log('\nRows for docs/zoho/zoho-change-log.csv (renumber Z-NEW-n to the next Log IDs):');
    for (const l of lines) console.log(l);
  }
  return failed > 0 ? 1 : 0;
}

if (require.main === module) {
  run(process.argv.slice(2))
    .then((code) => {
      process.exitCode = code;
      return db.pool.end();
    })
    .catch((e) => {
      console.error(e.message);
      process.exitCode = 1;
      return db.pool.end();
    });
}

module.exports = { run };
