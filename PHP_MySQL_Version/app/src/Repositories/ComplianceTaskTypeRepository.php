<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDOException;

/**
 * Admin-editable list of compliance/pre-closure task names (docs/schema.sql
 * Section AR) — ECGC Cover, Pre-Shipment Inspection, etc. Gated on
 * manage_compliance_task_types; the per-order checklist itself (which
 * actually uses this list) is gated on the existing close_orders permission
 * — see OrderComplianceTaskRepository.
 */
final class ComplianceTaskTypeRepository
{
    /** @return array<int, array<string,mixed>> alphabetical */
    public static function all(bool $includeInactive = false): array
    {
        $sql = 'SELECT * FROM compliance_task_types';
        if (!$includeInactive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY name';
        return Database::connection()->query($sql)->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM compliance_task_types WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(string $name, int $createdBy): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO compliance_task_types (name, created_by) VALUES (:name, :created_by)');
        $stmt->execute(['name' => $name, 'created_by' => $createdBy]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, string $name): void
    {
        Database::connection()->prepare('UPDATE compliance_task_types SET name = :name WHERE id = :id')
            ->execute(['name' => $name, 'id' => $id]);
    }

    public static function toggleActive(int $id): void
    {
        Database::connection()->prepare('UPDATE compliance_task_types SET is_active = 1 - is_active WHERE id = :id')
            ->execute(['id' => $id]);
    }

    /** @throws PDOException if the type is still referenced by order_compliance_tasks (FK) — caller should catch and flash a friendly message */
    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM compliance_task_types WHERE id = :id')->execute(['id' => $id]);
    }
}
