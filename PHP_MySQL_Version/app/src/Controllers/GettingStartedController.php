<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\View;
use App\Services\AuthService;

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

        View::render('getting-started/show', [
            'userName' => $user['name'] ?? null,
        ], null);
    }
}
