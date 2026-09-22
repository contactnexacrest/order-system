<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class ProductImageRepository
{
    /** @return array<int, array<string,mixed>> */
    public static function forProduct(int $productId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM catalog_product_images WHERE product_id = :product_id ORDER BY id'
        );
        $stmt->execute(['product_id' => $productId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM catalog_product_images WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(int $productId, string $serverPath, ?string $mimeType, ?int $uploadedBy): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO catalog_product_images (product_id, server_path, mime_type, uploaded_by)
             VALUES (:product_id, :server_path, :mime_type, :uploaded_by)'
        );
        $stmt->execute([
            'product_id'  => $productId,
            'server_path' => $serverPath,
            'mime_type'   => $mimeType,
            'uploaded_by' => $uploadedBy,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM catalog_product_images WHERE id = :id')->execute(['id' => $id]);
    }
}
