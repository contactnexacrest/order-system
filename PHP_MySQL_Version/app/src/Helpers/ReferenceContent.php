<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Renders the Internal Reference Library's admin-editable plain-text
 * content into safe HTML. A deliberately tiny format — "# "/"## " for
 * headings, "- " for a bullet, "1. " for a numbered step, blank line for
 * a paragraph break — rather than a full Markdown library, since this is
 * an internal reference page, not a document template. Escapes every
 * line BEFORE recognizing any of these markers, so admin-entered content
 * can never inject HTML/script — the marker characters themselves aren't
 * privileged, only their position at the start of an already-escaped line.
 */
final class ReferenceContent
{
    public static function toHtml(string $content): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $html = '';
        $listOpen = null; // 'ul' | 'ol' | null

        $closeList = static function () use (&$html, &$listOpen): void {
            if ($listOpen !== null) {
                $html .= "</{$listOpen}>";
                $listOpen = null;
            }
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                $closeList();
                continue;
            }
            $escaped = htmlspecialchars($trimmed, ENT_QUOTES, 'UTF-8');

            if (str_starts_with($trimmed, '## ')) {
                $closeList();
                $html .= '<h3>' . htmlspecialchars(substr($trimmed, 3), ENT_QUOTES, 'UTF-8') . '</h3>';
            } elseif (str_starts_with($trimmed, '# ')) {
                $closeList();
                $html .= '<h2>' . htmlspecialchars(substr($trimmed, 2), ENT_QUOTES, 'UTF-8') . '</h2>';
            } elseif (str_starts_with($trimmed, '- ')) {
                if ($listOpen !== 'ul') {
                    $closeList();
                    $html .= '<ul>';
                    $listOpen = 'ul';
                }
                $html .= '<li>' . htmlspecialchars(substr($trimmed, 2), ENT_QUOTES, 'UTF-8') . '</li>';
            } elseif (preg_match('/^\d+\.\s+(.*)$/', $trimmed, $m)) {
                if ($listOpen !== 'ol') {
                    $closeList();
                    $html .= '<ol>';
                    $listOpen = 'ol';
                }
                $html .= '<li>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</li>';
            } else {
                $closeList();
                $html .= '<p>' . $escaped . '</p>';
            }
        }
        $closeList();
        return $html;
    }

    /**
     * {placeholder} tokens resolved from live company_settings, the same
     * convention as bl_type_instruction's {company} token — so a page
     * like the Wall Reference never goes stale relative to whatever the
     * actual configured values are right now.
     */
    public static function substitutePlaceholders(string $content): string
    {
        $keys = [
            'master_tracking_ref_format', 'client_number_format', 'order_ref_format',
            'bl_type_instruction', 'bl_consignee_instruction',
            'quantity_shortfall_tolerance_pct', 'dispute_response_days_n', 'weekly_off_days',
        ];
        $tokens = [];
        foreach ($keys as $key) {
            $tokens['{' . $key . '}'] = \App\Repositories\CompanySettingsRepository::get($key) ?? '—';
        }
        return strtr($content, $tokens);
    }
}
