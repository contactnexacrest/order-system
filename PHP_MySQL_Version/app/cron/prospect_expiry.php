<?php

declare(strict_types=1);

/**
 * Owner decision D-394 (20 Nov 2026): quick-quote prospects, once a day.
 *  1. Reminder: N days (Settings › Quick-quote prospects, default 7) before a
 *     prospect's quotation expires, its order's owner gets ONE notification
 *     (Notifications + My tasks) and e-mail.
 *  2. Automatic closing (when On): on the date, each of the prospect's still
 *     active orders moves to "Did not go ahead" (Mark Lost's own service and
 *     refusal rules — an order Mark Lost would refuse, or one past the
 *     quotation stage, leaves the whole client as it is) and the client is
 *     deactivated. Nothing is ever deleted.
 * Safe to run twice a day: a reminder is sent once per expiry date and a
 * closed client is no longer active.
 *
 * Run on a cPanel Cron Job, e.g. 06:40 IST:
 *   php /path/to/app/cron/prospect_expiry.php
 * Node twin: src/jobs/prospectExpiry.js (run by the app's own scheduler).
 */

require __DIR__ . '/../bootstrap.php';

$reminded = \App\Services\ProspectService::sendReminders();
echo "Prospect reminders: {$reminded} sent.\n";
$closed = \App\Services\ProspectService::expireDue();
echo "Prospects closed automatically: {$closed['clients']} client(s), {$closed['orders']} order(s) moved to \"Did not go ahead\"."
    . (\App\Services\ProspectService::autoClose() ? '' : ' (Automatic closing is off — only reminders.)') . "\n";
foreach ($closed['skipped'] as $line) {
    echo "Left as it is: {$line}\n";
}
