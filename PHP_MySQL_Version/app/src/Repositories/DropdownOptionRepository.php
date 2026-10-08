<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Admin CRUD for dropdown_options (docs/schema.sql Section BD) — the
 * generic small-option-list table that already existed for coo_type,
 * container_type, supplier_type, dispute_status, upload_doc_type_*,
 * received_from, freight_terms, but had no admin screen wired up to it
 * until now. Options are never hard-deleted (same convention as
 * tc_clauses/payment_presets) — a no-longer-wanted option is deactivated
 * instead, since an existing order/submission may still carry its exact
 * text in a plain VARCHAR column with no FK to this table.
 */
final class DropdownOptionRepository
{
    /** @return array<string, array<int, array<string,mixed>>> every list_key present, each option list ordered by sort_order */
    public static function allGrouped(bool $includeInactive = false): array
    {
        $sql = 'SELECT * FROM dropdown_options';
        if (!$includeInactive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY list_key, sort_order, id';
        $rows = Database::connection()->query($sql)->fetchAll();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['list_key']][] = $row;
        }
        return $grouped;
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM dropdown_options WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(string $listKey, string $value, int $sortOrder): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active) VALUES (:list_key, :value, :sort_order, 0, 1)'
        );
        $stmt->execute(['list_key' => $listKey, 'value' => $value, 'sort_order' => $sortOrder]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, string $value, int $sortOrder): void
    {
        Database::connection()->prepare(
            'UPDATE dropdown_options SET option_value = :value, sort_order = :sort_order WHERE id = :id'
        )->execute(['value' => $value, 'sort_order' => $sortOrder, 'id' => $id]);
    }

    /** Unsets is_default on every other row sharing this option's list_key, then sets it on this one. */
    public static function setDefault(int $id, string $listKey): void
    {
        $pdo = Database::connection();
        $pdo->prepare('UPDATE dropdown_options SET is_default = 0 WHERE list_key = :list_key')
            ->execute(['list_key' => $listKey]);
        $pdo->prepare('UPDATE dropdown_options SET is_default = 1 WHERE id = :id')
            ->execute(['id' => $id]);
    }

    public static function toggleActive(int $id): void
    {
        Database::connection()->prepare('UPDATE dropdown_options SET is_active = 1 - is_active WHERE id = :id')
            ->execute(['id' => $id]);
    }
}
