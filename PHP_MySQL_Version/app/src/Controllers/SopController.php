<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\View;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Services\SopAccessService;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Staff-facing viewer for the Standard Operating Procedure — reads
 * docs/SOP/*.md directly off disk (never copied into a database), so a
 * new chapter dropped into that folder shows up immediately with no code
 * change. Deliberately not the Internal Reference Library
 * (ReferenceDocController): that one only supports a tiny hand-rolled
 * text format with no inline images, which would strip out the very
 * screenshots the SOP is built around and require someone to manually
 * re-paste every chapter here whenever the source file changes.
 *
 * Every route in this controller is read-only and requires nothing more
 * than being logged in (see index.php) — same visibility as the Reference
 * Library, since any staff member should be able to look this up — EXCEPT
 * the CA / Accounting chapters (the 'ca-' prefix chapters() already
 * splits out as category 'ca'), which document the same supplier-cost/
 * profitability/government-benefit detail the live CA module itself
 * gates behind ca_module_view (see ca-01-overview.md's own "stricter
 * permission" sections). Reading the live screens already needs that
 * permission; reading the SOP chapter describing them didn't, which is
 * the same class of gap the Reference Library's own category-level
 * required_permission already closes for its own content —
 * ReferenceDocController::index()/canViewCustomDoc() mirrored here.
 */
final class SopController
{
    private function canViewCaChapters(): bool
    {
        $user = AuthService::currentUser();
        return $user !== null && PermissionService::can(
            (int) $user['id'],
            $user['role_id'] !== null ? (int) $user['role_id'] : null,
            'ca_module_view'
        );
    }

    /** @return array{0:int,1:?int} the signed-in staff member's id and role id */
    private function who(): array
    {
        $user = AuthService::currentUser();
        return [(int) ($user['id'] ?? 0), isset($user['role_id']) && $user['role_id'] !== null ? (int) $user['role_id'] : null];
    }

    /** @return list<string> */
    private function visibleSlugs(): array
    {
        [$userId, $roleId] = $this->who();
        return SopAccessService::visibleSlugs($userId, $roleId, $this->canViewCaChapters());
    }

    /** @return list<string> chapters shown as "Extra for you" (not for people who see every chapter anyway) */
    private function extraSlugs(): array
    {
        [$userId, $roleId] = $this->who();
        return SopAccessService::isPrivileged($userId, $roleId) ? [] : SopAccessService::userExtras($userId);
    }

    /** D-266: the refusal page — in the normal layout, never a bare error. */
    private function refuse(string $message, string $backUrl, string $backLabel): void
    {
        http_response_code(403);
        View::render('sop/forbidden', ['message' => $message, 'backUrl' => $backUrl, 'backLabel' => $backLabel], 'layout/base');
    }

    private function docsDir(): string
    {
        return dirname(__DIR__, 3) . '/docs/SOP';
    }

