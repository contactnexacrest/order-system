<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/** docs/schema.sql Section BX — a Complaint / Process Issue, distinct from a Dispute. */
final class OrderComplaintRepository
{
    public static function create(int $orderId, int $clientId, string $description): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO order_complaints (order_id, client_id, description, status)
             VALUES (:order_id, :client_id, :description, \'open\')'
        );
        $stmt->execute(['order_id' => $orderId, 'client_id' => $clientId, 'description' => $description]);
        return (int) $pdo->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT c.*, o.order_reference FROM order_complaints c JOIN orders o ON o.id = c.order_id WHERE c.id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int, array<string,mixed>> */
    public static function forOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM order_complaints WHERE order_id = :order_id ORDER BY created_at DESC'
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
                'SELECT c.*, o.order_reference, o.company_legal_name FROM order_complaints c
                 JOIN orders o ON o.id = c.order_id
                 WHERE c.status = :status ORDER BY c.created_at DESC'
            );
            $stmt->execute(['status' => $statusFilter]);
        } else {
            $stmt = $pdo->query(
                'SELECT c.*, o.order_reference, o.company_legal_name FROM order_complaints c
                 JOIN orders o ON o.id = c.order_id
                 ORDER BY c.created_at DESC'
            );
        }
        return $stmt->fetchAll();
    }

    public static function updateStatus(int $id, string $status): void
    {
        if ($status === 'resolved') {
            Database::connection()->prepare(
                "UPDATE order_complaints SET status = 'resolved', resolved_at = NOW() WHERE id = :id"
            )->execute(['id' => $id]);
            return;
        }
        Database::connection()->prepare(
            'UPDATE order_complaints SET status = :status, resolved_at = NULL WHERE id = :id'
        )->execute(['status' => $status, 'id' => $id]);
    }
}
