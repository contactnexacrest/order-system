<?php

declare(strict_types=1);

/**
 * Zoho integration Stage 1 (2026-10-29) — removes the records made in
 * Zoho Books while testing, before go-live. Testing runs in NexaCrest's
 * real Books organisation (owner decision), so every test record must go.
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
 *   php app/cron/zoho_cleanup_test_records.php                     # dry run: lists what would go, changes nothing
 *   php app/cron/zoho_cleanup_test_records.php --apply             # deletes them
 *   ... --from-sync-log                                            # include this database's synced records
 *   ... --by "Name"                                                # who is running it (for the change log)
 *   ... --log ../docs/zoho/zoho-change-log.csv                     # append one row per deletion to the change log
 * Without --log, the change-log rows are printed for pasting in.
 */

require __DIR__ . '/../bootstrap.php';

use App\Config\Database;
use App\Services\ZohoBooksService;

$args = array_slice($argv, 1);
$apply = in_array('--apply', $args, true);
$fromSyncLog = in_array('--from-sync-log', $args, true);
if ($fromSyncLog && App\Services\ZohoGuard::isProduction()) {
    fwrite(STDERR, "Refused: --from-sync-log would take this database's real payments and customers. It runs only where ZOHO_ENVIRONMENT=test.\n");
    exit(1);
}
$option = static function (string $name) use ($args): ?string {
    $i = array_search($name, $args, true);
    return $i !== false && isset($args[$i + 1]) ? $args[$i + 1] : null;
};
$by = $option('--by') ?? (getenv('USER') ?: 'unknown');
$logFile = $option('--log');

$pdo = Database::connection();
$plan = []; // kind => [id => label]
$plan['customerpayment'] = [];
$plan['vendorpayment'] = [];
$plan['invoice'] = [];
$plan['bill'] = [];
$plan['expense'] = [];
$plan['journal'] = [];
$plan['contact'] = [];

foreach (ZohoBooksService::listTestContacts() as $contact) {
    foreach ($contact['payments'] as $payment) {
        $plan['customerpayment'][$payment['payment_id']] = "payment {$payment['label']} of {$contact['name']}";
    }
    foreach ($contact['invoices'] as $invoice) {
        $plan['invoice'][$invoice['invoice_id']] = "invoice {$invoice['label']} of {$contact['name']}";
    }
    foreach ($contact['vendor_payments'] as $payment) {
        $plan['vendorpayment'][$payment['payment_id']] = "vendor payment {$payment['label']} to {$contact['name']}";
    }
    foreach ($contact['bills'] as $bill) {
        $plan['bill'][$bill['bill_id']] = "bill {$bill['label']} from {$contact['name']}";
    }
    $plan['contact'][$contact['contact_id']] = ($contact['bills'] !== [] || $contact['vendor_payments'] !== [] ? 'vendor ' : 'contact ') . $contact['name'];
}
foreach (ZohoBooksService::listTestExpenses() as $expense) {
    $plan['expense'][$expense['expense_id']] = "expense {$expense['label']}";
}
foreach (ZohoBooksService::listTestJournals() as $journal) {
    $plan['journal'][$journal['journal_id']] = "journal {$journal['label']}";
}
if ($fromSyncLog) {
    $rows = $pdo->query("SELECT entity_id, leg, zoho_reference FROM zoho_sync_log WHERE sync_type = 'revenue_payment' AND status = 'success' AND zoho_reference IS NOT NULL")->fetchAll();
    foreach ($rows as $row) {
        $plan['customerpayment'][(string) $row['zoho_reference']] ??= "payment for order #{$row['entity_id']} ({$row['leg']}) from this database's sync log";
    }
    foreach ($pdo->query('SELECT order_id, kind, zoho_invoice_id, zoho_invoice_number FROM order_zoho_invoices')->fetchAll() as $row) {
        $plan['invoice'][(string) $row['zoho_invoice_id']] ??= "invoice {$row['zoho_invoice_number']} for order #{$row['order_id']} ({$row['kind']}) from this database";
    }
    foreach ($pdo->query('SELECT company_legal_name, zoho_contact_id FROM clients WHERE zoho_contact_id IS NOT NULL')->fetchAll() as $row) {
        $plan['contact'][(string) $row['zoho_contact_id']] ??= "customer {$row['company_legal_name']} (created by this database)";
    }
    foreach ($pdo->query('SELECT id, scheme_name, received_amount, zoho_journal_id FROM ca_export_benefits WHERE zoho_journal_id IS NOT NULL')->fetchAll() as $row) {
        $plan['journal'][(string) $row['zoho_journal_id']] ??= "journal for export benefit #{$row['id']} {$row['scheme_name']} INR {$row['received_amount']} (created by this database)";
    }
    foreach (App\Repositories\ZohoPurchaseRepository::pushedRecords() as $record) {
        $plan[$record['kind']][$record['id']] ??= $record['label'];
    }
}

