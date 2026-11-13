<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\View;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Services\SopAccessService;

/**
 * A first-day "Getting Started" tour for brand-new staff — deliberately
 * NOT wrapped in the usual app shell (layout/base.php): it has its own
 * standalone design with its own navigation rail, built on app.css's own
 * tokens so it still looks like part of the same application. Mirrors
 * docs/SOP/00-getting-started.md's content (the canonical, version-
 * controlled text any staff member can also read via the SOP viewer)
 * but as a visually distinct, interactive companion piece rather than a
 * literal HTML transcription of that Markdown file.
 *
 * Same visibility as SopController: logged in is the only requirement,
 * since this is meant to be the very first thing a new hire opens.
 */
final class GettingStartedController
{
    public function show(array $params): void
    {
        $user = AuthService::currentUser();

        // QA Round 5, R5-04: links to pages this user can't open become plain text.
        ob_start();
        View::render('getting-started/show', [
            'userName' => $user['name'] ?? null,
        ], null);
        $html = \App\Helpers\PermittedLinks::filterHtml((string) ob_get_clean());
        // UI-005 / D-266: links to SOP chapters this person may not open become plain text,
        // and screenshots they may not load are left out (no broken image).
        $userId = (int) ($user['id'] ?? 0);
        $roleId = isset($user['role_id']) && $user['role_id'] !== null ? (int) $user['role_id'] : null;
        $visible = SopAccessService::visibleSlugs($userId, $roleId, PermissionService::can($userId, $roleId, 'ca_module_view'));
        $html = SopAccessService::filterChapterLinks($html, $visible);
        echo SopAccessService::filterScreenshots($html, $userId, $roleId, $visible);
    }
}
