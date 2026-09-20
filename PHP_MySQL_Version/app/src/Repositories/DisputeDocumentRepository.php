<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class DisputeDocumentRepository
{
    public static function attach(int $disputeId, int $fileId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO dispute_documents (dispute_id, file_id) VALUES (:dispute_id, :file_id)'
        );
        $stmt->execute(['dispute_id' => $disputeId, 'file_id' => $fileId]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<int, array<string,mixed>> */
    public static function forDispute(int $disputeId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT dd.*, fs.original_filename, fs.server_path, fs.mime_type, fs.uploaded_at, fs.received_from
             FROM dispute_documents dd JOIN file_store fs ON fs.id = dd.file_id
             WHERE dd.dispute_id = :dispute_id
             ORDER BY fs.uploaded_at'
        );
        $stmt->execute(['dispute_id' => $disputeId]);
        return $stmt->fetchAll();
    }
}
