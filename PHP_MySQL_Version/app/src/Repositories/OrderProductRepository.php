<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class OrderProductRepository
{
    /** @return array<int, array<string,mixed>> */
    public static function forOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM order_products WHERE order_id = :order_id AND is_active = 1 ORDER BY line_no'
        );
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM order_products WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function nextLineNo(int $orderId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COALESCE(MAX(line_no), 0) + 1 AS next_line FROM order_products WHERE order_id = :order_id'
        );
        $stmt->execute(['order_id' => $orderId]);
        return (int) $stmt->fetch()['next_line'];
    }

    /**
     * Order-Edit feature (added 2026-09-26) — order_products previously
     * had add() (called once, at order creation) and nothing else: no
     * update/delete/duplicate path existed for a line already saved.
     */
    public static function update(
        int $id,
        string $description,
        ?string $dimensions,
        ?string $finish,
        ?string $quantity,
        bool $quantityIsTbc,
        ?string $unit,
        ?string $unitPrice,
        string $hsCode
    ): void {
        $fobValue = ($quantity !== null && $unitPrice !== null && !$quantityIsTbc)
            ? (string) (((float) $quantity) * ((float) $unitPrice))
            : null;

        Database::connection()->prepare(
            'UPDATE order_products SET
                description = :description, finish = :finish, dimensions = :dimensions,
                quantity = :quantity, quantity_is_tbc = :quantity_is_tbc, unit = :unit,
                unit_price = :unit_price, fob_value = :fob_value, hs_code = :hs_code
             WHERE id = :id'
        )->execute([
            'description'     => $description,
            'finish'          => $finish,
            'dimensions'      => $dimensions,
            'quantity'        => $quantity ?: null,
            'quantity_is_tbc' => $quantityIsTbc ? 1 : 0,
            'unit'            => $unit,
            'unit_price'      => $unitPrice ?: null,
            'fob_value'       => $fobValue,
            'hs_code'         => $hsCode ?: '6802.93',
            'id'              => $id,
        ]);
    }

    /** Soft delete — is_active=0, same convention as everywhere else in this system; nothing is ever hard-deleted. */
    public static function softDelete(int $id): void
    {
        Database::connection()->prepare('UPDATE order_products SET is_active = 0 WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * Clones a line's fields into a new row — either a new line on the
     * same order (the common case: "same product, just the name or
     * dimension changed") or, with $targetOrderId, onto a different order
     * entirely (reused by whole-order duplication).
     */
    public static function duplicate(int $id, ?int $targetOrderId = null): int
    {
        $source = self::find($id);
        if ($source === null) {
            throw new \RuntimeException("Cannot duplicate order_products row {$id} — not found.");
        }
        $orderId = $targetOrderId ?? (int) $source['order_id'];
        return self::add(
            $orderId,
            self::nextLineNo($orderId),
            (string) $source['description'],
            $source['dimensions'],
            $source['finish'],
            $source['quantity'] !== null ? (string) $source['quantity'] : null,
            (bool) $source['quantity_is_tbc'],
            $source['unit'],
            $source['unit_price'] !== null ? (string) $source['unit_price'] : null,
            (string) $source['hs_code']
        );
    }

    public static function add(
        int $orderId,
        int $lineNo,
        string $description,
        ?string $dimensions,
        ?string $finish,
        ?string $quantity,
        bool $quantityIsTbc,
        ?string $unit,
        ?string $unitPrice,
        string $hsCode = '6802.93'
    ): int {
        $fobValue = ($quantity !== null && $unitPrice !== null && !$quantityIsTbc)
            ? (string) (((float) $quantity) * ((float) $unitPrice))
            : null;

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO order_products
                (order_id, line_no, description, finish, dimensions, quantity, quantity_is_tbc, unit, unit_price, fob_value, hs_code)
             VALUES
                (:order_id, :line_no, :description, :finish, :dimensions, :quantity, :quantity_is_tbc, :unit, :unit_price, :fob_value, :hs_code)'
        );
        $stmt->execute([
            'order_id'        => $orderId,
            'line_no'         => $lineNo,
            'description'     => $description,
            'finish'          => $finish,
            'dimensions'      => $dimensions,
            'quantity'        => $quantity ?: null,
            'quantity_is_tbc' => $quantityIsTbc ? 1 : 0,
            'unit'            => $unit,
            'unit_price'      => $unitPrice ?: null,
            'fob_value'       => $fobValue,
            'hs_code'         => $hsCode ?: '6802.93',
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function totalFobValue(int $orderId): float
    {
        $stmt = Database::connection()->prepare(
            'SELECT COALESCE(SUM(fob_value), 0) AS total FROM order_products WHERE order_id = :order_id AND is_active = 1'
        );
        $stmt->execute(['order_id' => $orderId]);
        return (float) $stmt->fetch()['total'];
    }

    /**
     * Ordered-quantity total for the shortfall-tolerance check
     * (OrderController::savePacking). Only meaningful when every active
     * line shares one unit and none is quantity_is_tbc — mixing SQM and
     * PCS (or comparing against a quantity nobody has confirmed yet) has
     * no sane single percentage, so the caller falls back to manual entry
     * in that case rather than the system silently comparing apples to
     * oranges.
     * @return array{total: ?float, unit: ?string, comparable: bool}
     */
    public static function orderedQuantitySummary(int $orderId): array
    {
        $rows = self::forOrder($orderId);
        if (empty($rows)) {
            return ['total' => null, 'unit' => null, 'comparable' => false];
        }

        $units = [];
        $total = 0.0;
        foreach ($rows as $r) {
            if (!empty($r['quantity_is_tbc']) || $r['quantity'] === null) {
                return ['total' => null, 'unit' => null, 'comparable' => false];
            }
            $units[$r['unit'] ?? ''] = true;
            $total += (float) $r['quantity'];
        }

        if (count($units) !== 1) {
            return ['total' => null, 'unit' => null, 'comparable' => false];
        }

        return ['total' => $total, 'unit' => array_key_first($units), 'comparable' => true];
    }
}
