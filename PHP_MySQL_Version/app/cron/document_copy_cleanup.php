<?php

declare(strict_types=1);

/**
 * Owner points 5 / 8 / 9 (2 Nov 2026): daily Document copies clean-up.
 * Removes what Admin → Settings → Document copies clean-up lists in its
 * preview — nothing at all while every rule there is off (the default).
 * See App\Services\DocumentCopyCleanupService for what is never removed.
 *
 * Suggested crontab (once a day, quiet hours):
 *   15 2 * * * php /path/to/app/cron/document_copy_cleanup.php
 */

require __DIR__ . '/../bootstrap.php';

use App\Services\DocumentCopyCleanupService;

$result = DocumentCopyCleanupService::run(null, 'scheduled');
if ($result['skipped_off']) {
    echo "Document copies clean-up: every rule is off — nothing removed.\n";
    exit(0);
}
echo sprintf("Document copies clean-up: %d copy(ies) removed, %s freed%s.\n",
    $result['files'], DocumentCopyCleanupService::formatBytes($result['bytes']),
    $result['missing'] > 0 ? " ({$result['missing']} file(s) were already missing from storage)" : '');
