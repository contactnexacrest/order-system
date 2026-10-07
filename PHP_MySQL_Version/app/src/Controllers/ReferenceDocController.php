<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Env;
use App\Helpers\Flash;
use App\Helpers\ReferenceContent;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\InternalReferenceDocRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\ReferenceLibraryCategoryRepository;
use App\Repositories\ReferenceLibraryRepository;
use App\Services\AuthService;
use App\Services\PermissionService;

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

    /**
     * docs/schema.sql Section AY — a custom entry filed under a category
     * whose required_permission the current user doesn't hold is left out
     * entirely; uncategorized entries (category_id NULL, the only kind
     * that existed before this feature) are always visible, same as
     * today's behaviour. The fixed 8 internal_reference_docs are never
     * scoped — they're system-wide material every staff member needs.
     */
    public function index(array $params): void
    {
        $user = AuthService::currentUser();
        $roleId = $user && $user['role_id'] !== null ? (int) $user['role_id'] : null;
        $customDocs = array_values(array_filter(
            ReferenceLibraryRepository::all(),
            static function (array $doc) use ($user, $roleId): bool {
                $required = $doc['category_required_permission'] ?? null;
                return $required === null || ($user && PermissionService::can((int) $user['id'], $roleId, $required));
            }
        ));

        View::render('reference_docs/index', [
            'docs' => InternalReferenceDocRepository::all(),
            'customDocs' => $customDocs,
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
        View::render('reference_docs/custom_create', ['categories' => ReferenceLibraryCategoryRepository::all()], 'layout/base');
    }

    public function customCreate(array $params): void
    {
        $title = trim((string) ($_POST['title'] ?? ''));
        $content = trim((string) ($_POST['content'] ?? '')) ?: null;
        $categoryId = !empty($_POST['category_id']) ? (int) $_POST['category_id'] : null;

        if ($title === '') {
            Flash::set('error', 'Title is required.');
            header('Location: /reference-docs/custom/create');
            return;
        }

        $user = AuthService::currentUser();
        $id = ReferenceLibraryRepository::create($title, $content, (int) $user['id'], $categoryId);

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

    /**
     * docs/schema.sql Section AY — the index already filters out what a
     * user can't see, but the same check is re-applied here (and in
     * customDownload below) so a direct URL can't bypass the category
     * scope.
     */
    private function canViewCustomDoc(array $doc): bool
    {
        if ($doc['category_id'] === null) {
            return true;
        }
        $category = ReferenceLibraryCategoryRepository::find((int) $doc['category_id']);
        if (!$category || $category['required_permission'] === null) {
            return true;
        }
        $user = AuthService::currentUser();
        return $user !== null && PermissionService::can((int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null, $category['required_permission']);
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
        if (!$this->canViewCustomDoc($doc)) {
            http_response_code(403);
            echo '<h1>403 — Not permitted</h1><p>You do not have access to this category of Reference Library document.</p><p><a href="/reference-docs">Back to Reference Library</a></p>';
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
        View::render('reference_docs/custom_edit', ['doc' => $doc, 'categories' => ReferenceLibraryCategoryRepository::all()], 'layout/base');
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
        $categoryId = !empty($_POST['category_id']) ? (int) $_POST['category_id'] : null;
        if ($title === '') {
            Flash::set('error', 'Title is required.');
            header("Location: /reference-docs/custom/{$id}/edit");
            return;
        }

        $user = AuthService::currentUser();
        ReferenceLibraryRepository::updateText($id, $title, $content, (int) $user['id'], $categoryId);

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

    // --- Categories: docs/schema.sql Section AY — gated the same as the custom-entry CRUD above (manage_company_settings) ---

    public function categoriesIndex(array $params): void
    {
        View::render('reference_docs/categories', [
            'categories' => ReferenceLibraryCategoryRepository::all(),
            'permissions' => PermissionRepository::all(),
        ], 'layout/base');
    }

    public function categoryCreate(array $params): void
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $requiredPermission = trim((string) ($_POST['required_permission'] ?? '')) ?: null;
        if ($name === '') {
            Flash::set('error', 'Category name is required.');
            header('Location: /reference-docs/categories');
            return;
        }

        $user = AuthService::currentUser();
        $id = ReferenceLibraryCategoryRepository::create($name, $requiredPermission);
        AuditLogRepository::log((int) $user['id'], 'REFERENCE_LIBRARY_CATEGORY_CREATED', 'reference_library_categories', $id, 'name', null, $name);
        Flash::set('success', "Category \"{$name}\" created.");
        header('Location: /reference-docs/categories');
    }

    public function categoryUpdate(array $params): void
    {
        $id = (int) $params['id'];
        $category = ReferenceLibraryCategoryRepository::find($id);
        if (!$category) {
            Flash::set('error', 'Category not found.');
            header('Location: /reference-docs/categories');
            return;
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $requiredPermission = trim((string) ($_POST['required_permission'] ?? '')) ?: null;
        if ($name === '') {
            Flash::set('error', 'Category name is required.');
            header('Location: /reference-docs/categories');
            return;
        }

        $user = AuthService::currentUser();
        ReferenceLibraryCategoryRepository::update($id, $name, $requiredPermission);
        AuditLogRepository::log((int) $user['id'], 'REFERENCE_LIBRARY_CATEGORY_UPDATED', 'reference_library_categories', $id, 'required_permission', $category['required_permission'], $requiredPermission);
        Flash::set('success', "Category \"{$name}\" updated.");
        header('Location: /reference-docs/categories');
    }

    public function categoryDelete(array $params): void
    {
        $id = (int) $params['id'];
        $category = ReferenceLibraryCategoryRepository::find($id);
        if (!$category) {
            Flash::set('error', 'Category not found.');
            header('Location: /reference-docs/categories');
            return;
        }

        $user = AuthService::currentUser();
        ReferenceLibraryCategoryRepository::delete($id);
        AuditLogRepository::log((int) $user['id'], 'REFERENCE_LIBRARY_CATEGORY_DELETED', 'reference_library_categories', $id, 'name', $category['name'], null);
        Flash::set('success', "Category \"{$category['name']}\" deleted. Any documents filed under it are now uncategorized (visible to everyone), never deleted.");
        header('Location: /reference-docs/categories');
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
        if (!$this->canViewCustomDoc($doc)) {
            http_response_code(403);
            echo '<h1>403 — Not permitted</h1><p>You do not have access to this category of Reference Library document.</p><p><a href="/reference-docs">Back to Reference Library</a></p>';
            return;
        }

        header('Content-Type: ' . ($doc['file_mime_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: ' . self::contentDispositionHeaderValue((string) ($doc['file_original_name'] ?? 'file')));
        readfile($doc['file_path']);
    }

    /**
     * A raw non-ASCII byte (e.g. an em dash — Quarry SOP — Block
     * Selection) in a bare filename="..." is outside what an HTTP header
     * value may legally contain; browsers vary in how they recover, from
     * mojibake to (on stricter servers/runtimes) an outright error. The
     * RFC 6266 filename* parameter carries the real UTF-8 name
     * percent-encoded, with an ASCII-only fallback kept in filename= for
     * any client that ignores it.
     */
    public static function contentDispositionHeaderValue(string $originalName): string
    {
        $safeDownloadName = str_replace(['/', '\\'], '-', $originalName);
        $safeDownloadName = preg_replace('/[\x00-\x1F\x7F"]/', '', $safeDownloadName);
        $asciiDownloadName = preg_replace('/[^\x20-\x7E]/', '_', $safeDownloadName);

        return 'attachment; filename="' . $asciiDownloadName . '"; filename*=UTF-8\'\'' . rawurlencode($safeDownloadName);
    }
}
