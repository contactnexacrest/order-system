<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\DocumentLabel;
use App\Helpers\Flash;
use App\Helpers\ReasonValidator;
use App\Repositories\AuditLogRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\FileStoreRepository;
use App\Services\AuthService;
use App\Services\DocumentGenerationService;
use App\Services\OrderAccessService;
use App\Services\PermissionService;
use App\Services\StageGateBlockedException;

final class DocumentController
{
    private const ALLOWED_TYPES = ['QT', 'ANNEXA', 'PI', 'OC', 'BUYERPO', 'SUPPO', 'FDN', 'PL', 'BLI', 'CI', 'COOPREP'];

    /**
     * The invoice date staff picked: null for today (left blank or today),
     * the Y-m-d date for an earlier day, or false when it is not a real
     * date, is in the future, or is before the order was created.
     */
    private static function pickedDocumentDate(string $date, int $orderId): string|false|null
    {
        $date = trim($date);
        $today = date('Y-m-d');
        if ($date === '' || $date === $today) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date || $date > $today) {
            return false;
        }
        $order = \App\Repositories\OrderRepository::find($orderId);
        if ($order && !empty($order['created_at']) && $date < substr((string) $order['created_at'], 0, 10)) {
            return false;
        }
        return $date;
    }

    public function generate(array $params): void
    {
        $orderId = (int) $params['id'];
        $type = strtoupper((string) ($_POST['document_type'] ?? ''));
        $user = AuthService::currentUser();

        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            Flash::set('error', "Unknown or unsupported document type: {$type}");
            header("Location: /orders/{$orderId}");
            return;
        }

        // QA Round 5, R5-03: terms needing MD/ED approval block the Quotation.
        if ($type === 'QT' && \App\Services\PaymentTermsApprovalService::isPending($orderId)) {
            Flash::set('error', \App\Services\PaymentTermsApprovalService::pendingMessage($orderId));
            header("Location: /orders/{$orderId}#step-1-quotation-prepare");
            return;
        }

        // PDF is always generated — the approval workflow, the draft->final
        // watermark swap, and the buyer email attachment all assume every
        // document has one, so it isn't actually a user-controllable choice
        // even though the PDF checkbox is shown (checked, disabled) for
        // clarity in the form. DOCX is the real optional toggle.
        $generateDocx = !empty($_POST['generate_docx']);

        // Owner request (2026-10-27): the Commercial Invoice can be dated
        // today or earlier (e.g. to match the BL) — today if left blank.
        $documentDate = null;
        if ($type === 'CI') {
            $documentDate = self::pickedDocumentDate((string) ($_POST['document_date'] ?? ''), $orderId);
            if ($documentDate === false) {
                Flash::set('error', 'Choose an invoice date that is today or earlier (and not before the order was created).');
                header("Location: /orders/{$orderId}#step-ci-generate");
                return;
            }
        }

        // Narrow, audited escape hatch for generate()'s own locked-order /
        // out-of-sequence-stage guard (see that method's docblock) — never
        // trust the checkbox alone: re-check the permission server-side
        // (Super Admin bypasses it automatically — PermissionService::can())
        // and require a real reason before it does anything.
        $overrideRequested = !empty($_POST['override_gate']);
        $overrideReason = trim((string) ($_POST['override_reason'] ?? ''));
        $allowOverride = false;
        if ($overrideRequested) {
            $canOverride = PermissionService::can((int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null, 'edit_locked_data');
            if (!$canOverride) {
                Flash::set('error', 'You do not have permission to override a locked order or out-of-sequence stage.');
                header("Location: /orders/{$orderId}");
                return;
            }
            if ($overrideReason === '' || mb_strlen($overrideReason) < 10) {
                Flash::set('error', 'A reason (at least 10 characters) is required to force-generate a document out of sequence or on a locked order.');
                header("Location: /orders/{$orderId}");
                return;
            }
            $allowOverride = true;
        }

        // QA Round 6, R6-N18: a double click (or several) made one revision
        // each — one generation per order and document type at a time.
        if ($busy = self::claimGeneration($orderId, $type)) {
            Flash::set('warning', $busy);
            header("Location: /orders/{$orderId}");
            return;
        }
        try {
            $result = DocumentGenerationService::generate($orderId, $type, (int) $user['id'], null, $generateDocx, $allowOverride, $documentDate);
        } catch (StageGateBlockedException $e) {
            Flash::set('error', $e->getMessage());
            header("Location: /orders/{$orderId}");
            return;
        } catch (\Throwable $e) {
            error_log('[DOCUMENT GENERATION FAILED] order=' . $orderId . ' type=' . $type . ' — ' . $e->getMessage());
            Flash::set('error', 'Document generation failed. Check the server error log for details.');
            header("Location: /orders/{$orderId}");
            return;
        } finally {
            self::releaseGeneration($orderId, $type);
        }

        if ($allowOverride) {
            AuditLogRepository::log(
                (int) $user['id'],
                'DOCUMENT_GATE_OVERRIDE',
                'orders',
                $orderId,
                'document_type',
                null,
                $type,
                $overrideReason
            );
        }

        // GATE-01: Stage 1 passes only once the QT is actually *approved*
        // (see ReviewWorkflowService::finalizeIfFullyApproved()), not on mere
        // draft generation — Owner Decision #1: "No stage passes until its
        // document is approved." Generating the QT here just creates the
        // draft; it still has to go through review before anything unlocks.

        // QA Round 5, R5-25: a document without its own reference (COOPREP) is named by its order.
        Flash::set('success', !empty($result['document_reference'])
            ? "{$type} generated: {$result['document_reference']} Rev.{$result['revision_number']}."
            : DocumentLabel::for($type, null, $orderId) . " generated — Rev.{$result['revision_number']}.");

        // A revision (not a first-ever generation) of an earlier-stage
        // document while later-stage documents already exist means those
        // later documents were built from data that existed before
        // whatever just changed — see downstreamDocumentsAtRisk()'s
        // docblock. Surfacing this only on regeneration, not on normal
        // forward progress through the stages.
        if ((int) $result['revision_number'] > 0) {
            $downstream = DocumentGenerationService::downstreamDocumentsAtRisk($orderId, $type);
            if (!empty($downstream)) {
                Flash::set('warning', "{$type} was revised, but this order already has " . implode(', ', $downstream) . " generated from data that existed before this change. Review whether " . (count($downstream) > 1 ? 'they need' : 'it needs') . " to be regenerated too.");
            }
        }

        header("Location: /orders/{$orderId}");
    }

    public function download(array $params): void
    {
        $documentId = (int) $params['documentId'];
        $format = strtolower((string) ($_GET['format'] ?? 'pdf'));

        $document = DocumentRepository::find($documentId);
        if (!$document) {
            http_response_code(404);
            echo 'Document not found.';
            return;
        }

        // Point 2 follow-up: the internal-only CA Financial Annexure
        // (government export benefits/expenses) is one of many document
        // types this generic route serves, gated at the route level only
        // on the broad download_pdf permission that most staff hold.
        // That's fine for every other type here (BLI/COOPREP/SUPPO/AMD
        // are internal too, but not sensitive financial data) — CAFIN
        // specifically needs the same ca_module_view gate its data
        // already carries everywhere else it's shown, so a staff member
        // without any CA permission can't fetch it just by knowing/
        // guessing its document id.
        // Owner decision (2026-10-28): its own permission, view_cafin — and
        // view_cafin alone is enough for CAFIN (the CA holds it without
        // "Download PDF"); every other document needs download_pdf.
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        $requiredKey = $document['document_type_code'] === 'CAFIN' ? 'view_cafin' : 'download_pdf';
        // QA Round 6, Q-R6-2 (D-118): CAFIN also needs the order itself —
        // whoever cannot open the order page cannot download its CAFIN.
        if (!PermissionService::can((int) $user['id'], $roleId, $requiredKey)
            || ($document['document_type_code'] === 'CAFIN' && !OrderAccessService::currentUserMayOpenOrderId((int) $document['order_id']))) {
            http_response_code(403);
            echo 'You do not have permission to view this document.';
            return;
        }

        $fileId = $format === 'docx' ? $document['docx_file_id'] : $document['pdf_file_id'];
        if (!$fileId) {
            http_response_code(404);
            echo 'That format was not generated for this document.';
            return;
        }

        $file = FileStoreRepository::find((int) $fileId);
        if (!$file || !is_file($file['server_path'])) {
            http_response_code(404);
            echo 'File is missing from storage.';
            return;
        }

        // internal_only files (the content-parity DOCX) are downloadable
        // from here for internal staff use, but are never emailed to the
        // buyer — that boundary is enforced wherever a future "send to
        // buyer" action is built, not here.
        // original_filename is a display name, not a path — a document
        // reference containing "/" (e.g. "SC/AMD/2026/1809001") must not
        // be run through basename(), which treats it as a path separator
        // and silently truncates the header value. Strip slashes/controls
        // instead so the browser always gets the full intended name.
        $safeDownloadName = str_replace(['/', '\\'], '-', $file['original_filename']);
        $safeDownloadName = preg_replace('/[\x00-\x1F\x7F"]/', '', $safeDownloadName) ?? $safeDownloadName;
        header('Content-Type: ' . $file['mime_type']);
        header('Content-Disposition: attachment; filename="' . $safeDownloadName . '"');
        header('Content-Length: ' . (string) $file['file_size_bytes']);
        readfile($file['server_path']);
    }

    /**
     * Deletes a mistaken draft document — draft only, never in_review or
     * beyond (those are superseded by a new revision, not deleted). Requires
     * a real reason, same ReasonValidator::MIN_LENGTH rule as every other
     * reason field, recorded to the audit trail before the row is gone.
     */
    public function delete(array $params): void
    {
        $orderId = (int) $params['id'];
        $documentId = (int) $params['documentId'];
        $user = AuthService::currentUser();

        $document = DocumentRepository::find($documentId);
        if (!$document || (int) $document['order_id'] !== $orderId) {
            Flash::set('error', 'Document not found.');
            header("Location: /orders/{$orderId}");
            return;
        }
        if ($document['status'] !== 'draft') {
            Flash::set('error', 'Only a draft document can be deleted — this one has moved past draft status.');
            header("Location: /orders/{$orderId}");
            return;
        }

        $reason = trim((string) ($_POST['reason'] ?? ''));
        $reasonError = ReasonValidator::check($reason);
        if ($reasonError !== null) {
            Flash::set('error', $reasonError);
            header("Location: /orders/{$orderId}");
            return;
        }

        $reference = $document['document_reference'] ?? ($document['document_type_code'] . ' Rev.' . $document['revision_number']);
        DocumentRepository::deleteDraft($documentId);

        AuditLogRepository::log(
            (int) $user['id'],
            'DOCUMENT_DRAFT_DELETED',
            'orders',
            $orderId,
            'document',
            $reference,
            null,
            $reason
        );

        Flash::set('success', "Draft document {$reference} deleted.");
        header("Location: /orders/{$orderId}");
    }

    /**
     * QA Round 6, R6-N18: one generation per order and document type at a
     * time — a MySQL named lock, so of several posts at the same moment (a
     * double click) only the first makes a revision.
     *
     * @return string|null why it cannot be generated right now; null when claimed
     */
    private static function claimGeneration(int $orderId, string $type): ?string
    {
        $lock = \App\Config\Database::connection()->prepare('SELECT GET_LOCK(:name, 0)');
        $lock->execute(['name' => "nx_docgen_{$orderId}_{$type}"]);
        return (int) $lock->fetchColumn() === 1 ? null : 'This document is being generated right now — wait a moment, then refresh the page.';
    }

    private static function releaseGeneration(int $orderId, string $type): void
    {
        \App\Config\Database::connection()->prepare('SELECT RELEASE_LOCK(:name)')->execute(['name' => "nx_docgen_{$orderId}_{$type}"]);
    }
}
