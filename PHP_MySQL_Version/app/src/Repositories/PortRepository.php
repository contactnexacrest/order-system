<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * 90-item backlog #59: admin CRUD for `ports` (docs/schema.sql, defined
 * near the top alongside incoterms/currencies) — the table already
 * existed and already backs LookupRepository::ports($role), but no admin
 * screen was ever wired up to it, so only the single seeded Chennai
 * (loading) row existed. Never hard-deleted — orders.port_of_loading_id/
 * port_of_discharge_id carry a real FK to this table, so an existing
 * order's port must never disappear from under it; deactivate instead
 * (same convention as dropdown_options/payment_presets).
 */
final class PortRepository
{
    /** @return array<int, array<string,mixed>> */
    public static function all(bool $includeInactive = false): array
    {
        $sql = 'SELECT * FROM ports';
        if (!$includeInactive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order, name';
        return Database::connection()->query($sql)->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM ports WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(string $name, ?string $country, string $portRole, int $sortOrder): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO ports (name, country, port_role, is_default, is_active, sort_order) VALUES (:name, :country, :role, 0, 1, :sort_order)'
        );
        $stmt->execute(['name' => $name, 'country' => $country, 'role' => $portRole, 'sort_order' => $sortOrder]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, string $name, ?string $country, string $portRole, int $sortOrder): void
    {
        Database::connection()->prepare(
            'UPDATE ports SET name = :name, country = :country, port_role = :role, sort_order = :sort_order WHERE id = :id'
        )->execute(['name' => $name, 'country' => $country, 'role' => $portRole, 'sort_order' => $sortOrder, 'id' => $id]);
    }

    public static function toggleActive(int $id): void
    {
        Database::connection()->prepare('UPDATE ports SET is_active = 1 - is_active WHERE id = :id')
            ->execute(['id' => $id]);
    }

    /**
     * Unsets is_default on every other port that would appear alongside
     * this one in LookupRepository::ports() for ANY role this port is
     * eligible for, so the order-creation form's Port of Loading/Discharge
     * dropdowns never end up with two options simultaneously marked
     * `selected` (invalid HTML the browser would just silently pick one
     * of). A 'both'-role port is eligible for both dropdowns, so setting
     * it default clears every other port's flag entirely; a 'loading' or
     * 'discharge' port only needs to clear that one role plus 'both'.
     */
    public static function setDefault(int $id): void
    {
        $port = self::find($id);
        if (!$port) {
            return;
        }
        $pdo = Database::connection();
        if ($port['port_role'] === 'both') {
            $pdo->exec('UPDATE ports SET is_default = 0');
        } else {
            $pdo->prepare("UPDATE ports SET is_default = 0 WHERE port_role = :role OR port_role = 'both'")
                ->execute(['role' => $port['port_role']]);
        }
        $pdo->prepare('UPDATE ports SET is_default = 1 WHERE id = :id')->execute(['id' => $id]);
    }
}
