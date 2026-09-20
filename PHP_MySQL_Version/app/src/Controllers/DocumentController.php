<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Repositories\DocumentRepository;
use App\Repositories\FileStoreRepository;
use App\Services\AuthService;
use App\Services\DocumentGenerationService;
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

        try {
            $result = DocumentGenerationService::generate($orderId, $type, (int) $user['id']);
        } catch (\Throwable $e) {
            error_log('[DOCUMENT GENERATION FAILED] order=' . $orderId . ' type=' . $type . ' — ' . $e->getMessage());
            Flash::set('error', 'Document generation failed. Check the server error log for details.');
            header("Location: /orders/{$orderId}");
            return;
        }

        // QT generation is Stage 1's gate — passing it here (rather than
        // inside DocumentGenerationService) keeps stage progression, a
        // workflow/orchestration concern, out of the pure rendering service.
        if ($type === 'QT') {
            StageGateService::passAndUnlockNext($orderId, 1, (int) $user['id']);
        }

        Flash::set('success', "{$type} generated: {$result['document_reference']} Rev.{$result['revision_number']}.");
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
