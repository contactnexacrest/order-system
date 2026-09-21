<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Every uploaded copy of the buyer's signed PO, newest first — see
 * schema.sql SECTION S. attach() never overwrites a prior row, so
 * re-uploading a corrected copy is real version history, not a
 * replacement.
 */
final class OrderBuyerPoDocumentRepository
{
    public static function attach(int $orderId, int $fileId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO order_buyer_po_documents (order_id, file_id) VALUES (:order_id, :file_id)'
        );
        $stmt->execute(['order_id' => $orderId, 'file_id' => $fileId]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<int, array<string,mixed>> */
    public static function forOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT d.*, fs.original_filename, fs.server_path, fs.mime_type, fs.uploaded_at
             FROM order_buyer_po_documents d JOIN file_store fs ON fs.id = d.file_id
             WHERE d.order_id = :order_id
             ORDER BY fs.uploaded_at DESC'
        );
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll();
    }
}
