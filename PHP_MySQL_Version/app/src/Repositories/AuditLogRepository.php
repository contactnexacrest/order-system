<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class AuditLogRepository
{
    public static function log(
        ?int $userId,
        string $actionType,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $fieldName = null,
        ?string $oldValue = null,
        ?string $newValue = null,
        ?string $reason = null
    ): void {
        Database::connection()->prepare(
            'INSERT INTO audit_log
                (user_id, action_type, entity_type, entity_id, field_name, old_value, new_value, reason, ip_address)
             VALUES
                (:user_id, :action_type, :entity_type, :entity_id, :field_name, :old_value, :new_value, :reason, :ip)'
        )->execute([
            'user_id'     => $userId,
            'action_type' => $actionType,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'field_name'  => $fieldName,
            'old_value'   => $oldValue,
            'new_value'   => $newValue,
            'reason'      => $reason,
            'ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    /**
     * Spec Section 14/17 — the audit log viewer. Read-only by design: no
     * method here ever updates or deletes a row (the schema also denies
     * UPDATE/DELETE at the DB-user grant level — see schema.sql comment
     * on this table). Filters are all optional/combinable; results are
     * newest-first with simple offset paging.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function search(
        ?string $entityType = null,
        ?int $entityId = null,
        ?int $userId = null,
        ?string $actionType = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        int $limit = 100,
        int $offset = 0
    ): array {
        $where = [];
        $params = [];
        if ($entityType) {
            $where[] = 'al.entity_type = :entity_type';
            $params['entity_type'] = $entityType;
        }
        if ($entityId) {
            $where[] = 'al.entity_id = :entity_id';
            $params['entity_id'] = $entityId;
        }
        if ($userId) {
            $where[] = 'al.user_id = :user_id';
            $params['user_id'] = $userId;
        }
        if ($actionType) {
            $where[] = 'al.action_type = :action_type';
            $params['action_type'] = $actionType;
        }
        if ($dateFrom) {
            $where[] = 'DATE(al.created_at) >= :date_from';
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo) {
            $where[] = 'DATE(al.created_at) <= :date_to';
            $params['date_to'] = $dateTo;
        }

        $sql = 'SELECT al.*, u.name AS user_name
                FROM audit_log al LEFT JOIN users u ON u.id = al.user_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY al.created_at DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return string[] distinct action_type values, for the filter dropdown */
    public static function distinctActionTypes(): array
    {
        $stmt = Database::connection()->query('SELECT DISTINCT action_type FROM audit_log ORDER BY action_type');
        return array_column($stmt->fetchAll(), 'action_type');
    }
}
