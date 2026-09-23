<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Env;
use App\Helpers\Flash;
use App\Helpers\ReferenceContent;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\InternalReferenceDocRepository;
use App\Repositories\ReferenceLibraryRepository;
use App\Services\AuthService;

/**
 * The Internal Reference Library — any authenticated staff member can
 * view (SOPs, the Stage Gate Reference, the Cross-Verification Checklist,
 * and the Wall Reference are things every staff member should be able to
 * look up), editing is gated the same as Company Settings since this is
 * effectively business-rule configuration content, not per-user data.
 *
 * Also covers the custom Reference Library entries (schema.sql Section Y)
 * — add/delete/reupload-a-file, alongside the 8 fixed entries above,
 * which stay edit-only (add/delete doesn't make sense for those; each is
 * a specific named document the app itself refers to by code).
 */
final class ReferenceDocController
{
    private const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt'];
    private const MAX_BYTES = 15 * 1024 * 1024;
    private const MIME_BY_EXT = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'txt' => 'text/plain',
    ];

    public function index(array $params): void
    {
        View::render('reference_docs/index', [
            'docs' => InternalReferenceDocRepository::all(),
            'customDocs' => ReferenceLibraryRepository::all(),
        ], 'layout/base');
    }

    public function show(array $params): void
    {
        $code = strtoupper((string) ($params['code'] ?? ''));
        $doc = InternalReferenceDocRepository::findByCode($code);
        if (!$doc) {
            http_response_code(404);
            echo 'Reference document not found.';
            return;
        }

        $content = $doc['content'] ?? '';
        if ($code === 'WALLREF' && $content !== '') {
            $content = ReferenceContent::substitutePlaceholders($content);
        }

        View::render('reference_docs/show', [
            'doc' => $doc,
            'contentHtml' => $content !== '' ? ReferenceContent::toHtml($content) : null,
        ], 'layout/base');
    }

    public function edit(array $params): void
    {
        $code = strtoupper((string) ($params['code'] ?? ''));
        $doc = InternalReferenceDocRepository::findByCode($code);
        if (!$doc) {
            http_response_code(404);
            echo 'Reference document not found.';
            return;
        }
        View::render('reference_docs/edit', ['doc' => $doc], 'layout/base');
    }

    public function update(array $params): void
    {
        $code = strtoupper((string) ($params['code'] ?? ''));
        $doc = InternalReferenceDocRepository::findByCode($code);
        if (!$doc) {
            http_response_code(404);
            echo 'Reference document not found.';
            return;
        }

        $content = trim((string) ($_POST['content'] ?? ''));
        $user = AuthService::currentUser();
        InternalReferenceDocRepository::upsert((int) $doc['document_type_id'], $content, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'REFERENCE_DOC_UPDATED', 'internal_reference_docs', (int) $doc['document_type_id'], 'content', null, null, "Updated {$code}");

        Flash::set('success', "{$doc['name']} updated.");
        header("Location: /reference-docs/{$code}");
    }

    // --- Custom entries: add / edit / delete / reupload file ---

    /** @return array{path:string, originalName:string, mimeType:string} */
    private function saveUploadedFile(array $file): array
    {
        $originalName = (string) $file['name'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \RuntimeException('File type .' . $extension . ' is not allowed (allowed: ' . implode(', ', self::ALLOWED_EXTENSIONS) . ').');
        }
        if ((int) $file['size'] > self::MAX_BYTES) {
            throw new \RuntimeException('File is too large — maximum is 15 MB.');
        }

        $storageBase = Env::get('STORAGE_BASE_PATH', dirname(__DIR__, 3) . '/storage');
        $targetDir = $storageBase . '/internal/reference_library';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $targetPath = $targetDir . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new \RuntimeException('Could not save the uploaded file.');
        }

        return [
            'path' => $targetPath,
            'originalName' => $originalName,
            'mimeType' => self::MIME_BY_EXT[$extension] ?? 'application/octet-stream',
        ];
    }

    public function customCreateForm(array $params): void
    {
        View::render('reference_docs/custom_create', [], 'layout/base');
    }

    public function customCreate(array $params): void
    {
        $title = trim((string) ($_POST['title'] ?? ''));
        $content = trim((string) ($_POST['content'] ?? '')) ?: null;

        if ($title === '') {
            Flash::set('error', 'Title is required.');
            header('Location: /reference-docs/custom/create');
            return;
        }

        $user = AuthService::currentUser();
        $id = ReferenceLibraryRepository::create($title, $content, (int) $user['id']);

        if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            try {
                $saved = $this->saveUploadedFile($_FILES['file']);
                ReferenceLibraryRepository::updateFile($id, $saved['path'], $saved['originalName'], $saved['mimeType'], (int) $user['id']);
            } catch (\Throwable $e) {
                Flash::set('error', "Document created, but the file wasn't attached: {$e->getMessage()}");
                header('Location: /reference-docs');
                return;
            }
        }

        AuditLogRepository::log((int) $user['id'], 'REFERENCE_LIBRARY_DOC_CREATED', 'reference_library_documents', $id, 'title', null, $title);
        Flash::set('success', "\"{$title}\" added to the Reference Library.");
        header('Location: /reference-docs');
    }

    public function customShow(array $params): void
    {
        $id = (int) $params['id'];
        $doc = ReferenceLibraryRepository::find($id);
        if (!$doc) {
            http_response_code(404);
            echo 'Reference document not found.';
            return;
        }
        View::render('reference_docs/custom_show', [
            'doc' => $doc,
            'contentHtml' => $doc['content'] ? ReferenceContent::toHtml($doc['content']) : null,
        ], 'layout/base');
    }

    public function customEditForm(array $params): void
    {
        $id = (int) $params['id'];
        $doc = ReferenceLibraryRepository::find($id);
        if (!$doc) {
            http_response_code(404);
            echo 'Reference document not found.';
            return;
        }
        View::render('reference_docs/custom_edit', ['doc' => $doc], 'layout/base');
    }

    public function customUpdate(array $params): void
    {
        $id = (int) $params['id'];
        $doc = ReferenceLibraryRepository::find($id);
        if (!$doc) {
            http_response_code(404);
            echo 'Reference document not found.';
            return;
        }

        $title = trim((string) ($_POST['title'] ?? ''));
        $content = trim((string) ($_POST['content'] ?? '')) ?: null;
        if ($title === '') {
            Flash::set('error', 'Title is required.');
            header("Location: /reference-docs/custom/{$id}/edit");
            return;
        }

        $user = AuthService::currentUser();
        ReferenceLibraryRepository::updateText($id, $title, $content, (int) $user['id']);

        if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            try {
                $saved = $this->saveUploadedFile($_FILES['file']);
                ReferenceLibraryRepository::updateFile($id, $saved['path'], $saved['originalName'], $saved['mimeType'], (int) $user['id']);
            } catch (\Throwable $e) {
                Flash::set('error', "Text saved, but the new file wasn't attached: {$e->getMessage()}");
                header("Location: /reference-docs/custom/{$id}/edit");
                return;
            }
        }

        AuditLogRepository::log((int) $user['id'], 'REFERENCE_LIBRARY_DOC_UPDATED', 'reference_library_documents', $id, 'title', $doc['title'], $title);
        Flash::set('success', "\"{$title}\" updated.");
        header('Location: /reference-docs');
    }

    public function customDelete(array $params): void
    {
        $id = (int) $params['id'];
        $doc = ReferenceLibraryRepository::find($id);
        if (!$doc) {
            Flash::set('error', 'Reference document not found.');
            header('Location: /reference-docs');
            return;
        }

        $user = AuthService::currentUser();
        ReferenceLibraryRepository::delete($id);
        AuditLogRepository::log((int) $user['id'], 'REFERENCE_LIBRARY_DOC_DELETED', 'reference_library_documents', $id, 'title', $doc['title'], null);
        Flash::set('success', "\"{$doc['title']}\" removed from the Reference Library. (Its uploaded file, if any, is left on disk — nothing is ever deleted.)");
        header('Location: /reference-docs');
    }

    public function customDownload(array $params): void
    {
        $id = (int) $params['id'];
        $doc = ReferenceLibraryRepository::find($id);
        if (!$doc || empty($doc['file_path']) || !is_file($doc['file_path'])) {
            http_response_code(404);
            echo 'File is missing from storage.';
            return;
        }

        $safeDownloadName = str_replace(['/', '\\'], '-', (string) ($doc['file_original_name'] ?? 'file'));
        $safeDownloadName = preg_replace('/[\x00-\x1F\x7F"]/', '', $safeDownloadName);

        header('Content-Type: ' . ($doc['file_mime_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $safeDownloadName . '"');
        readfile($doc['file_path']);
    }
}
