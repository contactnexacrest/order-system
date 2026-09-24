<?php

declare(strict_types=1);

/**
 * docs/schema.sql Section AE — the buyer has 48 hours from the Order
 * Confirmation being emailed to either acknowledge it in their portal or
 * reply to the email (which staff record). If neither happens in that
 * window, it auto-confirms so the order isn't stuck waiting on a buyer who
 * never responds — Stage 5 (Supplier PO) unlocks exactly as if the buyer
 * had clicked "I acknowledge" themselves.
 *
 * Same reasoning as dispatch_deferred_emails.php: no persistent worker on
 * Bluehost shared hosting, so this is a polling script run via cPanel
 * Cron Job (README has the exact setup) rather than a scheduled task
 * firing in-process at exactly the 48-hour mark.
 *
 * Usage: php /path/to/app/cron/auto_confirm_oc_acknowledgments.php
 */

require __DIR__ . '/../bootstrap.php';

use App\Repositories\AuditLogRepository;
use App\Repositories\OrderOcAcknowledgmentRepository;
use App\Services\StageGateService;

$due = OrderOcAcknowledgmentRepository::dueForAutoConfirm();
$confirmedCount = 0;

foreach ($due as $row) {
    $orderId = (int) $row['order_id'];
    OrderOcAcknowledgmentRepository::markAcknowledged($orderId, 'auto_48h', null, null);
    StageGateService::passAndUnlockNext($orderId, 4, null);
    AuditLogRepository::log(null, 'OC_AUTO_CONFIRMED', 'orders', $orderId, null, null, null, 'Buyer did not respond within 48 hours of the Order Confirmation being emailed — auto-confirmed.');
    $confirmedCount++;
}

echo "[auto_confirm_oc_acknowledgments] checked " . count($due) . " due row(s): {$confirmedCount} auto-confirmed." . PHP_EOL;
