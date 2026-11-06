<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AssociateRepository;
use App\Services\AssociateAccess;
use App\Services\AuthService;
use App\Services\IncentiveService;

/**
 * D-261 (owner approval 6 Nov 2026, mockups mock13): the staff side of associate
 * portal access levels — an associate's level (Portal access card), "Not yet" on the
 * upgrade suggestion, and the Admin › Associates › Access levels matrix. Only the MD
 * changes anything; every change needs a reason and is logged.
 */
final class AssociateAccessController
{
    private static function user(): array
    {
        return AuthService::currentUser() ?? [];
    }

    public function levels(array $params): void
    {
        View::render('associates/access_levels', [
            'matrix' => AssociateAccess::matrix(),
            'history' => AssociateAccess::history(null, 50),
            'counts' => AssociateAccess::countsPerLevel(),
            'isMd' => IncentiveService::isMd(self::user()),
        ], 'layout/base');
    }

    public function saveLevels(array $params): void
    {
        $restore = ($_POST['action'] ?? '') === 'restore';
        $error = AssociateAccess::saveMatrix((array) ($_POST['tick'] ?? []), (string) ($_POST['reason'] ?? ''), self::user(), $restore);
        if ($error !== null) {
            Flash::set('error', $error);
            Flash::setOld($_POST);
        } else {
            Flash::set('success', $restore ? 'Recommended defaults restored.' : 'Level settings saved. They apply to every associate on that level at once.');
        }
        header('Location: /associates/access-levels');
    }

    public function setLevel(array $params): void
    {
        $a = AssociateRepository::find((int) ($params['id'] ?? 0));
        if ($a === null) {
            http_response_code(404);
            echo 'Associate not found.';
            return;
        }
        $error = AssociateAccess::setLevel($a, (string) ($_POST['access_level'] ?? ''), array_map('strval', (array) ($_POST['feature'] ?? [])), (string) ($_POST['reason'] ?? ''), self::user());
        if ($error !== null) {
            Flash::set('error', $error);
            Flash::setOld($_POST);
        } else {
            Flash::set('success', 'Portal access changed. ' . $a['name'] . ' now has: ' . AssociateAccess::describe(AssociateRepository::find((int) $a['id']) ?? $a) . '.');
        }
        header('Location: /associates/' . (int) $a['id'] . '#portal-access');
    }

    public function notYet(array $params): void
    {
        $a = AssociateRepository::find((int) ($params['id'] ?? 0));
        if ($a === null) {
            http_response_code(404);
            echo 'Associate not found.';
            return;
        }
        $error = AssociateAccess::dismissUpgrade($a, self::user());
        Flash::set($error === null ? 'success' : 'error', $error ?? 'Noted — you will be asked again after their next completed, fully paid order.');
        header('Location: /associates/' . (int) $a['id'] . '#portal-access');
    }
}
