<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Owner decision D-337 (12 Nov 2026): requests to copy the client's current
 * details onto an order that already has an issued document
 * (order_buyer_detail_requests). One PENDING request per order at a time.
 * Every decision moves the row only from PENDING (move()), so two people
 * deciding at once cannot both win. Node twin: orderBuyerDetailRequestRepository.js.
 */
final class OrderBuyerDetailRequestRepository
{
    private const SELECT = 'SELECT r.*, u.name AS requested_by_name, d.name AS decided_by_name
                              FROM order_buyer_detail_requests r
                              LEFT JOIN users u ON u.id = r.requested_by
                              LEFT JOIN users d ON d.id = r.decided_by';

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(self::SELECT . ' WHERE r.id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function pendingForOrder(int $orderId): ?array
    {
        $stmt = Database::connection()->prepare(self::SELECT . " WHERE r.order_id = :o AND r.status = 'PENDING' ORDER BY r.id LIMIT 1");
        $stmt->execute(['o' => $orderId]);
        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> newest first */
    public static function forOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(self::SELECT . ' WHERE r.order_id = :o ORDER BY r.id DESC');
        $stmt->execute(['o' => $orderId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> the PENDING requests of every order of this client */
    public static function pendingForClient(int $clientId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT r.*, o.order_reference FROM order_buyer_detail_requests r JOIN orders o ON o.id = r.order_id
              WHERE o.client_id = :c AND r.status = 'PENDING' ORDER BY r.id"
        );
        $stmt->execute(['c' => $clientId]);
        return $stmt->fetchAll();
    }

    /**
     * Creates the request only when the order has no PENDING one (one open request per order).
     * @return int the new id, or 0 when a request is already waiting
     */
    public static function create(int $orderId, int $userId, string $reason, string $changesJson, string $hash): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "INSERT INTO order_buyer_detail_requests (order_id, requested_by, reason, changes_json, client_snapshot_hash)
             SELECT :o, :u, :r, :c, :h FROM DUAL
              WHERE NOT EXISTS (SELECT 1 FROM order_buyer_detail_requests x WHERE x.order_id = :o2 AND x.status = 'PENDING')"
        );
        $stmt->execute(['o' => $orderId, 'u' => $userId, 'r' => $reason, 'c' => $changesJson, 'h' => $hash, 'o2' => $orderId]);
        return $stmt->rowCount() === 1 ? (int) $pdo->lastInsertId() : 0;
    }

    /** PENDING → $to. Returns false when the request was no longer PENDING. */
    public static function decide(int $id, string $to, ?int $userId, ?string $note): bool
    {
        $stmt = Database::connection()->prepare(
            "UPDATE order_buyer_detail_requests SET status = :s, decided_by = :u, decided_at = NOW(), decision_note = :n
              WHERE id = :id AND status = 'PENDING'"
        );
        $stmt->execute(['s' => $to, 'u' => $userId, 'n' => $note, 'id' => $id]);
        return $stmt->rowCount() === 1;
    }
}
