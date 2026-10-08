<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Config\Env;
use App\Repositories\AmendmentRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\CaExpenseRepository;
use App\Repositories\CaExportBenefitRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\FileStoreRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderStageRepository;
use App\Repositories\TermsClauseRepository;
use Dompdf\Dompdf;
use Dompdf\Options as DompdfOptions;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader as TwigFilesystemLoader;

/**
 * Twig -> DOMPDF (buyer-facing PDF, always) and, when
 * docx_generation_settings.is_enabled for that document type, also ->
 * PHPWord (internal-only DOCX, content-parity not pixel-parity — the PDF
 * is what carries visual fidelity; see README's Phase B scope note).
 */
final class DocumentGenerationService
{
    public static function generate(int $orderId, string $documentTypeCode, int $generatedByUserId, ?int $signatoryOverrideUserId = null, bool $generateDocx = false, bool $allowOverride = false): array
    {
        $pdo = Database::connection();

        $docType = self::findDocumentType($documentTypeCode);
        if (!$docType) {
            throw new \RuntimeException("Unknown document type: {$documentTypeCode}");
        }

        $order = OrderRepository::find($orderId);
        if (!$order) {
            throw new \RuntimeException("Order {$orderId} not found");
        }

        // Real, dangerous gap this closes (found 2026-09-25): the order-
        // detail view only ever hides a "Generate"/"Regenerate" button once
        // the order is locked/complete or the relevant stage hasn't
        // unlocked yet — generate() itself never checked either condition,
        // so posting the route directly (or a bug in the view's own
        // condition) could still generate ANY document type at ANY time,
        // including regenerating an early-stage document (QT, PI, ...)
        // after the order has already closed. $allowOverride is the one
        // deliberately narrow escape hatch — callers must have already
        // verified the caller holds edit_locked_data (Super Admin bypasses
        // this check automatically — see PermissionService::can()) and
        // captured a mandatory reason before setting it true; see
        // DocumentController::generate().
        if (!$allowOverride) {
            if ((bool) ($order['is_locked'] ?? false)) {
                throw new StageGateBlockedException(
                    "Order #{$orderId} is locked (status: {$order['status']}) — documents can no longer be generated or regenerated for it. " .
                    'A user with the "Override locked data" permission can force this through with a reason if absolutely necessary.'
                );
            }

            $requiredStage = self::stageSequenceFor($documentTypeCode);
            if ($requiredStage !== null) {
                $stage = OrderStageRepository::findByOrderAndStageNumber($orderId, $requiredStage);
                if (!$stage || $stage['status'] === 'locked') {
                    throw new StageGateBlockedException(
                        "Stage {$requiredStage} isn't open yet for order #{$orderId} — {$documentTypeCode} can't be generated out of sequence. " .
                        'A user with the "Override locked data" permission can force this through with a reason if absolutely necessary.'
                    );
                }
            }
        }

        // Real bug this fixes: OrderRepository::setPiDates() existed but was
        // never called anywhere — every PI ever generated showed "VALID
        // UNTIL * TBC" on the document itself, and the PI-send email
        // template's {pi_valid_until} placeholder (docs/seed.sql) would
        // render blank for every buyer. pi_date/pi_valid_until mirror
        // quotation_date/quotation_valid_until (set at order creation) but
        // can only be set here, at actual PI-generation time — set (or
        // reset, on a re-issued PI) every time a PI is generated so the
        // validity window always reflects the most recent issue.
        if ($documentTypeCode === 'PI') {
            $piValidityDays = (int) (CompanySettingsRepository::get('pi_validity_days') ?? 15);
            OrderRepository::setPiDates(
                $orderId,
                (new \DateTimeImmutable())->format('Y-m-d'),
                (new \DateTimeImmutable("+{$piValidityDays} days"))->format('Y-m-d')
            );
        }

        $data = DocumentDataAssembler::assemble($orderId, $documentTypeCode);

        $existing = DocumentRepository::findLatestForOrderAndType($orderId, (int) $docType['id']);
        // QA-5 CONC-04: reserved atomically, up front — see
        // ReferenceNumberService::nextDocumentRevisionNumber()'s docblock
        // for why a plain `$existing['revision_number'] + 1` read (with the
        // actual INSERT not landing until after PDF/DOCX rendering
        // finished) let two concurrent regenerations of the same document
        // silently share one revision number.
        $revisionNumber = ReferenceNumberService::nextDocumentRevisionNumber($orderId, (int) $docType['id']);

        // Client-facing revision (docs/schema.sql Section AH) — how many
        // documents of this (order, type) the client has actually already
        // been sent. Deliberately NOT derived from $revisionNumber: staff
        // can regenerate as many times as needed to fix an internal mistake
        // before the first real send, and none of that churn should ever
        // reach the client as a jump from "Rev.00" to "Rev.09".
        $clientRevisionNumber = DocumentRepository::countPriorSent($orderId, (int) $docType['id']);

        $documentReference = $existing['document_reference']
            ?? self::preAssignedReferenceFor($documentTypeCode, $orderId)
            ?? ReferenceNumberService::generateDocumentRef((int) $docType['id']);

        $watermark = self::withRevisionStamp(self::draftWatermark(), $clientRevisionNumber, $docType['category'] ?? null);
        $balanceTriggerOption = $order['balance_trigger_option'] ?? null;
        $terms = self::resolveTerms($documentTypeCode, $data, $balanceTriggerOption);
        $legalTerms = self::resolveClauseGroup($documentTypeCode, 'legal_terms', $data, $balanceTriggerOption);
        $definitions = self::resolveClauseGroup($documentTypeCode, 'definitions', $data, $balanceTriggerOption);
        $signatory = DocumentDataAssembler::signatoryBlock((int) $docType['id'], $signatoryOverrideUserId);

        $context = array_merge($data, [
            'meta' => [
                'document_reference' => $documentReference,
                'revision_number'    => $revisionNumber,
                'revision_label'     => 'Rev.' . str_pad((string) $revisionNumber, 2, '0', STR_PAD_LEFT),
                'client_revision_number' => $clientRevisionNumber,
                'client_revision_label'  => 'Rev.' . str_pad((string) $clientRevisionNumber, 2, '0', STR_PAD_LEFT),
                'generated_date'     => (new \DateTimeImmutable())->format('d F Y'),
            ],
            'watermark' => $watermark,
            'doc_title' => self::titleFor($documentTypeCode),
            'section1_title' => self::section1TitleFor($documentTypeCode),
            'terms' => $terms,
            'terms_section_number' => self::termsSectionNumberFor($documentTypeCode),
            'terms_section_title'  => self::termsSectionTitleFor($documentTypeCode),
            'legal_terms_clauses' => $legalTerms,
            'definition_clauses' => $definitions,
            'legal_terms_section_number' => self::legalTermsSectionNumberFor($documentTypeCode),
            'signatory' => $signatory,
        ]);

        $twig = self::twigEnvironment();
        $templateFile = self::templateFileFor($documentTypeCode);
        $html = $twig->render($templateFile, $context);

        $storageBase = rtrim(Env::get('STORAGE_BASE_PATH', ''), '/');
        $clientNumber = self::sanitizePathSegment($order['client_unique_number']);
        $orderRef = self::sanitizePathSegment($order['order_reference']);
        $stageSlug = self::sanitizePathSegment($order['current_stage_slug'] ?? 'stage');
        $targetDir = "{$storageBase}/clients/{$clientNumber}/{$orderRef}/{$stageSlug}/generated";
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        // documentReference itself keeps its real "/" separators (that's the
        // correct on-document display format, e.g. SC/QT/2026/1809001) — but
        // a filename can't contain "/", so the download's displayed filename
        // uses a dash-joined version instead of the raw reference.
        $filenameSafeReference = $documentReference !== null ? str_replace('/', '-', $documentReference) : $documentTypeCode;

        // --- PDF (always) ---
        $pdfBytes = self::renderPdf($html);
        $pdfUuidName = self::uuidFilename('pdf');
        $pdfPath = "{$targetDir}/{$pdfUuidName}";
        file_put_contents($pdfPath, $pdfBytes);
        $pdfFileId = FileStoreRepository::insertGenerated(
            null,
            $orderId,
            null,
            $pdfPath,
            $pdfUuidName,
            "{$documentTypeCode} {$filenameSafeReference} Rev.{$clientRevisionNumber}.pdf",
            strlen($pdfBytes),
            'application/pdf',
            $generatedByUserId,
            false
        );

        // --- DOCX (only if the user asked for it AND it's admin-enabled for this document type) ---
        $docxFileId = null;
        if ($generateDocx && self::docxEnabledFor((int) $docType['id'])) {
            $docxUuidName = self::uuidFilename('docx');
            $docxPath = "{$targetDir}/{$docxUuidName}";
            self::renderDocx($docxPath, $documentTypeCode, $context);
            $docxFileId = FileStoreRepository::insertGenerated(
                null,
                $orderId,
                null,
                $docxPath,
                $docxUuidName,
                "{$documentTypeCode} {$filenameSafeReference} Rev.{$revisionNumber} (internal).docx",
                filesize($docxPath),
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                $generatedByUserId,
                true // internal_only — never emailed to buyer, per ARCHITECTURE.md diagram 2
            );
        }

        $documentId = DocumentRepository::create(
            $orderId,
            (int) $docType['id'],
            $documentReference,
            $revisionNumber,
            $pdfFileId,
            $docxFileId,
            $generatedByUserId,
            $signatory,
            $data['company'],
            $clientRevisionNumber
        );

        return [
            'document_id'        => $documentId,
            'document_reference' => $documentReference,
            'revision_number'    => $revisionNumber,
            'client_revision_number' => $clientRevisionNumber,
            'pdf_file_id'        => $pdfFileId,
            'docx_file_id'       => $docxFileId,
        ];
    }

