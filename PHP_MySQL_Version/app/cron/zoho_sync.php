<?php

declare(strict_types=1);

/**
 * CA / Accounting module — the scheduled half of the Zoho Books sync (the
 * other is the "Sync Now" button on /ca/zoho-sync). Runs both directions
 * — Phase 3's revenue push and Phase 4's expense import — via the same
 * CaSyncService::runFullSync() the button calls, so the two never drift
 * apart. Safe to run as often as wanted — an already-synced revenue leg
 * is never re-pushed and an already-imported expense is never
 * re-inserted, and runFullSync() itself no-ops cleanly (logging a
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
    "[%s] Zoho Books sync: revenue %d synced/%d failed/%d skipped; expenses %d imported/%d failed/%d skipped\n",
    date('Y-m-d H:i:s'),
    $result['revenue']['synced'],
    $result['revenue']['failed'],
    $result['revenue']['skipped'],
    $result['expenses']['imported'],
    $result['expenses']['failed'],
    $result['expenses']['skipped']
);
