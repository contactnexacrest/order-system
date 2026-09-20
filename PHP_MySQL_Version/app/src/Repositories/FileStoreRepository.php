<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class FileStoreRepository
{
    public static function insertGenerated(
        ?int $clientId,
        ?int $orderId,
        ?int $stageId,
        string $serverPath,
        string $uuidFilename,
        string $originalFilename,
        int $fileSizeBytes,
        string $mimeType,
        ?int $uploadedBy,
        bool $internalOnly = false
    ): int {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO file_store
                (client_id, order_id, stage_id, file_origin, generation_method, sent_to_client, internal_only,
                 server_path, uuid_filename, original_filename, file_size_bytes, mime_type, uploaded_by)
             VALUES
                (:client_id, :order_id, :stage_id, \'GENERATED_AUTO\', \'twig+dompdf/phpword\', 0, :internal_only,
                 :server_path, :uuid_filename, :original_filename, :file_size_bytes, :mime_type, :uploaded_by)'
        );
        $stmt->execute([
            'client_id'         => $clientId,
            'order_id'          => $orderId,
            'stage_id'          => $stageId,
            'internal_only'     => $internalOnly ? 1 : 0,
            'server_path'       => $serverPath,
            'uuid_filename'     => $uuidFilename,
            'original_filename' => $originalFilename,
            'file_size_bytes'   => $fileSizeBytes,
            'mime_type'         => $mimeType,
            'uploaded_by'       => $uploadedBy,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * A file NexaCrest received from someone else (signed amendment copy,
     * dispute correspondence, ...) rather than generated — see
     * FileUploadService. file_origin='RECEIVED', never internal_only
     * (that flag means "internal-parity DOCX", not relevant here).
     */
    public static function insertReceived(
        ?int $clientId,
        ?int $orderId,
        string $serverPath,
        string $uuidFilename,
        string $originalFilename,
        int $fileSizeBytes,
        string $mimeType,
        ?int $uploadedBy,
        ?string $receivedFrom = null,
        ?string $documentTypeLabel = null
    ): int {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO file_store
                (client_id, order_id, file_origin, received_from, document_type_label,
                 server_path, uuid_filename, original_filename, file_size_bytes, mime_type, uploaded_by)
             VALUES
                (:client_id, :order_id, \'RECEIVED\', :received_from, :document_type_label,
                 :server_path, :uuid_filename, :original_filename, :file_size_bytes, :mime_type, :uploaded_by)'
        );
        $stmt->execute([
            'client_id'           => $clientId,
            'order_id'            => $orderId,
            'received_from'       => $receivedFrom,
            'document_type_label' => $documentTypeLabel,
            'server_path'         => $serverPath,
            'uuid_filename'       => $uuidFilename,
            'original_filename'   => $originalFilename,
            'file_size_bytes'     => $fileSizeBytes,
            'mime_type'           => $mimeType,
            'uploaded_by'         => $uploadedBy,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM file_store WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }
}
