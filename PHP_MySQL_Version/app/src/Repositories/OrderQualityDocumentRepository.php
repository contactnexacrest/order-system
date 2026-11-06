<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Owner decision D-257 Phase 0B (5 Nov 2026): the quality documents an
 * order must collect, as its lines' product categories ask for them (e.g.
 * "Mill test certificate 3.1", "Drawing approval"). A line with no category
 * is Natural Stone, which asks for none today, so a stone order shows no
 * checklist. Port: orderQualityDocumentRepository.js.
 */
final class OrderQualityDocumentRepository
{
    public const STATUSES = ['pending' => 'Pending', 'received' => 'Received', 'approved' => 'Approved'];

    /**
     * Every quality document the order needs, with its status and file.
     *
     * @return list<array{category_id:int, category_name:string, document_name:string, status:string, file_store_id:?int, original_filename:?string, note:?string, updated_at:?string, updated_by_name:?string}>
     */
    public static function forOrder(int $orderId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT DISTINCT COALESCE(op.category_id, (SELECT pc.id FROM product_categories pc WHERE pc.is_system = 1 ORDER BY pc.id LIMIT 1)) AS cid
               FROM order_products op
              WHERE op.order_id = :o AND op.line_type = 'goods' AND op.is_active = 1"  // fresh-eyes review L3: deleted lines ask for nothing
        );
        $stmt->execute(['o' => $orderId]);
        $categoryIds = array_values(array_filter(array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN))));
        if ($categoryIds === []) {
            return [];
        }
        $stored = [];
        $rows = $pdo->prepare(
            'SELECT q.*, fs.original_filename, u.name AS updated_by_name
               FROM order_quality_documents q
               LEFT JOIN file_store fs ON fs.id = q.file_store_id
               LEFT JOIN users u ON u.id = q.updated_by
              WHERE q.order_id = :o'
        );
        $rows->execute(['o' => $orderId]);
        foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $stored[(int) $r['category_id'] . '|' . mb_strtolower((string) $r['document_name'])] = $r;
        }
        $out = [];
        sort($categoryIds);
        foreach ($categoryIds as $cid) {
            $category = \App\Services\OrderSnapshotService::category($orderId, $cid); // D-262: frozen with the order
            if ($category === null) {
                continue;
            }
            foreach (ProductCategoryRepository::qualityDocuments($category) as $doc) {
                $r = $stored[$cid . '|' . mb_strtolower($doc)] ?? null;
                $out[] = [
                    'category_id' => $cid,
                    'category_name' => (string) $category['name'],
                    'document_name' => $doc,
                    'status' => $r !== null ? (string) $r['status'] : 'pending',
                    'file_store_id' => $r !== null && $r['file_store_id'] !== null ? (int) $r['file_store_id'] : null,
                    'original_filename' => $r['original_filename'] ?? null,
                    'note' => $r['note'] ?? null,
                    'updated_at' => $r['updated_at'] ?? null,
                    'updated_by_name' => $r['updated_by_name'] ?? null,
                ];
            }
        }
        return $out;
    }

    /** The required document, or null when the order does not ask for it. */
    public static function required(int $orderId, int $categoryId, string $documentName): ?array
    {
        foreach (self::forOrder($orderId) as $d) {
            if ($d['category_id'] === $categoryId && mb_strtolower($d['document_name']) === mb_strtolower(trim($documentName))) {
                return $d;
            }
        }
        return null;
    }

    public static function save(int $orderId, int $categoryId, string $documentName, string $status, ?int $fileStoreId, ?string $note, int $userId): void
    {
        Database::connection()->prepare(
            'INSERT INTO order_quality_documents (order_id, category_id, document_name, status, file_store_id, note, updated_by, updated_at)
             VALUES (:o, :c, :d, :s, :f, :n, :u, NOW())
             ON DUPLICATE KEY UPDATE status = VALUES(status), file_store_id = COALESCE(VALUES(file_store_id), file_store_id),
                                     note = VALUES(note), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)'
        )->execute(['o' => $orderId, 'c' => $categoryId, 'd' => $documentName, 's' => $status, 'f' => $fileStoreId, 'n' => $note, 'u' => $userId]);
    }
}
