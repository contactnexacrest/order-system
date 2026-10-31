<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Plain-PHP view renderer for the app's own admin/auth screens.
 *
 * Judgment call: Twig (per ARCHITECTURE.md) is reserved for the
 * business-document templates (QT/PI/OC/CI/...), where "zero business
 * value hardcoded in a template" genuinely matters and needs enforcing.
 * The app's own screens (login form, settings list, asset manager) carry
 * no business data of that kind, so a plain PHP include avoids pulling
 * Twig's Composer dependency into Phase A at all. If you'd rather have
 * one templating engine everywhere, these views can be ported to Twig
 * once vendor/ is installed — nothing else in the app needs to change,
 * since controllers already just pass an associative array of data in.
 */
final class View
{
    public static function render(string $viewPath, array $data = [], ?string $layout = 'layout/base'): void
    {
        $baseDir = dirname(__DIR__, 2) . '/views/';

        $content = self::capture($baseDir . $viewPath . '.php', $data);

        if ($layout === null) {
            echo $content;
            return;
        }

        // QA Round 6, R6-N02: on staff pages, a link the user would get a
        // 403 from is shown as plain text (the sidebar has its own checks).
        if ($layout === 'layout/base') {
            $content = PermittedLinks::filterHtml($content);
        }
        $data['content'] = $content;
        echo self::capture($baseDir . $layout . '.php', $data);
    }

    private static function capture(string $file, array $data): string
    {
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$file}");
        }
        // Owner decision D-214: every view knows which money the signed-in person may see ($sees['selling'], ...).
        if (!array_key_exists('sees', $data)) {
            $data['sees'] = \App\Services\MoneyVisibility::flags();
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }

    /**
     * QA Round 5, R5-26: a public file's URL with its last-change time
     * added (?v=…), so after a deploy every browser fetches the new app.js /
     * app.css at once instead of keeping a cached copy. Mirrors server.js's
     * asset() template global on the Node stack.
     */
    public static function asset(string $path): string
    {
        $file = dirname(__DIR__, 3) . '/public_html' . $path;
        return self::e($path . '?v=' . (is_file($file) ? (string) filemtime($file) : '0'));
    }
}
