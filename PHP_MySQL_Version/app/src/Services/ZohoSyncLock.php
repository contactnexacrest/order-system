<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\ZohoSyncLogRepository;

/**
 * QA batch 4, ZOH-1: only one Zoho sync at a time. The hourly run, "Sync
 * now", "Sync this order" and a record's "Send to Zoho Books" all read the
 * same waiting records before marking them sent; two at once would post the
 * same payment, expense or journal twice. A database lock (per database, so
 * the PHP and Node stacks on one database share it) is taken without waiting:
 * a second run stops at once with one Sync Log row. Node twin:
 * src/services/zohoSyncLock.js.
 */
final class ZohoSyncLock
{
    public const BUSY = 'A Zoho sync is already running — try again in a minute.';

    private static int $depth = 0;

    /** Runs $fn holding the lock; when another sync holds it, logs one row and returns $busy(). */
    public static function run(callable $fn, callable $busy, string $triggeredBy, ?int $userId, ?int $orderId = null)
    {
        if (self::$depth > 0) {
            return $fn(); // already inside a sync (e.g. "Send to Zoho Books" from a full run)
        }
        $pdo = Database::connection();
        $got = (int) $pdo->query("SELECT GET_LOCK(CONCAT('nx_zoho_sync_', DATABASE()), 0)")->fetchColumn();
        if ($got !== 1) {
            ZohoSyncLogRepository::log('sync_run', $orderId !== null ? 'order' : null, $orderId, null, 'error', null, self::BUSY, $triggeredBy, $userId);
            return $busy();
        }
        self::$depth++;
        try {
            return $fn();
        } finally {
            self::$depth--;
            $pdo->query("SELECT RELEASE_LOCK(CONCAT('nx_zoho_sync_', DATABASE()))")->fetchColumn();
        }
    }
}