foreach ($plan as $kind => $items) {
    foreach ($items as $id => $label) {
        echo "{$kind} {$id}  {$label}\n";
    }
}
echo sprintf(
    "\n%d customer payment(s), %d vendor payment(s), %d invoice(s), %d bill(s), %d expense(s), %d journal(s) and %d customer/vendor contact(s) to delete from Zoho Books.\n",
    count($plan['customerpayment']), count($plan['vendorpayment']), count($plan['invoice']), count($plan['bill']), count($plan['expense']), count($plan['journal']), count($plan['contact'])
);
if (!$apply) {
    echo "Dry run — nothing changed. Run again with --apply to delete them.\n";
    exit(0);
}

$csvRows = [];
$failed = 0;
$n = 0;
foreach ($plan as $kind => $items) {
    foreach ($items as $id => $label) {
        try {
            $deleted = ZohoBooksService::deleteRecord($kind, (string) $id);
            echo ($deleted ? 'deleted' : 'already gone') . " {$kind} {$id}\n";
            $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i');
            if ($deleted) {
                $csvRows[] = [
                    'Z-NEW-' . (++$n), $now, $by, $by, 'Zoho API (cleanup script)', 'Zoho Books', 'Books org ' . (string) App\Repositories\CompanySettingsRepository::get('zoho_books_organization_id'),
                    [
                        'contact' => 'DELETE /books/v3/contacts/{id} (Books: Sales -> Customers / Purchases -> Vendors)',
                        'invoice' => 'DELETE /books/v3/invoices/{id} (Books: Sales -> Invoices)',
                        'vendorpayment' => 'DELETE /books/v3/vendorpayments/{id} (Books: Purchases -> Payments Made)',
                        'bill' => 'DELETE /books/v3/bills/{id} (Books: Purchases -> Bills)',
                        'expense' => 'DELETE /books/v3/expenses/{id} (Books: Purchases -> Expenses)',
                        'customerpayment' => 'DELETE /books/v3/customerpayments/{id} (Books: Sales -> Payments Received)',
                        'journal' => 'DELETE /books/v3/journals/{id} (Books: Accountant -> Manual Journals)',
                    ][$kind],
                    'Delete', "{$label}; Zoho ID {$id}", 'Exists', 'Deleted', 'Remove test data before go-live', 'Test', 'Yes', "{$now} / {$by}",
                    'cron/zoho_cleanup_test_records.php', '', '',
                ];
            }
            App\Repositories\ZohoPurchaseRepository::forgetZohoRecord($kind, (string) $id);
            if ($kind === 'contact') {
                $pdo->prepare('UPDATE clients SET zoho_contact_id = NULL WHERE zoho_contact_id = :id')->execute(['id' => $id]);
            } elseif ($kind === 'invoice') {
                App\Repositories\OrderZohoInvoiceRepository::forgetZohoInvoice((string) $id);
            } elseif ($kind === 'customerpayment') {
                foreach (['advance', 'balance', 'freight'] as $leg) {
                    $pdo->prepare("UPDATE order_payment_status SET {$leg}_zoho_synced_at = NULL, {$leg}_zoho_reference = NULL, {$leg}_zoho_applied_at = NULL WHERE {$leg}_zoho_reference = :id")->execute(['id' => $id]);
                }
                App\Repositories\OrderLcDetailRepository::forgetZohoPayment((string) $id);
            } elseif ($kind === 'journal') {
                $pdo->prepare('UPDATE ca_export_benefits SET zoho_journal_id = NULL, zoho_synced_at = NULL, zoho_pushed_hash = NULL WHERE zoho_journal_id = :id')->execute(['id' => $id]);
            }
        } catch (\Throwable $e) {
            $failed++;
            echo "! {$kind} {$id}: {$e->getMessage()}\n";
        }
    }
}

$out = $logFile !== null ? fopen($logFile, 'ab') : fopen('php://stdout', 'wb');
if ($logFile === null && $csvRows !== []) {
    echo "\nRows for docs/zoho/zoho-change-log.csv (renumber Z-NEW-n to the next Log IDs):\n";
}
foreach ($csvRows as $row) {
    fputcsv($out, $row, ',', '"', '', "\r\n");
}
if ($logFile !== null) {
    fclose($out);
    echo count($csvRows) . " row(s) added to {$logFile} — renumber Z-NEW-n to the next Log IDs.\n";
}
exit($failed > 0 ? 1 : 0);
