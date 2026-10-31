<?php

declare(strict_types=1);

/**
 * CA / Accounting module — the scheduled half of the Zoho Books sync (the
 * other is the "Sync Now" button on /ca/zoho-sync). Runs the same
 * CaSyncService::runFullSync() the button calls, so the two never drift
 * apart: it sends what is new to Zoho Books, imports the CA's expenses,
 * and — once a day here, every time on Sync Now — checks the records
 * already in Books and puts back any changed or deleted there (owner
 * decision 2026-10-29). Safe to run as often as wanted — nothing already
 * sent is sent twice, and runFullSync() itself no-ops cleanly (logging a
 * "skipped" row for each direction) when Zoho Books isn't configured, so
 * this never breaks the cron job itself or blocks anything else in the
 * app (CA module brief, point 5).
 *
 * Usage: php /path/to/app/cron/zoho_sync.php
 * Suggested cadence: hourly, e.g. `0 * * * *` (see README's cron section
 * for the cPanel setup this project already uses for check_alerts.php).
 */

require __DIR__ . '/../bootstrap.php';

use App\Services\CaSyncService;

$result = CaSyncService::runFullSync('scheduled', null);

echo sprintf(
    "[%s] Zoho Books sync: revenue %d invoices/%d synced/%d applied/%d failed/%d skipped; purchases %d bills/%d payments/%d applied/%d expenses/%d failed; benefits %d journals/%d removed/%d failed; GST refunds %d journals/%d failed; expenses %d imported/%d updated/%d removed/%d failed/%d skipped\n",
    date('Y-m-d H:i:s'),
    $result['revenue']['invoices'],
    $result['revenue']['synced'],
    $result['revenue']['applied'],
    $result['revenue']['failed'],
    $result['revenue']['skipped'],
    $result['purchases']['bills'],
    $result['purchases']['payments'],
    $result['purchases']['applied'],
    $result['purchases']['expenses'],
    $result['purchases']['failed'],
    $result['benefits']['journals'],
    $result['benefits']['removed'],
    $result['benefits']['failed'],
    $result['gst_refunds']['journals'],
    $result['gst_refunds']['failed'],
    $result['expenses']['imported'],
    $result['expenses']['updated'],
    $result['expenses']['removed'],
    $result['expenses']['failed'],
    $result['expenses']['skipped']
);
