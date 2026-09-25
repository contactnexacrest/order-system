<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Repositories\AuditLogRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\FileStoreRepository;
use App\Services\AuthService;
use App\Services\DocumentGenerationService;
use App\Services\PermissionService;
use App\Services\StageGateBlockedException;
use App\Services\StageGateService;

final class DocumentController
{
    private const ALLOWED_TYPES = ['QT', 'ANNEXA', 'PI', 'OC', 'BUYERPO', 'SUPPO', 'FDN', 'PL', 'BLI', 'CI', 'COOPREP'];

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

        // PDF is always generated — the approval workflow, the draft->final
        // watermark swap, and the buyer email attachment all assume every
        // document has one, so it isn't actually a user-controllable choice
        // even though the PDF checkbox is shown (checked, disabled) for
        // clarity in the form. DOCX is the real optional toggle.
        $generateDocx = !empty($_POST['generate_docx']);

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

        try {
            $result = DocumentGenerationService::generate($orderId, $type, (int) $user['id'], null, $generateDocx, $allowOverride);
        } catch (StageGateBlockedException $e) {
            Flash::set('error', $e->getMessage());
            header("Location: /orders/{$orderId}");
            return;
        } catch (\Throwable $e) {
            error_log('[DOCUMENT GENERATION FAILED] order=' . $orderId . ' type=' . $type . ' — ' . $e->getMessage());
            Flash::set('error', 'Document generation failed. Check the server error log for details.');
            header("Location: /orders/{$orderId}");
            return;
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

        // QT generation is Stage 1's gate — passing it here (rather than
        // inside DocumentGenerationService) keeps stage progression, a
        // workflow/orchestration concern, out of the pure rendering service.
        if ($type === 'QT') {
            StageGateService::passAndUnlockNext($orderId, 1, (int) $user['id']);
        }

        Flash::set('success', "{$type} generated: {$result['document_reference']} Rev.{$result['revision_number']}.");

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
}
