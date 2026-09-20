<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class DisputeRepository
{
    public static function create(
        int $orderId,
        string $noticeDate,
        ?string $fromParty,
        string $description,
        ?int $assignedTo,
        ?string $responseDueDate
    ): int {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO disputes (order_id, notice_date, from_party, description, assigned_to, response_due_date, status)
             VALUES (:order_id, :notice_date, :from_party, :description, :assigned_to, :response_due_date, \'Open\')'
        );
        $stmt->execute([
            'order_id'          => $orderId,
            'notice_date'       => $noticeDate,
            'from_party'        => $fromParty,
            'description'       => $description,
            'assigned_to'       => $assignedTo,
            'response_due_date' => $responseDueDate,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT d.*, o.order_reference FROM disputes d JOIN orders o ON o.id = d.order_id WHERE d.id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int, array<string,mixed>> */
    public static function forOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM disputes WHERE order_id = :order_id ORDER BY created_at DESC'
        );
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string,mixed>> */
    public static function all(?string $statusFilter = null): array
    {
        $pdo = Database::connection();
        if ($statusFilter) {
            $stmt = $pdo->prepare(
                'SELECT d.*, o.order_reference FROM disputes d JOIN orders o ON o.id = d.order_id
                 WHERE d.status = :status ORDER BY d.created_at DESC'
            );
            $stmt->execute(['status' => $statusFilter]);
        } else {
            $stmt = $pdo->query(
                'SELECT d.*, o.order_reference FROM disputes d JOIN orders o ON o.id = d.order_id
                 ORDER BY d.created_at DESC'
            );
        }
        return $stmt->fetchAll();
    }

    public static function updateStatus(int $id, string $status): void
    {
        Database::connection()->prepare(
            'UPDATE disputes SET status = :status WHERE id = :id'
        )->execute(['status' => $status, 'id' => $id]);
    }

    public static function resolve(int $id, string $resolutionNotes): void
    {
        Database::connection()->prepare(
            "UPDATE disputes SET status = 'Resolved', resolved_at = NOW(), resolution_notes = :notes WHERE id = :id"
        )->execute(['notes' => $resolutionNotes, 'id' => $id]);
    }
}