    /**
     * SUPPO is the one document type whose reference has to exist before
     * this method ever runs: order_supplier_po.supplier_po_reference is
     * NOT NULL and is entered (via ReferenceNumberService, same generator
     * this class would otherwise call) at the point NexaCrest fills the
     * Supplier PO's material/commercial terms — the render needs that
     * data to already be there (DocumentDataAssembler::supplierPoBlock()
     * pulls it from order_supplier_po), so that row is created first.
     * On the first-ever generate() call for a SUPPO (no `documents` row
     * yet, so $existing is null above), reuse that pre-assigned reference
     * instead of minting a second, different one from the same day's
     * sequence.
     */
    private static function preAssignedReferenceFor(string $code, int $orderId): ?string
    {
        if ($code !== 'SUPPO') {
            return null;
        }
        $row = \App\Repositories\OrderSupplierPoRepository::findLatestForOrder($orderId);
        return $row['supplier_po_reference'] ?? null;
    }

    /**
     * Where a document type sits in the order lifecycle (stages_master
     * sequence) — used both to detect a stage-regeneration cascade risk
     * (downstreamDocumentsAtRisk() below) AND, since 2026-09-25, as the
     * server-side stage-gate check in generate() itself (requiredStageGate()
     * below) — the order-detail view already hides a document type's
     * "Generate" button until its stage unlocks, but generate() never
     * verified that server-side, so posting the route directly could
     * generate any document type at any time regardless of stage. Two
     * types can legitimately share a stage (ANNEXA rides with QT; PL and
     * BLI both belong to the packing/BL stage) since both are produced
     * from the same stage's data and neither is "downstream" of the
     * other. AMD isn't part of the buyer-facing document sequence a
     * regeneration would meaningfully cascade into, so it's excluded (it
     * has its own approval workflow instead — see AmendmentService).
     */
    private static function stageSequenceFor(string $code): ?int
    {
        return match ($code) {
            'QT', 'ANNEXA' => 1,
            'BUYERPO' => 2,
            'PI' => 3,
            'OC' => 4,
            'SUPPO' => 5,
            'FDN' => 6,
            'PL', 'BLI' => 7,
            'CI' => 8,
            'COOPREP' => 9,
            default => null,
        };
    }

    /**
     * Real risk this guards against: every document type's data comes from
     * a live read of the order at the moment generate() runs (see
     * assemble() above) — there is no propagation from an earlier-stage
     * document to a later one beyond a cross-reference number (PI cites
     * the QT ref, OC cites the PI ref). So if QT is REGENERATED (a new
     * revision of a document type that already existed) after OC has
     * already been generated, OC's already-rendered PDF still reflects
     * whatever data was live when OC was made — it does not silently pick
     * up whatever changed about QT. Nothing technically breaks, but a
     * later-stage document can now be quietly out of step with an
     * earlier-stage one a reviewer or the buyer might compare it against.
     * Called only for a REGENERATION (revisionNumber > 0) — the first-ever
     * generation of a type is normal forward progress, not a cascade risk.
     *
     * @return array<int, string> document type codes with an existing
     *         generated document at a later stage than $regeneratedCode
     */
    public static function downstreamDocumentsAtRisk(int $orderId, string $regeneratedCode): array
    {
        $sequence = self::stageSequenceFor($regeneratedCode);
        if ($sequence === null) {
            return [];
        }

        $documents = DocumentRepository::forOrder($orderId);
        $seen = [];
        $atRisk = [];
        foreach ($documents as $doc) {
            $code = $doc['document_type_code'];
            if (isset($seen[$code])) {
                continue;
            }
            $docSequence = self::stageSequenceFor($code);
            if ($docSequence !== null && $docSequence > $sequence) {
                $seen[$code] = true;
                $atRisk[] = $code;
            }
        }
        return $atRisk;
    }

