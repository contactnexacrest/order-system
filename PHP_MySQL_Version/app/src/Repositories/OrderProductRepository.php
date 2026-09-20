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
}
