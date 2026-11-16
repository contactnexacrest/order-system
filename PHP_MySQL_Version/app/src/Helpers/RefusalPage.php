<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * D-379 cosmetic follow-up (16 Nov 2026, owner-approved mockup): the 403 refusal page of the report gates
 * (App\Middleware\ReportAccessCheck) and of App\Middleware\PermissionCheck, drawn inside the normal app
 * layout (views/errors/refused.php): a red "Not open to you" pill, a title, the same explanatory text as
 * before and a "← Back" button. The caller sets nothing else: the HTTP status stays 403 and the
 * PERMISSION_DENIED audit row is written by the caller, as before. If the layout cannot be drawn (an error
 * while rendering), the old plain page is sent instead, so a refusal always answers.
 *
 * Node twin: NODE_MySQL_Version/app/src/helpers/refusalPage.js
 */
final class RefusalPage
{
    public const PILL = 'Not open to you';
    public const REPORT_TITLE = 'This report is not open to your role';
    public const PAGE_TITLE = 'This page is not open to your role';

    /** A report refusal: back to the Reports page (or the dashboard when no report opens at all). */
    public static function report(string $message, bool $toReports = true): void
    {
        self::send(self::REPORT_TITLE, $message, $toReports ? '/reports' : '/', $toReports ? 'Back to Reports' : 'Back to dashboard');
    }

    /** A permission refusal (PermissionCheck): back to the dashboard. */
    public static function permission(string $message): void
    {
        self::send(self::PAGE_TITLE, $message, '/', 'Back to dashboard');
    }

    public static function send(string $title, string $message, string $backUrl, string $backLabel): void
    {
        http_response_code(403);
        $level = ob_get_level();
        ob_start();
        try {
            View::render('errors/refused', [
                'title' => $title,
                'message' => $message,
                'backUrl' => $backUrl,
                'backLabel' => $backLabel,
            ], 'layout/base');
            $html = (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            $html = self::plain($message, $backUrl, $backLabel);
        }
        echo $html;
    }

    /** The page as it was before (no layout). */
    public static function plain(string $message, string $backUrl = '/', string $backLabel = 'Back to dashboard'): string
    {
        return '<h1>403 — Not permitted</h1><p>' . htmlspecialchars($message) . '</p><p><a href="' . htmlspecialchars($backUrl) . '">' . htmlspecialchars($backLabel) . '</a></p>';
    }
}