    private static function findDocumentType(string $code): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM document_types WHERE code = :code');
        $stmt->execute(['code' => $code]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Public lookup for callers outside this service that need a
     * document_types.id before generate() itself runs — currently just
     * OrderController::saveSupplierPo(), which must mint SUPPO's
     * reference number up front (see preAssignedReferenceFor() above).
     */
    public static function documentTypeIdFor(string $code): ?int
    {
        $docType = self::findDocumentType($code);
        return $docType ? (int) $docType['id'] : null;
    }

    private static function docxEnabledFor(int $documentTypeId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT is_enabled FROM docx_generation_settings WHERE document_type_id = :id'
        );
        $stmt->execute(['id' => $documentTypeId]);
        $row = $stmt->fetch();
        return $row ? (bool) $row['is_enabled'] : false;
    }

    private static function draftWatermark(): array
    {
        $stmt = Database::connection()->query(
            "SELECT * FROM watermark_settings WHERE scope = 'global' AND is_draft_mode = 1 LIMIT 1"
        );
        return self::watermarkFromRow($stmt->fetch() ?: null);
    }

    /**
     * mode ('text'|'image'|'both') decides what the Twig watermark block
     * actually renders (see _layout.html.twig) — both a text overlay and
     * an image overlay can be present on the same document at once,
     * they're independent layers, not mutually exclusive.
     *
     * @param array<string,mixed>|null $row a watermark_settings row
     */
    private static function watermarkFromRow(?array $row): array
    {
        if (!$row) {
            return ['enabled' => false];
        }
        $mode = $row['mode'] ?? 'text';
        $imageDataUri = null;
        if (($mode === 'image' || $mode === 'both') && !empty($row['image_asset_id'])) {
            $assetStmt = Database::connection()->prepare('SELECT * FROM assets WHERE id = :id');
            $assetStmt->execute(['id' => $row['image_asset_id']]);
            $imageDataUri = self::assetDataUri($assetStmt->fetch() ?: null);
        }
        return [
            'enabled'        => true,
            'mode'           => $mode,
            'show_text'      => $mode === 'text' || $mode === 'both',
            'show_image'     => ($mode === 'image' || $mode === 'both') && $imageDataUri !== null,
            'text'           => $row['text_content'],
            'color'          => $row['color'],
            'opacity'        => $row['opacity'],
            'angle'          => $row['angle'],
            'font_size'      => $row['font_size'],
            'image_data_uri' => $imageDataUri,
            'image_opacity'  => $row['image_opacity'] ?? 0.15,
            'image_position' => $row['image_position'] ?? 'center',
        ];
    }

    /** @param array<string,mixed>|null $asset */
    private static function assetDataUri(?array $asset): ?string
    {
        if (!$asset) {
            return null;
        }
        if (!is_file($asset['server_path'])) {
            // Point 8 fix — this used to fail silently: mode 'both' would
            // quietly render only the text layer with no error anywhere,
            // looking exactly like the image branch was never coded. Now
            // it's at least visible in the logs, and WatermarkController
            // additionally refuses to save 'image'/'both' with no on-disk
            // file in the first place (see its own comment).
            error_log("[WATERMARK] Asset id {$asset['id']} references missing file: {$asset['server_path']}");
            return null;
        }
        return 'data:' . ($asset['mime_type'] ?: 'image/png') . ';base64,' . base64_encode((string) file_get_contents($asset['server_path']));
    }

    /**
     * The watermark a document switches to once ReviewWorkflowService
     * considers it fully approved (finalizeApproval() below) — still a
     * visible watermark (Business Rule #8: "No clean PDF exists in this
     * system"), just no longer the amber DRAFT one.
     */
    private static function finalWatermark(): array
    {
        $stmt = Database::connection()->query(
            "SELECT * FROM watermark_settings WHERE scope = 'global' AND is_draft_mode = 0 LIMIT 1"
        );
        return self::watermarkFromRow($stmt->fetch() ?: null);
    }

    /**
     * Spec Section 9 — once every required reviewer has approved a
     * document (ReviewWorkflowService::approve() decides when that's
     * true), the DRAFT watermark is replaced by the final one. Re-renders
     * the exact same revision (same document_reference, same
     * revision_number) from the order's current data — safe because
     * nothing legitimately changes an order's data between "document
     * generated" and "all reviewers approved" (locked fields, no new
     * stage progression until this document is actually released) — and
     * writes the result as a NEW file_store row rather than overwriting
     * the draft PDF on disk, so the draft copy stays on record for audit
     * purposes (file_store rows are soft-delete-only, never actually
     * removed).
     */
    public static function finalizeApproval(int $documentId): void
    {
        $document = DocumentRepository::find($documentId);
        if (!$document) {
            throw new \RuntimeException("Document {$documentId} not found");
        }
        if ($document['order_id'] === null) {
            // Internal, order-less documents (SOPs, StageGate, WallRef) never
            // go through the buyer-facing review/approval pipeline.
            throw new \RuntimeException('This document type is not order-scoped and cannot be finalized here.');
        }

        $orderId = (int) $document['order_id'];
        $order = OrderRepository::find($orderId);
        $documentTypeCode = $document['document_type_code'];

        $data = DocumentDataAssembler::assemble($orderId, $documentTypeCode);
        $terms = self::resolveTerms($documentTypeCode, $data);

        $context = array_merge($data, [
            'meta' => [
                'document_reference' => $document['document_reference'],
                'revision_number'    => (int) $document['revision_number'],
                'revision_label'     => 'Rev.' . str_pad((string) $document['revision_number'], 2, '0', STR_PAD_LEFT),
                'client_revision_number' => (int) $document['client_revision_number'],
                'client_revision_label'  => 'Rev.' . str_pad((string) $document['client_revision_number'], 2, '0', STR_PAD_LEFT),
                'generated_date'     => (new \DateTimeImmutable($document['generated_at']))->format('d F Y'),
            ],
            'watermark' => self::withRevisionStamp(self::finalWatermark(), (int) $document['client_revision_number'], $document['document_type_category'] ?? null),
            'doc_title' => self::titleFor($documentTypeCode),
            'section1_title' => self::section1TitleFor($documentTypeCode),
            'terms' => $terms,
            'terms_section_number' => self::termsSectionNumberFor($documentTypeCode),
            'terms_section_title'  => self::termsSectionTitleFor($documentTypeCode),
            // Re-rendering the SAME document (draft -> final watermark
            // swap) must keep showing the same signatory it was originally
            // generated with — read from the row's own snapshot, never
            // re-resolved from current defaults (see "Real bugs found":
            // without this, the buyer-facing FINAL PDF rendered with a
            // blank signature/seal block, since this render path never
            // merged a `signatory` key into $context at all).
            'signatory' => DocumentDataAssembler::signatoryFromSnapshot($document),
            // Same reasoning, extended to company/bank/LUT details: $data
            // above re-read company_settings LIVE via assemble() ->
            // companyBlock(), so a bank account switch or LUT renewal made
            // during the review window would have silently changed what
            // the buyer-facing FINAL PDF shows versus the DRAFT a reviewer
            // actually approved. Override with the row's own snapshot.
            'company' => DocumentDataAssembler::companyFromSnapshot($document),
        ]);

        $twig = self::twigEnvironment();
        $html = $twig->render(self::templateFileFor($documentTypeCode), $context);
        $pdfBytes = self::renderPdf($html);

        $storageBase = rtrim(Env::get('STORAGE_BASE_PATH', ''), '/');
        $clientNumber = self::sanitizePathSegment($order['client_unique_number']);
        $orderRef = self::sanitizePathSegment($order['order_reference']);
        $stageSlug = self::sanitizePathSegment($order['current_stage_slug'] ?? 'stage');
        $targetDir = "{$storageBase}/clients/{$clientNumber}/{$orderRef}/{$stageSlug}/generated";
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $filenameSafeReference = $document['document_reference'] !== null
            ? str_replace('/', '-', $document['document_reference'])
            : $documentTypeCode;
        $finalUuidName = self::uuidFilename('pdf');
        $finalPath = "{$targetDir}/{$finalUuidName}";
        file_put_contents($finalPath, $pdfBytes);

        $finalPdfFileId = FileStoreRepository::insertGenerated(
            null,
            $orderId,
            null,
            $finalPath,
            $finalUuidName,
            "{$documentTypeCode} {$filenameSafeReference} Rev.{$document['client_revision_number']} (approved).pdf",
            strlen($pdfBytes),
            'application/pdf',
            null,
            false
        );

        DocumentRepository::markApproved($documentId, $finalPdfFileId);
    }

