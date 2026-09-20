<?php

declare(strict_types=1);

/**
 * Spec Section 10 — "LEVEL 2 ... APPROVE -> email sends at scheduled time."
 *
 * On Bluehost shared hosting there's no long-running worker process, so a
 * Level-2 approval can't fire the send itself the instant scheduled_at
 * arrives if that moment is in the future (and even an "immediate" send
 * still has to wait for cPanel's next cron tick — there's no queue
 * consumer otherwise). This script is that consumer: run it on a cPanel
 * Cron Job every 5-15 minutes (README has the exact setup), and it sends
 * every email_log row that's 'approved' and whose scheduled_at has
 * passed (or is NULL, meaning "immediate").
 *
 * Deliberately a thin loop — all the actual logic (which file to attach,
 * marking documents 'sent', audit logging) lives in EmailDispatchService,
 * so this script and a future "send now" button (if ever added) share
 * one code path instead of two.
 *
 * Usage: php /path/to/app/cron/dispatch_deferred_emails.php
 */

require __DIR__ . '/../bootstrap.php';

use App\Repositories\EmailLogRepository;
use App\Services\EmailDispatchService;

$due = EmailLogRepository::dueForSend();
$sentCount = 0;
$failedCount = 0;

foreach ($due as $row) {
    if (EmailDispatchService::dispatch($row)) {
        $sentCount++;
    } else {
        $failedCount++;
    }
}

echo "[dispatch_deferred_emails] checked " . count($due) . " due row(s): {$sentCount} sent, {$failedCount} failed." . PHP_EOL;
