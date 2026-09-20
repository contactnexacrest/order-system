<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Config\Env;
use App\Repositories\AmendmentRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\FileStoreRepository;
use App\Repositories\OrderRepository;
use App\Repositories\TermsClauseRepository;
use Dompdf\Dompdf;
use Dompdf\Options as DompdfOptions;
use PhpOffice\PhpWord\IOFactory as PhpWordIOFactory;
use PhpOffice\PhpWord\PhpWord;
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
    public static function generate(int $orderId, string $documentTypeCode, int $generatedByUserId): array
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

        $data = DocumentDataAssembler::assemble($orderId);

        $existing = DocumentRepository::findLatestForOrderAndType($orderId, (int) $docType['id']);
        $revisionNumber = $existing ? ((int) $existing['revision_number']) + 1 : 0;

        $documentReference = $existing['document_reference']
            ?? self::preAssignedReferenceFor($documentTypeCode, $orderId)
            ?? ReferenceNumberService::generateDocumentRef((int) $docType['id']);

        $watermark = self::draftWatermark();
        $terms = self::resolveTerms($documentTypeCode, $data);

        $context = array_merge($data, [
            'meta' => [
                'document_reference' => $documentReference,
                'revision_number'    => $revisionNumber,
                'revision_label'     => 'Rev.' . str_pad((string) $revisionNumber, 2, '0', STR_PAD_LEFT),
                'generated_date'     => (new \DateTimeImmutable())->format('d F Y'),
            ],
            'watermark' => $watermark,
            'doc_title' => self::titleFor($documentTypeCode),
            'section1_title' => self::section1TitleFor($documentTypeCode),
            'terms' => $terms,
            'terms_section_number' => self::termsSectionNumberFor($documentTypeCode),
            'terms_section_title'  => self::termsSectionTitleFor($documentTypeCode),
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
            "{$documentTypeCode} {$filenameSafeReference} Rev.{$revisionNumber}.pdf",
            strlen($pdfBytes),
            'application/pdf',
            $generatedByUserId,
            false
        );

        // --- DOCX (only if enabled for this document type) ---
        $docxFileId = null;
        if (self::docxEnabledFor((int) $docType['id'])) {
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
            $generatedByUserId
        );

        return [
            'document_id'        => $documentId,
            'document_reference' => $documentReference,
            'revision_number'    => $revisionNumber,
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
        $row = $stmt->fetch();
        if (!$row) {
            return ['enabled' => false];
        }
        return [
            'enabled'  => true,
            'text'     => $row['text_content'],
            'color'    => $row['color'],
            'opacity'  => $row['opacity'],
            'angle'    => $row['angle'],
            'font_size' => $row['font_size'],
        ];
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
        $row = $stmt->fetch();
        if (!$row) {
            return ['enabled' => false];
        }
        return [
            'enabled'  => true,
            'text'     => $row['text_content'],
            'color'    => $row['color'],
            'opacity'  => $row['opacity'],
            'angle'    => $row['angle'],
            'font_size' => $row['font_size'],
        ];
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

        $data = DocumentDataAssembler::assemble($orderId);
        $terms = self::resolveTerms($documentTypeCode, $data);

        $context = array_merge($data, [
            'meta' => [
                'document_reference' => $document['document_reference'],
                'revision_number'    => (int) $document['revision_number'],
                'revision_label'     => 'Rev.' . str_pad((string) $document['revision_number'], 2, '0', STR_PAD_LEFT),
                'generated_date'     => (new \DateTimeImmutable($document['generated_at']))->format('d F Y'),
            ],
            'watermark' => self::finalWatermark(),
            'doc_title' => self::titleFor($documentTypeCode),
            'section1_title' => self::section1TitleFor($documentTypeCode),
            'terms' => $terms,
            'terms_section_number' => self::termsSectionNumberFor($documentTypeCode),
            'terms_section_title'  => self::termsSectionTitleFor($documentTypeCode),
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
            "{$documentTypeCode} {$filenameSafeReference} Rev.{$document['revision_number']} (approved).pdf",
            strlen($pdfBytes),
            'application/pdf',
            null,
            false
        );

        DocumentRepository::markApproved($documentId, $finalPdfFileId);
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

        $context = [
            'company' => $company,
            'assets'  => $assets,
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
            $generatedByUserId
        );

        AmendmentRepository::attachDocument($amendmentId, $documentId);

        return ['document_id' => $documentId, 'pdf_file_id' => $pdfFileId];
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

    private static function renderPdf(string $html): string
    {
        $options = new DompdfOptions();
        $options->set('isRemoteEnabled', false); // security: never fetch remote resources into a generated PDF
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return $dompdf->output();
    }

    /**
     * Content-parity internal DOCX — a straightforward PHPWord rendering
     * of the same assembled data, not a pixel-for-pixel copy of the PDF
     * layout (see class docblock).
     */
    private static function renderDocx(string $path, string $documentTypeCode, array $context): void
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        $section->addText($context['company']['legal_name'], ['bold' => true, 'size' => 16]);
        $section->addText(self::titleFor($documentTypeCode), ['bold' => true, 'size' => 13]);
        $section->addTextBreak();

        $section->addText("{$documentTypeCode} No.: {$context['meta']['document_reference']} {$context['meta']['revision_label']}");
        $section->addText("Date: {$context['meta']['generated_date']}");
        $section->addText("Buyer Inquiry Ref: {$context['order']['buyer_inquiry_ref']}");
        $section->addTextBreak();

        $section->addText('Buyer: ' . $context['buyer']['company_legal_name'], ['bold' => true]);
        $section->addText($context['buyer']['billing_address']);
        $section->addTextBreak();

        $table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999', 'width' => 100 * 50]);
        $table->addRow();
        foreach (['#', 'Description', 'Qty', 'Unit', 'Unit Price', 'Amount'] as $header) {
            $table->addCell(2000)->addText($header, ['bold' => true]);
        }
        foreach ($context['products'] as $i => $p) {
            $table->addRow();
            $table->addCell(2000)->addText((string) ($i + 1));
            $table->addCell(2000)->addText($p['description']);
            $table->addCell(2000)->addText($p['quantity']);
            $table->addCell(2000)->addText($p['unit'] ?? '');
            $table->addCell(2000)->addText($p['unit_price']);
            $table->addCell(2000)->addText($p['amount']);
        }

        $section->addTextBreak();
        $section->addText("FOB Value ({$context['order']['currency_code']}): {$context['financial']['fob_value']}", ['bold' => true]);
        $section->addText("Advance ({$context['financial']['advance_pct']}%): {$context['financial']['advance_amount']}");
        $section->addText("Balance ({$context['financial']['balance_pct']}%): {$context['financial']['balance_amount']}");

        $writer = PhpWordIOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($path);
    }

    private static function titleFor(string $code): string
    {
        return match ($code) {
            'QT' => 'QUOTATION',
            'PI' => 'PROFORMA INVOICE',
            'OC' => 'ORDER CONFIRMATION',
            'BUYERPO' => 'PURCHASE ORDER — ORDER ACCEPTANCE',
            'SUPPO' => 'PURCHASE ORDER — MATERIAL PROCUREMENT',
            'FDN' => 'FREIGHT DEBIT NOTE',
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
            'SUPPO' => 'BUYER (NEXACREST INTERNATIONAL PRIVATE LIMITED)',
            'FDN' => 'FROM (SELLER / EXPORTER)',
            'CI' => 'EXPORTER / SELLER',
            'BLI' => 'SHIPPER / EXPORTER (APPEARS ON BL EXACTLY AS WRITTEN)',
            'AMD' => 'THE EXPORTER',
            default => 'SELLER / EXPORTER', // QT, PI, OC, PL
        };
    }

    private static function termsSectionNumberFor(string $code): int
    {
        return match ($code) {
            'QT' => 7,
            'PI' => 8,
            'OC' => 7,
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
    private static function resolveTerms(string $documentTypeCode, array $data): array
    {
        $clauses = TermsClauseRepository::forDocumentTypeCode($documentTypeCode);
        $tolerance = CompanySettingsRepository::get('quantity_shortfall_tolerance_pct') ?? '5';
        $quotationValidityDays = CompanySettingsRepository::get('quotation_validity_days') ?? '30';
        $piValidityDays = CompanySettingsRepository::get('pi_validity_days') ?? '15';

        $replacements = [
            '{tolerance}'                => rtrim(rtrim($tolerance, '0'), '.'),
            '{advance_pct}'              => $data['financial']['advance_pct'],
            '{balance_pct}'              => $data['financial']['balance_pct'],
            '{quotation_validity_days}'  => $quotationValidityDays,
            '{pi_validity_days}'         => $piValidityDays,
        ];

        return array_map(
            static fn(array $c) => strtr($c['text'], $replacements),
            $clauses
        );
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