    /**
     * The buyer's own copy must be unmistakable about which revision
     * they're looking at — not just in the on-page revision labels but
     * stamped into the watermark itself, since the watermark is the one
     * thing visible on every page of a printed or forwarded copy. Only
     * applied from the second client-facing issue onward
     * (client_revision_number 0 is the first-ever send — nothing to
     * distinguish it from) and only for customer_facing document types
     * (BLI/COOPREP/SUPPO etc. are never buyer-facing, so a revision count
     * on them would mean nothing to whoever's reading one).
     */
    private static function withRevisionStamp(array $watermark, int $clientRevisionNumber, ?string $documentTypeCategory): array
    {
        if (empty($watermark['enabled']) || empty($watermark['show_text']) || $clientRevisionNumber < 1 || $documentTypeCategory !== 'customer_facing') {
            return $watermark;
        }
        $watermark['text'] = trim((string) $watermark['text']) . ' — REVISION ' . str_pad((string) $clientRevisionNumber, 2, '0', STR_PAD_LEFT);
        return $watermark;
    }

    /**
     * Hardcoded rather than read from watermark_settings — there's no
     * admin need to customize how an INVALID stamp looks, and keeping it
     * out of the DB means it can never accidentally be reconfigured into
     * something less obvious. Deliberately impossible to miss: bright red,
     * large, steep angle — the opposite design intent of the DRAFT/final
     * watermarks, which are meant to be visible but unobtrusive.
     */
    private static function invalidWatermark(): array
    {
        return [
            'enabled'        => true,
            'mode'           => 'text',
            'show_text'      => true,
            'show_image'     => false,
            'text'           => 'INVALID DOCUMENT — SUPERSEDED',
            'color'          => '#C0152F',
            'opacity'        => 0.35,
            'angle'          => 35,
            'font_size'      => 54,
            'image_data_uri' => null,
            'image_opacity'  => 0,
            'image_position' => 'center',
        ];
    }

    /**
     * A newer revision of this (order, type) has just been fully approved
     * — this older, previously-approved/sent revision is no longer the
     * valid copy. Re-renders it from its own stored snapshot (exact same
     * pattern as finalizeApproval() above: same document_reference, same
     * revision_number, signatory/company read from the row's own
     * snapshot, never live data) with the INVALID DOCUMENT watermark
     * instead of the final one, writes the result as a new file_store row
     * (the previously-approved PDF is left alone on disk/DB, per the
     * soft-delete-only convention), and repoints pdf_file_id to it so
     * anyone who opens/downloads this document from here on sees the
     * invalidated copy, never the old clean one.
     */
    public static function markSuperseded(int $documentId): void
    {
        $document = DocumentRepository::find($documentId);
        if (!$document || $document['order_id'] === null) {
            return;
        }

        $orderId = (int) $document['order_id'];
        $order = OrderRepository::find($orderId);
        $documentTypeCode = $document['document_type_code'];

        $data = DocumentDataAssembler::assemble($orderId, $documentTypeCode);
        $terms = self::resolveTerms($documentTypeCode, $data);

        $context = array_merge($data, [
            'meta' => [
                'document_reference' => $document['document_reference'],
                'revision_number'    => (int) $document['revision_number'],
                'revision_label'     => 'Rev.' . str_pad((string) $document['revision_number'], 2, '0', STR_PAD_LEFT),
                'client_revision_number' => (int) $document['client_revision_number'],
                'client_revision_label'  => 'Rev.' . str_pad((string) $document['client_revision_number'], 2, '0', STR_PAD_LEFT),
                'generated_date'     => (new \DateTimeImmutable($document['generated_at']))->format('d F Y'),
            ],
            'watermark' => self::invalidWatermark(),
            'doc_title' => self::titleFor($documentTypeCode),
            'section1_title' => self::section1TitleFor($documentTypeCode),
            'terms' => $terms,
            'terms_section_number' => self::termsSectionNumberFor($documentTypeCode),
            'terms_section_title'  => self::termsSectionTitleFor($documentTypeCode),
            'signatory' => DocumentDataAssembler::signatoryFromSnapshot($document),
            'company' => DocumentDataAssembler::companyFromSnapshot($document),
        ]);

        $twig = self::twigEnvironment();
        $html = $twig->render(self::templateFileFor($documentTypeCode), $context);
        $pdfBytes = self::renderPdf($html);

        $storageBase = rtrim(Env::get('STORAGE_BASE_PATH', ''), '/');
        $clientNumber = self::sanitizePathSegment($order['client_unique_number']);
        $orderRef = self::sanitizePathSegment($order['order_reference']);
        $stageSlug = self::sanitizePathSegment($order['current_stage_slug'] ?? 'stage');
        $targetDir = "{$storageBase}/clients/{$clientNumber}/{$orderRef}/{$stageSlug}/generated";
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $filenameSafeReference = $document['document_reference'] !== null
            ? str_replace('/', '-', $document['document_reference'])
            : $documentTypeCode;
        $supersededUuidName = self::uuidFilename('pdf');
        $supersededPath = "{$targetDir}/{$supersededUuidName}";
        file_put_contents($supersededPath, $pdfBytes);

        $supersededPdfFileId = FileStoreRepository::insertGenerated(
            null,
            $orderId,
            null,
            $supersededPath,
            $supersededUuidName,
            "{$documentTypeCode} {$filenameSafeReference} Rev.{$document['client_revision_number']} (SUPERSEDED).pdf",
            strlen($pdfBytes),
            'application/pdf',
            null,
            false
        );

        DocumentRepository::markSuperseded($documentId, $supersededPdfFileId);
        AuditLogRepository::log(null, 'DOCUMENT_SUPERSEDED', 'documents', $documentId, 'status', $document['status'], 'superseded');
    }

