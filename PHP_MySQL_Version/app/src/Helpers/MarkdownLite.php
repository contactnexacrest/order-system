<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * 90-item backlog Phase 5: editor-level bold/emphasis control for the
 * main T&C Clause library (Terms & Conditions, Legal Terms, Definitions
 * — printed on every buyer-facing document). Deliberately NOT the same
 * stored-HTML/contenteditable approach the Annexure's free-text field
 * uses — clause_text stays plain text in the database (zero migration
 * risk to decades of existing legal wording), and staff instead type a
 * tiny markdown-like syntax directly: **bold**, *italic*, _underline_.
 * tokenize() is the single source of truth both toHtml() (PDF, via the
 * markdown_lite Twig filter) and the DOCX builder's addText() run-by-run
 * styling read from, so the two renderers can never disagree about which
 * part of a clause is bold/italic/underlined.
 *
 * Deliberately not a general-purpose Markdown/CommonMark parser: no
 * nesting, no escaping syntax for a literal asterisk, and a stray
 * unpaired delimiter is always left as a literal character rather than
 * guessed at — exactly enough for "make this phrase bold" in a legal
 * clause, nothing more.
 */
final class MarkdownLite
{
    private const PATTERN = '/\*\*(?!\s)(.+?)(?<!\s)\*\*|\*(?!\s)(.+?)(?<!\s)\*|_(?!\s)(.+?)(?<!\s)_/s';

    /**
     * @return array<int, array{text:string, bold:bool, italic:bool, underline:bool}>
     *   segments in order; concatenating every segment's 'text' reproduces
     *   the original input with its bold/italic/underline markers stripped.
     */
    public static function tokenize(string $text): array
    {
        $segments = [];
        $lastEnd = 0;

        if (preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $m) {
                $offset = $m[0][1];
                $fullMatch = $m[0][0];
                if ($offset > $lastEnd) {
                    $segments[] = self::plain(substr($text, $lastEnd, $offset - $lastEnd));
                }

                if (isset($m[1]) && $m[1][1] !== -1) {
                    $segments[] = ['text' => $m[1][0], 'bold' => true, 'italic' => false, 'underline' => false];
                } elseif (isset($m[2]) && $m[2][1] !== -1) {
                    $segments[] = ['text' => $m[2][0], 'bold' => false, 'italic' => true, 'underline' => false];
                } elseif (isset($m[3]) && $m[3][1] !== -1) {
                    $segments[] = ['text' => $m[3][0], 'bold' => false, 'italic' => false, 'underline' => true];
                }

                $lastEnd = $offset + strlen($fullMatch);
            }
        }

        if ($lastEnd < strlen($text)) {
            $segments[] = self::plain(substr($text, $lastEnd));
        }

        return $segments === [] ? [self::plain($text)] : $segments;
    }

    /** HTML-escapes every segment first, then wraps it — a literal &/</> in the clause can never become unsafe markup. */
    public static function toHtml(string $text): string
    {
        $html = '';
        foreach (self::tokenize($text) as $seg) {
            $escaped = htmlspecialchars($seg['text'], ENT_QUOTES, 'UTF-8');
            if ($seg['bold']) {
                $escaped = "<strong>{$escaped}</strong>";
            }
            if ($seg['italic']) {
                $escaped = "<em>{$escaped}</em>";
            }
            if ($seg['underline']) {
                $escaped = "<u>{$escaped}</u>";
            }
            $html .= $escaped;
        }
        return $html;
    }

    /** @return array{text:string, bold:bool, italic:bool, underline:bool} */
    private static function plain(string $text): array
    {
        return ['text' => $text, 'bold' => false, 'italic' => false, 'underline' => false];
    }
}
