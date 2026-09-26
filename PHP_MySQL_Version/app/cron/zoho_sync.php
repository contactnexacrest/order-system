<?php

declare(strict_types=1);

/**
 * CA / Accounting module (Phase 3) — the scheduled half of the Zoho Books
 * sync (the other is the "Sync Now" button on /ca/zoho-sync). Pushes every
 * settlement leg that has an INR actual recorded but hasn't been synced
 * yet, same logic as the manual button, via the same CaSyncService so the
 * two never drift apart. Safe to run as often as wanted — a leg already
 * marked synced is never re-pushed, and CaSyncService::syncPendingRevenue()
 * itself no-ops cleanly (logging one "skipped" row) when Zoho Books isn't
 * configured, so this never breaks the cron job itself or blocks anything
 * else in the app (CA module brief, point 5).
 *
 * Usage: php /path/to/app/cron/zoho_sync.php
 * Suggested cadence: hourly, e.g. `0 * * * *` (see README's cron section
 * for the cPanel setup this project already uses for check_alerts.php).
 */

require __DIR__ . '/../bootstrap.php';

use App\Services\CaSyncService;

$result = CaSyncService::syncPendingRevenue('scheduled', null);

echo sprintf(
    "[%s] Zoho Books sync: %d synced, %d failed, %d skipped\n",
    date('Y-m-d H:i:s'),
    $result['synced'],
    $result['failed'],
    $result['skipped']
);