    /**
     * Called right after a document is fully approved (finalizeApproval()
     * above) — every OTHER document of the same (order, type) that's
     * still sitting as 'approved' or 'sent' is now stale: only the
     * revision that was just approved may legitimately be sent to or
     * relied on by the buyer going forward. Finds every sibling revision
     * via DocumentRepository::allForOrderAndType() and invalidates each
     * one still in 'approved'/'sent' status (a 'draft'/'in_review'/
     * already-'superseded' sibling is left alone — nothing to invalidate).
     */
    public static function supersedeOtherApprovedRevisions(int $documentId): void
    {
        $document = DocumentRepository::find($documentId);
        if (!$document || $document['order_id'] === null) {
            return;
        }

        $siblings = DocumentRepository::allForOrderAndType((int) $document['order_id'], (int) $document['document_type_id']);
        foreach ($siblings as $sibling) {
            if ((int) $sibling['id'] === $documentId) {
                continue;
            }
            if (in_array($sibling['status'], ['approved', 'sent'], true)) {
                self::markSuperseded((int) $sibling['id']);
            }
        }
    }

    /**
     * Section 8 — Payment Terms Amendment Agreement. Unlike every other
     * document type, this one's content comes entirely from a single
     * `amendments` row (frozen at request time — original_terms_snapshot
     * — plus the amended terms typed in alongside it), not from
     * DocumentDataAssembler::assemble()'s live order snapshot: an
     * amendment is a legal record of what changed and when, so it must
     * render the same way even if the order's live data moves on
     * afterwards. Can only be called after MD approval (enforced by
     * AmendmentController, not here) — this method just renders whatever
     * status the amendment is in.
     */
    public static function generateAmendment(int $amendmentId, int $generatedByUserId): array
    {
        $amendment = AmendmentRepository::find($amendmentId);
        if (!$amendment) {
            throw new \RuntimeException("Amendment {$amendmentId} not found");
        }
        $orderId = (int) $amendment['order_id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            throw new \RuntimeException("Order {$orderId} not found");
        }

        $docTypeId = self::documentTypeIdFor('AMD');
        if (!$docTypeId) {
            throw new \RuntimeException('AMD document type is not seeded.');
        }

        $snapshot = $amendment['original_terms_snapshot'] ?? [];
        $company = DocumentDataAssembler::companyBlock();
        $assets = DocumentDataAssembler::assetsBlock();
        $signatory = DocumentDataAssembler::signatoryBlock($docTypeId);

        $context = [
            'company' => $company,
            'assets'  => $assets,
            'signatory' => $signatory,
            'order'   => ['incoterm_code' => null], // suppresses the default header incoterm chip — not relevant to a legal amendment record
            'doc_title' => self::titleFor('AMD'),
            'section1_title' => self::section1TitleFor('AMD'),
            'watermark' => self::draftWatermark(),
            'meta' => [
                'document_reference' => $amendment['amendment_reference'],
                'revision_number'    => 0,
                'revision_label'     => '',
                'generated_date'     => (new \DateTimeImmutable())->format('d F Y'),
            ],
            'amendment' => [
                'amendment_reference' => $amendment['amendment_reference'],
                'buyer_inquiry_ref'    => $amendment['buyer_inquiry_ref'],
                'effective_from'       => DocumentDataAssembler::formatDate($amendment['effective_from']),
                'reason'               => $amendment['reason'],
                'requested_by'         => $amendment['requested_by'] === 'importer' ? 'The Importer' : 'NexaCrest International Private Limited',
                'md_approved_at'       => DocumentDataAssembler::formatDate($amendment['md_approved_at']),
                'original' => [
                    'quotation_ref'    => $snapshot['quotation_ref'] ?? null,
                    'quotation_date'   => $snapshot['quotation_date'] ?? null,
                    'pi_ref'           => $snapshot['pi_ref'] ?? null,
                    'pi_date'          => $snapshot['pi_date'] ?? null,
                    'oc_ref'           => $snapshot['oc_ref'] ?? null,
                    'product_summary'  => $snapshot['product_summary'] ?? null,
                    'total_fob_value'  => $snapshot['total_fob_value'] ?? null,
                    'advance_terms_text' => $snapshot['advance_terms_text'] ?? null,
                    'advance_amount'   => $snapshot['advance_amount'] ?? null,
                    'balance_terms_text' => $snapshot['balance_terms_text'] ?? null,
                    'balance_amount'   => $snapshot['balance_amount'] ?? null,
                    'freight_terms'    => $snapshot['freight_terms'] ?? null,
                    'currency'         => $snapshot['currency'] ?? null,
                    'port_of_loading'  => $snapshot['port_of_loading'] ?? null,
                    'incoterm_label'   => $snapshot['incoterm_label'] ?? null,
                    'lut_number'       => $snapshot['lut_number'] ?? null,
                    'gstin'            => $snapshot['gstin'] ?? null,
                    'iec_pan'          => $snapshot['iec_pan'] ?? null,
                ],
                'amended' => [
                    // The amendments table stores the amended ADVANCE side as
                    // a percentage + amount only (no separate free-text
                    // trigger-condition column) — real-world amendments per
                    // the spec's own example almost always renegotiate the
                    // BALANCE side, not the advance trigger condition, so the
                    // advance description below reuses the original PI's
                    // trigger wording (frozen in the snapshot) with just the
                    // percentage swapped in. Any wording change beyond the
                    // percentage belongs in the Reason field, which prints
                    // in full on this document.
                    'advance_pct'      => $amendment['amended_advance_pct'],
                    'advance_terms_text' => $amendment['amended_advance_pct'] !== null
                        ? rtrim(rtrim(number_format((float) $amendment['amended_advance_pct'], 2), '0'), '.') . '% advance T/T on FOB Value ' . ($snapshot['advance_trigger_text'] ?? '')
                        : null,
                    'advance_amount'   => $amendment['amended_advance_amount'] !== null ? DocumentDataAssembler::formatMoney($amendment['amended_advance_amount']) : null,
                    'balance_terms'    => $amendment['amended_balance_terms'],
                    'balance_amount'   => $amendment['amended_balance_amount'] !== null ? DocumentDataAssembler::formatMoney($amendment['amended_balance_amount']) : null,
                ],
            ],
            'buyer' => [
                'company_legal_name' => $order['company_legal_name'],
                'billing_address'    => $order['billing_address'],
                'vat_eori_tax_no'    => $order['vat_eori_tax_no'],
                'contact_person'     => $order['contact_person'],
                'phone'              => $order['client_phone'],
                'email'              => $order['client_email'],
            ],
        ];

        $twig = self::twigEnvironment();
        $html = $twig->render('AMD/payment_terms_amendment.html.twig', $context);
        $pdfBytes = self::renderPdf($html);

        $storageBase = rtrim(Env::get('STORAGE_BASE_PATH', ''), '/');
        $clientNumber = self::sanitizePathSegment($order['client_unique_number']);
        $orderRef = self::sanitizePathSegment($order['order_reference']);
        $amdRefSafe = self::sanitizePathSegment($amendment['amendment_reference']);
        $targetDir = "{$storageBase}/clients/{$clientNumber}/{$orderRef}/amendments/{$amdRefSafe}/generated";
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        $amdUuidName = self::uuidFilename('pdf');
        $pdfPath = "{$targetDir}/{$amdUuidName}";
        file_put_contents($pdfPath, $pdfBytes);

        $pdfFileId = FileStoreRepository::insertGenerated(
            null,
            $orderId,
            null,
            $pdfPath,
            $amdUuidName,
            'AMD ' . str_replace('/', '-', $amendment['amendment_reference']) . '.pdf',
            strlen($pdfBytes),
            'application/pdf',
            $generatedByUserId,
            false
        );

        $documentId = DocumentRepository::create(
            $orderId,
            $docTypeId,
            $amendment['amendment_reference'],
            0,
            $pdfFileId,
            null,
            $generatedByUserId,
            $signatory,
            $company
        );

        AmendmentRepository::attachDocument($amendmentId, $documentId);

        return ['document_id' => $documentId, 'pdf_file_id' => $pdfFileId];
    }

