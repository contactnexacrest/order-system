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

        $data['content'] = $content;
        echo self::capture($baseDir . $layout . '.php', $data);
    }

    private static function capture(string $file, array $data): string
    {
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$file}");
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
}
