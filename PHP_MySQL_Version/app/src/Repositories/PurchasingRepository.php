<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Owner decision D-214 (2026-10-31), phase 3: purchase requirements (raised
 * by Sales, no prices) and the supplier quotes Procurement records against
 * them. Port: purchasingRepository.js.
 */
final class PurchasingRepository
{
    public const STATUSES = [
        'open' => 'Waiting for quotes', 'quoted' => 'Quotes in', 'awarded' => 'Awarded — waiting for approval',
        'approved' => 'Approved — with the supplier', 'received' => 'Received', 'cancelled' => 'Cancelled',
    ];

    private const SELECT = "SELECT pr.*, o.order_reference, q.supplier_id AS awarded_supplier_id, s.supplier_legal_name AS awarded_supplier_name,
                                   q.unit_price_inr AS awarded_unit_price_inr, q.gst_percent AS awarded_gst_percent, q.lead_time_days AS awarded_lead_time_days,
                                   (SELECT COUNT(*) FROM purchase_quotes x WHERE x.requirement_id = pr.id) AS quote_count
                              FROM purchase_requirements pr
                              JOIN orders o ON o.id = pr.order_id
                              LEFT JOIN purchase_quotes q ON q.id = pr.awarded_quote_id
                              LEFT JOIN suppliers s ON s.id = q.supplier_id";

    /** @return list<array<string,mixed>> */
    public static function forOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(self::SELECT . ' WHERE pr.order_id = :o ORDER BY pr.id');
        $stmt->execute(['o' => $orderId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> the Procurement queue: everything not received or cancelled, earliest need first */
    public static function queue(?string $status = null): array
    {
        $where = $status !== null ? 'pr.status = :s' : "pr.status NOT IN ('received', 'cancelled')";
        $stmt = Database::connection()->prepare(self::SELECT . " WHERE {$where} ORDER BY pr.needed_by, pr.id");
        $stmt->execute($status !== null ? ['s' => $status] : []);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(self::SELECT . ' WHERE pr.id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(int $orderId, ?int $orderProductId, string $description, ?string $specification, float $quantity, ?string $unit, string $neededBy, int $raisedBy, ?string $note): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO purchase_requirements (order_id, order_product_id, description, specification, quantity, unit, needed_by, raised_by, note)
             VALUES (:o, :p, :d, :s, :q, :u, :n, :r, :note)'
        )->execute(['o' => $orderId, 'p' => $orderProductId, 'd' => $description, 's' => $specification, 'q' => $quantity, 'u' => $unit, 'n' => $neededBy, 'r' => $raisedBy, 'note' => $note]);
        return (int) $pdo->lastInsertId();
    }

    public static function setStatus(int $id, string $status): void
    {
        Database::connection()->prepare('UPDATE purchase_requirements SET status = :s WHERE id = :id')->execute(['s' => $status, 'id' => $id]);
    }

    public static function award(int $id, int $quoteId, int $userId, bool $approvalNeeded): void
    {
        Database::connection()->prepare(
            "UPDATE purchase_requirements SET awarded_quote_id = :q, awarded_by = :u, awarded_at = NOW(), approval_needed = :n,
                    status = :s, approved_by = :ab, approved_at = IF(:n2 = 1, NULL, NOW())
              WHERE id = :id"
        )->execute(['q' => $quoteId, 'u' => $userId, 'n' => (int) $approvalNeeded, 'n2' => (int) $approvalNeeded,
            's' => $approvalNeeded ? 'awarded' : 'approved', 'ab' => $approvalNeeded ? null : $userId, 'id' => $id]);
    }

    public static function approve(int $id, int $userId): void
    {
        Database::connection()->prepare("UPDATE purchase_requirements SET status = 'approved', approved_by = :u, approved_at = NOW() WHERE id = :id")
            ->execute(['u' => $userId, 'id' => $id]);
    }

    public static function receive(int $id, float $quantity, string $receivedOn, string $qcResult, ?string $qcNote, int $userId): void
    {
        Database::connection()->prepare(
            "UPDATE purchase_requirements SET status = 'received', received_quantity = :q, received_on = :d, qc_result = :r, qc_note = :n, received_by = :u WHERE id = :id"
        )->execute(['q' => $quantity, 'd' => $receivedOn, 'r' => $qcResult, 'n' => $qcNote, 'u' => $userId, 'id' => $id]);
    }

    /** @return list<array<string,mixed>> a requirement's quotes, cheapest landed value first */
    public static function quotes(int $requirementId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT q.*, s.supplier_legal_name, ROUND(q.unit_price_inr * (1 + q.gst_percent / 100), 2) AS unit_price_with_gst_inr
               FROM purchase_quotes q JOIN suppliers s ON s.id = q.supplier_id
              WHERE q.requirement_id = :r ORDER BY unit_price_with_gst_inr, q.lead_time_days, q.id'
        );
        $stmt->execute(['r' => $requirementId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function findQuote(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT q.*, s.supplier_legal_name FROM purchase_quotes q JOIN suppliers s ON s.id = q.supplier_id WHERE q.id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function addQuote(int $requirementId, int $supplierId, float $unitPrice, float $gstPercent, int $leadTimeDays, ?string $validUntil, ?string $reference, ?string $note, int $userId): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO purchase_quotes (requirement_id, supplier_id, unit_price_inr, gst_percent, lead_time_days, valid_until, quote_reference, note, recorded_by)
             VALUES (:r, :s, :p, :g, :l, :v, :ref, :n, :u)'
        )->execute(['r' => $requirementId, 's' => $supplierId, 'p' => $unitPrice, 'g' => $gstPercent, 'l' => $leadTimeDays, 'v' => $validUntil, 'ref' => $reference, 'n' => $note, 'u' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * A supplier's record: quotes given, orders won, received on time (by
     * the needed-by date) and quality checks failed.
     * @return list<array<string,mixed>>
     */
    public static function supplierPerformance(): array
    {
        return Database::connection()->query(
            "SELECT s.id, s.supplier_legal_name,
                    COUNT(DISTINCT q.id) AS quotes_given,
                    COUNT(DISTINCT pr.id) AS awards_won,
                    COUNT(DISTINCT CASE WHEN pr.status = 'received' THEN pr.id END) AS received,
                    COUNT(DISTINCT CASE WHEN pr.status = 'received' AND pr.received_on <= pr.needed_by THEN pr.id END) AS on_time,
                    COUNT(DISTINCT CASE WHEN pr.qc_result = 'failed' THEN pr.id END) AS qc_failed
               FROM suppliers s
               JOIN purchase_quotes q ON q.supplier_id = s.id
               LEFT JOIN purchase_requirements pr ON pr.awarded_quote_id = q.id
              GROUP BY s.id, s.supplier_legal_name
              ORDER BY awards_won DESC, s.supplier_legal_name"
        )->fetchAll();
    }
}
