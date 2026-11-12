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
    /** Owner decision D-336: 'A' = Annexure A (as quoted), 'B' = Annexure B (as shipped). */
    public const WHICH = ['A', 'B'];

    /** 'B' for B, anything else 'A'. */
    public static function which(mixed $value): string
    {
        return strtoupper(trim((string) $value)) === 'B' ? 'B' : 'A';
    }

    /**
     * @return array<int, array<string,mixed>> each product row with its images nested under 'images'
     * D-336: one annexure's entries — Annexure A unless $which is 'B'.
     */
    public static function forOrder(int $orderId, string $which = 'A'): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT * FROM order_annexure_products WHERE order_id = :order_id AND `which` = :which ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['order_id' => $orderId, 'which' => self::which($which)]);
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

    /** D-336: number of product entries in one annexure. */
    public static function countFor(int $orderId, string $which = 'A'): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM order_annexure_products WHERE order_id = :o AND `which` = :w');
        $stmt->execute(['o' => $orderId, 'w' => self::which($which)]);
        return (int) $stmt->fetchColumn();
    }

    public static function createProduct(int $orderId, array $data, string $which = 'A'): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO order_annexure_products
                (order_id, `which`, name, description, dimensions, finish, components, technical_notes, sort_order)
             VALUES
                (:order_id, :which, :name, :description, :dimensions, :finish, :components, :technical_notes,
                 (SELECT next_sort FROM (SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort FROM order_annexure_products WHERE order_id = :order_id_2 AND `which` = :which_2) t))'
        );
        $stmt->execute([
            'order_id' => $orderId,
            'order_id_2' => $orderId,
            'which' => self::which($which),
            'which_2' => self::which($which),
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

    /**
     * D-336: "Copy everything from Annexure A" — replaces Annexure B's product
     * entries with a copy of Annexure A's (same fields and order). The images
     * point at the same stored files (file_store rows are never deleted —
     * soft-delete only — so removing an image from one annexure never touches
     * the other's). Returns the number of entries copied.
     */
    public static function replaceWithCopy(int $orderId, string $from = 'A', string $to = 'B'): int
    {
        $from = self::which($from);
        $to = self::which($to);
        $pdo = Database::connection();
        foreach (self::forOrder($orderId, $to) as $old) {
            self::deleteProduct((int) $old['id']);
        }
        $copied = 0;
        foreach (self::forOrder($orderId, $from) as $p) {
            $pdo->prepare(
                'INSERT INTO order_annexure_products
                    (order_id, `which`, product_id, product_code, name, description, dimensions, finish, components, technical_notes, sort_order)
                 VALUES (:o, :w, :pid, :pc, :n, :d, :dim, :f, :c, :t, :s)'
            )->execute([
                'o' => $orderId, 'w' => $to, 'pid' => $p['product_id'] ?? null, 'pc' => $p['product_code'] ?? null,
                'n' => $p['name'], 'd' => $p['description'], 'dim' => $p['dimensions'], 'f' => $p['finish'],
                'c' => $p['components'], 't' => $p['technical_notes'], 's' => (int) $p['sort_order'],
            ]);
            $newId = (int) $pdo->lastInsertId();
            foreach ($p['images'] as $img) {
                $pdo->prepare('INSERT INTO order_annexure_images (order_annexure_product_id, file_id, sort_order) VALUES (:p, :f, :s)')
                    ->execute(['p' => $newId, 'f' => (int) $img['file_id'], 's' => (int) $img['sort_order']]);
            }
            $copied++;
        }
        return $copied;
    }

    /**
     * Owner decision D-338 (1b): copies one annexure's product entries of an order onto
     * another order (the same annexure), images pointing at the same stored files —
     * exactly as replaceWithCopy() does between A and B. The target's entries of that
     * annexure are replaced. Returns the number of entries copied.
     */
    public static function copyToOrder(int $sourceOrderId, int $targetOrderId, string $which = 'A'): int
    {
        $which = self::which($which);
        $pdo = Database::connection();
        foreach (self::forOrder($targetOrderId, $which) as $old) {
            self::deleteProduct((int) $old['id']);
        }
        $copied = 0;
        foreach (self::forOrder($sourceOrderId, $which) as $p) {
            $pdo->prepare(
                'INSERT INTO order_annexure_products
                    (order_id, `which`, product_id, product_code, name, description, dimensions, finish, components, technical_notes, sort_order)
                 VALUES (:o, :w, :pid, :pc, :n, :d, :dim, :f, :c, :t, :s)'
            )->execute([
                'o' => $targetOrderId, 'w' => $which, 'pid' => $p['product_id'] ?? null, 'pc' => $p['product_code'] ?? null,
                'n' => $p['name'], 'd' => $p['description'], 'dim' => $p['dimensions'], 'f' => $p['finish'],
                'c' => $p['components'], 't' => $p['technical_notes'], 's' => (int) $p['sort_order'],
            ]);
            $newId = (int) $pdo->lastInsertId();
            foreach ($p['images'] as $img) {
                $pdo->prepare('INSERT INTO order_annexure_images (order_annexure_product_id, file_id, sort_order) VALUES (:p, :f, :s)')
                    ->execute(['p' => $newId, 'f' => (int) $img['file_id'], 's' => (int) $img['sort_order']]);
            }
            $copied++;
        }
        return $copied;
    }
}
