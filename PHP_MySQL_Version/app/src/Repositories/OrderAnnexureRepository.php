<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Annexure A — Product Technical Specifications (schema Section... the
 * order_annexure_products/order_annexure_images tables shipped in the
 * original delivery with no repository, controller, or template ever
 * built against them — this is that missing piece. Referenced from the
 * QT/PI/OC/BUYERPO T&C section via the `annexure-notice` callout box
 * whenever orders.include_annexure_a is set, and generated as its own
 * document type (ANNEXA) through the same DocumentGenerationService
 * pipeline every other document type uses.
 */
final class OrderAnnexureRepository
{
    /** @return array<int, array<string,mixed>> each product row with its images nested under 'images' */
    public static function forOrder(int $orderId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT * FROM order_annexure_products WHERE order_id = :order_id ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['order_id' => $orderId]);
        $products = $stmt->fetchAll();

        if (!$products) {
            return [];
        }

        $ids = array_map(static fn(array $p): int => (int) $p['id'], $products);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $imgStmt = $pdo->prepare(
            "SELECT oai.*, fs.server_path, fs.mime_type, fs.original_filename
             FROM order_annexure_images oai
             JOIN file_store fs ON fs.id = oai.file_id
             WHERE oai.order_annexure_product_id IN ({$placeholders})
             ORDER BY oai.sort_order ASC, oai.id ASC"
        );
        $imgStmt->execute($ids);
        $images = $imgStmt->fetchAll();

        $imagesByProduct = [];
        foreach ($images as $img) {
            $imagesByProduct[(int) $img['order_annexure_product_id']][] = $img;
        }

        foreach ($products as &$p) {
            $p['images'] = $imagesByProduct[(int) $p['id']] ?? [];
        }
        unset($p);

        return $products;
    }

    public static function findProduct(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM order_annexure_products WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function createProduct(int $orderId, array $data): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO order_annexure_products
                (order_id, name, description, dimensions, finish, components, technical_notes, sort_order)
             VALUES
                (:order_id, :name, :description, :dimensions, :finish, :components, :technical_notes,
                 (SELECT next_sort FROM (SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort FROM order_annexure_products WHERE order_id = :order_id_2) t))'
        );
        $stmt->execute([
            'order_id' => $orderId,
            'order_id_2' => $orderId,
            'name' => $data['name'],
            'description' => $data['description'] ?: null,
            'dimensions' => $data['dimensions'] ?: null,
            'finish' => $data['finish'] ?: null,
            'components' => $data['components'] ?: null,
            'technical_notes' => $data['technical_notes'] ?: null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function updateProduct(int $id, array $data): void
    {
        Database::connection()->prepare(
            'UPDATE order_annexure_products
             SET name = :name, description = :description, dimensions = :dimensions,
                 finish = :finish, components = :components, technical_notes = :technical_notes
             WHERE id = :id'
        )->execute([
            'id' => $id,
            'name' => $data['name'],
            'description' => $data['description'] ?: null,
            'dimensions' => $data['dimensions'] ?: null,
            'finish' => $data['finish'] ?: null,
            'components' => $data['components'] ?: null,
            'technical_notes' => $data['technical_notes'] ?: null,
        ]);
    }

    /** Deletes the product and its image rows (not the underlying file_store rows — those are soft-delete-only, per convention). */
    public static function deleteProduct(int $id): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM order_annexure_images WHERE order_annexure_product_id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM order_annexure_products WHERE id = :id')->execute(['id' => $id]);
    }

    public static function addImage(int $productId, int $fileId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO order_annexure_images (order_annexure_product_id, file_id, sort_order)
             VALUES (:product_id, :file_id,
                (SELECT next_sort FROM (SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort FROM order_annexure_images WHERE order_annexure_product_id = :product_id_2) t))'
        );
        $stmt->execute(['product_id' => $productId, 'product_id_2' => $productId, 'file_id' => $fileId]);
        return (int) $pdo->lastInsertId();
    }

    public static function findImage(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM order_annexure_images WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function removeImage(int $id): void
    {
        Database::connection()->prepare('DELETE FROM order_annexure_images WHERE id = :id')->execute(['id' => $id]);
    }
}
