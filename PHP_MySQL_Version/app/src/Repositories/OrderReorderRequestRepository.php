<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Client-initiated "repeat order" requests (schema.sql Section AJ). A
 * client submits one from an order they've already placed (even long
 * after it closed); it lands in a staff review queue exactly like the
 * Quotation Intake and PI-stage intake queues — never becomes a live
 * order by itself. On approval, OrderDuplicationService creates the real
 * order from this request's (possibly client-edited) product lines.
 */
final class OrderReorderRequestRepository
{
    public static function create(int $clientId, int $sourceOrderId, ?string $notes): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO order_reorder_requests (client_id, source_order_id, notes) VALUES (:client_id, :source_order_id, :notes)'
        );
        $stmt->execute([
            'client_id'       => $clientId,
            'source_order_id' => $sourceOrderId,
            'notes'           => $notes,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function addProductLine(
        int $requestId,
        int $lineNo,
        string $description,
        ?string $dimensions,
        ?string $finish,
        ?string $quantity,
        bool $quantityIsTbc,
        ?string $unit,
        ?string $unitPrice,
        ?string $hsCode
    ): void {
        Database::connection()->prepare(
            'INSERT INTO order_reorder_request_products
                (reorder_request_id, line_no, description, dimensions, finish, quantity, quantity_is_tbc, unit, unit_price, hs_code)
             VALUES
                (:reorder_request_id, :line_no, :description, :dimensions, :finish, :quantity, :quantity_is_tbc, :unit, :unit_price, :hs_code)'
        )->execute([
            'reorder_request_id' => $requestId,
            'line_no'            => $lineNo,
            'description'        => $description,
            'dimensions'         => $dimensions,
            'finish'             => $finish,
            'quantity'           => $quantity ?: null,
            'quantity_is_tbc'    => $quantityIsTbc ? 1 : 0,
            'unit'               => $unit,
            'unit_price'         => $unitPrice ?: null,
            'hs_code'            => $hsCode ?: null,
        ]);
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT rr.*, c.company_legal_name, o.order_reference AS source_order_reference
             FROM order_reorder_requests rr
             JOIN clients c ON c.id = rr.client_id
             JOIN orders o ON o.id = rr.source_order_id
             WHERE rr.id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int, array<string,mixed>> */
    public static function productLines(int $requestId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM order_reorder_request_products WHERE reorder_request_id = :id ORDER BY line_no'
        );
        $stmt->execute(['id' => $requestId]);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string,mixed>> */
    public static function pendingReview(): array
    {
        return Database::connection()->query(
            "SELECT rr.*, c.company_legal_name, o.order_reference AS source_order_reference
             FROM order_reorder_requests rr
             JOIN clients c ON c.id = rr.client_id
             JOIN orders o ON o.id = rr.source_order_id
             WHERE rr.status = 'pending'
             ORDER BY rr.submitted_at ASC"
        )->fetchAll();
    }

    /** @return array<int, array<string,mixed>> */
    public static function recentResolved(int $limit = 20): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT rr.*, c.company_legal_name, o.order_reference AS source_order_reference,
                    u.name AS reviewed_by_name, no.order_reference AS new_order_reference
             FROM order_reorder_requests rr
             JOIN clients c ON c.id = rr.client_id
             JOIN orders o ON o.id = rr.source_order_id
             LEFT JOIN users u ON u.id = rr.reviewed_by
             LEFT JOIN orders no ON no.id = rr.new_order_id
             WHERE rr.status != 'pending'
             ORDER BY rr.reviewed_at DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string,mixed>> */
    public static function forClient(int $clientId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT rr.*, o.order_reference AS source_order_reference, no.order_reference AS new_order_reference
             FROM order_reorder_requests rr
             JOIN orders o ON o.id = rr.source_order_id
             LEFT JOIN orders no ON no.id = rr.new_order_id
             WHERE rr.client_id = :client_id
             ORDER BY rr.submitted_at DESC'
        );
        $stmt->execute(['client_id' => $clientId]);
        return $stmt->fetchAll();
    }

    public static function markApproved(int $id, int $reviewedBy, int $newOrderId): void
    {
        Database::connection()->prepare(
            "UPDATE order_reorder_requests
             SET status = 'approved', reviewed_by = :reviewed_by, reviewed_at = NOW(), new_order_id = :new_order_id
             WHERE id = :id"
        )->execute(['reviewed_by' => $reviewedBy, 'new_order_id' => $newOrderId, 'id' => $id]);
    }

    public static function markRejected(int $id, int $reviewedBy, string $reason): void
    {
        Database::connection()->prepare(
            "UPDATE order_reorder_requests
             SET status = 'rejected', reviewed_by = :reviewed_by, reviewed_at = NOW(), rejection_reason = :reason
             WHERE id = :id"
        )->execute(['reviewed_by' => $reviewedBy, 'reason' => $reason, 'id' => $id]);
    }
}
