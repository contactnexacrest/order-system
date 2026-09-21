<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class DocumentRepository
{
    /**
     * The client portal's own view — every customer_facing document ever
     * approved/sent for this order, most recent revision first. Deliberately
     * excludes draft/in_review status (a client never sees a document
     * before it's approved) and internal/procurement categories (BLI,
     * COOPREP, SUPPO — never buyer-facing by design, per document_types).
     * A client sees this retroactively from the Quotation onward, per the
     * confirmed scope — there is no date filter here at all.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function customerFacingForOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT d.*, dt.code AS document_type_code, dt.name AS document_type_name
             FROM documents d
             JOIN document_types dt ON dt.id = d.document_type_id
             WHERE d.order_id = :order_id
               AND dt.category = 'customer_facing'
               AND d.status IN ('approved', 'sent')
             ORDER BY d.generated_at DESC"
        );
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string,mixed>> */
    public static function forOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT d.*, dt.code AS document_type_code, dt.name AS document_type_name, dt.min_reviewers_default
             FROM documents d
             JOIN document_types dt ON dt.id = d.document_type_id
             WHERE d.order_id = :order_id
             ORDER BY d.generated_at DESC'
        );
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT d.*, dt.code AS document_type_code, dt.name AS document_type_name, dt.min_reviewers_default, dt.category AS document_type_category
             FROM documents d JOIN document_types dt ON dt.id = d.document_type_id
             WHERE d.id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function findLatestForOrderAndType(int $orderId, int $documentTypeId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM documents
             WHERE order_id = :order_id AND document_type_id = :document_type_id
             ORDER BY revision_number DESC LIMIT 1'
        );
        $stmt->execute(['order_id' => $orderId, 'document_type_id' => $documentTypeId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Looks up the latest generated document of a given type (by code, e.g.
     * 'QT' or 'PI') for an order, purely to expose its document_reference
     * for cross-referencing on a later-stage document (PI shows the QT ref,
     * OC shows the PI ref). Returns null if that document hasn't been
     * generated yet — callers render 'TBC'/'—' rather than fail.
     */
    public static function findLatestForOrderAndTypeCode(int $orderId, string $documentTypeCode): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT d.* FROM documents d
             JOIN document_types dt ON dt.id = d.document_type_id
             WHERE d.order_id = :order_id AND dt.code = :code
             ORDER BY d.revision_number DESC LIMIT 1'
        );
        $stmt->execute(['order_id' => $orderId, 'code' => $documentTypeCode]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Spec Section 9 — every required reviewer has approved: swap in the
     * final PDF (re-rendered with the final watermark by
     * DocumentGenerationService::finalizeApproval()) and flip status.
     * The draft pdf_file_id's file_store row is left alone (soft-delete
     * only, never removed) — this just repoints which file counts as
     * "the" PDF for downloads/sends going forward.
     */
    public static function markApproved(int $id, int $finalPdfFileId): void
    {
        Database::connection()->prepare(
            "UPDATE documents SET status = 'approved', pdf_file_id = :pdf_file_id WHERE id = :id"
        )->execute(['pdf_file_id' => $finalPdfFileId, 'id' => $id]);
    }

    public static function markInReview(int $id): void
    {
        Database::connection()->prepare(
            "UPDATE documents SET status = 'in_review' WHERE id = :id"
        )->execute(['id' => $id]);
    }

    /** A reviewer rejected — creator needs to fix and regenerate. */
    public static function markDraft(int $id): void
    {
        Database::connection()->prepare(
            "UPDATE documents SET status = 'draft' WHERE id = :id"
        )->execute(['id' => $id]);
    }

    public static function markSent(int $id): void
    {
        Database::connection()->prepare(
            "UPDATE documents SET status = 'sent' WHERE id = :id"
        )->execute(['id' => $id]);
    }

    /**
     * @param array<string,mixed>|null $signatory as returned by
     *        DocumentDataAssembler::signatoryBlock() — snapshotted onto the
     *        row so a later change to any signatory default never alters
     *        how a document that was already generated reads.
     * @param array<string,mixed>|null $companySnapshot as returned by
     *        DocumentDataAssembler::companyBlock() — snapshotted onto the
     *        row for the same reason: a bank account switch or LUT renewal
     *        must never alter how a document that was already generated
     *        reads. See schema.sql SECTION P.
     */
    public static function create(
        ?int $orderId,
        int $documentTypeId,
        ?string $documentReference,
        int $revisionNumber,
        ?int $pdfFileId,
        ?int $docxFileId,
        ?int $generatedBy,
        ?array $signatory = null,
        ?array $companySnapshot = null
    ): int {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO documents
                (order_id, document_type_id, document_reference, revision_number, status, generated_by, docx_file_id, pdf_file_id,
                 signatory_user_id, signatory_name_snapshot, signatory_designation_snapshot,
                 signature_asset_id_snapshot, seal_asset_id_snapshot, used_designation_seal, company_snapshot_json)
             VALUES
                (:order_id, :document_type_id, :document_reference, :revision_number, \'draft\', :generated_by, :docx_file_id, :pdf_file_id,
                 :signatory_user_id, :signatory_name_snapshot, :signatory_designation_snapshot,
                 :signature_asset_id_snapshot, :seal_asset_id_snapshot, :used_designation_seal, :company_snapshot_json)'
        );
        $stmt->execute([
            'order_id'           => $orderId,
            'document_type_id'   => $documentTypeId,
            'document_reference' => $documentReference,
            'revision_number'    => $revisionNumber,
            'generated_by'       => $generatedBy,
            'docx_file_id'       => $docxFileId,
            'pdf_file_id'        => $pdfFileId,
            'signatory_user_id'  => $signatory['user_id'] ?? null,
            'signatory_name_snapshot' => $signatory['name'] ?? null,
            'signatory_designation_snapshot' => $signatory['designation'] ?? null,
            'signature_asset_id_snapshot' => $signatory['signature_asset_id'] ?? null,
            'seal_asset_id_snapshot' => $signatory['seal_asset_id'] ?? null,
            'used_designation_seal' => !empty($signatory['used_designation_seal']) ? 1 : 0,
            'company_snapshot_json' => $companySnapshot !== null ? json_encode($companySnapshot) : null,
        ]);
        return (int) $pdo->lastInsertId();
    }
}