    /**
     * Point 2 follow-up (2026-09-30) — the internal-only "CA Financial
     * Annexure": every RODTEP/export-benefit claim and every expense
     * (ECGC insurance, inspection, CHA, transport, ...) linked to one
     * order, collected into one printable record for staff/CA use.
     *
     * This is a HARD, structural separation from every client-facing
     * document type, not a checkbox on an existing one:
     *   - Its own document_types row (CAFIN, category='internal') — the
     *     exact mechanism this app already uses to keep SUPPO/BLI/
     *     COOPREP/AMD/checklists out of DocumentRepository::
     *     customerFacingForOrder() (that query filters on
     *     category = 'customer_facing'), so this can never appear in the
     *     client portal's document list, whatever CaController does.
     *   - Its own Twig template (CAFIN/ca_financial_annexure.html.twig)
     *     that does NOT extend _layout.html.twig — deliberately no
     *     shared header/footer/signature-block markup with any buyer
     *     document, so it can never be visually mistaken for one even if
     *     it somehow ended up in the wrong hands. Carries a bright red
     *     "INTERNAL ONLY" banner top and bottom instead.
     *   - Stored under the order's own "internal-ca" path segment, not
     *     alongside stage-based generated documents.
     *   - file_store.internal_only = true (same flag content-parity
     *     DOCX renders already use) and excluded from the general
     *     dossier ZIP (OrderController::downloadDossier()) so a staff
     *     member holding only the broad manage_orders permission can't
     *     pull it in bulk — DocumentController::download() separately
     *     requires ca_module_view specifically for this one document
     *     type before serving it.
     *
     * Refused unless orders.ca_internal_doc_enabled is set (checked here
     * too, not just in the controller, as defense in depth) — see
     * OrderRepository::setCaInternalDocEnabled(). No stage-gate or
     * locked-order check: unlike buyer documents, this one is often only
     * generated well after an order has closed (RODTEP can take months
     * to actually be credited), so it must remain generatable regardless
     * of the order's own lifecycle state.
     */
    public static function generateCaInternalAnnexure(int $orderId, int $generatedByUserId): array
    {
        $order = OrderRepository::find($orderId);
        if (!$order) {
            throw new \RuntimeException("Order {$orderId} not found");
        }
        if (empty($order['ca_internal_doc_enabled'])) {
            throw new \RuntimeException("Internal CA financial annexure is not enabled for order {$orderId}.");
        }

        $docTypeId = self::documentTypeIdFor('CAFIN');
        if (!$docTypeId) {
            throw new \RuntimeException('CAFIN document type is not seeded.');
        }

        $benefits = CaExportBenefitRepository::forOrder($orderId);
        $expenses = CaExpenseRepository::forOrder($orderId);
        // Added alongside the Order Profitability Sheet (docs/schema.sql
        // Section AP) — supplier pricing is exactly the kind of data this
        // document exists for (internal-only, never client-facing) and was
        // previously missing from it entirely.
        $supplierPo = \App\Repositories\OrderSupplierPoRepository::findLatestForOrder($orderId);
        $costEntries = \App\Repositories\OrderCostEntryRepository::forOrder($orderId);
        $profitability = \App\Services\OrderProfitabilityService::computeForOrder($orderId);

        $revisionNumber = ReferenceNumberService::nextDocumentRevisionNumber($orderId, $docTypeId);
        $existing = DocumentRepository::findLatestForOrderAndType($orderId, $docTypeId);
        $documentReference = $existing['document_reference'] ?? ReferenceNumberService::generateDocumentRef($docTypeId);

        $context = [
            'company' => DocumentDataAssembler::companyBlock(),
            'order' => [
                'order_reference' => $order['order_reference'],
                'company_legal_name' => $order['company_legal_name'],
            ],
            'meta' => [
                'document_reference' => $documentReference,
                'revision_number'    => $revisionNumber,
                'generated_date'     => (new \DateTimeImmutable())->format('d F Y H:i'),
                'generated_by_name'  => \App\Repositories\UserRepository::findById($generatedByUserId)['name'] ?? 'Unknown',
            ],
            'benefits' => $benefits,
            'expenses' => $expenses,
            'supplier_po' => $supplierPo,
            'cost_entries' => $costEntries,
            'profitability' => $profitability,
        ];

        $twig = self::twigEnvironment();
        $html = $twig->render('CAFIN/ca_financial_annexure.html.twig', $context);
        $pdfBytes = self::renderPdf($html);

        $storageBase = rtrim(Env::get('STORAGE_BASE_PATH', ''), '/');
        $clientNumber = self::sanitizePathSegment($order['client_unique_number']);
        $orderRef = self::sanitizePathSegment($order['order_reference']);
        $targetDir = "{$storageBase}/clients/{$clientNumber}/{$orderRef}/internal-ca/generated";
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $filenameSafeReference = str_replace('/', '-', $documentReference);
        $uuidName = self::uuidFilename('pdf');
        $pdfPath = "{$targetDir}/{$uuidName}";
        file_put_contents($pdfPath, $pdfBytes);

        $pdfFileId = FileStoreRepository::insertGenerated(
            null,
            $orderId,
            null,
            $pdfPath,
            $uuidName,
            "CAFIN {$filenameSafeReference} (INTERNAL ONLY - not for client).pdf",
            strlen($pdfBytes),
            'application/pdf',
            $generatedByUserId,
            true // internal_only — never emailed/sent to buyer, never in the client portal
        );

        $documentId = DocumentRepository::create(
            $orderId,
            $docTypeId,
            $documentReference,
            $revisionNumber,
            $pdfFileId,
            null,
            $generatedByUserId
        );

        return [
            'document_id'        => $documentId,
            'document_reference' => $documentReference,
            'revision_number'    => $revisionNumber,
            'pdf_file_id'        => $pdfFileId,
        ];
    }

