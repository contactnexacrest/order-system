<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Every uploaded copy of the supplier's signed PO acknowledgment for one
 * specific order_supplier_po row, newest first — see schema.sql SECTION S.
 * Keyed by order_supplier_po_id (not order_id) since an order can have
 * more than one Supplier PO version; the acknowledgment belongs to the PO
 * version it was signed against. attach() never overwrites a prior row,
 * so re-uploading a corrected copy is real version history, not a
 * replacement.
 */
final class OrderSupplierPoDocumentRepository
{
    public static function attach(int $orderSupplierPoId, int $fileId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO order_supplier_po_documents (order_supplier_po_id, file_id) VALUES (:order_supplier_po_id, :file_id)'
        );
        $stmt->execute(['order_supplier_po_id' => $orderSupplierPoId, 'file_id' => $fileId]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<int, array<string,mixed>> */
    public static function forSupplierPo(int $orderSupplierPoId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT d.*, fs.original_filename, fs.server_path, fs.mime_type, fs.uploaded_at
             FROM order_supplier_po_documents d JOIN file_store fs ON fs.id = d.file_id
             WHERE d.order_supplier_po_id = :order_supplier_po_id
             ORDER BY fs.uploaded_at DESC'
        );
        $stmt->execute(['order_supplier_po_id' => $orderSupplierPoId]);
        return $stmt->fetchAll();
    }
}
