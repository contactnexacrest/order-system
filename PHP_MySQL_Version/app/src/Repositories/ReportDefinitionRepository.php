<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Spec Section 16 / ARCHITECTURE.md's own addition — "Saved report
 * definitions ... so recurring reports (overdue payments, stage-wise
 * pipeline, buyer history) are configure-once, run-repeatedly rather than
 * rebuilt from scratch each time." report_type drives which
 * ReportRepository query runs; filters_json/columns_json are opaque to
 * this repository (ReportService interprets them).
 */
final class ReportDefinitionRepository
{
    public static function create(string $name, string $reportType, int $ownerUserId, string $visibility, array $filters, array $columns): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO report_definitions (name, report_type, owner_user_id, visibility, filters_json, columns_json)
             VALUES (:name, :report_type, :owner_user_id, :visibility, :filters_json, :columns_json)'
        );
        $stmt->execute([
            'name' => $name,
            'report_type' => $reportType,
            'owner_user_id' => $ownerUserId,
            'visibility' => $visibility === 'shared' ? 'shared' : 'private',
            'filters_json' => json_encode($filters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'columns_json' => json_encode($columns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM report_definitions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch() ?: null;
        if ($row) {
            $row['filters_json'] = json_decode((string) $row['filters_json'], true) ?? [];
            $row['columns_json'] = json_decode((string) $row['columns_json'], true) ?? [];
        }
        return $row;
    }

    /** Visible to this user: their own (private or shared) plus everyone else's shared ones. */
    public static function visibleTo(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT rd.*, u.name AS owner_name FROM report_definitions rd
             JOIN users u ON u.id = rd.owner_user_id
             WHERE rd.owner_user_id = :user_id OR rd.visibility = 'shared'
             ORDER BY rd.name"
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public static function markRun(int $id): void
    {
        Database::connection()->prepare('UPDATE report_definitions SET last_run_at = NOW() WHERE id = :id')->execute(['id' => $id]);
    }

    public static function updateNameVisibility(int $id, string $name, string $visibility): void
    {
        Database::connection()
            ->prepare('UPDATE report_definitions SET name = :name, visibility = :visibility WHERE id = :id')
            ->execute(['name' => $name, 'visibility' => $visibility === 'shared' ? 'shared' : 'private', 'id' => $id]);
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM report_definitions WHERE id = :id')->execute(['id' => $id]);
    }
}
