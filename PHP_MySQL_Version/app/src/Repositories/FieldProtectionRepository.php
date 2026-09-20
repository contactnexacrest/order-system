<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;
use RuntimeException;

/**
 * Repository for the "protected fields" governance mechanism (Section L,
 * schema.sql). One shared table/flag/workflow, reused across every table
 * that can carry an is_protected column — currently company_settings,
 * tc_clauses, payment_presets. Deliberately generic: a new protectable
 * table only needs an is_protected column and an entry in
 * PROTECTABLE_TABLES below, nothing else here changes.
 *
 * Flipping is_protected is NEVER done directly by a single user — it always
 * goes through field_protection_requests: one user requests lock/unlock
 * with a reason, a second, different privileged user (manage_field_protection)
 * approves or rejects. Approval is the only code path that actually writes
 * to the is_protected column; see approve() below.
 */
final class FieldProtectionRepository
{
    /**
     * Fixed whitelist of protectable tables — the only tables this class
     * will ever interpolate into a query. idCol and labelExpr are both
     * hardcoded strings here, never derived from request input, so the
     * interpolation below is safe.
     *
     * @var array<string, array{idCol:string, labelExpr:string}>
     */
    private const PROTECTABLE_TABLES = [
        'company_settings' => ['idCol' => 'id', 'labelExpr' => 'setting_key'],
        'tc_clauses' => ['idCol' => 'id', 'labelExpr' => "CONCAT(COALESCE(clause_number, '\u{2014}'), ' \u{2014} ', clause_title)"],
        'payment_presets' => ['idCol' => 'id', 'labelExpr' => 'preset_name'],
    ];

    /** @return array<string, array{idCol:string, labelExpr:string}> */
    public static function protectableTables(): array
    {
        return self::PROTECTABLE_TABLES;
    }

    private static function assertKnownTable(string $tableName): void
    {
        if (!isset(self::PROTECTABLE_TABLES[$tableName])) {
            throw new RuntimeException("Unknown protectable table: {$tableName}");
        }
    }

    /** @return array<string, array<int, array<string,mixed>>> */
    public static function listProtectable(): array
    {
        $pdo = Database::connection();
        $out = [];
        foreach (self::PROTECTABLE_TABLES as $tableName => $def) {
            // Table name and label expression both come from the fixed
            // whitelist above, never from user input, so this
            // interpolation is safe.
            $out[$tableName] = $pdo->query(
                "SELECT {$def['idCol']} AS id, ({$def['labelExpr']}) AS label, is_protected FROM {$tableName} ORDER BY {$def['idCol']}"
            )->fetchAll();
        }
        return $out;
    }

    /** @return array<int, array<string,mixed>> */
    public static function pendingRequests(): array
    {
        return Database::connection()->query(
            "SELECT fpr.*, u.name AS requested_by_name
             FROM field_protection_requests fpr
             JOIN users u ON u.id = fpr.requested_by
             WHERE fpr.status = 'pending'
             ORDER BY fpr.created_at ASC"
        )->fetchAll();
    }

    /** @return array<int, array<string,mixed>> */
    public static function recentResolved(int $limit = 20): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT fpr.*, u.name AS requested_by_name, r.name AS resolved_by_name
             FROM field_protection_requests fpr
             JOIN users u ON u.id = fpr.requested_by
             LEFT JOIN users r ON r.id = fpr.resolved_by
             WHERE fpr.status IN ('approved','rejected')
             ORDER BY fpr.resolved_at DESC
             LIMIT " . max(0, $limit)
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function findPendingForRecord(string $tableName, int $recordId): ?array
    {
        self::assertKnownTable($tableName);
        $stmt = Database::connection()->prepare(
            "SELECT * FROM field_protection_requests
             WHERE table_name = :table_name AND record_id = :record_id AND status = 'pending' LIMIT 1"
        );
        $stmt->execute(['table_name' => $tableName, 'record_id' => $recordId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function getRequest(int $requestId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM field_protection_requests WHERE id = :id');
        $stmt->execute(['id' => $requestId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function createRequest(
        string $tableName,
        int $recordId,
        string $recordLabel,
        string $action,
        string $reason,
        int $requestedBy
    ): int {
        self::assertKnownTable($tableName);
        if (!in_array($action, ['lock', 'unlock'], true)) {
            throw new RuntimeException("Invalid requested_action: {$action}");
        }
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO field_protection_requests
                (table_name, record_id, record_label, requested_action, reason, requested_by)
             VALUES
                (:table_name, :record_id, :record_label, :action, :reason, :requested_by)'
        )->execute([
            'table_name' => $tableName,
            'record_id' => $recordId,
            'record_label' => $recordLabel,
            'action' => $action,
            'reason' => $reason,
            'requested_by' => $requestedBy,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Approves a pending request: flips the actual is_protected column on
     * the target table, then marks the request resolved. The two writes
     * happen in one transaction so a request can never end up "approved"
     * while the underlying flag failed to flip, or vice versa.
     *
     * @return array<string,mixed> the resolved request row plus 'newFlag'
     */
    public static function approve(int $requestId, int $resolvedBy, ?string $resolvedReason): array
    {
        $request = self::getRequest($requestId);
        if (!$request) {
            throw new RuntimeException('Request not found.');
        }
        if ($request['status'] !== 'pending') {
            throw new RuntimeException('This request has already been resolved.');
        }
        if ((int) $request['requested_by'] === $resolvedBy && !\App\Services\SuperAdminService::isEffective($resolvedBy)) {
            throw new RuntimeException('You cannot approve your own protection request — a different privileged user must confirm.');
        }
        self::assertKnownTable($request['table_name']);
        $def = self::PROTECTABLE_TABLES[$request['table_name']];
        $newFlag = $request['requested_action'] === 'lock' ? 1 : 0;

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "UPDATE {$request['table_name']} SET is_protected = :flag WHERE {$def['idCol']} = :id"
            )->execute(['flag' => $newFlag, 'id' => $request['record_id']]);

            $pdo->prepare(
                "UPDATE field_protection_requests
                    SET status = 'approved', resolved_by = :resolved_by, resolved_reason = :resolved_reason, resolved_at = NOW()
                  WHERE id = :id"
            )->execute([
                'resolved_by' => $resolvedBy,
                'resolved_reason' => $resolvedReason ?: null,
                'id' => $requestId,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $request['newFlag'] = $newFlag;
        return $request;
    }

    /** @return array<string,mixed> the resolved request row */
    public static function reject(int $requestId, int $resolvedBy, ?string $resolvedReason): array
    {
        $request = self::getRequest($requestId);
        if (!$request) {
            throw new RuntimeException('Request not found.');
        }
        if ($request['status'] !== 'pending') {
            throw new RuntimeException('This request has already been resolved.');
        }
        if ((int) $request['requested_by'] === $resolvedBy) {
            throw new RuntimeException('You cannot reject your own protection request — a different privileged user must action it.');
        }
        Database::connection()->prepare(
            "UPDATE field_protection_requests
                SET status = 'rejected', resolved_by = :resolved_by, resolved_reason = :resolved_reason, resolved_at = NOW()
              WHERE id = :id"
        )->execute([
            'resolved_by' => $resolvedBy,
            'resolved_reason' => $resolvedReason ?: null,
            'id' => $requestId,
        ]);
        return $request;
    }
}
