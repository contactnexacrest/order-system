<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Services\AuthService;
use App\Services\SetupChecklist;

/**
 * Owner decision D-312 (point 4 B, 11 Nov 2026): Admin › Setup checklist
 * (manage_company_settings). Node twin: setupChecklistController.js.
 */
final class SetupChecklistController
{
    private const BACK = '/admin/setup-checklist';

    public function index(array $params): void
    {
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        $items = SetupChecklist::items((int) $user['id'], $roleId);
        View::render('settings/setup_checklist', [
            'items' => $items,
            'neededCount' => count(array_filter($items, static fn (array $i): bool => $i['status'] === 'needed')),
            'differences' => SetupChecklist::differences(),
            'recommended' => SetupChecklist::RECOMMENDED,
            'year' => (int) date('Y'),
        ], 'layout/base');
    }

    public function tick(array $params): void
    {
        $user = AuthService::currentUser();
        $on = ($_POST['ticked'] ?? '') === '1';
        $error = SetupChecklist::setTick((string) ($_POST['item_key'] ?? ''), $on, (string) ($_POST['note'] ?? ''), (int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null);
        Flash::set($error === null ? 'success' : 'error', $error ?? ($on ? 'Ticked — recorded with your name and the date.' : 'Tick removed.'));
        header('Location: ' . self::BACK);
    }

    public function applyRecommended(array $params): void
    {
        $user = AuthService::currentUser();
        $keys = array_values(array_filter(array_map('strval', (array) ($_POST['keys'] ?? [])), static fn (string $k): bool => isset(SetupChecklist::RECOMMENDED[$k])));
        $error = SetupChecklist::applyRecommended($keys, (string) ($_POST['reason'] ?? ''), (int) $user['id']);
        Flash::set($error === null ? 'success' : 'error', $error ?? 'Recommended values applied — each change is in the Audit Log with your reason.');
        header('Location: ' . self::BACK . '#recommended');
    }

    public function nationalHolidays(array $params): void
    {
        $user = AuthService::currentUser();
        $added = SetupChecklist::addNationalHolidays((int) date('Y'), (int) $user['id']);
        Flash::set('success', $added > 0 ? "{$added} national holiday(s) added to the Holiday Calendar." : 'The national holidays were already in the Holiday Calendar.');
        header('Location: ' . self::BACK);
    }
}
