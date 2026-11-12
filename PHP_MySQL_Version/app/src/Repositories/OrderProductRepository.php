<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class OrderProductRepository
{
    /** @return array<int, array<string,mixed>> */
    public static function forOrder(int $orderId): array
    {
        // D-245 P5: read once per report run (BatchCache scope only).
        return \App\Helpers\BatchCache::memo('products:' . $orderId, static fn () => self::forOrderUncached($orderId));
    }

        private static function forOrderUncached(int $orderId): array
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
            ? number_format(round(((float) $quantity) * ((float) $unitPrice), 2), 2, '.', '') // QA batch 4, MON-3: rounded once, half away from zero, the same on both stacks
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
            'hs_code'         => $hsCode ?: '680293',
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
     *
     * Owner decision D-271/D-272 (points 13 and 24, 11 Nov 2026): an exact copy —
     * every option, the piece size, material, product, price-request link and
     * Zoho item — placed right below the original on the same order and marked
     * "copy of line N". The MD's price approval, the figures fixed at PI and an
     * open approval request are NOT copied: the caller runs
     * OrderLinePricing::afterSave() so the copy is checked at once.
     */
    public static function duplicate(int $id, ?int $targetOrderId = null): int
    {
        $source = self::find($id);
        if ($source === null) {
            throw new \RuntimeException("Cannot duplicate order_products row {$id} — not found.");
        }
        $sameOrder = $targetOrderId === null || $targetOrderId === (int) $source['order_id'];
        $orderId = $targetOrderId ?? (int) $source['order_id'];
        $lineNo = self::nextLineNo($orderId);
        if ($sameOrder) {
            // Right below the original: the lines after it move down by one.
            $lineNo = (int) $source['line_no'] + 1;
            Database::connection()->prepare('UPDATE order_products SET line_no = line_no + 1 WHERE order_id = :o AND line_no >= :n ORDER BY line_no DESC')
                ->execute(['o' => $orderId, 'n' => $lineNo]);
        }
        $newId = self::add(
            $orderId,
            $lineNo,
            (string) $source['description'],
            $source['dimensions'],
            $source['finish'],
            $source['quantity'] !== null ? (string) $source['quantity'] : null,
            (bool) $source['quantity_is_tbc'],
            $source['unit'],
            $source['unit_price'] !== null ? (string) $source['unit_price'] : null,
            (string) $source['hs_code']
        );
        self::setLineType($newId, (string) ($source['line_type'] ?? 'goods'), $source['sac_code'] ?? null);
        // D-215 (5a): the copy keeps the line's product category and attribute values.
        Database::connection()->prepare('UPDATE order_products SET category_id = :c, attribute_values = :v, catalog_product_id = :cp, price_set_by = :pb WHERE id = :id')
            ->execute(['c' => $source['category_id'] ?? null, 'v' => $source['attribute_values'] ?? null, 'cp' => $source['catalog_product_id'] ?? null, 'pb' => $source['price_set_by'] ?? null, 'id' => $newId]);
        // D-272: and everything else that describes the line.
        Database::connection()->prepare(
            'UPDATE order_products SET product_id = :p, material = :m, spec_selection = :s, price_request_id = :pr, zoho_item_id = :z,
                    piece_length_cm = :l, piece_width_cm = :w, piece_thickness_cm = :t, copied_from_id = :cf, as_per_annexure = :apa,
                    pieces_count = :pc, size_note = :sn WHERE id = :id'
        )->execute([
            'p' => $source['product_id'] ?? null, 'm' => $source['material'] ?? null, 's' => $source['spec_selection'] ?? null,
            'pr' => $source['price_request_id'] ?? null, 'z' => $source['zoho_item_id'] ?? null,
            'l' => $source['piece_length_cm'] ?? null, 'w' => $source['piece_width_cm'] ?? null, 't' => $source['piece_thickness_cm'] ?? null,
            'cf' => $sameOrder ? $id : null, 'apa' => (int) ($source['as_per_annexure'] ?? 0),
            // D-339: the No. of pieces and the size note.
            'pc' => $source['pieces_count'] ?? null, 'sn' => $source['size_note'] ?? null, 'id' => $newId,
        ]);
        return $newId;
    }

    /**
     * Owner decision D-270 (point 25): the piece size Length × Width × Thickness (cm) a line's
     * price is converted with. Used for the price check only — the Dimensions text prints.
     *
     * @param array{piece_length_cm?: ?string, piece_width_cm?: ?string, piece_thickness_cm?: ?string} $size
     */
    /** Owner decision D-336: the line's size is given in the annexure ("As per annexure"). */
    public static function setAsPerAnnexure(int $id, bool $perAnnexure): void
    {
        Database::connection()->prepare('UPDATE order_products SET as_per_annexure = :a WHERE id = :id')
            ->execute(['a' => $perAnnexure ? 1 : 0, 'id' => $id]);
    }

    /** Owner decision D-339: a line's No. of pieces and its size note (printed under the line). */
    public static function setPieces(int $id, ?int $pieces, ?string $sizeNote): void
    {
        Database::connection()->prepare('UPDATE order_products SET pieces_count = :p, size_note = :n WHERE id = :id')
            ->execute(['p' => $pieces, 'n' => $sizeNote, 'id' => $id]);
    }

    public static function setPieceSize(int $id, array $size): void
    {
        Database::connection()->prepare('UPDATE order_products SET piece_length_cm = :l, piece_width_cm = :w, piece_thickness_cm = :t WHERE id = :id')
            ->execute(['l' => $size['piece_length_cm'] ?? null, 'w' => $size['piece_width_cm'] ?? null, 't' => $size['piece_thickness_cm'] ?? null, 'id' => $id]);
    }

    /** D-272: the line number of the line a copy was made from (null when it is gone or not a copy). */
    public static function copiedFromLineNo(array $line, array $lines): ?int
    {
        if (empty($line['copied_from_id'])) {
            return null;
        }
        foreach ($lines as $l) {
            if ((int) $l['id'] === (int) $line['copied_from_id']) {
                return (int) $l['line_no'];
            }
        }
        return null;
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
        string $hsCode = '680293'
    ): int {
        $fobValue = ($quantity !== null && $unitPrice !== null && !$quantityIsTbc)
            ? number_format(round(((float) $quantity) * ((float) $unitPrice), 2), 2, '.', '') // QA batch 4, MON-3: rounded once, half away from zero, the same on both stacks
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
            'hs_code'         => $hsCode ?: '680293',
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Owner decision 2 Nov 2026 (D-240): the Products-menu product a line was picked
     * from (null = typed by hand), and who set its unit price (for the self-approval
     * rule on a discount below the reference price).
     */
    public static function setCatalogProduct(int $id, ?int $catalogProductId): void
    {
        Database::connection()->prepare('UPDATE order_products SET catalog_product_id = :c WHERE id = :id')
            ->execute(['c' => $catalogProductId, 'id' => $id]);
    }

    public static function setPriceSetBy(int $id, int $userId): void
    {
        Database::connection()->prepare('UPDATE order_products SET price_set_by = :u WHERE id = :id')
            ->execute(['u' => $userId, 'id' => $id]);
    }

    /** Owner decision D-215: a line is goods, a service (SAC code, its own Export Service Invoice) or a charge. */
    public const LINE_TYPES = ['goods' => 'Goods', 'service' => 'Service', 'charge' => 'Charge'];

    public static function setLineType(int $id, string $lineType, ?string $sacCode): void
    {
        Database::connection()->prepare('UPDATE order_products SET line_type = :t, sac_code = :s WHERE id = :id')
            ->execute(['t' => $lineType, 's' => $lineType === 'service' ? $sacCode : null, 'id' => $id]);
    }

    /** The service lines' total — invoiced on the Export Service Invoice, never part of the goods FOB value. */
    public static function totalServiceValue(int $orderId): float
    {
        $stmt = Database::connection()->prepare(
            "SELECT COALESCE(SUM(fob_value), 0) AS total FROM order_products WHERE order_id = :order_id AND is_active = 1 AND line_type = 'service'"
        );
        $stmt->execute(['order_id' => $orderId]);
        return (float) $stmt->fetch()['total'];
    }

    /** The goods FOB value (goods and charges; D-215: service lines are invoiced separately). */
    public static function totalFobValue(int $orderId): float
    {
        $stmt = Database::connection()->prepare(
            "SELECT COALESCE(SUM(fob_value), 0) AS total FROM order_products WHERE order_id = :order_id AND is_active = 1 AND line_type <> 'service'"
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
