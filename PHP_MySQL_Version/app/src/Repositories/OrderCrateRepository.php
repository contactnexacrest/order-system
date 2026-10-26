<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class OrderCrateRepository
{
    /** @return array<int, array<string,mixed>> */
    public static function forOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM order_crates WHERE order_id = :order_id ORDER BY crate_no'
        );
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll();
    }

    /**
     * Crates are entered as a batch from the factory packing sheet, so a
     * re-submission replaces the whole set for this order rather than
     * trying to diff/match rows — there's no natural "this is the same
     * crate as before" identity across two packing-sheet entries.
     *
     * @param array<int, array{crate_no:string, marks_numbers:?string, product_description:?string,
     *   dimensions_lwh_cm:?string, pcs:?string, net_weight_kg:?string, gross_weight_kg:?string,
     *   cbm:?string, hs_code:?string}> $crates
     */
    public static function replaceForOrder(int $orderId, array $crates): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM order_crates WHERE order_id = :order_id')->execute(['order_id' => $orderId]);

        $stmt = $pdo->prepare(
            'INSERT INTO order_crates
                (order_id, crate_no, marks_numbers, product_description, dimensions_lwh_cm, pcs, net_weight_kg, gross_weight_kg, cbm, hs_code)
             VALUES
                (:order_id, :crate_no, :marks_numbers, :product_description, :dimensions, :pcs, :net, :gross, :cbm, :hs_code)'
        );
        foreach ($crates as $c) {
            $stmt->execute([
                'order_id'            => $orderId,
                'crate_no'            => $c['crate_no'],
                'marks_numbers'       => $c['marks_numbers'] ?? null,
                'product_description' => $c['product_description'] ?? null,
                'dimensions'          => $c['dimensions_lwh_cm'] ?? null,
                'pcs'                 => $c['pcs'] ?: null,
                'net'                 => $c['net_weight_kg'] ?: null,
                'gross'               => $c['gross_weight_kg'] ?: null,
                'cbm'                 => $c['cbm'] ?: null,
                'hs_code'             => $c['hs_code'] ?: null,
            ]);
        }
    }

    /**
     * Owner request (2026-10-26, t4): a crate's HS code is never typed — it
     * is the HS code of the order product the crate holds. A crate whose
     * product is not one of the order's lines (older free-text entries)
     * still gets the order's HS code when every line shares one.
     *
     * @param array<int, array<string,mixed>> $products the order's active product lines
     */
    public static function hsCodeFor(array $products, ?string $productDescription): ?string
    {
        foreach ($products as $p) {
            if ((string) $p['description'] === (string) $productDescription) {
                return (string) $p['hs_code'];
            }
        }
        $codes = array_values(array_unique(array_map(static fn(array $p): string => (string) $p['hs_code'], $products)));
        return count($codes) === 1 ? $codes[0] : null;
    }
}
