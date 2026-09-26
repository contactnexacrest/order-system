<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\View;
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
 * Library, since any staff member should be able to look this up.
 */
final class SopController
{
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
     * @return array<int, array{slug:string, title:string}> every chapter
     *         file present right now, in filename order (which is how the
     *         00-, 01-, 02- prefixes were chosen to sort).
     */
    private function chapters(): array
    {
        $dir = $this->docsDir();
        $files = glob($dir . '/*.md') ?: [];
        sort($files, SORT_STRING);

        $chapters = [];
        foreach ($files as $path) {
            $slug = basename($path, '.md');
            if ($slug === 'README') {
                continue;
            }
            $chapters[] = [
                'slug'  => $slug,
                'title' => $this->extractTitle($path) ?? $slug,
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
        return preg_replace('/\]\(\.\/([\w-]+)\.md(#[\w-]*)?\)/', '](/sop/$1$2)', $markdown) ?? $markdown;
    }

    private function renderMarkdownFile(string $path): string
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Could not read {$path}");
        }
        return (string) $this->converter()->convert($this->rewriteRelativeLinks($raw));
    }

    public function index(array $params): void
    {
        $readmePath = $this->docsDir() . '/README.md';
        $contentHtml = is_file($readmePath) ? $this->renderMarkdownFile($readmePath) : null;

        View::render('sop/index', [
            'contentHtml' => $contentHtml,
            'chapters'    => $this->chapters(),
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
