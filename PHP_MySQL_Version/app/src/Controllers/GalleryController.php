<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\View;
use App\Services\AuthService;
use App\Services\SopAccessService;

/**
 * A pure disk-backed knowledgebase gallery — reads whatever folders and
 * files exist under docs/gallery live off disk, exactly like SopController
 * reads docs/SOP. Deliberately no database, no upload form, and no
 * category list hardcoded anywhere: files are added directly on the
 * server's filesystem (e.g. via FTP) and show up immediately, folders
 * double as categories with zero code change. Read-only, same visibility
 * as the SOP viewer — any logged-in staff member (see index.php) — since
 * this is reference material, not an action surface.
 */
final class GalleryController
{
    private const MIME_BY_EXT = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf',
    ];

    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];

    private function rootDir(): string
    {
        return dirname(__DIR__, 3) . '/docs/gallery';
    }

    /**
     * Resolves a user-supplied relative path against the gallery root and
     * returns its real, on-disk path only if it stays inside that root —
     * null otherwise. realpath() collapses any '../' and resolves symlinks
     * before the containment check, so this also closes the symlink-escape
     * gap a plain string check on '..' would miss (same "belt and braces"
     * reasoning as SopController::show()/asset(), generalized here to
     * arbitrary folder depth since the gallery, unlike SOP's flat images
     * folder, supports nested category folders).
     */
    private function resolveSafePath(string $relative): ?string
    {
        if (str_contains($relative, "\0")) {
            return null;
        }
        $relative = trim(str_replace('\\', '/', $relative), '/');

        $root = realpath($this->rootDir());
        if ($root === false) {
            return null;
        }
        if ($relative === '') {
            return $root;
        }

        $candidate = realpath($root . '/' . $relative);
        if ($candidate === false) {
            return null;
        }
        if ($candidate === $root || str_starts_with($candidate, $root . DIRECTORY_SEPARATOR)) {
            return $candidate;
        }
        return null;
    }

    private static function isImage(string $filename): bool
    {
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, self::IMAGE_EXTENSIONS, true);
    }

    /** Owner decision D-266: the Gallery belongs to the help library — hidden with it when the SOP library is off. */
    private function libraryClosed(): bool
    {
        $user = AuthService::currentUser();
        return !SopAccessService::libraryVisibleTo((int) ($user['id'] ?? 0), isset($user['role_id']) && $user['role_id'] !== null ? (int) $user['role_id'] : null);
    }

    public function index(array $params): void
    {
        if ($this->libraryClosed()) {
            http_response_code(403);
            View::render('sop/forbidden', ['message' => SopAccessService::LIBRARY_OFF, 'backUrl' => '/getting-started', 'backLabel' => 'Open Getting Started'], 'layout/base');
            return;
        }
        $relative = trim((string) ($_GET['p'] ?? ''), '/');
        $dir = $this->resolveSafePath($relative);

        if ($dir === null || !is_dir($dir)) {
            $dir = $this->resolveSafePath('');
            $relative = '';
        }

        $folders = [];
        $files = [];
        if ($dir !== null) {
            $entries = scandir($dir) ?: [];
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                    continue;
                }
                $full = $dir . '/' . $entry;
                $entryRelative = $relative === '' ? $entry : $relative . '/' . $entry;
                if (is_dir($full)) {
                    $folders[] = ['name' => $entry, 'path' => $entryRelative];
                } else {
                    $files[] = [
                        'name' => $entry,
                        'path' => $entryRelative,
                        'isImage' => self::isImage($entry),
                        'sizeBytes' => (int) (filesize($full) ?: 0),
                    ];
                }
            }
            usort($folders, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
            usort($files, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
        }

        $crumbs = [];
        if ($relative !== '') {
            $accum = '';
            foreach (explode('/', $relative) as $segment) {
                $accum = $accum === '' ? $segment : $accum . '/' . $segment;
                $crumbs[] = ['name' => $segment, 'path' => $accum];
            }
        }
        $parentPath = null;
        if ($relative !== '') {
            $slash = strrpos($relative, '/');
            $parentPath = $slash === false ? '' : substr($relative, 0, $slash);
        }

        View::render('gallery/index', [
            'folders' => $folders,
            'files' => $files,
            'crumbs' => $crumbs,
            'currentPath' => $relative,
            'parentPath' => $parentPath,
            'isEmpty' => $dir !== null && count($folders) === 0 && count($files) === 0,
        ], 'layout/base');
    }

    /** Streams one file's raw bytes — images render inline, everything else downloads. */
    public function file(array $params): void
    {
        if ($this->libraryClosed()) {
            http_response_code(403);
            echo 'Not permitted.';
            return;
        }
        $relative = trim((string) ($_GET['p'] ?? ''), '/');
        $path = $this->resolveSafePath($relative);

        if ($path === null || !is_file($path)) {
            http_response_code(404);
            echo 'File not found.';
            return;
        }

        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $mime = self::MIME_BY_EXT[$ext] ?? 'application/octet-stream';

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: private, max-age=3600');
        if (!str_starts_with($mime, 'image/')) {
            $safeName = str_replace(['/', '\\'], '-', basename($path));
            header('Content-Disposition: attachment; filename="' . $safeName . '"');
        }
        readfile($path);
    }
}
