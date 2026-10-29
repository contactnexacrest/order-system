'use strict';

require('../config/env'); // loads .env as a side effect — must run first
const fs = require('fs');
const db = require('../config/db');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const zohoBooksService = require('../services/zohoBooksService');
const zohoGuard = require('../services/zohoGuard');
const orderZohoInvoiceRepository = require('../repositories/orderZohoInvoiceRepository');

/**
 * Port of cron/zoho_cleanup_test_records.php. Zoho integration Stage 1
 * (2026-10-29) — removes the records made in Zoho Books while testing,
 * before go-live. Testing runs in NexaCrest's real Books organisation
 * (owner decision), so every test record must go.
 *
 * What it removes:
 *   - every Books customer whose name starts with TEST-, with its payments
 *     and (Stage 2) its invoices;
 *   - with --from-sync-log, also every payment this database's sync log
 *     pushed, every invoice it sent (order_zoho_invoices) and every
 *     customer this database created (clients.zoho_contact_id).
 *     Run that only on the TEST database, never on the live one.
 * Payments go first, then invoices (Books refuses to delete a customer
 * that still has transactions); this database's Zoho references are then
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

  const plan = { customerpayment: new Map(), invoice: new Map(), contact: new Map() };
  for (const contact of await zohoBooksService.listTestContacts()) {
    for (const payment of contact.payments) {
      plan.customerpayment.set(payment.payment_id, `payment ${payment.label} of ${contact.name}`);
    }
    for (const invoice of contact.invoices) {
      plan.invoice.set(invoice.invoice_id, `invoice ${invoice.label} of ${contact.name}`);
    }
    plan.contact.set(contact.contact_id, `customer ${contact.name}`);
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
  }

  for (const [kind, items] of Object.entries(plan)) {
    for (const [id, label] of items) console.log(`${kind} ${id}  ${label}`);
  }
  console.log(`\n${plan.customerpayment.size} payment(s), ${plan.invoice.size} invoice(s) and ${plan.contact.size} customer(s) to delete from Zoho Books.`);
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
        await zohoBooksService.deleteRecord(kind, id);
        console.log(`deleted ${kind} ${id}`);
        const now = new Date(Date.now() + 5.5 * 3600e3).toISOString().slice(0, 16).replace('T', ' ');
        n += 1;
        csvRows.push([
          `Z-NEW-${n}`, now, by, by, 'Zoho API (cleanup script)', 'Zoho Books', `Books org ${orgId}`,
          {
            contact: 'DELETE /books/v3/contacts/{id} (Books: Sales -> Customers)',
            invoice: 'DELETE /books/v3/invoices/{id} (Books: Sales -> Invoices)',
            customerpayment: 'DELETE /books/v3/customerpayments/{id} (Books: Sales -> Payments Received)',
          }[kind],
          'Delete', `${label}; Zoho ID ${id}`, 'Exists', 'Deleted', 'Remove test data before go-live', 'Test', 'Yes', `${now} / ${by}`,
          'src/jobs/zohoCleanupTestRecords.js', '', '',
        ]);
        if (kind === 'contact') {
          await db.execute('UPDATE clients SET zoho_contact_id = NULL WHERE zoho_contact_id = :id', { id });
        } else if (kind === 'invoice') {
          await orderZohoInvoiceRepository.forgetZohoInvoice(id);
        } else {
          for (const leg of ['advance', 'balance', 'freight']) {
            await db.execute(`UPDATE order_payment_status SET ${leg}_zoho_synced_at = NULL, ${leg}_zoho_reference = NULL, ${leg}_zoho_applied_at = NULL WHERE ${leg}_zoho_reference = :id`, { id });
          }
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