    private static function twigEnvironment(): TwigEnvironment
    {
        $loader = new TwigFilesystemLoader(dirname(__DIR__, 2) . '/templates');
        return new TwigEnvironment($loader, [
            'cache' => false, // Bluehost-shared-hosting-safe default; enable a cache dir once storage permissions are confirmed
            'autoescape' => 'html',
        ]);
    }

    private static function templateFileFor(string $code): string
    {
        return match ($code) {
            'QT' => 'QT/quotation.html.twig',
            'ANNEXA' => 'ANNEXA/annexure.html.twig',
            'PI' => 'PI/proforma_invoice.html.twig',
            'OC' => 'OC/order_confirmation.html.twig',
            'BUYERPO' => 'BUYERPO/buyer_po.html.twig',
            'SUPPO' => 'SUPPO/supplier_po.html.twig',
            'FDN' => 'FDN/freight_debit_note.html.twig',
            'PL' => 'PL/packing_list.html.twig',
            'BLI' => 'BLI/bl_instruction.html.twig',
            'CI' => 'CI/commercial_invoice.html.twig',
            'COOPREP' => 'COOPREP/coo_prep.html.twig',
            'AMD' => 'AMD/payment_terms_amendment.html.twig',
            default => throw new \RuntimeException("No template mapped for document type {$code}"),
        };
    }

    /**
     * Document fidelity rebuild (2026-09-21): the real source templates
     * (the .docx files NexaCrest actually hands a buyer, and uses offline
     * to build one by hand) set every run to "Aptos" — Microsoft's current
     * Office default, not freely redistributable and not installed on any
     * Bluehost/VPS host this app will ever run on. Carlito (SIL Open Font
     * License, bundled in app/assets/fonts/) is the closest freely
     * redistributable substitute available — same humanist-sans category
     * Microsoft's own Calibri/Aptos lineage sits in. Registering it AS the
     * "Aptos" family name (rather than changing every template's CSS to
     * say "Carlito") means: every template can keep writing
     * font-family:'Aptos' to stay literally traceable to the source
     * template's own font choice, this embedded copy is what actually
     * renders regardless of what's installed on the host, and if a real
     * licensed Aptos ever gets installed server-side under this exact
     * family name, DOMPDF's registration below still wins — so nothing
     * silently changes without a deliberate code edit.
     */
    private static function registerDocumentFonts(Dompdf $dompdf): void
    {
        $fontDir = dirname(__DIR__, 2) . '/assets/fonts';
        $metrics = $dompdf->getFontMetrics();
        // data:// URIs, not file:// paths — DOMPDF's file:// protocol rule
        // enforces its own chroot (defaults to the dompdf package's own
        // vendor directory), which would reject anything under this app's
        // own assets/ folder. data:// carries no such restriction and is
        // the same technique this app already uses for the logo/seal
        // images (see DocumentDataAssembler::assetsBlock()'s *_data_uri
        // fields) — one consistent way to hand DOMPDF a local file.
        foreach ([
            ['normal', 'normal', 'Carlito-Regular.ttf'],
            ['normal', 'bold', 'Carlito-Bold.ttf'],
            ['italic', 'normal', 'Carlito-Italic.ttf'],
            ['italic', 'bold', 'Carlito-BoldItalic.ttf'],
        ] as [$style, $weight, $file]) {
            $uri = 'data://font/ttf;base64,' . base64_encode((string) file_get_contents("{$fontDir}/{$file}"));
            $metrics->registerFont(['family' => 'Aptos', 'style' => $style, 'weight' => $weight], $uri);
        }
    }

    private static function renderPdf(string $html): string
    {
        $options = new DompdfOptions();
        $options->set('isRemoteEnabled', false); // security: never fetch remote resources into a generated PDF
        $options->set('defaultFont', 'Aptos');
        // registerFont() below doesn't just read the source TTFs — it also
        // WRITES its own converted copy + metrics cache to fontDir/fontCache.
        // Left at DOMPDF's default, that's inside app/vendor/dompdf/dompdf/
        // itself: on Bluehost, vendor/ is a delivered, not-necessarily-writable
        // tree (and even where it is writable, generated cache files don't
        // belong inside a vendor snapshot). storage/ is this app's one
        // guaranteed-writable directory (STORAGE_BASE_PATH), so the cache
        // goes there instead — same reasoning as every generated PDF/DOCX
        // already living under storage/, not under app/.
        $fontCacheDir = rtrim(Env::get('STORAGE_BASE_PATH', ''), '/') . '/font_cache';
        if (!is_dir($fontCacheDir)) {
            mkdir($fontCacheDir, 0755, true);
        }
        $options->setFontDir($fontCacheDir);
        $options->setFontCache($fontCacheDir);
        $dompdf = new Dompdf($options);
        self::registerDocumentFonts($dompdf);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return $dompdf->output();
    }

    /**
     * Document-fidelity internal DOCX — one PHPWord render method per
     * document type (App\Services\Docx\DocxDocumentBuilder), each mirroring
     * its own Twig template's sections/labels/colors via the shared
     * App\Services\Docx\DocxComponents toolkit, not a single generic body
     * (see class docblock and DocxDocumentBuilder's own docblock).
     */
    private static function renderDocx(string $path, string $documentTypeCode, array $context): void
    {
        \App\Services\Docx\DocxDocumentBuilder::render($path, $documentTypeCode, $context);
    }