    private function converter(): GithubFlavoredMarkdownConverter
    {
        return new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip', // admin-controlled files, but never trust raw HTML pass-through
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * @return array<int, array{slug:string, title:string, category:string}>
     *         every chapter file present right now, in filename order
     *         (which is how the 00-, 01-, 02- prefixes were chosen to
     *         sort). A `ca-` prefix (sorting after every numbered chapter,
     *         since digits sort before letters) marks a chapter as
     *         belonging to the CA / Accounting module rather than the main
     *         order-pipeline SOP — see the standing rule in this repo's
     *         history: every new module ships with its own SOP chapter(s),
     *         grouped under their own heading rather than mixed into the
     *         numbered list.
     */
    private function chapters(): array
    {
        $dir = $this->docsDir();
        $files = glob($dir . '/*.md') ?: [];
        sort($files, SORT_STRING);

        // Owner decision D-266: only the chapters this person may open (role ticks,
        // department roles, own extras; ca- still needs ca_module_view).
        $visible = array_flip($this->visibleSlugs());
        $extras = array_flip($this->extraSlugs());
        $chapters = [];
        foreach ($files as $path) {
            $slug = basename($path, '.md');
            if ($slug === 'README' || !isset($visible[$slug])) {
                continue;
            }
            $chapters[] = [
                'slug'     => $slug,
                'title'    => $this->extractTitle($path) ?? $slug,
                'category' => str_starts_with($slug, 'ca-') ? 'ca' : 'main',
                'extra'    => isset($extras[$slug]),
            ];
        }
        return $chapters;
    }

    private function extractTitle(string $path): ?string
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            return null;
        }
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (str_starts_with($line, '# ')) {
                    return trim(substr($line, 2));
                }
            }
        } finally {
            fclose($handle);
        }
        return null;
    }

    /** Rewrites the SOP's own relative links/images to routes this controller serves. */
    private function rewriteRelativeLinks(string $markdown): string
    {
        $markdown = str_replace('](./images/', '](/sop-assets/', $markdown);
        // QA Round 5, R5-05: README.md is the chapter list the /sop index page shows.
        $markdown = preg_replace('/\]\(\.\/README\.md(#[\w-]*)?\)/', '](/sop$1)', $markdown) ?? $markdown;
        return preg_replace('/\]\(\.\/([\w-]+)\.md(#[\w-]*)?\)/', '](/sop/$1$2)', $markdown) ?? $markdown;
    }

    private function renderMarkdownFile(string $path): string
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Could not read {$path}");
        }
        // QA Round 5, R5-04: links to pages this user can't open become plain text.
        // D-266: links to chapters this person cannot open become plain text.
        return SopAccessService::filterChapterLinks(\App\Helpers\PermittedLinks::filterHtml((string) $this->converter()->convert($this->rewriteRelativeLinks($raw))), $this->visibleSlugs());
    }

    public function index(array $params): void
    {
        [$userId, $roleId] = $this->who();
        if (!SopAccessService::libraryVisibleTo($userId, $roleId)) {
            $this->refuse(SopAccessService::LIBRARY_OFF, '/getting-started', 'Open Getting Started');
            return;
        }
        $readmePath = $this->docsDir() . '/README.md';
        $contentHtml = is_file($readmePath) ? $this->renderMarkdownFile($readmePath) : null;

        View::render('sop/index', [
            'contentHtml' => $contentHtml,
            'chapters'    => $this->chapters(),
            'canManage'   => SopAccessService::isPrivileged($userId, $roleId),
        ], 'layout/base');
    }

    public function show(array $params): void
    {
        $slug = basename((string) ($params['chapter'] ?? ''));
        $path = $this->docsDir() . '/' . $slug . '.md';

        // Whitelist by real existence in the docs directory, not just a
        // path-traversal check on the string — belt and braces, since
        // basename() alone already rules out '../' but this also rejects
        // any name that simply isn't a real chapter file.
        if ($slug === '' || $slug === 'README' || !is_file($path) || dirname(realpath($path)) !== realpath($this->docsDir())) {
            http_response_code(404);
            echo 'SOP chapter not found.';
            return;
        }
        // D-266 (and the earlier ca- rule): a chapter this person may not open.
        if (!in_array($slug, $this->visibleSlugs(), true)) {
            [$userId, $roleId] = $this->who();
            if (!SopAccessService::libraryVisibleTo($userId, $roleId)) {
                $this->refuse(SopAccessService::LIBRARY_OFF, '/getting-started', 'Open Getting Started');
            } else {
                $this->refuse(SopAccessService::CHAPTER_NOT_AVAILABLE, '/sop', 'Back to your SOP chapters');
            }
            return;
        }

        View::render('sop/show', [
            'contentHtml' => $this->renderMarkdownFile($path),
            'chapters'    => $this->chapters(),
            'currentSlug' => $slug,
        ], 'layout/base');
    }

    /** Serves an SOP screenshot — kept behind SessionAuth like everything else here, never a public static file. */
    public function asset(array $params): void
    {
        $filename = basename((string) ($params['file'] ?? ''));
        $path = $this->docsDir() . '/images/' . $filename;

        if ($filename === '' || !is_file($path) || dirname(realpath($path)) !== realpath($this->docsDir() . '/images')) {
            http_response_code(404);
            echo 'Image not found.';
            return;
        }

        // D-266: only a screenshot shown in a chapter this person may open.
        [$userId, $roleId] = $this->who();
        if (!SopAccessService::imageVisible($filename, $userId, $roleId, $this->visibleSlugs())) {
            http_response_code(403);
            echo 'Not permitted.';
            return;
        }

        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };
        header('Content-Type: ' . $mime);
        header('Cache-Control: private, max-age=3600');
        readfile($path);
    }
}
