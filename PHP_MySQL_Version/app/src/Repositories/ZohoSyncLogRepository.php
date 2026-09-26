<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * CA / Accounting module (Phase 3) — the Zoho Books sync's own
 * audit/error log, deliberately independent of AuditLogRepository (see
 * schema.sql comment on zoho_sync_log). Read-only from the outside, same
 * as AuditLogRepository: nothing here ever updates or deletes a row.
 */
final class ZohoSyncLogRepository
{
    public static function log(
        string $syncType,
        ?string $entityType,
        ?int $entityId,
        ?string $leg,
        string $status,
        ?string $zohoReference,
        ?string $message,
        string $triggeredBy,
        ?int $triggeredByUserId
    ): void {
        Database::connection()->prepare(
            'INSERT INTO zoho_sync_log
                (sync_type, entity_type, entity_id, leg, status, zoho_reference, message, triggered_by, triggered_by_user_id)
             VALUES
                (:sync_type, :entity_type, :entity_id, :leg, :status, :zoho_reference, :message, :triggered_by, :triggered_by_user_id)'
        )->execute([
            'sync_type' => $syncType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'leg' => $leg,
            'status' => $status,
            'zoho_reference' => $zohoReference,
            'message' => $message,
            'triggered_by' => $triggeredBy,
            'triggered_by_user_id' => $triggeredByUserId,
        ]);
    }

    /** @return array<int, array<string,mixed>> newest first */
    public static function recent(int $limit = 100): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT zsl.*, u.name AS triggered_by_name
             FROM zoho_sync_log zsl
             LEFT JOIN users u ON u.id = zsl.triggered_by_user_id
             ORDER BY zsl.created_at DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
