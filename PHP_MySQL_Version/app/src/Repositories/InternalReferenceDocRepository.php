<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * The Internal Reference Library (schema.sql SECTION R) — evergreen,
 * admin-editable content for the five internal document_types rows
 * (CHECKLIST, SOP_A_SALES, SOP_B_SALES, STAGEGATE, WALLREF) that existed
 * only as unused rows before this. One row per document_type_id, not
 * per-order — these apply company-wide, not to any single order.
 */
final class InternalReferenceDocRepository
{
    /** @return array<int, array<string,mixed>> every reference doc type, whether or not content has been entered yet */
    public static function all(): array
    {
        $stmt = Database::connection()->query(
            "SELECT dt.id AS document_type_id, dt.code, dt.name, ird.content, ird.updated_at
             FROM document_types dt
             LEFT JOIN internal_reference_docs ird ON ird.document_type_id = dt.id
             WHERE dt.code IN ('CHECKLIST', 'SOP_A_SALES', 'SOP_B_SALES', 'STAGEGATE', 'WALLREF')
             ORDER BY dt.id"
        );
        return $stmt->fetchAll();
    }

    public static function findByCode(string $code): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT dt.id AS document_type_id, dt.code, dt.name, ird.content, ird.updated_at, ird.updated_by
             FROM document_types dt
             LEFT JOIN internal_reference_docs ird ON ird.document_type_id = dt.id
             WHERE dt.code = :code"
        );
        $stmt->execute(['code' => $code]);
        return $stmt->fetch() ?: null;
    }

    /** Upsert — a code with no row yet (content never entered) becomes one on first save. */
    public static function upsert(int $documentTypeId, string $content, int $updatedBy): void
    {
        Database::connection()->prepare(
            'INSERT INTO internal_reference_docs (document_type_id, content, updated_by)
             VALUES (:document_type_id, :content, :updated_by)
             ON DUPLICATE KEY UPDATE content = VALUES(content), updated_by = VALUES(updated_by)'
        )->execute(['document_type_id' => $documentTypeId, 'content' => $content, 'updated_by' => $updatedBy]);
    }
}
