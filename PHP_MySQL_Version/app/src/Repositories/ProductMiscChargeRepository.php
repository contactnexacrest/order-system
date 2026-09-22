<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * product_misc_charges is open-ended free-text/amount reference data
 * (e.g. "Bank charges", "LC charges") — informational only. Nothing in
 * this repository, or anywhere else, sums these into a FOB calculation;
 * see ProductService::effectiveFobForSupplier(), which never queries
 * this table.
 */
final class ProductMiscChargeRepository
{
    /** @return array<int, array<string,mixed>> */
    public static function forProduct(int $productId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM catalog_product_misc_charges WHERE product_id = :product_id ORDER BY id'
        );
        $stmt->execute(['product_id' => $productId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM catalog_product_misc_charges WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(int $productId, string $label, float $amount, ?string $notes, ?int $userId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO catalog_product_misc_charges (product_id, label, amount, notes, created_by)
             VALUES (:product_id, :label, :amount, :notes, :created_by)'
        );
        $stmt->execute([
            'product_id' => $productId,
            'label'      => $label,
            'amount'     => $amount,
            'notes'      => $notes,
            'created_by' => $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM catalog_product_misc_charges WHERE id = :id')->execute(['id' => $id]);
    }
}
