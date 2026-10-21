<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDOException;

/**
 * 90-item backlog item 15: port-to-port sailing-days reference document.
 * Never hard-deleted — same convention as PortRepository itself —
 * deactivate instead so a route already quoted from doesn't disappear
 * from under an order's history. One row per (loading, discharge) pair,
 * enforced by a DB unique constraint (see create()/update()'s
 * duplicate-pair handling).
 */
final class PortRouteRepository
{
    /** @return array<int, array<string,mixed>> joined with both ports' names for the admin list screen */
    public static function all(bool $includeInactive = false): array
    {
        $sql = 'SELECT r.*, pl.name AS port_of_loading_name, pd.name AS port_of_discharge_name
                FROM port_routes r
                JOIN ports pl ON pl.id = r.port_of_loading_id
                JOIN ports pd ON pd.id = r.port_of_discharge_id';
        if (!$includeInactive) {
            $sql .= ' WHERE r.is_active = 1';
        }
        $sql .= ' ORDER BY pl.name, pd.name';
        return Database::connection()->query($sql)->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM port_routes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * The lookup the order create/edit form's auto-fill calls — active
     * routes only, since a deactivated route is no longer a reliable
     * sailing-time estimate to offer.
     */
    public static function findByPorts(int $portOfLoadingId, int $portOfDischargeId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM port_routes WHERE port_of_loading_id = :loading AND port_of_discharge_id = :discharge AND is_active = 1'
        );
        $stmt->execute(['loading' => $portOfLoadingId, 'discharge' => $portOfDischargeId]);
        return $stmt->fetch() ?: null;
    }

    /** @return int|null the new route's id, or null if this port pair is already recorded (caller should flash an error) */
    public static function create(int $portOfLoadingId, int $portOfDischargeId, int $sailingDaysMin, int $sailingDaysMax, ?string $notes): ?int
    {
        $pdo = Database::connection();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO port_routes (port_of_loading_id, port_of_discharge_id, sailing_days_min, sailing_days_max, notes, is_active)
                 VALUES (:loading, :discharge, :min, :max, :notes, 1)'
            );
            $stmt->execute(['loading' => $portOfLoadingId, 'discharge' => $portOfDischargeId, 'min' => $sailingDaysMin, 'max' => $sailingDaysMax, 'notes' => $notes]);
            return (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            if ((int) $e->errorInfo[1] === 1062) { // duplicate key on uq_port_routes_pair
                return null;
            }
            throw $e;
        }
    }

    /** @return bool false if this port pair is already recorded under a different route (caller should flash an error) */
    public static function update(int $id, int $portOfLoadingId, int $portOfDischargeId, int $sailingDaysMin, int $sailingDaysMax, ?string $notes): bool
    {
        try {
            Database::connection()->prepare(
                'UPDATE port_routes SET port_of_loading_id = :loading, port_of_discharge_id = :discharge,
                    sailing_days_min = :min, sailing_days_max = :max, notes = :notes WHERE id = :id'
            )->execute(['loading' => $portOfLoadingId, 'discharge' => $portOfDischargeId, 'min' => $sailingDaysMin, 'max' => $sailingDaysMax, 'notes' => $notes, 'id' => $id]);
            return true;
        } catch (PDOException $e) {
            if ((int) $e->errorInfo[1] === 1062) {
                return false;
            }
            throw $e;
        }
    }

    public static function toggleActive(int $id): void
    {
        Database::connection()->prepare('UPDATE port_routes SET is_active = 1 - is_active WHERE id = :id')
            ->execute(['id' => $id]);
    }
}