    private static function titleFor(string $code): string
    {
        return match ($code) {
            'QT' => 'QUOTATION',
            'ANNEXA' => 'ANNEXURE A',
            'PI' => 'PROFORMA INVOICE',
            'OC' => 'ORDER CONFIRMATION',
            'BUYERPO' => 'PURCHASE ORDER',
            'SUPPO' => 'PURCHASE ORDER — MATERIAL PROCUREMENT',
            'FDN' => 'DEBIT NOTE',
            'PL' => 'PACKING LIST',
            'BLI' => 'BILL OF LADING INSTRUCTION SHEET',
            'CI' => 'COMMERCIAL INVOICE',
            'COOPREP' => 'COO PREPARATION SHEET',
            'AMD' => 'PAYMENT TERMS AMENDMENT AGREEMENT',
            default => $code,
        };
    }

    /**
     * Section 1 of every business document is always NexaCrest's own
     * company block (see _layout.html.twig) — only the heading above it
     * changes, because in a Supplier PO NexaCrest is the "buyer" of raw
     * material, not the "seller", and a Buyer PO calls NexaCrest the
     * "supplier". Wording is taken verbatim from each source template.
     */
    private static function section1TitleFor(string $code): string
    {
        return match ($code) {
            'BUYERPO' => 'SUPPLIER',
            'SUPPO' => 'BUYER (NexaCrest International Private Limited)',
            'FDN' => 'FROM (SELLER / EXPORTER)',
            'CI' => 'EXPORTER / SELLER',
            'BLI' => 'SHIPPER / EXPORTER (APPEARS ON BL EXACTLY AS WRITTEN)',
            'AMD' => 'PARTIES TO THIS AMENDMENT',
            'PL' => 'EXPORTER',
            default => 'SELLER / EXPORTER', // QT, PI, OC
        };
    }

    private static function termsSectionNumberFor(string $code): int
    {
        // QT/OC moved from 7->8 and PI from 9->10 when the Buyer/
        // Consignee split (and PI's own new Notify Party section) added
        // one (QT/OC) or two (PI) sections ahead of this one — see
        // legalTermsSectionNumberFor() just below for the section that
        // now follows this one in every case.
        return match ($code) {
            'QT' => 8,
            'PI' => 10,
            'OC' => 8,
            'BUYERPO' => 5,
            'SUPPO' => 6, // "6. QUALITY & INSPECTION" in the source template
            default => 9,
        };
    }

    private static function termsSectionTitleFor(string $code): string
    {
        // The real Order Confirmation template calls this section "ORDER
        // CONDITIONS", and the Supplier PO calls it "QUALITY & INSPECTION"
        // — wording differences preserved from each source document, not
        // an inconsistency. PL/FDN/CI/BLI/COOPREP have no clauses linked
        // to them (see seed.sql), so `terms` comes back empty and
        // _layout.html.twig's `{% if terms %}` guard skips this section
        // for them entirely rather than showing an empty heading.
        return match ($code) {
            'OC' => 'ORDER CONDITIONS',
            'SUPPO' => 'QUALITY & INSPECTION',
            default => 'TERMS & CONDITIONS',
        };
    }

    /** @return string[] fully-substituted clause text, in order */
    private static function resolveTerms(string $documentTypeCode, array $data, ?string $balanceTriggerOption = null): array
    {
        $clauses = TermsClauseRepository::forDocumentTypeCodeAndGroup($documentTypeCode, 'standard', $balanceTriggerOption);
        return array_map(
            static fn(array $c) => strtr($c['text'], self::clauseTextReplacements($data)),
            $clauses
        );
    }

    /**
     * Legal Terms (red box) / Definitions (blue box) — Section [N] of
     * every buyer-facing document (never BLI). Same admin-editable
     * tc_clauses table as resolveTerms() above, filtered to
     * clause_group instead of 'standard', and run through the same
     * placeholder substitution for consistency even though neither
     * group's seeded text currently uses one.
     *
     * @return array<int, array{title:string, text:string}>
     */
    private static function resolveClauseGroup(string $documentTypeCode, string $clauseGroup, array $data, ?string $balanceTriggerOption): array
    {
        $clauses = TermsClauseRepository::forDocumentTypeCodeAndGroup($documentTypeCode, $clauseGroup, $balanceTriggerOption);
        $replacements = self::clauseTextReplacements($data);
        return array_map(
            static fn(array $c) => ['title' => $c['title'], 'text' => strtr($c['text'], $replacements)],
            $clauses
        );
    }

    /** @return array<string,string> */
    private static function clauseTextReplacements(array $data): array
    {
        $tolerance = CompanySettingsRepository::get('quantity_shortfall_tolerance_pct') ?? '5';
        $quotationValidityDays = CompanySettingsRepository::get('quotation_validity_days') ?? '30';
        $piValidityDays = CompanySettingsRepository::get('pi_validity_days') ?? '15';

        return [
            '{tolerance}'                => rtrim(rtrim($tolerance, '0'), '.'),
            '{advance_pct}'              => $data['financial']['advance_pct'],
            '{balance_pct}'              => $data['financial']['balance_pct'],
            '{quotation_validity_days}'  => $quotationValidityDays,
            '{pi_validity_days}'         => $piValidityDays,
        ];
    }

    /**
     * Section number of "LEGAL TERMS & DEFINITIONS" per document type —
     * matches the real position of that section in each type's own
     * numbering (NexaCrest_Developer_Spec.txt Section 3), not a fixed
     * constant across all of them, since some documents (PI/CI) carry
     * more preceding sections than others. null = this document type
     * never gets the section at all (BLI, and every internal-only type:
     * SUPPO/COOPREP/AMD/CAFIN/CHECKLIST* — none of those are buyer-facing
     * contracts, so there is nothing here for a buyer to need defined).
     */
    private static function legalTermsSectionNumberFor(string $code): ?int
    {
        return match ($code) {
            'QT' => 9,
            'PI' => 11,
            'OC' => 9,
            'PL' => 8,
            'CI' => 11,
            'FDN' => 7,
            'BUYERPO' => 8,
            'ANNEXA' => 8,
            default => null,
        };
    }

    private static function sanitizePathSegment(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_-]+/', '-', $value) ?? 'x';
    }

    /**
     * Spec Section 14 — "PDF filenames = UUIDs — never guessable." The
     * folder path (clients/{client}/{order}/{stage}/generated/) stays
     * human-navigable — that's just organization, never web-reachable
     * since storage/ sits outside public_html/ — but the leaf filename
     * itself must not be derivable from the document reference the way
     * `qt_SC-QT-2026-1809001_rev0.pdf` is. FileStoreRepository::find()'s
     * caller only ever reads `server_path` from the DB row (never
     * reconstructs it), and downloads present `original_filename` for
     * Content-Disposition, so this is a pure storage-layer change with no
     * effect on what a user sees or downloads.
     */
    private static function uuidFilename(string $extension): string
    {
        return bin2hex(random_bytes(16)) . '.' . $extension;
    }
}
