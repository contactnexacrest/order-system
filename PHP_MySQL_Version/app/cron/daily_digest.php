<?php

declare(strict_types=1);

/**
 * Owner decision 1 Nov 2026 (C-7b): the daily per-person digest. Run once
 * every morning on a cPanel Cron Job, e.g. 08:15 IST:
 *   php /path/to/app/cron/daily_digest.php
 * Safe to run twice: each person gets at most one digest a day.
 */

require __DIR__ . '/../bootstrap.php';

$result = \App\Services\DailyDigestService::run();
echo "Daily digest: {$result['sent']} sent, {$result['skipped']} with nothing waiting or already sent.\n";
